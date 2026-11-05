<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditOutcome;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only review of the audit trail. Audit records hold sensitive
 * information, so reading them is itself recorded (developer guide §12.3).
 */
class AuditLogController extends Controller
{
    public function __invoke(Request $request, AuditLogger $audit): Response
    {
        $filters = $request->validate([
            'event' => ['nullable', 'string', 'max:100'],
            'outcome' => ['nullable', Rule::enum(AuditOutcome::class)],
            'user_id' => ['nullable', 'integer'],
            'record_id' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        $audit->record('audit.reviewed', AuditOutcome::Success, $actor, ['filters' => array_filter($filters)]);

        $events = AuditEvent::query()
            ->with('user:id,name')
            ->when($filters['event'] ?? null, fn (Builder $query, string $event) => $query->where('event', 'like', "{$event}%"))
            ->when($filters['outcome'] ?? null, fn (Builder $query, string $outcome) => $query->where('outcome', $outcome))
            ->when($filters['user_id'] ?? null, fn (Builder $query, int|string $id) => $query->where('user_id', $id))
            ->when($filters['record_id'] ?? null, fn (Builder $query, string $id) => $query->where('record_id', $id))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->whereDate('occurred_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->whereDate('occurred_at', '<=', $to))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AuditEvent $event): array => [
                'id' => $event->id,
                'occurred_at' => $event->occurred_at->toIso8601String(),
                'event' => $event->event,
                'outcome' => $event->outcome->value,
                'user' => $event->user?->name,
                'record_type' => $event->record_type,
                'record_id' => $event->record_id,
                'ip_address' => $event->ip_address,
                'context' => $event->context,
            ]);

        return Inertia::render('admin/audit/Index', [
            'events' => $events,
            'filters' => $filters,
            'outcomes' => array_map(fn (AuditOutcome $outcome): string => $outcome->value, AuditOutcome::cases()),
        ]);
    }
}
