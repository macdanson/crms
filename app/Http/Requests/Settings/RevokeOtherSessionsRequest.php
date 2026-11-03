<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use App\Services\PasswordCheck;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
     * The password is checked by PasswordCheck, which limits and audits
     * wrong guesses.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var User $user */
                $user = $this->user();

                $error = app(PasswordCheck::class)->check(
                    $user,
                    $this->string('password')->toString(),
                    'revoke_other_sessions',
                    'The password is incorrect.',
                );

                if ($error !== null) {
                    $validator->errors()->add('password', $error);
                }
            },
        ];
    }
}
