<?php

namespace Database\Seeders;

use App\Enums\CasePersonRole;
use App\Enums\Role;
use App\Models\CaseAssignment;
use App\Models\CasePerson;
use App\Models\Department;
use App\Models\District;
use App\Models\OffenceCategory;
use App\Models\Person;
use App\Models\PersonIdentity;
use App\Models\Station;
use App\Models\User;
use App\Services\CaseRecords;
use App\Services\Statistics;
use Database\Factories\UserFactory;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with synthetic data only (developer guide
     * §24). Nothing here is a real person, place or case.
     *
     * Demo credentials are only ever created in local development; production
     * accounts must be provisioned individually with `php artisan user:invite`.
     */
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('Demo accounts may only be seeded in local or testing environments.');
        }

        if (blank(config('crms.data_protection.keys'))) {
            throw new RuntimeException('Set the data keys first: run `php artisan security:generate-keys` and add its output to .env.');
        }

        $north = District::factory()->create(['name' => 'Demo North District', 'code' => 'DEMO-N']);
        $south = District::factory()->create(['name' => 'Demo South District', 'code' => 'DEMO-S']);

        $central = Station::factory()->create(['district_id' => $north->id, 'name' => 'Demo Central Station', 'code' => 'DEMO-C']);
        $riverside = Station::factory()->create(['district_id' => $north->id, 'name' => 'Demo Riverside Station', 'code' => 'DEMO-R']);
        $hilltop = Station::factory()->create(['district_id' => $south->id, 'name' => 'Demo Hilltop Station', 'code' => 'DEMO-H']);

        Department::factory()->create(['station_id' => $central->id, 'name' => 'Demo CID Unit']);

        $accounts = [
            ['records', 'Demo Records Officer', Role::RecordsOfficer, $central],
            ['investigator', 'Demo Investigator', Role::InvestigatingOfficer, $central],
            ['commander', 'Demo Station Commander', Role::StationCommander, $central],
            ['supervisor', 'Demo District Supervisor', Role::DistrictSupervisor, $riverside],
            ['national', 'Demo National Officer', Role::NationalOfficer, $hilltop],
            ['quality', 'Demo Quality Reviewer', Role::RecordsQualityReviewer, $central],
            ['auditor', 'Demo Auditor', Role::Auditor, null],
            ['appadmin', 'Demo Application Administrator', Role::ApplicationAdministrator, null],
            ['security', 'Demo Security Administrator', Role::SecurityAdministrator, null],
            ['infrastructure', 'Demo Infrastructure Administrator', Role::InfrastructureAdministrator, null],
        ];

        /** @var array<string, User> $users */
        $users = [];

        foreach ($accounts as [$key, $name, $role, $station]) {
            $factory = User::factory()->withRole($role);

            $users[$key] = ($station === null ? $factory : $factory->atStation($station))->create([
                'name' => $name,
                'email' => "{$key}@crms.test",
                'staff_number' => 'DEMO-'.strtoupper($key),
                'job_title' => $role->label(),
            ]);
        }

        $categories = collect(['Demo category A', 'Demo category B', 'Demo category C', 'Demo category D'])
            ->map(fn (string $name, int $index): OffenceCategory => OffenceCategory::factory()->create([
                'code' => 'DEMO-'.($index + 1),
                'name' => $name,
            ]));

        foreach (range(1, 30) as $number) {
            $station = [$central, $riverside, $hilltop][$number % 3];

            $case = app(CaseRecords::class)->open([
                'offence_category_id' => $categories[$number % 4]->id,
                'incident_date' => now()->subDays($number * 11)->toDateString(),
                'incident_location' => 'Demo location '.$number,
                'narrative' => "Synthetic narrative for demonstration case {$number}. This did not happen.",
            ], $users['records']->setRelation('station', $station));

            $person = Person::factory()->registeredAt($station)->create([
                'given_names' => 'Demo'.$number,
                'family_name' => 'Testperson',
                'created_by' => $users['records']->id,
            ]);

            PersonIdentity::factory()->create(['person_id' => $person->id, 'created_by' => $users['records']->id]);

            CasePerson::factory()->create([
                'case_id' => $case->id,
                'person_id' => $person->id,
                'role' => CasePersonRole::cases()[$number % count(CasePersonRole::cases())],
                'linked_by' => $users['records']->id,
            ]);

            if ($station->is($central) && $number % 2 === 0) {
                CaseAssignment::factory()->create([
                    'case_id' => $case->id,
                    'user_id' => $users['investigator']->id,
                    'assigned_by' => $users['commander']->id,
                ]);
            }
        }

        app(Statistics::class)->refresh();

        $users['records']->unsetRelation('station');

        $this->command?->info('Demo accounts: records, investigator, commander, supervisor, national, quality, auditor, appadmin, security, infrastructure @crms.test');
        $this->command?->info('Password for all of them: '.UserFactory::PASSWORD.' (authenticator enrolment required at first sign-in)');
        $this->command?->warn('The NIN-style numbers and people here are synthetic. Never load real records into this environment.');
    }
}
