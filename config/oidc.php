<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | OpenID Connect
    |--------------------------------------------------------------------------
    |
    | Login through any standards-compliant OpenID Connect provider (Authentik,
    | Keycloak, Authelia, Zitadel, ...). Endpoints are discovered from the
    | issuer's ".well-known/openid-configuration" document. Register the
    | callback URL "<APP_URL>/auth/oidc/callback" with your provider.
    |
    */

    'enabled' => (bool) env('OIDC_ENABLED', false),

    'issuer' => env('OIDC_ISSUER'),

    'client_id' => env('OIDC_CLIENT_ID'),

    'client_secret' => env('OIDC_CLIENT_SECRET'),

    'scopes' => env('OIDC_SCOPES', 'openid profile email'),

    'button_label' => env('OIDC_BUTTON_LABEL'),

    // Skip the login form and send visitors straight to the provider. Only
    // takes effect when local email/password login is disabled.
    'auto_redirect' => (bool) env('OIDC_AUTO_REDIRECT', false),

    // Also end the session at the provider on logout (RP-initiated logout).
    'logout_at_provider' => (bool) env('OIDC_LOGOUT_AT_PROVIDER', false),

    // Seconds the discovery document and signing keys are cached.
    'cache_ttl' => (int) env('OIDC_CACHE_TTL', 3600),

    // Allowed clock skew in seconds when checking token timestamps.
    'leeway' => 60,

];
