<?php

namespace App\Services;

use App\Enums\AuditOutcome;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Records attributable security and business events (developer guide §12).
 *
 * Never pass passwords, tokens, session identifiers, keys or full identity
 * numbers in the context; known secret keys are stripped defensively.
 */
class AuditLogger
{
    public function __construct(private BrowserSessions $sessions) {}

    /**
     * Context keys that must never be persisted.
     *
     * @var list<string>
     */
    private const REDACTED_KEYS = [
        'password', 'password_confirmation', 'current_password', 'token',
        'code', 'recovery_code', 'secret', 'two_factor_secret', 'remember_token',
    ];

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $event,
        AuditOutcome $outcome = AuditOutcome::Success,
        ?User $user = null,
        array $context = [],
    ): AuditEvent {
        // Set by a console command (IdentifiesOperator). The request a command
        // sees is made up, so its address and browser say nothing.
        $operator = Context::get('operator');

        if ($operator !== null) {
            $context = ['operator' => $operator, 'os_user' => get_current_user(), ...$context];
        }

        $context = $this->sanitise($context);
        $request = request();

        $auditEvent = AuditEvent::create([
            'occurred_at' => now('UTC'),
            'user_id' => $user?->getKey(),
            'event' => $event,
            'outcome' => $outcome,
            'ip_address' => $operator === null ? $request->ip() : null,
            'user_agent' => $operator === null ? Str::limit((string) $request->userAgent(), 509) : null,
            'session_hash' => $this->sessionHash($request),
            'request_id' => Context::get('request_id'),
            'context' => $context === [] ? null : $context,
        ]);

        // A record rolled back with its change must not stay in the log file.
        DB::afterCommit(fn () => Log::channel('audit')->info($event, [
            'audit_id' => $auditEvent->id,
            'outcome' => $outcome->value,
            'user_id' => $user?->getKey(),
            'ip' => $auditEvent->ip_address,
            'session' => $auditEvent->session_hash,
            'context' => $context,
        ]));

        return $auditEvent;
    }

    /**
     * A one-way reference that links events from the same session without
     * revealing the session identifier itself.
     */
    private function sessionHash(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        return $this->sessions->sessionHash($request->session()->getId());
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sanitise(array $context): array
    {
        return collect($context)
            ->reject(fn (mixed $value, string $key): bool => in_array(strtolower($key), self::REDACTED_KEYS, true))
            ->map(fn (mixed $value): mixed => is_string($value) ? mb_scrub($value, 'UTF-8') : $value)
            ->all();
    }
}
