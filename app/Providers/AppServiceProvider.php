<?php

namespace App\Providers;

use App\Academics\BillableEnrollmentAdapter;
use App\Finance\Contracts\BillableEnrollmentProvider;
use App\Models\StudentCurriculum;
use App\Models\SubjectResultStatus;
use App\Models\User;
use App\Notifications\Contracts\CallbackTransport;
use App\Notifications\Contracts\Notifier as NotifierContract;
use App\Notifications\Services\HttpCallbackTransport;
use App\Notifications\Services\Notifier as NotifierService;
use App\Notifications\Services\PayloadHydrator;
use App\Notifications\Services\ServiceCallbackSigner;
use App\Observers\StudentCurriculumObserver;
use App\Policies\SubjectResultPolicy;
use App\Services\ActivityLog\ActivitySensitiveService;
use App\Services\ActivityLog\ActivitySeverityService;
use App\Support\ApprovalAbility;
use App\Support\ContactPointAuthority;
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

        // Notifications: the module's single public port. Callers depend on the
        // Contracts interface so module internals stay private (blueprint §9/§10,
        // held by tests/Arch/NotificationsArchTest.php); the binding lives here in
        // the composition root rather than in either module.
        // ⚠️ THE FEED'S NAMES DEPENDED ON THIS BINDING EXISTING, AND IT DID NOT.
        //
        // NotificationFeedController INJECTS a PayloadHydrator and calls hydrate() on
        // it; NotificationFeedResource resolved `app(PayloadHydrator::class)` when
        // rendering each row. Unbound, the container built a FRESH instance for each —
        // so the resource asked an empty map for every name, and every row fell back to
        // the generic string. Nothing threw. The feed simply never said a child's name.
        //
        // `scoped`, NOT `singleton`, because the lifetime of the hydrated map is one
        // request. Stated precisely rather than dramatically: a singleton is not
        // currently a cross-user name leak, because hydrate() now resets its maps
        // unconditionally, so each page's resolution replaces the last. Attempting to
        // bite-prove a leak is what showed that — `singleton` passed every test. What
        // `scoped` buys is that the lifetime matches the data by construction rather
        // than by hydrate()'s internals continuing to reset; the two are one edit apart.
        $this->app->scoped(PayloadHydrator::class);

        $this->app->bind(NotifierContract::class, NotifierService::class);

        // Per-request memo of "is contact_points authoritative yet". `scoped`, not
        // `singleton`: the marker cannot change mid-request, and a process-lifetime
        // memo would leak the first test's answer into every later test.
        $this->app->scoped(ContactPointAuthority::class);

        // The callback transport, bound in the composition root so production gets the
        // signed HTTP path and tests bind a counting double. The signer refuses to
        // construct without a secret — misconfiguration must stop the request, not
        // silently downgrade it to signatures anyone can forge.
        $this->app->bind(
            CallbackTransport::class,
            fn () => new HttpCallbackTransport(
                new ServiceCallbackSigner(config('services.pickup_authorization.callback_secret')),
            ),
        );

        // ACL wiring (composition root): Finance owns the port; the Academics side
        // adapts to it. Binding lives here — not in Finance — so Finance never names
        // a concrete Academics class and the dependency arrow stays one-way
        // (Academics → Finance's contract).
        $this->app->bind(
            BillableEnrollmentProvider::class,
            BillableEnrollmentAdapter::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerSuperAdminGate();
        $this->registerPolicies();

        StudentCurriculum::observe(StudentCurriculumObserver::class);
    }

    /**
     * Grant the team-less `super_admin` role every ability via a Gate::before
     * hook. isSuperAdmin() resolves the role in a null-team context, so this
     * works regardless of the school/team currently active. Kept behind a flag
     * so the bypass can be disabled instantly if it misbehaves (auth.php).
     *
     * EXCEPT checker actions (ADR 0040): any ability whose terminal segment is
     * `approve`/`reject` is never bypassed — approval authority comes only from
     * an explicit grant, never from platform authority. See ApprovalAbility for
     * why this is a convention rather than a list.
     *
     * Returns null (never false) on every miss: Spatie registers its own
     * Gate::before ahead of this one and a `false` from either would silently
     * defeat the other. Excluded abilities therefore fall through to the normal
     * permission resolution rather than being denied here.
     */
    protected function registerSuperAdminGate(): void
    {
        Gate::before(function (User $user, string $ability) {
            if (ApprovalAbility::isExcludedFromSuperAdminBypass($ability)) {
                return null;
            }

            if (config('auth.gate_before_superadmin') && $user->isSuperAdmin()) {
                return true;
            }

            return null;
        });
    }

    /**
     * Policies are registered EXPLICITLY rather than left to Laravel's
     * name-convention discovery: SubjectResultPolicy does not map to
     * SubjectResultStatusPolicy, so discovery would silently find nothing and
     * every authorize() call would fall through to "no policy" — an
     * authorization control that fails open by naming accident.
     */
    protected function registerPolicies(): void
    {
        Gate::policy(SubjectResultStatus::class, SubjectResultPolicy::class);
        // The Ph3 CreditNote policy is registered by App\Finance\FinanceServiceProvider —
        // inside the module, so no non-Finance file names a Finance model (arch boundary).
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
