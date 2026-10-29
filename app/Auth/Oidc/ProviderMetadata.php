<?php

declare(strict_types=1);

namespace App\Auth\Oidc;

/**
 * The parts of a provider's discovery document we rely on.
 */
final readonly class ProviderMetadata
{
    /**
     * @param  list<string>  $tokenEndpointAuthMethods
     */
    public function __construct(
        public string $issuer,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public string $jwksUri,
        public ?string $endSessionEndpoint,
        public array $tokenEndpointAuthMethods,
    ) {}

    /**
     * @param  array<mixed>  $document
     *
     * @throws OidcException
     */
    public static function fromDiscovery(array $document): self
    {
        $url = static function (string $key) use ($document): string {
            $value = $document[$key] ?? null;

            if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
                throw OidcException::invalidDiscovery("missing or invalid \"{$key}\"");
            }

            return $value;
        };

        $methods = $document['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic'];
        $endSession = $document['end_session_endpoint'] ?? null;

        return new self(
            issuer: $url('issuer'),
            authorizationEndpoint: $url('authorization_endpoint'),
            tokenEndpoint: $url('token_endpoint'),
            jwksUri: $url('jwks_uri'),
            endSessionEndpoint: is_string($endSession) && $endSession !== '' ? $endSession : null,
            tokenEndpointAuthMethods: is_array($methods) ? array_values(array_filter($methods, is_string(...))) : [],
        );
    }
}
