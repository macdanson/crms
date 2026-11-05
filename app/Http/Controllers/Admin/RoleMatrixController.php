<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The role and permission matrix, read-only: it changes through an approved
 * release, not at run time (developer guide §2.2, §7.1).
 */
class RoleMatrixController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('admin/roles/Index', [
            'permissions' => array_map(fn (Permission $permission): string => $permission->value, Permission::cases()),
            'roles' => array_map(fn (Role $role): array => [
                'value' => $role->value,
                'label' => $role->label(),
                'permissions' => array_map(fn (Permission $permission): string => $permission->value, $role->permissions()),
            ], Role::cases()),
        ]);
    }
}
