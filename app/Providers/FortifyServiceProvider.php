<?php

namespace App\Providers;

use App\Actions\Fortify\AuthenticateUser;
use App\Actions\Fortify\ConfirmTwoFactorAuthentication;
use App\Actions\Fortify\DisableTwoFactorAuthentication;
use App\Actions\Fortify\EnableTwoFactorAuthentication;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RecoveryCodeController;
use App\Http\Controllers\Auth\TwoFactorAuthenticatedSessionController;
use App\Http\Requests\Auth\TwoFactorLoginRequest;
use App\Http\Responses\LogoutResponse;
use App\Http\Responses\PasswordResetFailedResponse;
use App\Http\Responses\PasswordResetLinkRequestedResponse;
use App\Http\Responses\SignedInResponse;
use App\Models\User;
use App\Services\PasswordCheck;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication as FortifyConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication as FortifyDisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication as FortifyEnableTwoFactorAuthentication;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Controllers\NewPasswordController as FortifyNewPasswordController;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController as FortifyPasswordResetLinkController;
use Laravel\Fortify\Http\Controllers\RecoveryCodeController as FortifyRecoveryCodeController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController as FortifyTwoFactorAuthenticatedSessionController;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest as FortifyTwoFactorLoginRequest;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Request attribute holding the result of the credential check.
     */
    private const AUTHENTICATED_USER = 'crms.authenticated_user';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, PasswordResetLinkRequestedResponse::class);
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, PasswordResetLinkRequestedResponse::class);
        $this->app->bind(FailedPasswordResetResponse::class, PasswordResetFailedResponse::class);
        $this->app->singleton(LogoutResponseContract::class, LogoutResponse::class);
        $this->app->bind(LoginResponse::class, SignedInResponse::class);
        $this->app->bind(TwoFactorLoginResponse::class, SignedInResponse::class);

        $this->app->bind(FortifyTwoFactorLoginRequest::class, TwoFactorLoginRequest::class);
        $this->app->bind(FortifyPasswordResetLinkController::class, PasswordResetLinkController::class);
        $this->app->bind(FortifyNewPasswordController::class, NewPasswordController::class);
        $this->app->bind(FortifyTwoFactorAuthenticatedSessionController::class, TwoFactorAuthenticatedSessionController::class);
        $this->app->bind(FortifyRecoveryCodeController::class, RecoveryCodeController::class);

        $this->app->bind(FortifyEnableTwoFactorAuthentication::class, EnableTwoFactorAuthentication::class);
        $this->app->bind(FortifyConfirmTwoFactorAuthentication::class, ConfirmTwoFactorAuthentication::class);
        $this->app->bind(FortifyDisableTwoFactorAuthentication::class, DisableTwoFactorAuthentication::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    private function configureActions(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            // CRMS never issues long-lived "remember me" cookies: one would let
            // its holder skip both the password and the authenticator.
            $request->merge(['remember' => false]);

            // Fortify's pipeline asks for the user twice when no authenticator
            // is confirmed; check the credentials (and hash the password) once.
            if (! $request->attributes->has(self::AUTHENTICATED_USER)) {
                $request->attributes->set(self::AUTHENTICATED_USER, app(AuthenticateUser::class)($request));
            }

            return $request->attributes->get(self::AUTHENTICATED_USER);
        });
        // Password confirmation inside a session is limited and audited like
        // every other in-session password check.
        Fortify::confirmPasswordsUsing(function (User $user, ?string $password): bool {
            $error = app(PasswordCheck::class)->check($user, (string) $password, 'confirm_password', __('The provided password was incorrect.'));

            if ($error !== null) {
                throw ValidationException::withMessages(['password' => $error]);
            }

            return true;
        });
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
    }

    private function configureViews(): void
    {
        Fortify::loginView(fn () => Inertia::render('auth/Login'));

        Fortify::requestPasswordResetLinkView(fn () => Inertia::render('auth/ForgotPassword'));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'token' => $request->route('token'),
            'email' => is_string($request->query('email')) ? $request->query('email') : '',
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    private function configureRateLimiting(): void
    {
        // Applied to every Fortify route. Generous enough for many users
        // sharing one office network address, tight enough to slow abuse.
        RateLimiter::for('fortify', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)
            ->by((string) $request->session()->get('login.id', $request->ip())));
    }
}
