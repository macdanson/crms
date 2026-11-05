<?php

namespace App\Services;

use App\Enums\AuditOutcome;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\RoleAssignment;
use App\Models\SecurityAlert;
use App\Models\User;
use App\Notifications\SecurityAlertRaised;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Looks for the suspicious patterns of developer guide §12.4 in the audit
 * trail and tells security staff. Rules live in crms.alerts.rules.
 */
class SecurityAlerts
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Check the current window of every rule. One rule, user and window raises
     * one alert, however often this runs.
     *
     * @return int How many new alerts were raised.
     */
    public function scan(): int
    {
        $raised = 0;

        /** @var array<string, array{label: string, events: list<string>, outcome?: string, threshold: int, window_minutes: int, severity: string}> $rules */
        $rules = config('crms.alerts.rules');

        foreach ($rules as $name => $rule) {
            $raised += $this->check($name, $rule);
        }

        if ($raised > 0) {
            $this->notifySecurityStaff();
        }

        return $raised;
    }

    /**
     * @param  array{label: string, events: list<string>, outcome?: string, threshold: int, window_minutes: int, severity: string}  $rule
     */
    private function check(string $name, array $rule): int
    {
        $windowSeconds = max(60, $rule['window_minutes'] * 60);
        $windowStart = Carbon::createFromTimestampUTC((int) floor(now()->getTimestamp() / $windowSeconds) * $windowSeconds);

        $groups = AuditEvent::query()
            ->whereIn('event', $rule['events'])
            ->when($rule['outcome'] ?? null, fn ($query, string $outcome) => $query->where('outcome', $outcome))
            ->where('occurred_at', '>=', $windowStart)
            ->selectRaw('user_id, COUNT(*) as total')
            ->groupBy('user_id')
            ->toBase()
            ->get();

        $raised = 0;

        foreach ($groups as $group) {
            if ((int) $group->total < $rule['threshold']) {
                continue;
            }

            $alert = SecurityAlert::query()
                ->where('rule', $name)
                ->where('user_id', $group->user_id)
                ->where('window_started_at', $windowStart)
                ->first();

            if ($alert !== null) {
                $alert->forceFill(['event_count' => (int) $group->total])->save();

                continue;
            }

            $alert = new SecurityAlert;
            $alert->forceFill([
                'rule' => $name,
                'severity' => $rule['severity'],
                'user_id' => $group->user_id,
                'event_count' => (int) $group->total,
                'window_started_at' => $windowStart,
            ])->save();

            $this->audit->record('security.alert.raised', AuditOutcome::Success, null, [
                'rule' => $name,
                'subject_id' => $group->user_id,
                'event_count' => (int) $group->total,
            ], $alert);

            $raised++;
        }

        return $raised;
    }

    private function notifySecurityStaff(): void
    {
        $staff = User::query()
            ->whereIn('id', RoleAssignment::query()->active()->where('role', Role::SecurityAdministrator)->select('user_id'))
            ->get()
            ->filter(fn (User $user): bool => $user->isActive());

        Notification::send($staff, new SecurityAlertRaised);
    }
}
