<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

it('keeps accounts that existed before the account status column active', function () {
    $migration = require database_path('migrations/2026_11_02_165808_add_account_security_columns_to_users_table.php');
    $migration->down();

    DB::table('users')->insert([
        'name' => 'Existing User',
        'email' => 'existing@crms.test',
        'password' => 'not-a-real-hash',
    ]);

    $migration->up();

    expect(DB::table('users')->where('email', 'existing@crms.test')->value('status'))->toBe('active');
});

it('stores existing email addresses the one way they are looked up', function () {
    $password = 'not-a-real-hash';

    DB::table('users')->insert([
        ['name' => 'Mixed Case', 'email' => ' Alice@Example.org ', 'password' => $password],
        ['name' => 'Already Lower', 'email' => 'bob@example.org', 'password' => $password],
    ]);

    (require migrationFile('normalise_stored_user_emails'))->up();

    expect(DB::table('users')->where('name', 'Mixed Case')->value('email'))->toBe('alice@example.org')
        ->and(User::findByEmail('Alice@Example.org')?->name)->toBe('Mixed Case')
        ->and(DB::table('users')->where('name', 'Already Lower')->value('email'))->toBe('bob@example.org');
});

it('leaves an existing email address alone when the lower-case one is taken, and warns', function () {
    Log::spy();
    $password = 'not-a-real-hash';

    DB::table('users')->insert([
        ['name' => 'Lower', 'email' => 'carol@example.org', 'password' => $password],
        ['name' => 'Upper', 'email' => 'CAROL@example.org', 'password' => $password],
    ]);

    (require migrationFile('normalise_stored_user_emails'))->up();

    expect(DB::table('users')->where('name', 'Upper')->value('email'))->toBe('CAROL@example.org')
        ->and(DB::table('users')->where('name', 'Lower')->value('email'))->toBe('carol@example.org');

    Log::shouldHaveReceived('warning')->once();
})->skip(fn () => DB::connection()->getDriverName() === 'mysql', 'MySQL treats these as the same address, so it cannot hold both.');

/**
 * The path of the migration with this name, whatever its timestamp.
 */
function migrationFile(string $name): string
{
    return collect(glob(database_path("migrations/*_{$name}.php")))->sole();
}
