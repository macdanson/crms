<?php

namespace App\Http\Controllers\Records;

use App\Enums\CasePersonRole;
use App\Enums\CaseStatus;
use App\Enums\RecordLifecycle;
use App\Http\Controllers\Controller;
use App\Models\CaseAssignment;
use App\Models\CasePerson;
use App\Models\CriminalCase;
use App\Models\Person;
use App\Models\User;
use App\Services\CaseRecords;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The work done on a case: progress notes, status and lifecycle changes,
 * assignments and the people linked to it.
 */
class CaseProgressController extends Controller
{
    public function storeNote(Request $request, CriminalCase $case, CaseRecords $cases): RedirectResponse
    {
        Gate::authorize('progress', $case);

        $validated = $request->validate(['note' => ['required', 'string', 'min:3', 'max:5000']]);

        $cases->addNote($case, $validated['note'], $this->actor($request));

        return back()->with('status', 'Progress note added.');
    }

    public function updateStatus(Request $request, CriminalCase $case, CaseRecords $cases): RedirectResponse
    {
        Gate::authorize('progress', $case);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(CaseStatus::class)],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $cases->changeStatus($case, CaseStatus::from($validated['status']), $validated['reason'], $this->actor($request));

        return back()->with('status', 'Case status updated.');
    }

    public function updateLifecycle(Request $request, CriminalCase $case, CaseRecords $cases): RedirectResponse
    {
        Gate::authorize('manageLifecycle', $case);

        $validated = $request->validate([
            'lifecycle' => ['required', Rule::enum(RecordLifecycle::class)],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $cases->changeLifecycle($case, RecordLifecycle::from($validated['lifecycle']), $validated['reason'], $this->actor($request));

        return back()->with('status', 'Record lifecycle updated.');
    }

    public function storeAssignment(Request $request, CriminalCase $case, CaseRecords $cases): RedirectResponse
    {
        Gate::authorize('assign', $case);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'is_lead' => ['sometimes', 'boolean'],
        ]);

        $cases->assign($case, User::query()->findOrFail((int) $validated['user_id']), (bool) ($validated['is_lead'] ?? false), $this->actor($request));

        return back()->with('status', 'Officer assigned. They have been notified.');
    }

    public function destroyAssignment(Request $request, CriminalCase $case, CaseAssignment $assignment, CaseRecords $cases): RedirectResponse
    {
        Gate::authorize('assign', $case);

        abort_unless($assignment->case_id === $case->id && $assignment->ended_at === null, 404);

        $cases->endAssignment($assignment, $this->actor($request));

        return back()->with('status', 'Assignment ended.');
    }

    public function storePerson(Request $request, CriminalCase $case, CaseRecords $cases): RedirectResponse
    {
        Gate::authorize('linkPerson', $case);

        $validated = $request->validate([
            'person_id' => ['required', 'uuid'],
            'role' => ['required', Rule::enum(CasePersonRole::class)],
            'role_status' => ['nullable', 'string', 'max:120'],
        ]);

        $actor = $this->actor($request);

        // Linking must not be a way to reach a record the officer cannot see.
        $person = Person::query()->visibleTo($actor)->notMerged()->whereKey((string) $validated['person_id'])->first();

        if ($person === null) {
            throw ValidationException::withMessages(['person_id' => 'That person could not be found.']);
        }

        $cases->linkPerson($case, $person, CasePersonRole::from($validated['role']), $validated['role_status'] ?? null, $actor);

        return back()->with('status', 'Person linked to the case.');
    }

    public function destroyPerson(Request $request, CriminalCase $case, CasePerson $link, CaseRecords $cases): RedirectResponse
    {
        Gate::authorize('linkPerson', $case);

        abort_unless($link->case_id === $case->id && $link->unlinked_at === null, 404);

        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        $cases->unlinkPerson($link, $validated['reason'], $this->actor($request));

        return back()->with('status', 'Link ended. It stays in the case history.');
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
