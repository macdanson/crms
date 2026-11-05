<?php

use App\Contracts\MalwareScanner;
use App\Enums\CaseEventType;
use App\Enums\Role;
use App\Enums\ScanStatus;
use App\Jobs\ScanDocument;
use App\Models\AuditEvent;
use App\Models\CriminalCase;
use App\Models\Document;
use App\Models\InvestigationEvent;
use App\Models\Station;
use App\Services\DocumentStore;
use App\Services\Scanners\NoScanner;
use Database\Factories\DocumentFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function stubScanner(ScanStatus|Throwable $result): void
{
    app()->instance(MalwareScanner::class, new class($result) implements MalwareScanner
    {
        public function __construct(private ScanStatus|Throwable $result) {}

        public function scan(string $contents): ScanStatus
        {
            return $this->result instanceof Throwable ? throw $this->result : $this->result;
        }
    });
}

beforeEach(function () {
    Storage::fake('documents');
    $this->station = Station::factory()->create();
    $this->case = CriminalCase::factory()->atStation($this->station)->create();
    $this->records = officer(Role::RecordsOfficer, $this->station);
});

it('stores an upload encrypted, under a random name, awaiting a scan', function () {
    Queue::fake();

    $this->actingAs($this->records)->post(route('cases.documents.store', $this->case), [
        'category' => 'report',
        'file' => UploadedFile::fake()->createWithContent('../../evil name.txt', 'Plain synthetic text'),
    ])->assertRedirect();

    $document = Document::sole();
    $stored = Storage::disk('documents')->get($document->storage_key);

    expect($document)
        ->original_name->toBe('evil name.txt')
        ->mime_type->toBe('text/plain')
        ->scan_status->toBe(ScanStatus::Pending)
        ->sha256->toBe(hash('sha256', 'Plain synthetic text'))
        ->and($document->storage_key)->not->toContain('evil')
        ->and($stored)->not->toContain('Plain synthetic text')
        ->and(app(DocumentStore::class)->contents($document))->toBe('Plain synthetic text')
        ->and(InvestigationEvent::where('case_id', $this->case->id)->sole()->type)->toBe(CaseEventType::DocumentAdded);

    Queue::assertPushed(ScanDocument::class, fn (ScanDocument $job): bool => $job->documentId === $document->id);
});

it('refuses a file whose content is not allowed, whatever its name says', function () {
    Queue::fake();

    // A real file: a fake upload reports its type from the name alone.
    $path = tempnam(sys_get_temp_dir(), 'crms');
    file_put_contents($path, "<?php echo 'hello';");

    $this->actingAs($this->records)->post(route('cases.documents.store', $this->case), [
        'category' => 'report',
        'file' => new UploadedFile($path, 'report.pdf', 'application/pdf', null, true),
    ])->assertSessionHasErrors('file');

    unlink($path);

    expect(Document::count())->toBe(0);
});

it('refuses an executable extension and an oversized file', function () {
    Queue::fake();
    $this->actingAs($this->records);

    $this->post(route('cases.documents.store', $this->case), [
        'category' => 'report',
        'file' => UploadedFile::fake()->createWithContent('notes.html', 'Plain text'),
    ])->assertSessionHasErrors('file');

    config(['crms.documents.max_kilobytes' => 1]);

    $this->post(route('cases.documents.store', $this->case), [
        'category' => 'report',
        'file' => UploadedFile::fake()->createWithContent('big.txt', str_repeat('x', 2048)),
    ])->assertSessionHasErrors('file');
});

it('refuses uploads to a case out of reach', function () {
    $this->actingAs(officer(Role::RecordsOfficer))->post(route('cases.documents.store', $this->case), [
        'category' => 'report',
        'file' => UploadedFile::fake()->createWithContent('a.txt', 'x'),
    ])->assertNotFound();
});

it('records the scan result, and a clean file can be downloaded', function () {
    stubScanner(ScanStatus::Clean);

    $document = Document::factory()->create(['case_id' => $this->case->id, 'scan_status' => ScanStatus::Pending]);

    dispatch_sync(new ScanDocument($document->id));

    expect($document->fresh()->scan_status)->toBe(ScanStatus::Clean);

    $response = $this->actingAs($this->records)->get(route('documents.show', $document));

    $response->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

    expect($response->getContent())->toBe(DocumentFactory::CONTENTS)
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(AuditEvent::where('event', 'document.downloaded')->sole())
        ->user_id->toBe($this->records->id)
        ->record_id->toBe($document->id);
});

it('never gives out a file that is not known to be clean', function (ScanStatus $status) {
    $document = Document::factory()->create(['case_id' => $this->case->id, 'scan_status' => $status]);

    $this->actingAs($this->records)->get(route('documents.show', $document))->assertStatus(409);

    expect(AuditEvent::where('event', 'document.download.blocked')->count())->toBe(1);
})->with([ScanStatus::Pending, ScanStatus::Infected, ScanStatus::Failed, ScanStatus::NotScanned]);

it('quarantines an infected file', function () {
    stubScanner(ScanStatus::Infected);

    $document = Document::factory()->create(['case_id' => $this->case->id, 'scan_status' => ScanStatus::Pending]);

    dispatch_sync(new ScanDocument($document->id));

    expect($document->fresh()->scan_status)->toBe(ScanStatus::Infected)
        ->and($document->fresh()->isDownloadable())->toBeFalse();
});

it('allows an unscanned file only when explicitly configured', function () {
    app()->instance(MalwareScanner::class, new NoScanner);

    $document = Document::factory()->create(['case_id' => $this->case->id, 'scan_status' => ScanStatus::Pending]);

    dispatch_sync(new ScanDocument($document->id));

    expect($document->fresh()->scan_status)->toBe(ScanStatus::NotScanned)
        ->and($document->fresh()->isDownloadable())->toBeFalse();

    config(['crms.documents.allow_unscanned' => true]);

    expect($document->fresh()->isDownloadable())->toBeTrue();
});

it('marks a file as failed after the last retry, and it stays undownloadable', function () {
    $document = Document::factory()->create(['case_id' => $this->case->id, 'scan_status' => ScanStatus::Pending]);

    (new ScanDocument($document->id))->failed(new RuntimeException('Scanner down'));

    expect($document->fresh()->scan_status)->toBe(ScanStatus::Failed);
});

it('checks access on every download, as not found when out of reach', function () {
    $document = Document::factory()->create(['case_id' => $this->case->id]);

    $this->actingAs(officer(Role::RecordsOfficer))->get(route('documents.show', $document))->assertNotFound();

    expect(AuditEvent::where('event', 'document.downloaded')->count())->toBe(0);
});

it('does not let the same address work for someone who loses access', function () {
    $document = Document::factory()->create(['case_id' => $this->case->id]);
    $url = route('documents.show', $document);

    $this->actingAs($this->records)->get($url)->assertOk();

    $this->records->roleAssignments()->update(['revoked_at' => now()]);
    $this->records->forgetActiveRoles();

    $this->actingAs($this->records)->get($url)->assertNotFound();
});

it('refuses a role that can see the case but not its documents', function () {
    $document = Document::factory()->create(['case_id' => $this->case->id]);
    $reviewer = officer(Role::RecordsQualityReviewer, $this->station);

    $this->actingAs($reviewer)->get(route('documents.show', $document))->assertForbidden();
});

it('previews inline only allowed types, sandboxed', function () {
    $pdf = Document::factory()->create(['case_id' => $this->case->id, 'mime_type' => 'application/pdf']);
    $text = Document::factory()->create(['case_id' => $this->case->id]);

    $response = $this->actingAs($this->records)->get(route('documents.show', [$pdf, 'preview' => 1]));

    expect($response->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox')
        ->and(AuditEvent::where('event', 'document.previewed')->count())->toBe(1);

    expect($this->get(route('documents.show', [$text, 'preview' => 1]))->headers->get('Content-Disposition'))->toStartWith('attachment');
});

it('applies a legal hold with a reason', function () {
    $document = Document::factory()->create(['case_id' => $this->case->id]);

    $this->actingAs(officer(Role::StationCommander, $this->station))
        ->put(route('documents.legal-hold', $document), ['legal_hold' => true, 'reason' => 'Court order received'])
        ->assertRedirect();

    expect($document->fresh()->legal_hold)->toBeTrue();

    $this->actingAs($this->records)
        ->put(route('documents.legal-hold', $document), ['legal_hold' => false, 'reason' => 'Not allowed'])
        ->assertForbidden();
});

it('offers no way to delete a document', function () {
    expect(collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains($route->uri(), 'documents') && in_array('DELETE', $route->methods(), true)))
        ->toBeEmpty();
});
