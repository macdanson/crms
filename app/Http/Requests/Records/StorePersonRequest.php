<?php

namespace App\Http\Requests\Records;

use App\Enums\IdentityType;
use App\Enums\PersonSex;
use App\Models\Person;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Person::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'given_names' => ['required', 'string', 'max:120'],
            'family_name' => ['required', 'string', 'max:120'],
            'other_names' => ['nullable', 'string', 'max:120'],
            'sex' => ['nullable', Rule::enum(PersonSex::class)],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1900-01-01'],
            'nationality' => ['nullable', 'string', 'max:80'],
            'occupation' => ['nullable', 'string', 'max:120'],
            'identity_type' => ['nullable', 'required_with:identity_number', Rule::enum(IdentityType::class)],
            'identity_number' => ['nullable', 'required_with:identity_type', 'string', 'min:4', 'max:40', 'regex:/^[A-Za-z0-9\s\-]+$/'],
        ];
    }
}
