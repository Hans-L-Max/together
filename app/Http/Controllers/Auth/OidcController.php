<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ResolveOidcUser;
use App\Auth\Oidc\OidcClient;
use App\Auth\Oidc\OidcException;
use App\Auth\Oidc\OidcSettings;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

final class OidcController extends Controller
{
    private const string SESSION_KEY = 'oidc.pending';

    public function __construct(
        private readonly OidcSettings $settings,
        private readonly OidcClient $client,
        private readonly StatefulGuard $guard,
        private readonly LoggerInterface $logger,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        abort_unless($this->settings->enabled, 404);

        $pending = [
            'state' => Str::random(40),
            'nonce' => Str::random(40),
            'verifier' => Str::random(64),
        ];

        try {
            $url = $this->client->authorizationUrl(
                route('oidc.callback'),
                $pending['state'],
                $pending['nonce'],
                $pending['verifier'],
            );
        } catch (OidcException $e) {
            return $this->failed($e);
        }

        $request->session()->put(self::SESSION_KEY, $pending);

        return redirect()->away($url);
    }

    public function callback(Request $request, ResolveOidcUser $resolveUser): RedirectResponse
    {
        abort_unless($this->settings->enabled, 404);

        /** @var array{state: string, nonce: string, verifier: string}|null $pending */
        $pending = $request->session()->pull(self::SESSION_KEY);

        try {
            $state = $request->query('state');

            if ($pending === null || ! is_string($state) || ! hash_equals($pending['state'], $state)) {
                throw OidcException::invalidState();
            }

            $error = $request->query('error');

            if (is_string($error) && $error !== '') {
                throw OidcException::denied($error);
            }

            $code = $request->query('code');

            if (! is_string($code) || $code === '') {
                throw OidcException::invalidToken('callback without code');
            }

            $claims = $this->client->authenticate($code, route('oidc.callback'), $pending['verifier'], $pending['nonce']);
            $user = $resolveUser($claims);
        } catch (OidcException $e) {
            return $this->failed($e);
        }

        $this->guard->login($user);
        $request->session()->regenerate();

        return redirect()->intended(config('fortify.home'));
    }

    private function failed(OidcException $e): RedirectResponse
    {
        $this->logger->warning('OIDC login failed', ['reason' => $e->reason]);

        return redirect()->route('login')->withErrors(['oidc' => $e->getMessage()]);
    }
}
