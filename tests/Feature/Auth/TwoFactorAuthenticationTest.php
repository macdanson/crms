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
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
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

it('alerts the user for each new lock, with the time the pause ends', function () {
    Notification::fake();
    $user = User::factory()->withTwoFactor()->create();

    startAttemptWindow();
    lockSignInCodes($user);
    $firstPauseEnds = app(AccountAttemptLimiter::class)->unlocksAt(SecondFactorCheck::AT_SIGN_IN, $user);

    $this->travel(2 * AccountAttemptLimiter::DECAY_SECONDS)->seconds(); // long after the pause
    lockSignInCodes($user);

    Notification::assertSentToTimes($user, SignInCodesLocked::class, 2);
    Notification::assertSentTo($user, SignInCodesLocked::class, fn (SignInCodesLocked $notification): bool => $notification->pausedUntil?->equalTo($firstPauseEnds) === true);
});

it('still answers a locked sign-in normally when the alert cannot be sent', function () {
    $this->mock(NotificationDispatcher::class)
        ->shouldReceive('send')
        ->andThrow(new RuntimeException('Mail server unavailable'));
    $user = User::factory()->withTwoFactor()->create();

    startAttemptWindow();
    lockSignInCodes($user, AccountAttemptLimiter::MAX_ATTEMPTS - 1);

    // This is the last wrong code allowed, so it is the one that sends the alert.
    startTwoFactorLogin($user);
    $this->post(route('two-factor.login.store'), ['code' => '000000'])
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHasErrors(['code' => 'The provided two factor authentication code was invalid.']);
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

it('alerts the user when the last allowed wrong code is given, without a further attempt', function () {
    Notification::fake();
    $user = User::factory()->withTwoFactor()->create();

    startAttemptWindow();
    lockSignInCodes($user, AccountAttemptLimiter::MAX_ATTEMPTS);

    Notification::assertSentToTimes($user, SignInCodesLocked::class, 1);
});

it('checks only the authenticator code when both kinds of code are sent at sign-in', function () {
    $user = User::factory()->withTwoFactor()->create();
    $recoveryCode = $user->recoveryCodes()[0];

    startAttemptWindow();
    startTwoFactorLogin($user);

    $this->post(route('two-factor.login.store'), ['code' => '000000', 'recovery_code' => $recoveryCode])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
    expect(app(AccountAttemptLimiter::class)->attempts(SecondFactorCheck::AT_SIGN_IN, $user))->toBe(1)
        ->and($user->fresh()->recoveryCodes())->toContain($recoveryCode);
});

it('keeps the recovery code in settings when a correct authenticator code is also sent', function () {
    $user = User::factory()->withTwoFactor()->create();
    $recoveryCode = $user->recoveryCodes()[0];

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson(route('security.second-factor.confirm'), ['code' => currentAuthenticatorCode($user), 'recovery_code' => $recoveryCode])
        ->assertNoContent();

    expect($user->fresh()->recoveryCodes())->toContain($recoveryCode);
});

it('still writes the lock alert for a copy queued without the time the pause ends', function () {
    $user = User::factory()->create();

    $mail = (new SignInCodesLocked)->toMail($user);

    expect(implode(' ', $mail->introLines))->toContain('was paused for a while');
});

it('treats recovery codes that cannot be decrypted as no codes, not as an error', function () {
    $user = User::factory()->withTwoFactor()->create();
    DB::table('users')->where('id', $user->id)->update(['two_factor_recovery_codes' => 'not-a-valid-ciphertext']);

    $this->actingAs($user->fresh())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson(route('security.second-factor.confirm'), ['recovery_code' => 'abcdefghij-klmnopqrst'])
        ->assertJsonValidationErrors(['recovery_code' => 'That recovery code is not valid.']);
});

it('ends a code challenge begun with the old password once the password is reset', function () {
    $user = User::factory()->withTwoFactor()->create();

    startTwoFactorLogin($user);

    // The real user resets the password meanwhile.
    $this->post(route('password.update'), [
        'token' => Password::broker()->createToken($user),
        'email' => $user->email,
        'password' => 'A-Brand-New-Pass-42',
        'password_confirmation' => 'A-Brand-New-Pass-42',
    ])->assertSessionHasNoErrors();

    $this->post(route('two-factor.login.store'), ['code' => currentAuthenticatorCode($user)])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('ends a code challenge that was left unfinished for too long', function () {
    $user = User::factory()->withTwoFactor()->create();

    startTwoFactorLogin($user);
    $this->travel(config('crms.auth.challenge_timeout') + 1)->minutes();

    $this->post(route('two-factor.login.store'), ['code' => currentAuthenticatorCode($user)])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('explains, and ends the challenge, when an expired code page is opened', function () {
    $user = User::factory()->withTwoFactor()->create();

    startTwoFactorLogin($user);
    $this->travel(config('crms.auth.challenge_timeout') + 1)->minutes();

    $this->get(route('two-factor.login'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error', 'Your sign-in took too long or your password has changed. Please sign in again.');

    expect(session()->has('login.id'))->toBeFalse()
        ->and(session()->has(SessionPolicy::CHALLENGE))->toBeFalse();
});

it('does not let a linked authenticator be confirmed again', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson(route('two-factor.confirm'), ['code' => currentAuthenticatorCode($user)])
        ->assertForbidden();
});

it('treats an authenticator secret that cannot be decrypted as matching no code', function () {
    $user = User::factory()->withTwoFactor()->create();
    DB::table('users')->where('id', $user->id)->update(['two_factor_secret' => 'not-a-valid-ciphertext']);

    $this->actingAs($user->fresh())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson(route('security.second-factor.confirm'), ['code' => '123456'])
        ->assertJsonValidationErrors('code');
});

it('still shows the account pages when the recovery codes cannot be read', function () {
    $user = User::factory()->withTwoFactor()->create();
    DB::table('users')->where('id', $user->id)->update(['two_factor_recovery_codes' => 'not-a-valid-ciphertext']);

    $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk();
    $this->withSession(['auth.password_confirmed_at' => time()])->get(route('security.show'))->assertOk();
});

it('refuses to replace the authenticator with a recovery code another request has just used', function () {
    $user = User::factory()->withTwoFactor()->create();
    $recoveryCode = $user->recoveryCodes()[0];
    $usedElsewhere = false;

    // Just after this request has checked the code, another request uses it.
    DB::listen(function (QueryExecuted $query) use (&$usedElsewhere, $user, $recoveryCode): void {
        if ($usedElsewhere || ! readsRecoveryCodes($query)) {
            return;
        }

        $usedElsewhere = true;
        app(SecondFactor::class)->consumeRecoveryCode(User::find($user->id), $recoveryCode);
    });

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('security.two-factor.reset'), ['recovery_code' => $recoveryCode])
        ->assertSessionHasErrors('recovery_code');

    expect($usedElsewhere)->toBeTrue()
        ->and($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

it('sends at most one lock alert per quarter hour to someone guessing at the allowed rate', function () {
    Notification::fake();
    $user = User::factory()->withTwoFactor()->create();

    startAttemptWindow();
    lockSignInCodes($user, AccountAttemptLimiter::MAX_ATTEMPTS);

    // Then one wrong code each time one is allowed again, for half an hour.
    foreach (range(1, 10) as $guess) {
        $this->travel(181)->seconds();
        startTwoFactorLogin($user);
        $this->post(route('two-factor.login.store'), ['code' => '000000']);
    }

    expect(Notification::sent($user, SignInCodesLocked::class)->count())->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(3);
});

it('never ends a code challenge for its age when the challenge timeout is 0', function () {
    config(['crms.auth.challenge_timeout' => 0]);
    $user = User::factory()->withTwoFactor()->create();

    startTwoFactorLogin($user);
    $this->travel(2)->hours();

    $this->post(route('two-factor.login.store'), ['code' => currentAuthenticatorCode($user)])
        ->assertRedirect(route('dashboard'));
});

it('removes every trace of the code challenge once signed in', function () {
    $user = User::factory()->withTwoFactor()->create();

    startTwoFactorLogin($user);
    $this->post(route('two-factor.login.store'), ['code' => currentAuthenticatorCode($user)])
        ->assertRedirect(route('dashboard'));

    expect(session()->has(SessionPolicy::CHALLENGE))->toBeFalse()
        ->and(session()->has('login.id'))->toBeFalse();
});

it('gives a half-finished enrolment a new secret when the old one cannot be read', function () {
    $user = User::factory()->create(['two_factor_secret' => 'not-a-valid-ciphertext']);

    $this->actingAs($user->fresh())
        ->get(route('two-factor.setup'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('step', 'scan')->whereType('setupKey', 'string'));

    expect($user->fresh()->twoFactorSecret())->not->toBeNull();
});

it('lists no recovery codes, rather than failing, when they cannot be read', function () {
    $user = User::factory()->withTwoFactor()->create();
    DB::table('users')->where('id', $user->id)->update(['two_factor_recovery_codes' => 'not-a-valid-ciphertext']);

    $this->actingAs($user->fresh())
        ->withSession(['auth.password_confirmed_at' => time(), SecondFactor::CONFIRMED_AT => time()])
        ->getJson(route('two-factor.recovery-codes'))
        ->assertOk()
        ->assertExactJson([]);
});

it('replaces the authenticator with a recovery code, recording its use without making a replacement code', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('security.two-factor.reset'), ['recovery_code' => $user->recoveryCodes()[0]])
        ->assertRedirect(route('two-factor.setup'));

    expect(AuditEvent::where('event', 'mfa.recovery_code.used')->sole()->context)->toBe(['purpose' => 'authenticator_reset'])
        ->and(AuditEvent::where('event', 'mfa.reset')->sole()->context)->toMatchArray(['proof' => 'recovery_code'])
        ->and($user->fresh()->two_factor_recovery_codes)->toBeNull();
});

it('counts the remaining recovery codes without reading them from the database again', function () {
    $user = User::factory()->withTwoFactor()->create();
    $reads = 0;

    DB::listen(function (QueryExecuted $query) use (&$reads): void {
        if (readsRecoveryCodes($query)) {
            $reads++;
        }
    });

    expect($user->remainingRecoveryCodeCount())->toBe(8)
        ->and($reads)->toBe(0);
});

it('sends an enrolment whose new secret cannot be read back to the setup page', function () {
    $user = User::factory()->create(['two_factor_secret' => 'not-a-valid-ciphertext']);

    $this->actingAs($user->fresh())->withSession(['auth.password_confirmed_at' => time()]);

    $this->getJson(route('two-factor.secret-key'))->assertStatus(409);
    $this->post(route('two-factor.confirm'), ['code' => '123456'])
        ->assertRedirect(route('two-factor.setup'))
        ->assertSessionHas('error');
});

it('replaces the authenticator with a recovery code even if another code is used meanwhile', function () {
    $user = User::factory()->withTwoFactor()->create();
    $codes = $user->recoveryCodes();
    $reads = 0;

    // Once the reset has read the codes, another request uses a different code.
    DB::listen(function (QueryExecuted $query) use (&$reads, $codes, $user): void {
        if (! readsRecoveryCodes($query) || ++$reads !== 2) {
            return;
        }

        app(SecondFactor::class)->consumeRecoveryCode(User::find($user->id), $codes[1]);
    });

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('security.two-factor.reset'), ['recovery_code' => $codes[0]])
        ->assertRedirect(route('two-factor.setup'));

    expect($reads)->toBeGreaterThan(2)
        ->and($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

it('sends one lock alert for a lock that lasts longer than a quarter hour', function () {
    Notification::fake();
    $user = User::factory()->withTwoFactor()->create();

    startAttemptWindow();
    lockSignInCodes($user, AccountAttemptLimiter::MAX_ATTEMPTS);

    // Still within the same lock, more than 15 minutes after it began.
    $this->travel(AccountAttemptLimiter::DECAY_SECONDS)->seconds();
    expect(app(AccountAttemptLimiter::class)->unlocksAt(SecondFactorCheck::AT_SIGN_IN, $user)->isFuture())->toBeTrue();
    lockSignInCodes($user, 1);

    Notification::assertSentToTimes($user, SignInCodesLocked::class, 1);
});

it('records an expired challenge, and checks the account, when the code page is opened', function () {
    $user = User::factory()->withTwoFactor()->create();

    startTwoFactorLogin($user);
    $this->travel(config('crms.auth.challenge_timeout') + 1)->minutes();
    $this->get(route('two-factor.login'))->assertRedirect(route('login'));

    expect(AuditEvent::where('event', 'auth.challenge.expired')->where('user_id', $user->id)->exists())->toBeTrue();

    $suspended = User::factory()->withTwoFactor()->create();
    startTwoFactorLogin($suspended);
    app(AccountLifecycle::class)->suspend($suspended, 'Investigation');

    $this->get(route('two-factor.login'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error', $suspended->fresh()->status->signInBlockedMessage());
});

it('reads the remember choice before the challenge data is cleared', function () {
    $user = User::factory()->withTwoFactor()->create();

    startTwoFactorLogin($user);

    $response = $this->withSession(['login.remember' => true])
        ->post(route('two-factor.login.store'), ['code' => currentAuthenticatorCode($user)]);

    expect(collect($response->headers->getCookies())->contains(fn ($cookie): bool => str_starts_with($cookie->getName(), 'remember_web_')))->toBeTrue();
});

it('shows new recovery codes at the end of enrolment when the confirmed ones cannot be read', function () {
    $user = User::factory()->withTwoFactor()->create();
    DB::table('users')->where('id', $user->id)->update(['two_factor_recovery_codes' => 'not-a-valid-ciphertext']);

    $this->actingAs($user->fresh())
        ->withSession(['status' => Fortify::TWO_FACTOR_AUTHENTICATION_CONFIRMED])
        ->get(route('two-factor.setup'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('step', 'recovery')->count('recoveryCodes', 8));
});

it('shows the recovery codes another page load already made instead of making new ones', function () {
    $user = User::factory()->withTwoFactor()->create();
    DB::table('users')->where('id', $user->id)->update(['two_factor_recovery_codes' => 'not-a-valid-ciphertext']);
    $user = $user->fresh();

    // Another load of the page replaces the unreadable codes first.
    $other = User::find($user->id);
    app(GenerateNewRecoveryCodes::class)($other);

    $this->actingAs($user)
        ->withSession(['status' => Fortify::TWO_FACTOR_AUTHENTICATION_CONFIRMED])
        ->get(route('two-factor.setup'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('step', 'recovery')->where('recoveryCodes', $other->recoveryCodes()));

    expect($user->fresh()->recoveryCodes())->toBe($other->recoveryCodes());
});

it('ends a challenge for a deleted account without leaving a wrong-code error', function () {
    $user = User::factory()->withTwoFactor()->create();

    startTwoFactorLogin($user);
    $user->delete();

    $this->get(route('two-factor.login'))
        ->assertRedirect(route('login'))
        ->assertSessionHasNoErrors()
        ->assertSessionMissing('error');

    expect(session()->has('login.id'))->toBeFalse();
});

it('does not report an unreadable new secret that it replaces', function () {
    Exceptions::fake();
    $user = User::factory()->create(['two_factor_secret' => 'not-a-valid-ciphertext']);

    $this->actingAs($user->fresh())->withSession(['auth.password_confirmed_at' => time()]);

    $this->getJson(route('two-factor.secret-key'))->assertStatus(409);
    $this->get(route('two-factor.setup'))->assertOk();

    Exceptions::assertNothingReported();
});
