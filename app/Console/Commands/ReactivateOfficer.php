<?php

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Models\User;
use App\Services\AccountLifecycle;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('officer:reactivate {email}')]
#[Description('Restore access to a suspended or deactivated officer account')]
class ReactivateOfficer extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AccountLifecycle $accounts): int
    {
        $user = User::query()->where('email', strtolower((string) $this->argument('email')))->first();

        if ($user === null) {
            $this->components->error('No account matches that email address.');

            return self::FAILURE;
        }

        if (! in_array($user->status, [AccountStatus::Suspended, AccountStatus::Deactivated], true)) {
            $this->components->error("This account is {$user->status->label()} and cannot be reactivated.");

            return self::FAILURE;
        }

        $accounts->reactivate($user);

        $this->components->info("{$user->email} is active again.");

        return self::SUCCESS;
    }
}
