<?php

namespace App\Enums;

/**
 * The result of a limited guess at a secret (see AccountAttemptLimiter).
 */
enum AttemptResult
{
    /** The guess was right. */
    case Passed;

    /** The guess was wrong and has been counted. */
    case Failed;

    /** Too many wrong guesses: the guess was not checked. */
    case Locked;
}
