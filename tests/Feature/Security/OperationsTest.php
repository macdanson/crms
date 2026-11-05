<?php

use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\CriminalCase;
use App\Models\Document;
use App\Models\InvestigationEvent;
use App\Models\Person;
use App\Models\PersonIdentity;
use App\Models\Station;
use App\Models\User;
use App\Services\DataProtection;
use App\Services\DocumentStore;
use Database\Factories\DocumentFactory;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function secureProductionConfig(): void
{
    app()->detectEnvironment(fn (): string => 'production');

    config([
        'app.debug' => false,
        'app.url' => 'https://crms.example.test',
        'session.secure' => true,
        'session.http_only' => true,
        'session.encrypt' => true,
        'crms.auth.require_mfa' => true,
        'crms.auth.idle_timeout' => 15,
        'crms.security.csp' => true,
        'crms.documents.scanner' => 'clamd',
        'crms.documents.allow_unscanned' => false,
        'queue.default' => 'redis',
        'mail.default' => 'smtp',
    ]);
}

describe('production preflight', function () {
    it('passes a secure configuration', function () {
        secureProductionConfig();

        $this->artisan('ops:preflight')->assertSuccessful();
    });

    it('fails on each insecure setting', function (string $key, mixed $value) {
        secureProductionConfig();
        config([$key => $value]);

        $this->artisan('ops:preflight')->assertFailed();
    })->with([
        ['app.debug', true],
        ['app.url', 'http://crms.example.test'],
        ['session.secure', false],
        ['crms.auth.require_mfa', false],
        ['crms.audit.seal_key', null],
        ['crms.data_protection.keys', null],
        ['crms.data_protection.lookup_key', null],
        ['crms.documents.scanner', 'none'],
        ['crms.documents.allow_unscanned', true],
        ['queue.default', 'sync'],
        ['mail.default', 'log'],
    ]);

    it('fails when not in production', function () {
        secureProductionConfig();
        app()->detectEnvironment(fn (): string => 'staging');

        $this->artisan('ops:preflight')->assertFailed();
    });
});

describe('rotating data keys', function () {
    beforeEach(fn () => Storage::fake('documents'));

    it('re-encrypts everything so the old key can be retired', function () {
        $newKey = base64_encode(random_bytes(32));
        $oldKeys = (string) config('crms.data_protection.keys');

        $person = Person::factory()->create();
        $identity = PersonIdentity::factory()->number('TESTROTATE01')->create(['person_id' => $person->id]);
        $case = CriminalCase::factory()->create();
        $document = Document::factory()->create(['case_id' => $case->id]);

        config(['crms.data_protection.keys' => "{$oldKeys},2:{$newKey}"]);

        $this->artisan('security:rotate-data-keys', ['--by' => 'ops@crms.test'])->assertSuccessful();

        config(['crms.data_protection.keys' => "2:{$newKey}"]);

        expect(DB::table('person_identities')->sole()->identifier_ciphertext)->toStartWith('v2:')
            ->and($identity->fresh()->identifier_ciphertext)->toBe('TESTROTATE01')
            ->and($person->fresh()->date_of_birth_ciphertext)->toBe('1990-01-15')
            ->and($case->fresh()->narrative_ciphertext)->toBe('Synthetic narrative for testing.')
            ->and(app(DocumentStore::class)->contents($document))->toBe(DocumentFactory::CONTENTS)
            ->and(AuditEvent::where('event', 'security.data_keys.rotated')->sole()->context['key_version'])->toBe(2);
    });

    it('re-encrypts append-only history without tripping its guard', function () {
        $case = CriminalCase::factory()->create();
        $event = new InvestigationEvent;
        $event->forceFill(['case_id' => $case->id, 'user_id' => $case->created_by, 'type' => 'note', 'summary_ciphertext' => 'A synthetic note', 'occurred_at' => now()])->save();

        config(['crms.data_protection.keys' => config('crms.data_protection.keys').',2:'.base64_encode(random_bytes(32))]);

        $this->artisan('security:rotate-data-keys', ['--by' => 'ops@crms.test'])->assertSuccessful();

        expect(DB::table('investigation_events')->sole()->summary_ciphertext)->toStartWith('v2:')
            ->and($event->fresh()->summary_ciphertext)->toBe('A synthetic note');
    });

    it('skips values already on the newest key when run again', function () {
        Person::factory()->create();

        config(['crms.data_protection.keys' => config('crms.data_protection.keys').',2:'.base64_encode(random_bytes(32))]);

        $this->artisan('security:rotate-data-keys', ['--by' => 'ops@crms.test'])->assertSuccessful();
        $firstRun = DB::table('people')->sole()->date_of_birth_ciphertext;

        $this->artisan('security:rotate-data-keys', ['--by' => 'ops@crms.test'])->assertSuccessful();

        expect(DB::table('people')->sole()->date_of_birth_ciphertext)->toBe($firstRun)
            ->and(AuditEvent::where('event', 'security.data_keys.rotated')->orderByDesc('occurred_at')->orderByDesc('id')->first()->context['values_rotated'])->toBe(0);
    });
});

describe('data protection', function () {
    it('refuses to store without keys rather than falling back to the app key', function () {
        config(['crms.data_protection.keys' => null]);

        expect(fn () => app(DataProtection::class)->encrypt('x'))->toThrow(RuntimeException::class, 'No data keys are configured');
    });

    it('refuses a lookup without its own secret', function () {
        config(['crms.data_protection.lookup_key' => null]);

        expect(fn () => app(DataProtection::class)->lookupToken('national_id', 'TEST1'))->toThrow(RuntimeException::class, 'No identifier lookup key');
    });

    it('produces different tokens for different secrets and document types', function () {
        $protection = app(DataProtection::class);
        $token = $protection->lookupToken('national_id', 'TEST12345');

        expect($protection->lookupToken('passport', 'TEST12345'))->not->toBe($token);

        config(['crms.data_protection.lookup_key' => 'another-secret']);

        expect($protection->lookupToken('national_id', 'TEST12345'))->not->toBe($token);
    });

    it('masks short values completely', function () {
        $protection = app(DataProtection::class);

        expect($protection->maskIdentifier('AB12'))->toBe('****')
            ->and($protection->maskContact('12345'))->toBe('*****')
            ->and($protection->maskContact('jane@example.org'))->toBe('j***@example.org');
    });
});

describe('placement', function () {
    it('refuses new records at a station that has been deactivated', function () {
        $station = Station::factory()->create(['is_active' => false]);
        $officer = officer(Role::RecordsOfficer, $station);

        $this->actingAs($officer);

        $this->get(route('cases.create'))->assertForbidden();
        $this->get(route('persons.create'))->assertForbidden();
    });
});

it('accepts a real PDF and a real image, read from their content', function () {
    Queue::fake();
    Storage::fake('documents');

    $station = Station::factory()->create();
    $case = CriminalCase::factory()->atStation($station)->create();

    $upload = function (string $name, string $contents): UploadedFile {
        $path = tempnam(sys_get_temp_dir(), 'crms');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, null, null, true);
    };

    $this->actingAs(officer(Role::RecordsOfficer, $station));

    $this->post(route('cases.documents.store', $case), ['category' => 'report', 'file' => $upload('report.pdf', "%PDF-1.4\n%synthetic\n")])
        ->assertSessionHasNoErrors();

    $this->post(route('cases.documents.store', $case), ['category' => 'report', 'file' => $upload('photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='))])
        ->assertSessionHasNoErrors();

    expect(Document::orderBy('original_name')->pluck('mime_type')->all())->toBe(['image/png', 'application/pdf']);
});

it('seeds synthetic demonstration data only', function () {
    $this->seed();

    expect(User::where('email', 'security@crms.test')->sole()->hasRole(Role::SecurityAdministrator))->toBeTrue()
        ->and(User::where('email', 'infrastructure@crms.test')->sole()->permissions())->toBe([])
        ->and(CriminalCase::count())->toBe(30)
        ->and(Person::pluck('family_name')->unique()->all())->toBe(['Testperson'])
        ->and(DB::table('statistic_snapshots')->count())->toBeGreaterThan(0);
});

it('refuses to seed demonstration accounts outside local or testing', function () {
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => (new DatabaseSeeder)->run())->toThrow(RuntimeException::class, 'may only be seeded');
});
