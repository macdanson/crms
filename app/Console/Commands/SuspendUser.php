<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccountLifecycle;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('user:suspend {email} {--reason= : Why access is being withdrawn}')]
#[Description('Suspend a user account and sign out all of its sessions immediately')]
class SuspendUser extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AccountLifecycle $accounts): int
    {
        $user = User::query()->where('email', User::normaliseEmail((string) $this->argument('email')))->first();

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
