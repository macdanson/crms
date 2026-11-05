<?php

use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\District;
use App\Models\Station;

it('lets an application administrator add and update the organisation', function () {
    $administrator = officer(Role::ApplicationAdministrator);

    $this->actingAs($administrator)
        ->post(route('admin.districts.store'), ['name' => 'Northern Test', 'code' => ' ntest '])
        ->assertRedirect();

    $district = District::where('code', 'NTEST')->sole();

    $this->post(route('admin.stations.store'), ['district_id' => $district->id, 'name' => 'Central Test', 'code' => 'ct-1'])
        ->assertRedirect();

    $station = Station::where('code', 'CT-1')->sole();

    $this->post(route('admin.departments.store'), ['station_id' => $station->id, 'name' => 'CID'])
        ->assertRedirect();

    $this->put(route('admin.stations.update', $station), ['name' => 'Renamed Test', 'is_active' => false])
        ->assertRedirect();

    expect($station->fresh())
        ->name->toBe('Renamed Test')
        ->is_active->toBeFalse()
        ->and(Department::where('station_id', $station->id)->count())->toBe(1)
        ->and(AuditEvent::where('event', 'organisation.station.updated')->sole())
        ->record_id->toBe((string) $station->id)
        ->context->toBe(['changed' => ['name', 'is_active']]);
});

it('does not let a station move to another district or change its code', function () {
    $station = Station::factory()->create(['code' => 'KEEP']);
    $districtId = $station->district_id;

    $this->actingAs(officer(Role::ApplicationAdministrator))
        ->put(route('admin.stations.update', $station), [
            'name' => 'Same',
            'is_active' => true,
            'code' => 'CHANGED',
            'district_id' => District::factory()->create()->id,
        ])
        ->assertRedirect();

    expect($station->fresh())->code->toBe('KEEP')->district_id->toBe($districtId);
});

it('refuses a badly formed code and a duplicate name', function () {
    $existing = District::factory()->create();

    $this->actingAs(officer(Role::ApplicationAdministrator))
        ->from(route('admin.organisation.index'))
        ->post(route('admin.districts.store'), ['name' => $existing->name, 'code' => 'no spaces!'])
        ->assertSessionHasErrors([
            'name' => 'The name has already been taken.',
            'code' => 'The code must be 2 to 12 letters, numbers or hyphens.',
        ]);
});

it('refuses organisation changes to other roles', function () {
    $this->actingAs(officer(Role::SecurityAdministrator))
        ->post(route('admin.districts.store'), ['name' => 'Nope', 'code' => 'NOPE'])
        ->assertForbidden();

    expect(District::where('code', 'NOPE')->exists())->toBeFalse();
});

it('shows the organisation structure', function () {
    Station::factory()->create(['name' => 'Visible Test Station']);

    $this->actingAs(officer(Role::ApplicationAdministrator))
        ->get(route('admin.organisation.index'))
        ->assertInertia(fn ($page) => $page->component('admin/organisation/Index')->has('districts', 2)->has('stations', 2));
});
