<?php

declare(strict_types=1);

namespace App\Auth\Oidc;

use RuntimeException;

/**
 * A failed OpenID Connect login. The message is safe to show to users;
 * technical details go into the "reason" for logging.
 */
final class OidcException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = '')
    {
        parent::__construct($message);
    }

    public static function invalidDiscovery(string $reason): self
    {
        return new self(__('The login provider is not configured correctly.'), "discovery: {$reason}");
    }

    public static function providerUnavailable(string $reason): self
    {
        return new self(__('The login provider could not be reached. Please try again later.'), $reason);
    }

    public static function invalidState(): self
    {
        return new self(__('The login request has expired. Please try again.'), 'state mismatch or missing session');
    }

    public static function denied(string $error): self
    {
        return new self(__('The login was cancelled or denied by the provider.'), "provider error: {$error}");
    }

    public static function invalidToken(string $reason): self
    {
        return new self(__('The login could not be verified. Please try again.'), "id token: {$reason}");
    }

    public static function missingEmail(): self
    {
        return new self(__('Your login provider did not share an email address. Please allow access to your email address and try again.'), 'no email claim');
    }

    public static function emailTaken(): self
    {
        return new self(__('An account with this email address already exists. Please log in with your password instead.'), 'email belongs to an unlinked account');
    }
}
