<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\DuplicateCandidate;
use App\Models\Person;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * What a user may do with a person record. As with cases, a record the user
 * may not see is answered as "not found".
 */
class PersonPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::PersonsView);
    }

    public function view(User $user, Person $person): Response
    {
        return $person->isVisibleTo($user) ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::PersonsCreate) && $user->station?->is_active === true;
    }

    public function correct(User $user, Person $person): Response
    {
        return $this->check($user, $person, Permission::PersonsCorrect);
    }

    /**
     * Adding details needs the right to register people or to correct them.
     */
    public function addDetails(User $user, Person $person): Response
    {
        return $this->check($user, $person, $user->hasPermission(Permission::PersonsCreate) ? Permission::PersonsCreate : Permission::PersonsCorrect);
    }

    public function verifyIdentity(User $user, Person $person): Response
    {
        return $this->check($user, $person, Permission::IdentifiersVerify);
    }

    public function reveal(User $user, Person $person): Response
    {
        return $this->check($user, $person, Permission::IdentifiersReveal, whileMerged: true);
    }

    /**
     * Reviewing a possible duplicate shows both records, so the reviewer must
     * be allowed to see both.
     */
    public function reviewDuplicate(User $user, DuplicateCandidate $candidate): Response
    {
        if (! $user->hasPermission(Permission::PersonsMerge)) {
            return Response::deny();
        }

        return $candidate->firstPerson->isVisibleTo($user) && $candidate->secondPerson->isVisibleTo($user)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    private function check(User $user, Person $person, Permission $permission, bool $whileMerged = false): Response
    {
        if (! $person->isVisibleTo($user)) {
            return Response::denyAsNotFound();
        }

        if (! $user->hasPermission($permission)) {
            return Response::deny();
        }

        if (! $whileMerged && $person->merged_into_id !== null) {
            return Response::deny('This record was merged into another. Make changes there.');
        }

        return Response::allow();
    }
}
