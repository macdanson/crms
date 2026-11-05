<?php

namespace App\Models;

use App\Casts\ProtectedText;
use App\Enums\Permission;
use App\Enums\PersonSex;
use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Someone who appears in the records, in whatever role. Having a record here
 * says nothing about them: a person's role belongs to each case (§4).
 *
 * Attributes are set only through App\Services\PersonRecords.
 *
 * @property string $id
 * @property string $given_names
 * @property string $family_name
 * @property string|null $other_names
 * @property string $search_name
 * @property PersonSex|null $sex
 * @property string|null $date_of_birth_ciphertext The date as YYYY-MM-DD, decrypted by the cast.
 * @property int|null $birth_year
 * @property string|null $nationality
 * @property string|null $occupation
 * @property int $registered_district_id
 * @property int $registered_station_id
 * @property int $created_by
 * @property string|null $merged_into_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $full_name
 * @property-read Station $registeredStation
 * @property-read District $registeredDistrict
 */
#[Hidden(['date_of_birth_ciphertext', 'search_name'])]
class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use HasFactory, HasUuids;

    protected $table = 'people';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sex' => PersonSex::class,
            'date_of_birth_ciphertext' => ProtectedText::class,
            'birth_year' => 'integer',
        ];
    }

    /**
     * Keep what name searches and duplicate checks use in step with the record.
     */
    protected static function booted(): void
    {
        static::saving(function (Person $person): void {
            $person->search_name = self::normaliseName("{$person->family_name} {$person->given_names} {$person->other_names}");
        });
    }

    /**
     * The one way names are compared: lower case letters, digits and single
     * spaces. It also takes out the wildcards of a LIKE search.
     */
    public static function normaliseName(string $name): string
    {
        $name = Str::lower(Str::ascii($name));

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9\s]/', ' ', $name)));
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->given_names} {$this->other_names} {$this->family_name}");
    }

    /**
     * The people this user may see: the single rule behind every person
     * screen, search and report (§7.4). Anything not matched is denied.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->hasPermission(Permission::PersonsView)) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($user->hasPermission(Permission::CasesViewNational)) {
            return;
        }

        $query->where(function (Builder $query) use ($user): void {
            // Someone they registered at their current station, who may have no
            // case yet. It ends with a transfer, like the rest of their access.
            $query->where(fn (Builder $query) => $query
                ->where('created_by', $user->getKey())
                ->where('registered_station_id', $user->station_id));

            if ($user->hasPermission(Permission::CasesViewDistrict) && $user->district_id !== null) {
                $query->orWhere('registered_district_id', $user->district_id);
            }

            if ($user->hasPermission(Permission::CasesViewStation) && $user->station_id !== null) {
                $query->orWhere('registered_station_id', $user->station_id);
            }

            $query->orWhereHas('caseLinks', fn (Builder $links) => $links
                ->whereNull('unlinked_at')
                ->whereHas('criminalCase', fn (Builder $cases) => $cases->visibleTo($user)));
        });
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeNotMerged(Builder $query): void
    {
        $query->whereNull('merged_into_id');
    }

    public function isVisibleTo(User $user): bool
    {
        return self::query()->visibleTo($user)->whereKey($this->getKey())->exists();
    }

    /**
     * @return HasMany<PersonIdentity, $this>
     */
    public function identities(): HasMany
    {
        return $this->hasMany(PersonIdentity::class);
    }

    /**
     * @return HasMany<PersonContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(PersonContact::class);
    }

    /**
     * @return HasMany<PersonAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(PersonAddress::class);
    }

    /**
     * @return HasMany<CasePerson, $this>
     */
    public function caseLinks(): HasMany
    {
        return $this->hasMany(CasePerson::class);
    }

    /**
     * @return BelongsTo<Station, $this>
     */
    public function registeredStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'registered_station_id');
    }

    /**
     * @return BelongsTo<District, $this>
     */
    public function registeredDistrict(): BelongsTo
    {
        return $this->belongsTo(District::class, 'registered_district_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }
}
