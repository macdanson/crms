<?php

namespace App\Http\Requests\Settings;

use App\Enums\AuditOutcome;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SecondFactor;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A request that must carry proof of the user's second factor: the code
 * from their authenticator app, or one of their recovery codes.
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
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || $this->hasValidSecondFactor()) {
                    return;
                }

                app(AuditLogger::class)->record($this->failureEvent(), AuditOutcome::Failure, $this->challengedUser());

                $this->filled('recovery_code')
                    ? $validator->errors()->add('recovery_code', 'That recovery code is not valid.')
                    : $validator->errors()->add('code', 'That code is not valid. Check your authenticator app and try again.');
            },
        ];
    }

    /**
     * The recovery code the user gave, once validated, or null when they
     * used their authenticator.
     */
    public function usedRecoveryCode(): ?string
    {
        return $this->filled('recovery_code')
            ? app(SecondFactor::class)->matchingRecoveryCode($this->challengedUser(), $this->string('recovery_code')->toString())
            : null;
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

    private function hasValidSecondFactor(): bool
    {
        $secondFactor = app(SecondFactor::class);

        if ($this->filled('recovery_code')) {
            return $secondFactor->matchingRecoveryCode($this->challengedUser(), $this->string('recovery_code')->toString()) !== null;
        }

        return $secondFactor->isValidCode($this->challengedUser(), $this->string('code')->toString());
    }
}
