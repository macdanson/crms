<?php

namespace App\Http\Controllers\Reports;

use App\Enums\AuditOutcome;
use App\Enums\CaseStatus;
use App\Http\Controllers\Controller;
use App\Models\CriminalCase;
use App\Models\OffenceCategory;
use App\Models\Station;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Statistics;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboards (developer guide §27.8). Operational reports count only what the
 * user may see. Statistics come from the de-identified layer. Neither ever
 * lists a person.
 */
class ReportController extends Controller
{
    public function operational(Request $request, AuditLogger $audit): Response
    {
        /** @var User $user */
        $user = $request->user();

        $audit->record('report.operational.viewed', AuditOutcome::Success, $user);

        $stations = Station::query()->pluck('name', 'id');
        $categories = OffenceCategory::query()->pluck('name', 'id');

        $since = now()->subMonths(11)->startOfMonth();
        $byMonth = [];

        foreach (CriminalCase::query()->visibleTo($user)->where('incident_date', '>=', $since)->select('incident_date')->toBase()->cursor() as $case) {
            $month = substr((string) $case->incident_date, 0, 7);
            $byMonth[$month] = ($byMonth[$month] ?? 0) + 1;
        }

        ksort($byMonth);

        return Inertia::render('reports/Index', [
            'total' => CriminalCase::query()->visibleTo($user)->count(),
            'open' => CriminalCase::query()->visibleTo($user)->where('status', '!=', CaseStatus::ClosedAdministratively)->count(),
            'byStation' => $this->countsBy($user, 'station_id')->map(fn (object $row): array => ['label' => (string) $stations->get($row->dimension, 'Unknown'), 'count' => (int) $row->total]),
            'byCategory' => $this->countsBy($user, 'offence_category_id')->map(fn (object $row): array => ['label' => (string) $categories->get($row->dimension, 'Unknown'), 'count' => (int) $row->total]),
            'byStatus' => $this->countsBy($user, 'status')->map(fn (object $row): array => ['label' => CaseStatus::from($row->dimension)->label(), 'count' => (int) $row->total]),
            'byMonth' => collect($byMonth)->map(fn (int $count, string $month): array => ['label' => $month, 'count' => $count])->values(),
        ]);
    }

    /**
     * @param  'station_id'|'offence_category_id'|'status'  $column  A fixed name, never user input.
     * @return Collection<int, object{dimension: mixed, total: mixed}>
     */
    private function countsBy(User $user, string $column): Collection
    {
        /** @var Collection<int, object{dimension: mixed, total: mixed}> */
        return CriminalCase::query()
            ->visibleTo($user)
            ->selectRaw("{$column} as dimension, COUNT(*) as total")
            ->groupBy($column)
            ->orderByDesc('total')
            ->toBase()
            ->get();
    }

    public function statistics(Request $request, Statistics $statistics, AuditLogger $audit): Response
    {
        $audit->record('report.statistics.viewed', AuditOutcome::Success, $request->user());

        return Inertia::render('reports/Statistics', $statistics->summary());
    }
}
