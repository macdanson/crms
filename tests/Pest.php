<?php

use App\Enums\Role;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A signed-in-ready user holding the role, with their authenticator enrolled,
 * placed at the station (a new one in a new district if none is given).
 */
function officer(Role $role, ?Station $station = null): User
{
    return User::factory()
        ->withTwoFactor()
        ->atStation($station ?? Station::factory()->create())
        ->withRole($role)
        ->create();
}

/**
 * Treat the user's password as confirmed a moment ago.
 */
function confirmedPassword(): array
{
    return ['auth.password_confirmed_at' => time()];
}

/**
 * Move the clock to just after the start of an attempt-limit window, so a
 * test that moves time forward stays inside one window.
 */
function startAttemptWindow(): void
{
    test()->travelTo(now()->startOfDay()->addSecond());
}

/**
 * The code the user's authenticator app shows right now.
 */
function currentAuthenticatorCode(User $user): string
{
    return app(Google2FA::class)->getCurrentOtp(Fortify::currentEncrypter()->decrypt($user->two_factor_secret));
}
