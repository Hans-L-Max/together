<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Auth\Oidc\OidcSettings;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;

/**
 * An in-memory OpenID provider for feature tests: discovery document, JWKS
 * and a token endpoint that hands out ID tokens signed with a real RSA key.
 */
final class FakeOidcProvider
{
    public const string ISSUER = 'https://id.example.test/realms/together';

    public const string CLIENT_ID = 'together-app';

    public const string CLIENT_SECRET = 's3cret';

    public string $kid = 'key-1';

    /** @var array<string, mixed> */
    public array $claims = [];

    public ?string $idToken = null;

    public int $tokenStatus = 200;

    /** @var list<Request> */
    public array $tokenRequests = [];

    public int $jwksRequests = 0;

    /** @var list<string> */
    public array $authMethods = ['client_secret_basic', 'client_secret_post'];

    private OpenSSLAsymmetricKey $key;

    public function __construct()
    {
        $this->key = self::newKey();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function install(array $config = []): self
    {
        config()->set('oidc', array_merge([
            'enabled' => true,
            'issuer' => self::ISSUER,
            'client_id' => self::CLIENT_ID,
            'client_secret' => self::CLIENT_SECRET,
            'scopes' => 'openid profile email',
            'button_label' => null,
            'auto_redirect' => false,
            'logout_at_provider' => false,
            'cache_ttl' => 3600,
            'leeway' => 0,
        ], $config));

        app()->forgetInstance(OidcSettings::class);

        $provider = new self;
        $provider->fake();

        return $provider;
    }

    public function fake(): void
    {
        Http::fake([
            self::ISSUER.'/.well-known/openid-configuration' => fn () => Http::response([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => self::ISSUER.'/protocol/openid-connect/auth',
                'token_endpoint' => self::ISSUER.'/protocol/openid-connect/token',
                'jwks_uri' => self::ISSUER.'/protocol/openid-connect/certs',
                'end_session_endpoint' => self::ISSUER.'/protocol/openid-connect/logout',
                'token_endpoint_auth_methods_supported' => $this->authMethods,
            ]),
            self::ISSUER.'/protocol/openid-connect/certs' => function () {
                $this->jwksRequests++;

                return Http::response(['keys' => [$this->publicJwk()]]);
            },
            self::ISSUER.'/protocol/openid-connect/token' => function (Request $request) {
                $this->tokenRequests[] = $request;

                return Http::response(
                    $this->tokenStatus === 200 ? ['access_token' => 'at', 'token_type' => 'Bearer', 'id_token' => $this->idToken] : ['error' => 'invalid_grant'],
                    $this->tokenStatus,
                );
            },
        ]);
    }

    /**
     * Sign an ID token for the given nonce. Claims can be overridden or
     * removed (by passing null).
     *
     * @param  array<string, mixed>  $overrides
     */
    public function issue(string $nonce, array $overrides = [], ?OpenSSLAsymmetricKey $signWith = null): string
    {
        $now = time();

        $claims = array_merge([
            'iss' => self::ISSUER,
            'sub' => 'user-123',
            'aud' => self::CLIENT_ID,
            'iat' => $now,
            'exp' => $now + 300,
            'nonce' => $nonce,
            'email' => 'ada@example.com',
            'email_verified' => true,
            'name' => 'Ada Lovelace',
        ], $this->claims, $overrides);

        $claims = array_filter($claims, fn (mixed $value): bool => $value !== null);

        return $this->idToken = JWT::encode($claims, $this->privatePem($signWith ?? $this->key), 'RS256', $this->kid);
    }

    /**
     * Swap the signing key, as a provider does during key rotation.
     */
    public function rotateKey(string $kid): void
    {
        $this->key = self::newKey();
        $this->kid = $kid;
    }

    public static function newKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        assert($key instanceof OpenSSLAsymmetricKey);

        return $key;
    }

    /**
     * @return array<string, string>
     */
    private function publicJwk(): array
    {
        $details = openssl_pkey_get_details($this->key);
        assert(is_array($details));

        $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

        return [
            'kty' => 'RSA',
            'kid' => $this->kid,
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => $encode($details['rsa']['n']),
            'e' => $encode($details['rsa']['e']),
        ];
    }

    private function privatePem(OpenSSLAsymmetricKey $key): string
    {
        openssl_pkey_export($key, $pem);

        return $pem;
    }
}
