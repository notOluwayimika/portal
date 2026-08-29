<?php

namespace App\Finance;

use App\Finance\Models\CreditNote;
use App\Finance\Models\VoidRequest;
use App\Finance\Policies\CreditNotePolicy;
use App\Finance\Policies\VoidRequestPolicy;
use App\Finance\Services\PaystackClient;
use App\Finance\Services\PaystackSignature;
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
    /**
     * Paystack's two halves, bound HERE and deliberately not in AppServiceProvider.
     *
     * The first draft bound them in the composition root and `--group=arch` refused it:
     * `App\Finance\Services` is private to `App\Finance`, so a provider outside the module cannot
     * name a concrete Finance service. That rule is right and the fix is not an exemption — the
     * module wires its own internals, exactly as this file's docblock already says it exists to do.
     * If a non-Finance caller ever needs the gateway, it gets a Contract, not this class.
     *
     * BOTH READ THE SAME SECRET, which is not duplication: Paystack signs webhooks with the API
     * secret, so the outbound credential and the inbound forgery check are one value. Binding rather
     * than calling `config()` inside the classes keeps them constructible with an explicit key in a
     * test, and makes a missing key fail at RESOLUTION with a legible message instead of as a 401
     * from the provider that reads like an outage.
     */
    public function register(): void
    {
        $this->app->bind(PaystackClient::class, fn () => new PaystackClient(
            config('services.paystack.secret_key'),
            (string) config('services.paystack.base_url'),
        ));

        $this->app->bind(PaystackSignature::class, fn () => new PaystackSignature(
            config('services.paystack.secret_key'),
        ));
    }

    public function boot(): void
    {
        // Ph3 maker-checker — explicit registration (App\Finance\Models\CreditNote does not
        // map to a discoverable App\Policies name, and lives outside the default namespace).
        // approve/reject run for super_admin too (ApprovalAbility excludes them from the
        // Gate::before bypass), so this Policy actually decides maker ≠ checker.
        Gate::policy(CreditNote::class, CreditNotePolicy::class);

        // Ph3b — the second maker-checker instance, same rationale as above.
        Gate::policy(VoidRequest::class, VoidRequestPolicy::class);
    }
}
