<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccountLifecycle;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('officer:suspend {email} {--reason= : Why access is being withdrawn}')]
#[Description('Suspend an officer account and sign out all of its sessions immediately')]
class SuspendOfficer extends Command
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

        $reason = (string) ($this->option('reason') ?: $this->ask('Reason for suspension'));

        if (trim($reason) === '') {
            $this->components->error('A reason is required.');

            return self::FAILURE;
        }

        $accounts->suspend($user, $reason);

        $this->components->info("{$user->email} has been suspended.");

        return self::SUCCESS;
    }
}
