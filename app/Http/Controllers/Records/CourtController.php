<?php

namespace App\Http\Controllers\Records;

use App\Enums\CourtOutcomeType;
use App\Enums\CourtProceedingStatus;
use App\Http\Controllers\Controller;
use App\Models\CasePerson;
use App\Models\CourtOutcome;
use App\Models\CourtProceeding;
use App\Models\CriminalCase;
use App\Models\User;
use App\Services\CourtRecords;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Court proceedings and outcomes, entered manually from a named source until an
 * authorised integration is approved (developer guide §27.5, §32).
 */
class CourtController extends Controller
{
    public function storeProceeding(Request $request, CriminalCase $case, CourtRecords $court): RedirectResponse
    {
        Gate::authorize('recordCourt', $case);

        $validated = $request->validate([
            'court_name' => ['required', 'string', 'max:160'],
            'court_reference' => ['required', 'string', 'max:80'],
            'status' => ['required', Rule::enum(CourtProceedingStatus::class)],
            'filed_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $court->addProceeding($case, $validated['court_name'], $validated['court_reference'], CourtProceedingStatus::from($validated['status']), $validated['filed_on'] ?? null, $user);

        return back()->with('status', 'Court proceeding added.');
    }

    public function storeOutcome(Request $request, CourtProceeding $proceeding, CourtRecords $court): RedirectResponse
    {
        Gate::authorize('recordCourt', $proceeding->criminalCase);

        $validated = $request->validate([
            'case_person_id' => ['required', 'integer'],
            'outcome' => ['required', Rule::enum(CourtOutcomeType::class)],
            'outcome_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'source' => ['required', 'string', 'min:3', 'max:160'],
            'source_reference' => ['nullable', 'string', 'max:120'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $court->recordOutcome(
            $proceeding,
            CasePerson::query()->where('case_id', $proceeding->case_id)->whereKey((int) $validated['case_person_id'])->firstOrFail(),
            CourtOutcomeType::from($validated['outcome']),
            $validated['outcome_date'],
            $validated['source'],
            $validated['source_reference'] ?? null,
            $user,
        );

        return back()->with('status', 'Outcome recorded. It has no effect until another officer verifies it.');
    }

    public function verifyOutcome(Request $request, CourtOutcome $outcome, CourtRecords $court): RedirectResponse
    {
        Gate::authorize('verifyCourt', $outcome->proceeding->criminalCase);

        $validated = $request->validate([
            'confirmed' => ['required', 'boolean'],
            'note' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $court->verify($outcome, (bool) $validated['confirmed'], $validated['note'], $user);

        return back()->with('status', 'Outcome reviewed.');
    }
}
