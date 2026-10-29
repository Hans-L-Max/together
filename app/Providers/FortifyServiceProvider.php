<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Auth\Oidc\OidcSettings;
use App\Http\Responses\LogoutResponse;
use App\Models\User;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LogoutResponseContract::class, LogoutResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();

        // The session is gone once the logout response is built, so remember
        // on the request whether the account belongs to the OIDC provider.
        Event::listen(function (Logout $event): void {
            if ($event->user instanceof User && $event->user->usesOidc()) {
                $this->app->make('request')->attributes->set(LogoutResponse::OIDC_ATTRIBUTE, true);
            }
        });
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);

        // Local login can be switched off (OIDC only). Accounts created via
        // OIDC have no password and can never sign in with one.
        Fortify::authenticateUsing(function (Request $request): ?User {
            if (! config('auth.local_enabled')) {
                return null;
            }

            $user = User::query()->where('email', (string) $request->input(Fortify::username()))->first();

            if ($user === null || ! $user->hasPassword()) {
                return null;
            }

            return $this->app->make(Hasher::class)->check((string) $request->input('password'), (string) $user->password)
                ? $user
                : null;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(function (Request $request): View|RedirectResponse {
            $oidc = $this->app->make(OidcSettings::class);
            $localEnabled = (bool) config('auth.local_enabled');

            // Not after a failed attempt, otherwise errors would loop back to the provider.
            if ($oidc->enabled && $oidc->autoRedirect && ! $localEnabled && ! $request->session()->has('errors')) {
                return redirect()->route('oidc.redirect');
            }

            return view('pages::auth.login', [
                'localEnabled' => $localEnabled,
                'oidcEnabled' => $oidc->enabled,
                'oidcLabel' => $oidc->buttonLabel ?? __('Log in with single sign-on'),
            ]);
        });
        Fortify::verifyEmailView(fn () => view('pages::auth.verify-email'));
        Fortify::confirmPasswordView(fn () => view('pages::auth.confirm-password'));
        Fortify::registerView(fn () => view('pages::auth.register'));
        Fortify::resetPasswordView(fn () => view('pages::auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('pages::auth.forgot-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
