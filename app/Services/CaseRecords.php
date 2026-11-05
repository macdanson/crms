<?php

namespace App\Services;

use App\Enums\AuditOutcome;
use App\Enums\CaseEventType;
use App\Enums\CasePersonRole;
use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Enums\RecordLifecycle;
use App\Models\CaseAssignment;
use App\Models\CasePerson;
use App\Models\CriminalCase;
use App\Models\InvestigationEvent;
use App\Models\Person;
use App\Models\Station;
use App\Models\User;
use App\Notifications\CaseAssigned;
use App\Notifications\CaseStatusChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Every change to a case (developer guide §4, §13, §27.4). Each one adds to the
 * case's own history and to the audit trail, in the same transaction.
 */
class CaseRecords
{
    public function __construct(
        private AuditLogger $audit,
        private RecordCorrections $corrections,
    ) {}

    /**
     * @param  array{offence_category_id: int, incident_date: string, incident_location: string, narrative: string}  $details
     */
    public function open(array $details, User $actor): CriminalCase
    {
        /** @var Station $station */
        $station = $actor->station;

        return DB::transaction(function () use ($details, $actor, $station): CriminalCase {
            $case = new CriminalCase;
            $case->forceFill([
                'reference' => $this->nextReference($station),
                'station_id' => $station->id,
                'district_id' => $station->district_id,
                'offence_category_id' => $details['offence_category_id'],
                'incident_date' => $details['incident_date'],
                'incident_location' => $details['incident_location'],
                'narrative_ciphertext' => $details['narrative'],
                'status' => CaseStatus::Open,
                'lifecycle' => RecordLifecycle::Active,
                'created_by' => $actor->getKey(),
            ])->save();

            $this->addHistory($case, CaseEventType::Opened, "Case {$case->reference} opened.", $actor);
            $this->audit->record('case.created', AuditOutcome::Success, $actor, record: $case);

            return $case;
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return list<string> The attributes that changed.
     */
    public function correct(CriminalCase $case, array $changes, string $reason, User $actor): array
    {
        if (array_key_exists('narrative', $changes)) {
            $changes['narrative_ciphertext'] = $changes['narrative'];
            unset($changes['narrative']);
        }

        return DB::transaction(function () use ($case, $changes, $reason, $actor): array {
            $changed = $this->corrections->apply($case, $changes, $reason, $actor);

            if ($changed !== []) {
                $labels = implode(', ', array_map(fn (string $field): string => str_replace(['_ciphertext', '_id', '_'], ['', '', ' '], $field), $changed));

                $this->addHistory($case, CaseEventType::Corrected, "Corrected {$labels}: {$reason}", $actor, ['fields' => $changed]);
            }

            return $changed;
        });
    }

    public function addNote(CriminalCase $case, string $note, User $actor): void
    {
        DB::transaction(function () use ($case, $note, $actor): void {
            $this->addHistory($case, CaseEventType::Note, $note, $actor);
            $this->audit->record('case.note.added', AuditOutcome::Success, $actor, record: $case);
        });
    }

    /**
     * Change the police operational status. Closing a case says nothing about
     * guilt or innocence, so it never touches a court outcome (§4.3).
     */
    public function changeStatus(CriminalCase $case, CaseStatus $status, string $reason, User $actor): void
    {
        if (! in_array($status, $case->status->allowedNext(), true)) {
            throw ValidationException::withMessages(['status' => "A case that is {$case->status->label()} cannot move to {$status->label()}."]);
        }

        DB::transaction(function () use ($case, $status, $reason, $actor): void {
            $previous = $case->status;

            $case->forceFill([
                'status' => $status,
                'closed_at' => $status === CaseStatus::ClosedAdministratively ? now() : null,
            ])->save();

            $this->addHistory($case, CaseEventType::StatusChanged, "{$previous->label()} → {$status->label()}: {$reason}", $actor, [
                'from' => $previous->value,
                'to' => $status->value,
            ]);

            $this->audit->record('case.status.changed', AuditOutcome::Success, $actor, [
                'from' => $previous->value,
                'to' => $status->value,
                'reason' => $reason,
            ], $case);
        });

        $assigned = User::query()
            ->whereIn('id', $case->assignments()->whereNull('ended_at')->select('user_id'))
            ->whereKeyNot($actor->getKey())
            ->get();

        Notification::send($assigned, new CaseStatusChanged($case->id));
    }

    public function changeLifecycle(CriminalCase $case, RecordLifecycle $lifecycle, string $reason, User $actor): void
    {
        DB::transaction(function () use ($case, $lifecycle, $reason, $actor): void {
            $previous = $case->lifecycle;

            $case->forceFill(['lifecycle' => $lifecycle])->save();

            $this->addHistory($case, CaseEventType::LifecycleChanged, "{$previous->label()} → {$lifecycle->label()}: {$reason}", $actor, [
                'from' => $previous->value,
                'to' => $lifecycle->value,
            ]);

            $this->audit->record('case.lifecycle.changed', AuditOutcome::Success, $actor, [
                'from' => $previous->value,
                'to' => $lifecycle->value,
                'reason' => $reason,
            ], $case);
        });
    }

    /**
     * Assign an officer in the case's district who can work on assigned cases.
     * Provisional rule: the approved access matrix may widen it (§2.2).
     */
    public function assign(CriminalCase $case, User $officer, bool $isLead, User $actor): CaseAssignment
    {
        if ($officer->district_id !== $case->district_id || ! $officer->hasPermission(Permission::CasesViewAssigned)) {
            throw ValidationException::withMessages(['user_id' => 'Choose an active investigating officer in this case’s district.']);
        }

        $assignment = DB::transaction(function () use ($case, $officer, $isLead, $actor): CaseAssignment {
            CriminalCase::query()->whereKey($case->id)->lockForUpdate()->value('id');

            if ($case->assignments()->where('user_id', $officer->id)->whereNull('ended_at')->exists()) {
                throw ValidationException::withMessages(['user_id' => 'This officer is already assigned to the case.']);
            }

            if ($isLead) {
                $case->assignments()->whereNull('ended_at')->update(['is_lead' => false]);
            }

            $assignment = new CaseAssignment;
            $assignment->forceFill([
                'case_id' => $case->id,
                'user_id' => $officer->id,
                'is_lead' => $isLead,
                'assigned_by' => $actor->getKey(),
                'assigned_at' => now(),
            ])->save();

            $this->addHistory($case, CaseEventType::AssignmentAdded, "{$officer->name} assigned".($isLead ? ' as lead.' : '.'), $actor, ['user_id' => $officer->id]);
            $this->audit->record('case.assignment.created', AuditOutcome::Success, $actor, ['officer_id' => $officer->id, 'is_lead' => $isLead], $case);

            return $assignment;
        });

        $officer->notify(new CaseAssigned($case->id));

        return $assignment;
    }

    public function endAssignment(CaseAssignment $assignment, User $actor): void
    {
        DB::transaction(function () use ($assignment, $actor): void {
            $assignment->forceFill(['ended_at' => now(), 'ended_by' => $actor->getKey(), 'is_lead' => false])->save();

            $case = $assignment->criminalCase;

            $this->addHistory($case, CaseEventType::AssignmentEnded, "{$assignment->user->name}'s assignment ended.", $actor, ['user_id' => $assignment->user_id]);
            $this->audit->record('case.assignment.ended', AuditOutcome::Success, $actor, ['officer_id' => $assignment->user_id], $case);
        });
    }

    public function linkPerson(CriminalCase $case, Person $person, CasePersonRole $role, ?string $roleStatus, User $actor): CasePerson
    {
        return DB::transaction(function () use ($case, $person, $role, $roleStatus, $actor): CasePerson {
            CriminalCase::query()->whereKey($case->id)->lockForUpdate()->value('id');

            $alreadyLinked = $case->personLinks()
                ->where('person_id', $person->id)
                ->where('role', $role)
                ->whereNull('unlinked_at')
                ->exists();

            if ($alreadyLinked) {
                throw ValidationException::withMessages(['role' => "This person is already linked to the case as {$role->label()}."]);
            }

            $link = new CasePerson;
            $link->forceFill([
                'case_id' => $case->id,
                'person_id' => $person->id,
                'role' => $role,
                'role_status' => $roleStatus,
                'linked_by' => $actor->getKey(),
                'linked_at' => now(),
            ])->save();

            $this->addHistory($case, CaseEventType::PersonLinked, "A person was linked as {$role->label()}.", $actor, ['person_id' => $person->id, 'role' => $role->value]);
            $this->audit->record('case.person.linked', AuditOutcome::Success, $actor, ['person_id' => $person->id, 'role' => $role->value], $case);

            return $link;
        });
    }

    public function unlinkPerson(CasePerson $link, string $reason, User $actor): void
    {
        DB::transaction(function () use ($link, $reason, $actor): void {
            $link->forceFill(['unlinked_at' => now(), 'unlinked_by' => $actor->getKey(), 'unlink_reason' => $reason])->save();

            $case = $link->criminalCase;

            $this->addHistory($case, CaseEventType::PersonUnlinked, "A person's link as {$link->role->label()} ended: {$reason}", $actor, ['person_id' => $link->person_id, 'role' => $link->role->value]);
            $this->audit->record('case.person.unlinked', AuditOutcome::Success, $actor, ['person_id' => $link->person_id, 'role' => $link->role->value, 'reason' => $reason], $case);
        });
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function addHistory(CriminalCase $case, CaseEventType $type, string $summary, User $actor, ?array $meta = null): void
    {
        $event = new InvestigationEvent;
        $event->forceFill([
            'case_id' => $case->id,
            'user_id' => $actor->getKey(),
            'type' => $type,
            'summary_ciphertext' => $summary,
            'meta' => $meta,
            'occurred_at' => now(),
        ])->save();
    }

    /**
     * The next reference for the station and year, such as CRMS/KLA-01/2026/000012.
     * The row lock keeps numbers unique when two officers open a case at once.
     * It must run inside the caller's transaction.
     */
    private function nextReference(Station $station): string
    {
        $year = (int) now()->format('Y');

        DB::table('case_reference_sequences')->insertOrIgnore(['station_id' => $station->id, 'year' => $year, 'last_number' => 0]);

        $sequence = DB::table('case_reference_sequences')->where('station_id', $station->id)->where('year', $year);
        $number = (int) (clone $sequence)->lockForUpdate()->value('last_number') + 1;

        $sequence->update(['last_number' => $number]);

        return sprintf('CRMS/%s/%d/%06d', $station->code, $year, $number);
    }
}
