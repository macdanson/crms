<?php

namespace App\Services;

/**
 * A keyed one-way hash (HMAC-SHA256 with the app key) for values that must
 * be linkable but never stored as they are: session IDs, mistyped email
 * addresses in the audit log.
 */
class KeyedHash
{
    public function of(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
