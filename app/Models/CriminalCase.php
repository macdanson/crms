<?php

namespace App\Models;

use App\Casts\ProtectedText;
use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Enums\RecordLifecycle;
use Database\Factories\CriminalCaseFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A crime or case record. Attributes are set only through
 * App\Services\CaseRecords.
 *
 * @property string $id
 * @property string $reference
 * @property int $station_id
 * @property int $district_id
 * @property int $offence_category_id
 * @property Carbon $incident_date
 * @property string $incident_location
 * @property string $narrative_ciphertext The narrative, decrypted by the cast.
 * @property CaseStatus $status
 * @property RecordLifecycle $lifecycle
 * @property Carbon|null $closed_at
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Station $station
 * @property-read District $district
 * @property-read OffenceCategory $offenceCategory
 */
#[Hidden(['narrative_ciphertext'])]
class CriminalCase extends Model
{
    /** @use HasFactory<CriminalCaseFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'incident_date' => 'date',
            'narrative_ciphertext' => ProtectedText::class,
            'status' => CaseStatus::class,
            'lifecycle' => RecordLifecycle::class,
            'closed_at' => 'datetime',
        ];
    }

    /**
     * The cases this user may see: the single rule behind every case screen,
     * search, report and export (§7, §7.4). Anything not matched is denied,
     * and a role with no matching place in the organisation matches nothing.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->isActive()) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($user->hasPermission(Permission::CasesViewNational)) {
            return;
        }

        $seesRestricted = $user->hasPermission(Permission::CasesViewRestricted);
        $unlessRestricted = fn (Builder $query) => $seesRestricted
            ? $query
            : $query->where('lifecycle', '!=', RecordLifecycle::Restricted);

        $query->where(function (Builder $query) use ($user, $unlessRestricted): void {
            $query->whereRaw('1 = 0');

            if ($user->hasPermission(Permission::CasesViewDistrict) && $user->district_id !== null) {
                $query->orWhere(fn (Builder $query) => $unlessRestricted($query->where('district_id', $user->district_id)));
            }

            if ($user->hasPermission(Permission::CasesViewStation) && $user->station_id !== null) {
                $query->orWhere(fn (Builder $query) => $unlessRestricted($query->where('station_id', $user->station_id)));
            }

            if ($user->hasPermission(Permission::CasesViewAssigned)) {
                $query->orWhereHas('assignments', fn (Builder $assignments) => $assignments
                    ->where('user_id', $user->getKey())
                    ->whereNull('ended_at'));
            }

            // Exceptional access someone else approved, until it expires (§8).
            $query->orWhereIn('id', AccessJustification::query()->activeGrantFor($user)->select('case_id'));
        });
    }

    public function isVisibleTo(User $user): bool
    {
        return self::query()->visibleTo($user)->whereKey($this->getKey())->exists();
    }

    /**
     * @return BelongsTo<Station, $this>
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /**
     * @return BelongsTo<District, $this>
     */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /**
     * @return BelongsTo<OffenceCategory, $this>
     */
    public function offenceCategory(): BelongsTo
    {
        return $this->belongsTo(OffenceCategory::class);
    }

    /**
     * @return HasMany<CaseAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(CaseAssignment::class, 'case_id');
    }

    /**
     * @return HasMany<CasePerson, $this>
     */
    public function personLinks(): HasMany
    {
        return $this->hasMany(CasePerson::class, 'case_id');
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'case_id');
    }

    /**
     * @return HasMany<CourtProceeding, $this>
     */
    public function courtProceedings(): HasMany
    {
        return $this->hasMany(CourtProceeding::class, 'case_id');
    }

    /**
     * @return HasMany<InvestigationEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(InvestigationEvent::class, 'case_id');
    }
}
