<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User $target */
        $target = $this->route('user');

        return $this->user()?->can('update', $target) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $target */
        $target = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'staff_number' => ['nullable', 'string', 'max:32', Rule::unique('users', 'staff_number')->ignore($target)],
            'job_title' => ['nullable', 'string', 'max:64'],
            'district_id' => ['nullable', 'integer', Rule::exists('districts', 'id')],
            'station_id' => ['nullable', 'integer', Rule::exists('stations', 'id')],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
        ];
    }
}
