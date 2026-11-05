<?php

namespace App\Http\Controllers;

use App\Enums\CaseStatus;
use App\Enums\JustificationStatus;
use App\Enums\Permission;
use App\Listeners\RecordAuthenticationActivity;
use App\Models\AccessJustification;
use App\Models\CriminalCase;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The user's landing page after sign-in.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Dashboard', [
            'security' => [
                'mfa_enabled' => $user->hasEnabledTwoFactorAuthentication(),
                'recovery_codes_remaining' => $user->remainingRecoveryCodeCount(),
                'password_changed_at' => $user->password_changed_at?->toIso8601String(),
                'previous_login' => $request->session()->get(RecordAuthenticationActivity::PREVIOUS_LOGIN),
            ],
            'workload' => $this->workload($user),
        ]);
    }

    /**
     * Counts for this user's own work. A user with no operational role gets
     * nothing here, not even numbers.
     *
     * @return array{open_cases: int|null, assigned_to_me: int|null, access_requests_to_decide: int|null}
     */
    private function workload(User $user): array
    {
        $seesCases = $user->can('viewAny', CriminalCase::class);

        return [
            'open_cases' => $seesCases
                ? CriminalCase::query()->visibleTo($user)->where('status', '!=', CaseStatus::ClosedAdministratively)->count()
                : null,
            'assigned_to_me' => $user->hasPermission(Permission::CasesViewAssigned)
                ? CriminalCase::query()->whereHas('assignments', fn ($query) => $query->where('user_id', $user->id)->whereNull('ended_at'))->count()
                : null,
            'access_requests_to_decide' => $user->hasPermission(Permission::AccessApprove)
                ? AccessJustification::query()
                    ->where('action', AccessJustification::ACTION_CASE_ACCESS)
                    ->where('status', JustificationStatus::Pending)
                    ->where('user_id', '!=', $user->id)
                    ->whereIn('case_id', CriminalCase::query()->visibleTo($user)->select('id'))
                    ->count()
                : null,
        ];
    }
}
