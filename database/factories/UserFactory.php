<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\RecoveryCode;
use PragmaRX\Google2FA\Google2FA;

/**
 * Generates synthetic officers only. Never seed real personnel data (§24).
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * A password that satisfies the CRMS password policy.
     */
    public const PASSWORD = 'Synthetic-Pass-2026!';

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'service_number' => 'TEST-'.fake()->unique()->numerify('######'),
            'rank' => fake()->randomElement([
                'Police Constable', 'Corporal', 'Sergeant', 'Assistant Inspector of Police',
                'Inspector of Police', 'Assistant Superintendent of Police', 'Superintendent of Police',
            ]),
            'email' => fake()->unique()->userName().'@crms.test',
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make(self::PASSWORD),
            'password_changed_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Provisioned but not yet activated by the officer.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AccountStatus::Pending,
            'email_verified_at' => null,
            'password_changed_at' => null,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AccountStatus::Suspended,
            'suspended_at' => now(),
            'suspension_reason' => 'Synthetic suspension for testing.',
        ]);
    }

    public function deactivated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AccountStatus::Deactivated,
        ]);
    }

    /**
     * An officer with a confirmed authenticator app.
     */
    public function withTwoFactor(?string $secret = null): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret ?? app(Google2FA::class)->generateSecretKey()),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(
                Collection::times(8, fn (): string => RecoveryCode::generate())->all(),
            )),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
