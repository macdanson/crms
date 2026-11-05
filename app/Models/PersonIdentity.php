<?php

namespace App\Models;

use App\Casts\ProtectedText;
use App\Enums\IdentityType;
use App\Enums\VerificationStatus;
use Database\Factories\PersonIdentityFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An identity document. Verification is held apart from the number (§5.2).
 *
 * @property int $id
 * @property string $person_id
 * @property IdentityType $type
 * @property string $identifier_ciphertext The number, decrypted by the cast.
 * @property string $identifier_lookup_hmac
 * @property string $identifier_masked
 * @property string|null $issuing_country
 * @property string|null $issuing_authority
 * @property VerificationStatus $verification_status
 * @property string|null $verification_source
 * @property int|null $verified_by
 * @property Carbon|null $verified_at
 * @property int $created_by
 * @property-read Person $person
 * @property-read User|null $verifiedBy
 */
#[Hidden(['identifier_ciphertext', 'identifier_lookup_hmac'])]
class PersonIdentity extends Model
{
    /** @use HasFactory<PersonIdentityFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => IdentityType::class,
            'identifier_ciphertext' => ProtectedText::class,
            'verification_status' => VerificationStatus::class,
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
