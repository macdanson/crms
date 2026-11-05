<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStationRequest;
use App\Http\Requests\Admin\UpdateStationRequest;
use App\Models\Station;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;

class StationController extends Controller
{
    public function store(StoreStationRequest $request, AuditLogger $audit): RedirectResponse
    {
        $station = Station::create($request->validated());

        $audit->record('organisation.station.created', AuditOutcome::Success, $request->user(), record: $station);

        return back()->with('status', "Station {$station->name} added.");
    }

    public function update(UpdateStationRequest $request, Station $station, AuditLogger $audit): RedirectResponse
    {
        $station->update($request->validated());

        $audit->record('organisation.station.updated', AuditOutcome::Success, $request->user(), [
            'changed' => array_keys(Arr::except($station->getChanges(), 'updated_at')),
        ], $station);

        return back()->with('status', 'Station updated.');
    }
}
