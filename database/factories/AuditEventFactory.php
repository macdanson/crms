<?php

namespace Database\Factories;

use App\Enums\AuditOutcome;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Generates synthetic audit history for tests. Real audit records are only
 * ever written by App\Services\AuditLogger.
 *
 * @extends Factory<AuditEvent>
 */
class AuditEventFactory extends Factory
{
    /**
     * Define the model's default state: a successful sign-in.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'occurred_at' => now('UTC'),
            'user_id' => User::factory(),
            'event' => 'auth.login',
            'outcome' => AuditOutcome::Success,
            'ip_address' => fake()->ipv4(),
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
            'session_hash' => hash('sha256', Str::random(40)),
            'request_id' => (string) Str::uuid(),
            'context' => null,
        ];
    }

    /**
     * A sign-in from the given browser.
     */
    public function signIn(string $userAgent): static
    {
        return $this->state(fn (array $attributes) => [
            'event' => 'auth.login',
            'outcome' => AuditOutcome::Success,
            'user_agent' => $userAgent,
        ]);
    }

    /**
     * A failed sign-in attempt.
     */
    public function failedSignIn(): static
    {
        return $this->state(fn (array $attributes) => [
            'event' => 'auth.login.failed',
            'outcome' => AuditOutcome::Failure,
        ]);
    }

    /**
     * An event that happened the given number of days ago.
     */
    public function daysAgo(int $days): static
    {
        return $this->state(fn (array $attributes) => [
            'occurred_at' => now('UTC')->subDays($days),
        ]);
    }
}
