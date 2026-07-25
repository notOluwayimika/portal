<?php

namespace App\Finance;

use App\Finance\Models\CreditNote;
use App\Finance\Policies\CreditNotePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * The Finance module's own service provider. It lives INSIDE app/Finance precisely so the
 * module can wire up its models/policies without a non-Finance file naming a Finance model
 * (arch: App\Finance\Models is private to App\Finance) — the composition root (AppServiceProvider)
 * only binds the cross-module ACL port, never a concrete Finance class.
 */
class FinanceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Ph3 maker-checker — explicit registration (App\Finance\Models\CreditNote does not
        // map to a discoverable App\Policies name, and lives outside the default namespace).
        // approve/reject run for super_admin too (ApprovalAbility excludes them from the
        // Gate::before bypass), so this Policy actually decides maker ≠ checker.
        Gate::policy(CreditNote::class, CreditNotePolicy::class);
    }
}
