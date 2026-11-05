<?php

namespace App\Services;

use App\Enums\AuditOutcome;
use App\Enums\CaseEventType;
use App\Enums\CourtOutcomeType;
use App\Enums\CourtProceedingStatus;
use App\Enums\OutcomeVerification;
use App\Models\CasePerson;
use App\Models\Conviction;
use App\Models\CourtOutcome;
use App\Models\CourtProceeding;
use App\Models\CriminalCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Court proceedings and judicial outcomes (developer guide §4.3, §27.5).
 *
 * An outcome is entered by one officer and verified by another. Only a verified
 * outcome has any effect, and a conviction exists only because of one. Later
 * outcomes supersede earlier ones without overwriting them.
 */
class CourtRecords
{
    public function __construct(
        private AuditLogger $audit,
        private CaseRecords $cases,
    ) {}

    public function addProceeding(CriminalCase $case, string $courtName, string $courtReference, CourtProceedingStatus $status, ?string $filedOn, User $actor): CourtProceeding
    {
        return DB::transaction(function () use ($case, $courtName, $courtReference, $status, $filedOn, $actor): CourtProceeding {
            $proceeding = new CourtProceeding;
            $proceeding->forceFill([
                'case_id' => $case->id,
                'court_name' => $courtName,
                'court_reference' => $courtReference,
                'status' => $status,
                'filed_on' => $filedOn,
                'recorded_by' => $actor->getKey(),
            ])->save();

            $this->cases->addHistory($case, CaseEventType::CourtRecorded, "Court proceeding added: {$courtName}.", $actor, ['proceeding_id' => $proceeding->id]);
            $this->audit->record('court.proceeding.created', AuditOutcome::Success, $actor, ['case_id' => $case->id], $proceeding);

            return $proceeding;
        });
    }

    public function recordOutcome(CourtProceeding $proceeding, CasePerson $link, CourtOutcomeType $outcome, string $outcomeDate, string $source, ?string $sourceReference, User $actor): CourtOutcome
    {
        if ($link->case_id !== $proceeding->case_id) {
            throw ValidationException::withMessages(['case_person_id' => 'That person is not linked to this case.']);
        }

        return DB::transaction(function () use ($proceeding, $link, $outcome, $outcomeDate, $source, $sourceReference, $actor): CourtOutcome {
            $record = new CourtOutcome;
            $record->forceFill([
                'court_proceeding_id' => $proceeding->id,
                'case_person_id' => $link->id,
                'outcome' => $outcome,
                'outcome_date' => $outcomeDate,
                'source' => $source,
                'source_reference' => $sourceReference,
                'verification_status' => OutcomeVerification::Pending,
                'recorded_by' => $actor->getKey(),
                'recorded_at' => now(),
            ])->save();

            $this->cases->addHistory($proceeding->criminalCase, CaseEventType::CourtRecorded, "Court outcome recorded, awaiting verification: {$outcome->label()}.", $actor, ['outcome_id' => $record->id]);
            $this->audit->record('court.outcome.recorded', AuditOutcome::Success, $actor, [
                'case_id' => $proceeding->case_id,
                'outcome' => $outcome->value,
            ], $record);

            return $record;
        });
    }

    /**
     * Verify or reject an outcome. Verifying one brings its effects: a
     * conviction is created, or an earlier one is marked as overturned.
     */
    public function verify(CourtOutcome $outcome, bool $confirmed, string $note, User $actor): void
    {
        if ($outcome->recorded_by === $actor->getKey()) {
            throw ValidationException::withMessages(['note' => 'A different officer must verify an outcome you recorded.']);
        }

        DB::transaction(function () use ($outcome, $confirmed, $note, $actor): void {
            $locked = CourtOutcome::query()->whereKey($outcome->id)->lockForUpdate()->firstOrFail();

            if ($locked->verification_status !== OutcomeVerification::Pending) {
                throw ValidationException::withMessages(['note' => 'This outcome has already been reviewed.']);
            }

            $outcome->forceFill([
                'verification_status' => $confirmed ? OutcomeVerification::Verified : OutcomeVerification::Rejected,
                'verified_by' => $actor->getKey(),
                'verified_at' => now(),
                'verification_note' => $note,
                'supersedes_id' => $confirmed ? $this->currentOutcomeId($outcome) : null,
            ])->save();

            if ($confirmed) {
                $this->applyEffects($outcome);
            }

            $case = $outcome->proceeding->criminalCase;
            $result = $confirmed ? 'verified' : 'rejected';

            $this->cases->addHistory($case, CaseEventType::CourtRecorded, "Court outcome {$result}: {$outcome->outcome->label()}.", $actor, ['outcome_id' => $outcome->id]);
            $this->audit->record("court.outcome.{$result}", AuditOutcome::Success, $actor, [
                'case_id' => $case->id,
                'outcome' => $outcome->outcome->value,
                'note' => $note,
            ], $outcome);
        });
    }

    /**
     * The verified outcome this person has now on this proceeding, if any.
     */
    private function currentOutcomeId(CourtOutcome $outcome): ?int
    {
        return CourtOutcome::query()
            ->where('court_proceeding_id', $outcome->court_proceeding_id)
            ->where('case_person_id', $outcome->case_person_id)
            ->where('verification_status', OutcomeVerification::Verified)
            ->whereKeyNot($outcome->id)
            ->orderByDesc('verified_at')
            ->orderByDesc('id')
            ->value('id');
    }

    private function applyEffects(CourtOutcome $outcome): void
    {
        if ($outcome->outcome === CourtOutcomeType::Convicted) {
            $conviction = new Conviction;
            $conviction->forceFill([
                'court_outcome_id' => $outcome->id,
                'case_person_id' => $outcome->case_person_id,
                'person_id' => $outcome->casePerson->person_id,
                'case_id' => $outcome->casePerson->case_id,
                'convicted_on' => $outcome->outcome_date,
            ])->save();

            return;
        }

        if ($outcome->outcome === CourtOutcomeType::ConvictionOverturned) {
            $updated = Conviction::query()
                ->where('case_person_id', $outcome->case_person_id)
                ->whereNull('overturned_at')
                ->update(['overturned_at' => now(), 'overturned_by_outcome_id' => $outcome->id]);

            if ($updated === 0) {
                throw ValidationException::withMessages(['note' => 'There is no standing conviction for this person to overturn.']);
            }
        }
    }
}
