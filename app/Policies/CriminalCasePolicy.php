<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\CriminalCase;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * What a user may do with a case (developer guide §7). A case the user may not
 * see is answered as "not found", so knowing or guessing its address does not
 * confirm that it exists (§25.2). Refusals are audited either way.
 */
class CriminalCasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::CasesViewNational)
            || $user->hasPermission(Permission::CasesViewDistrict)
            || $user->hasPermission(Permission::CasesViewStation)
            || $user->hasPermission(Permission::CasesViewAssigned);
    }

    public function view(User $user, CriminalCase $case): Response
    {
        return $case->isVisibleTo($user) ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::CasesCreate) && $user->station?->is_active === true;
    }

    public function correct(User $user, CriminalCase $case): Response
    {
        return $this->check($user, $case, Permission::CasesCorrect);
    }

    public function progress(User $user, CriminalCase $case): Response
    {
        return $this->check($user, $case, Permission::CasesProgress);
    }

    public function assign(User $user, CriminalCase $case): Response
    {
        return $this->check($user, $case, Permission::CasesAssign);
    }

    public function linkPerson(User $user, CriminalCase $case): Response
    {
        return $this->check($user, $case, Permission::CasesLinkPersons);
    }

    /**
     * Allowed on a read-only record: this is how it stops being one.
     */
    public function manageLifecycle(User $user, CriminalCase $case): Response
    {
        return $this->check($user, $case, Permission::CasesManageLifecycle, whileReadOnly: true);
    }

    public function printRecord(User $user, CriminalCase $case): Response
    {
        return $this->check($user, $case, Permission::CasesPrint, whileReadOnly: true);
    }

    public function uploadDocument(User $user, CriminalCase $case): Response
    {
        return $this->check($user, $case, Permission::DocumentsUpload);
    }

    public function viewDocuments(User $user, CriminalCase $case): Response
    {
        return $this->check($user, $case, Permission::DocumentsView, whileReadOnly: true);
    }

    /**
     * Allowed on a read-only record: retention review is when a hold matters.
     */
    public function holdDocuments(User $user, CriminalCase $case): Response
    {
        return $this->check($user, $case, Permission::DocumentsHold, whileReadOnly: true);
    }

    public function recordCourt(User $user, CriminalCase $case): Response
    {
        return $this->check($user, $case, Permission::CourtRecord);
    }

    public function verifyCourt(User $user, CriminalCase $case): Response
    {
        return $this->check($user, $case, Permission::CourtVerify, whileReadOnly: true);
    }

    private function check(User $user, CriminalCase $case, Permission $permission, bool $whileReadOnly = false): Response
    {
        if (! $case->isVisibleTo($user)) {
            return Response::denyAsNotFound();
        }

        if (! $user->hasPermission($permission)) {
            return Response::deny();
        }

        if (! $whileReadOnly && $case->lifecycle->isReadOnly()) {
            return Response::deny('This record is read-only because of its lifecycle state.');
        }

        return Response::allow();
    }
}
