<?php

namespace App\Http\Requests\Records;

use App\Models\CriminalCase;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CorrectCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var CriminalCase $case */
        $case = $this->route('case');

        return $this->user()?->can('correct', $case) ?? false;
    }

    /**
     * Get the validation rules that apply to the request. The reference,
     * station and status cannot be corrected here.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'offence_category_id' => ['required', 'integer', Rule::exists('offence_categories', 'id')],
            'incident_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'incident_location' => ['required', 'string', 'max:255'],
            'narrative' => ['required', 'string', 'min:10', 'max:20000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
