<?php

namespace Database\Factories;

use App\Enums\AddressType;
use App\Models\Person;
use App\Models\PersonAddress;
use App\Models\User;
use App\Services\DataProtection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PersonAddress>
 */
class PersonAddressFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $address = fake()->numerify('## Synthetic Test Road');

        return [
            'person_id' => Person::factory(),
            'type' => AddressType::Home,
            'address_ciphertext' => $address,
            'address_masked' => app(DataProtection::class)->maskAddress($address),
            'created_by' => User::factory(),
        ];
    }
}
