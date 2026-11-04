<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\IdentifiesOperator;
use App\Models\User;
use App\Services\AccountLifecycle;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('user:suspend {email} {--reason= : Why access is being withdrawn} {--by= : Who is running this, for the audit log}')]
#[Description('Suspend a user account and sign out all of its sessions immediately')]
class SuspendUser extends Command
{
    use IdentifiesOperator;

    /**
     * Execute the console command.
     */
    public function handle(AccountLifecycle $accounts): int
    {
        if (! $this->identifyOperator()) {
            return self::FAILURE;
        }

        $user = User::findByEmail((string) $this->argument('email'));

        if ($user === null) {
            $this->components->error('No account matches that email address.');

            return self::FAILURE;
        }

        $reason = (string) ($this->option('reason') ?: $this->ask('Reason for suspension'));

        if (trim($reason) === '') {
            $this->components->error('A reason is required.');

            return self::FAILURE;
        }

        if (mb_strlen($reason) > 255) {
            $this->components->error('The reason can be at most 255 characters.');

            return self::FAILURE;
        }

        $accounts->suspend($user, $reason, $this->operatorAccount());

        $this->components->info("{$user->email} has been suspended.");

        return self::SUCCESS;
    }
}
