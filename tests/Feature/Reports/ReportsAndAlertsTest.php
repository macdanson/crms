<?php

use App\Enums\CaseStatus;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\CriminalCase;
use App\Models\District;
use App\Models\Document;
use App\Models\OffenceCategory;
use App\Models\Person;
use App\Models\PersonIdentity;
use App\Models\SecurityAlert;
use App\Models\Station;
use App\Models\StatisticSnapshot;
use App\Models\User;
use App\Notifications\CaseAssigned;
use App\Notifications\CaseStatusChanged;
use App\Notifications\SecurityAlertRaised;
use App\Services\AuditLogger;
use App\Services\Statistics;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

describe('operational reports', function () {
    it('counts only the cases the officer may see', function () {
        $station = Station::factory()->create(['name' => 'Report Station']);
        CriminalCase::factory()->count(2)->atStation($station)->create();
        CriminalCase::factory()->atStation(Station::factory()->create())->create();

        $this->actingAs(officer(Role::StationCommander, $station))
            ->get(route('reports.index'))
            ->assertInertia(fn ($page) => $page
                ->component('reports/Index')
                ->where('total', 2)
                ->has('byStation', 1)
                ->where('byStation.0.label', 'Report Station')
                ->where('byStation.0.count', 2));

        expect(AuditEvent::where('event', 'report.operational.viewed')->count())->toBe(1);
    });

    it('is refused to roles without the permission', function () {
        $this->actingAs(officer(Role::RecordsOfficer))->get(route('reports.index'))->assertForbidden();
        $this->actingAs(officer(Role::SecurityAdministrator))->get(route('reports.index'))->assertForbidden();
    });
});

describe('de-identified statistics', function () {
    it('holds no column that could identify a person or a case', function () {
        expect(Schema::getColumnListing('statistic_snapshots'))
            ->toEqualCanonicalizing(['id', 'period', 'district_id', 'offence_category_id', 'status', 'case_count', 'generated_at']);
    });

    it('aggregates cases by month, district, category and status', function () {
        $station = Station::factory()->create();
        $category = OffenceCategory::factory()->create();
        CriminalCase::factory()->count(3)->atStation($station)->create(['offence_category_id' => $category->id, 'incident_date' => '2026-03-10']);
        CriminalCase::factory()->atStation($station)->create(['offence_category_id' => $category->id, 'incident_date' => '2026-03-20', 'status' => CaseStatus::Submitted]);

        $this->artisan('statistics:refresh')->assertSuccessful();

        expect(StatisticSnapshot::where('status', 'open')->sole())
            ->case_count->toBe(3)
            ->district_id->toBe($station->district_id)
            ->and(substr((string) StatisticSnapshot::where('status', 'open')->sole()->period, 0, 7))->toBe('2026-03')
            ->and(StatisticSnapshot::count())->toBe(2);
    });

    it('replaces the old figures when refreshed', function () {
        CriminalCase::factory()->create();
        app(Statistics::class)->refresh();

        DB::table('criminal_cases')->delete();
        app(Statistics::class)->refresh();

        expect(StatisticSnapshot::count())->toBe(0);
    });

    it('hides small counts', function () {
        $result = app(Statistics::class)->suppress(collect([
            ['label' => 'Large', 'count' => 40],
            ['label' => 'Also large', 'count' => 12],
            ['label' => 'Tiny', 'count' => 2],
            ['label' => 'Small', 'count' => 4],
        ]));

        expect(collect($result)->pluck('count', 'label')->all())
            ->toBe(['Large' => 40, 'Also large' => 12, 'Tiny' => null, 'Small' => null]);
    });

    it('hides a second count when only one was hidden, so it cannot be subtracted back', function () {
        $result = app(Statistics::class)->suppress(collect([
            ['label' => 'Large', 'count' => 40],
            ['label' => 'Next smallest', 'count' => 9],
            ['label' => 'Tiny', 'count' => 1],
        ]));

        expect(collect($result)->pluck('count', 'label')->all())
            ->toBe(['Large' => 40, 'Next smallest' => null, 'Tiny' => null]);
    });

    it('never sends a small count to the browser', function () {
        $district = District::factory()->create(['name' => 'Small District']);
        $station = Station::factory()->create(['district_id' => $district->id]);
        CriminalCase::factory()->count(2)->atStation($station)->create();
        CriminalCase::factory()->count(9)->atStation(Station::factory()->create())->create();
        CriminalCase::factory()->count(9)->atStation(Station::factory()->create())->create();
        app(Statistics::class)->refresh();

        $this->actingAs(officer(Role::Auditor))
            ->get(route('reports.statistics'))
            ->assertInertia(fn ($page) => $page
                ->component('reports/Statistics')
                ->where('by_district', fn ($rows) => collect($rows)->firstWhere('label', 'Small District')['count'] === null));

        expect(AuditEvent::where('event', 'report.statistics.viewed')->count())->toBe(1);
    });

    it('is refused to roles without the permission', function () {
        $this->actingAs(officer(Role::RecordsOfficer))->get(route('reports.statistics'))->assertForbidden();
    });
});

describe('notifications', function () {
    it('tells an officer about an assignment with generic text only', function () {
        Notification::fake();
        $station = Station::factory()->create();
        $case = CriminalCase::factory()->atStation($station)->create();
        $investigator = officer(Role::InvestigatingOfficer, $station);

        $this->actingAs(officer(Role::StationCommander, $station))
            ->post(route('cases.assignments.store', $case), ['user_id' => $investigator->id]);

        Notification::assertSentTo($investigator, CaseAssigned::class, function (CaseAssigned $notification) use ($investigator, $case): bool {
            $mail = $notification->toMail($investigator)->render();
            $array = json_encode($notification->toArray($investigator));

            return ! str_contains((string) $mail, $case->reference)
                && ! str_contains((string) $mail, 'Synthetic narrative')
                && ! str_contains((string) $array, $case->reference)
                && str_contains((string) $mail, 'Details are only available after you sign in');
        });
    });

    it('tells assigned officers about a status change, but not whoever made it', function () {
        Notification::fake();
        $station = Station::factory()->create();
        $commander = officer(Role::StationCommander, $station);
        $investigator = officer(Role::InvestigatingOfficer, $station);
        $case = CriminalCase::factory()->atStation($station)->assignedTo($investigator)->assignedTo($commander)->create();

        $this->actingAs($commander)->put(route('cases.status.update', $case), ['status' => 'under_investigation', 'reason' => 'Work has started']);

        Notification::assertSentTo($investigator, CaseStatusChanged::class);
        Notification::assertNotSentTo($commander, CaseStatusChanged::class);
    });

    it('shows a user only their own notifications and opens them through a fresh check', function () {
        $station = Station::factory()->create();
        $mine = officer(Role::InvestigatingOfficer, $station);
        $other = officer(Role::InvestigatingOfficer, $station);
        $case = CriminalCase::factory()->atStation($station)->create();

        $mine->notify(new CaseAssigned($case->id));
        $other->notify(new CaseAssigned($case->id));

        $this->actingAs($mine)
            ->get(route('notifications.index'))
            ->assertInertia(fn ($page) => $page->component('notifications/Index')->has('notifications.data', 1)->where('auth.user.unread_notifications', 1));

        $this->get(route('notifications.open', $other->notifications()->sole()->id))->assertNotFound();

        $this->get(route('notifications.open', $mine->notifications()->sole()->id))
            ->assertRedirect(route('cases.show', $case));

        expect($mine->fresh()->unreadNotifications()->count())->toBe(0);

        // The link does not get around the case's own check.
        $this->get(route('cases.show', $case))->assertNotFound();
    });
});

describe('security alerts', function () {
    it('raises one alert when a user crosses a threshold, and tells security staff', function () {
        Notification::fake();
        config(['crms.alerts.rules.identifier_reveals.threshold' => 3]);

        $subject = User::factory()->create();
        $staff = officer(Role::SecurityAdministrator);

        foreach (range(1, 3) as $reveal) {
            app(AuditLogger::class)->record('person.identifier.revealed', user: $subject);
        }

        $this->artisan('alerts:scan')->assertSuccessful();
        $this->artisan('alerts:scan')->assertSuccessful();

        expect(SecurityAlert::where('rule', 'identifier_reveals')->sole())
            ->user_id->toBe($subject->id)
            ->event_count->toBe(3)
            ->severity->toBe('high');

        Notification::assertSentToTimes($staff, SecurityAlertRaised::class, 1);
    });

    it('stays quiet below the threshold', function () {
        config(['crms.alerts.rules.identifier_reveals.threshold' => 3]);

        app(AuditLogger::class)->record('person.identifier.revealed', user: User::factory()->create());

        $this->artisan('alerts:scan')->assertSuccessful();

        expect(SecurityAlert::count())->toBe(0);
    });

    it('raises a critical alert when the audit trail fails verification', function () {
        Notification::fake();

        $event = app(AuditLogger::class)->record('test.event', context: ['a' => 1]);
        DB::table('audit_events')->where('id', $event->id)->update(['event' => 'changed.event']);

        $this->artisan('audit:verify')->assertFailed();
        $this->artisan('alerts:scan')->assertSuccessful();

        expect(SecurityAlert::where('rule', 'audit_integrity')->sole())
            ->severity->toBe('critical')
            ->user_id->toBeNull();
    });

    it('sends an alert with no detail about who or what', function () {
        $notification = new SecurityAlertRaised;
        $staff = User::factory()->create();

        expect($notification->toArray($staff)['title'])->toBe('A new security alert needs review');
    });

    it('lets security staff review an alert, with a note on record', function () {
        $staff = officer(Role::SecurityAdministrator);
        $alert = SecurityAlert::factory()->create();

        $this->actingAs($staff)
            ->get(route('admin.alerts.index'))
            ->assertInertia(fn ($page) => $page->component('admin/alerts/Index')->has('alerts.data', 1));

        $this->put(route('admin.alerts.review', $alert), ['note' => 'Confirmed with the supervisor'])->assertRedirect();

        expect($alert->fresh())
            ->status->toBe(SecurityAlert::STATUS_REVIEWED)
            ->reviewed_by->toBe($staff->id);
    });

    it('is refused to other roles', function () {
        $this->actingAs(officer(Role::ApplicationAdministrator))->get(route('admin.alerts.index'))->assertForbidden();
    });
});

describe('verifying a restored backup', function () {
    beforeEach(function () {
        Storage::fake('documents');
    });

    it('passes when the data can be read', function () {
        $person = Person::factory()->create();
        PersonIdentity::factory()->create(['person_id' => $person->id]);
        Document::factory()->create();

        $this->artisan('backup:verify-restore')->assertSuccessful();

        expect(AuditEvent::where('event', 'backup.restore_verified')->sole()->context['failures'])->toBe([]);
    });

    it('fails when the data keys cannot read the records', function () {
        Person::factory()->create();

        config(['crms.data_protection.keys' => '2:'.base64_encode(random_bytes(32))]);

        $this->artisan('backup:verify-restore')
            ->expectsOutputToContain('dates of birth could not be decrypted')
            ->assertFailed();
    });

    it('fails when a document is missing or changed', function () {
        $document = Document::factory()->create();

        Storage::disk('documents')->delete($document->storage_key);

        $this->artisan('backup:verify-restore')
            ->expectsOutputToContain('documents are missing, unreadable or changed')
            ->assertFailed();
    });

    it('fails when the audit trail was altered', function () {
        $event = app(AuditLogger::class)->record('test.event');
        DB::table('audit_events')->where('id', $event->id)->update(['event' => 'changed.event']);

        $this->artisan('backup:verify-restore')->assertFailed();
    });
});

describe('the dashboard', function () {
    it('shows an officer counts from their own reach', function () {
        $station = Station::factory()->create();
        CriminalCase::factory()->count(2)->atStation($station)->create();
        CriminalCase::factory()->create();

        $this->actingAs(officer(Role::RecordsOfficer, $station))
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('workload.open_cases', 2)->where('workload.assigned_to_me', null));
    });

    it('shows a technical administrator no operational numbers', function () {
        CriminalCase::factory()->create();

        $this->actingAs(officer(Role::SecurityAdministrator))
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('workload.open_cases', null));
    });
});
