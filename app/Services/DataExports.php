<?php

namespace App\Services;

use App\Enums\AccessPurpose;
use App\Enums\AuditOutcome;
use App\Enums\CaseStatus;
use App\Enums\ExportStatus;
use App\Enums\Permission;
use App\Jobs\GenerateExport;
use App\Models\AccessJustification;
use App\Models\CriminalCase;
use App\Models\DataExport;
use App\Models\User;
use App\Notifications\ExportDecided;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Controlled exports (developer guide §17): an approved format and fields,
 * record-level access applied at generation, a documented purpose, approval
 * above a threshold, one encrypted download, automatic expiry and a full
 * record of who did what.
 */
class DataExports
{
    public function __construct(
        private AuditLogger $audit,
        private AccessRequests $access,
        private DataProtection $protection,
    ) {}

    /**
     * @param  array{status?: string|null, offence_category_id?: int|null, from?: string|null, to?: string|null}  $filters
     */
    public function request(User $user, array $filters, AccessPurpose $purpose, string $explanation): DataExport
    {
        $filters = array_filter($filters, fn (mixed $value): bool => $value !== null && $value !== '');
        $rowCount = $this->casesFor($user, $filters)->count();

        if ($rowCount === 0) {
            throw ValidationException::withMessages(['filters' => 'No cases you can access match these filters.']);
        }

        $needsApproval = $rowCount > (int) config('crms.exports.approval_threshold');

        $export = DB::transaction(function () use ($user, $filters, $purpose, $explanation, $rowCount, $needsApproval): DataExport {
            $justification = $this->access->recordPurpose($user, AccessJustification::ACTION_EXPORT, $purpose, explanation: $explanation);

            $export = new DataExport;
            $export->forceFill([
                'requested_by' => $user->getKey(),
                'justification_id' => $justification->id,
                'filters' => $filters,
                'fields' => config('crms.exports.fields'),
                'row_count' => $rowCount,
                'status' => $needsApproval ? ExportStatus::PendingApproval : ExportStatus::Approved,
            ])->save();

            $this->audit->record('export.requested', AuditOutcome::Success, $user, [
                'filters' => $filters,
                'fields' => $export->fields,
                'row_count' => $rowCount,
                'purpose' => $purpose->value,
                'needs_approval' => $needsApproval,
            ], $export);

            return $export;
        });

        if (! $needsApproval) {
            GenerateExport::dispatch($export->id);
        }

        return $export;
    }

    public function decide(DataExport $export, bool $approve, string $note, User $actor): void
    {
        DB::transaction(function () use ($export, $approve, $note, $actor): void {
            $locked = DataExport::query()->whereKey($export->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ExportStatus::PendingApproval) {
                throw ValidationException::withMessages(['note' => 'This export has already been decided.']);
            }

            $export->forceFill([
                'status' => $approve ? ExportStatus::Approved : ExportStatus::Rejected,
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();

            $this->audit->record($approve ? 'export.approved' : 'export.rejected', AuditOutcome::Success, $actor, ['note' => $note, 'requester_id' => $export->requested_by], $export);
        });

        $export->requester->notify(new ExportDecided($approve));

        if ($approve) {
            GenerateExport::dispatch($export->id);
        }
    }

    /**
     * Prepare the file, with the requester's access as it is now: an export
     * cannot reach anything they can no longer see (§7.4, §25.2).
     */
    public function generate(DataExport $export): void
    {
        $requester = $export->requester;

        if (! $requester->hasPermission(Permission::ExportsRequest)) {
            $export->forceFill(['status' => ExportStatus::Failed])->save();

            $this->audit->record('export.refused', AuditOutcome::Blocked, $requester, ['reason' => 'requester_no_longer_authorised'], $export);

            return;
        }

        $rows = $this->casesFor($requester, $export->filters)
            ->with(['station:id,name', 'district:id,name', 'offenceCategory:id,name'])
            ->orderBy('reference')
            ->get();

        $handle = fopen('php://temp', 'r+b');

        if ($handle === false) {
            throw new RuntimeException('Could not open a temporary stream for the export.');
        }

        // A watermark, so a copy that travels says whose it was (§17.1).
        fputcsv($handle, ["Restricted export for {$requester->name} ({$requester->id}), ".now()->toIso8601String().". Export {$export->id}"], escape: '');
        fputcsv($handle, $export->fields, escape: '');

        foreach ($rows as $case) {
            fputcsv($handle, array_map(fn (string $field): string => $this->neutraliseFormula($this->fieldValue($case, $field)), $export->fields), escape: '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        $storageKey = 'exports/'.Str::random(48);

        Storage::disk((string) config('crms.documents.disk'))->put($storageKey, $this->protection->encrypt($csv));

        $export->forceFill([
            'status' => ExportStatus::Ready,
            'row_count' => $rows->count(),
            'storage_key' => $storageKey,
            'generated_at' => now(),
            'expires_at' => now()->addHours((int) config('crms.exports.expiry_hours')),
        ])->save();

        $this->audit->record('export.generated', AuditOutcome::Success, $requester, ['row_count' => $rows->count()], $export);
    }

    /**
     * Give the file to the requester, once. The claim is one conditional update,
     * so two requests at the same moment cannot both get it.
     *
     * @return string|null The CSV, or null when it can no longer be collected.
     */
    public function collect(DataExport $export, User $user): ?string
    {
        $claimed = DataExport::query()
            ->whereKey($export->id)
            ->where('status', ExportStatus::Ready)
            ->where('expires_at', '>', now())
            ->update(['status' => ExportStatus::Downloaded, 'downloaded_at' => now(), 'updated_at' => now()]);

        if ($claimed !== 1) {
            return null;
        }

        $disk = Storage::disk((string) config('crms.documents.disk'));
        $csv = $this->protection->decrypt((string) $disk->get((string) $export->storage_key));

        $disk->delete((string) $export->storage_key);

        $this->audit->record('export.downloaded', AuditOutcome::Success, $user, ['row_count' => $export->row_count], $export);

        return $csv;
    }

    /**
     * Destroy prepared files that were not collected in time.
     *
     * @return int How many were destroyed.
     */
    public function expireOverdue(): int
    {
        $overdue = DataExport::query()
            ->where('status', ExportStatus::Ready)
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($overdue as $export) {
            Storage::disk((string) config('crms.documents.disk'))->delete((string) $export->storage_key);

            $export->forceFill(['status' => ExportStatus::Expired])->save();

            $this->audit->record('export.expired', AuditOutcome::Success, null, record: $export);
        }

        return $overdue->count();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<CriminalCase>
     */
    private function casesFor(User $user, array $filters): Builder
    {
        return CriminalCase::query()
            ->visibleTo($user)
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', CaseStatus::from($status)))
            ->when($filters['offence_category_id'] ?? null, fn (Builder $query, int|string $id) => $query->where('offence_category_id', $id))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->whereDate('incident_date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->whereDate('incident_date', '<=', $to));
    }

    private function fieldValue(CriminalCase $case, string $field): string
    {
        return match ($field) {
            'reference' => $case->reference,
            'station' => $case->station->name,
            'district' => $case->district->name,
            'category' => $case->offenceCategory->name,
            'status' => $case->status->label(),
            'incident_date' => $case->incident_date->toDateString(),
            default => '',
        };
    }

    /**
     * Keep a spreadsheet from running a cell's text as a formula.
     */
    private function neutraliseFormula(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'{$value}" : $value;
    }
}
