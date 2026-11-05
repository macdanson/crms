<?php

use App\Enums\CaseStatus;
use App\Enums\ExportStatus;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\CriminalCase;
use App\Models\DataExport;
use App\Models\Person;
use App\Models\Station;
use App\Notifications\ExportDecided;
use App\Services\DataExports;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

function exportRequest(array $overrides = []): array
{
    return [
        'purpose' => 'approved_reporting',
        'explanation' => 'Monthly report for the district review.',
        ...$overrides,
    ];
}

beforeEach(function () {
    Storage::fake('documents');
    config(['crms.exports.enabled' => true, 'crms.exports.approval_threshold' => 2]);

    $this->station = Station::factory()->create();
    $this->commander = officer(Role::StationCommander, $this->station);
});

it('is off by default', function () {
    config(['crms.exports.enabled' => false]);
    CriminalCase::factory()->atStation($this->station)->create();

    $this->actingAs($this->commander)->post(route('exports.store'), exportRequest())->assertForbidden();

    expect(DataExport::count())->toBe(0);
});

it('needs a purpose and an explanation', function () {
    $this->actingAs($this->commander)
        ->post(route('exports.store'), [])
        ->assertSessionHasErrors(['purpose' => 'The purpose field is required.', 'explanation' => 'The explanation field is required.']);
});

it('prepares a small export, containing only cases in reach and approved fields', function () {
    $visible = CriminalCase::factory()->atStation($this->station)->create(['reference' => 'CRMS/VISIBLE/1']);
    $hidden = CriminalCase::factory()->create(['reference' => 'CRMS/HIDDEN/1']);

    $this->actingAs($this->commander)->post(route('exports.store'), exportRequest())->assertRedirect();

    $export = DataExport::sole();

    expect($export->status)->toBe(ExportStatus::Ready)
        ->and($export->row_count)->toBe(1)
        ->and(Storage::disk('documents')->get($export->storage_key))->not->toContain('CRMS/VISIBLE');

    $response = $this->get(route('exports.download', $export))->assertOk();

    expect($response->getContent())
        ->toContain($visible->reference)
        ->toContain($this->commander->name)
        ->toContain('reference,station,district,category,status,incident_date')
        ->not->toContain($hidden->reference)
        ->not->toContain('Synthetic narrative');
});

it('contains no personal data even for a linked person', function () {
    $case = CriminalCase::factory()->atStation($this->station)->create();
    $person = Person::factory()->registeredAt($this->station)->create();
    $case->personLinks()->forceCreate(['person_id' => $person->id, 'role' => 'witness', 'linked_by' => $this->commander->id, 'linked_at' => now()]);

    $this->actingAs($this->commander)->post(route('exports.store'), exportRequest());

    expect($this->get(route('exports.download', DataExport::sole()))->getContent())->not->toContain($person->given_names);
});

it('neutralises a value a spreadsheet would run as a formula', function () {
    CriminalCase::factory()->atStation($this->station)->create(['reference' => '=HYPERLINK("http://evil")']);

    $this->actingAs($this->commander)->post(route('exports.store'), exportRequest());

    expect($this->get(route('exports.download', DataExport::sole()))->getContent())->toContain("'=HYPERLINK");
});

it('can be collected once, by the requester only, and the file is destroyed', function () {
    CriminalCase::factory()->atStation($this->station)->create();

    $this->actingAs($this->commander)->post(route('exports.store'), exportRequest());
    $export = DataExport::sole();

    $this->actingAs(officer(Role::StationCommander, $this->station))->get(route('exports.download', $export))->assertNotFound();

    $this->actingAs($this->commander)->get(route('exports.download', $export))->assertOk();
    $this->get(route('exports.download', $export))->assertStatus(410);

    Storage::disk('documents')->assertMissing($export->storage_key);

    expect($export->fresh()->status)->toBe(ExportStatus::Downloaded)
        ->and(AuditEvent::where('event', 'export.downloaded')->count())->toBe(1);
});

it('sends a large export for approval by another officer', function () {
    Notification::fake();
    CriminalCase::factory()->count(3)->atStation($this->station)->create();

    $this->actingAs($this->commander)->post(route('exports.store'), exportRequest())->assertRedirect();

    $export = DataExport::sole();

    expect($export->status)->toBe(ExportStatus::PendingApproval)->and($export->storage_key)->toBeNull();

    $this->get(route('exports.download', $export))->assertStatus(410);

    $this->withSession(confirmedPassword())
        ->post(route('exports.decide', $export), ['approve' => true, 'note' => 'Approving my own'])
        ->assertForbidden();

    $supervisor = officer(Role::DistrictSupervisor, Station::factory()->create(['district_id' => $this->station->district_id]));

    $this->actingAs($supervisor)
        ->withSession(confirmedPassword())
        ->post(route('exports.decide', $export), ['approve' => true, 'note' => 'Needed for the review'])
        ->assertRedirect();

    expect($export->fresh())
        ->status->toBe(ExportStatus::Ready)
        ->decided_by->toBe($supervisor->id)
        ->row_count->toBe(3);

    Notification::assertSentTo($this->commander, ExportDecided::class);
});

it('does not prepare a rejected export', function () {
    Notification::fake();
    $export = DataExport::factory()->create(['requested_by' => $this->commander->id]);

    $this->actingAs(officer(Role::DistrictSupervisor, $this->station))
        ->withSession(confirmedPassword())
        ->post(route('exports.decide', $export), ['approve' => false, 'note' => 'Too broad for the purpose'])
        ->assertRedirect();

    expect($export->fresh())
        ->status->toBe(ExportStatus::Rejected)
        ->storage_key->toBeNull();
});

it('lets only a supervisor of the requester decide', function () {
    $export = DataExport::factory()->create(['requested_by' => $this->commander->id]);

    $this->actingAs(officer(Role::DistrictSupervisor))
        ->withSession(confirmedPassword())
        ->post(route('exports.decide', $export), ['approve' => true, 'note' => 'Other district'])
        ->assertForbidden();

    $this->actingAs(officer(Role::SecurityAdministrator))
        ->withSession(confirmedPassword())
        ->post(route('exports.decide', $export), ['approve' => true, 'note' => 'Administrator'])
        ->assertForbidden();

    expect($export->fresh()->status)->toBe(ExportStatus::PendingApproval);
});

it('applies access as it is when prepared, so losing access stops an approved export', function () {
    $export = DataExport::factory()->create(['requested_by' => $this->commander->id, 'status' => ExportStatus::Approved]);

    $this->commander->roleAssignments()->update(['revoked_at' => now()]);
    $this->commander->forgetActiveRoles();

    app(DataExports::class)->generate($export->fresh());

    expect($export->fresh())
        ->status->toBe(ExportStatus::Failed)
        ->storage_key->toBeNull()
        ->and(AuditEvent::where('event', 'export.refused')->count())->toBe(1);
});

it('filters by what was asked for', function () {
    CriminalCase::factory()->atStation($this->station)->create(['status' => CaseStatus::Open]);
    CriminalCase::factory()->atStation($this->station)->create(['status' => CaseStatus::Submitted]);

    $this->actingAs($this->commander)
        ->post(route('exports.store'), exportRequest(['status' => 'submitted']))
        ->assertRedirect();

    expect(DataExport::sole())
        ->row_count->toBe(1)
        ->filters->toBe(['status' => 'submitted']);
});

it('refuses an export that matches nothing', function () {
    $this->actingAs($this->commander)
        ->post(route('exports.store'), exportRequest())
        ->assertSessionHasErrors(['filters' => 'No cases you can access match these filters.']);
});

it('destroys files that are not collected in time', function () {
    config(['crms.auth.idle_timeout' => 0, 'crms.auth.absolute_lifetime' => 0]);
    CriminalCase::factory()->atStation($this->station)->create();
    $this->actingAs($this->commander)->post(route('exports.store'), exportRequest());
    $export = DataExport::sole();

    $this->travel((int) config('crms.exports.expiry_hours') + 1)->hours();

    $this->get(route('exports.download', $export))->assertStatus(410);
    $this->artisan('exports:prune')->assertSuccessful();

    Storage::disk('documents')->assertMissing($export->storage_key);

    expect($export->fresh()->status)->toBe(ExportStatus::Expired);
});

it('logs requester, filters, fields, row count and approval needs', function () {
    CriminalCase::factory()->atStation($this->station)->create(['status' => CaseStatus::Open]);

    $this->actingAs($this->commander)->post(route('exports.store'), exportRequest(['status' => 'open']));

    expect(AuditEvent::where('event', 'export.requested')->sole()->context)->toMatchArray([
        'filters' => ['status' => 'open'],
        'fields' => config('crms.exports.fields'),
        'row_count' => 1,
        'purpose' => 'approved_reporting',
        'needs_approval' => false,
    ]);
});

it('marks a printed copy with who printed it, and records it', function () {
    $case = CriminalCase::factory()->atStation($this->station)->create();

    $this->actingAs($this->commander)
        ->get(route('cases.print', $case))
        ->assertInertia(fn ($page) => $page->component('cases/Print')->where('watermark', fn (string $mark) => str_contains($mark, $this->commander->name)));

    expect(AuditEvent::where('event', 'case.printed')->count())->toBe(1);
});

it('restricts printing by role', function () {
    $case = CriminalCase::factory()->atStation($this->station)->create();

    $this->actingAs(officer(Role::RecordsOfficer, $this->station))->get(route('cases.print', $case))->assertForbidden();
});
