<?php

use App\Finance\Models\CreditNote;
use App\Finance\Models\Invoice;
use App\Finance\Models\InvoiceLine;
use App\Finance\Models\LedgerTransaction;
use App\Finance\Models\OpeningBalanceBatch;
use App\Finance\Models\OpeningBalanceRow;
use App\Finance\Models\Payment;
use App\Finance\Models\PaymentAllocation;
use App\Finance\Models\StudentAccount;
use App\Finance\Models\VoidRequest;

return [
    /*
    |--------------------------------------------------------------------------
    | Single source of School access
    |--------------------------------------------------------------------------
    |
    | When true, User::accessibleSchoolIds() derives School access solely from
    | model_has_roles (the single source of truth — §7.1), instead of the union
    | of the school_user pivot, guardian records and the users.school_id
    | fallback.
    |
    | This is a temporary expand/contract rollout flag. It stays OFF until the
    | parity test is green and the legacy sources have been backfilled into
    | model_has_roles in every environment; only then is it switched on and the
    | legacy columns dropped (a later slice).
    |
    */
    'single_source_access' => env('RBAC_SINGLE_SOURCE_ACCESS', false),

    /*
    |--------------------------------------------------------------------------
    | Platform 2FA enforcement (C7 D5/D6)
    |--------------------------------------------------------------------------
    | Master switch above the per-role two_factor_required toggle: off => nobody
    | is checked. Default is per-ENVIRONMENT (on in production, off elsewhere) —
    | deliberately NOT a hard environment() branch in the middleware, so the
    | enforcement path stays one code path staging can soak (Invariant 10).
    | Flips are audited (activity_log 'rbac') — D7.
    */
    'two_factor_enforced' => (bool) env('RBAC_TWO_FACTOR_ENFORCED', env('APP_ENV') === 'production'),

    /*
    |--------------------------------------------------------------------------
    | Parity soak (S7) — dual-compute divergence detection
    |--------------------------------------------------------------------------
    |
    | When true, every School-access decision computes BOTH the legacy union and
    | the model_has_roles single source in the SAME request and logs any
    | mismatch (App\Support\SchoolAccessParity). This is how divergence is
    | proven zero on live traffic BEFORE the columns are dropped — a flag-flip
    | between runs compares different traffic and would miss per-user
    | divergence. Independent of single_source_access (which one is *returned*),
    | so both paths are always exercised while the soak runs. OFF by default;
    | enabled only during the S7 soak.
    |
    */
    'parity_soak' => env('RBAC_PARITY_SOAK', false),

    /*
    |--------------------------------------------------------------------------
    | Fail-closed School scope — PER-MODEL rollout
    |--------------------------------------------------------------------------
    |
    | An allowlist of School-scoped model classes for which querying with no
    | active School context throws MissingSchoolContextException instead of
    | silently returning unscoped rows (§5.5). There is deliberately NO
    | super-admin exemption: authority (Gate::before) and isolation (SchoolScope)
    | are separate axes.
    |
    | This is intentionally per-model, NOT a global switch (roadmap Rollout Flags
    | table: "scope.fail_closed | per model"; Risk #14). Enabling fail-closed for
    | every model at once would break every console/seeder/job read that runs
    | without a School in a single flip. Instead each model is opted in only after
    | its off-request read paths (seeders, commands, jobs) have been audited and
    | given explicit context via Model::withoutSchoolScope() or
    | ActiveSchool::runFor(), verified by driving the affected flows.
    |
    | THE DEFAULT IS A VERSIONED LIST, NOT EMPTY — and that is a change from how
    | this setting started. It defaulted to empty and expected every environment
    | to set RBAC_FAIL_CLOSED_MODELS itself, which made the protection something
    | a deploy could forget: a rule that only holds if someone remembers to set
    | an env var is not a rule, it is a wish. The batch below ships in the
    | repository, is reviewed like code and arrives in every environment at once.
    | RBAC_FAIL_CLOSED_MODELS remains, but its meaning is now a per-environment
    | RETREAT — an override for an environment that must temporarily run without
    | a model's protection — never the source of the list.
    |
    | THE FIRST BATCH IS THE FINANCE TRANSACTIONAL SET, opted in while these
    | tables still hold seed and drive data. After term-1 billing they hold real
    | money and the same flip becomes a production risk rather than a cheap one.
    | The #224 School-context survey is the evidence: of fifteen finance
    | maker-checker actions, nine were saved from acting on another School's row
    | only by a firstOrFail() that happens to sit on a scoped read, and three
    | were not saved at all. That difference was an accident of call order, not a
    | design, and fail-closed is what turns it into one.
    |
    | The finance CATALOG models — FeeSchedule, FeeItem, DiscountPolicy, the two
    | change tables and SchoolFinanceSettings — are deliberately NOT here yet.
    | They are a later batch on their own evidence, not a category extended by
    | analogy.
    |
    | To override in one environment (comma-separated, fully qualified). The
    | override REPLACES the default wholesale; it does not add to it:
    |
    |   RBAC_FAIL_CLOSED_MODELS="App\Finance\Models\Invoice,App\Finance\Models\Payment"
    |
    | A BLANK value means "not set", and that is deliberate rather than tidy.
    | env() distinguishes an absent key from a present-but-empty one — an empty
    | RBAC_FAIL_CLOSED_MODELS= line returns "" and would take the default's place,
    | switching the entire batch off. That line is exactly what an .env copied
    | from a template carries, so blank-means-empty would let the protection be
    | disabled platform-wide by a copy-paste, silently, with nothing to notice it.
    | Turning a model off is therefore something you can only do by writing down
    | the models that stay on.
    |
    */
    'fail_closed_models' => array_values(array_filter(array_map(
        'trim',
        explode(',', trim((string) env('RBAC_FAIL_CLOSED_MODELS', '')) ?: implode(',', [
            LedgerTransaction::class,
            Payment::class,
            PaymentAllocation::class,
            Invoice::class,
            InvoiceLine::class,
            CreditNote::class,
            StudentAccount::class,
            OpeningBalanceBatch::class,
            OpeningBalanceRow::class,
            VoidRequest::class,
        ])),
    ))),
];
