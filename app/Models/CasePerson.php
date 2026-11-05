<?php

namespace App\Models;

use App\Enums\CasePersonRole;
use Database\Factories\CasePersonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person's role in one case.
 *
 * @property int $id
 * @property string $case_id
 * @property string $person_id
 * @property CasePersonRole $role
 * @property string|null $role_status
 * @property int $linked_by
 * @property Carbon $linked_at
 * @property Carbon|null $unlinked_at
 * @property int|null $unlinked_by
 * @property string|null $unlink_reason
 * @property-read CriminalCase $criminalCase
 * @property-read Person $person
 */
class CasePerson extends Model
{
    /** @use HasFactory<CasePersonFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $table = 'case_people';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => CasePersonRole::class,
            'linked_at' => 'datetime',
            'unlinked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CriminalCase, $this>
     */
    public function criminalCase(): BelongsTo
    {
        return $this->belongsTo(CriminalCase::class, 'case_id');
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
