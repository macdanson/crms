<?php

namespace App\Http\Controllers\Records;

use App\Enums\DuplicateStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\DuplicateCandidate;
use App\Models\Person;
use App\Models\User;
use App\Services\PersonRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Human review of possible duplicates (developer guide §5.1). A reviewer only
 * sees pairs where they may see both records.
 */
class DuplicateReviewController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->hasPermission(Permission::PersonsMerge), 403);

        return Inertia::render('persons/Duplicates', [
            'candidates' => DuplicateCandidate::query()
                ->where('status', DuplicateStatus::Pending)
                ->whereHas('firstPerson', fn (Builder $query) => $query->visibleTo($user))
                ->whereHas('secondPerson', fn (Builder $query) => $query->visibleTo($user))
                ->with(['firstPerson.registeredStation:id,name', 'secondPerson.registeredStation:id,name'])
                ->orderByDesc('confidence')
                ->orderBy('id')
                ->paginate(25)
                ->through(fn (DuplicateCandidate $candidate): array => [
                    'id' => $candidate->id,
                    'reason' => $candidate->reason === DuplicateCandidate::REASON_IDENTITY
                        ? 'Same identity number'
                        : 'Same name and birth year (a possible match only)',
                    'confidence' => $candidate->confidence,
                    'first' => $this->personSummary($candidate->firstPerson),
                    'second' => $this->personSummary($candidate->secondPerson),
                ]),
        ]);
    }

    public function merge(Request $request, DuplicateCandidate $candidate, PersonRecords $people): RedirectResponse
    {
        Gate::authorize('reviewDuplicate', [Person::class, $candidate]);

        $validated = $request->validate([
            'target_person_id' => ['required', 'string', Rule::in([$candidate->first_person_id, $candidate->second_person_id])],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $target = $validated['target_person_id'] === $candidate->first_person_id ? $candidate->firstPerson : $candidate->secondPerson;

        $people->merge($candidate, $target, $validated['reason'], $user);

        return back()->with('status', 'Records merged. The history of both has been kept.');
    }

    public function dismiss(Request $request, DuplicateCandidate $candidate, PersonRecords $people): RedirectResponse
    {
        Gate::authorize('reviewDuplicate', [Person::class, $candidate]);

        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        /** @var User $user */
        $user = $request->user();

        $people->dismissDuplicate($candidate, $validated['reason'], $user);

        return back()->with('status', 'Marked as different people.');
    }

    /**
     * @return array{id: string, full_name: string, birth_year: int|null, station: string, created_at: string|null}
     */
    private function personSummary(Person $person): array
    {
        return [
            'id' => $person->id,
            'full_name' => $person->full_name,
            'birth_year' => $person->birth_year,
            'station' => $person->registeredStation->name,
            'created_at' => $person->created_at?->toIso8601String(),
        ];
    }
}
