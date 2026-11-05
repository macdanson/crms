<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where a record is in its life (§13). Provisional. There is deliberately no
 * "disposed" state or deletion path: lawful disposal needs an approved
 * retention schedule first (§2.2, §13.2).
 */
enum RecordLifecycle: string
{
    use HasOptions;

    case Active = 'active';
    case UnderReview = 'under_review';
    case Restricted = 'restricted';
    case Archived = 'archived';
    case RetentionReviewDue = 'retention_review_due';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::UnderReview => 'Under review',
            self::Restricted => 'Restricted',
            self::Archived => 'Archived',
            self::RetentionReviewDue => 'Retention review due',
        };
    }

    public function isReadOnly(): bool
    {
        return in_array($this, [self::Archived, self::RetentionReviewDue], true);
    }
}
