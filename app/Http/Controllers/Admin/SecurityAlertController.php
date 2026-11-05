<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditOutcome;
use App\Http\Controllers\Controller;
use App\Models\SecurityAlert;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Security alerts raised from the audit trail, for review (developer guide §12.4).
 */
class SecurityAlertController extends Controller
{
    public function index(): Response
    {
        /** @var array<string, array{label: string}> $rules */
        $rules = config('crms.alerts.rules');

        return Inertia::render('admin/alerts/Index', [
            'alerts' => SecurityAlert::query()
                ->with(['user:id,name', 'reviewedBy:id,name'])
                ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [SecurityAlert::STATUS_OPEN])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(25)
                ->through(fn (SecurityAlert $alert): array => [
                    'id' => $alert->id,
                    'label' => $rules[$alert->rule]['label'] ?? $alert->rule,
                    'severity' => $alert->severity,
                    'user' => $alert->user?->name,
                    'user_id' => $alert->user_id,
                    'event_count' => $alert->event_count,
                    'window_started_at' => $alert->window_started_at->toIso8601String(),
                    'status' => $alert->status,
                    'reviewed_by' => $alert->reviewedBy?->name,
                    'review_note' => $alert->review_note,
                    'created_at' => $alert->created_at?->toIso8601String(),
                ]),
        ]);
    }

    public function review(Request $request, SecurityAlert $alert, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'min:5', 'max:500']]);

        $alert->forceFill([
            'status' => SecurityAlert::STATUS_REVIEWED,
            'reviewed_by' => $request->user()?->getKey(),
            'reviewed_at' => now(),
            'review_note' => $validated['note'],
        ])->save();

        $audit->record('security.alert.reviewed', AuditOutcome::Success, $request->user(), ['note' => $validated['note']], $alert);

        return back()->with('status', 'Alert reviewed.');
    }
}
