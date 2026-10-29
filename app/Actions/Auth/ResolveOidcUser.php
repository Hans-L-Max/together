<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Auth\Oidc\OidcClaims;
use App\Auth\Oidc\OidcException;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Finds the account linked to an OpenID identity or creates a new one.
 *
 * Accounts are matched by (issuer, subject) only. An existing account with the
 * same email address is never linked automatically, because that would let
 * anyone who controls the address at the provider take over the account.
 */
final readonly class ResolveOidcUser
{
    /**
     * @throws OidcException
     */
    public function __invoke(OidcClaims $claims): User
    {
        $user = User::query()
            ->where('oidc_issuer', $claims->issuer)
            ->where('oidc_subject', $claims->subject)
            ->first();

        if ($user !== null) {
            if ($claims->emailVerified && $user->email_verified_at === null && $user->email === $claims->email) {
                $user->forceFill(['email_verified_at' => Carbon::now()])->save();
            }

            return $user;
        }

        if ($claims->email === null) {
            throw OidcException::missingEmail();
        }

        if (User::query()->where('email', $claims->email)->exists()) {
            throw OidcException::emailTaken();
        }

        $user = new User;
        $user->forceFill([
            'name' => mb_substr($claims->name ?? strstr($claims->email, '@', true) ?: $claims->email, 0, 255),
            'email' => $claims->email,
            'email_verified_at' => $claims->emailVerified ? Carbon::now() : null,
            'password' => null,
            'oidc_issuer' => $claims->issuer,
            'oidc_subject' => $claims->subject,
        ])->save();

        return $user;
    }
}
