<?php

declare(strict_types=1);

namespace App\Auth\Oidc;

/**
 * Immutable view of config/oidc.php.
 */
final readonly class OidcSettings
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public bool $enabled,
        public string $issuer,
        public string $clientId,
        public string $clientSecret,
        public array $scopes,
        public ?string $buttonLabel,
        public bool $autoRedirect,
        public bool $logoutAtProvider,
        public int $cacheTtl,
        public int $leeway,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $string = static fn (string $key): string => is_scalar($config[$key] ?? null) ? trim((string) $config[$key]) : '';

        $scopes = preg_split('/[\s,]+/', $string('scopes'), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        if (! in_array('openid', $scopes, true)) {
            array_unshift($scopes, 'openid');
        }

        $issuer = $string('issuer');
        $clientId = $string('client_id');

        return new self(
            enabled: (bool) ($config['enabled'] ?? false) && $issuer !== '' && $clientId !== '',
            issuer: rtrim($issuer, '/'),
            clientId: $clientId,
            clientSecret: $string('client_secret'),
            scopes: array_values(array_unique($scopes)),
            buttonLabel: $string('button_label') ?: null,
            autoRedirect: (bool) ($config['auto_redirect'] ?? false),
            logoutAtProvider: (bool) ($config['logout_at_provider'] ?? false),
            cacheTtl: max(0, (int) ($config['cache_ttl'] ?? 3600)),
            leeway: max(0, (int) ($config['leeway'] ?? 60)),
        );
    }
}
