<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\DataExport;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DataExportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ExportsRequest) || $user->hasPermission(Permission::ExportsApprove);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ExportsRequest);
    }

    /**
     * Only the person who asked for an export can collect it.
     */
    public function download(User $user, DataExport $export): Response
    {
        return $export->requested_by === $user->getKey() && $user->hasPermission(Permission::ExportsRequest)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Someone else decides, and only for requesters they supervise.
     */
    public function decide(User $user, DataExport $export): bool
    {
        return $user->hasPermission(Permission::ExportsApprove)
            && $export->requested_by !== $user->getKey()
            && $this->supervises($user, $export->requester);
    }

    public function supervises(User $user, User $requester): bool
    {
        if ($user->district_id === null || $user->district_id !== $requester->district_id) {
            return false;
        }

        return $user->hasPermission(Permission::CasesViewDistrict)
            || ($user->station_id !== null && $user->station_id === $requester->station_id);
    }
}
