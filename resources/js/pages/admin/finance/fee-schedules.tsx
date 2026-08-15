import { Head } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertCircle,
    CheckCircle2,
    Clock,
    Copy,
    FileText,
    Pencil,
    Plus,
    RefreshCw,
    Save,
    Search,
    Send,
    Tags,
    Trash2,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import { FinanceStatCard } from '@/components/finance/finance-stat-card';
import { StatusPill } from '@/components/finance/status-pill';
import type { StatusTone } from '@/components/finance/status-pill';
import Select from '@/components/ui/base-dropdown';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import Modal from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/spinner';
import { usePermissions } from '@/hooks/use-permissions';
import { formatNaira, minorToNairaInput, nairaToMinor } from '@/lib/format';
import type { Money } from '@/types/finance';

/**
 * The school's fee schedules — where prices are authored (U1 commit 2).
 *
 * FIVE ACTS AND NO SIXTH: list, author a draft, edit a draft, submit a draft for the ED's
 * approval, retire an active schedule. THERE IS NO APPROVE AND NO REJECT — that is
 * /finance/approvals, and a second place for the ED's decision to live is a second place for it
 * to disagree with itself. A re-price is a sixth control only in appearance: an active
 * schedule's items are frozen (FeeScheduleStatus's docblock), so "re-price" authors a NEW draft
 * through supersede and then goes through the same publish approval as any other draft.
 *
 * TERMS AND CLASS LEVELS ARRIVE AS PROPS, accounts as a FETCH, and the asymmetry is deliberate:
 * the only API listing terms is gated on `academic_data.view`, which this seat does not hold,
 * while GET /api/v1/finance/bank-accounts is gated on `finance.bank-account.manage`, which every
 * role holding this screen's ability also holds (asserted in FeeSchedulesScreenTest, not assumed).
 * Props are for data the seat cannot fetch; a second source for data it can fetch is drift.
 *
 * THE FRONTEND COMPUTES NO MONEY. `total` is summed by FeeScheduleResource in PHP; the amount
 * inputs go through nairaToMinor and the amounts on screen through formatNaira. There is no
 * `* 100`, no parseFloat, no toFixed and no Intl in this file — bin/ci-money-lint.php is a gate
 * step and refuses all four. The KPI cards below count ROWS, which is not money and is not summed.
 *
 * LAID OUT TO docs/ui-ux-design-system.md: page shell → hero card → KPI stat cards → filter/table
 * card, with loading, error and empty as three DISTINCT spanning rows. The API contract, the
 * permission gate and every write path are unchanged.
 */

type TermOption = { id: number; label: string };
type ClassLevelOption = { id: number; name: string };

type BankAccount = {
    id: string;
    label: string;
    bank_name: string;
    is_active: boolean;
};

type ScheduleStatus =
    | 'draft'
    | 'pending_approval'
    | 'active'
    | 'superseded'
    | 'retired';

type FeeItem = {
    id: string;
    description: string;
    amount: Money;
    bank_account_id: string | null;
    is_mandatory: boolean;
    is_discountable: boolean;
    sort_order: number;
};

// FeeScheduleResource's CATALOG shape. `total` is null when the schedule's items disagree on a
// currency — the resource surfaces that rather than adding two currencies together; the table
// renders it as "Mixed currencies" below.
type FeeSchedule = {
    id: string;
    term_id: number;
    class_level_id: number;
    term_label: string | null;
    class_level_label: string | null;
    label: string;
    status: ScheduleStatus;
    items: FeeItem[];
    total: Money | null;
};

type ItemRow = {
    key: number;
    description: string;
    amount: string; // as typed, in naira — converted at submit by nairaToMinor
    bank_account_id: string;
    // CARRIED, NOT EDITED. See openFrom() for why a field this form cannot author is nonetheless
    // held on the row: an edit must not rewrite what the operator did not touch.
    currency: string;
    is_mandatory: boolean;
    is_discountable: boolean;
};

/**
 * What a NEW line is denominated in. This form cannot author anything else and this commit does
 * not change that — Money::DEFAULT_CURRENCY, stated here so the create path posts a currency
 * explicitly rather than relying on `items.*.currency` being `sometimes` and defaulting server-side.
 * An EDIT never reads this: it posts back whatever the item already carried.
 */
const DEFAULT_CURRENCY = 'NGN';

type Mode = 'create' | 'edit' | 'supersede';

/**
 * The 422 bag, SPLIT BY ROW. Laravel returns `items.0.bank_account_id` and `items.2.amount_minor`;
 * flattening those into one map — the shape bank-accounts.tsx uses, where every field is
 * top-level — puts every item error in a single box with no indication of WHICH of eight fee
 * lines is wrong. `message` carries the other 422: a BusinessRuleException (the slot collision)
 * comes back as `{message}` with no `errors` key at all.
 */
type ErrorBag = {
    fields: Record<string, string>;
    items: Record<number, Record<string, string>>;
    message: string | null;
};

const NO_ERRORS: ErrorBag = { fields: {}, items: {}, message: null };

const STATUS_LABEL: Record<ScheduleStatus, string> = {
    draft: 'Draft',
    pending_approval: 'With the ED',
    active: 'Active',
    superseded: 'Superseded',
    retired: 'Retired',
};

// Tone is MEANING (design system § 2/§ 14): amber = waiting on someone, emerald = live and
// billing, slate = neutral/closed. A draft is slate rather than blue because it is not yet
// informational about anything — nothing bills off it.
const STATUS_TONE: Record<ScheduleStatus, StatusTone> = {
    draft: 'slate',
    pending_approval: 'amber',
    active: 'emerald',
    superseded: 'slate',
    retired: 'slate',
};

const STATUS_FILTER_OPTIONS: { label: string; value: string }[] = [
    { label: 'Any status', value: '' },
    ...(Object.keys(STATUS_LABEL) as ScheduleStatus[]).map((status) => ({
        label: STATUS_LABEL[status],
        value: status,
    })),
];

function parseErrorBag(err: unknown): ErrorBag | null {
    if (!axios.isAxiosError(err) || err.response?.status !== 422) {
        return null;
    }

    const bag = (err.response.data?.errors ?? {}) as Record<string, unknown>;
    const fields: Record<string, string> = {};
    const items: Record<number, Record<string, string>> = {};

    for (const [key, value] of Object.entries(bag)) {
        const message = Array.isArray(value) ? String(value[0]) : String(value);
        const nested = /^items\.(\d+)\.(.+)$/.exec(key);

        if (nested) {
            const index = Number(nested[1]);
            items[index] = { ...(items[index] ?? {}), [nested[2]]: message };
        } else {
            fields[key] = message;
        }
    }

    return {
        fields,
        items,
        // The Action's friendly refusals (the slot collision, an empty item list) are a 422 with
        // a `message` and NO bag. Without this branch the modal would close on a failed save, or
        // sit there having reported nothing.
        message:
            typeof err.response.data?.message === 'string' &&
            Object.keys(bag).length === 0
                ? err.response.data.message
                : null,
    };
}

export default function FeeSchedules({
    terms,
    class_levels: classLevels,
}: {
    terms: TermOption[];
    class_levels: ClassLevelOption[];
}) {
    const { can } = usePermissions();
    // SUBMIT AND RETIRE ARE A DIFFERENT ABILITY FROM THIS SCREEN'S. The page is gated on
    // `finance.fee-schedule.manage`; proposing a publish or a retire needs
    // `finance.fee-schedule.change.submit`, and the seeded `admin` role holds the first and NOT
    // the second (RbacSeeder grantsMap). Rendering the button unconditionally would hand that
    // seat a control that 403s — the same defect the nav gate exists to prevent, one layer in.
    const canPropose = can('finance.fee-schedule.change.submit');

    const [schedules, setSchedules] = useState<FeeSchedule[]>([]);
    const [accounts, setAccounts] = useState<BankAccount[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);

    // Filters. DEFAULT IS NO TERM — a school arriving in September has one term of schedules, and
    // hiding them behind a preselected filter is worse than a short list. Both are applied
    // SERVER-SIDE through the query params commit 1 added, never by filtering a full fetch here.
    const [termFilter, setTermFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('');

    // The search box is the ONE client-side narrowing on this screen, and it is sound only
    // because GET /v1/finance/fee-schedules does not paginate — it returns the School's whole
    // matching set in one response (see the controller's docblock and
    // docs/handoff/tickets/fee-schedule-index-unpaginated.md). So "Showing X of Y" below draws
    // both numbers from the same array and there is no server total to disagree with. The day
    // that endpoint paginates, this moves into the query string with the other two.
    const [search, setSearch] = useState('');

    const [modalOpen, setModalOpen] = useState(false);
    const [mode, setMode] = useState<Mode>('create');
    const [target, setTarget] = useState<FeeSchedule | null>(null);
    const [termId, setTermId] = useState('');
    const [classLevelId, setClassLevelId] = useState('');
    const [label, setLabel] = useState('');
    const [rows, setRows] = useState<ItemRow[]>([]);
    const [errors, setErrors] = useState<ErrorBag>(NO_ERRORS);
    const [submitting, setSubmitting] = useState(false);

    // Row keys come from a counter, not the array index: a React key that is the index makes the
    // wrong row's input lose focus when a row above it is removed.
    const nextKey = useRef(1);
    const newRow = useCallback(
        (): ItemRow => ({
            key: nextKey.current++,
            description: '',
            amount: '',
            bank_account_id: '',
            currency: DEFAULT_CURRENCY,
            is_mandatory: true,
            is_discountable: true,
        }),
        [],
    );

    const [proposal, setProposal] = useState<{
        schedule: FeeSchedule;
        kind: 'publish' | 'retire';
    } | null>(null);
    const [reason, setReason] = useState('');
    const [proposing, setProposing] = useState(false);

    const load = useCallback(async () => {
        setLoading(true);
        setError(false);

        const query = new URLSearchParams();

        if (termFilter) {
            query.set('term_id', termFilter);
        }

        if (statusFilter) {
            query.set('status', statusFilter);
        }

        try {
            const { data } = await axios.get(
                `/api/v1/finance/fee-schedules?${query.toString()}`,
            );
            setSchedules(data ?? []);
        } catch {
            setError(true);
        } finally {
            setLoading(false);
        }
    }, [termFilter, statusFilter]);

    const loadAccounts = useCallback(async () => {
        try {
            const { data } = await axios.get('/api/v1/finance/bank-accounts');
            setAccounts(data.bank_accounts ?? []);
        } catch {
            toast.error(
                'Could not load the bank accounts a fee line is paid into.',
            );
        }
    }, []);

    useEffect(() => {
        // Same disable the two sibling finance pages carry (bank-accounts.tsx, finance/index.tsx):
        // the fetch is the effect's whole purpose and its loading flag is set synchronously
        // inside it.
        // eslint-disable-next-line react-hooks/set-state-in-effect
        void load();
    }, [load]);

    useEffect(() => {
        // Same disable, same reason — the accounts fetch is a SECOND effect rather than a branch of
        // the first because it does not depend on the filters: re-fetching every account each time
        // the operator changes the term filter would be a request per keystroke of a select.
        // eslint-disable-next-line react-hooks/set-state-in-effect
        void loadAccounts();
    }, [loadAccounts]);

    // Only an ACTIVE account may be a destination — the exists rule on items.*.bank_account_id is
    // `whereNull('deactivated_at')`, so offering a deactivated one is offering a guaranteed 422.
    // A deactivated account already ON a draft still shows, because the row points at it and
    // hiding it would silently blank the operator's existing destination.
    const activeAccounts = accounts.filter((account) => account.is_active);
    const accountOptions = (current: string) => {
        const offered = activeAccounts.some((account) => account.id === current)
            ? activeAccounts
            : accounts.filter(
                  (account) => account.is_active || account.id === current,
              );

        return [
            { label: 'Choose an account…', value: '' },
            ...offered.map((account) => ({
                label: `${account.label} · ${account.bank_name}`,
                value: account.id,
            })),
        ];
    };

    const openCreate = () => {
        setMode('create');
        setTarget(null);
        setTermId(terms[0] ? String(terms[0].id) : '');
        setClassLevelId(classLevels[0] ? String(classLevels[0].id) : '');
        setLabel('');
        setRows([newRow()]);
        setErrors(NO_ERRORS);
        setModalOpen(true);
    };

    const openFrom = (
        schedule: FeeSchedule,
        nextMode: 'edit' | 'supersede',
    ) => {
        setMode(nextMode);
        setTarget(schedule);
        setTermId(String(schedule.term_id));
        setClassLevelId(String(schedule.class_level_id));
        setLabel(
            nextMode === 'supersede'
                ? `${schedule.label} (re-priced)`
                : schedule.label,
        );
        setRows(
            schedule.items.map((item) => ({
                key: nextKey.current++,
                description: item.description,
                // minorToNairaInput, not formatNaira: the input has to hold a value nairaToMinor
                // accepts back, and "₦2,500.75" is not one.
                amount: minorToNairaInput(item.amount),
                // THE TWO CARRIED FIELDS, AND THEY ARE ONE ARGUMENT. An edit replaces the item set
                // WHOLESALE — EditFeeScheduleDraft deletes and re-inserts — so every field the form
                // does not send back is not "left alone", it is rewritten from whatever default the
                // write path supplies. An operator opening a draft to fix one typo must not have
                // the fields they did not touch silently changed underneath them.
                //
                // bank_account_id is the field commit 1 added for exactly this: without it every
                // line's destination came back blank and had to be re-picked from nothing, and a
                // wrong pick lands money in the wrong account.
                //
                // currency is the same defect one field over, and worse because it is INVISIBLE.
                // `items.*.currency` is `sometimes` (HasFeeScheduleItemRules), so an omitted
                // currency is not a 422 the way an omitted bank account is — CreateFeeSchedule
                // reads `$item['currency'] ?? Money::DEFAULT_CURRENCY` and writes NGN. A USD item
                // edited through this form would keep its minor units, change its denomination, and
                // report a total that is not an amount of anything. The form cannot AUTHOR a
                // non-NGN item and this does not change that; it stops the form DESTROYING one.
                bank_account_id: item.bank_account_id ?? '',
                currency: item.amount.currency,
                is_mandatory: item.is_mandatory,
                is_discountable: item.is_discountable,
            })),
        );
        setErrors(NO_ERRORS);
        setModalOpen(true);
    };

    const patchRow = (key: number, patch: Partial<ItemRow>) =>
        setRows((current) =>
            current.map((row) =>
                row.key === key ? { ...row, ...patch } : row,
            ),
        );

    const submit = async () => {
        // INLINE VALIDATION BEFORE THE REQUEST, for the one rule the server cannot phrase: an
        // amount the operator typed that is not a number at all has no minor-unit form to send.
        // nairaToMinor returns null rather than guessing, and a guess here would be a wrong price.
        const rejected: Record<number, Record<string, string>> = {};
        const items = rows.map((row, index) => {
            const minor = nairaToMinor(row.amount);

            if (minor === null) {
                rejected[index] = {
                    amount_minor:
                        'Enter an amount in naira — digits, with at most two decimal places (e.g. 2500.75).',
                };
            }

            return {
                description: row.description,
                amount_minor: minor ?? 0,
                bank_account_id: row.bank_account_id,
                // Posted back on every path, edit included — see openFrom(). Dropping this line is
                // the defect: it re-denominates every item on the schedule to NGN.
                currency: row.currency,
                is_mandatory: row.is_mandatory,
                is_discountable: row.is_discountable,
                sort_order: index,
            };
        });

        if (Object.keys(rejected).length > 0) {
            setErrors({ fields: {}, items: rejected, message: null });

            return;
        }

        setSubmitting(true);
        setErrors(NO_ERRORS);

        try {
            if (mode === 'edit' && target) {
                // LABEL AND ITEMS ONLY. EditFeeScheduleDraftRequest carries no term_id and no
                // class_level_id — a draft's slot is fixed by the row, and re-slotting it from the
                // body is the defect supersede was renamed to avoid.
                await axios.put(
                    `/api/v1/finance/fee-schedules/${target.id}/draft`,
                    { label, items },
                );
                toast.success('Draft updated.');
            } else if (mode === 'supersede' && target) {
                // supersede reads term and class level from the BOUND schedule, not the body — but
                // FeeScheduleRequest still requires both, so they are sent from the row they will
                // be ignored in favour of.
                await axios.put(`/api/v1/finance/fee-schedules/${target.id}`, {
                    term_id: Number(termId),
                    class_level_id: Number(classLevelId),
                    label,
                    items,
                });
                toast.success('New draft authored for this slot.');
            } else {
                await axios.post('/api/v1/finance/fee-schedules', {
                    term_id: Number(termId),
                    class_level_id: Number(classLevelId),
                    label,
                    items,
                });
                toast.success('Draft created.');
            }

            setModalOpen(false);
            await load();
        } catch (err: unknown) {
            const bag = parseErrorBag(err);

            if (bag) {
                setErrors(bag);

                if (bag.message) {
                    toast.error(bag.message);
                }
            } else {
                toast.error('Could not save the fee schedule.');
            }
        } finally {
            setSubmitting(false);
        }
    };

    const sendProposal = async () => {
        if (!proposal) {
            return;
        }

        setProposing(true);

        try {
            await axios.post('/api/v1/finance/fee-schedule-changes', {
                kind: proposal.kind,
                target: proposal.schedule.id,
                reason,
            });
            toast.success(
                proposal.kind === 'publish'
                    ? 'Sent to the executive director for approval.'
                    : 'Retirement sent for approval.',
            );
            setProposal(null);
            setReason('');
            await load();
        } catch (err: unknown) {
            const bag = parseErrorBag(err);
            const message =
                bag?.message ??
                bag?.fields.reason ??
                'Could not send this for approval.';
            toast.error(message);
        } finally {
            setProposing(false);
        }
    };

    const term = search.trim().toLowerCase();
    const visible =
        term === ''
            ? schedules
            : schedules.filter((schedule) =>
                  [
                      schedule.label,
                      schedule.term_label ?? '',
                      schedule.class_level_label ?? '',
                  ]
                      .join(' ')
                      .toLowerCase()
                      .includes(term),
              );

    // Row counts over the loaded set — not sums, and never money. They describe THIS VIEW, which
    // is why the sub-text says so: with a status filter applied two of the three are 0 by
    // construction, and a card labelled as a school-wide total would then be a lie.
    const countOf = (status: ScheduleStatus) =>
        visible.filter((schedule) => schedule.status === status).length;

    const hasFilters = termFilter !== '' || statusFilter !== '' || term !== '';

    const clearFilters = () => {
        setTermFilter('');
        setStatusFilter('');
        setSearch('');
    };

    const termOptions = [
        { label: 'All terms', value: '' },
        ...terms.map((t) => ({ label: t.label, value: String(t.id) })),
    ];

    return (
        <>
            <Head title="Fee schedules" />

            <div className="min-h-screen bg-[#f5f7fb] px-4 py-5 pb-24 sm:px-6 lg:px-8 dark:bg-background">
                <div className="mx-auto max-w-7xl space-y-5">
                    {/* ── Hero Card ─────────────────────────────────────────────── */}
                    <div className="relative overflow-hidden rounded-2xl border border-white bg-white px-6 py-4 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:border-white/5 dark:bg-card">
                        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div className="flex items-center gap-4">
                                <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-linear-to-br from-indigo-50 to-violet-50 shadow-sm ring-1 ring-black/5 dark:from-indigo-950/50 dark:to-violet-950/50">
                                    <Tags className="h-6 w-6 text-indigo-600 dark:text-indigo-400" />
                                </div>
                                <div>
                                    <h1 className="text-xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                                        Fee schedules
                                    </h1>
                                    <p className="text-xs text-slate-500">
                                        What a term costs, per class level —
                                        priced as a draft, billable only once
                                        the executive director approves it.
                                    </p>
                                </div>
                            </div>

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
                                    disabled={terms.length === 0}
                                    className="rounded-lg bg-indigo-600 px-4 font-semibold text-white shadow-md transition-all hover:bg-indigo-700 hover:shadow-lg active:scale-95"
                                >
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    New draft
                                </Button>
                            </div>
                        </div>
                    </div>

                    {/* A school with no terms cannot price anything, and the disabled New-draft
                        button alone does not say why. Amber, because it is an attention state the
                        operator can resolve — not an error. */}
                    {terms.length === 0 && (
                        <div className="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 dark:border-amber-900/40 dark:bg-amber-900/20">
                            <AlertCircle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
                            <div>
                                <p className="text-sm font-semibold text-amber-800 dark:text-amber-300">
                                    This school has no terms yet
                                </p>
                                <p className="text-xs text-amber-700 dark:text-amber-400">
                                    There is nothing to price until one exists.
                                    Set up an academic session and its terms
                                    first.
                                </p>
                            </div>
                        </div>
                    )}

                    {/* ── KPI Stat Cards ───────────────────────────────────────── */}
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <FinanceStatCard
                            icon={FileText}
                            tone="slate"
                            label="Drafts"
                            value={String(countOf('draft'))}
                            subText="In this view — priced, not yet submitted"
                            loading={loading && schedules.length === 0}
                        />
                        <FinanceStatCard
                            icon={Clock}
                            tone="amber"
                            label="With the ED"
                            value={String(countOf('pending_approval'))}
                            subText="In this view — frozen until the decision"
                            loading={loading && schedules.length === 0}
                        />
                        <FinanceStatCard
                            icon={CheckCircle2}
                            tone="emerald"
                            label="Active"
                            value={String(countOf('active'))}
                            subText="In this view — currently billable"
                            loading={loading && schedules.length === 0}
                        />
                    </div>

                    {/* ── Filters + Table Card ─────────────────────────────────── */}
                    <div className="overflow-hidden rounded-xl border-none bg-white shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card">
                        <div className="border-b border-slate-100 dark:border-slate-800">
                            <div className="flex flex-col gap-3 px-5 py-3 sm:flex-row sm:items-center">
                                <div className="relative w-full sm:max-w-xs sm:flex-1">
                                    <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                                    <Input
                                        placeholder="Search by label or class level…"
                                        aria-label="Search fee schedules"
                                        className="h-9 rounded-lg border-slate-200 bg-white pl-9 text-sm focus-visible:ring-2 focus-visible:ring-indigo-100 dark:border-slate-700 dark:bg-slate-900"
                                        value={search}
                                        onChange={(e) =>
                                            setSearch(e.target.value)
                                        }
                                    />
                                </div>

                                <div className="w-full sm:w-44">
                                    <Select
                                        value={termFilter}
                                        onChange={(val) =>
                                            setTermFilter(
                                                val ? String(val) : '',
                                            )
                                        }
                                        placeholder="All terms"
                                        options={termOptions}
                                    />
                                </div>

                                <div className="w-full sm:w-44">
                                    <Select
                                        value={statusFilter}
                                        onChange={(val) =>
                                            setStatusFilter(
                                                val ? String(val) : '',
                                            )
                                        }
                                        placeholder="Any status"
                                        options={STATUS_FILTER_OPTIONS}
                                    />
                                </div>

                                <div className="flex items-center gap-2 sm:ml-auto">
                                    <span className="hidden text-xs font-medium text-slate-500 sm:inline">
                                        Showing{' '}
                                        <span className="font-bold text-slate-700 dark:text-slate-200">
                                            {visible.length}
                                        </span>{' '}
                                        of{' '}
                                        <span className="font-bold text-slate-700 dark:text-slate-200">
                                            {schedules.length}
                                        </span>
                                    </span>
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
                                            Schedule
                                        </th>
                                        <th className="px-4 py-2.5 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Term
                                        </th>
                                        <th className="px-4 py-2.5 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Class level
                                        </th>
                                        <th className="px-4 py-2.5 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Status
                                        </th>
                                        <th className="px-4 py-2.5 text-right text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Lines
                                        </th>
                                        <th className="px-4 py-2.5 text-right text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Total
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
                                                colSpan={7}
                                                className="py-12 text-center"
                                            >
                                                <Spinner className="mx-auto" />
                                            </td>
                                        </tr>
                                    ) : error ? (
                                        <tr>
                                            <td colSpan={7} className="py-12">
                                                <div className="flex flex-col items-center gap-3 text-center">
                                                    <div className="flex size-12 items-center justify-center rounded-full bg-red-50 text-red-500 dark:bg-red-900/20">
                                                        <AlertCircle className="h-6 w-6" />
                                                    </div>
                                                    <div>
                                                        <p className="text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                            Could not load the
                                                            fee schedules
                                                        </p>
                                                        <p className="text-xs text-slate-500">
                                                            Something went wrong
                                                            fetching the data.
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
                                    ) : visible.length === 0 ? (
                                        <tr>
                                            <td colSpan={7} className="py-12">
                                                <div className="flex flex-col items-center gap-3 text-center">
                                                    <div className="flex size-12 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-slate-800">
                                                        <Tags className="h-6 w-6" />
                                                    </div>
                                                    <div>
                                                        <p className="text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                            No fee schedules to
                                                            show
                                                        </p>
                                                        <p className="text-xs text-slate-500">
                                                            {hasFilters
                                                                ? 'No schedules match this view. Try clearing the filters.'
                                                                : 'Author a draft to price a term for a class level.'}
                                                        </p>
                                                    </div>
                                                    {hasFilters && (
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
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ) : (
                                        visible.map((schedule) => (
                                            <tr
                                                key={schedule.id}
                                                className="transition-colors hover:bg-slate-50/60 dark:hover:bg-slate-900/30"
                                                data-testid="fee-schedule-row"
                                            >
                                                <td className="px-4 py-2.5 font-semibold text-slate-700 dark:text-slate-200">
                                                    {schedule.label}
                                                </td>
                                                <td className="px-4 py-2.5 text-slate-600 dark:text-slate-300">
                                                    {schedule.term_label ?? '—'}
                                                </td>
                                                <td className="px-4 py-2.5 text-slate-600 dark:text-slate-300">
                                                    {schedule.class_level_label ??
                                                        '—'}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    <StatusPill
                                                        tone={
                                                            STATUS_TONE[
                                                                schedule.status
                                                            ]
                                                        }
                                                        label={
                                                            STATUS_LABEL[
                                                                schedule.status
                                                            ]
                                                        }
                                                    />
                                                </td>
                                                <td className="px-4 py-2.5 text-right text-slate-600 tabular-nums dark:text-slate-300">
                                                    {schedule.items.length}
                                                </td>
                                                <td className="px-4 py-2.5 text-right font-semibold text-slate-800 tabular-nums dark:text-slate-100">
                                                    {/*
                                                     * DISPLAYED, NEVER COMPUTED. The resource sums the
                                                     * items in PHP; null means those items do not agree
                                                     * on a currency, which is a fact worth naming rather
                                                     * than a blank. Icon AND text — colour alone is not
                                                     * a signal (design system § 21).
                                                     */}
                                                    {schedule.total === null ? (
                                                        <span className="inline-flex items-center gap-1 font-semibold text-red-600 dark:text-red-400">
                                                            <AlertCircle className="h-3.5 w-3.5" />
                                                            Mixed currencies
                                                        </span>
                                                    ) : (
                                                        formatNaira(
                                                            schedule.total,
                                                        )
                                                    )}
                                                </td>
                                                <td className="px-4 py-2.5 text-right whitespace-nowrap">
                                                    {/*
                                                     * BUTTONS BY STATUS. SubmitFeeScheduleChange already
                                                     * refuses the wrong ones, so this is not the control
                                                     * — it is not offering an operator a button that
                                                     * 422s. `pending_approval` deliberately offers
                                                     * nothing and says where the schedule is instead.
                                                     */}
                                                    {schedule.status ===
                                                        'draft' && (
                                                        <>
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                className="h-7 w-7"
                                                                title={`Edit ${schedule.label}`}
                                                                aria-label={`Edit ${schedule.label}`}
                                                                onClick={() =>
                                                                    openFrom(
                                                                        schedule,
                                                                        'edit',
                                                                    )
                                                                }
                                                            >
                                                                <Pencil className="h-3.5 w-3.5" />
                                                            </Button>
                                                            {canPropose && (
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="h-7 w-7 text-slate-500 hover:bg-indigo-50 hover:text-indigo-600 dark:hover:bg-indigo-900/20 dark:hover:text-indigo-400"
                                                                    title={`Submit ${schedule.label} for the executive director's approval`}
                                                                    aria-label={`Submit ${schedule.label} for approval`}
                                                                    onClick={() => {
                                                                        setProposal(
                                                                            {
                                                                                schedule,
                                                                                kind: 'publish',
                                                                            },
                                                                        );
                                                                        setReason(
                                                                            '',
                                                                        );
                                                                    }}
                                                                >
                                                                    <Send className="h-3.5 w-3.5" />
                                                                </Button>
                                                            )}
                                                        </>
                                                    )}

                                                    {schedule.status ===
                                                        'pending_approval' && (
                                                        <span className="text-[11px] text-slate-400">
                                                            With the executive
                                                            director — lines
                                                            frozen
                                                        </span>
                                                    )}

                                                    {schedule.status ===
                                                        'active' && (
                                                        <>
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                className="h-7 w-7"
                                                                title={`Re-price ${schedule.label} as a new draft`}
                                                                aria-label={`Re-price ${schedule.label}`}
                                                                onClick={() =>
                                                                    openFrom(
                                                                        schedule,
                                                                        'supersede',
                                                                    )
                                                                }
                                                            >
                                                                <Copy className="h-3.5 w-3.5" />
                                                            </Button>
                                                            {canPropose && (
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="h-7 w-7 text-slate-500 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/20 dark:hover:text-red-400"
                                                                    title={`Propose retiring ${schedule.label}`}
                                                                    aria-label={`Propose retiring ${schedule.label}`}
                                                                    onClick={() => {
                                                                        setProposal(
                                                                            {
                                                                                schedule,
                                                                                kind: 'retire',
                                                                            },
                                                                        );
                                                                        setReason(
                                                                            '',
                                                                        );
                                                                    }}
                                                                >
                                                                    <Trash2 className="h-3.5 w-3.5" />
                                                                </Button>
                                                            )}
                                                        </>
                                                    )}

                                                    {(schedule.status ===
                                                        'superseded' ||
                                                        schedule.status ===
                                                            'retired') && (
                                                        <span className="text-[11px] text-slate-400">
                                                            Read only
                                                        </span>
                                                    )}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <Modal
                isOpen={modalOpen}
                onClose={() => setModalOpen(false)}
                title={
                    mode === 'edit'
                        ? `Edit ${target?.label ?? 'draft'}`
                        : mode === 'supersede'
                          ? 'Re-price this slot'
                          : 'New fee schedule draft'
                }
                size="4xl"
                footer={
                    <div className="flex justify-end gap-3">
                        <Button
                            variant="outline"
                            onClick={() => setModalOpen(false)}
                            disabled={submitting}
                        >
                            Cancel
                        </Button>
                        <Button onClick={submit} disabled={submitting}>
                            {submitting ? (
                                <Spinner className="mr-2 h-4 w-4" />
                            ) : (
                                <Save className="mr-2 h-4 w-4" />
                            )}
                            {submitting
                                ? 'Saving…'
                                : mode === 'edit'
                                  ? 'Save draft'
                                  : 'Create draft'}
                        </Button>
                    </div>
                }
            >
                <div className="space-y-4">
                    {errors.message && (
                        <p className="rounded-md bg-destructive/10 p-2 text-sm text-destructive">
                            {errors.message}
                        </p>
                    )}

                    {mode === 'supersede' && (
                        <p className="rounded-lg bg-slate-50 p-3 text-xs text-slate-500 dark:bg-slate-900/40 dark:text-slate-400">
                            An active schedule&rsquo;s lines are frozen, so
                            re-pricing authors a NEW draft for the same term and
                            class level. The current schedule keeps billing
                            until the executive director approves the
                            replacement.
                        </p>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <Label htmlFor="fs-term">Term</Label>
                            {mode === 'create' ? (
                                <Select
                                    id="fs-term"
                                    value={termId}
                                    onChange={(val) =>
                                        setTermId(val ? String(val) : '')
                                    }
                                    placeholder="Choose a term…"
                                    options={terms.map((t) => ({
                                        label: t.label,
                                        value: String(t.id),
                                    }))}
                                />
                            ) : (
                                // NOT a disabled input. The slot is a fact about the row, not a
                                // field the operator may argue with — and a disabled input that
                                // still posts its value is not a guard.
                                <p className="pt-2 text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    {target?.term_label ?? '—'}
                                </p>
                            )}
                            {errors.fields.term_id && (
                                <p className="mt-1 text-xs text-destructive">
                                    {errors.fields.term_id}
                                </p>
                            )}
                        </div>

                        <div>
                            <Label htmlFor="fs-class-level">Class level</Label>
                            {mode === 'create' ? (
                                <Select
                                    id="fs-class-level"
                                    value={classLevelId}
                                    onChange={(val) =>
                                        setClassLevelId(val ? String(val) : '')
                                    }
                                    placeholder="Choose a class level…"
                                    options={classLevels.map((level) => ({
                                        label: level.name,
                                        value: String(level.id),
                                    }))}
                                />
                            ) : (
                                <p className="pt-2 text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    {target?.class_level_label ?? '—'}
                                </p>
                            )}
                            {errors.fields.class_level_id && (
                                <p className="mt-1 text-xs text-destructive">
                                    {errors.fields.class_level_id}
                                </p>
                            )}
                        </div>
                    </div>

                    <div>
                        <Label htmlFor="fs-label">Label</Label>
                        <Input
                            id="fs-label"
                            value={label}
                            placeholder="JSS 1 — First Term"
                            onChange={(e) => setLabel(e.target.value)}
                        />
                        {errors.fields.label && (
                            <p className="mt-1 text-xs text-destructive">
                                {errors.fields.label}
                            </p>
                        )}
                    </div>

                    <div className="space-y-3 border-t border-slate-100 pt-4 dark:border-slate-800">
                        <div className="flex items-center justify-between">
                            <h3 className="text-sm font-bold text-slate-700 dark:text-slate-200">
                                Fee lines
                            </h3>
                            <Button
                                variant="outline"
                                size="sm"
                                className="rounded-lg font-semibold"
                                onClick={() =>
                                    setRows((current) => [...current, newRow()])
                                }
                            >
                                <Plus className="mr-1.5 h-4 w-4" />
                                Add line
                            </Button>
                        </div>

                        {errors.fields.items && (
                            <p className="rounded-md bg-destructive/10 p-2 text-sm text-destructive">
                                {errors.fields.items}
                            </p>
                        )}

                        {rows.map((row, index) => (
                            <div
                                key={row.key}
                                className="space-y-3 rounded-xl border border-slate-100 bg-slate-50/40 p-3 dark:border-slate-800 dark:bg-slate-900/30"
                                data-testid="fee-item-row"
                            >
                                <div className="grid gap-3 sm:grid-cols-12">
                                    <div className="sm:col-span-5">
                                        <Label
                                            htmlFor={`fs-desc-${row.key}`}
                                            className="text-xs"
                                        >
                                            Description
                                        </Label>
                                        <Input
                                            id={`fs-desc-${row.key}`}
                                            value={row.description}
                                            placeholder="Tuition"
                                            onChange={(e) =>
                                                patchRow(row.key, {
                                                    description: e.target.value,
                                                })
                                            }
                                        />
                                        {errors.items[index]?.description && (
                                            <p className="mt-1 text-xs text-destructive">
                                                {
                                                    errors.items[index]
                                                        .description
                                                }
                                            </p>
                                        )}
                                    </div>

                                    <div className="sm:col-span-3">
                                        <Label
                                            htmlFor={`fs-amount-${row.key}`}
                                            className="text-xs"
                                        >
                                            Amount (₦)
                                        </Label>
                                        <Input
                                            id={`fs-amount-${row.key}`}
                                            value={row.amount}
                                            inputMode="decimal"
                                            placeholder="250000.00"
                                            className="tabular-nums"
                                            onChange={(e) =>
                                                patchRow(row.key, {
                                                    amount: e.target.value,
                                                })
                                            }
                                        />
                                        {errors.items[index]?.amount_minor && (
                                            <p className="mt-1 text-xs text-destructive">
                                                {
                                                    errors.items[index]
                                                        .amount_minor
                                                }
                                            </p>
                                        )}
                                    </div>

                                    <div className="sm:col-span-4">
                                        <Label
                                            htmlFor={`fs-account-${row.key}`}
                                            className="text-xs"
                                        >
                                            Paid into
                                        </Label>
                                        <Select
                                            id={`fs-account-${row.key}`}
                                            value={row.bank_account_id}
                                            onChange={(val) =>
                                                patchRow(row.key, {
                                                    bank_account_id: val
                                                        ? String(val)
                                                        : '',
                                                })
                                            }
                                            placeholder="Choose an account…"
                                            options={accountOptions(
                                                row.bank_account_id,
                                            )}
                                        />
                                        {errors.items[index]
                                            ?.bank_account_id && (
                                            <p className="mt-1 text-xs text-destructive">
                                                {
                                                    errors.items[index]
                                                        .bank_account_id
                                                }
                                            </p>
                                        )}
                                    </div>
                                </div>

                                <div className="flex flex-wrap items-center gap-4">
                                    <div className="flex items-center gap-2">
                                        <Checkbox
                                            id={`fs-mandatory-${row.key}`}
                                            checked={row.is_mandatory}
                                            onCheckedChange={(checked) =>
                                                patchRow(row.key, {
                                                    is_mandatory:
                                                        checked === true,
                                                })
                                            }
                                        />
                                        <Label
                                            htmlFor={`fs-mandatory-${row.key}`}
                                            className="text-xs font-medium text-slate-600 dark:text-slate-300"
                                        >
                                            Mandatory
                                        </Label>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Checkbox
                                            id={`fs-discountable-${row.key}`}
                                            checked={row.is_discountable}
                                            onCheckedChange={(checked) =>
                                                patchRow(row.key, {
                                                    is_discountable:
                                                        checked === true,
                                                })
                                            }
                                        />
                                        <Label
                                            htmlFor={`fs-discountable-${row.key}`}
                                            className="text-xs font-medium text-slate-600 dark:text-slate-300"
                                        >
                                            Discountable
                                        </Label>
                                    </div>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="ml-auto h-7 w-7 text-slate-500 hover:bg-red-50 hover:text-red-600 disabled:opacity-40 dark:hover:bg-red-900/20 dark:hover:text-red-400"
                                        disabled={rows.length === 1}
                                        title="Remove this fee line"
                                        aria-label="Remove this fee line"
                                        onClick={() =>
                                            setRows((current) =>
                                                current.filter(
                                                    (r) => r.key !== row.key,
                                                ),
                                            )
                                        }
                                    >
                                        <X className="h-3.5 w-3.5" />
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </Modal>

            <Modal
                isOpen={proposal !== null}
                onClose={() => setProposal(null)}
                title={
                    proposal?.kind === 'retire'
                        ? 'Retire this schedule'
                        : 'Submit for approval'
                }
                size="lg"
                footer={
                    <div className="flex justify-end gap-3">
                        <Button
                            variant="outline"
                            onClick={() => setProposal(null)}
                            disabled={proposing}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={sendProposal}
                            disabled={proposing || reason.trim() === ''}
                        >
                            {proposing ? (
                                <Spinner className="mr-2 h-4 w-4" />
                            ) : (
                                <Send className="mr-2 h-4 w-4" />
                            )}
                            {proposing ? 'Sending…' : 'Send to the ED'}
                        </Button>
                    </div>
                }
            >
                <div className="space-y-4">
                    <p className="text-xs text-slate-500">
                        {proposal?.kind === 'retire'
                            ? 'The executive director decides. Until they approve, this schedule keeps billing.'
                            : 'The executive director decides. The draft’s lines freeze the moment you send it, so what they approve is what you are showing them.'}
                    </p>

                    <div>
                        <Label htmlFor="fs-reason">Reason</Label>
                        <Input
                            id="fs-reason"
                            value={reason}
                            maxLength={255}
                            placeholder="Why this, and why now — the ED reads this."
                            onChange={(e) => setReason(e.target.value)}
                        />
                    </div>
                </div>
            </Modal>
        </>
    );
}

FeeSchedules.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'Fee schedules', href: '/finance/fee-schedules' },
    ],
};
