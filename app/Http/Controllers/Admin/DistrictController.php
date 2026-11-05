<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDistrictRequest;
use App\Http\Requests\Admin\UpdateDistrictRequest;
use App\Models\District;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;

class DistrictController extends Controller
{
    public function store(StoreDistrictRequest $request, AuditLogger $audit): RedirectResponse
    {
        $district = District::create($request->validated());

        $audit->record('organisation.district.created', AuditOutcome::Success, $request->user(), record: $district);

        return back()->with('status', "District {$district->name} added.");
    }

    public function update(UpdateDistrictRequest $request, District $district, AuditLogger $audit): RedirectResponse
    {
        $district->update($request->validated());

        $audit->record('organisation.district.updated', AuditOutcome::Success, $request->user(), [
            'changed' => array_keys(Arr::except($district->getChanges(), 'updated_at')),
        ], $district);

        return back()->with('status', 'District updated.');
    }
}
