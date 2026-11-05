<?php

namespace App\Services;

use App\Enums\AuditOutcome;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        'identifier', 'identifier_value', 'identity_number', 'nin',
    ];

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $event,
        AuditOutcome $outcome = AuditOutcome::Success,
        ?User $user = null,
        array $context = [],
        ?Model $record = null,
    ): AuditEvent {
        // Set by a console command (IdentifiesOperator). The request a command
        // sees is made up, so its address and browser say nothing.
        $operator = Context::get('operator');

        if ($operator !== null) {
            $context = ['operator' => $operator, 'os_user' => get_current_user(), ...$context];
        }

        $context = $this->sanitise($context);
        $request = request();

        // Who acted, for where they work. Only a user already known: asking
        // the guard to look one up could sign someone in part-way through.
        $actor = Auth::hasUser() ? Auth::user() : $user;

        $attributes = [
            'id' => (string) Str::uuid7(),
            'occurred_at' => now('UTC')->startOfSecond(),
            'user_id' => $user?->getKey(),
            'event' => $event,
            'outcome' => $outcome,
            'record_type' => $record?->getMorphClass(),
            'record_id' => $record === null ? null : (string) $record->getKey(),
            'actor_district_id' => $actor instanceof User ? $actor->district_id : null,
            'actor_station_id' => $actor instanceof User ? $actor->station_id : null,
            'ip_address' => $operator === null ? $request->ip() : null,
            'user_agent' => $operator === null ? Str::limit((string) $request->userAgent(), 509) : null,
            'session_hash' => $this->sessionHash($request),
            'request_id' => Context::get('request_id'),
            'context' => $context === [] ? null : $context,
        ];

        $auditEvent = new AuditEvent;
        $auditEvent->forceFill($attributes);
        $auditEvent->seal = $this->seal($auditEvent);
        $auditEvent->save();

        // A record rolled back with its change must not stay in the log file.
        DB::afterCommit(fn () => Log::channel('audit')->info($event, [
            'audit_id' => $auditEvent->id,
            'outcome' => $outcome->value,
            'user_id' => $user?->getKey(),
            'record_type' => $auditEvent->record_type,
            'record_id' => $auditEvent->record_id,
            'actor_district_id' => $auditEvent->actor_district_id,
            'actor_station_id' => $auditEvent->actor_station_id,
            'ip' => $auditEvent->ip_address,
            'session' => $auditEvent->session_hash,
            'request_id' => $auditEvent->request_id,
            'context' => $context,
            'seal' => $auditEvent->seal,
        ]));

        return $auditEvent;
    }

    /**
     * A keyed fingerprint of an event, or null when no seal key is configured.
     * Someone with write access to the database but not the key cannot alter
     * an event without it showing (`php artisan audit:verify`, §12.3). It
     * cannot show a removed event: the copy on the audit log channel, shipped
     * off the server, is what covers that.
     */
    public function seal(AuditEvent $event): ?string
    {
        $key = config('crms.audit.seal_key');

        if (! is_string($key) || $key === '') {
            return null;
        }

        return hash_hmac('sha256', (string) json_encode($this->canonical([
            'id' => $event->id,
            'occurred_at' => $event->occurred_at->format('Y-m-d H:i:s'),
            'user_id' => $event->user_id,
            'event' => $event->event,
            'outcome' => $event->outcome->value,
            'record_type' => $event->record_type,
            'record_id' => $event->record_id,
            'actor_district_id' => $event->actor_district_id,
            'actor_station_id' => $event->actor_station_id,
            'ip_address' => $event->ip_address,
            'session_hash' => $event->session_hash,
            'request_id' => $event->request_id,
            'context' => $event->context,
        ]), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), $key);
    }

    /**
     * Sort keys at every level: MySQL's JSON columns reorder them on storage.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function canonical(array $values): array
    {
        ksort($values);

        return array_map(fn (mixed $value): mixed => is_array($value) ? $this->canonical($value) : $value, $values);
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
