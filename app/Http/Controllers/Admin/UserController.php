<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountStatus;
use App\Enums\AuditOutcome;
use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InviteUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\District;
use App\Models\RoleAssignment;
use App\Models\Station;
use App\Models\User;
use App\Services\AccountLifecycle;
use App\Services\AuditLogger;
use App\Services\UserRoles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * User accounts: provisioning, placement and access review (developer guide
 * §27.2). Accounts are never deleted; see UserLifecycleController.
 */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(AccountStatus::class)],
            'station_id' => ['nullable', 'integer'],
        ]);

        $users = User::query()
            ->with(['station:id,name', 'district:id,name', 'roleAssignments' => fn ($query) => $query->active()])
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(
                fn (Builder $query) => $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('staff_number', 'like', "%{$search}%"),
            ))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['station_id'] ?? null, fn (Builder $query, int|string $id) => $query->where('station_id', $id))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'staff_number' => $user->staff_number,
                'status' => $user->status->value,
                'status_label' => $user->status->label(),
                'station' => $user->station?->name,
                'district' => $user->district?->name,
                'roles' => $user->roleAssignments->map(fn (RoleAssignment $assignment): string => $assignment->role->label())->values(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/users/Index', [
            'users' => $users,
            'filters' => $filters,
            'statuses' => collect(AccountStatus::cases())->map(fn (AccountStatus $status): array => ['value' => $status->value, 'label' => $status->label()]),
            'stations' => Station::query()->orderBy('name')->get(['id', 'name']),
            'canInvite' => $request->user()?->can('create', User::class) ?? false,
        ]);
    }

    public function store(InviteUserRequest $request, AccountLifecycle $accounts, UserRoles $roles): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        /** @var array{name: string, email: string, staff_number?: string|null, job_title?: string|null} $details */
        $details = $request->safe()->only(['name', 'email', 'staff_number', 'job_title']);

        $user = $accounts->invite($details, $actor);

        $station = Station::find($request->integer('station_id'));

        if ($station !== null) {
            $roles->place($user, $station->district_id, $station, null, $actor);
        }

        return to_route('admin.users.show', $user)->with('status', "Activation link sent to {$user->email}.");
    }

    public function show(Request $request, User $user, AuditLogger $audit): Response
    {
        Gate::authorize('view', $user);

        /** @var User $actor */
        $actor = $request->user();

        $audit->record('account.reviewed', AuditOutcome::Success, $actor, ['subject_id' => $user->id], $user);

        return Inertia::render('admin/users/Show', [
            'subject' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'staff_number' => $user->staff_number,
                'job_title' => $user->job_title,
                'status' => $user->status->value,
                'status_label' => $user->status->label(),
                'suspension_reason' => $user->suspension_reason,
                'district_id' => $user->district_id,
                'station_id' => $user->station_id,
                'department_id' => $user->department_id,
                'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'is_self' => $actor->is($user),
            ],
            'assignments' => $user->roleAssignments()
                ->with(['grantedBy:id,name', 'revokedBy:id,name'])
                ->orderByDesc('granted_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (RoleAssignment $assignment): array => [
                    'id' => $assignment->id,
                    'role' => $assignment->role->label(),
                    'reason' => $assignment->reason,
                    'granted_by' => $assignment->grantedBy?->name,
                    'granted_at' => $assignment->granted_at->toIso8601String(),
                    'expires_at' => $assignment->expires_at?->toIso8601String(),
                    'revoked_at' => $assignment->revoked_at?->toIso8601String(),
                    'revoked_by' => $assignment->revokedBy?->name,
                    'revocation_reason' => $assignment->revocation_reason,
                    'is_active' => $assignment->isActive(),
                ]),
            'activity' => $actor->hasPermission(Permission::AuditView)
                ? AuditEvent::query()
                    ->where('user_id', $user->id)
                    ->orderByDesc('occurred_at')
                    ->orderByDesc('id')
                    ->limit(15)
                    ->get()
                    ->map(fn (AuditEvent $event): array => [
                        'id' => $event->id,
                        'event' => $event->event,
                        'outcome' => $event->outcome->value,
                        'occurred_at' => $event->occurred_at->toIso8601String(),
                    ])
                : [],
            'options' => [
                'roles' => collect(Role::cases())->map(fn (Role $role): array => [
                    'value' => $role->value,
                    'label' => $role->label(),
                    'requires_expiry' => $role->requiresExpiry(),
                ]),
                'districts' => District::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
                'stations' => Station::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'district_id']),
                'departments' => Department::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'station_id']),
            ],
            'can' => [
                'update' => $actor->can('update', $user),
                'change_status' => $actor->can('changeStatus', $user),
                'reset_two_factor' => $actor->can('resetTwoFactor', $user),
                'manage_roles' => $actor->can('manageRoles', $user),
            ],
        ]);
    }

    public function update(UpdateUserRequest $request, User $user, UserRoles $roles, AuditLogger $audit): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $user->forceFill($request->safe()->only(['name', 'staff_number', 'job_title']))->save();

        if ($user->wasChanged()) {
            $audit->record('account.details.updated', AuditOutcome::Success, $user, [
                'actor_id' => $actor->id,
                'changed' => array_keys(Arr::except($user->getChanges(), 'updated_at')),
            ]);
        }

        $roles->place(
            $user,
            $request->integer('district_id') ?: null,
            Station::find($request->integer('station_id')),
            Department::find($request->integer('department_id')),
            $actor,
        );

        return back()->with('status', 'Account updated.');
    }
}
