<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDepartmentRequest;
use App\Http\Requests\Admin\UpdateDepartmentRequest;
use App\Models\Department;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;

class DepartmentController extends Controller
{
    public function store(StoreDepartmentRequest $request, AuditLogger $audit): RedirectResponse
    {
        $department = Department::create($request->validated());

        $audit->record('organisation.department.created', AuditOutcome::Success, $request->user(), record: $department);

        return back()->with('status', "Department {$department->name} added.");
    }

    public function update(UpdateDepartmentRequest $request, Department $department, AuditLogger $audit): RedirectResponse
    {
        $department->update($request->validated());

        $audit->record('organisation.department.updated', AuditOutcome::Success, $request->user(), [
            'changed' => array_keys(Arr::except($department->getChanges(), 'updated_at')),
        ], $department);

        return back()->with('status', 'Department updated.');
    }
}
