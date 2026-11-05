<?php

use App\Enums\CaseEventType;
use App\Enums\CasePersonRole;
use App\Enums\CaseStatus;
use App\Enums\RecordLifecycle;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\CaseAssignment;
use App\Models\CasePerson;
use App\Models\CriminalCase;
use App\Models\District;
use App\Models\InvestigationEvent;
use App\Models\OffenceCategory;
use App\Models\Person;
use App\Models\RecordCorrection;
use App\Models\Station;
use Illuminate\Support\Facades\DB;

function validCase(array $overrides = []): array
{
    return [
        'offence_category_id' => OffenceCategory::factory()->create()->id,
        'incident_date' => '2026-10-01',
        'incident_location' => 'Synthetic location',
        'narrative' => 'A synthetic narrative of the incident.',
        ...$overrides,
    ];
}

beforeEach(function () {
    $this->station = Station::factory()->create(['code' => 'ST-1']);
    $this->records = officer(Role::RecordsOfficer, $this->station);
});

it('opens a case at the officer’s own station with a unique reference', function () {
    $this->travelTo('2026-11-04 10:00:00');
    $otherStation = Station::factory()->create();

    $this->actingAs($this->records)
        ->post(route('cases.store'), validCase(['station_id' => $otherStation->id, 'status' => 'closed_administratively']))
        ->assertRedirect();

    $this->post(route('cases.store'), validCase())->assertRedirect();

    $cases = CriminalCase::orderBy('reference')->get();

    expect($cases->pluck('reference')->all())->toBe(['CRMS/ST-1/2026/000001', 'CRMS/ST-1/2026/000002'])
        ->and($cases[0])
        ->station_id->toBe($this->station->id)
        ->district_id->toBe($this->station->district_id)
        ->status->toBe(CaseStatus::Open)
        ->and(InvestigationEvent::where('case_id', $cases[0]->id)->sole()->type)->toBe(CaseEventType::Opened);
});

it('stores the narrative encrypted', function () {
    $this->actingAs($this->records)->post(route('cases.store'), validCase());

    expect(DB::table('criminal_cases')->sole()->narrative_ciphertext)->not->toContain('synthetic')
        ->and(CriminalCase::sole()->narrative_ciphertext)->toBe('A synthetic narrative of the incident.');
});

it('refuses opening a case in a retired category', function () {
    $retired = OffenceCategory::factory()->create(['is_active' => false]);

    $this->actingAs($this->records)
        ->post(route('cases.store'), validCase(['offence_category_id' => $retired->id]))
        ->assertSessionHasErrors('offence_category_id');
});

it('refuses opening a case with no station to open it at', function () {
    $this->records->forceFill(['station_id' => null])->save();

    $this->actingAs($this->records)->post(route('cases.store'), validCase())->assertForbidden();
});

describe('who can see a case', function () {
    beforeEach(function () {
        $this->case = CriminalCase::factory()->atStation($this->station)->create();
        $this->sameDistrict = Station::factory()->create(['district_id' => $this->station->district_id]);
        $this->otherDistrict = Station::factory()->create();
    });

    it('applies the visibility rule for each role and placement', function (Role $role, string $placement, bool $sees) {
        $station = match ($placement) {
            'same station' => $this->station,
            'same district' => $this->sameDistrict,
            'other district' => $this->otherDistrict,
        };

        expect($this->case->isVisibleTo(officer($role, $station)))->toBe($sees);
    })->with([
        [Role::RecordsOfficer, 'same station', true],
        [Role::RecordsOfficer, 'same district', false],
        [Role::StationCommander, 'same station', true],
        [Role::StationCommander, 'same district', false],
        [Role::DistrictSupervisor, 'same district', true],
        [Role::DistrictSupervisor, 'other district', false],
        [Role::NationalOfficer, 'other district', true],
        [Role::InvestigatingOfficer, 'same station', false],
        [Role::Auditor, 'same station', false],
        [Role::SecurityAdministrator, 'same station', false],
        [Role::ApplicationAdministrator, 'same station', false],
        [Role::InfrastructureAdministrator, 'same station', false],
    ]);

    it('shows an investigator only the cases assigned to them, while assigned', function () {
        $investigator = officer(Role::InvestigatingOfficer, $this->station);

        expect($this->case->isVisibleTo($investigator))->toBeFalse();

        $assignment = CaseAssignment::factory()->create(['case_id' => $this->case->id, 'user_id' => $investigator->id]);
        expect($this->case->isVisibleTo($investigator))->toBeTrue();

        $assignment->forceFill(['ended_at' => now()])->save();
        expect($this->case->isVisibleTo($investigator))->toBeFalse();
    });

    it('hides a restricted case from a records officer but not from the commander', function () {
        $this->case->forceFill(['lifecycle' => RecordLifecycle::Restricted])->save();

        expect($this->case->isVisibleTo($this->records))->toBeFalse()
            ->and($this->case->isVisibleTo(officer(Role::StationCommander, $this->station)))->toBeTrue();
    });

    it('lists only what the officer may see', function () {
        CriminalCase::factory()->atStation($this->otherDistrict)->create();

        $this->actingAs($this->records)
            ->get(route('cases.index'))
            ->assertInertia(fn ($page) => $page
                ->component('cases/Index')
                ->has('cases.data', 1)
                ->where('cases.data.0.id', $this->case->id));
    });

    it('answers a case out of reach as not found, and records the attempt', function () {
        $outsider = officer(Role::RecordsOfficer, $this->otherDistrict);

        $this->actingAs($outsider)->get(route('cases.show', $this->case))->assertNotFound();

        expect(AuditEvent::where('event', 'auth.access.denied')->sole())
            ->user_id->toBe($outsider->id)
            ->context->toMatchArray(['route' => 'cases.show']);
    });

    it('refuses every change to a case out of reach', function (string $method, string $route, array $data) {
        $this->actingAs(officer(Role::StationCommander, $this->otherDistrict))
            ->{$method}(route($route, $this->case), $data)
            ->assertNotFound();

        expect($this->case->fresh()->status)->toBe(CaseStatus::Open);
    })->with([
        ['post', 'cases.notes.store', ['note' => 'Not allowed']],
        ['put', 'cases.status.update', ['status' => 'closed_administratively', 'reason' => 'Not allowed']],
        ['put', 'cases.lifecycle.update', ['lifecycle' => 'archived', 'reason' => 'Not allowed']],
        ['post', 'cases.assignments.store', ['user_id' => 1]],
        ['post', 'cases.people.store', ['person_id' => '0193e3a0-0000-7000-8000-000000000000', 'role' => 'witness']],
    ]);

    it('records each case view', function () {
        $this->actingAs($this->records)->get(route('cases.show', $this->case))->assertOk();

        expect(AuditEvent::where('event', 'case.viewed')->sole())->record_id->toBe($this->case->id);
    });
});

describe('working a case', function () {
    beforeEach(function () {
        $this->case = CriminalCase::factory()->atStation($this->station)->create();
        $this->commander = officer(Role::StationCommander, $this->station);
    });

    it('moves the status only along the workflow and keeps the history', function () {
        $this->actingAs($this->commander);

        $this->put(route('cases.status.update', $this->case), ['status' => 'submitted', 'reason' => 'Skipping a step'])
            ->assertSessionHasErrors(['status' => 'A case that is Open cannot move to Submitted.']);

        $this->put(route('cases.status.update', $this->case), ['status' => 'under_investigation', 'reason' => 'Officer assigned'])
            ->assertRedirect();

        expect($this->case->fresh()->status)->toBe(CaseStatus::UnderInvestigation)
            ->and(InvestigationEvent::where('case_id', $this->case->id)->sole())
            ->type->toBe(CaseEventType::StatusChanged)
            ->meta->toBe(['from' => 'open', 'to' => 'under_investigation'])
            ->and(AuditEvent::where('event', 'case.status.changed')->sole()->context['reason'])->toBe('Officer assigned');
    });

    it('sets and clears the closing date with the status', function () {
        $this->actingAs($this->commander);

        $this->put(route('cases.status.update', $this->case), ['status' => 'closed_administratively', 'reason' => 'No further action']);
        expect($this->case->fresh()->closed_at)->not->toBeNull();

        $this->put(route('cases.status.update', $this->case), ['status' => 'under_investigation', 'reason' => 'New information']);
        expect($this->case->fresh()->closed_at)->toBeNull();
    });

    it('needs a reason to change the status', function () {
        $this->actingAs($this->commander)
            ->put(route('cases.status.update', $this->case), ['status' => 'under_investigation'])
            ->assertSessionHasErrors(['reason' => 'The reason field is required.']);
    });

    it('does not let a records officer change the status', function () {
        $this->actingAs($this->records)
            ->put(route('cases.status.update', $this->case), ['status' => 'under_investigation', 'reason' => 'Not allowed'])
            ->assertForbidden();
    });

    it('makes an archived case read-only until its lifecycle changes', function () {
        $this->actingAs($this->commander);

        $this->put(route('cases.lifecycle.update', $this->case), ['lifecycle' => 'archived', 'reason' => 'Retention schedule'])->assertRedirect();

        $this->post(route('cases.notes.store', $this->case), ['note' => 'Too late'])->assertForbidden();

        $this->put(route('cases.lifecycle.update', $this->case), ['lifecycle' => 'active', 'reason' => 'Reopened on review'])->assertRedirect();

        $this->post(route('cases.notes.store', $this->case), ['note' => 'Now allowed'])->assertRedirect();
    });

    it('corrects a case with a reason and keeps the old narrative encrypted', function () {
        $this->actingAs($this->records)->put(route('cases.update', $this->case), [
            'offence_category_id' => $this->case->offence_category_id,
            'incident_date' => $this->case->incident_date->toDateString(),
            'incident_location' => $this->case->incident_location,
            'narrative' => 'The corrected synthetic narrative.',
            'reason' => 'Wrong date in the statement',
            'reference' => 'CHANGED',
            'status' => 'closed_administratively',
        ])->assertRedirect();

        $case = $this->case->fresh();

        expect($case->narrative_ciphertext)->toBe('The corrected synthetic narrative.')
            ->and($case->reference)->toBe($this->case->reference)
            ->and($case->status)->toBe(CaseStatus::Open)
            ->and(RecordCorrection::sole())
            ->field->toBe('narrative_ciphertext')
            ->previous_value_ciphertext->toBe('Synthetic narrative for testing.')
            ->and(DB::table('record_corrections')->sole()->previous_value_ciphertext)->toStartWith('v1:')
            ->and(InvestigationEvent::where('case_id', $case->id)->sole()->type)->toBe(CaseEventType::Corrected);
    });

    it('assigns an investigator in the district, once', function () {
        $investigator = officer(Role::InvestigatingOfficer, $this->station);

        $this->actingAs($this->commander)
            ->post(route('cases.assignments.store', $this->case), ['user_id' => $investigator->id, 'is_lead' => true])
            ->assertRedirect();

        expect(CaseAssignment::sole())
            ->user_id->toBe($investigator->id)
            ->assigned_by->toBe($this->commander->id)
            ->is_lead->toBeTrue()
            ->and($this->case->isVisibleTo($investigator))->toBeTrue();

        $this->post(route('cases.assignments.store', $this->case), ['user_id' => $investigator->id])
            ->assertSessionHasErrors(['user_id' => 'This officer is already assigned to the case.']);
    });

    it('refuses assigning someone from another district or who cannot work cases', function () {
        $elsewhere = officer(Role::InvestigatingOfficer, Station::factory()->create(['district_id' => District::factory()]));
        $notInvestigator = officer(Role::RecordsOfficer, $this->station);
        $this->actingAs($this->commander);

        foreach ([$elsewhere, $notInvestigator] as $officer) {
            $this->post(route('cases.assignments.store', $this->case), ['user_id' => $officer->id])
                ->assertSessionHasErrors(['user_id' => 'Choose an active investigating officer in this case’s district.']);
        }

        expect(CaseAssignment::count())->toBe(0);
    });

    it('ends an assignment, keeps it on record and removes the access', function () {
        $investigator = officer(Role::InvestigatingOfficer, $this->station);
        $assignment = CaseAssignment::factory()->create(['case_id' => $this->case->id, 'user_id' => $investigator->id]);

        $this->actingAs($this->commander)
            ->delete(route('cases.assignments.destroy', [$this->case, $assignment]))
            ->assertRedirect();

        expect($assignment->fresh()->ended_by)->toBe($this->commander->id)
            ->and($this->case->isVisibleTo($investigator))->toBeFalse();
    });

    it('refuses ending an assignment through another case’s address', function () {
        $assignment = CaseAssignment::factory()->create();

        $this->actingAs($this->commander)
            ->delete(route('cases.assignments.destroy', [$this->case, $assignment]))
            ->assertNotFound();
    });

    it('links a person with a role, once per role, and can unlink with a reason', function () {
        $person = Person::factory()->registeredAt($this->station)->create();
        $this->actingAs($this->records);

        $this->post(route('cases.people.store', $this->case), ['person_id' => $person->id, 'role' => 'suspect'])->assertRedirect();

        $this->post(route('cases.people.store', $this->case), ['person_id' => $person->id, 'role' => 'suspect'])
            ->assertSessionHasErrors(['role' => 'This person is already linked to the case as Suspect.']);

        $this->post(route('cases.people.store', $this->case), ['person_id' => $person->id, 'role' => 'witness'])->assertRedirect();

        $link = CasePerson::where('role', CasePersonRole::Suspect)->sole();

        $this->delete(route('cases.people.destroy', [$this->case, $link]), ['reason' => 'Linked by mistake'])->assertRedirect();

        expect($link->fresh())
            ->unlinked_by->toBe($this->records->id)
            ->unlink_reason->toBe('Linked by mistake')
            ->and(CasePerson::count())->toBe(2);
    });

    it('refuses linking a person the officer cannot see', function () {
        $hidden = Person::factory()->registeredAt(Station::factory()->create())->create();

        $this->actingAs($this->records)
            ->post(route('cases.people.store', $this->case), ['person_id' => $hidden->id, 'role' => 'witness'])
            ->assertSessionHasErrors(['person_id' => 'That person could not be found.']);

        expect(CasePerson::count())->toBe(0);
    });

    it('shows the same person with different roles in different cases', function () {
        $person = Person::factory()->registeredAt($this->station)->create();
        $other = CriminalCase::factory()->atStation($this->station)->create();
        CasePerson::factory()->create(['case_id' => $this->case->id, 'person_id' => $person->id, 'role' => CasePersonRole::Victim]);
        CasePerson::factory()->create(['case_id' => $other->id, 'person_id' => $person->id, 'role' => CasePersonRole::Witness]);

        $this->actingAs($this->records)
            ->get(route('persons.show', $person))
            ->assertInertia(fn ($page) => $page->has('caseLinks', 2));
    });

    it('lists only linked cases the officer may see on a person', function () {
        $person = Person::factory()->registeredAt($this->station)->create();
        $hiddenCase = CriminalCase::factory()->atStation(Station::factory()->create())->create();
        CasePerson::factory()->create(['case_id' => $this->case->id, 'person_id' => $person->id]);
        CasePerson::factory()->create(['case_id' => $hiddenCase->id, 'person_id' => $person->id]);

        $this->actingAs($this->records)
            ->get(route('persons.show', $person))
            ->assertInertia(fn ($page) => $page->has('caseLinks', 1)->where('caseLinks.0.case_id', $this->case->id));
    });
});

it('keeps a case’s history append-only', function () {
    $case = CriminalCase::factory()->create();
    $event = new InvestigationEvent;
    $event->forceFill(['case_id' => $case->id, 'user_id' => $case->created_by, 'type' => CaseEventType::Note, 'summary_ciphertext' => 'x', 'occurred_at' => now()])->save();

    expect(fn () => $event->delete())->toThrow(LogicException::class)
        ->and(fn () => $event->forceFill(['summary_ciphertext' => 'y'])->save())->toThrow(LogicException::class);
});

it('manages the offence catalogue', function () {
    $this->actingAs(officer(Role::ApplicationAdministrator));

    $this->post(route('admin.catalogue.store'), ['code' => 'th-1', 'name' => 'Synthetic theft'])->assertRedirect();

    $category = OffenceCategory::where('code', 'TH-1')->sole();

    $this->put(route('admin.catalogue.update', $category), ['name' => 'Synthetic theft', 'is_active' => false])->assertRedirect();

    expect($category->fresh()->is_active)->toBeFalse();

    $this->actingAs($this->records)->post(route('admin.catalogue.store'), ['code' => 'NO', 'name' => 'No'])->assertForbidden();
});
