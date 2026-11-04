<?php

namespace App\Actions\Fortify;

use Closure;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords.
     *
     * The strength policy itself is defined once in AppServiceProvider via
     * Password::defaults() so every password form enforces the same rules.
     *
     * @return array<int, Rule|Closure|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        // Validation stops at the first failure, and the policy (with its
        // breach lookup) comes last: a password that is too long, or whose
        // confirmation does not match, is never looked up.
        return ['bail', 'required', 'string', $this->fitsPasswordHash(...), 'confirmed', Password::default()];
    }

    /**
     * bcrypt ignores everything after the first 72 bytes, so a longer
     * password would be weaker than it looks. Accented letters and symbols
     * can take several bytes each, so this is checked in bytes.
     */
    protected function fitsPasswordHash(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && strlen($value) > 72) {
            $fail('The password is too long. Use at most 72 characters, or fewer if it includes accented letters or symbols.');
        }
    }
}
