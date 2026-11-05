<?php

use App\Enums\AuditOutcome;
use App\Enums\JustificationStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\AccessJustification;
use App\Models\AuditEvent;
use App\Models\CasePerson;
use App\Models\CriminalCase;
use App\Models\DataExport;
use App\Models\Document;
use App\Models\Person;
use App\Models\PersonIdentity;
use App\Models\Station;
use App\Services\AccountLifecycle;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Storage;

/*
 * The essential authorisation test cases of developer guide §25.2, numbered the
 * same way, as a release gate (§25.3). The detail behind each one is in the
 * feature tests of the module concerned.
 */

beforeEach(function () {
    Storage::fake('documents');

    $this->stationA = Station::factory()->create();
    $this->stationB = Station::factory()->create();
    $this->caseB = CriminalCase::factory()->atStation($this->stationB)->create();
    $this->personB = Person::factory()->registeredAt($this->stationB)->create();
    CasePerson::factory()->create(['case_id' => $this->caseB->id, 'person_id' => $this->personB->id]);
});

it('1. stops an officer from Station A accessing Station B records', function () {
    $officerA = officer(Role::RecordsOfficer, $this->stationA);

    $this->actingAs($officerA);

    $this->get(route('cases.show', $this->caseB))->assertNotFound();
    $this->get(route('persons.show', $this->personB))->assertNotFound();

    $this->get(route('cases.index'))->assertInertia(fn ($page) => $page->has('cases.data', 0));
    $this->get(route('persons.index'))->assertInertia(fn ($page) => $page->has('people.data', 0));
});

it('2. stops a user reaching a case by changing the ID in the address', function () {
    $own = CriminalCase::factory()->atStation($this->stationA)->create();
    $officerA = officer(Role::RecordsOfficer, $this->stationA);

    $this->actingAs($officerA)->get(route('cases.show', $own))->assertOk();
    $this->get(str_replace($own->id, $this->caseB->id, route('cases.show', $own)))->assertNotFound();
});

it('3. stops a user revealing a full NIN without the permission', function () {
    $identity = PersonIdentity::factory()->number('TESTNIN12345')->create(['person_id' => $this->personB->id]);

    $this->actingAs(officer(Role::RecordsOfficer, $this->stationB))
        ->postJson(route('persons.reveal', $this->personB), [
            'kind' => 'identity',
            'record_id' => $identity->id,
            'purpose' => 'assigned_investigation',
            'password' => UserFactory::PASSWORD,
        ])
        ->assertForbidden()
        ->assertJsonMissing(['value' => 'TESTNIN12345']);
});

it('4. stops an investigator viewing an unrelated case merely by knowing its reference', function () {
    $investigator = officer(Role::InvestigatingOfficer, $this->stationB);

    $this->actingAs($investigator);

    $this->get(route('cases.show', $this->caseB))->assertNotFound();

    $this->postJson(route('search.cases'), ['reference' => $this->caseB->reference])
        ->assertOk()
        ->assertJsonCount(0, 'cases');
});

it('5. stops a suspended officer using an existing session', function () {
    $officer = officer(Role::RecordsOfficer, $this->stationB);

    $this->actingAs($officer)->get(route('cases.show', $this->caseB))->assertOk();

    app(AccountLifecycle::class)->suspend($officer, 'Synthetic suspension');

    $this->get(route('cases.show', $this->caseB))->assertRedirect(route('login'));
});

it('6. does not let a system administrator view operational records automatically', function (Role $role) {
    $administrator = officer($role, $this->stationB);

    expect($administrator->hasPermission(Permission::PersonsView))->toBeFalse();

    $this->actingAs($administrator);

    $this->get(route('cases.show', $this->caseB))->assertNotFound();
    $this->get(route('persons.show', $this->personB))->assertNotFound();
    $this->get(route('cases.index'))->assertForbidden();
})->with([Role::ApplicationAdministrator, Role::SecurityAdministrator, Role::InfrastructureAdministrator]);

it('7. stops a report or export bypassing record-level restrictions', function () {
    config(['crms.exports.enabled' => true]);
    $commander = officer(Role::StationCommander, $this->stationA);
    CriminalCase::factory()->atStation($this->stationA)->create();

    $this->actingAs($commander)
        ->get(route('reports.index'))
        ->assertInertia(fn ($page) => $page->where('total', 1));

    $this->post(route('exports.store'), ['purpose' => 'approved_reporting', 'explanation' => 'Monthly review for the station.'])->assertRedirect();

    expect($this->get(route('exports.download', DataExport::sole()))->getContent())
        ->not->toContain($this->caseB->reference);
});

it('8. stops an unauthorised user reusing a document address', function () {
    $document = Document::factory()->create(['case_id' => $this->caseB->id]);
    $url = route('documents.show', $document);

    $this->actingAs(officer(Role::RecordsOfficer, $this->stationB))->get($url)->assertOk();
    $this->actingAs(officer(Role::RecordsOfficer, $this->stationA))->get($url)->assertNotFound();

    expect(AuditEvent::where('event', 'auth.access.denied')->count())->toBe(1);
});

it('9. stops a user granting themselves permissions', function () {
    $administrator = officer(Role::SecurityAdministrator);

    $this->actingAs($administrator)
        ->withSession(confirmedPassword())
        ->post(route('admin.users.roles.store', $administrator), [
            'role' => Role::NationalOfficer->value,
            'reason' => 'Giving myself national access',
            'expires_at' => now()->addDay()->toIso8601String(),
        ])
        ->assertForbidden();

    expect($administrator->fresh()->hasRole(Role::NationalOfficer))->toBeFalse()
        ->and($this->post(route('admin.users.reset-two-factor', $administrator))->status())->toBe(403);
});

it('10. logs an exceptional-access event and makes it available for review', function () {
    $requester = officer(Role::InvestigatingOfficer, $this->stationA);
    $commander = officer(Role::StationCommander, $this->stationB);
    $auditor = officer(Role::Auditor);

    $request = AccessJustification::factory()->pendingFor($this->caseB)->create(['user_id' => $requester->id]);

    $this->actingAs($commander)
        ->withSession(confirmedPassword())
        ->post(route('access-requests.decide', $request), ['approve' => true, 'note' => 'Confirmed the link to the investigation']);

    expect($request->fresh()->status)->toBe(JustificationStatus::Approved);

    $this->actingAs($requester)->get(route('cases.show', $this->caseB))->assertOk();

    $this->actingAs($auditor)
        ->get(route('admin.audit.index', ['event' => 'access.request.approved']))
        ->assertInertia(fn ($page) => $page
            ->has('events.data', 1)
            ->where('events.data.0.record_id', $this->caseB->id)
            ->where('events.data.0.outcome', AuditOutcome::Success->value));

    expect(AuditEvent::where('event', 'case.viewed')->where('user_id', $requester->id)->sole()->context['exceptional_access'])
        ->toBe($request->id);
});
