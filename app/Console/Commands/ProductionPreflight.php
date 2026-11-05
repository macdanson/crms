<?php

namespace App\Console\Commands;

use App\Services\DataProtection;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('ops:preflight')]
#[Description('Check that this environment has the secure settings production requires')]
class ProductionPreflight extends Command
{
    /**
     * Execute the console command. It checks only what the application can see.
     * It is one small part of the release gate (developer guide §25.3), and a
     * pass is not a statement that the system is ready or compliant.
     */
    public function handle(DataProtection $protection): int
    {
        $failures = array_keys(array_filter($this->checks($protection), fn (bool $passed): bool => ! $passed));

        foreach ($this->checks($protection) as $check => $passed) {
            $this->components->twoColumnDetail($check, $passed ? '<fg=green>ok</>' : '<fg=red>FAILED</>');
        }

        if ($failures !== []) {
            $this->components->error(count($failures).' check(s) failed.');

            return self::FAILURE;
        }

        $this->components->info('All application checks passed. This does not replace the release gate.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, bool>
     */
    private function checks(DataProtection $protection): array
    {
        return [
            'APP_ENV is production' => app()->isProduction(),
            'APP_DEBUG is off' => ! config('app.debug'),
            'App URL uses HTTPS' => str_starts_with((string) config('app.url'), 'https://'),
            'Session cookie is secure' => (bool) config('session.secure'),
            'Session cookie is HTTP only' => (bool) config('session.http_only'),
            'Session data is encrypted' => (bool) config('session.encrypt'),
            'Multi-factor authentication is required' => (bool) config('crms.auth.require_mfa'),
            'An idle timeout is set' => (int) config('crms.auth.idle_timeout') > 0,
            'Content Security Policy is on' => (bool) config('crms.security.csp'),
            'Audit seal key is set' => filled(config('crms.audit.seal_key')),
            'Data keys are usable' => $this->dataKeysWork($protection),
            'Identifier lookup key is set' => filled(config('crms.data_protection.lookup_key')),
            'Lookup key is not the app key' => config('crms.data_protection.lookup_key') !== config('app.key'),
            'Documents are malware scanned' => config('crms.documents.scanner') !== 'none',
            'Unscanned documents are refused' => ! config('crms.documents.allow_unscanned'),
            'Jobs run off the request (a queue worker is needed)' => config('queue.default') !== 'sync',
            'Mail is really sent' => ! in_array(config('mail.default'), ['log', 'array'], true),
        ];
    }

    private function dataKeysWork(DataProtection $protection): bool
    {
        try {
            return $protection->decrypt($protection->encrypt('preflight')) === 'preflight';
        } catch (Throwable) {
            return false;
        }
    }
}
