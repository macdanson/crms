<?php

namespace App\Http\Requests\Records;

use App\Enums\PersonSex;
use App\Models\Person;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CorrectPersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Person $person */
        $person = $this->route('person');

        return $this->user()?->can('correct', $person) ?? false;
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
            'nationality' => ['nullable', 'string', 'max:80'],
            'occupation' => ['nullable', 'string', 'max:120'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
