<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\District;
use App\Models\Station;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organisation structure: districts, stations and their departments.
 */
class OrganisationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/organisation/Index', [
            'districts' => District::query()
                ->withCount('stations')
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'is_active']),
            'stations' => Station::query()
                ->with('district:id,name')
                ->withCount('departments')
                ->orderBy('name')
                ->get(['id', 'district_id', 'name', 'code', 'is_active']),
            'departments' => Department::query()
                ->with('station:id,name')
                ->orderBy('name')
                ->get(['id', 'station_id', 'name', 'is_active']),
        ]);
    }
}
