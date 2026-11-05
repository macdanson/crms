<?php

namespace App\Console\Commands;

use App\Services\Statistics;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('statistics:refresh')]
#[Description('Rebuild the de-identified statistics from the case records')]
class RefreshStatistics extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(Statistics $statistics): int
    {
        $this->components->info($statistics->refresh().' aggregate row(s) written.');

        return self::SUCCESS;
    }
}
