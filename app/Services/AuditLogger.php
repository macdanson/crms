<?php

namespace App\Services;

use App\Enums\AuditOutcome;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
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
        $context = $this->sanitise($context);
        $request = request();

        $auditEvent = AuditEvent::create([
            'occurred_at' => now('UTC'),
            'user_id' => $user?->getKey(),
            'event' => $event,
            'outcome' => $outcome,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 509),
            'session_hash' => $this->sessionHash($request),
            'request_id' => Context::get('request_id'),
            'context' => $context === [] ? null : $context,
        ]);

        Log::channel('audit')->info($event, [
            'audit_id' => $auditEvent->id,
            'outcome' => $outcome->value,
            'user_id' => $user?->getKey(),
            'ip' => $auditEvent->ip_address,
            'session' => $auditEvent->session_hash,
            'context' => $context,
        ]);

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

        return hash_hmac('sha256', $request->session()->getId(), (string) config('app.key'));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sanitise(array $context): array
    {
        return collect($context)
            ->reject(fn (mixed $value, string $key): bool => in_array(strtolower($key), self::REDACTED_KEYS, true))
            ->all();
    }
}
