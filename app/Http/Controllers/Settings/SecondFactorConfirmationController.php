<?php

namespace App\Http\Controllers\Settings;

use App\Enums\AuditOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\SecondFactorRequest;
use App\Http\ResponseFormat;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SecondFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * The user proves their second factor before viewing or replacing recovery
 * codes, or switching multi-factor authentication off. The proof lasts a
 * few minutes (see RequireSecondFactorConfirmation).
 */
class SecondFactorConfirmationController extends Controller
{
    public function __invoke(SecondFactorRequest $request, SecondFactor $secondFactor, AuditLogger $audit): RedirectResponse|Response
    {
        /** @var User $user */
        $user = $request->user();

        // A recovery code is single-use, whatever it was used for.
        $recoveryCode = $request->usedRecoveryCode();

        if ($recoveryCode !== null) {
            $user->replaceRecoveryCode($recoveryCode);
        }

        $secondFactor->markConfirmed($request->session());

        $audit->record('mfa.step_up.passed', AuditOutcome::Success, $user, [
            'method' => $recoveryCode === null ? 'authenticator' : 'recovery_code',
        ]);

        return ResponseFormat::wantsJson($request) ? response()->noContent() : back();
    }
}
