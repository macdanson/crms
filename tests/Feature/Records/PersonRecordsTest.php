<?php

use App\Enums\DuplicateStatus;
use App\Enums\IdentityType;
use App\Enums\Role;
use App\Enums\VerificationStatus;
use App\Models\AuditEvent;
use App\Models\CasePerson;
use App\Models\CriminalCase;
use App\Models\DuplicateCandidate;
use App\Models\Person;
use App\Models\PersonIdentity;
use App\Models\RecordCorrection;
use App\Models\Station;
use App\Services\DataProtection;
use App\Services\DuplicateDetector;
use Illuminate\Support\Facades\DB;

const SYNTHETIC_NIN = 'TEST-1234 5678';

function validPerson(array $overrides = []): array
{
    return [
        'given_names' => 'Synthetic',
        'family_name' => 'Testperson',
        'date_of_birth' => '1990-05-01',
        ...$overrides,
    ];
}

beforeEach(function () {
    $this->station = Station::factory()->create();
    $this->records = officer(Role::RecordsOfficer, $this->station);
});

it('registers a person at the officer’s own station', function () {
    $otherStation = Station::factory()->create();

    $this->actingAs($this->records)
        ->post(route('persons.store'), validPerson(['registered_station_id' => $otherStation->id]))
        ->assertRedirect();

    expect(Person::sole())
        ->registered_station_id->toBe($this->station->id)
        ->registered_district_id->toBe($this->station->district_id)
        ->birth_year->toBe(1990)
        ->search_name->toBe('testperson synthetic')
        ->and(AuditEvent::where('event', 'person.created')->sole()->user_id)->toBe($this->records->id);
});

it('keeps an identity number encrypted, with a keyed lookup token and a masked form', function () {
    $this->actingAs($this->records)->post(route('persons.store'), validPerson([
        'identity_type' => IdentityType::NationalId->value,
        'identity_number' => SYNTHETIC_NIN,
    ]))->assertRedirect();

    $row = DB::table('person_identities')->sole();
    $identity = PersonIdentity::sole();

    expect($row->identifier_ciphertext)->toStartWith('v1:')
        ->not->toContain('TEST')
        ->and($identity->identifier_ciphertext)->toBe('TEST12345678')
        ->and($row->identifier_lookup_hmac)->toBe(app(DataProtection::class)->lookupToken('national_id', 'test12345678'))
        ->and($row->identifier_lookup_hmac)->not->toBe(hash('sha256', 'TEST12345678'))
        ->and($identity->identifier_masked)->toBe('TE********78')
        ->and($identity->verification_status)->toBe(VerificationStatus::Unverified);
});

it('never sends the full identifier, contact or lookup token to the browser', function () {
    $person = Person::factory()->registeredAt($this->station)->create();
    PersonIdentity::factory()->number(SYNTHETIC_NIN)->create(['person_id' => $person->id]);

    $response = $this->actingAs($this->records)->get(route('persons.show', $person));

    $response->assertOk()->assertInertia(fn ($page) => $page->component('persons/Show')->where('identities.0.masked', 'TE********78'));

    expect($response->getContent())
        ->not->toContain('TEST12345678')
        ->not->toContain(PersonIdentity::sole()->identifier_lookup_hmac)
        ->not->toContain('1990-01-15');
});

it('keeps the identifier out of the audit trail', function () {
    $this->actingAs($this->records)->post(route('persons.store'), validPerson([
        'identity_type' => IdentityType::NationalId->value,
        'identity_number' => SYNTHETIC_NIN,
    ]));

    expect(AuditEvent::all()->toJson())->not->toContain('12345678');
});

it('does not keep a refused identity number in the session', function () {
    $this->actingAs($this->records)
        ->from(route('persons.create'))
        ->post(route('persons.store'), ['identity_type' => 'national_id', 'identity_number' => 'bad!!number'])
        ->assertSessionHasErrors('identity_number')
        ->assertSessionMissing('_old_input.identity_number');
});

it('corrects a person with a reason and keeps what it said before', function () {
    $person = Person::factory()->registeredAt($this->station)->create(['occupation' => 'Teacher']);

    $this->actingAs($this->records)->put(route('persons.update', $person), [
        'given_names' => $person->given_names,
        'family_name' => $person->family_name,
        'occupation' => 'Farmer',
        'reason' => 'Typing error at registration',
    ])->assertRedirect();

    expect($person->fresh()->occupation)->toBe('Farmer')
        ->and(RecordCorrection::sole())
        ->field->toBe('occupation')
        ->previous_value_ciphertext->toBe('Teacher')
        ->new_value_ciphertext->toBe('Farmer')
        ->reason->toBe('Typing error at registration')
        ->corrected_by->toBe($this->records->id)
        ->and(AuditEvent::where('event', 'record.corrected')->sole()->context)
        ->toBe(['fields' => ['occupation'], 'reason' => 'Typing error at registration']);
});

it('refuses a correction without a reason', function () {
    $person = Person::factory()->registeredAt($this->station)->create();

    $this->actingAs($this->records)
        ->put(route('persons.update', $person), ['given_names' => 'New', 'family_name' => 'Name'])
        ->assertSessionHasErrors(['reason' => 'The reason field is required.']);

    expect(RecordCorrection::count())->toBe(0);
});

it('hides a person from an officer with no link to them, as not found', function () {
    $elsewhere = Person::factory()->registeredAt(Station::factory()->create())->create();

    $this->actingAs($this->records)->get(route('persons.show', $elsewhere))->assertNotFound();

    expect(AuditEvent::where('event', 'auth.access.denied')->count())->toBe(1);
});

it('shows a person to an investigator only through an assigned case', function () {
    $investigator = officer(Role::InvestigatingOfficer, $this->station);
    $person = Person::factory()->registeredAt($this->station)->create();

    $this->actingAs($investigator)->get(route('persons.show', $person))->assertNotFound();

    $case = CriminalCase::factory()->atStation($this->station)->assignedTo($investigator)->create();
    CasePerson::factory()->create(['case_id' => $case->id, 'person_id' => $person->id]);

    $this->actingAs($investigator)->get(route('persons.show', $person))->assertOk();
});

it('records each person view', function () {
    $person = Person::factory()->registeredAt($this->station)->create();

    $this->actingAs($this->records)->get(route('persons.show', $person));

    expect(AuditEvent::where('event', 'person.viewed')->sole())->record_id->toBe($person->id);
});

it('needs a named source to mark an identity verified, and records who did', function () {
    $reviewer = officer(Role::RecordsQualityReviewer, $this->station);
    $person = Person::factory()->registeredAt($this->station)->create();
    $identity = PersonIdentity::factory()->create(['person_id' => $person->id]);

    $this->actingAs($reviewer)
        ->put(route('persons.identities.verification', [$person, $identity]), ['status' => 'verified'])
        ->assertSessionHasErrors(['source' => 'Say which authorised source confirmed this identity.']);

    expect($identity->fresh()->verification_status)->toBe(VerificationStatus::Unverified);

    $this->put(route('persons.identities.verification', [$person, $identity]), ['status' => 'verified', 'source' => 'Manual document check'])
        ->assertRedirect();

    expect($identity->fresh())
        ->verification_status->toBe(VerificationStatus::Verified)
        ->verified_by->toBe($reviewer->id);
});

it('does not let a records officer verify an identity', function () {
    $person = Person::factory()->registeredAt($this->station)->create();
    $identity = PersonIdentity::factory()->create(['person_id' => $person->id]);

    $this->actingAs($this->records)
        ->put(route('persons.identities.verification', [$person, $identity]), ['status' => 'verified', 'source' => 'Myself'])
        ->assertForbidden();
});

it('refuses verifying an identity through another person’s address', function () {
    $reviewer = officer(Role::RecordsQualityReviewer, $this->station);
    $person = Person::factory()->registeredAt($this->station)->create();
    $identity = PersonIdentity::factory()->create();

    $this->actingAs($reviewer)
        ->put(route('persons.identities.verification', [$person, $identity]), ['status' => 'failed'])
        ->assertNotFound();
});

it('adds masked contact details and addresses', function () {
    $person = Person::factory()->registeredAt($this->station)->create();

    $this->actingAs($this->records);

    $this->post(route('persons.contacts.store', $person), ['type' => 'phone', 'value' => '+000 712 345 483'])->assertRedirect();
    $this->post(route('persons.contacts.store', $person), ['type' => 'email', 'value' => 'not-an-email'])->assertSessionHasErrors('value');
    $this->post(route('persons.addresses.store', $person), ['type' => 'home', 'address' => '12 Synthetic Road'])->assertRedirect();

    $this->get(route('persons.show', $person))->assertInertia(fn ($page) => $page
        ->where('contacts.0.masked', '+*** *** *** 483')
        ->where('addresses.0.masked', '12 *****'));

    expect(DB::table('person_contacts')->sole()->value_ciphertext)->not->toContain('712');
});

it('raises a possible duplicate on a shared identity number, without merging', function () {
    PersonIdentity::factory()->number(SYNTHETIC_NIN)->create([
        'person_id' => Person::factory()->create(['date_of_birth_ciphertext' => null, 'birth_year' => null])->id,
    ]);

    $this->actingAs($this->records)->post(route('persons.store'), validPerson([
        'identity_type' => 'national_id',
        'identity_number' => 'test12345678',
    ]))->assertRedirect();

    expect(DuplicateCandidate::sole())
        ->reason->toBe(DuplicateCandidate::REASON_IDENTITY)
        ->confidence->toBe(100)
        ->status->toBe(DuplicateStatus::Pending)
        ->and(Person::whereNotNull('merged_into_id')->count())->toBe(0);
});

it('treats the same name and birth year as a lower confidence possible match', function () {
    Person::factory()->create(['given_names' => 'Synthetic', 'family_name' => 'Testperson', 'birth_year' => 1990]);

    $this->actingAs($this->records)->post(route('persons.store'), validPerson());

    expect(DuplicateCandidate::sole())
        ->reason->toBe(DuplicateCandidate::REASON_NAME_AND_YEAR)
        ->confidence->toBe(70);
});

it('does not tell the officer registering about a match', function () {
    Person::factory()->create(['given_names' => 'Synthetic', 'family_name' => 'Testperson', 'birth_year' => 1990]);

    $response = $this->actingAs($this->records)->post(route('persons.store'), validPerson());

    $response->assertSessionHas('status', 'Person registered.');
});

describe('duplicate review', function () {
    beforeEach(function () {
        $this->reviewer = officer(Role::RecordsQualityReviewer, $this->station);
        $this->first = Person::factory()->registeredAt($this->station)->create();
        $this->second = Person::factory()->registeredAt($this->station)->create();
        $this->candidate = DuplicateCandidate::factory()->create([
            'first_person_id' => $this->first->id,
            'second_person_id' => $this->second->id,
        ]);
    });

    it('merges into the chosen record, keeping the source and what moved', function () {
        $identity = PersonIdentity::factory()->create(['person_id' => $this->second->id]);
        $link = CasePerson::factory()->create(['person_id' => $this->second->id]);

        $this->actingAs($this->reviewer)
            ->withSession(confirmedPassword())
            ->post(route('persons.duplicates.merge', $this->candidate), [
                'target_person_id' => $this->first->id,
                'reason' => 'Same document seen in person',
            ])
            ->assertRedirect();

        expect($this->second->fresh()->merged_into_id)->toBe($this->first->id)
            ->and($identity->fresh()->person_id)->toBe($this->first->id)
            ->and($link->fresh()->person_id)->toBe($this->first->id)
            ->and($this->candidate->fresh()->status)->toBe(DuplicateStatus::Merged)
            ->and(DB::table('person_merges')->sole()->source_person_id)->toBe($this->second->id)
            ->and(json_decode(DB::table('person_merges')->sole()->moved_records, true)['identities'])->toBe([$identity->id]);
    });

    it('needs a recent password confirmation to merge', function () {
        $this->actingAs($this->reviewer)
            ->post(route('persons.duplicates.merge', $this->candidate), ['target_person_id' => $this->first->id, 'reason' => 'Same person'])
            ->assertRedirect(route('password.confirm'));

        expect($this->second->fresh()->merged_into_id)->toBeNull();
    });

    it('refuses merging into a person outside the pair', function () {
        $this->actingAs($this->reviewer)
            ->withSession(confirmedPassword())
            ->post(route('persons.duplicates.merge', $this->candidate), ['target_person_id' => Person::factory()->create()->id, 'reason' => 'Wrong one'])
            ->assertSessionHasErrors('target_person_id');
    });

    it('refuses merging a pair twice', function () {
        $this->candidate->forceFill(['status' => DuplicateStatus::Dismissed])->save();

        $this->actingAs($this->reviewer)
            ->withSession(confirmedPassword())
            ->post(route('persons.duplicates.merge', $this->candidate), ['target_person_id' => $this->first->id, 'reason' => 'Too late now'])
            ->assertSessionHasErrors(['reason' => 'This match has already been reviewed.']);
    });

    it('does not show or allow a pair when one record is out of reach', function () {
        $outOfReach = Person::factory()->registeredAt(Station::factory()->create())->create();
        $hidden = DuplicateCandidate::factory()->create(['first_person_id' => $this->first->id, 'second_person_id' => $outOfReach->id]);

        $this->actingAs($this->reviewer)
            ->get(route('persons.duplicates.index'))
            ->assertInertia(fn ($page) => $page->has('candidates.data', 1)->where('candidates.data.0.id', $this->candidate->id));

        $this->post(route('persons.duplicates.dismiss', $hidden), ['reason' => 'Not allowed'])->assertNotFound();
    });

    it('does not raise a dismissed pair again', function () {
        $this->actingAs($this->reviewer)
            ->post(route('persons.duplicates.dismiss', $this->candidate), ['reason' => 'Different people, checked'])
            ->assertRedirect();

        DuplicateCandidate::query()->update(['reason' => DuplicateCandidate::REASON_NAME_AND_YEAR]);

        app(DuplicateDetector::class)->check($this->first);

        expect(DuplicateCandidate::sole()->status)->toBe(DuplicateStatus::Dismissed);
    });

    it('refuses the review to officers without the permission', function () {
        $this->actingAs($this->records)->get(route('persons.duplicates.index'))->assertForbidden();
        $this->post(route('persons.duplicates.dismiss', $this->candidate), ['reason' => 'Not allowed'])->assertForbidden();
    });
});
