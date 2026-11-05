<?php

namespace App\Console\Commands;

use App\Services\DataExports;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('exports:prune')]
#[Description('Destroy prepared export files that were not collected in time')]
class PruneExports extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DataExports $exports): int
    {
        $this->components->info($exports->expireOverdue().' expired export(s) destroyed.');

        return self::SUCCESS;
    }
}
