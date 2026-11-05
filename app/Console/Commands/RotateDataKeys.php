<?php

namespace App\Console\Commands;

use App\Enums\AuditOutcome;
use App\Models\Document;
use App\Services\AuditLogger;
use App\Services\DataProtection;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

#[Signature('security:rotate-data-keys {--by= : Who is running this, for the audit log}')]
#[Description('Re-encrypt stored values and documents under the newest data key')]
class RotateDataKeys extends Command
{
    /**
     * Every column holding a value encrypted by DataProtection.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const PROTECTED_COLUMNS = [
        ['people', 'date_of_birth_ciphertext'],
        ['person_identities', 'identifier_ciphertext'],
        ['person_contacts', 'value_ciphertext'],
        ['person_addresses', 'address_ciphertext'],
        ['criminal_cases', 'narrative_ciphertext'],
        ['investigation_events', 'summary_ciphertext'],
        ['record_corrections', 'previous_value_ciphertext'],
        ['record_corrections', 'new_value_ciphertext'],
    ];

    /**
     * Execute the console command. Add the new key to CRMS_DATA_KEYS first and
     * keep the old one until this has finished and been verified. It can run
     * again safely: values already on the newest key are skipped. The rows are
     * updated directly because the content does not change, and history tables
     * are append-only only in what they say, not in how it is encrypted.
     */
    public function handle(DataProtection $protection, AuditLogger $audit): int
    {
        $current = 'v'.$protection->currentKeyVersion().':';
        $rotated = 0;

        foreach (self::PROTECTED_COLUMNS as [$table, $column]) {
            $rotated += $this->rotateColumn($protection, $table, $column, $current);
        }

        $disk = Storage::disk((string) config('crms.documents.disk'));

        Document::query()->chunkById(100, function ($documents) use ($protection, $disk, &$rotated): void {
            foreach ($documents as $document) {
                $stored = (string) $disk->get($document->storage_key);

                if ($protection->needsRotation($stored)) {
                    $disk->put($document->storage_key, $protection->encrypt($protection->decrypt($stored)));
                    $rotated++;
                }
            }
        });

        $audit->record('security.data_keys.rotated', AuditOutcome::Success, null, [
            'operator' => $this->option('by') ?: get_current_user(),
            'key_version' => $protection->currentKeyVersion(),
            'values_rotated' => $rotated,
        ]);

        $this->components->info("{$rotated} value(s) re-encrypted. Run backup:verify-restore before retiring the old key.");

        return self::SUCCESS;
    }

    private function rotateColumn(DataProtection $protection, string $table, string $column, string $current): int
    {
        $rotated = 0;

        DB::table($table)
            ->whereNotNull($column)
            ->where($column, 'not like', $current.'%')
            ->select(['id', $column])
            ->lazyById(200)
            ->each(function (object $row) use ($protection, $table, $column, &$rotated): void {
                DB::table($table)->where('id', $row->id)->update([
                    $column => $protection->encrypt($protection->decrypt($row->{$column})),
                ]);

                $rotated++;
            });

        return $rotated;
    }
}
