<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\IdentifiesOperator;
use App\Enums\Role;
use App\Models\User;
use App\Services\UserRoles;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

#[Signature('user:revoke-role
    {email : Email address of the account}
    {role : The role, as printed by access:matrix}
    {--reason= : Why the role is being revoked, for the audit log}
    {--by= : Who is running this, for the audit log}')]
#[Description('Revoke a role from a user')]
class RevokeUserRole extends Command
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
        $assignment = $role === null ? null : $user?->roleAssignments()->active()->where('role', $role)->first();

        if ($assignment === null || $reason === '') {
            $this->components->error('Give an account holding that role and a --reason.');

            return self::FAILURE;
        }

        try {
            $roles->revoke($assignment, $reason, $this->operatorAccount());
        } catch (ValidationException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Role revoked from {$user->email}.");

        return self::SUCCESS;
    }
}
