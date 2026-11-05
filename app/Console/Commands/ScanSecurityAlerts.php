<?php

namespace App\Console\Commands;

use App\Services\SecurityAlerts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('alerts:scan')]
#[Description('Look for suspicious patterns in the audit trail and raise security alerts')]
class ScanSecurityAlerts extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SecurityAlerts $alerts): int
    {
        $this->components->info($alerts->scan().' new alert(s) raised.');

        return self::SUCCESS;
    }
}
