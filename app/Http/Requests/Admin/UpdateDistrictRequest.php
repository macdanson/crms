<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use App\Models\District;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDistrictRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permission::OrganisationManage) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var District $district */
        $district = $this->route('district');

        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('districts', 'name')->ignore($district)],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
