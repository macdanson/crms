<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRequestMacros();
    }

    /**
     * Request helpers shared by middleware and the exception handler.
     */
    protected function configureRequestMacros(): void
    {
        // API routes and plain fetch/XHR clients get JSON. Inertia visits also
        // send Accept: application/json but expect redirects and pages.
        Request::macro('wantsJsonResponse', function (): bool {
            /** @var Request $this */
            return $this->is('api/*') || ($this->expectsJson() && ! $this->header('X-Inertia'));
        });
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
            $rule = Password::min(12)
                ->max(128)
                ->letters()
                ->mixedCase()
                ->numbers()
                ->symbols();

            return app()->isProduction() ? $rule->uncompromised() : $rule;
        });
    }
}
