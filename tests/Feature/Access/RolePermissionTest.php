<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\RoleAssignment;
use App\Models\Station;
use App\Models\User;
use App\Policies\UserPolicy;

$operational = fn (): array => array_filter(Permission::cases(), fn (Permission $permission): bool => str_starts_with($permission->value, 'persons.')
    || str_starts_with($permission->value, 'cases.')
    || str_starts_with($permission->value, 'documents.')
    || str_starts_with($permission->value, 'court.')
    || str_starts_with($permission->value, 'search.')
    || str_starts_with($permission->value, 'exports.'));

it('gives technical and audit roles no access to operational records', function (Role $role) use ($operational) {
    expect(array_intersect(
        array_map(fn (Permission $permission) => $permission->value, $role->permissions()),
        array_map(fn (Permission $permission) => $permission->value, $operational()),
    ))->toBeEmpty();
})->with([
    Role::ApplicationAdministrator,
    Role::SecurityAdministrator,
    Role::InfrastructureAdministrator,
    Role::Auditor,
]);

it('gives no permission to a user without a role', function () {
    expect(User::factory()->create()->permissions())->toBe([]);
});

it('stops a role working once it is revoked or has expired', function () {
    $user = User::factory()->create();
    $assignment = RoleAssignment::factory()->create(['user_id' => $user->id, 'role' => Role::Auditor, 'expires_at' => now()->addHour()]);

    expect($user->hasPermission(Permission::AuditView))->toBeTrue();

    $this->travel(2)->hours();
    $user->forgetActiveRoles();
    expect($user->hasPermission(Permission::AuditView))->toBeFalse();

    $this->travelBack();
    $assignment->forceFill(['revoked_at' => now()])->save();
    $user->forgetActiveRoles();
    expect($user->hasPermission(Permission::AuditView))->toBeFalse();
});

it('gives an account that cannot sign in no permission, whatever roles it holds', function (string $state) {
    $user = User::factory()->{$state}()->withRole(Role::Auditor)->create();

    expect($user->hasPermission(Permission::AuditView))->toBeFalse();
})->with(['suspended', 'deactivated', 'pending']);

it('never lets anyone act on their own account', function (string $ability) {
    $administrator = officer(Role::SecurityAdministrator);

    expect((new UserPolicy)->{$ability}($administrator, $administrator))->toBeFalse()
        ->and((new UserPolicy)->{$ability}($administrator, User::factory()->create()))->toBeTrue();
})->with(['update', 'changeStatus', 'resetTwoFactor', 'manageRoles']);

it('refuses administrative pages to a user whose role does not allow them', function (string $route, Role $role) {
    $this->actingAs(officer($role))->get(route($route))->assertForbidden();
})->with([
    ['admin.users.index', Role::InvestigatingOfficer],
    ['admin.organisation.index', Role::SecurityAdministrator],
    ['admin.audit.index', Role::ApplicationAdministrator],
    ['admin.roles.index', Role::StationCommander],
]);

it('sends a guest to sign in from administrative pages', function () {
    $this->get(route('admin.users.index'))->assertRedirect(route('login'));
});

it('sends a user without an authenticator to enrol before administrative pages', function () {
    $user = User::factory()->atStation(Station::factory()->create())->withRole(Role::SecurityAdministrator)->create();

    $this->actingAs($user)->get(route('admin.users.index'))->assertRedirect();
});

it('prints the matrix for review', function () {
    $this->artisan('access:matrix')
        ->expectsOutputToContain('`cases.view.national`')
        ->assertSuccessful();
});
