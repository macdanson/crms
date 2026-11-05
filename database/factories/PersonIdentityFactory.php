<?php

namespace Database\Factories;

use App\Enums\IdentityType;
use App\Enums\VerificationStatus;
use App\Models\Person;
use App\Models\PersonIdentity;
use App\Models\User;
use App\Services\DataProtection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Generates synthetic identity numbers only (developer guide §24).
 *
 * @extends Factory<PersonIdentity>
 */
class PersonIdentityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return $this->forNumber('TEST'.fake()->unique()->numerify('##########'));
    }

    /**
     * @return array<string, mixed>
     */
    private function forNumber(string $number): array
    {
        $protection = app(DataProtection::class);

        return [
            'person_id' => Person::factory(),
            'type' => IdentityType::NationalId,
            'identifier_ciphertext' => $protection->normaliseIdentifier($number),
            'identifier_lookup_hmac' => $protection->lookupToken(IdentityType::NationalId->value, $number),
            'identifier_masked' => $protection->maskIdentifier($number),
            'verification_status' => VerificationStatus::Unverified,
            'created_by' => User::factory(),
        ];
    }

    public function number(string $number): static
    {
        return $this->state(fn (array $attributes) => array_diff_key($this->forNumber($number), ['person_id' => true, 'created_by' => true]));
    }
}
