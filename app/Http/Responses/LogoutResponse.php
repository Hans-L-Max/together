<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Auth\Oidc\OidcClient;
use App\Auth\Oidc\OidcSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;

/**
 * After logout, optionally end the session at the OpenID provider too.
 */
final readonly class LogoutResponse implements LogoutResponseContract
{
    /**
     * Request attribute set by the logout listener for OIDC-linked accounts.
     */
    public const string OIDC_ATTRIBUTE = 'oidc.logout';

    public function __construct(
        private OidcSettings $settings,
        private OidcClient $client,
    ) {}

    /**
     * @param  Request  $request
     */
    public function toResponse($request): RedirectResponse
    {
        if ($this->settings->enabled && $this->settings->logoutAtProvider && $request->attributes->get(self::OIDC_ATTRIBUTE) === true) {
            $url = $this->client->logoutUrl(route('home'));

            if ($url !== null) {
                return redirect()->away($url);
            }
        }

        return redirect()->route('home');
    }
}
