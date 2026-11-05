<?php

namespace App\Services;

use App\Enums\AuditOutcome;
use App\Models\RecordCorrection;
use App\Models\User;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Applies a factual correction to a record through a controlled workflow
 * (developer guide §13.1): who, when, why and what it said before, so material
 * history is never silently overwritten. Status and court outcome changes are
 * not corrections and have their own workflows.
 */
class RecordCorrections
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $changes  New values by attribute. Unchanged values are ignored.
     * @return list<string> The attributes that changed.
     */
    public function apply(Model $record, array $changes, string $reason, User $actor): array
    {
        return DB::transaction(function () use ($record, $changes, $reason, $actor): array {
            $changed = [];

            foreach ($changes as $field => $value) {
                $previous = $this->asText($record->getAttribute($field));
                $new = $this->asText($value);

                if ($previous === $new) {
                    continue;
                }

                $correction = new RecordCorrection;
                $correction->forceFill([
                    'record_type' => $record->getMorphClass(),
                    'record_id' => (string) $record->getKey(),
                    'field' => $field,
                    'previous_value_ciphertext' => $previous,
                    'new_value_ciphertext' => $new,
                    'reason' => $reason,
                    'corrected_by' => $actor->getKey(),
                    'corrected_at' => now(),
                ])->save();

                $record->setAttribute($field, $value);
                $changed[] = $field;
            }

            if ($changed !== []) {
                $record->save();

                // Field names only: the values may be as sensitive as the record.
                $this->audit->record('record.corrected', AuditOutcome::Success, $actor, [
                    'fields' => $changed,
                    'reason' => $reason,
                ], $record);
            }

            return $changed;
        });
    }

    private function asText(mixed $value): ?string
    {
        return match (true) {
            $value === null, $value === '' => null,
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            default => (string) $value,
        };
    }
}
