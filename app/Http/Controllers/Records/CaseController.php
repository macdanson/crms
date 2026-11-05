<?php

namespace App\Http\Controllers\Records;

use App\Enums\AccessPurpose;
use App\Enums\AuditOutcome;
use App\Enums\CasePersonRole;
use App\Enums\CaseStatus;
use App\Enums\CourtOutcomeType;
use App\Enums\CourtProceedingStatus;
use App\Enums\DocumentCategory;
use App\Enums\Permission;
use App\Enums\RecordLifecycle;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Records\CorrectCaseRequest;
use App\Http\Requests\Records\StoreCaseRequest;
use App\Models\AccessJustification;
use App\Models\CaseAssignment;
use App\Models\CasePerson;
use App\Models\CourtOutcome;
use App\Models\CourtProceeding;
use App\Models\CriminalCase;
use App\Models\Document;
use App\Models\InvestigationEvent;
use App\Models\OffenceCategory;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CaseRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CaseController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', CriminalCase::class);

        /** @var User $user */
        $user = $request->user();

        $filters = $request->validate([
            'reference' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', Rule::enum(CaseStatus::class)],
            'offence_category_id' => ['nullable', 'integer'],
        ]);

        return Inertia::render('cases/Index', [
            'cases' => CriminalCase::query()
                ->visibleTo($user)
                ->with(['station:id,name', 'offenceCategory:id,name'])
                ->when($filters['reference'] ?? null, fn (Builder $query, string $reference) => $query->where('reference', 'like', '%'.addcslashes($reference, '%_\\').'%'))
                ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
                ->when($filters['offence_category_id'] ?? null, fn (Builder $query, int|string $id) => $query->where('offence_category_id', $id))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString()
                ->through(fn (CriminalCase $case): array => [
                    'id' => $case->id,
                    'reference' => $case->reference,
                    'station' => $case->station->name,
                    'category' => $case->offenceCategory->name,
                    'incident_date' => $case->incident_date->toDateString(),
                    'status' => $case->status->value,
                    'status_label' => $case->status->label(),
                    'lifecycle_label' => $case->lifecycle->label(),
                ]),
            'filters' => $filters,
            'statuses' => CaseStatus::options(),
            'categories' => OffenceCategory::query()->orderBy('name')->get(['id', 'name']),
            'canCreate' => $user->can('create', CriminalCase::class),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', CriminalCase::class);

        return Inertia::render('cases/Create', [
            'categories' => OffenceCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(StoreCaseRequest $request, CaseRecords $cases): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{offence_category_id: int, incident_date: string, incident_location: string, narrative: string} $details */
        $details = $request->validated();

        $case = $cases->open($details, $user);

        return to_route('cases.show', $case)->with('status', "Case {$case->reference} opened.");
    }

    public function show(Request $request, CriminalCase $case, AuditLogger $audit): Response
    {
        Gate::authorize('view', $case);

        /** @var User $user */
        $user = $request->user();

        $grant = AccessJustification::query()->activeGrantFor($user)->where('case_id', $case->id)->first();

        $audit->record('case.viewed', AuditOutcome::Success, $user, $grant === null ? [] : ['exceptional_access' => $grant->id], $case);

        return Inertia::render('cases/Show', [
            'exceptionalAccess' => $grant === null ? null : ['expires_at' => $grant->expires_at?->toIso8601String()],
            'documents' => $user->can('viewDocuments', $case)
                ? $case->documents()->with('uploadedBy:id,name')->orderByDesc('created_at')->orderByDesc('id')->get()->map(fn (Document $document): array => [
                    'id' => $document->id,
                    'name' => $document->original_name,
                    'category' => $document->category->label(),
                    'size_bytes' => $document->size_bytes,
                    'scan_status' => $document->scan_status->label(),
                    'downloadable' => $document->isDownloadable(),
                    'previewable' => in_array($document->mime_type, (array) config('crms.documents.previewable_types'), true),
                    'legal_hold' => $document->legal_hold,
                    'uploaded_by' => $document->uploadedBy->name,
                    'created_at' => $document->created_at?->toIso8601String(),
                ])
                : [],
            'court' => $case->courtProceedings()
                ->with(['outcomes.casePerson.person', 'outcomes.recordedBy:id,name', 'outcomes.verifiedBy:id,name'])
                ->orderBy('id')
                ->get()
                ->map(fn (CourtProceeding $proceeding): array => [
                    'id' => $proceeding->id,
                    'court_name' => $proceeding->court_name,
                    'court_reference' => $proceeding->court_reference,
                    'status' => $proceeding->status->label(),
                    'filed_on' => $proceeding->filed_on?->toDateString(),
                    'outcomes' => $proceeding->outcomes->sortByDesc('recorded_at')->values()->map(fn (CourtOutcome $outcome): array => [
                        'id' => $outcome->id,
                        'person' => $outcome->casePerson->person->full_name,
                        'outcome' => $outcome->outcome->label(),
                        'outcome_date' => $outcome->outcome_date->toDateString(),
                        'source' => $outcome->source,
                        'source_reference' => $outcome->source_reference,
                        'verification_status' => $outcome->verification_status->value,
                        'verification_label' => $outcome->verification_status->label(),
                        'recorded_by' => $outcome->recordedBy->name,
                        'recorded_by_me' => $outcome->recorded_by === $user->id,
                        'verified_by' => $outcome->verifiedBy?->name,
                        'verification_note' => $outcome->verification_note,
                    ]),
                ]),
            'record' => $this->summary($case),
            'history' => $case->events()
                ->with('user:id,name')
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (InvestigationEvent $event): array => [
                    'id' => $event->id,
                    'type' => $event->type->label(),
                    'summary' => $event->summary_ciphertext,
                    'user' => $event->user->name,
                    'occurred_at' => $event->occurred_at->toIso8601String(),
                ]),
            'people' => $user->hasPermission(Permission::PersonsView)
                ? $case->personLinks()->with('person')->orderByDesc('linked_at')->get()->map(fn (CasePerson $link): array => [
                    'id' => $link->id,
                    'person_id' => $link->person_id,
                    'full_name' => $link->person->full_name,
                    'birth_year' => $link->person->birth_year,
                    'role' => $link->role->label(),
                    'role_status' => $link->role_status,
                    'linked_at' => $link->linked_at->toIso8601String(),
                    'unlinked_at' => $link->unlinked_at?->toIso8601String(),
                    'unlink_reason' => $link->unlink_reason,
                ])
                : [],
            'assignments' => $case->assignments()->with('user:id,name,job_title')->orderByDesc('assigned_at')->get()->map(fn (CaseAssignment $assignment): array => [
                'id' => $assignment->id,
                'name' => $assignment->user->name,
                'job_title' => $assignment->user->job_title,
                'is_lead' => $assignment->is_lead,
                'assigned_at' => $assignment->assigned_at->toIso8601String(),
                'ended_at' => $assignment->ended_at?->toIso8601String(),
            ]),
            'options' => [
                'categories' => OffenceCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
                'next_statuses' => array_map(fn (CaseStatus $status): array => ['value' => $status->value, 'label' => $status->label()], $case->status->allowedNext()),
                'lifecycles' => RecordLifecycle::options(),
                'person_roles' => CasePersonRole::options(),
                'officers' => $user->can('assign', $case) ? $this->assignableOfficers($case) : [],
                'document_categories' => DocumentCategory::options(),
                'court_statuses' => CourtProceedingStatus::options(),
                'court_outcomes' => CourtOutcomeType::options(),
                'purposes' => AccessPurpose::options(),
            ],
            'can' => [
                'correct' => $user->can('correct', $case),
                'progress' => $user->can('progress', $case),
                'assign' => $user->can('assign', $case),
                'link_person' => $user->can('linkPerson', $case),
                'manage_lifecycle' => $user->can('manageLifecycle', $case),
                'print' => $user->can('printRecord', $case),
                'upload_document' => $user->can('uploadDocument', $case),
                'hold_documents' => $user->can('holdDocuments', $case),
                'record_court' => $user->can('recordCourt', $case),
                'verify_court' => $user->can('verifyCourt', $case),
            ],
        ]);
    }

    /**
     * A printable copy, marked with who printed it and when. Printing is
     * restricted by role and recorded (developer guide §17.1).
     */
    public function printRecord(Request $request, CriminalCase $case, AuditLogger $audit): Response
    {
        Gate::authorize('printRecord', $case);

        /** @var User $user */
        $user = $request->user();

        $audit->record('case.printed', AuditOutcome::Success, $user, record: $case);

        return Inertia::render('cases/Print', [
            'record' => $this->summary($case),
            'watermark' => "{$user->name} · {$user->staff_number} · ".now()->toDateTimeString().' UTC',
        ]);
    }

    public function update(CorrectCaseRequest $request, CriminalCase $case, CaseRecords $cases): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $changed = $cases->correct($case, $request->safe()->except('reason'), $request->string('reason')->toString(), $user);

        return back()->with('status', $changed === [] ? 'Nothing was changed.' : 'Correction recorded.');
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(CriminalCase $case): array
    {
        return [
            'id' => $case->id,
            'reference' => $case->reference,
            'station' => $case->station->name,
            'district' => $case->district->name,
            'category_id' => $case->offence_category_id,
            'category' => $case->offenceCategory->name,
            'incident_date' => $case->incident_date->toDateString(),
            'incident_location' => $case->incident_location,
            'narrative' => $case->narrative_ciphertext,
            'status' => $case->status->value,
            'status_label' => $case->status->label(),
            'lifecycle' => $case->lifecycle->value,
            'lifecycle_label' => $case->lifecycle->label(),
            'closed_at' => $case->closed_at?->toIso8601String(),
            'created_at' => $case->created_at?->toIso8601String(),
        ];
    }

    /**
     * Active investigating officers in the case's district: names only, for
     * someone allowed to assign this case.
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function assignableOfficers(CriminalCase $case): array
    {
        return User::query()
            ->where('district_id', $case->district_id)
            ->whereIn('id', RoleAssignment::query()->active()->where('role', Role::InvestigatingOfficer)->select('user_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'status'])
            ->filter(fn (User $officer): bool => $officer->isActive())
            ->map(fn (User $officer): array => ['id' => $officer->id, 'name' => $officer->name])
            ->values()
            ->all();
    }
}
