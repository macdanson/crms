<?php

namespace App\Console\Commands;

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('access:matrix')]
#[Description('Print the role and permission matrix as Markdown, for institutional review')]
class ShowAccessMatrix extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $roles = Role::cases();

        $this->line('| Permission | '.implode(' | ', array_map(fn (Role $role): string => "{$role->label()} (`{$role->value}`)", $roles)).' |');
        $this->line('| --- |'.str_repeat(' --- |', count($roles)));

        foreach (Permission::cases() as $permission) {
            $cells = array_map(fn (Role $role): string => in_array($permission, $role->permissions(), true) ? 'Yes' : '', $roles);

            $this->line("| `{$permission->value}` | ".implode(' | ', $cells).' |');
        }

        return self::SUCCESS;
    }
}
