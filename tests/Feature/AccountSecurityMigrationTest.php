<?php

use Illuminate\Support\Facades\DB;

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
