<?php

declare(strict_types=1);

namespace App\Auth\Oidc;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Throwable;
use UnexpectedValueException;

/**
 * Relying-party side of the OpenID Connect authorization code flow (with PKCE).
 */
final readonly class OidcClient
{
    public function __construct(
        private OidcSettings $settings,
        private Http $http,
        private Cache $cache,
    ) {}

    /**
     * @throws OidcException
     */
    public function metadata(): ProviderMetadata
    {
        /** @var array<mixed> $document */
        $document = $this->cache->remember(
            $this->cacheKey('discovery'),
            $this->settings->cacheTtl,
            fn (): array => $this->fetchJson($this->settings->issuer.'/.well-known/openid-configuration'),
        );

        $metadata = ProviderMetadata::fromDiscovery($document);

        if (rtrim($metadata->issuer, '/') !== $this->settings->issuer) {
            throw OidcException::invalidDiscovery("issuer mismatch ({$metadata->issuer})");
        }

        return $metadata;
    }

    /**
     * @throws OidcException
     */
    public function authorizationUrl(string $redirectUri, string $state, string $nonce, string $codeVerifier): string
    {
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->settings->clientId,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $this->settings->scopes),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::codeChallenge($codeVerifier),
            'code_challenge_method' => 'S256',
        ], arg_separator: '&', encoding_type: PHP_QUERY_RFC3986);

        $endpoint = $this->metadata()->authorizationEndpoint;

        return $endpoint.(str_contains($endpoint, '?') ? '&' : '?').$query;
    }

    /**
     * Exchange the authorization code and return the verified ID token claims.
     *
     * @throws OidcException
     */
    public function authenticate(string $code, string $redirectUri, string $codeVerifier, string $nonce): OidcClaims
    {
        $idToken = $this->exchangeCode($code, $redirectUri, $codeVerifier);

        return $this->verifyIdToken($idToken, $nonce);
    }

    /**
     * URL that ends the session at the provider, if it supports RP-initiated logout.
     */
    public function logoutUrl(string $postLogoutRedirectUri): ?string
    {
        try {
            $endpoint = $this->metadata()->endSessionEndpoint;
        } catch (OidcException) {
            return null;
        }

        if ($endpoint === null) {
            return null;
        }

        $query = http_build_query([
            'client_id' => $this->settings->clientId,
            'post_logout_redirect_uri' => $postLogoutRedirectUri,
        ], arg_separator: '&', encoding_type: PHP_QUERY_RFC3986);

        return $endpoint.(str_contains($endpoint, '?') ? '&' : '?').$query;
    }

    public static function codeChallenge(string $codeVerifier): string
    {
        return self::base64UrlEncode(hash('sha256', $codeVerifier, true));
    }

    /**
     * @throws OidcException
     */
    private function exchangeCode(string $code, string $redirectUri, string $codeVerifier): string
    {
        $metadata = $this->metadata();

        $payload = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
            'client_id' => $this->settings->clientId,
        ];

        $request = $this->request()->asForm();

        if ($this->settings->clientSecret !== '') {
            if (in_array('client_secret_basic', $metadata->tokenEndpointAuthMethods, true)) {
                // RFC 6749 §2.3.1: credentials are form-encoded before Basic auth.
                $request = $request->withBasicAuth(
                    urlencode($this->settings->clientId),
                    urlencode($this->settings->clientSecret),
                );
            } else {
                $payload['client_secret'] = $this->settings->clientSecret;
            }
        }

        try {
            $response = $request->post($metadata->tokenEndpoint, $payload);
        } catch (ConnectionException $e) {
            throw OidcException::providerUnavailable('token endpoint: '.$e->getMessage());
        }

        $idToken = $response->json('id_token');

        if ($response->failed() || ! is_string($idToken) || $idToken === '') {
            throw OidcException::invalidToken("token endpoint returned HTTP {$response->status()} without an id_token");
        }

        return $idToken;
    }

    /**
     * @throws OidcException
     */
    private function verifyIdToken(string $idToken, string $nonce): OidcClaims
    {
        $claims = $this->decode($idToken);

        $audience = $claims['aud'] ?? null;
        $audiences = is_array($audience) ? $audience : [$audience];

        if (! in_array($this->settings->clientId, $audiences, true)) {
            throw OidcException::invalidToken('audience mismatch');
        }

        if (count($audiences) > 1 && ($claims['azp'] ?? null) !== $this->settings->clientId) {
            throw OidcException::invalidToken('authorized party mismatch');
        }

        if (! is_string($claims['iss'] ?? null) || rtrim($claims['iss'], '/') !== $this->settings->issuer) {
            throw OidcException::invalidToken('issuer mismatch');
        }

        if (! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])) {
            throw OidcException::invalidToken('nonce mismatch');
        }

        if (! isset($claims['exp'], $claims['iat'])) {
            throw OidcException::invalidToken('missing exp or iat');
        }

        $verified = OidcClaims::fromArray($claims);

        if ($verified->subject === '') {
            throw OidcException::invalidToken('missing subject');
        }

        return $verified;
    }

    /**
     * Verify the signature and timestamps. Retries once with freshly fetched
     * keys so a key rotation at the provider does not break logins.
     *
     * @return array<string, mixed>
     *
     * @throws OidcException
     */
    private function decode(string $idToken): array
    {
        JWT::$leeway = $this->settings->leeway;

        try {
            return $this->decodeWith($idToken, $this->signingKeys(refresh: false));
        } catch (OidcException $e) {
            throw $e;
        } catch (UnexpectedValueException $e) {
            if (! str_contains($e->getMessage(), '"kid"')) {
                throw OidcException::invalidToken($e->getMessage());
            }
        } catch (Throwable $e) {
            throw OidcException::invalidToken($e->getMessage());
        }

        try {
            return $this->decodeWith($idToken, $this->signingKeys(refresh: true));
        } catch (OidcException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw OidcException::invalidToken($e->getMessage());
        }
    }

    /**
     * @param  array<string, Key>  $keys
     * @return array<string, mixed>
     */
    private function decodeWith(string $idToken, array $keys): array
    {
        $payload = JWT::decode($idToken, $keys);

        /** @var array<string, mixed> */
        return json_decode((string) json_encode($payload), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, Key>
     *
     * @throws OidcException
     */
    private function signingKeys(bool $refresh): array
    {
        $key = $this->cacheKey('jwks');

        if ($refresh) {
            $this->cache->forget($key);
        }

        /** @var array<mixed> $jwks */
        $jwks = $this->cache->remember(
            $key,
            $this->settings->cacheTtl,
            fn (): array => $this->fetchJson($this->metadata()->jwksUri),
        );

        try {
            // Keys without an "alg" member are RSA keys used with RS256, the
            // algorithm every OpenID provider must support.
            return JWK::parseKeySet($jwks, 'RS256');
        } catch (Throwable $e) {
            throw OidcException::invalidDiscovery('unusable JWKS: '.$e->getMessage());
        }
    }

    /**
     * @return array<mixed>
     *
     * @throws OidcException
     */
    private function fetchJson(string $url): array
    {
        try {
            $response = $this->request()->acceptJson()->get($url);
        } catch (ConnectionException $e) {
            throw OidcException::providerUnavailable("{$url}: {$e->getMessage()}");
        }

        $json = $response->json();

        if ($response->failed() || ! is_array($json)) {
            throw OidcException::providerUnavailable("{$url} returned HTTP {$response->status()}");
        }

        return $json;
    }

    private function request(): PendingRequest
    {
        return $this->http->timeout(10)->connectTimeout(5);
    }

    private function cacheKey(string $name): string
    {
        return 'oidc:'.$name.':'.sha1($this->settings->issuer);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
