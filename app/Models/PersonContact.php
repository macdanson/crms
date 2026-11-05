<?php

namespace App\Models;

use App\Casts\ProtectedText;
use App\Enums\ContactType;
use Database\Factories\PersonContactFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $person_id
 * @property ContactType $type
 * @property string $value_ciphertext The value, decrypted by the cast.
 * @property string $value_masked
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 * @property int $created_by
 * @property-read Person $person
 */
#[Hidden(['value_ciphertext'])]
class PersonContact extends Model
{
    /** @use HasFactory<PersonContactFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ContactType::class,
            'value_ciphertext' => ProtectedText::class,
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
