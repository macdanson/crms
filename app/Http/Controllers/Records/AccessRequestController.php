<?php

namespace App\Http\Controllers\Records;

use App\Enums\AccessPurpose;
use App\Enums\JustificationStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\AccessJustification;
use App\Models\CriminalCase;
use App\Models\User;
use App\Services\AccessRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Asking for, and deciding on, exceptional access to a case (developer guide §8).
 */
class AccessRequestController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->hasPermission(Permission::AccessRequest) || $user->hasPermission(Permission::AccessApprove), 403);

        $toDecide = [];

        if ($user->hasPermission(Permission::AccessApprove)) {
            $toDecide = AccessJustification::query()
                ->where('action', AccessJustification::ACTION_CASE_ACCESS)
                ->where('status', JustificationStatus::Pending)
                ->where('user_id', '!=', $user->id)
                ->whereIn('case_id', CriminalCase::query()->visibleTo($user)->select('id'))
                ->with(['user:id,name,station_id', 'user.station:id,name', 'criminalCase:id,reference'])
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit(100)
                ->get()
                ->map(fn (AccessJustification $request): array => [
                    'id' => $request->id,
                    'requester' => $request->user->name,
                    'requester_station' => $request->user->station?->name,
                    'reference' => $request->criminalCase?->reference,
                    'purpose' => $request->purpose->label(),
                    'explanation' => $request->explanation,
                    'created_at' => $request->created_at?->toIso8601String(),
                ]);
        }

        return Inertia::render('access/Index', [
            'mine' => AccessJustification::query()
                ->where('user_id', $user->id)
                ->where('action', AccessJustification::ACTION_CASE_ACCESS)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (AccessJustification $request): array => [
                    'id' => $request->id,
                    // Only a decision says anything about the case: a request
                    // for a reference that does not exist simply stays pending.
                    'case_id' => $request->status === JustificationStatus::Approved && $request->expires_at?->isFuture() ? $request->case_id : null,
                    'reference' => $request->case_reference,
                    'purpose' => $request->purpose->label(),
                    'status' => $request->status->label(),
                    'decision_note' => $request->decision_note,
                    'created_at' => $request->created_at?->toIso8601String(),
                    'expires_at' => $request->expires_at?->toIso8601String(),
                ]),
            'toDecide' => $toDecide,
            'purposes' => AccessPurpose::options(),
            'can' => [
                'request' => $user->hasPermission(Permission::AccessRequest),
                'decide' => $user->hasPermission(Permission::AccessApprove),
            ],
        ]);
    }

    public function store(Request $request, AccessRequests $access): RedirectResponse
    {
        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:60'],
            'purpose' => ['required', Rule::enum(AccessPurpose::class)],
            'explanation' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $access->request($user, $validated['reference'], AccessPurpose::from($validated['purpose']), $validated['explanation']);

        return back()->with('status', 'Request sent. If the reference is valid, an authorised officer will decide on it.');
    }

    public function decide(Request $request, AccessJustification $justification, AccessRequests $access): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($access->canDecide($user, $justification), 404);

        $validated = $request->validate([
            'approve' => ['required', 'boolean'],
            'note' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $access->decide($justification, (bool) $validated['approve'], $validated['note'], $user);

        return back()->with('status', 'Decision recorded.');
    }
}
