<?php

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\AccountLifecycle;
use App\Services\SecondFactor;
use App\Services\SessionPolicy;
use Database\Factories\UserFactory;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

/**
 * Signs in with the password step so the session holds a pending 2FA login.
 */
function startTwoFactorLogin(User $user): void
{
    test()->post(route('login.store'), [
        'email' => $user->email,
        'password' => UserFactory::PASSWORD,
    ])->assertRedirect(route('two-factor.login'));
}

it('completes sign-in with a valid authenticator code', function () {
    $secret = app(Google2FA::class)->generateSecretKey();
    $user = User::factory()->withTwoFactor($secret)->create();

    startTwoFactorLogin($user);

    $this->post(route('two-factor.login.store'), [
        'code' => app(Google2FA::class)->getCurrentOtp($secret),
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect(AuditEvent::where('event', 'mfa.challenge.passed')->exists())->toBeTrue();
});

it('rejects an invalid authenticator code and audits it', function () {
    $user = User::factory()->withTwoFactor()->create();

    startTwoFactorLogin($user);

    $this->post(route('two-factor.login.store'), ['code' => '000000'])
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHasErrors('code');

    $this->assertGuest();
    expect(AuditEvent::where('event', 'mfa.challenge.failed')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('accepts a recovery code once and then retires it', function () {
    $user = User::factory()->withTwoFactor()->create();
    $code = $user->recoveryCodes()[0];

    startTwoFactorLogin($user);
    $this->post(route('two-factor.login.store'), ['recovery_code' => $code])
        ->assertRedirect(route('dashboard'));
    $this->post(route('logout'));

    startTwoFactorLogin($user);
    $this->post(route('two-factor.login.store'), ['recovery_code' => $code])
        ->assertSessionHasErrors('recovery_code');

    $this->assertGuest();
    expect($user->fresh()->recoveryCodes())->not->toContain($code);
});

it('forces a user without an authenticator to enrol before using the system', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('two-factor.setup'));
});

it('lets users use the system without an authenticator when MFA is not required', function () {
    config(['crms.auth.require_mfa' => false]);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

it('shows a QR code and setup key on the enrolment page without regenerating them', function () {
    $user = User::factory()->create();

    $first = $this->actingAs($user)->get(route('two-factor.setup'));
    $first->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('auth/TwoFactorSetup')
        ->where('step', 'scan')
        ->whereType('qrCodeSvg', 'string')
        ->whereType('setupKey', 'string'));

    $this->get(route('two-factor.setup'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('setupKey', $first->inertiaProps('setupKey')));
});

it('confirms enrolment with a valid code and then shows the recovery codes once', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('two-factor.setup'));
    $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);

    $this->from(route('two-factor.setup'))
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.confirm'), ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
        ->assertRedirect(route('two-factor.setup'));

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();

    $this->get(route('two-factor.setup'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('step', 'recovery')
            ->has('recoveryCodes', 8)
            ->where('flash.status', null));

    $this->get(route('two-factor.setup'))->assertRedirect(route('dashboard'));
});

it('does not let users switch off mandatory multi-factor authentication', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time(), SecondFactor::CONFIRMED_AT => time()])
        ->delete(route('two-factor.disable'))
        ->assertSessionHasErrors(['two_factor' => 'Multi-factor authentication is mandatory and cannot be switched off.']);

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

it('does not replace a confirmed authenticator through a forced enable request', function () {
    $user = User::factory()->withTwoFactor()->create();
    $originalSecret = $user->two_factor_secret;

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.enable'), ['force' => true]);

    expect($user->fresh()->two_factor_secret)->toBe($originalSecret);
});

it('does not finish signing in an account suspended after the password step', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => UserFactory::PASSWORD])
        ->assertRedirect(route('two-factor.login'));

    app(AccountLifecycle::class)->suspend($user, 'Investigation');

    $this->post(route('two-factor.login.store'), ['code' => currentAuthenticatorCode($user)])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(AuditEvent::where('event', 'auth.login')->exists())->toBeFalse()
        ->and(AuditEvent::where('event', 'auth.login.blocked')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('never shows the secret of a linked authenticator again', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    $this->getJson(route('two-factor.secret-key'))->assertForbidden();
    $this->getJson(route('two-factor.qr-code'))->assertForbidden();
});

it('shows recovery codes only after the user proves their authenticator', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    $this->getJson(route('two-factor.recovery-codes'))
        ->assertStatus(423)
        ->assertJson(['second_factor_required' => true]);

    $this->postJson(route('security.second-factor.confirm'), ['code' => '000000'])
        ->assertJsonValidationErrors('code');

    $this->postJson(route('security.second-factor.confirm'), ['code' => currentAuthenticatorCode($user)])
        ->assertNoContent();

    $this->getJson(route('two-factor.recovery-codes'))
        ->assertOk()
        ->assertJson($user->recoveryCodes());

    expect(AuditEvent::where('event', 'mfa.step_up.failed')->count())->toBe(1)
        ->and(AuditEvent::where('event', 'mfa.step_up.passed')->count())->toBe(1);
});

it('does not replace recovery codes without a recent proof of the authenticator', function () {
    $user = User::factory()->withTwoFactor()->create();
    $codes = $user->recoveryCodes();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.regenerate-recovery-codes'))
        ->assertSessionHas('error');

    expect($user->fresh()->recoveryCodes())->toBe($codes);

    $this->travel(SecondFactor::CONFIRMATION_SECONDS + 1)->seconds();
    $this->withSession([SecondFactor::CONFIRMED_AT => now()->subSeconds(SecondFactor::CONFIRMATION_SECONDS)->getTimestamp()])
        ->post(route('two-factor.regenerate-recovery-codes'))
        ->assertSessionHas('error');

    expect($user->fresh()->recoveryCodes())->toBe($codes);
});

it('uses up a recovery code given as proof', function () {
    $user = User::factory()->withTwoFactor()->create();
    $recoveryCode = $user->recoveryCodes()[0];

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('security.second-factor.confirm'), ['recovery_code' => $recoveryCode])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->recoveryCodes())->not->toContain($recoveryCode);
});

it('switches optional multi-factor authentication off only after proof, and signs out other sessions', function () {
    config(['crms.auth.require_mfa' => false]);
    $user = User::factory()->withTwoFactor()->create();
    $otherDeviceStamp = app(SessionPolicy::class)->stampFor($user);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('two-factor.disable'))
        ->assertSessionHas('error');

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();

    $this->withSession([SecondFactor::CONFIRMED_AT => time()])
        ->delete(route('two-factor.disable'))
        ->assertSessionHasNoErrors();

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();

    $this->actingAs($user->fresh())
        ->withSession([SessionPolicy::STAMP => $otherDeviceStamp])
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));
});
