<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccountLifecycle;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

#[Signature('user:invite
    {email : Official email address of the user}
    {--name= : Full name}
    {--staff-number= : Staff or personnel number}
    {--job-title= : Job title}')]
#[Description('Provision a pending user account and email a single-use activation link')]
class InviteUser extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AccountLifecycle $accounts): int
    {
        $input = [
            'email' => User::normaliseEmail((string) $this->argument('email')),
            'name' => (string) ($this->option('name') ?: $this->ask('Full name')),
            'staff_number' => $this->option('staff-number') ?: null,
            'job_title' => $this->option('job-title') ?: null,
        ];

        $validator = Validator::make($input, [
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'staff_number' => ['nullable', 'string', 'max:32', Rule::unique('users', 'staff_number')->where(
                fn ($query) => $query->where('email', '!=', $input['email']),
            )],
            'job_title' => ['nullable', 'string', 'max:64'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        try {
            $user = $accounts->invite([
                'name' => $input['name'],
                'email' => $input['email'],
                'staff_number' => $input['staff_number'],
                'job_title' => $input['job_title'],
            ]);
        } catch (ValidationException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Activation link sent to {$user->email}.");

        return self::SUCCESS;
    }
}
