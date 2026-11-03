<?php

namespace App\Http\Requests\Settings;

use App\Enums\AuditOutcome;
use App\Models\User;
use App\Services\AuditLogger;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

/**
 * Replacing the authenticator needs proof of the current second factor (an
 * authenticator code or a recovery code), not only the password: a
 * hijacked session must not be able to take over the second factor.
 */
class ResetTwoFactorRequest extends FormRequest
{
    /**
     * Only a user with a linked authenticator can replace it.
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

                /** @var User $user */
                $user = $this->user();
                app(AuditLogger::class)->record('mfa.reset.failed', AuditOutcome::Failure, $user);

                $this->filled('recovery_code')
                    ? $validator->errors()->add('recovery_code', 'That recovery code is not valid.')
                    : $validator->errors()->add('code', 'That code is not valid. Check your authenticator app and try again.');
            },
        ];
    }

    private function hasValidSecondFactor(): bool
    {
        /** @var User $user */
        $user = $this->user();

        if ($this->filled('recovery_code')) {
            $recoveryCode = trim($this->string('recovery_code')->toString());

            return collect($user->recoveryCodes())
                ->contains(fn (string $code): bool => hash_equals($code, $recoveryCode));
        }

        return app(TwoFactorAuthenticationProvider::class)->verify(
            Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret),
            preg_replace('/\s+/', '', $this->string('code')->toString()),
        );
    }
}
