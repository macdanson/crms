<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use App\Models\Station;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permission::OrganisationManage) ?? false;
    }

    /**
     * Get the validation rules that apply to the request. The district and
     * code cannot be changed; see App\Models\Station.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Station $station */
        $station = $this->route('station');

        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('stations', 'name')->where('district_id', $station->district_id)->ignore($station)],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
