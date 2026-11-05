<?php

namespace App\Http\Requests\Records;

use App\Models\CriminalCase;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CriminalCase::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request. The station always
     * comes from the officer's own placement, never from the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'offence_category_id' => ['required', 'integer', Rule::exists('offence_categories', 'id')->where('is_active', true)],
            'incident_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'incident_location' => ['required', 'string', 'max:255'],
            'narrative' => ['required', 'string', 'min:10', 'max:20000'],
        ];
    }
}
