<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\IdentifiesOperator;
use App\Enums\AccountStatus;
use App\Models\User;
use App\Services\AccountLifecycle;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('user:reactivate {email} {--by= : Who is running this, for the audit log}')]
#[Description('Restore access to a suspended or deactivated user account')]
class ReactivateUser extends Command
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

        if (! in_array($user->status, [AccountStatus::Suspended, AccountStatus::Deactivated], true)) {
            $this->components->error("This account is {$user->status->label()} and cannot be reactivated.");

            return self::FAILURE;
        }

        $status = $accounts->reactivate($user, $this->operatorAccount());

        $this->components->info(match ($status) {
            AccountStatus::Pending => "{$user->email} is no longer suspended. The account was never activated, so they still need to use their activation link.",
            AccountStatus::Deactivated => "{$user->email} is no longer suspended, but it was deactivated before that and stays deactivated. Run this again to restore it.",
            default => "{$user->email} is active again.",
        });

        return self::SUCCESS;
    }
}
