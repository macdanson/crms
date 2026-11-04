<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database with synthetic data.
     *
     * Demo credentials are only ever created in local development; production
     * accounts must be provisioned individually with `php artisan user:invite`.
     */
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('Demo accounts may only be seeded in local or testing environments.');
        }

        User::factory()->create([
            'name' => 'Demo Analyst',
            'email' => 'analyst@crms.test',
            'staff_number' => 'DEMO-000001',
            'job_title' => 'Senior Crime Analyst',
        ]);

        $this->command?->info('Demo account: analyst@crms.test / '.UserFactory::PASSWORD.' (authenticator enrolment required at first sign-in)');
    }
}
