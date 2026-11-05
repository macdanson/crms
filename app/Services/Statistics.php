<?php

namespace App\Services;

use App\Enums\AuditOutcome;
use App\Enums\CaseStatus;
use App\Enums\CourtOutcomeType;
use App\Enums\OutcomeVerification;
use App\Models\CourtOutcome;
use App\Models\CriminalCase;
use App\Models\District;
use App\Models\OffenceCategory;
use App\Models\StatisticSnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The de-identified statistics layer (developer guide §18): an approved
 * extraction into aggregate counts, read back with small counts suppressed.
 *
 * Nothing here can name a person. Suppression reduces the risk of identifying
 * someone from a rare combination, but it does not replace the
 * re-identification review needed before anything is published or shared.
 */
class Statistics
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Rebuild the aggregates from the case records, in one transaction so
     * readers never see a half-built set.
     *
     * @return int How many aggregate rows were written.
     */
    public function refresh(): int
    {
        $cells = [];

        foreach (CriminalCase::query()->select(['incident_date', 'district_id', 'offence_category_id', 'status'])->toBase()->cursor() as $case) {
            $key = implode('|', [substr((string) $case->incident_date, 0, 7), $case->district_id, $case->offence_category_id, $case->status]);
            $cells[$key] = ($cells[$key] ?? 0) + 1;
        }

        $now = now();

        $rows = collect($cells)->map(function (int $count, string $key) use ($now): array {
            [$month, $districtId, $categoryId, $status] = explode('|', $key);

            return [
                'period' => "{$month}-01",
                'district_id' => (int) $districtId,
                'offence_category_id' => (int) $categoryId,
                'status' => $status,
                'case_count' => $count,
                'generated_at' => $now,
            ];
        })->values();

        DB::transaction(function () use ($rows): void {
            StatisticSnapshot::query()->delete();

            foreach ($rows->chunk(500) as $chunk) {
                StatisticSnapshot::query()->insert($chunk->all());
            }
        });

        $this->audit->record('statistics.refreshed', AuditOutcome::Success, null, ['rows' => $rows->count()]);

        return $rows->count();
    }

    /**
     * Counts by each dimension. There is no grand total, and each table is
     * suppressed separately, so one cannot be worked out from another.
     *
     * @return array{generated_at: string|null, threshold: int, by_district: list<array{label: string, count: int|null}>, by_category: list<array{label: string, count: int|null}>, by_status: list<array{label: string, count: int|null}>, by_month: list<array{label: string, count: int|null}>, by_outcome: list<array{label: string, count: int|null}>}
     */
    public function summary(): array
    {
        $districts = District::query()->pluck('name', 'id');
        $categories = OffenceCategory::query()->pluck('name', 'id');

        return [
            'generated_at' => StatisticSnapshot::query()->max('generated_at'),
            'threshold' => $this->threshold(),
            'by_district' => $this->dimension('district_id', fn (mixed $id): string => (string) $districts->get($id, 'Unknown')),
            'by_category' => $this->dimension('offence_category_id', fn (mixed $id): string => (string) $categories->get($id, 'Unknown')),
            'by_status' => $this->dimension('status', fn (mixed $status): string => CaseStatus::from((string) $status)->label()),
            'by_month' => $this->dimension('period', fn (mixed $period): string => substr((string) $period, 0, 7), sortByLabel: true),
            'by_outcome' => $this->verifiedOutcomes(),
        ];
    }

    /**
     * Hide every count below the threshold, then one more if only one was
     * hidden: otherwise it could be worked out by subtraction from the others.
     *
     * @param  Collection<int, array{label: string, count: int}>  $rows
     * @return list<array{label: string, count: int|null}>
     */
    public function suppress(Collection $rows): array
    {
        $threshold = $this->threshold();
        $suppressed = $rows->filter(fn (array $row): bool => $row['count'] < $threshold)->keys()->all();

        if (count($suppressed) === 1 && $rows->count() > 1) {
            $suppressed[] = $rows->except($suppressed)->sortBy('count')->keys()->first();
        }

        return array_values($rows
            ->map(fn (array $row, int $index): array => ['label' => $row['label'], 'count' => in_array($index, $suppressed, true) ? null : $row['count']])
            ->all());
    }

    /**
     * @param  'district_id'|'offence_category_id'|'status'|'period'  $column
     * @param  callable(mixed): string  $label
     * @return list<array{label: string, count: int|null}>
     */
    private function dimension(string $column, callable $label, bool $sortByLabel = false): array
    {
        $rows = StatisticSnapshot::query()
            ->selectRaw("{$column} as dimension, SUM(case_count) as total")
            ->groupBy($column)
            ->get()
            ->map(fn (StatisticSnapshot $row): array => ['label' => $label($row->getAttribute('dimension')), 'count' => (int) $row->getAttribute('total')]);

        $rows = ($sortByLabel ? $rows->sortBy('label') : $rows->sortByDesc('count'))->values();

        return $this->suppress($rows);
    }

    /**
     * Only verified outcomes count (§4.3).
     *
     * @return list<array{label: string, count: int|null}>
     */
    private function verifiedOutcomes(): array
    {
        $rows = CourtOutcome::query()
            ->where('verification_status', OutcomeVerification::Verified)
            ->selectRaw('outcome, COUNT(*) as total')
            ->groupBy('outcome')
            ->toBase()
            ->get()
            ->map(fn (object $row): array => ['label' => CourtOutcomeType::from($row->outcome)->label(), 'count' => (int) $row->total])
            ->sortByDesc('count')
            ->values();

        return $this->suppress($rows);
    }

    private function threshold(): int
    {
        return (int) config('crms.statistics.suppression_threshold');
    }
}
