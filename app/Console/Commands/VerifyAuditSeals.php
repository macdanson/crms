<?php

namespace App\Console\Commands;

use App\Enums\AuditOutcome;
use App\Models\AuditEvent;
use App\Services\AuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('audit:verify')]
#[Description('Check that no sealed audit event has been altered')]
class VerifyAuditSeals extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AuditLogger $audit): int
    {
        if (blank(config('crms.audit.seal_key'))) {
            $this->components->error('No audit seal key is configured (CRMS_AUDIT_SEAL_KEY), so nothing can be verified.');

            return self::FAILURE;
        }

        $checked = 0;
        $unsealed = 0;
        $altered = [];

        AuditEvent::query()->lazyById(500)->each(function (AuditEvent $event) use ($audit, &$checked, &$unsealed, &$altered): void {
            if ($event->seal === null) {
                $unsealed++;

                return;
            }

            $checked++;

            if (! hash_equals($event->seal, (string) $audit->seal($event))) {
                $altered[] = $event->id;
            }
        });

        $audit->record('audit.verification.completed', $altered === [] ? AuditOutcome::Success : AuditOutcome::Failure, null, [
            'checked' => $checked,
            'unsealed' => $unsealed,
            'altered' => count($altered),
            'altered_ids' => array_slice($altered, 0, 50),
        ]);

        $this->components->twoColumnDetail('Sealed events checked', (string) $checked);
        $this->components->twoColumnDetail('Events with no seal', (string) $unsealed);

        if ($altered !== []) {
            $this->components->error(count($altered).' audit event(s) do not match their seal:');

            foreach ($altered as $id) {
                $this->line("  {$id}");
            }

            return self::FAILURE;
        }

        $this->components->info('No altered events found.');

        return self::SUCCESS;
    }
}
