<?php

use App\Http\Requests\Settings\SecondFactorRequest;
use App\Models\AuditEvent;
use App\Models\User;
use App\Notifications\SignInCodesLocked;
use App\Services\AccountAttemptLimiter;
use App\Services\AccountLifecycle;
use App\Services\SecondFactor;
use App\Services\SecondFactorCheck;
use App\Services\SessionPolicy;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

/**
 * Whether the query reads a user's stored recovery codes.
 */
function readsRecoveryCodes(QueryExecuted $query): bool
{
    return str_starts_with($query->sql, 'select') && str_contains($query->sql, '"two_factor_recovery_codes" from "users"');
}

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

it('sends a refused Inertia DELETE back with GET', function () {
    config(['crms.auth.require_mfa' => false]);
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('security.show'))
        ->withHeader('X-Inertia', 'true')
        ->delete(route('two-factor.disable'))
        ->assertStatus(303)
        ->assertRedirect(route('security.show'));
});

it('locks authenticator code checks after five wrong codes', function () {
    startAttemptWindow();
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    foreach (range(1, AccountAttemptLimiter::MAX_ATTEMPTS) as $attempt) {
        $this->postJson(route('security.second-factor.confirm'), ['code' => '000000'])->assertJsonValidationErrors('code');
        $this->travel(13)->seconds(); // stay under the route's 5-a-minute throttle
    }

    $this->postJson(route('security.second-factor.confirm'), ['code' => currentAuthenticatorCode($user)])
        ->assertJsonValidationErrors(['code' => 'Too many incorrect codes']);

    expect(AuditEvent::where('event', 'mfa.check.locked')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('uses up a recovery code once even when two requests validated it', function () {
    $user = User::factory()->withTwoFactor()->create();
    $recoveryCode = $user->recoveryCodes()[0];
    $secondFactor = app(SecondFactor::class);

    // Both requests loaded the user while the code was still unused.
    $firstRequestUser = User::find($user->id);
    $secondRequestUser = User::find($user->id);

    expect($secondFactor->consumeRecoveryCode($firstRequestUser, $recoveryCode))->toBeTrue()
        ->and($secondFactor->consumeRecoveryCode($secondRequestUser, $recoveryCode))->toBeFalse();
});

it('does not sign out other sessions when abandoning an unconfirmed enrolment', function () {
    config(['crms.auth.require_mfa' => false]);
    $user = User::factory()->create();
    $otherDeviceStamp = app(SessionPolicy::class)->stampFor($user);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('two-factor.disable'))
        ->assertSessionHasNoErrors();

    $this->actingAs($user->fresh())
        ->withSession([SessionPolicy::STAMP => $otherDeviceStamp])
        ->get(route('dashboard'))
        ->assertOk();
});

it('keeps the sign-in challenge when another sign-in used the same recovery code first', function () {
    $user = User::factory()->withTwoFactor()->create();
    $recoveryCode = $user->recoveryCodes()[0];

    $this->post(route('login.store'), ['email' => $user->email, 'password' => UserFactory::PASSWORD])
        ->assertRedirect(route('two-factor.login'));

    // Another sign-in uses the same code just after this one has read the codes.
    $otherSignInDone = false;
    DB::listen(function (QueryExecuted $query) use (&$otherSignInDone, $user, $recoveryCode): void {
        if ($otherSignInDone || ! str_contains($query->sql, 'two_factor_recovery_codes')) {
            return;
        }

        $otherSignInDone = true;
        app(SecondFactor::class)->consumeRecoveryCode(User::find($user->id), $recoveryCode);
    });

    $this->post(route('two-factor.login.store'), ['recovery_code' => $recoveryCode])
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHasErrors('recovery_code');

    expect($otherSignInDone)->toBeTrue();
    $this->assertGuest();
    $this->get(route('two-factor.login'))->assertOk();
});

it('accepts a valid recovery code however often other codes change while it is used', function () {
    $user = User::factory()->withTwoFactor()->create();
    $codes = $user->recoveryCodes();
    $givenCode = $codes[0];
    $codesUsedMeanwhile = 0;
    $otherRequestRunning = false;

    // After each of the first four reads, another request uses a different
    // code first, so this request's save keeps failing and is retried.
    DB::listen(function (QueryExecuted $query) use (&$codesUsedMeanwhile, &$otherRequestRunning, $codes, $user): void {
        if ($otherRequestRunning || $codesUsedMeanwhile >= 4 || ! readsRecoveryCodes($query)) {
            return;
        }

        $otherRequestRunning = true;
        $codesUsedMeanwhile++;
        app(SecondFactor::class)->consumeRecoveryCode(User::find($user->id), $codes[$codesUsedMeanwhile]);
        $otherRequestRunning = false;
    });

    expect(app(SecondFactor::class)->consumeRecoveryCode($user, $givenCode))->toBeTrue()
        ->and($codesUsedMeanwhile)->toBe(4)
        ->and($user->fresh()->recoveryCodes())->not->toContain($givenCode);
});

it('locks the sign-in challenge per account after five wrong codes, however often it is restarted', function () {
    startAttemptWindow();
    $user = User::factory()->withTwoFactor()->create();

    foreach (range(1, AccountAttemptLimiter::MAX_ATTEMPTS) as $attempt) {
        startTwoFactorLogin($user);
        $this->post(route('two-factor.login.store'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->travel(13)->seconds(); // stay under the challenge's 5-a-minute throttle
    }

    startTwoFactorLogin($user);
    $this->post(route('two-factor.login.store'), ['code' => currentAuthenticatorCode($user)])
        ->assertSessionHasErrors('code');

    expect(session('errors')->first('code'))->toStartWith('Too many incorrect codes.');

    $this->assertGuest();
    expect(AuditEvent::where('event', 'mfa.check.locked')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('checks the second factor for any action that takes a second-factor request', function () {
    Route::post('_test/second-factor-action', fn (SecondFactorRequest $request) => 'action ran')->middleware(['web', 'auth']);
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->postJson('_test/second-factor-action', ['code' => '000000'])
        ->assertJsonValidationErrors('code');

    $this->postJson('_test/second-factor-action', ['code' => currentAuthenticatorCode($user)])
        ->assertOk()
        ->assertSee('action ran');
});

it('keeps the recovery code if replacing the authenticator fails', function () {
    $user = User::factory()->withTwoFactor()->create();
    $recoveryCode = $user->recoveryCodes()[0];

    $this->partialMock(AccountLifecycle::class)
        ->shouldReceive('resetTwoFactor')
        ->andThrow(new RuntimeException('Database unavailable'));

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('security.two-factor.reset'), ['recovery_code' => $recoveryCode])
        ->assertServerError();

    expect($user->fresh()->recoveryCodes())->toContain($recoveryCode);
});

it('asks the user to try again, without counting a guess, when the codes keep changing', function () {
    $user = User::factory()->withTwoFactor()->create();
    $recoveryCode = $user->recoveryCodes()[0];

    // Every time the codes are read, something else saves them again.
    DB::listen(function (QueryExecuted $query) use ($user): void {
        if (! readsRecoveryCodes($query)) {
            return;
        }

        DB::table('users')->where('id', $user->id)->update([
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode($user->recoveryCodes())),
        ]);
    });

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson(route('security.second-factor.confirm'), ['recovery_code' => $recoveryCode])
        ->assertJsonValidationErrors(['recovery_code' => 'Please try again']);

    expect(app(AccountAttemptLimiter::class)->attempts(SecondFactorCheck::IN_SETTINGS, $user))->toBe(0)
        ->and(AuditEvent::where('event', 'mfa.step_up.failed')->exists())->toBeFalse();
});

it('refuses to replace a recovery code that is not one of the user\'s', function () {
    $user = User::factory()->withTwoFactor()->create();

    expect(fn () => $user->replaceRecoveryCode('not-a-recovery-code'))->toThrow(ValidationException::class);
});

it('keeps code checks in settings working, and tells the user, when sign-in codes are locked', function () {
    startAttemptWindow();
    Notification::fake();
    $user = User::factory()->withTwoFactor()->create();

    foreach (range(1, AccountAttemptLimiter::MAX_ATTEMPTS + 2) as $attempt) {
        startTwoFactorLogin($user);
        $this->post(route('two-factor.login.store'), ['code' => '000000']);
        $this->travel(13)->seconds();
    }

    Notification::assertSentToTimes($user, SignInCodesLocked::class, 1);

    // The real user's existing session is not locked out of settings.
    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson(route('security.second-factor.confirm'), ['code' => currentAuthenticatorCode($user)])
        ->assertNoContent();
});

it('records that a recovery code was the proof for replacing the authenticator', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('security.two-factor.reset'), ['recovery_code' => $user->recoveryCodes()[0]])
        ->assertRedirect(route('two-factor.setup'));

    expect(AuditEvent::where('event', 'mfa.reset')->sole()->context)->toMatchArray(['proof' => 'recovery_code']);
});

/**
 * Gives wrong codes at the sign-in challenge; by default enough to lock it.
 */
function lockSignInCodes(User $user, int $wrongCodes = AccountAttemptLimiter::MAX_ATTEMPTS + 1): void
{
    foreach (range(1, $wrongCodes) as $attempt) {
        startTwoFactorLogin($user);
        test()->post(route('two-factor.login.store'), ['code' => '000000']);
        test()->travel(13)->seconds(); // stay under the challenge's 5-a-minute throttle
    }
}

it('alerts the user for each new lock, with the time actually left', function () {
    Notification::fake();
    $user = User::factory()->withTwoFactor()->create();

    startAttemptWindow();
    $this->travel(13)->minutes();
    lockSignInCodes($user);

    $this->travel(2)->minutes(); // the next window
    lockSignInCodes($user);

    Notification::assertSentToTimes($user, SignInCodesLocked::class, 2);
    Notification::assertSentTo($user, SignInCodesLocked::class, fn (SignInCodesLocked $notification): bool => $notification->minutes === 1);
});

it('still answers a locked sign-in normally when the alert cannot be sent', function () {
    $this->mock(NotificationDispatcher::class)
        ->shouldReceive('send')
        ->andThrow(new RuntimeException('Mail server unavailable'));
    $user = User::factory()->withTwoFactor()->create();

    startAttemptWindow();
    lockSignInCodes($user, AccountAttemptLimiter::MAX_ATTEMPTS);

    // This guess reaches the limit, so it is the one that sends the alert.
    startTwoFactorLogin($user);
    $this->post(route('two-factor.login.store'), ['code' => '000000'])
        ->assertSessionHasErrors(['code' => 'Too many incorrect codes. Try again in 14 minutes.']);
});

it('treats unreadable stored recovery codes as no codes, not as an error', function () {
    $user = User::factory()->withTwoFactor()->create();
    $user->forceFill(['two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt('null')])->save();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson(route('security.second-factor.confirm'), ['recovery_code' => 'abcdefghij-klmnopqrst'])
        ->assertJsonValidationErrors(['recovery_code' => 'That recovery code is not valid.']);
});

it('signs in with a correct authenticator code even if a recovery code was also sent', function () {
    $user = User::factory()->withTwoFactor()->create();
    $recoveryCode = $user->recoveryCodes()[0];

    startTwoFactorLogin($user);

    $this->post(route('two-factor.login.store'), [
        'code' => currentAuthenticatorCode($user),
        'recovery_code' => $recoveryCode,
    ])->assertRedirect(route('dashboard'));

    expect($user->fresh()->recoveryCodes())->toContain($recoveryCode);
});
