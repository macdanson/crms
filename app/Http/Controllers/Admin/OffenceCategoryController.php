<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditOutcome;
use App\Http\Controllers\Controller;
use App\Models\OffenceCategory;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The controlled offence catalogue. A category in use can be retired but not
 * removed, so old cases keep their classification.
 */
class OffenceCategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/catalogue/Index', [
            'categories' => OffenceCategory::query()->orderBy('name')->get(['id', 'code', 'name', 'is_active']),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        $category = OffenceCategory::create($request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Z0-9-]{2,20}$/', Rule::unique('offence_categories', 'code')],
            'name' => ['required', 'string', 'max:160', Rule::unique('offence_categories', 'name')],
        ]));

        $audit->record('catalogue.category.created', AuditOutcome::Success, $request->user(), record: $category);

        return back()->with('status', 'Category added.');
    }

    public function update(Request $request, OffenceCategory $category, AuditLogger $audit): RedirectResponse
    {
        $category->update($request->validate([
            'name' => ['required', 'string', 'max:160', Rule::unique('offence_categories', 'name')->ignore($category)],
            'is_active' => ['required', 'boolean'],
        ]));

        $audit->record('catalogue.category.updated', AuditOutcome::Success, $request->user(), [
            'changed' => array_keys(Arr::except($category->getChanges(), 'updated_at')),
        ], $category);

        return back()->with('status', 'Category updated.');
    }
}
