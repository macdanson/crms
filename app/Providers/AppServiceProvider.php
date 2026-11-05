<?php

namespace App\Providers;

use App\Contracts\MalwareScanner;
use App\Enums\Permission;
use App\Models\User;
use App\Services\Scanners\ClamdScanner;
use App\Services\Scanners\NoScanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MalwareScanner::class, function (): MalwareScanner {
            /** @var array{scanner: string, clamd_host: string, clamd_port: int} $config */
            $config = config('crms.documents');

            return $config['scanner'] === 'none'
                ? new NoScanner
                : new ClamdScanner($config['clamd_host'], $config['clamd_port']);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->definePermissionGates();
    }

    /**
     * One gate per permission, for checks that do not depend on a record (the
     * `can:` route middleware). There is deliberately no Gate::before bypass:
     * no role, however senior, skips a policy (developer guide §7, §1.1).
     */
    protected function definePermissionGates(): void
    {
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user): bool => $user->hasPermission($permission));
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // One password policy for every environment (developer guide §6.1, §23).
        // Breached-password screening calls an external k-anonymity API, so it
        // is limited to production where outbound access is approved.
        Password::defaults(function (): Password {
            // The upper limit is in bytes (bcrypt uses only the first 72); see
            // PasswordValidationRules::fitsPasswordHash().
            $rule = Password::min(12)
                ->letters()
                ->mixedCase()
                ->numbers()
                ->symbols();

            return app()->isProduction() ? $rule->uncompromised() : $rule;
        });
    }
}
