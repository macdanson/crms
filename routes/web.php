<?php

use App\Http\Controllers\Auth\AccountActivationController;
use App\Http\Controllers\Auth\TwoFactorSetupController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SessionHeartbeatController;
use App\Http\Controllers\Settings\BrowserSessionController;
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
        ->middleware('throttle:6,1')
        ->name('activation.store');
});

Route::middleware('auth')->group(function () {
    Route::post('session/heartbeat', SessionHeartbeatController::class)
        ->middleware('throttle:30,1')
        ->name('session.heartbeat');

    Route::get('security/two-factor/setup', [TwoFactorSetupController::class, 'show'])
        ->name('two-factor.setup');

    Route::middleware('mfa')->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        Route::middleware('password.confirm')->prefix('settings/security')->group(function () {
            Route::get('/', [SecurityController::class, 'show'])->name('security.show');

            Route::delete('sessions/{session}', [BrowserSessionController::class, 'destroy'])
                ->name('security.sessions.destroy');

            Route::delete('sessions', [BrowserSessionController::class, 'destroyOthers'])
                ->name('security.sessions.destroy-others');

            Route::post('two-factor/reset', TwoFactorResetController::class)
                ->name('security.two-factor.reset');
        });
    });
});
