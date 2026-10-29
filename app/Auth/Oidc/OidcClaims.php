<?php

declare(strict_types=1);

namespace App\Auth\Oidc;

/**
 * Verified identity claims taken from an ID token.
 */
final readonly class OidcClaims
{
    public function __construct(
        public string $issuer,
        public string $subject,
        public ?string $email,
        public bool $emailVerified,
        public ?string $name,
    ) {}

    /**
     * @param  array<string, mixed>  $claims
     */
    public static function fromArray(array $claims): self
    {
        $string = static fn (string $key): ?string => isset($claims[$key]) && is_string($claims[$key]) && trim($claims[$key]) !== ''
            ? trim($claims[$key])
            : null;

        $fullName = trim(($string('given_name') ?? '').' '.($string('family_name') ?? ''));

        $email = $string('email');
        $verified = $claims['email_verified'] ?? false;

        return new self(
            issuer: (string) $string('iss'),
            subject: (string) $string('sub'),
            email: $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower($email) : null,
            emailVerified: $verified === true || $verified === 'true',
            name: $string('name') ?? ($fullName !== '' ? $fullName : null) ?? $string('preferred_username'),
        );
    }
}
