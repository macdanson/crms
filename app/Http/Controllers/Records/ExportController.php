<?php

namespace App\Http\Controllers\Records;

use App\Enums\AccessPurpose;
use App\Enums\CaseStatus;
use App\Enums\ExportStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\DataExport;
use App\Models\OffenceCategory;
use App\Models\User;
use App\Policies\DataExportPolicy;
use App\Services\DataExports;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Case-list exports (developer guide §17). Off until the institution enables them.
 */
class ExportController extends Controller
{
    public function index(Request $request, DataExportPolicy $policy): Response
    {
        Gate::authorize('viewAny', DataExport::class);

        /** @var User $user */
        $user = $request->user();

        $toDecide = $user->hasPermission(Permission::ExportsApprove)
            ? DataExport::query()
                ->where('status', ExportStatus::PendingApproval)
                ->where('requested_by', '!=', $user->id)
                ->with(['requester', 'justification'])
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit(100)
                ->get()
                ->filter(fn (DataExport $export): bool => $policy->supervises($user, $export->requester))
                ->values()
                ->map(fn (DataExport $export): array => [
                    ...$this->summary($export),
                    'requester' => $export->requester->name,
                    'purpose' => $export->justification->purpose->label(),
                    'explanation' => $export->justification->explanation,
                ])
            : [];

        return Inertia::render('exports/Index', [
            'enabled' => (bool) config('crms.exports.enabled'),
            'threshold' => (int) config('crms.exports.approval_threshold'),
            'fields' => config('crms.exports.fields'),
            'mine' => DataExport::query()
                ->where('requested_by', $user->id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (DataExport $export): array => $this->summary($export)),
            'toDecide' => $toDecide,
            'purposes' => AccessPurpose::options(),
            'statuses' => CaseStatus::options(),
            'categories' => OffenceCategory::query()->orderBy('name')->get(['id', 'name']),
            'can' => ['request' => $user->can('create', DataExport::class)],
        ]);
    }

    public function store(Request $request, DataExports $exports): RedirectResponse
    {
        Gate::authorize('create', DataExport::class);

        abort_unless((bool) config('crms.exports.enabled'), 403, 'Exports are switched off.');

        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(CaseStatus::class)],
            'offence_category_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'purpose' => ['required', Rule::enum(AccessPurpose::class)],
            'explanation' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        /** @var User $user */
        $user = $request->user();

        /** @var array{status?: string|null, offence_category_id?: int|null, from?: string|null, to?: string|null} $filters */
        $filters = array_intersect_key($validated, array_flip(['status', 'offence_category_id', 'from', 'to']));

        $export = $exports->request($user, $filters, AccessPurpose::from($validated['purpose']), $validated['explanation']);

        return back()->with('status', $export->status === ExportStatus::PendingApproval
            ? 'This export needs approval because of its size. You will be notified.'
            : 'Your export is being prepared.');
    }

    public function decide(Request $request, DataExport $export, DataExports $exports): RedirectResponse
    {
        Gate::authorize('decide', $export);

        $validated = $request->validate([
            'approve' => ['required', 'boolean'],
            'note' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $exports->decide($export, (bool) $validated['approve'], $validated['note'], $user);

        return back()->with('status', 'Decision recorded.');
    }

    public function download(Request $request, DataExport $export, DataExports $exports): HttpResponse
    {
        Gate::authorize('download', $export);

        /** @var User $user */
        $user = $request->user();

        $csv = $exports->collect($export, $user);

        abort_if($csv === null, 410, 'This export has already been downloaded or has expired.');

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="crms-export-'.$export->id.'.csv"',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(DataExport $export): array
    {
        return [
            'id' => $export->id,
            'row_count' => $export->row_count,
            'status' => $export->status->value,
            'status_label' => $export->status->label(),
            'filters' => $export->filters,
            'decision_note' => $export->decision_note,
            'created_at' => $export->created_at?->toIso8601String(),
            'expires_at' => $export->expires_at?->toIso8601String(),
        ];
    }
}
