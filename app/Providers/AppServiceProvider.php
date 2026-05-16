<?php

namespace App\Providers;

use App\Repositories\ClassLevel\ClassLevelRepository;
use App\Repositories\ClassLevel\ClassLevelRepositoryInterface;
use App\Repositories\Session\SessionRepository;
use App\Repositories\Session\SessionRepositoryInterface;
use App\Services\ClassLevelService;
use App\Services\SessionService;
use Carbon\CarbonImmutable;
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
        // Activity log services read their config-backed arrays; bind their
        // ::make() factories so the container can resolve them (and anything
        // that depends on them, e.g. ActivityLogQueryService).
        $this->app->bind(
            \App\Services\ActivityLog\ActivitySensitiveService::class,
            fn () => \App\Services\ActivityLog\ActivitySensitiveService::make(),
        );
        $this->app->bind(
            \App\Services\ActivityLog\ActivitySeverityService::class,
            fn () => \App\Services\ActivityLog\ActivitySeverityService::make(),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
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
            fn(): ?Password => app()->isProduction()
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
