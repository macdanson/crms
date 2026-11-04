<?php

namespace App\Console\Commands\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * For commands that change an account: the audit log must say who ran them
 * (developer guide §12), and it cannot be filled in afterwards. The name is
 * attached to every audit record the command writes (AuditLogger).
 */
trait IdentifiesOperator
{
    /**
     * Take the operator from --by, or ask. Returns false when none is given.
     */
    protected function identifyOperator(): bool
    {
        $operator = trim((string) ($this->option('by') ?: $this->ask('Your name or email address, for the audit log')));

        if ($operator === '') {
            $this->components->error('The audit log needs to know who is running this command. Pass --by.');

            return false;
        }

        Context::add('operator', Str::limit($operator, 255, ''));

        return true;
    }

    /**
     * The operator's own account, when they gave its email address.
     */
    protected function operatorAccount(): ?User
    {
        return User::findByEmail((string) Context::get('operator'));
    }
}
