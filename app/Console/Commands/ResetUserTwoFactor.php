<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccountLifecycle;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('user:reset-mfa {email}')]
#[Description('Clear a user\'s authenticator (e.g. lost phone) so they must enrol again at next sign-in')]
class ResetUserTwoFactor extends Command
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

        if (! $this->confirm("Reset the authenticator for {$user->email}? Their sessions will be signed out.", true)) {
            return self::FAILURE;
        }

        $accounts->resetTwoFactor($user, actor: null);

        $this->components->info('Authenticator cleared. The user will be asked to enrol again at next sign-in.');

        return self::SUCCESS;
    }
}
