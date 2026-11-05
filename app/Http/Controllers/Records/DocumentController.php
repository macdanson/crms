<?php

namespace App\Http\Controllers\Records;

use App\Enums\AuditOutcome;
use App\Enums\DocumentCategory;
use App\Http\Controllers\Controller;
use App\Models\CriminalCase;
use App\Models\Document;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Case documents (developer guide §19). Files are always proxied through here,
 * after an access check and with an audit record first: there is no direct or
 * guessable storage URL to reuse.
 */
class DocumentController extends Controller
{
    public function store(Request $request, CriminalCase $case, DocumentStore $documents): RedirectResponse
    {
        Gate::authorize('uploadDocument', $case);

        $allowed = (array) config('crms.documents.allowed_types');

        $validated = $request->validate([
            'category' => ['required', Rule::enum(DocumentCategory::class)],
            // `mimetypes` reads the file's content, not its name or the type the
            // browser claims; `extensions` also refuses a misleading name.
            'file' => [
                'required',
                'file',
                'max:'.(int) config('crms.documents.max_kilobytes'),
                'mimetypes:'.implode(',', array_keys($allowed)),
                'extensions:'.implode(',', [...array_values($allowed), 'jpeg']),
            ],
        ]);

        /** @var User $user */
        $user = $request->user();

        $documents->store($case, $validated['file'], DocumentCategory::from($validated['category']), $user);

        return back()->with('status', 'Document uploaded. It can be opened once the malware scan has finished.');
    }

    public function show(Request $request, Document $document, DocumentStore $documents, AuditLogger $audit): Response
    {
        $case = $document->criminalCase;

        Gate::authorize('viewDocuments', $case);

        /** @var User $user */
        $user = $request->user();

        if (! $document->isDownloadable()) {
            $audit->record('document.download.blocked', AuditOutcome::Blocked, $user, ['scan_status' => $document->scan_status->value], $document);

            abort(409, $document->scan_status->label());
        }

        $preview = $request->boolean('preview') && in_array($document->mime_type, (array) config('crms.documents.previewable_types'), true);

        $audit->record($preview ? 'document.previewed' : 'document.downloaded', AuditOutcome::Success, $user, ['case_id' => $case->id], $document);

        $response = response($documents->contents($document), 200, [
            'Content-Type' => $document->mime_type,
            // Keeps an uploaded file from running as part of this site.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ]);

        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            $preview ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $document->original_name,
            'document',
        ));

        return $response;
    }

    public function updateHold(Request $request, Document $document, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('holdDocuments', $document->criminalCase);

        $validated = $request->validate([
            'legal_hold' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $document->forceFill(['legal_hold' => (bool) $validated['legal_hold']])->save();

        $audit->record('document.legal_hold.changed', AuditOutcome::Success, $request->user(), [
            'legal_hold' => $document->legal_hold,
            'reason' => $validated['reason'],
        ], $document);

        return back()->with('status', $document->legal_hold ? 'Legal hold applied.' : 'Legal hold lifted.');
    }
}
