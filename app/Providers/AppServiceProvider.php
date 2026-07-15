<?php

namespace App\Providers;

use App\Models\StudentCurriculum;
use App\Models\User;
use App\Observers\StudentCurriculumObserver;
use App\Services\ActivityLog\ActivitySensitiveService;
use App\Services\ActivityLog\ActivitySeverityService;
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
        // Activity log services read their config-backed arrays; bind their
        // ::make() factories so the container can resolve them (and anything
        // that depends on them, e.g. ActivityLogQueryService).
        $this->app->bind(
            ActivitySensitiveService::class,
            fn () => ActivitySensitiveService::make(),
        );
        $this->app->bind(
            ActivitySeverityService::class,
            fn () => ActivitySeverityService::make(),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerSuperAdminGate();

        StudentCurriculum::observe(StudentCurriculumObserver::class);
    }

    /**
     * Grant the team-less `super_admin` role every ability via a Gate::before
     * hook. isSuperAdmin() resolves the role in a null-team context, so this
     * works regardless of the school/team currently active. Kept behind a flag
     * so the bypass can be disabled instantly if it misbehaves (auth.php).
     */
    protected function registerSuperAdminGate(): void
    {
        Gate::before(function (User $user, string $ability) {
            if (config('auth.gate_before_superadmin') && $user->isSuperAdmin()) {
                return true;
            }

            return null;
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

        Password::defaults(
            fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
