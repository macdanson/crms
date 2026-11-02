<?php

namespace App\Console\Commands;

use App\Services\AccountLifecycle;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

#[Signature('officer:invite
    {email : Official email address of the officer}
    {--name= : Full name}
    {--service-number= : Police force / service number}
    {--rank= : Rank}')]
#[Description('Provision a pending officer account and email a single-use activation link')]
class InviteOfficer extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AccountLifecycle $accounts): int
    {
        $input = [
            'email' => (string) $this->argument('email'),
            'name' => (string) ($this->option('name') ?: $this->ask('Full name')),
            'service_number' => $this->option('service-number') ?: null,
            'rank' => $this->option('rank') ?: null,
        ];

        $validator = Validator::make($input, [
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'service_number' => ['nullable', 'string', 'max:32', Rule::unique('users', 'service_number')->where(
                fn ($query) => $query->where('email', '!=', strtolower($input['email'])),
            )],
            'rank' => ['nullable', 'string', 'max:64'],
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
                'service_number' => $input['service_number'],
                'rank' => $input['rank'],
            ]);
        } catch (ValidationException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Activation link sent to {$user->email}.");

        return self::SUCCESS;
    }
}
