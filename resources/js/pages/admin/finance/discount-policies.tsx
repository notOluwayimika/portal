import { Head } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertCircle,
    Archive,
    CheckCircle2,
    Layers,
    Pencil,
    Percent,
    Plus,
    RefreshCw,
    Search,
    Send,
    X,
} from 'lucide-react';
import { Fragment, useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';

import { FinanceStatCard } from '@/components/finance/finance-stat-card';
import { StatusPill } from '@/components/finance/status-pill';
import type { StatusTone } from '@/components/finance/status-pill';
import Select from '@/components/ui/base-dropdown';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import Modal from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/spinner';
import { formatNaira, minorToNairaInput, nairaToMinor } from '@/lib/format';

/**
 * The school's discount policies — the catalog every reduction on an invoice has to cite (U2).
 *
 * WHY THE SCREEN EXISTS. Every endpoint behind it already shipped, and the reduction trigger
 * (`finance_invoice_lines_reduction_guard`) refuses a reduction line that names no policy. With no way
 * to author one there were zero policies, so a scholarship could only be handled by credit-noting a
 * full-price invoice — three approvals a term per student, and a parent shown the full fee with a
 * correction underneath instead of their actual bill.
 *
 * FOUR ACTS AND NO FIFTH: list, propose a new policy, propose an amendment, propose a retirement.
 * THERE IS NO APPROVE AND NO REJECT HERE — that is /finance/approvals, and a second home for the ED's
 * decision is a second place for it to disagree with itself. Nothing on this page writes a policy:
 * all three proposals POST /api/v1/finance/discount-policy-changes and wait, and
 * ApproveDiscountPolicyChange is the only writer of `finance_discount_policies` (an arch test says so).
 * The hero carries exactly two buttons — Refresh, and the one primary act, Propose a policy — and the
 * table's row controls are Amend and Retire. There is no fifth control anywhere on the page.
 *
 * RETIRED AND SUPERSEDED ROWS STAY ON THE LIST. A policy that priced an invoice must remain nameable
 * forever — the same argument bank accounts uses for having no delete, and the same one the table
 * itself makes with a no-DELETE trigger and a name unique scoped to the ACTIVE state. They are shown
 * below the active ones, without controls: `rows` is built active-first (see `rows` below), a spanning
 * divider row introduces the closed block, and the Actions cell of a closed row renders the sentence
 * "Kept for the invoices it priced" instead of a control.
 *
 * THE FRONTEND COMPUTES NO MONEY. An amount policy's value goes in through nairaToMinor and is read
 * back through formatNaira/minorToNairaInput; a percent policy is NOT money and touches neither — it
 * is an integer 1..100 (`unsignedTinyInteger`, CHECK-bounded in the migration). There is no `* 100`,
 * no parseFloat, no toFixed and no Intl in this file; bin/ci-money-lint.php is a gate step and this
 * file sits inside its strict zone (`resources/js/pages/admin/finance/`). THE KPI CARDS COUNT ROWS
 * AND NEVER SUM A VALUE — beyond § 24's ban, a value total here would be meaningless as well as
 * forbidden: an amount policy carries money and a percent policy does not, so there is no quantity
 * the two can be added into.
 *
 * LAID OUT TO docs/ui-ux-design-system.md: page shell → hero card → KPI stat cards → filter/table
 * card, with loading, error and empty as three DISTINCT spanning rows. The failed load was a toast
 * and nothing else — the screen then rendered as an empty school, which is § 26's first recorded
 * defect in its original form. Nothing about the API contract or the three write paths moved.
 */

type PolicyStatus = 'active' | 'superseded' | 'retired';

type Basis = 'amount' | 'percent';

// DiscountPolicyResource's shape. `value_minor`/`value_currency` are the amount basis's two halves and
// arrive UNWRAPPED (that resource does not cast through Money) — they are paired back into the wire
// shape at the one place they are rendered, in valueLabel() below. Every key here is pinned from the
// server's side by tests/Feature/Finance/DiscountPoliciesScreenTest.php's catalog-contract arm.
type DiscountPolicy = {
    id: string;
    name: string;
    description: string | null;
    basis: Basis;
    value_minor: number | null;
    value_currency: string | null;
    percent: number | null;
    requires_approval: boolean;
    status: PolicyStatus;
};

type Proposal =
    | { kind: 'create'; policy: null }
    | { kind: 'amend'; policy: DiscountPolicy }
    | { kind: 'retire'; policy: DiscountPolicy };

type FormState = {
    name: string;
    description: string;
    basis: Basis;
    // As typed, in naira — converted at submit by nairaToMinor. Only read when basis = 'amount'.
    amount: string;
    // As typed. An integer 1..100, and NOT money: it never goes near nairaToMinor.
    percent: string;
    requiresApproval: boolean;
    reason: string;
};

const EMPTY: FormState = {
    name: '',
    description: '',
    basis: 'percent',
    amount: '',
    percent: '',
    requiresApproval: false,
    reason: '',
};

/**
 * What an amount policy is denominated in. This form authors nothing else, and the currency is posted
 * explicitly rather than left to a server default — `value_currency` is NOT cast through Money on this
 * table, so a missing or malformed one persists into an append-only row and fails much later, at
 * whatever tries to apply the policy (SubmitDiscountPolicyChangeRequest:36-40 says the same thing from
 * the other side).
 */
const DEFAULT_CURRENCY = 'NGN';

type ErrorBag = { fields: Record<string, string>; message: string | null };

const NO_ERRORS: ErrorBag = { fields: {}, message: null };

const STATUS_LABEL: Record<PolicyStatus, string> = {
    active: 'Active',
    superseded: 'Superseded',
    retired: 'Retired',
};

/**
 * Tone is MEANING (§ 2 / § 14), read off the shared six-value union — no seventh tone is invented and
 * the pill itself is {@see StatusPill}, not a re-implementation. `active` is emerald because it is the
 * live, choosable state. `superseded` and `retired` BOTH map to slate, which § 2 defines as
 * "neutral, settled, void, inactive": both mean the same thing about the policy's usability — it can
 * no longer be cited on a new invoice — and they differ only in HOW it stopped, which is what the
 * label carries. Two states sharing a tone is correct here; colour was never the signal (§ 21).
 */
const STATUS_TONE: Record<PolicyStatus, StatusTone> = {
    active: 'emerald',
    superseded: 'slate',
    retired: 'slate',
};

type StatusFilter = '' | PolicyStatus;

const STATUS_FILTER_OPTIONS: { label: string; value: StatusFilter }[] = [
    { label: 'All policies', value: '' },
    { label: 'Active', value: 'active' },
    { label: 'Superseded', value: 'superseded' },
    { label: 'Retired', value: 'retired' },
];

const BASIS_OPTIONS: { label: string; value: Basis }[] = [
    { label: 'A percentage of the bill', value: 'percent' },
    { label: 'A fixed amount off', value: 'amount' },
];

function parseErrorBag(err: unknown): ErrorBag | null {
    if (!axios.isAxiosError(err) || err.response?.status !== 422) {
        return null;
    }

    const bag = (err.response.data?.errors ?? {}) as Record<string, unknown>;
    const fields: Record<string, string> = {};

    for (const [key, value] of Object.entries(bag)) {
        fields[key] = Array.isArray(value) ? String(value[0]) : String(value);
    }

    return {
        fields,
        // The Action's friendly refusals — an already-open request for this policy, an empty reason —
        // are a 422 with a `message` and NO bag. Without this the modal would report nothing at all.
        message:
            typeof err.response.data?.message === 'string' &&
            Object.keys(bag).length === 0
                ? err.response.data.message
                : null,
    };
}

/** How a policy reduces a bill, in the operator's words. Amount through formatNaira; percent is not money. */
function valueLabel(policy: DiscountPolicy): string {
    if (policy.basis === 'percent') {
        return policy.percent === null
            ? '—'
            : `${policy.percent}% of discountable charges`;
    }

    return policy.value_minor === null || policy.value_currency === null
        ? '—'
        : formatNaira({
              amount_minor: policy.value_minor,
              currency: policy.value_currency,
          });
}

export default function DiscountPolicies() {
    const [policies, setPolicies] = useState<DiscountPolicy[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);

    // FILTERED IN THE BROWSER, AND THAT IS SOUND HERE ONLY BECAUSE THERE IS NO PAGINATION.
    // DiscountPolicyController::index (app/Finance/Http/Controllers/DiscountPolicyController.php:41-49)
    // ends in `->get()` with one optional `status` filter and no page/per_page, so the School's whole
    // catalog arrives in one response and the counts below are drawn from the same array the rows are
    // — there is no server total for a client filter to disagree with. The design system's ban (§ 7)
    // is on filtering a *paginated* list client-side. The day that endpoint paginates, both of these
    // move into the query string; this screen is named as a dependent in
    // docs/handoff/tickets/fee-schedule-index-unpaginated.md so the person who paginates it finds us.
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<StatusFilter>('');

    const [proposal, setProposal] = useState<Proposal | null>(null);
    const [form, setForm] = useState<FormState>(EMPTY);
    const [errors, setErrors] = useState<ErrorBag>(NO_ERRORS);
    const [submitting, setSubmitting] = useState(false);

    const load = useCallback(async () => {
        setLoading(true);
        setError(false);

        try {
            // NO `status` PARAMETER, deliberately: absent means unfiltered, and a superseded or retired
            // policy is provenance this screen has to keep showing. A caller that wants only the
            // choosable ones asks for `?status=active`. (The status control above is a view filter over
            // the whole catalog, not a narrower fetch — DiscountPoliciesScreenTest's last arm reds if
            // this URL ever grows a `?status=`.)
            const { data } = await axios.get(
                '/api/v1/finance/discount-policies',
            );
            setPolicies(data ?? []);
        } catch {
            // A DISTINCT ERROR STATE, NOT A TOAST (§ 13). The toast this replaces dismissed itself and
            // left the screen rendering "No discount policies yet" — a sentence about the school, made
            // from a dead fetch, and the first entry in § 26's record.
            setError(true);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        // THIS DISABLE IS NEW AND IT IS NOT COSMETIC. The file previously carried a comment saying the
        // opposite — that `react-hooks/set-state-in-effect` did NOT fire here, so a disable copied from
        // the siblings would be an unused directive (a warning) claiming a rule was being broken when
        // it was not. That was true of the old `load`, and it stopped being true when this one grew the
        // error state: verified by running eslint over both revisions of this file, the pre-change one
        // clean and this one reporting the rule at the `void load()` line. The disable is the same one
        // bank-accounts.tsx:113 and finance/index.tsx:95 carry, for the same reason they carry it — the
        // initial fetch is the effect's whole purpose and its flags are set synchronously inside it.
        // eslint-disable-next-line react-hooks/set-state-in-effect
        void load();
    }, [load]);

    const openCreate = () => {
        setForm(EMPTY);
        setErrors(NO_ERRORS);
        setProposal({ kind: 'create', policy: null });
    };

    const openAmend = (policy: DiscountPolicy) => {
        // Prefilled from the policy being superseded, because an amendment is authored FROM the current
        // terms — an empty form would make "change the rate" into "retype the whole policy", which is
        // how a description or a requires_approval flag silently changes with it.
        setForm({
            name: policy.name,
            description: policy.description ?? '',
            basis: policy.basis,
            amount:
                policy.value_minor !== null && policy.value_currency !== null
                    ? minorToNairaInput({
                          amount_minor: policy.value_minor,
                          currency: policy.value_currency,
                      })
                    : '',
            percent: policy.percent === null ? '' : String(policy.percent),
            requiresApproval: policy.requires_approval,
            reason: '',
        });
        setErrors(NO_ERRORS);
        setProposal({ kind: 'amend', policy });
    };

    const openRetire = (policy: DiscountPolicy) => {
        setForm({ ...EMPTY, reason: '' });
        setErrors(NO_ERRORS);
        setProposal({ kind: 'retire', policy });
    };

    /**
     * The client half of the amount-XOR-percent rule. The schema is the authority — the policies table
     * carries a basis-exclusive CHECK and the change table repeats it in `…_terms_shape` — and the
     * FormRequest refuses a cross combo with a 422 before either is reached. This exists so an operator
     * cannot SUBMIT both or neither in the first place: the server refusing it anyway is the guarantee,
     * this is the form not wasting their time.
     */
    const localErrors = (): Record<string, string> => {
        if (proposal?.kind === 'retire') {
            return {};
        }

        const found: Record<string, string> = {};

        if (form.name.trim() === '') {
            found.name =
                'A policy needs a name — this is what a bursar picks it by.';
        }

        if (form.basis === 'amount') {
            const minor = nairaToMinor(form.amount);

            if (minor === null || minor < 1) {
                found.value_minor =
                    'Enter the amount taken off, in naira — for example 25000 or 2500.50.';
            }
        } else if (!/^\d{1,3}$/.test(form.percent.trim())) {
            found.percent = 'Enter a whole percentage between 1 and 100.';
        } else {
            const percent = Number(form.percent.trim());

            if (percent < 1 || percent > 100) {
                found.percent = 'Enter a whole percentage between 1 and 100.';
            }
        }

        return found;
    };

    const send = async () => {
        if (!proposal) {
            return;
        }

        const local = localErrors();

        if (form.reason.trim() === '') {
            local.reason =
                'The executive director reads this, and it is the only context they get.';
        }

        if (Object.keys(local).length > 0) {
            setErrors({ fields: local, message: null });

            return;
        }

        setSubmitting(true);
        setErrors(NO_ERRORS);

        // ONE SIDE OF THE BASIS IS POSTED, NEVER BOTH. `value_minor`/`value_currency` are
        // `prohibited_if:basis,percent` and `percent` is `prohibited_if:basis,amount`, so sending the
        // unused half — even empty — is a 422 rather than a tidy no-op.
        const terms =
            proposal.kind === 'retire'
                ? {}
                : {
                      name: form.name.trim(),
                      description:
                          form.description.trim() === ''
                              ? null
                              : form.description.trim(),
                      basis: form.basis,
                      requires_approval: form.requiresApproval,
                      ...(form.basis === 'amount'
                          ? {
                                value_minor: nairaToMinor(form.amount),
                                value_currency: DEFAULT_CURRENCY,
                            }
                          : { percent: Number(form.percent.trim()) }),
                  };

        try {
            await axios.post('/api/v1/finance/discount-policy-changes', {
                kind: proposal.kind,
                ...(proposal.policy ? { target: proposal.policy.id } : {}),
                reason: form.reason.trim(),
                ...terms,
            });

            toast.success(
                proposal.kind === 'retire'
                    ? 'Retirement sent to the executive director.'
                    : proposal.kind === 'amend'
                      ? 'Amendment sent to the executive director.'
                      : 'New policy sent to the executive director.',
            );
            setProposal(null);
            await load();
        } catch (err: unknown) {
            const bag = parseErrorBag(err);

            if (bag) {
                setErrors(bag);

                if (bag.message) {
                    toast.error(bag.message);
                }
            } else {
                toast.error('Could not send this for approval.');
            }
        } finally {
            setSubmitting(false);
        }
    };

    // Counted over the WHOLE loaded catalog, not the filtered view — these are the school's headline
    // figures and a filter must not restate them. Row counts only; see the file docblock.
    const activeCount = policies.filter((p) => p.status === 'active').length;
    const supersededCount = policies.filter(
        (p) => p.status === 'superseded',
    ).length;
    const retiredCount = policies.filter((p) => p.status === 'retired').length;

    const term = search.trim().toLowerCase();
    const visible = policies.filter((p) => {
        if (status !== '' && p.status !== status) {
            return false;
        }

        if (term === '') {
            return true;
        }

        return [p.name, p.description ?? '']
            .join(' ')
            .toLowerCase()
            .includes(term);
    });

    // ACTIVE FIRST, CLOSED BELOW — the docblock's rule expressed as an ordering rather than as a second
    // table. One table means one set of column widths, so a superseded policy's terms line up under the
    // active one it replaced, which is the comparison a reader of this list is actually making.
    const rows = [
        ...visible.filter((p) => p.status === 'active'),
        ...visible.filter((p) => p.status !== 'active'),
    ];

    const hasFilters = term !== '' || status !== '';

    const clearFilters = () => {
        setSearch('');
        setStatus('');
    };

    return (
        <>
            <Head title="Discount policies" />

            <div className="min-h-screen bg-[#f5f7fb] px-4 py-5 pb-24 sm:px-6 lg:px-8 dark:bg-background">
                <div className="mx-auto max-w-7xl space-y-5">
                    {/* ── Hero Card ─────────────────────────────────────────────── */}
                    <div className="relative overflow-hidden rounded-2xl border border-white bg-white px-6 py-4 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:border-white/5 dark:bg-card">
                        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div className="flex items-center gap-4">
                                <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-linear-to-br from-indigo-50 to-violet-50 shadow-sm ring-1 ring-black/5 dark:from-indigo-950/50 dark:to-violet-950/50">
                                    <Percent className="h-6 w-6 text-indigo-600 dark:text-indigo-400" />
                                </div>
                                <div>
                                    <h1 className="text-xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                                        Discount policies
                                    </h1>
                                    {/* One line (§ 4). The longer argument the old header carried — that
                                        a policy's terms are immutable and an amendment supersedes
                                        rather than edits — now sits where it is acted on: the amend
                                        modal's notice, and the divider above the closed rows. */}
                                    <p className="text-xs text-slate-500">
                                        Every reduction on an invoice has to
                                        name one of these, and only the
                                        executive director may change the list.
                                    </p>
                                </div>
                            </div>

                            {/* Two page-scoped acts and no more. The page route is gated on
                                `finance.discount-policy.change.submit`, which is the ONE maker ability
                                the whole screen posts (DiscountPoliciesScreenTest pins that there is
                                exactly one), so a <Can> here would re-ask the question that admitted
                                the operator. Propose stays live on a failed load: authoring a NEW
                                policy reads nothing from the catalog. */}
                            <div className="flex shrink-0 flex-wrap items-center gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => void load()}
                                    disabled={loading}
                                    className="rounded-lg border-slate-200 font-semibold text-slate-700 transition-all hover:bg-slate-50 hover:text-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-white"
                                >
                                    <RefreshCw
                                        className={`mr-1.5 h-4 w-4 ${loading ? 'animate-spin' : ''}`}
                                    />
                                    Refresh
                                </Button>
                                <Button
                                    size="sm"
                                    onClick={openCreate}
                                    className="rounded-lg bg-indigo-600 px-4 font-semibold text-white shadow-md transition-all hover:bg-indigo-700 hover:shadow-lg active:scale-95"
                                >
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Propose a policy
                                </Button>
                            </div>
                        </div>
                    </div>

                    {/* ── KPI Stat Cards ───────────────────────────────────────── */}
                    {/* ROW COUNTS, NEVER A VALUE TOTAL — see the file docblock: an amount policy carries
                        money and a percent policy does not, so there is no total to take even if § 24
                        allowed one. The whole catalog is in hand (the endpoint does not paginate), so
                        these describe every policy the school has rather than a page of them.

                        A FAILED LOAD RENDERS AN EM DASH, NEVER A NUMBER. `policies` is [] both when the
                        school has authored nothing and when the fetch died, so `String(0)` here is a
                        count the page does not have — the same empty-vs-error confusion the table's
                        error state removes, restated one region higher up. The dash is this repo's
                        existing no-value glyph (valueLabel() above returns it). It is not a skeleton,
                        which would say "still arriving" and pulse forever beside a Retry button; and
                        the card is not suppressed, because the label is what tells the reader WHICH
                        figure is missing. The dash is CONDITIONAL on `error` — a genuine zero still
                        renders `0`, which is what the drive shows for Superseded and Retired. */}
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <FinanceStatCard
                            icon={CheckCircle2}
                            tone="emerald"
                            label="Active"
                            value={error ? '—' : String(activeCount)}
                            subText="Citable on an invoice raised today"
                            loading={loading && policies.length === 0}
                        />
                        <FinanceStatCard
                            icon={Layers}
                            tone="slate"
                            label="Superseded"
                            value={error ? '—' : String(supersededCount)}
                            subText="Replaced by an amendment, still explaining old invoices"
                            loading={loading && policies.length === 0}
                        />
                        <FinanceStatCard
                            icon={Archive}
                            tone="slate"
                            label="Retired"
                            value={error ? '—' : String(retiredCount)}
                            subText="Withdrawn from choice, never deleted"
                            loading={loading && policies.length === 0}
                        />
                    </div>

                    {/* ── Filters + Table Card ─────────────────────────────────── */}
                    <div className="overflow-hidden rounded-xl border-none bg-white shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card">
                        <div className="border-b border-slate-100 dark:border-slate-800">
                            <div className="flex flex-col gap-3 px-5 py-3 sm:flex-row sm:items-center">
                                <div className="relative w-full sm:max-w-md sm:flex-1">
                                    <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                                    <Input
                                        placeholder="Search by name or description…"
                                        aria-label="Search discount policies"
                                        className="h-9 rounded-lg border-slate-200 bg-white pl-9 text-sm focus-visible:ring-2 focus-visible:ring-indigo-100 dark:border-slate-700 dark:bg-slate-900"
                                        value={search}
                                        onChange={(e) =>
                                            setSearch(e.target.value)
                                        }
                                    />
                                </div>

                                <div className="w-full sm:w-44">
                                    <Select
                                        value={status}
                                        onChange={(val) =>
                                            setStatus(
                                                (val
                                                    ? String(val)
                                                    : '') as StatusFilter,
                                            )
                                        }
                                        placeholder="All policies"
                                        options={STATUS_FILTER_OPTIONS}
                                    />
                                </div>

                                <div className="flex items-center gap-2 sm:ml-auto">
                                    {/* SUPPRESSED ON A FAILED LOAD, for the same reason the cards
                                        render a dash: both numbers come from an array that is empty
                                        because the fetch died, so "Showing 0 of 0" states a count the
                                        page does not have. There is no honest number here — a sentence
                                        with no content is worse than no sentence, and "Showing — of —"
                                        would be that sentence — so the counter is not rendered at all
                                        rather than dashed. The error row below says what happened. */}
                                    {!error && (
                                        <span className="hidden text-xs font-medium text-slate-500 sm:inline">
                                            Showing{' '}
                                            <span className="font-bold text-slate-700 dark:text-slate-200">
                                                {rows.length}
                                            </span>{' '}
                                            of{' '}
                                            <span className="font-bold text-slate-700 dark:text-slate-200">
                                                {policies.length}
                                            </span>
                                        </span>
                                    )}
                                    {hasFilters && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            onClick={clearFilters}
                                            className="rounded-lg text-slate-500 hover:bg-slate-50 hover:text-slate-700"
                                        >
                                            <X className="mr-1 h-3.5 w-3.5" />
                                            Clear
                                        </Button>
                                    )}
                                </div>
                            </div>
                        </div>

                        <div className="custom-scrollbar overflow-x-auto">
                            <table className="w-full text-xs">
                                <thead>
                                    <tr className="border-b border-slate-100 bg-slate-50/50 dark:border-slate-800 dark:bg-slate-900/30">
                                        <th className="px-4 py-2.5 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Policy
                                        </th>
                                        <th className="px-4 py-2.5 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Basis
                                        </th>
                                        <th className="px-4 py-2.5 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Value
                                        </th>
                                        <th className="px-4 py-2.5 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            How it is applied
                                        </th>
                                        <th className="px-4 py-2.5 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Status
                                        </th>
                                        <th className="px-4 py-2.5 text-right text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {loading ? (
                                        <tr>
                                            <td
                                                colSpan={6}
                                                className="py-12 text-center"
                                            >
                                                <Spinner className="mx-auto" />
                                            </td>
                                        </tr>
                                    ) : error ? (
                                        <tr>
                                            <td colSpan={6} className="py-12">
                                                <div className="flex flex-col items-center gap-3 text-center">
                                                    <div className="flex size-12 items-center justify-center rounded-full bg-red-50 text-red-500 dark:bg-red-900/20">
                                                        <AlertCircle className="h-6 w-6" />
                                                    </div>
                                                    <div>
                                                        <p className="text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                            Could not load
                                                            discount policies
                                                        </p>
                                                        <p className="text-xs text-slate-500">
                                                            Something went wrong
                                                            fetching the
                                                            catalog. This school
                                                            may still have
                                                            policies — none of
                                                            them could be read.
                                                        </p>
                                                    </div>
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            void load()
                                                        }
                                                        className="rounded-lg"
                                                    >
                                                        <RefreshCw className="mr-1.5 h-3.5 w-3.5" />
                                                        Retry
                                                    </Button>
                                                </div>
                                            </td>
                                        </tr>
                                    ) : rows.length === 0 ? (
                                        <tr>
                                            <td colSpan={6} className="py-12">
                                                <div className="flex flex-col items-center gap-3 text-center">
                                                    <div className="flex size-12 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-slate-800">
                                                        <Percent className="h-6 w-6" />
                                                    </div>
                                                    <div>
                                                        <p className="text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                            No discount policies
                                                            to show
                                                        </p>
                                                        <p className="text-xs text-slate-500">
                                                            {hasFilters
                                                                ? 'No policies match this view. Try clearing the filters.'
                                                                : 'Until one is approved no invoice can carry a discount at all — a reduction has to cite a policy.'}
                                                        </p>
                                                    </div>
                                                    {hasFilters ? (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={
                                                                clearFilters
                                                            }
                                                            className="rounded-lg"
                                                        >
                                                            <X className="mr-1.5 h-3.5 w-3.5" />
                                                            Clear filters
                                                        </Button>
                                                    ) : (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={openCreate}
                                                            className="rounded-lg"
                                                        >
                                                            <Plus className="mr-1.5 h-3.5 w-3.5" />
                                                            Propose a policy
                                                        </Button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ) : (
                                        rows.map((policy, index) => {
                                            // The first closed row carries the divider that introduces
                                            // the provenance block. Derived from the ordering rather
                                            // than from a second table, so the columns stay one set.
                                            const opensClosedBlock =
                                                policy.status !== 'active' &&
                                                (index === 0 ||
                                                    rows[index - 1].status ===
                                                        'active');

                                            return (
                                                <Fragment key={policy.id}>
                                                    {opensClosedBlock && (
                                                        <tr className="bg-slate-50/60 dark:bg-slate-900/30">
                                                            <td
                                                                colSpan={6}
                                                                className="px-4 py-2.5"
                                                            >
                                                                <p className="text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                                                    No longer in
                                                                    use
                                                                </p>
                                                                <p className="mt-0.5 max-w-3xl text-[11px] text-slate-500">
                                                                    Superseded
                                                                    and retired
                                                                    policies
                                                                    stay here
                                                                    for good.
                                                                    Each one may
                                                                    still be the
                                                                    only thing
                                                                    that
                                                                    explains a
                                                                    discount on
                                                                    an invoice
                                                                    issued
                                                                    months ago,
                                                                    so none of
                                                                    them can be
                                                                    deleted —
                                                                    and none of
                                                                    them can be
                                                                    amended or
                                                                    retired
                                                                    again.
                                                                </p>
                                                            </td>
                                                        </tr>
                                                    )}
                                                    <tr
                                                        className="transition-colors hover:bg-slate-50/60 dark:hover:bg-slate-900/30"
                                                        data-testid="discount-policy-row"
                                                    >
                                                        <td className="px-4 py-2.5">
                                                            <p className="font-semibold text-slate-700 dark:text-slate-200">
                                                                {policy.name}
                                                            </p>
                                                            {policy.description && (
                                                                <p className="text-[11px] text-slate-400">
                                                                    {
                                                                        policy.description
                                                                    }
                                                                </p>
                                                            )}
                                                        </td>
                                                        <td className="px-4 py-2.5 text-slate-600 dark:text-slate-300">
                                                            {policy.basis ===
                                                            'amount'
                                                                ? 'Fixed amount'
                                                                : 'Percentage'}
                                                        </td>
                                                        <td className="px-4 py-2.5 font-semibold text-slate-700 tabular-nums dark:text-slate-200">
                                                            {valueLabel(policy)}
                                                        </td>
                                                        <td className="px-4 py-2.5">
                                                            {/*
                                                             * THE FIELD THIS SCREEN LIVES OR DIES ON, said in the
                                                             * list as well as in the form — "Requires approval:
                                                             * yes" tells a reader nothing about what it DOES.
                                                             */}
                                                            {policy.requires_approval ? (
                                                                <span className="text-amber-700 dark:text-amber-400">
                                                                    Each award
                                                                    needs the
                                                                    ED&rsquo;s
                                                                    sign-off —
                                                                    raised as a
                                                                    credit note,
                                                                    never as an
                                                                    invoice line
                                                                </span>
                                                            ) : (
                                                                <span className="text-slate-500 dark:text-slate-400">
                                                                    A bursar
                                                                    applies it
                                                                    directly on
                                                                    the invoice
                                                                </span>
                                                            )}
                                                        </td>
                                                        <td className="px-4 py-2.5">
                                                            <StatusPill
                                                                tone={
                                                                    STATUS_TONE[
                                                                        policy
                                                                            .status
                                                                    ]
                                                                }
                                                                label={
                                                                    STATUS_LABEL[
                                                                        policy
                                                                            .status
                                                                    ]
                                                                }
                                                            />
                                                        </td>
                                                        <td className="px-4 py-2.5 text-right whitespace-nowrap">
                                                            {/*
                                                             * A CLOSED ROW HAS NO CONTROLS. Amend and Retire are
                                                             * both proposals AGAINST an active policy; offering
                                                             * either on a superseded or retired one would post a
                                                             * request the Action refuses, so the cell states why
                                                             * the row is here instead.
                                                             */}
                                                            {policy.status ===
                                                            'active' ? (
                                                                <>
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        className="h-7 w-7"
                                                                        title={`Amend ${policy.name}`}
                                                                        aria-label={`Amend ${policy.name}`}
                                                                        onClick={() =>
                                                                            openAmend(
                                                                                policy,
                                                                            )
                                                                        }
                                                                    >
                                                                        <Pencil className="h-3.5 w-3.5" />
                                                                    </Button>
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        className="h-7 w-7 text-slate-500 hover:bg-amber-50 hover:text-amber-600 dark:hover:bg-amber-900/20 dark:hover:text-amber-400"
                                                                        title={`Retire ${policy.name}`}
                                                                        aria-label={`Retire ${policy.name}`}
                                                                        onClick={() =>
                                                                            openRetire(
                                                                                policy,
                                                                            )
                                                                        }
                                                                    >
                                                                        <Archive className="h-3.5 w-3.5" />
                                                                    </Button>
                                                                </>
                                                            ) : (
                                                                <span className="text-[11px] text-slate-400">
                                                                    Kept for the
                                                                    invoices it
                                                                    priced
                                                                </span>
                                                            )}
                                                        </td>
                                                    </tr>
                                                </Fragment>
                                            );
                                        })
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <Modal
                isOpen={proposal !== null}
                onClose={() => setProposal(null)}
                title={
                    proposal?.kind === 'retire'
                        ? `Retire ${proposal.policy.name}`
                        : proposal?.kind === 'amend'
                          ? `Amend ${proposal.policy.name}`
                          : 'Propose a discount policy'
                }
                size="lg"
                footer={
                    <div className="flex justify-end gap-3">
                        <Button
                            variant="outline"
                            onClick={() => setProposal(null)}
                            disabled={submitting}
                        >
                            Cancel
                        </Button>
                        <Button onClick={send} disabled={submitting}>
                            {submitting ? (
                                <Spinner className="mr-2 h-4 w-4" />
                            ) : (
                                <Send className="mr-2 h-4 w-4" />
                            )}
                            {submitting ? 'Sending…' : 'Send to the ED'}
                        </Button>
                    </div>
                }
            >
                <div className="space-y-4">
                    {errors.message && (
                        <p className="rounded-md border border-destructive/40 bg-destructive/5 p-2 text-sm text-destructive">
                            {errors.message}
                        </p>
                    )}

                    {proposal?.kind === 'retire' ? (
                        <p className="text-sm text-muted-foreground">
                            The executive director decides. Until they approve,
                            this policy keeps working — retiring it withdraws it
                            from choice on future invoices and changes nothing
                            about the ones it already priced.
                        </p>
                    ) : (
                        <>
                            {proposal?.kind === 'amend' && (
                                <p className="rounded-lg bg-slate-50 p-3 text-xs text-slate-500 dark:bg-slate-900/40 dark:text-slate-400">
                                    A policy&rsquo;s terms are immutable, so
                                    this does not edit{' '}
                                    <span className="font-semibold">
                                        {proposal.policy.name}
                                    </span>
                                    . On approval the executive director
                                    supersedes it and a new policy takes its
                                    place; the old one stays on the list,
                                    unchanged, explaining every invoice it
                                    priced.
                                </p>
                            )}

                            <div>
                                <Label htmlFor="dp-name">Name</Label>
                                <Input
                                    id="dp-name"
                                    value={form.name}
                                    maxLength={255}
                                    placeholder="Sibling discount"
                                    onChange={(e) =>
                                        setForm({
                                            ...form,
                                            name: e.target.value,
                                        })
                                    }
                                />
                                <p className="mt-0.5 text-xs text-slate-500">
                                    What a bursar will pick it by, and what a
                                    parent may see beside the reduction.
                                </p>
                                {errors.fields.name && (
                                    <p className="mt-0.5 text-xs text-destructive">
                                        {errors.fields.name}
                                    </p>
                                )}
                            </div>

                            <div>
                                <Label htmlFor="dp-description">
                                    Description (optional)
                                </Label>
                                <Input
                                    id="dp-description"
                                    value={form.description}
                                    maxLength={500}
                                    placeholder="Who qualifies, and on what evidence."
                                    onChange={(e) =>
                                        setForm({
                                            ...form,
                                            description: e.target.value,
                                        })
                                    }
                                />
                                {errors.fields.description && (
                                    <p className="mt-0.5 text-xs text-destructive">
                                        {errors.fields.description}
                                    </p>
                                )}
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="dp-basis">Basis</Label>
                                    {/* THE SHARED Select, not a raw <select> (§ 7 / § 24). It sits in
                                        the modal's scrollable body, which is the structural case
                                        base-dropdown's fixed-position panel had to be taught about
                                        (base-dropdown.tsx:132-147) — so this consumer is worth
                                        opening in the drive, not just rendering. */}
                                    <Select
                                        id="dp-basis"
                                        value={form.basis}
                                        onChange={(val) =>
                                            setForm({
                                                ...form,
                                                basis: (val
                                                    ? String(val)
                                                    : 'percent') as Basis,
                                            })
                                        }
                                        options={BASIS_OPTIONS}
                                        placeholder="Choose a basis"
                                        buttonClass="flex h-9 w-full items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white px-3 text-sm font-medium text-slate-700 shadow-sm transition-all hover:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"
                                    />
                                    {errors.fields.basis && (
                                        <p className="mt-0.5 text-xs text-destructive">
                                            {errors.fields.basis}
                                        </p>
                                    )}
                                </div>

                                {/*
                                 * ONE FIELD OR THE OTHER, NEVER BOTH — the schema says so (the
                                 * basis-exclusive CHECK), so the form shows only the one the chosen
                                 * basis uses rather than greying the other out. A percentage is not
                                 * money and never goes near the naira helpers.
                                 */}
                                {form.basis === 'amount' ? (
                                    <div>
                                        <Label htmlFor="dp-amount">
                                            Amount off (₦)
                                        </Label>
                                        <Input
                                            id="dp-amount"
                                            value={form.amount}
                                            inputMode="decimal"
                                            placeholder="25000"
                                            onChange={(e) =>
                                                setForm({
                                                    ...form,
                                                    amount: e.target.value,
                                                })
                                            }
                                        />
                                        {(errors.fields.value_minor ??
                                            errors.fields.value_currency) && (
                                            <p className="mt-0.5 text-xs text-destructive">
                                                {errors.fields.value_minor ??
                                                    errors.fields
                                                        .value_currency}
                                            </p>
                                        )}
                                    </div>
                                ) : (
                                    <div>
                                        <Label htmlFor="dp-percent">
                                            Percentage off (%)
                                        </Label>
                                        <Input
                                            id="dp-percent"
                                            value={form.percent}
                                            inputMode="numeric"
                                            placeholder="10"
                                            onChange={(e) =>
                                                setForm({
                                                    ...form,
                                                    percent: e.target.value,
                                                })
                                            }
                                        />
                                        <p className="mt-0.5 text-xs text-slate-500">
                                            A whole number from 1 to 100,
                                            applied to the discountable charges
                                            on the bill.
                                        </p>
                                        {errors.fields.percent && (
                                            <p className="mt-0.5 text-xs text-destructive">
                                                {errors.fields.percent}
                                            </p>
                                        )}
                                    </div>
                                )}
                            </div>

                            {/*
                             * requires_approval, SPELLED OUT BESIDE THE CONTROL AND NOT IN A TOOLTIP.
                             * Its name says nothing about what it does, and both wrong answers are
                             * expensive: false on a scholarship hands out an unbounded reduction on one
                             * person's judgement, true on a routine discount buries the ED in
                             * signatures until they stop reading them. The wording is the behaviour,
                             * not a paraphrase of it: with `true` the reduction trigger REFUSES the
                             * discount as an invoice line outright ("apply it as a credit note, not an
                             * invoice line"), so every award goes through the credit-note
                             * maker-checker.
                             */}
                            <fieldset className="space-y-2">
                                <legend className="text-sm font-medium text-slate-700 dark:text-slate-200">
                                    How is it applied?
                                </legend>

                                {(
                                    [
                                        [
                                            false,
                                            'A bursar applies it directly',
                                            'The discount goes straight onto the invoice as a line. Right for a standing arrangement — a sibling discount, a staff rate — where the rule decides and nobody needs to look at the individual case.',
                                        ],
                                        [
                                            true,
                                            'Every award needs the executive director',
                                            'The discount cannot go on an invoice at all. Each award is raised as a credit note and the ED approves it one student at a time. Right for a scholarship or a hardship award, where each decision is about the student rather than about the rule.',
                                        ],
                                    ] as const
                                ).map(([value, title, blurb]) => (
                                    <label
                                        key={String(value)}
                                        className={`flex cursor-pointer gap-3 rounded-lg border p-3 transition-colors ${
                                            form.requiresApproval === value
                                                ? 'border-indigo-500 bg-indigo-50/50 dark:border-indigo-400 dark:bg-indigo-950/30'
                                                : 'border-slate-200 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-900/40'
                                        }`}
                                    >
                                        <input
                                            type="radio"
                                            className="mt-1"
                                            name="dp-requires-approval"
                                            checked={
                                                form.requiresApproval === value
                                            }
                                            onChange={() =>
                                                setForm({
                                                    ...form,
                                                    requiresApproval: value,
                                                })
                                            }
                                        />
                                        <span>
                                            <span className="block text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                {title}
                                            </span>
                                            <span className="block text-xs text-slate-500">
                                                {blurb}
                                            </span>
                                        </span>
                                    </label>
                                ))}

                                {errors.fields.requires_approval && (
                                    <p className="text-xs text-destructive">
                                        {errors.fields.requires_approval}
                                    </p>
                                )}
                            </fieldset>
                        </>
                    )}

                    <div>
                        <Label htmlFor="dp-reason">Reason</Label>
                        <Input
                            id="dp-reason"
                            value={form.reason}
                            maxLength={255}
                            placeholder="Why this, and why now — the ED reads this."
                            onChange={(e) =>
                                setForm({ ...form, reason: e.target.value })
                            }
                        />
                        {errors.fields.reason && (
                            <p className="mt-0.5 text-xs text-destructive">
                                {errors.fields.reason}
                            </p>
                        )}
                    </div>
                </div>
            </Modal>
        </>
    );
}

DiscountPolicies.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'Discount policies', href: '/finance/discount-policies' },
    ],
};
