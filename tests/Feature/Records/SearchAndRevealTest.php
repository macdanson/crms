<?php

use App\Enums\JustificationStatus;
use App\Enums\Role;
use App\Models\AccessJustification;
use App\Models\AuditEvent;
use App\Models\CaseAssignment;
use App\Models\CasePerson;
use App\Models\CriminalCase;
use App\Models\Person;
use App\Models\PersonContact;
use App\Models\PersonIdentity;
use App\Models\Station;
use App\Notifications\AccessRequestDecided;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Notification;

const SEARCH_NIN = 'TEST99887766';

beforeEach(function () {
    $this->station = Station::factory()->create();
    $this->commander = officer(Role::StationCommander, $this->station);
});

describe('searching by identity number', function () {
    beforeEach(function () {
        $this->person = Person::factory()->registeredAt($this->station)->create();
        PersonIdentity::factory()->number(SEARCH_NIN)->create(['person_id' => $this->person->id]);
    });

    it('finds an exact match however the number was typed, showing it masked', function () {
        $response = $this->actingAs($this->commander)->postJson(route('search.people.identity'), [
            'type' => 'national_id',
            'identity_number' => 'test 9988-7766',
            'purpose' => 'assigned_investigation',
        ]);

        $response->assertOk()
            ->assertJsonPath('people.0.id', $this->person->id)
            ->assertJsonPath('people.0.match_reason', 'Exact National ID (NIN) match (TE********66)');

        expect($response->getContent())->not->toContain(SEARCH_NIN);
    });

    it('needs a purpose', function () {
        $this->actingAs($this->commander)
            ->postJson(route('search.people.identity'), ['type' => 'national_id', 'identity_number' => SEARCH_NIN])
            ->assertJsonValidationErrors(['purpose' => 'The purpose field is required.']);
    });

    it('audits the search with its purpose but never the number', function () {
        $this->actingAs($this->commander)->postJson(route('search.people.identity'), [
            'type' => 'national_id',
            'identity_number' => SEARCH_NIN,
            'purpose' => 'assigned_investigation',
        ]);

        $event = AuditEvent::where('event', 'search.person.identity')->sole();

        expect($event->context)->toMatchArray(['purpose' => 'assigned_investigation', 'result_count' => 1])
            ->and($event->context['justification_id'])->toBe(AccessJustification::where('action', 'search_identity')->sole()->id)
            ->and(AuditEvent::all()->toJson())->not->toContain(SEARCH_NIN);
    });

    it('makes a match out of reach look exactly like no match', function () {
        $outsider = officer(Role::StationCommander);

        $search = fn (string $number) => $this->actingAs($outsider)->postJson(route('search.people.identity'), [
            'type' => 'national_id',
            'identity_number' => $number,
            'purpose' => 'assigned_investigation',
        ])->assertOk()->getContent();

        expect($search(SEARCH_NIN))->toBe($search('TESTDOESNOTEXIST'));
    });

    it('does not match the same number under another document type', function () {
        $this->actingAs($this->commander)->postJson(route('search.people.identity'), [
            'type' => 'passport',
            'identity_number' => SEARCH_NIN,
            'purpose' => 'assigned_investigation',
        ])->assertJsonCount(0, 'people');
    });

    it('is refused to roles without the permission', function () {
        $this->actingAs(officer(Role::RecordsOfficer, $this->station))
            ->postJson(route('search.people.identity'), ['type' => 'national_id', 'identity_number' => SEARCH_NIN, 'purpose' => 'assigned_investigation'])
            ->assertForbidden();
    });

    it('is rate limited', function () {
        $this->actingAs($this->commander);

        foreach (range(1, 10) as $attempt) {
            $this->postJson(route('search.people.identity'), ['type' => 'national_id', 'identity_number' => SEARCH_NIN, 'purpose' => 'assigned_investigation'])->assertOk();
        }

        $this->postJson(route('search.people.identity'), ['type' => 'national_id', 'identity_number' => SEARCH_NIN, 'purpose' => 'assigned_investigation'])->assertTooManyRequests();
    });
});

describe('searching by name', function () {
    it('finds only people the officer may see and says it is not proof', function () {
        $visible = Person::factory()->registeredAt($this->station)->create(['given_names' => 'Asiimwe', 'family_name' => 'Testperson']);
        Person::factory()->registeredAt(Station::factory()->create())->create(['given_names' => 'Asiimwe', 'family_name' => 'Hidden']);

        $this->actingAs($this->commander)
            ->postJson(route('search.people.name'), ['name' => 'asiimwe', 'purpose' => 'assigned_investigation'])
            ->assertOk()
            ->assertJsonCount(1, 'people')
            ->assertJsonPath('people.0.id', $visible->id)
            ->assertJsonPath('people.0.match_reason', 'Name match only: this does not prove it is the same person');
    });

    it('treats wildcards in a name as nothing rather than as a match-all', function () {
        Person::factory()->registeredAt($this->station)->create();

        $this->actingAs($this->commander)
            ->postJson(route('search.people.name'), ['name' => '%%', 'purpose' => 'assigned_investigation'])
            ->assertOk()
            ->assertJsonCount(0, 'people');

        $this->postJson(route('search.people.name'), ['name' => "x' OR '1'='1", 'purpose' => 'assigned_investigation'])
            ->assertOk()
            ->assertJsonCount(0, 'people');
    });

    it('audits a keyed hash of the term, not the name', function () {
        $this->actingAs($this->commander)->postJson(route('search.people.name'), ['name' => 'Asiimwe', 'purpose' => 'supervisory_review']);

        expect(AuditEvent::where('event', 'search.person.name')->sole()->context)
            ->toHaveKey('term_hash')
            ->and(AuditEvent::all()->toJson())->not->toContain('siimwe');
    });
});

it('searches cases by reference within the officer’s access', function () {
    $visible = CriminalCase::factory()->atStation($this->station)->create(['reference' => 'CRMS/ST/2026/000123']);
    CriminalCase::factory()->create(['reference' => 'CRMS/OTHER/2026/000123']);

    $this->actingAs($this->commander)
        ->postJson(route('search.cases'), ['reference' => '000123'])
        ->assertJsonCount(1, 'cases')
        ->assertJsonPath('cases.0.id', $visible->id);
});

it('never puts search terms in a URL', function () {
    expect(route('search.people.identity'))->not->toContain('?')
        ->and(app('router')->getRoutes()->getByName('search.people.identity')->methods())->toBe(['POST'])
        ->and(app('router')->getRoutes()->getByName('search.people.name')->methods())->toBe(['POST']);
});

describe('revealing a full value', function () {
    beforeEach(function () {
        $this->investigator = officer(Role::InvestigatingOfficer, $this->station);
        $this->person = Person::factory()->registeredAt($this->station)->create();
        $this->identity = PersonIdentity::factory()->number(SEARCH_NIN)->create(['person_id' => $this->person->id]);

        $case = CriminalCase::factory()->atStation($this->station)->assignedTo($this->investigator)->create();
        CasePerson::factory()->create(['case_id' => $case->id, 'person_id' => $this->person->id]);

        $this->request = [
            'kind' => 'identity',
            'record_id' => $this->identity->id,
            'purpose' => 'assigned_investigation',
            'password' => UserFactory::PASSWORD,
        ];
    });

    it('returns the value and records who, what and why, without the value', function () {
        $this->actingAs($this->investigator)
            ->postJson(route('persons.reveal', $this->person), $this->request)
            ->assertOk()
            ->assertJson(['value' => SEARCH_NIN]);

        $event = AuditEvent::where('event', 'person.identifier.revealed')->sole();

        expect($event)
            ->user_id->toBe($this->investigator->id)
            ->record_id->toBe($this->person->id)
            ->and($event->context)->toMatchArray(['kind' => 'identity', 'record_id' => $this->identity->id, 'purpose' => 'assigned_investigation'])
            ->and(AuditEvent::all()->toJson())->not->toContain(SEARCH_NIN);
    });

    it('needs the correct password', function () {
        $this->actingAs($this->investigator)
            ->postJson(route('persons.reveal', $this->person), [...$this->request, 'password' => 'wrong'])
            ->assertJsonValidationErrors(['password' => 'The password is incorrect.'])
            ->assertJsonMissing(['value' => SEARCH_NIN]);

        expect(AuditEvent::where('event', 'person.identifier.revealed')->count())->toBe(0);
    });

    it('needs a purpose', function () {
        $this->actingAs($this->investigator)
            ->postJson(route('persons.reveal', $this->person), [...$this->request, 'purpose' => ''])
            ->assertJsonValidationErrors('purpose');
    });

    it('refuses a user who can see the person but may not reveal', function () {
        $this->actingAs($this->commander)
            ->postJson(route('persons.reveal', $this->person), $this->request)
            ->assertForbidden();
    });

    it('answers a person out of reach as not found', function () {
        $this->actingAs(officer(Role::NationalOfficer)->fresh());

        $hidden = Person::factory()->create();

        $this->actingAs(officer(Role::InvestigatingOfficer))
            ->postJson(route('persons.reveal', $hidden), $this->request)
            ->assertNotFound();
    });

    it('refuses a record belonging to someone else', function () {
        $other = PersonContact::factory()->create();

        $this->actingAs($this->investigator)
            ->postJson(route('persons.reveal', $this->person), [...$this->request, 'kind' => 'contact', 'record_id' => $other->id])
            ->assertNotFound();
    });

    it('reveals a date of birth the same way', function () {
        $this->actingAs($this->investigator)
            ->postJson(route('persons.reveal', $this->person), [...$this->request, 'kind' => 'date_of_birth', 'record_id' => null])
            ->assertJson(['value' => '1990-01-15']);
    });
});

describe('exceptional access to a case', function () {
    beforeEach(function () {
        $this->case = CriminalCase::factory()->atStation($this->station)->create();
        $this->outsider = officer(Role::InvestigatingOfficer);
        $this->request = AccessJustification::factory()->pendingFor($this->case)->create(['user_id' => $this->outsider->id]);
    });

    it('gives the same answer whether or not the reference exists', function () {
        $ask = fn (string $reference) => $this->actingAs($this->outsider)
            ->post(route('access-requests.store'), ['reference' => $reference, 'purpose' => 'assigned_investigation', 'explanation' => 'Linked to my investigation.'])
            ->assertRedirect()
            ->getSession()->get('status');

        expect($ask($this->case->reference))->toBe($ask('CRMS/DOES/NOT/EXIST'));
    });

    it('grants time-limited access when another officer approves, then ends it', function () {
        Notification::fake();
        config(['crms.access.exceptional_hours' => 2]);

        expect($this->case->isVisibleTo($this->outsider))->toBeFalse();

        $this->actingAs($this->commander)
            ->withSession(confirmedPassword())
            ->post(route('access-requests.decide', $this->request), ['approve' => true, 'note' => 'Confirmed with the investigator'])
            ->assertRedirect();

        expect($this->case->isVisibleTo($this->outsider))->toBeTrue()
            ->and(AuditEvent::where('event', 'access.request.approved')->sole())->user_id->toBe($this->commander->id);

        Notification::assertSentTo($this->outsider, AccessRequestDecided::class);

        $this->actingAs($this->outsider)->get(route('cases.show', $this->case))->assertOk();

        expect(AuditEvent::where('event', 'case.viewed')->sole()->context['exceptional_access'])->toBe($this->request->id);

        $this->travel(3)->hours();

        expect($this->case->isVisibleTo($this->outsider))->toBeFalse();
    });

    it('gives no access when rejected', function () {
        Notification::fake();

        $this->actingAs($this->commander)
            ->withSession(confirmedPassword())
            ->post(route('access-requests.decide', $this->request), ['approve' => false, 'note' => 'Not related to this case'])
            ->assertRedirect();

        expect($this->request->fresh()->status)->toBe(JustificationStatus::Rejected)
            ->and($this->case->isVisibleTo($this->outsider))->toBeFalse();
    });

    it('lets nobody approve their own request', function () {
        $request = AccessJustification::factory()->pendingFor($this->case)->create(['user_id' => $this->commander->id]);

        $this->actingAs($this->commander)
            ->withSession(confirmedPassword())
            ->post(route('access-requests.decide', $request), ['approve' => true, 'note' => 'Approving myself'])
            ->assertNotFound();

        expect($request->fresh()->status)->toBe(JustificationStatus::Pending);
    });

    it('lets only someone who can already see the case decide', function () {
        $this->actingAs(officer(Role::StationCommander))
            ->withSession(confirmedPassword())
            ->post(route('access-requests.decide', $this->request), ['approve' => true, 'note' => 'Not my station'])
            ->assertNotFound();

        expect($this->request->fresh()->status)->toBe(JustificationStatus::Pending);
    });

    it('does not allow deciding twice', function () {
        Notification::fake();
        $this->actingAs($this->commander)->withSession(confirmedPassword());

        $this->post(route('access-requests.decide', $this->request), ['approve' => false, 'note' => 'No, on reflection']);
        $this->post(route('access-requests.decide', $this->request), ['approve' => true, 'note' => 'Yes, on reflection'])
            ->assertSessionHasErrors(['note' => 'This request has already been decided.']);

        expect($this->request->fresh()->status)->toBe(JustificationStatus::Rejected);
    });

    it('shows a decision-maker only requests for cases they can see', function () {
        AccessJustification::factory()->pendingFor(CriminalCase::factory()->create())->create(['user_id' => $this->outsider->id]);

        $this->actingAs($this->commander)
            ->get(route('access-requests.index'))
            ->assertInertia(fn ($page) => $page
                ->component('access/Index')
                ->has('toDecide', 1)
                ->where('toDecide.0.id', $this->request->id));
    });

    it('does not expose a case through a pending request', function () {
        $this->actingAs($this->outsider)
            ->get(route('access-requests.index'))
            ->assertInertia(fn ($page) => $page->where('mine.0.case_id', null)->where('mine.0.status', 'Awaiting decision'));
    });
});

it('ends an officer’s access when their assignment ends, even with a past grant expired', function () {
    $investigator = officer(Role::InvestigatingOfficer, $this->station);
    $case = CriminalCase::factory()->atStation($this->station)->create();
    $assignment = CaseAssignment::factory()->create(['case_id' => $case->id, 'user_id' => $investigator->id]);
    AccessJustification::factory()->pendingFor($case)->create([
        'user_id' => $investigator->id,
        'status' => JustificationStatus::Approved,
        'expires_at' => now()->subMinute(),
    ]);

    $assignment->forceFill(['ended_at' => now()])->save();

    expect($case->isVisibleTo($investigator))->toBeFalse();
});
