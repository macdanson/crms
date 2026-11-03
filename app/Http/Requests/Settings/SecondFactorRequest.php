<?php

namespace App\Http\Requests\Settings;

use App\Enums\AttemptResult;
use App\Enums\AuditOutcome;
use App\Models\User;
use App\Services\AccountAttemptLimiter;
use App\Services\AuditLogger;
use App\Services\SecondFactor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * A request that must carry proof of the user's second factor: the code
 * from their authenticator app, or one of their recovery codes.
 *
 * The input rules are checked first. Once they pass, the proof itself is
 * checked (and a recovery code used up) automatically, so no action that
 * takes this request can run without it, and no later rule can fail after
 * a code has been used.
 */
class SecondFactorRequest extends FormRequest
{
    /**
     * Only a user with a linked authenticator can prove it.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->hasEnabledTwoFactorAuthentication();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required_without' => 'Enter the code from your authenticator app, or a recovery code.',
        ];
    }

    /**
     * Check the proof, using a recovery code up, or throw a validation error.
     * Guesses are limited per account, not only per request, so a hijacked
     * session cannot keep guessing codes.
     *
     * @throws ValidationException
     */
    protected function passedValidation(): void
    {
        $user = $this->challengedUser();
        $limiter = app(AccountAttemptLimiter::class);
        $field = $this->filled('recovery_code') ? 'recovery_code' : 'code';

        $result = $limiter->attempt('second-factor', $user, fn (): bool => $this->checkProof());

        if ($result === AttemptResult::Passed) {
            return;
        }

        if ($result === AttemptResult::Locked) {
            app(AuditLogger::class)->record('mfa.check.locked', AuditOutcome::Blocked, $user);

            throw ValidationException::withMessages([$field => $limiter->lockedMessage('second-factor', $user, 'codes')]);
        }

        app(AuditLogger::class)->record($this->failureEvent(), AuditOutcome::Failure, $user);

        throw ValidationException::withMessages([$field => $field === 'recovery_code'
            ? 'That recovery code is not valid.'
            : 'That code is not valid. Check your authenticator app and try again.']);
    }

    /**
     * Whether a recovery code given as proof is used up. An action that
     * clears every recovery code itself only needs it checked, so a failed
     * action does not cost the user a code.
     */
    protected function usesUpRecoveryCode(): bool
    {
        return true;
    }

    /**
     * The audit event recorded when the proof is wrong.
     */
    protected function failureEvent(): string
    {
        return 'mfa.step_up.failed';
    }

    protected function challengedUser(): User
    {
        /** @var User */
        return $this->user();
    }

    /**
     * Check the code. A recovery code that is used up is used up by the check
     * itself, in one atomic step, so a code that a parallel request has just
     * used counts as a wrong guess.
     */
    private function checkProof(): bool
    {
        $secondFactor = app(SecondFactor::class);

        if ($this->filled('recovery_code')) {
            $recoveryCode = $this->string('recovery_code')->toString();

            return $this->usesUpRecoveryCode()
                ? $secondFactor->consumeRecoveryCode($this->challengedUser(), $recoveryCode)
                : $secondFactor->hasRecoveryCode($this->challengedUser(), $recoveryCode);
        }

        return $secondFactor->isValidCode($this->challengedUser(), $this->string('code')->toString());
    }
}
