<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GrantRoleRequest;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\UserRoles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

class UserRoleController extends Controller
{
    public function store(GrantRoleRequest $request, User $user, UserRoles $roles): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $role = Role::from($request->string('role')->toString());

        $roles->grant(
            $user,
            $role,
            $request->string('reason')->toString(),
            $actor,
            $request->filled('expires_at') ? Carbon::parse($request->string('expires_at')->toString()) : null,
        );

        return back()->with('status', "{$role->label()} granted. {$user->name} must sign in again.");
    }

    public function destroy(Request $request, User $user, RoleAssignment $assignment, UserRoles $roles): RedirectResponse
    {
        Gate::authorize('manageRoles', $user);

        abort_unless($assignment->user_id === $user->id && $assignment->isActive(), 404);

        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        /** @var User $actor */
        $actor = $request->user();

        $roles->revoke($assignment, $validated['reason'], $actor);

        return back()->with('status', "Role revoked. {$user->name} must sign in again.");
    }
}
