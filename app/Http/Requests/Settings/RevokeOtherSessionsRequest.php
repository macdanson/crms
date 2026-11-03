<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use App\Services\PasswordCheck;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class RevokeOtherSessionsRequest extends FormRequest
{
    /**
     * Any signed-in user may sign out their own other sessions.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Once the input rules pass, check the password (limited and audited by
     * PasswordCheck), so the action cannot run without it. Same pattern as
     * SecondFactorRequest.
     *
     * @throws ValidationException
     */
    protected function passedValidation(): void
    {
        /** @var User $user */
        $user = $this->user();

        $error = app(PasswordCheck::class)->check(
            $user,
            $this->string('password')->toString(),
            'revoke_other_sessions',
            'The password is incorrect.',
        );

        if ($error !== null) {
            throw ValidationException::withMessages(['password' => $error]);
        }
    }
}
