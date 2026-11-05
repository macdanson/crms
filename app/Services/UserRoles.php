<?php

namespace App\Services;

use App\Enums\AuditOutcome;
use App\Enums\Role;
use App\Models\Department;
use App\Models\RoleAssignment;
use App\Models\Station;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes what a user may reach: their roles and their place in the
 * organisation (developer guide §7, §23, §27.2).
 *
 * Every change is audited and ends the user's sessions, so nobody carries a
 * session across a change of privilege (§6.1, §16). Nobody can change their
 * own roles or placement (§25.2 test 9): the check is here as well as in the
 * policy so the console commands are held to it too.
 */
class UserRoles
{
    public function __construct(
        private AuditLogger $audit,
        private SessionPolicy $sessions,
    ) {}

    public function grant(User $user, Role $role, string $reason, ?User $actor = null, ?CarbonInterface $expiresAt = null): RoleAssignment
    {
        $this->refuseSelfChange($user, $actor, 'role');

        if ($role->requiresExpiry() && $expiresAt === null) {
            throw ValidationException::withMessages(['expires_at' => "{$role->label()} access must have an end date."]);
        }

        if ($expiresAt !== null && $expiresAt->isPast()) {
            throw ValidationException::withMessages(['expires_at' => 'The end date must be in the future.']);
        }

        $this->refuseMissingPlacement($user, $role);

        $assignment = DB::transaction(function () use ($user, $role, $reason, $actor, $expiresAt): RoleAssignment {
            User::query()->whereKey($user->getKey())->lockForUpdate()->value('id');

            if ($user->roleAssignments()->active()->where('role', $role)->exists()) {
                throw ValidationException::withMessages(['role' => "This user already holds the {$role->label()} role."]);
            }

            $assignment = new RoleAssignment;
            $assignment->forceFill([
                'user_id' => $user->getKey(),
                'role' => $role,
                'reason' => $reason,
                'granted_by' => $actor?->getKey(),
                'granted_at' => now(),
                'expires_at' => $expiresAt,
            ])->save();

            $this->sessions->endOtherSessions($user);

            return $assignment;
        });

        $user->forgetActiveRoles();

        $this->audit->record('role.granted', AuditOutcome::Success, $user, [
            'actor_id' => $actor?->getKey(),
            'role' => $role->value,
            'reason' => $reason,
            'expires_at' => $expiresAt?->toIso8601String(),
        ]);

        return $assignment;
    }

    public function revoke(RoleAssignment $assignment, string $reason, ?User $actor = null): void
    {
        $user = $assignment->user;

        $this->refuseSelfChange($user, $actor, 'role');

        DB::transaction(function () use ($assignment, $user, $reason, $actor): void {
            $assignment->forceFill([
                'revoked_at' => now(),
                'revoked_by' => $actor?->getKey(),
                'revocation_reason' => $reason,
            ])->save();

            $this->sessions->endOtherSessions($user);
        });

        $user->forgetActiveRoles();

        $this->audit->record('role.revoked', AuditOutcome::Success, $user, [
            'actor_id' => $actor?->getKey(),
            'role' => $assignment->role->value,
            'reason' => $reason,
        ]);
    }

    /**
     * Place the user at a station (which sets their district), in a district
     * only, or nowhere. A station role cannot outlive its station: a move that
     * would leave one meaningless is refused until the role is revoked.
     */
    public function place(User $user, ?int $districtId, ?Station $station, ?Department $department, ?User $actor = null): void
    {
        $this->refuseSelfChange($user, $actor, 'station_id');

        $districtId = $station !== null ? $station->district_id : $districtId;
        $stationId = $station?->getKey();
        $departmentId = $department?->getKey();

        if ($department !== null && $department->station_id !== $stationId) {
            throw ValidationException::withMessages(['department_id' => 'That department is not at the chosen station.']);
        }

        if ($user->district_id === $districtId && $user->station_id === $stationId && $user->department_id === $departmentId) {
            return;
        }

        foreach ($user->activeRoles() as $role) {
            $needed = $role->requiredPlacement();

            if (($needed === 'station' && $stationId === null) || ($needed === 'district' && $districtId === null)) {
                throw ValidationException::withMessages(['station_id' => "Revoke the {$role->label()} role first: it needs a {$needed}."]);
            }
        }

        $previous = $user->only(['district_id', 'station_id', 'department_id']);

        DB::transaction(function () use ($user, $districtId, $stationId, $departmentId): void {
            $user->forceFill(['district_id' => $districtId, 'station_id' => $stationId, 'department_id' => $departmentId])->save();

            $this->sessions->endOtherSessions($user);
        });

        $this->audit->record('account.placement.changed', AuditOutcome::Success, $user, [
            'actor_id' => $actor?->getKey(),
            'previous' => $previous,
            'current' => $user->only(['district_id', 'station_id', 'department_id']),
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function refuseSelfChange(User $user, ?User $actor, string $field): void
    {
        if ($actor?->is($user)) {
            $this->audit->record('role.self_change.refused', AuditOutcome::Blocked, $user);

            throw ValidationException::withMessages([$field => 'You cannot change your own access. Ask another administrator.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private function refuseMissingPlacement(User $user, Role $role): void
    {
        $needed = $role->requiredPlacement();

        if (($needed === 'station' && $user->station_id === null) || ($needed === 'district' && $user->district_id === null)) {
            throw ValidationException::withMessages(['role' => "Place this user at a {$needed} before granting the {$role->label()} role."]);
        }
    }
}
