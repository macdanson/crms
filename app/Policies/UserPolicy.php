<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * Administering accounts (developer guide §7.2, §23). Nobody may act on their
 * own account here: that would let an administrator escalate their own access
 * or lift their own suspension.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::UsersView);
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->hasPermission(Permission::UsersView);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(Permission::UsersManage);
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->hasPermission(Permission::UsersManage) && $actor->isNot($user);
    }

    public function changeStatus(User $actor, User $user): bool
    {
        return $actor->hasPermission(Permission::UsersManage) && $actor->isNot($user);
    }

    public function resetTwoFactor(User $actor, User $user): bool
    {
        return $actor->hasPermission(Permission::MfaReset) && $actor->isNot($user);
    }

    public function manageRoles(User $actor, User $user): bool
    {
        return $actor->hasPermission(Permission::RolesManage) && $actor->isNot($user);
    }
}
