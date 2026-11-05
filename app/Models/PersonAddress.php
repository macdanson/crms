<?php

namespace App\Models;

use App\Casts\ProtectedText;
use App\Enums\AddressType;
use Database\Factories\PersonAddressFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $person_id
 * @property AddressType $type
 * @property string $address_ciphertext The address, decrypted by the cast.
 * @property string $address_masked
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 * @property int $created_by
 * @property-read Person $person
 */
#[Hidden(['address_ciphertext'])]
class PersonAddress extends Model
{
    /** @use HasFactory<PersonAddressFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AddressType::class,
            'address_ciphertext' => ProtectedText::class,
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
