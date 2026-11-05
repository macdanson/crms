<?php

namespace App\Enums;

enum CaseEventType: string
{
    case Opened = 'opened';
    case Note = 'note';
    case StatusChanged = 'status_changed';
    case LifecycleChanged = 'lifecycle_changed';
    case AssignmentAdded = 'assignment_added';
    case AssignmentEnded = 'assignment_ended';
    case PersonLinked = 'person_linked';
    case PersonUnlinked = 'person_unlinked';
    case Corrected = 'corrected';
    case CourtRecorded = 'court_recorded';
    case DocumentAdded = 'document_added';

    public function label(): string
    {
        return match ($this) {
            self::Opened => 'Case opened',
            self::Note => 'Progress note',
            self::StatusChanged => 'Status changed',
            self::LifecycleChanged => 'Record lifecycle changed',
            self::AssignmentAdded => 'Officer assigned',
            self::AssignmentEnded => 'Assignment ended',
            self::PersonLinked => 'Person linked',
            self::PersonUnlinked => 'Person unlinked',
            self::Corrected => 'Record corrected',
            self::CourtRecorded => 'Court record added',
            self::DocumentAdded => 'Document added',
        };
    }
}
