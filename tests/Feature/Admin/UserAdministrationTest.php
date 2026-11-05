<?php

use App\Enums\AccountStatus;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\RoleAssignment;
use App\Models\Station;
use App\Models\User;
use App\Notifications\AccountInvitation;
use App\Services\SessionPolicy;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->administrator = officer(Role::SecurityAdministrator);
    $this->actingAs($this->administrator)->withSession(confirmedPassword());
});

it('invites a user and places them at a station', function () {
    Notification::fake();
    $station = Station::factory()->create();

    $this->post(route('admin.users.store'), [
        'name' => 'Test Officer',
        'email' => 'Test.Officer@crms.test',
        'staff_number' => 'TEST-777',
        'station_id' => $station->id,
    ])->assertRedirect();

    $user = User::where('email', 'test.officer@crms.test')->sole();

    expect($user)
        ->status->toBe(AccountStatus::Pending)
        ->station_id->toBe($station->id)
        ->district_id->toBe($station->district_id);

    Notification::assertSentTo($user, AccountInvitation::class);
});

it('needs a recent password confirmation to invite a user', function () {
    $this->withSession(['auth.password_confirmed_at' => 0])
        ->post(route('admin.users.store'), ['name' => 'Test Officer', 'email' => 'x@crms.test'])
        ->assertRedirect(route('password.confirm'));

    expect(User::where('email', 'x@crms.test')->exists())->toBeFalse();
});

it('grants a role with a reason, audits it and ends the user’s sessions', function () {
    $user = User::factory()->atStation(Station::factory()->create())->create();
    $stampBefore = $user->session_stamp;

    $this->post(route('admin.users.roles.store', $user), ['role' => Role::RecordsOfficer->value, 'reason' => 'Posted to records desk'])
        ->assertRedirect();

    expect($user->fresh()->hasRole(Role::RecordsOfficer))->toBeTrue()
        ->and($user->fresh()->session_stamp)->not->toBe($stampBefore)
        ->and(RoleAssignment::where('user_id', $user->id)->sole())
        ->granted_by->toBe($this->administrator->id)
        ->reason->toBe('Posted to records desk')
        ->and(AuditEvent::where('event', 'role.granted')->where('user_id', $user->id)->sole()->context)
        ->toMatchArray(['actor_id' => $this->administrator->id, 'role' => 'records_officer']);
});

it('refuses a user changing their own roles', function () {
    $this->post(route('admin.users.roles.store', $this->administrator), ['role' => Role::NationalOfficer->value, 'reason' => 'Escalating myself', 'expires_at' => now()->addDay()->toIso8601String()])
        ->assertForbidden();

    expect($this->administrator->fresh()->hasRole(Role::NationalOfficer))->toBeFalse();
});

it('refuses other administrators who may not manage roles', function () {
    $user = User::factory()->create();

    $this->actingAs(officer(Role::ApplicationAdministrator))
        ->post(route('admin.users.roles.store', $user), ['role' => Role::Auditor->value, 'reason' => 'Should not work'])
        ->assertForbidden();

    expect($user->fresh()->hasRole(Role::Auditor))->toBeFalse();
});

it('requires a placement and, for national access, an end date', function () {
    $unplaced = User::factory()->create();

    $this->post(route('admin.users.roles.store', $unplaced), ['role' => Role::StationCommander->value, 'reason' => 'Promoted to commander'])
        ->assertSessionHasErrors(['role' => 'Place this user at a station before granting the Station Commander role.']);

    $this->post(route('admin.users.roles.store', $unplaced), ['role' => Role::NationalOfficer->value, 'reason' => 'CID secondment'])
        ->assertSessionHasErrors(['expires_at' => 'Authorised National/CID Officer access must have an end date.']);
});

it('refuses granting a role the user already holds', function () {
    $user = User::factory()->withRole(Role::Auditor)->create();

    $this->post(route('admin.users.roles.store', $user), ['role' => Role::Auditor->value, 'reason' => 'Granting it twice'])
        ->assertSessionHasErrors(['role' => 'This user already holds the Auditor role.']);
});

it('revokes a role and keeps the history', function () {
    $user = User::factory()->withRole(Role::Auditor)->create();
    $assignment = $user->roleAssignments()->sole();

    $this->delete(route('admin.users.roles.destroy', [$user, $assignment]), ['reason' => 'Left the audit team'])
        ->assertRedirect();

    expect($assignment->fresh())
        ->revoked_by->toBe($this->administrator->id)
        ->revocation_reason->toBe('Left the audit team')
        ->and($user->fresh()->hasRole(Role::Auditor))->toBeFalse();
});

it('does not revoke an assignment through another user’s address', function () {
    $user = User::factory()->withRole(Role::Auditor)->create();
    $other = User::factory()->create();

    $this->delete(route('admin.users.roles.destroy', [$other, $user->roleAssignments()->sole()]), ['reason' => 'Wrong person'])
        ->assertNotFound();

    expect($user->fresh()->hasRole(Role::Auditor))->toBeTrue();
});

it('moves a user to a new station and ends their sessions', function () {
    $from = Station::factory()->create();
    $to = Station::factory()->create();
    $department = Department::factory()->create(['station_id' => $to->id]);
    $user = User::factory()->atStation($from)->create();
    $stampBefore = $user->session_stamp;

    $this->put(route('admin.users.update', $user), [
        'name' => $user->name,
        'station_id' => $to->id,
        'department_id' => $department->id,
    ])->assertRedirect();

    expect($user->fresh())
        ->station_id->toBe($to->id)
        ->district_id->toBe($to->district_id)
        ->department_id->toBe($department->id)
        ->session_stamp->not->toBe($stampBefore)
        ->and(AuditEvent::where('event', 'account.placement.changed')->sole()->context['previous']['station_id'])->toBe($from->id);
});

it('refuses a department from a different station', function () {
    $user = User::factory()->create();

    $this->put(route('admin.users.update', $user), [
        'name' => $user->name,
        'station_id' => Station::factory()->create()->id,
        'department_id' => Department::factory()->create()->id,
    ])->assertSessionHasErrors(['department_id' => 'That department is not at the chosen station.']);
});

it('refuses a move that would leave a station role with no station', function () {
    $user = officer(Role::InvestigatingOfficer);

    $this->put(route('admin.users.update', $user), ['name' => $user->name])
        ->assertSessionHasErrors('station_id');

    expect($user->fresh()->station_id)->not->toBeNull();
});

it('suspends a user, who can no longer use an existing session', function () {
    $user = officer(Role::RecordsOfficer);

    $this->post(route('admin.users.suspend', $user), ['reason' => 'Under investigation'])->assertRedirect();

    expect($user->fresh()->status)->toBe(AccountStatus::Suspended);

    $this->actingAs($user->fresh())
        ->withSession([SessionPolicy::STAMP => app(SessionPolicy::class)->stampFor($user)])
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));
});

it('deactivates a leaver and revokes their roles, so restoring gives back no access', function () {
    $user = officer(Role::RecordsOfficer);

    $this->post(route('admin.users.deactivate', $user), ['reason' => 'Left the service'])->assertRedirect();

    expect($user->fresh()->status)->toBe(AccountStatus::Deactivated)
        ->and($user->roleAssignments()->active()->count())->toBe(0);

    $this->post(route('admin.users.reactivate', $user))->assertRedirect();

    expect($user->fresh()->status)->toBe(AccountStatus::Active)
        ->and($user->fresh()->permissions())->toBe([]);
});

it('refuses restoring an account that is not suspended or deactivated', function () {
    $user = User::factory()->pending()->create();

    $this->post(route('admin.users.reactivate', $user))->assertSessionHas('error');

    expect($user->fresh()->status)->toBe(AccountStatus::Pending);
});

it('refuses suspending yourself or without a reason', function () {
    $this->post(route('admin.users.suspend', $this->administrator), ['reason' => 'Suspending myself'])->assertForbidden();

    $this->post(route('admin.users.suspend', User::factory()->create()), ['reason' => ''])
        ->assertSessionHasErrors(['reason' => 'The reason field is required.']);
});

it('clears a user’s authenticator', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('admin.users.reset-two-factor', $user))->assertRedirect();

    expect($user->fresh()->two_factor_secret)->toBeNull()
        ->and(AuditEvent::where('event', 'mfa.reset')->sole()->context['proof'])->toBe('administrator');
});

it('resends an invitation only to a pending account', function () {
    Notification::fake();
    $pending = User::factory()->pending()->create();
    $active = User::factory()->create();

    $this->post(route('admin.users.resend-invitation', $pending))->assertSessionHas('status');
    $this->post(route('admin.users.resend-invitation', $active))->assertSessionHas('error');

    Notification::assertSentTo($pending, AccountInvitation::class);
    Notification::assertNotSentTo($active, AccountInvitation::class);
});

it('lists and filters users without exposing secrets', function () {
    User::factory()->create(['name' => 'Findable Person']);
    User::factory()->create(['name' => 'Someone Else']);

    $response = $this->get(route('admin.users.index', ['search' => 'Findable']));

    $response->assertInertia(fn ($page) => $page
        ->component('admin/users/Index')
        ->has('users.data', 1)
        ->where('users.data.0.name', 'Findable Person')
        ->missing('users.data.0.password')
        ->missing('users.data.0.two_factor_secret'));
});

it('records each account review', function () {
    $user = User::factory()->create();

    $this->get(route('admin.users.show', $user))->assertOk();

    expect(AuditEvent::where('event', 'account.reviewed')->sole())->record_id->toBe((string) $user->id);
});

it('grants the first administrator from the command line, with an operator on record', function () {
    $user = User::factory()->create();

    $this->artisan('user:grant-role', ['email' => $user->email, 'role' => 'security_administrator', '--reason' => 'Project start', '--by' => 'ops@crms.test'])
        ->assertSuccessful();

    expect($user->fresh()->hasRole(Role::SecurityAdministrator))->toBeTrue()
        ->and(AuditEvent::where('event', 'role.granted')->sole()->context['operator'])->toBe('ops@crms.test');

    $this->artisan('user:revoke-role', ['email' => $user->email, 'role' => 'security_administrator', '--reason' => 'Handed over', '--by' => 'ops@crms.test'])
        ->assertSuccessful();

    expect($user->fresh()->hasRole(Role::SecurityAdministrator))->toBeFalse();
});

it('refuses an operator granting a role to their own account from the command line', function () {
    $user = User::factory()->create();

    $this->artisan('user:grant-role', ['email' => $user->email, 'role' => 'auditor', '--reason' => 'Myself', '--by' => $user->email])
        ->assertFailed();

    expect($user->fresh()->hasRole(Role::Auditor))->toBeFalse();
});
