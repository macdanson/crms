<?php

use App\Enums\AuditOutcome;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Station;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

it('copies where the actor works and which record was acted on', function () {
    $station = Station::factory()->create();
    $user = User::factory()->atStation($station)->create();

    $this->actingAs($user);

    $event = app(AuditLogger::class)->record('test.event', AuditOutcome::Success, $user, record: $station);

    expect($event->fresh())
        ->actor_station_id->toBe($station->id)
        ->actor_district_id->toBe($station->district_id)
        ->record_type->toBe(Station::class)
        ->record_id->toBe((string) $station->id);
});

it('strips identifiers and secrets from the context', function () {
    $event = app(AuditLogger::class)->record('test.event', context: [
        'identifier' => 'CM12345678',
        'NIN' => 'CM12345678',
        'password' => 'secret',
        'kept' => 'value',
    ]);

    expect($event->fresh()->context)->toBe(['kept' => 'value']);
});

it('passes verification when nothing has changed', function () {
    app(AuditLogger::class)->record('test.event', context: ['b' => 1, 'a' => ['y' => 1, 'x' => 2]]);

    $this->artisan('audit:verify')->assertSuccessful();
});

it('finds an event altered directly in the database', function () {
    $event = app(AuditLogger::class)->record('test.event', context: ['actor_id' => 1]);

    DB::table('audit_events')->where('id', $event->id)->update(['context' => json_encode(['actor_id' => 2])]);

    $this->artisan('audit:verify')
        ->expectsOutputToContain($event->id)
        ->assertFailed();

    expect(AuditEvent::where('event', 'audit.verification.completed')->sole()->outcome)->toBe(AuditOutcome::Failure);
});

it('refuses to verify with no seal key rather than reporting success', function () {
    config(['crms.audit.seal_key' => null]);

    $this->artisan('audit:verify')->assertFailed();
});

it('leaves events unsealed when no key is configured', function () {
    config(['crms.audit.seal_key' => null]);

    expect(app(AuditLogger::class)->record('test.event')->fresh()->seal)->toBeNull();
});

it('still refuses to change or remove an audit event through the model', function () {
    $event = app(AuditLogger::class)->record('test.event');

    expect(fn () => $event->update(['event' => 'other']))->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class);
});

it('shows the audit log to an auditor and records that it was read', function () {
    $auditor = officer(Role::Auditor);
    app(AuditLogger::class)->record('test.findable');
    app(AuditLogger::class)->record('other.event');

    $this->actingAs($auditor)
        ->get(route('admin.audit.index', ['event' => 'test.']))
        ->assertInertia(fn ($page) => $page->component('admin/audit/Index')
            ->has('events.data', 1)
            ->where('events.data.0.event', 'test.findable'));

    expect(AuditEvent::where('event', 'audit.reviewed')->sole())
        ->user_id->toBe($auditor->id)
        ->context->toBe(['filters' => ['event' => 'test.']]);
});

it('records a refused request', function () {
    $user = officer(Role::RecordsOfficer);

    $this->actingAs($user)->get(route('admin.audit.index'))->assertForbidden();

    expect(AuditEvent::where('event', 'auth.access.denied')->sole())
        ->user_id->toBe($user->id)
        ->context->toMatchArray(['route' => 'admin.audit.index']);
});
