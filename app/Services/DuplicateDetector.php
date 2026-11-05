<?php

namespace App\Services;

use App\Enums\AuditOutcome;
use App\Models\DuplicateCandidate;
use App\Models\Person;
use App\Models\PersonIdentity;
use Illuminate\Database\Eloquent\Builder;

/**
 * Raises possible duplicates for a person to review (developer guide §5). It
 * never merges anything, and a name match is only ever a possible match. The
 * person registering a record is not told about a match: it may concern a
 * record they are not allowed to know about.
 */
class DuplicateDetector
{
    public function __construct(private AuditLogger $audit) {}

    public function check(Person $person): void
    {
        $sameIdentity = PersonIdentity::query()
            ->whereIn('identifier_lookup_hmac', $person->identities()->pluck('identifier_lookup_hmac'))
            ->where('person_id', '!=', $person->id)
            ->whereHas('person', fn (Builder $query) => $query->notMerged())
            ->pluck('person_id')
            ->unique();

        foreach ($sameIdentity as $otherId) {
            $this->raise($person->id, $otherId, DuplicateCandidate::REASON_IDENTITY, 100);
        }

        if ($person->birth_year === null) {
            return;
        }

        $sameNameAndYear = Person::query()
            ->notMerged()
            ->where('search_name', $person->search_name)
            ->where('birth_year', $person->birth_year)
            ->whereKeyNot($person->id)
            ->pluck('id');

        foreach ($sameNameAndYear as $otherId) {
            $this->raise($person->id, $otherId, DuplicateCandidate::REASON_NAME_AND_YEAR, 70);
        }
    }

    /**
     * A pair that was already raised stays as it is, so a pair a reviewer
     * dismissed does not keep coming back.
     */
    private function raise(string $firstId, string $secondId, string $reason, int $confidence): void
    {
        [$first, $second] = $firstId < $secondId ? [$firstId, $secondId] : [$secondId, $firstId];

        $candidate = DuplicateCandidate::query()->firstOrCreate(
            ['first_person_id' => $first, 'second_person_id' => $second],
            ['reason' => $reason, 'confidence' => $confidence],
        );

        if ($candidate->wasRecentlyCreated) {
            $this->audit->record('person.duplicate.raised', AuditOutcome::Success, null, [
                'reason' => $reason,
                'first_person_id' => $first,
                'second_person_id' => $second,
            ], $candidate);
        }
    }
}
