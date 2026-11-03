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
use Illuminate\Validation\ValidationException;

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

        $usedRecoveryCode = $request->filled('recovery_code');

        // A recovery code is single-use, whatever it was used for; another
        // request may have used it since it was validated.
        if ($usedRecoveryCode && ! $secondFactor->consumeRecoveryCode($user, $request->string('recovery_code')->toString())) {
            throw ValidationException::withMessages(['recovery_code' => 'That recovery code is not valid.']);
        }

        $secondFactor->markConfirmed($request->session());

        $audit->record('mfa.step_up.passed', AuditOutcome::Success, $user, [
            'method' => $usedRecoveryCode ? 'recovery_code' : 'authenticator',
        ]);

        return ResponseFormat::wantsJson($request) ? response()->noContent() : back();
    }
}
