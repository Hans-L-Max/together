<?php

declare(strict_types=1);

use App\Auth\Oidc\OidcClient;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\FakeOidcProvider;

/**
 * Start the login flow and return the parameters sent to the provider.
 *
 * @return array<string, string>
 */
function startOidcLogin(): array
{
    $response = test()->get(route('oidc.redirect'));
    $response->assertRedirect();

    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith(FakeOidcProvider::ISSUER.'/protocol/openid-connect/auth?');

    /** @var array<string, string> $query */
    return $query;
}

function finishOidcLogin(string $state, string $code = 'auth-code'): TestResponse
{
    return test()->get(route('oidc.callback', ['state' => $state, 'code' => $code]));
}

beforeEach(function () {
    $this->provider = FakeOidcProvider::install();
});

test('login screen offers single sign-on when enabled', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('data-test="oidc-login-button"', false)
        ->assertSee('data-test="login-button"', false);
});

test('login screen hides single sign-on when disabled', function () {
    FakeOidcProvider::install(['enabled' => false]);

    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('data-test="oidc-login-button"', false);

    $this->get(route('oidc.redirect'))->assertNotFound();
    $this->get(route('oidc.callback'))->assertNotFound();
});

test('oidc counts as disabled without issuer or client id', function () {
    FakeOidcProvider::install(['issuer' => '']);

    $this->get(route('oidc.redirect'))->assertNotFound();
});

test('redirect uses the authorization code flow with pkce, state and nonce', function () {
    $query = startOidcLogin();

    expect($query)
        ->response_type->toBe('code')
        ->client_id->toBe(FakeOidcProvider::CLIENT_ID)
        ->redirect_uri->toBe(route('oidc.callback'))
        ->scope->toBe('openid profile email')
        ->code_challenge_method->toBe('S256')
        ->and(strlen($query['state']))->toBeGreaterThanOrEqual(32)
        ->and(strlen($query['nonce']))->toBeGreaterThanOrEqual(32);

    $pending = session('oidc.pending');

    expect($pending['state'])->toBe($query['state'])
        ->and($pending['nonce'])->toBe($query['nonce'])
        ->and(OidcClient::codeChallenge($pending['verifier']))->toBe($query['code_challenge']);
});

test('a new user is created and logged in', function () {
    $query = startOidcLogin();
    $this->provider->issue($query['nonce']);

    finishOidcLogin($query['state'])->assertRedirect(route('dashboard', absolute: false));

    $user = User::query()->sole();

    $this->assertAuthenticatedAs($user);
    expect($user)
        ->email->toBe('ada@example.com')
        ->name->toBe('Ada Lovelace')
        ->oidc_issuer->toBe(FakeOidcProvider::ISSUER)
        ->oidc_subject->toBe('user-123')
        ->password->toBeNull()
        ->email_verified_at->not->toBeNull();
});

test('the code is exchanged with the pkce verifier and client credentials', function () {
    $query = startOidcLogin();
    $verifier = session('oidc.pending')['verifier'];
    $this->provider->issue($query['nonce']);

    finishOidcLogin($query['state'], 'the-code');

    $request = $this->provider->tokenRequests[0];

    expect($request->data())
        ->grant_type->toBe('authorization_code')
        ->code->toBe('the-code')
        ->code_verifier->toBe($verifier)
        ->redirect_uri->toBe(route('oidc.callback'))
        ->not->toHaveKey('client_secret')
        ->and($request->header('Authorization')[0])
        ->toBe('Basic '.base64_encode(FakeOidcProvider::CLIENT_ID.':'.FakeOidcProvider::CLIENT_SECRET));
});

test('the client secret is posted when the provider does not support basic auth', function () {
    $this->provider->authMethods = ['client_secret_post'];

    $query = startOidcLogin();
    $this->provider->issue($query['nonce']);

    finishOidcLogin($query['state']);

    expect($this->provider->tokenRequests[0]->data())->client_secret->toBe(FakeOidcProvider::CLIENT_SECRET);
});

test('a returning user is matched by issuer and subject', function () {
    $user = User::factory()->create([
        'email' => 'old-address@example.com',
        'password' => null,
        'oidc_issuer' => FakeOidcProvider::ISSUER,
        'oidc_subject' => 'user-123',
    ]);

    $query = startOidcLogin();
    $this->provider->issue($query['nonce']);

    finishOidcLogin($query['state'])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect(User::query()->count())->toBe(1);
});

test('an unverified email from the provider is not marked verified', function () {
    $query = startOidcLogin();
    $this->provider->issue($query['nonce'], ['email_verified' => false]);

    finishOidcLogin($query['state']);

    expect(User::query()->sole()->email_verified_at)->toBeNull();
});

test('an existing local account is never linked by email', function () {
    $local = User::factory()->create(['email' => 'ada@example.com']);

    $query = startOidcLogin();
    $this->provider->issue($query['nonce']);

    finishOidcLogin($query['state'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('oidc');

    $this->assertGuest();
    expect($local->fresh()->oidc_subject)->toBeNull()
        ->and(User::query()->count())->toBe(1);
});

test('a login without an email claim is rejected', function () {
    $query = startOidcLogin();
    $this->provider->issue($query['nonce'], ['email' => null]);

    finishOidcLogin($query['state'])->assertSessionHasErrors('oidc');

    $this->assertGuest();
});

test('the id token is rejected when', function (Closure $tamper) {
    $query = startOidcLogin();
    $tamper($this->provider, $query['nonce']);

    finishOidcLogin($query['state'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('oidc');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
})->with([
    'the nonce does not match' => fn () => fn (FakeOidcProvider $p) => $p->issue('another-nonce'),
    'the audience is another client' => fn () => fn (FakeOidcProvider $p, string $nonce) => $p->issue($nonce, ['aud' => 'someone-else']),
    'the issuer differs' => fn () => fn (FakeOidcProvider $p, string $nonce) => $p->issue($nonce, ['iss' => 'https://evil.example.test']),
    'it has expired' => fn () => fn (FakeOidcProvider $p, string $nonce) => $p->issue($nonce, ['iat' => time() - 600, 'exp' => time() - 300]),
    'it is signed with an unknown key' => fn () => fn (FakeOidcProvider $p, string $nonce) => $p->issue($nonce, signWith: FakeOidcProvider::newKey()),
    'several audiences lack an authorized party' => fn () => fn (FakeOidcProvider $p, string $nonce) => $p->issue($nonce, ['aud' => [FakeOidcProvider::CLIENT_ID, 'other']]),
    'the subject is missing' => fn () => fn (FakeOidcProvider $p, string $nonce) => $p->issue($nonce, ['sub' => null]),
    'the token endpoint fails' => fn () => function (FakeOidcProvider $p, string $nonce) {
        $p->issue($nonce);
        $p->tokenStatus = 400;
    },
]);

test('an unsigned token is rejected', function () {
    $query = startOidcLogin();
    $header = rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT"}'), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode((string) json_encode([
        'iss' => FakeOidcProvider::ISSUER, 'sub' => 'x', 'aud' => FakeOidcProvider::CLIENT_ID,
        'iat' => time(), 'exp' => time() + 60, 'nonce' => $query['nonce'], 'email' => 'x@example.com',
    ])), '+/', '-_'), '=');
    $this->provider->idToken = "{$header}.{$payload}.";

    finishOidcLogin($query['state'])->assertSessionHasErrors('oidc');

    $this->assertGuest();
});

test('the callback rejects a state that does not match', function () {
    $query = startOidcLogin();
    $this->provider->issue($query['nonce']);

    finishOidcLogin('forged-state')->assertSessionHasErrors('oidc');

    $this->assertGuest();
    expect($this->provider->tokenRequests)->toBeEmpty();
});

test('the callback cannot be replayed', function () {
    $query = startOidcLogin();
    $this->provider->issue($query['nonce']);

    finishOidcLogin($query['state']);
    auth()->logout();

    finishOidcLogin($query['state'])->assertSessionHasErrors('oidc');
    $this->assertGuest();
});

test('an error from the provider is shown on the login screen', function () {
    $query = startOidcLogin();

    $this->get(route('oidc.callback', ['state' => $query['state'], 'error' => 'access_denied']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('oidc');

    $this->assertGuest();
});

test('signing keys are refetched after a key rotation', function () {
    $query = startOidcLogin();
    $this->provider->issue($query['nonce']);
    finishOidcLogin($query['state']);
    auth()->logout();

    expect($this->provider->jwksRequests)->toBe(1);

    $this->provider->rotateKey('key-2');
    $query = startOidcLogin();
    $this->provider->issue($query['nonce']);

    finishOidcLogin($query['state'])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
    expect($this->provider->jwksRequests)->toBe(2);
});

test('an unreachable provider shows a friendly error', function () {
    FakeOidcProvider::install(['issuer' => 'https://down.example.test']);

    $this->get(route('oidc.redirect'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('oidc');
});

describe('with local login disabled', function () {
    beforeEach(function () {
        config()->set('auth.local_enabled', false);
    });

    test('the password form is hidden', function () {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('data-test="oidc-login-button"', false)
            ->assertDontSee('data-test="login-button"', false);
    });

    test('passwords are not accepted', function () {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    });

    test('the login screen can send visitors straight to the provider', function () {
        FakeOidcProvider::install(['auto_redirect' => true]);

        $this->get(route('login'))->assertRedirect(route('oidc.redirect'));
    });

    test('auto redirect pauses after a failed attempt', function () {
        $this->provider = FakeOidcProvider::install(['auto_redirect' => true]);

        $query = startOidcLogin();

        $this->get(route('oidc.callback', ['state' => $query['state'], 'error' => 'access_denied']))
            ->assertRedirect(route('login'));

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('data-test="oidc-error"', false);
    });
});

test('accounts without a password cannot log in with an empty password', function () {
    $user = User::factory()->create(['password' => null, 'oidc_issuer' => FakeOidcProvider::ISSUER, 'oidc_subject' => 'abc']);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => ''])->assertSessionHasErrors();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('logout ends the provider session when configured', function () {
    FakeOidcProvider::install(['logout_at_provider' => true]);
    $user = User::factory()->create(['password' => null, 'oidc_issuer' => FakeOidcProvider::ISSUER, 'oidc_subject' => 'abc']);

    $response = $this->actingAs($user)->post(route('logout'));

    $this->assertGuest();
    expect($response->headers->get('Location'))
        ->toStartWith(FakeOidcProvider::ISSUER.'/protocol/openid-connect/logout?')
        ->toContain('client_id='.FakeOidcProvider::CLIENT_ID)
        ->toContain('post_logout_redirect_uri='.rawurlencode(route('home')));
});

test('logout of a local account stays in the app', function () {
    FakeOidcProvider::install(['logout_at_provider' => true]);
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('logout'))->assertRedirect(route('home'));
});

test('security settings do not ask accounts without a password to confirm one', function () {
    $user = User::factory()->create(['password' => null, 'oidc_issuer' => FakeOidcProvider::ISSUER, 'oidc_subject' => 'abc']);

    $this->actingAs($user)
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee('data-test="passwordless-notice"', false);
});

test('accounts without a password confirm deletion with their email address', function () {
    $user = User::factory()->create(['email' => 'ada@example.com', 'password' => null, 'oidc_issuer' => FakeOidcProvider::ISSUER, 'oidc_subject' => 'abc']);
    $this->actingAs($user);

    Livewire::test('pages::settings.delete-user-modal')
        ->set('email', 'wrong@example.com')
        ->call('deleteUser')
        ->assertHasErrors('email');

    expect($user->fresh())->not->toBeNull();

    Livewire::test('pages::settings.delete-user-modal')
        ->set('email', 'ada@example.com')
        ->call('deleteUser')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect($user->fresh())->toBeNull();
});

test('a password can still be checked for local accounts', function () {
    $user = User::factory()->create(['password' => Hash::make('secret-password')]);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'secret-password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});
