<?php

namespace App\Services;

use App\Enums\AccessPurpose;
use App\Enums\AuditOutcome;
use App\Enums\JustificationStatus;
use App\Enums\Permission;
use App\Models\AccessJustification;
use App\Models\CriminalCase;
use App\Models\User;
use App\Notifications\AccessRequestDecided;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Purpose capture and exceptional access (developer guide §8).
 *
 * Someone else always decides, the grant is reason-bound and time-limited, and
 * every step is audited. There is no self-service or emergency route.
 */
class AccessRequests
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Record why a sensitive action is being taken. It needs no approval.
     */
    public function recordPurpose(User $user, string $action, AccessPurpose $purpose, ?string $caseReference = null, ?string $explanation = null): AccessJustification
    {
        $justification = new AccessJustification;
        $justification->forceFill([
            'user_id' => $user->getKey(),
            'action' => $action,
            'purpose' => $purpose,
            'case_reference' => $caseReference,
            'explanation' => $explanation,
            'status' => JustificationStatus::Recorded,
        ])->save();

        return $justification;
    }

    /**
     * Ask for access to a case by its reference. The answer is the same whether
     * or not the reference exists, so asking confirms nothing: a request for an
     * unknown reference simply never reaches a decision-maker.
     */
    public function request(User $user, string $reference, AccessPurpose $purpose, string $explanation): AccessJustification
    {
        $case = CriminalCase::query()->where('reference', trim($reference))->first();

        $justification = new AccessJustification;
        $justification->forceFill([
            'user_id' => $user->getKey(),
            'action' => AccessJustification::ACTION_CASE_ACCESS,
            'purpose' => $purpose,
            'case_reference' => trim($reference),
            'case_id' => $case?->id,
            'explanation' => $explanation,
            'status' => JustificationStatus::Pending,
        ])->save();

        $this->audit->record('access.request.created', AuditOutcome::Success, $user, [
            'justification_id' => $justification->id,
            'purpose' => $purpose->value,
            'reference' => trim($reference),
        ], $case);

        return $justification;
    }

    public function decide(AccessJustification $request, bool $approve, string $note, User $actor): void
    {
        DB::transaction(function () use ($request, $approve, $note, $actor): void {
            $locked = AccessJustification::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== JustificationStatus::Pending) {
                throw ValidationException::withMessages(['note' => 'This request has already been decided.']);
            }

            $request->forceFill([
                'status' => $approve ? JustificationStatus::Approved : JustificationStatus::Rejected,
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
                'decision_note' => $note,
                'expires_at' => $approve ? now()->addHours((int) config('crms.access.exceptional_hours')) : null,
            ])->save();

            $this->audit->record($approve ? 'access.request.approved' : 'access.request.rejected', AuditOutcome::Success, $actor, [
                'justification_id' => $request->id,
                'requester_id' => $request->user_id,
                'note' => $note,
                'expires_at' => $request->expires_at?->toIso8601String(),
            ], $request->criminalCase);
        });

        $request->user->notify(new AccessRequestDecided($approve));
    }

    /**
     * Whether this user can decide this request: someone else, with the right
     * permission, who can already see the case.
     */
    public function canDecide(User $actor, AccessJustification $request): bool
    {
        return $actor->hasPermission(Permission::AccessApprove)
            && $request->user_id !== $actor->getKey()
            && $request->criminalCase?->isVisibleTo($actor) === true;
    }
}
