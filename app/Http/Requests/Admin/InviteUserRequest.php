<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['email' => User::normaliseEmail((string) $this->input('email'))]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'staff_number' => ['nullable', 'string', 'max:32', Rule::unique('users', 'staff_number')->where(
                fn ($query) => $query->where('email', '!=', $this->input('email')),
            )],
            'job_title' => ['nullable', 'string', 'max:64'],
            'station_id' => ['nullable', 'integer', Rule::exists('stations', 'id')],
        ];
    }
}
