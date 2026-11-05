<?php

use App\Enums\CourtOutcomeType;
use App\Enums\OutcomeVerification;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\CasePerson;
use App\Models\Conviction;
use App\Models\CourtOutcome;
use App\Models\CourtProceeding;
use App\Models\CriminalCase;
use App\Models\Station;

beforeEach(function () {
    $this->station = Station::factory()->create();
    $this->case = CriminalCase::factory()->atStation($this->station)->create();
    $this->link = CasePerson::factory()->create(['case_id' => $this->case->id]);
    $this->proceeding = CourtProceeding::factory()->create(['case_id' => $this->case->id]);

    $this->recorder = officer(Role::InvestigatingOfficer, $this->station);
    $this->case->assignments()->forceCreate([
        'user_id' => $this->recorder->id,
        'assigned_by' => $this->recorder->id,
        'assigned_at' => now(),
    ]);

    $this->verifier = officer(Role::DistrictSupervisor, $this->station);
});

function recordOutcome(CourtProceeding $proceeding, CasePerson $link, CourtOutcomeType $type): CourtOutcome
{
    return CourtOutcome::factory()->create([
        'court_proceeding_id' => $proceeding->id,
        'case_person_id' => $link->id,
        'outcome' => $type,
    ]);
}

it('records a proceeding and an outcome that has no effect yet', function () {
    $this->actingAs($this->recorder);

    $this->post(route('cases.court-proceedings.store', $this->case), [
        'court_name' => 'Synthetic Court',
        'court_reference' => 'SC-1',
        'status' => 'filed',
    ])->assertRedirect();

    $this->post(route('court-proceedings.outcomes.store', $this->proceeding), [
        'case_person_id' => $this->link->id,
        'outcome' => 'convicted',
        'outcome_date' => '2026-10-01',
        'source' => 'Certified court record',
    ])->assertRedirect();

    expect(CourtOutcome::sole())
        ->verification_status->toBe(OutcomeVerification::Pending)
        ->recorded_by->toBe($this->recorder->id)
        ->and(Conviction::count())->toBe(0);
});

it('needs a named source for an outcome', function () {
    $this->actingAs($this->recorder)->post(route('court-proceedings.outcomes.store', $this->proceeding), [
        'case_person_id' => $this->link->id,
        'outcome' => 'convicted',
        'outcome_date' => '2026-10-01',
    ])->assertSessionHasErrors(['source' => 'The source field is required.']);
});

it('refuses an outcome for a person not linked to the case', function () {
    $other = CasePerson::factory()->create();

    $this->actingAs($this->recorder)->post(route('court-proceedings.outcomes.store', $this->proceeding), [
        'case_person_id' => $other->id,
        'outcome' => 'convicted',
        'outcome_date' => '2026-10-01',
        'source' => 'Certified court record',
    ])->assertNotFound();
});

it('creates a conviction only when a different officer verifies a conviction outcome', function () {
    $outcome = recordOutcome($this->proceeding, $this->link, CourtOutcomeType::Convicted);

    $this->actingAs($this->verifier)
        ->withSession(confirmedPassword())
        ->put(route('court-outcomes.verification', $outcome), ['confirmed' => true, 'note' => 'Checked the court record'])
        ->assertRedirect();

    expect($outcome->fresh())
        ->verification_status->toBe(OutcomeVerification::Verified)
        ->verified_by->toBe($this->verifier->id)
        ->and(Conviction::sole())
        ->court_outcome_id->toBe($outcome->id)
        ->person_id->toBe($this->link->person_id)
        ->overturned_at->toBeNull();
});

it('lets nobody verify an outcome they recorded', function () {
    $outcome = recordOutcome($this->proceeding, $this->link, CourtOutcomeType::Convicted);
    $outcome->forceFill(['recorded_by' => $this->verifier->id])->save();

    $this->actingAs($this->verifier)
        ->withSession(confirmedPassword())
        ->put(route('court-outcomes.verification', $outcome), ['confirmed' => true, 'note' => 'Verifying my own'])
        ->assertSessionHasErrors(['note' => 'A different officer must verify an outcome you recorded.']);

    expect(Conviction::count())->toBe(0);
});

it('creates nothing when a conviction outcome is rejected', function () {
    $outcome = recordOutcome($this->proceeding, $this->link, CourtOutcomeType::Convicted);

    $this->actingAs($this->verifier)
        ->withSession(confirmedPassword())
        ->put(route('court-outcomes.verification', $outcome), ['confirmed' => false, 'note' => 'No such record at the court'])
        ->assertRedirect();

    expect($outcome->fresh()->verification_status)->toBe(OutcomeVerification::Rejected)
        ->and(Conviction::count())->toBe(0);
});

it('never turns an arrest, charge or closed case into a conviction', function () {
    $this->actingAs($this->verifier)->put(route('cases.status.update', $this->case), [
        'status' => 'closed_administratively',
        'reason' => 'Closed for synthetic reasons',
    ])->assertRedirect();

    expect(Conviction::count())->toBe(0)
        ->and(CourtOutcome::count())->toBe(0);
});

it('does not verify an outcome twice', function () {
    $outcome = recordOutcome($this->proceeding, $this->link, CourtOutcomeType::Convicted);
    $this->actingAs($this->verifier)->withSession(confirmedPassword());

    $this->put(route('court-outcomes.verification', $outcome), ['confirmed' => true, 'note' => 'Checked the record']);
    $this->put(route('court-outcomes.verification', $outcome), ['confirmed' => true, 'note' => 'Checked it again'])
        ->assertSessionHasErrors(['note' => 'This outcome has already been reviewed.']);

    expect(Conviction::count())->toBe(1);
});

it('keeps the history when a conviction is overturned', function () {
    $convicted = recordOutcome($this->proceeding, $this->link, CourtOutcomeType::Convicted);
    $overturned = recordOutcome($this->proceeding, $this->link, CourtOutcomeType::ConvictionOverturned);
    $this->actingAs($this->verifier)->withSession(confirmedPassword());

    $this->put(route('court-outcomes.verification', $convicted), ['confirmed' => true, 'note' => 'Checked the record']);
    $this->put(route('court-outcomes.verification', $overturned), ['confirmed' => true, 'note' => 'Appeal judgment seen'])->assertRedirect();

    expect(Conviction::sole())
        ->court_outcome_id->toBe($convicted->id)
        ->overturned_by_outcome_id->toBe($overturned->id)
        ->overturned_at->not->toBeNull()
        ->and($overturned->fresh()->supersedes_id)->toBe($convicted->id)
        ->and($convicted->fresh()->verification_status)->toBe(OutcomeVerification::Verified);
});

it('refuses overturning when there is no conviction, changing nothing', function () {
    $overturned = recordOutcome($this->proceeding, $this->link, CourtOutcomeType::ConvictionOverturned);

    $this->actingAs($this->verifier)
        ->withSession(confirmedPassword())
        ->put(route('court-outcomes.verification', $overturned), ['confirmed' => true, 'note' => 'Nothing to overturn'])
        ->assertSessionHasErrors(['note' => 'There is no standing conviction for this person to overturn.']);

    expect($overturned->fresh()->verification_status)->toBe(OutcomeVerification::Pending);
});

it('audits every outcome change', function () {
    $outcome = recordOutcome($this->proceeding, $this->link, CourtOutcomeType::Acquitted);

    $this->actingAs($this->verifier)
        ->withSession(confirmedPassword())
        ->put(route('court-outcomes.verification', $outcome), ['confirmed' => true, 'note' => 'Checked the record']);

    expect(AuditEvent::where('event', 'court.outcome.verified')->sole())
        ->user_id->toBe($this->verifier->id)
        ->and(AuditEvent::where('event', 'court.outcome.verified')->sole()->context)->toMatchArray(['outcome' => 'acquitted']);
});

it('refuses recording and verifying to roles without the permission or out of reach', function () {
    $outcome = recordOutcome($this->proceeding, $this->link, CourtOutcomeType::Convicted);

    $this->actingAs(officer(Role::RecordsOfficer, $this->station))
        ->post(route('cases.court-proceedings.store', $this->case), ['court_name' => 'X', 'court_reference' => 'X', 'status' => 'filed'])
        ->assertForbidden();

    $this->actingAs($this->recorder)
        ->withSession(confirmedPassword())
        ->put(route('court-outcomes.verification', $outcome), ['confirmed' => true, 'note' => 'Not allowed'])
        ->assertForbidden();

    $this->actingAs(officer(Role::DistrictSupervisor))
        ->withSession(confirmedPassword())
        ->put(route('court-outcomes.verification', $outcome), ['confirmed' => true, 'note' => 'Other district'])
        ->assertNotFound();
});
