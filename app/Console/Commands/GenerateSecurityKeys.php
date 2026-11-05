<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('security:generate-keys')]
#[Description('Print new data protection keys for this environment, to store in the approved secret manager')]
class GenerateSecurityKeys extends Command
{
    /**
     * Execute the console command. The keys are only printed: choosing where
     * they live is a decision for key custodians, not for this command.
     */
    public function handle(): int
    {
        $this->line('CRMS_DATA_KEYS=1:'.base64_encode(random_bytes(32)));
        $this->line('CRMS_IDENTIFIER_LOOKUP_KEY='.base64_encode(random_bytes(32)));
        $this->line('CRMS_AUDIT_SEAL_KEY='.base64_encode(random_bytes(32)));
        $this->newLine();
        $this->components->warn('Losing CRMS_DATA_KEYS makes encrypted records unreadable. Changing CRMS_IDENTIFIER_LOOKUP_KEY breaks identifier search until reindexed.');

        return self::SUCCESS;
    }
}
