<?php

use App\Http\Controllers\Auth\AccountActivationController;
use App\Http\Controllers\Auth\TwoFactorSetupController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SessionHeartbeatController;
use App\Http\Controllers\Settings\BrowserSessionController;
use App\Http\Controllers\Settings\SecondFactorConfirmationController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\TwoFactorResetController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
|
| Sign-in, sign-out, password reset, password confirmation and the two-factor
| challenge are provided by Laravel Fortify (see config/fortify.php and
| App\Providers\FortifyServiceProvider). There is no public registration.
|
*/

Route::redirect('/', '/dashboard')->name('home');

Route::middleware('guest')->group(function () {
    Route::get('activate/{token}', [AccountActivationController::class, 'create'])
        ->name('activation.create');

    Route::post('activate', [AccountActivationController::class, 'store'])
        ->middleware('throttle:6,1,activation')
        ->name('activation.store');
});

Route::middleware('auth')->group(function () {
    Route::post('session/heartbeat', SessionHeartbeatController::class)
        ->middleware('throttle:30,1,heartbeat')
        ->name('session.heartbeat');

    // Shows (and creates) the secret, so it needs the same password
    // confirmation as Fortify's own routes that show it.
    Route::get('security/two-factor/setup', [TwoFactorSetupController::class, 'show'])
        ->middleware('password.confirm')
        ->name('two-factor.setup');

    Route::middleware('mfa')->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        Route::middleware('password.confirm')->prefix('settings/security')->group(function () {
            Route::get('/', [SecurityController::class, 'show'])->name('security.show');

            Route::delete('sessions/{session}', [BrowserSessionController::class, 'destroy'])
                ->name('security.sessions.destroy');

            Route::delete('sessions', [BrowserSessionController::class, 'destroyOthers'])
                ->name('security.sessions.destroy-others');

            Route::post('second-factor/confirm', SecondFactorConfirmationController::class)
                ->middleware('throttle:5,1,second-factor')
                ->name('security.second-factor.confirm');

            Route::post('two-factor/reset', TwoFactorResetController::class)
                ->middleware('throttle:5,1,two-factor-reset')
                ->name('security.two-factor.reset');
        });
    });
});
