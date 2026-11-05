<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\IdentifiesOperator;
use App\Enums\Role;
use App\Models\User;
use App\Services\UserRoles;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

#[Signature('user:grant-role
    {email : Email address of the account}
    {role : The role, as printed by access:matrix}
    {--reason= : Why the role is being granted, for the audit log}
    {--expires= : When the grant ends (any date PHP understands)}
    {--by= : Who is running this, for the audit log}')]
#[Description('Grant a role to a user (how the first Security Administrator is appointed)')]
class GrantUserRole extends Command
{
    use IdentifiesOperator;

    /**
     * Execute the console command.
     */
    public function handle(UserRoles $roles): int
    {
        if (! $this->identifyOperator()) {
            return self::FAILURE;
        }

        $user = User::findByEmail((string) $this->argument('email'));
        $role = Role::tryFrom((string) $this->argument('role'));
        $reason = trim((string) $this->option('reason'));

        if ($user === null || $role === null || $reason === '') {
            $this->components->error('Give an existing account, a known role and a --reason.');

            return self::FAILURE;
        }

        try {
            $roles->grant(
                $user,
                $role,
                $reason,
                $this->operatorAccount(),
                $this->option('expires') ? Carbon::parse((string) $this->option('expires')) : null,
            );
        } catch (ValidationException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("{$role->label()} granted to {$user->email}.");

        return self::SUCCESS;
    }
}
