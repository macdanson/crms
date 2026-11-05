<?php

namespace App\Console\Commands;

use App\Enums\AuditOutcome;
use App\Models\CriminalCase;
use App\Models\Document;
use App\Models\Person;
use App\Models\PersonAddress;
use App\Models\PersonContact;
use App\Models\PersonIdentity;
use App\Services\AuditLogger;
use App\Services\DocumentStore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Migrations\Migrator;
use Throwable;

#[Signature('backup:verify-restore {--sample=200 : How many rows of each kind to check}')]
#[Description('After restoring a backup, prove the data is usable: schema, audit seals, decryption and documents')]
class VerifyRestoredData extends Command
{
    /**
     * Execute the console command. Taking and storing backups belongs to the
     * infrastructure, under separate credentials (developer guide §21.1); this
     * is the part only the application can do, because a backup is not proven
     * until it has been restored and validated.
     */
    public function handle(Migrator $migrator, DocumentStore $documents, AuditLogger $audit): int
    {
        $failures = [];

        $pending = array_diff(
            array_map(fn (string $file): string => $migrator->getMigrationName($file), array_values($migrator->getMigrationFiles(database_path('migrations')))),
            $migrator->getRepository()->getRan(),
        );

        if ($pending !== []) {
            $failures[] = count($pending).' migration(s) have not run.';
        }

        if ($this->call('audit:verify') !== self::SUCCESS) {
            $failures[] = 'The audit trail did not verify.';
        }

        $sample = max(1, (int) $this->option('sample'));

        $failures = [
            ...$failures,
            ...$this->checkDecryption('case narratives', CriminalCase::query(), 'narrative_ciphertext', $sample),
            ...$this->checkDecryption('dates of birth', Person::query()->whereNotNull('date_of_birth_ciphertext'), 'date_of_birth_ciphertext', $sample),
            ...$this->checkDecryption('identity numbers', PersonIdentity::query(), 'identifier_ciphertext', $sample),
            ...$this->checkDecryption('contact details', PersonContact::query(), 'value_ciphertext', $sample),
            ...$this->checkDecryption('addresses', PersonAddress::query(), 'address_ciphertext', $sample),
            ...$this->checkDocuments($documents, $sample),
        ];

        $audit->record('backup.restore_verified', $failures === [] ? AuditOutcome::Success : AuditOutcome::Failure, null, [
            'failures' => $failures,
            'sample' => $sample,
        ]);

        foreach ($failures as $failure) {
            $this->components->error($failure);
        }

        if ($failures !== []) {
            return self::FAILURE;
        }

        $this->components->info('The restored data verified.');

        return self::SUCCESS;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return list<string>
     */
    private function checkDecryption(string $label, Builder $query, string $attribute, int $sample): array
    {
        $unreadable = 0;
        $rows = (clone $query)->inRandomOrder()->limit($sample)->get();

        foreach ($rows as $row) {
            try {
                $row->getAttribute($attribute);
            } catch (Throwable) {
                $unreadable++;
            }
        }

        $this->components->twoColumnDetail("Checked {$label}", (string) $rows->count());

        return $unreadable === 0 ? [] : ["{$unreadable} of {$rows->count()} sampled {$label} could not be decrypted."];
    }

    /**
     * @return list<string>
     */
    private function checkDocuments(DocumentStore $documents, int $sample): array
    {
        $bad = 0;
        $rows = Document::query()->inRandomOrder()->limit($sample)->get();

        foreach ($rows as $document) {
            try {
                if (hash('sha256', $documents->contents($document)) !== $document->sha256) {
                    $bad++;
                }
            } catch (Throwable) {
                $bad++;
            }
        }

        $this->components->twoColumnDetail('Checked documents', (string) $rows->count());

        return $bad === 0 ? [] : ["{$bad} of {$rows->count()} sampled documents are missing, unreadable or changed."];
    }
}
