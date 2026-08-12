import { Head } from '@inertiajs/react';
import axios from 'axios';
import { Copy, Pencil, Plus, Send, Trash2, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
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
 * step and refuses all four.
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

const STATUS_CLASS: Record<ScheduleStatus, string> = {
    draft: 'bg-muted text-muted-foreground',
    pending_approval: 'bg-amber-100 text-amber-800',
    active: 'bg-emerald-100 text-emerald-800',
    superseded: 'bg-muted text-muted-foreground',
    retired: 'bg-muted text-muted-foreground',
};

const SELECT_CLASS =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs';

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

    // Filters. DEFAULT IS NO TERM — a school arriving in September has one term of schedules, and
    // hiding them behind a preselected filter is worse than a short list. Both are applied
    // SERVER-SIDE through the query params commit 1 added, never by filtering a full fetch here.
    const [termFilter, setTermFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('');

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
            toast.error('Could not load the fee schedules.');
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
        // Same disable the two sibling finance pages carry (bank-accounts.tsx:74,
        // finance/index.tsx:95): the fetch is the effect's whole purpose and its loading flag is
        // set synchronously inside it.
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
    const accountOptions = (current: string) =>
        activeAccounts.some((account) => account.id === current)
            ? activeAccounts
            : accounts.filter(
                  (account) => account.is_active || account.id === current,
              );

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

    return (
        <>
            <Head title="Fee schedules" />

            <div className="space-y-4 p-4">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold">Fee schedules</h1>
                        <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
                            What a term costs, per class level. A schedule is
                            authored as a draft and priced freely; it becomes
                            billable only when the executive director approves
                            it, and its lines are frozen from the moment it is
                            sent for approval.
                        </p>
                    </div>
                    <Button onClick={openCreate} disabled={terms.length === 0}>
                        <Plus className="mr-1 h-4 w-4" />
                        New draft
                    </Button>
                </div>

                {terms.length === 0 && (
                    <p className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                        This school has no terms yet, so there is nothing to
                        price. Set up an academic session and its terms first.
                    </p>
                )}

                <div className="flex flex-wrap items-end gap-3">
                    <div>
                        <Label htmlFor="fs-filter-term">Term</Label>
                        <select
                            id="fs-filter-term"
                            className={SELECT_CLASS}
                            value={termFilter}
                            onChange={(e) => setTermFilter(e.target.value)}
                        >
                            <option value="">All terms</option>
                            {terms.map((term) => (
                                <option key={term.id} value={term.id}>
                                    {term.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <Label htmlFor="fs-filter-status">Status</Label>
                        <select
                            id="fs-filter-status"
                            className={SELECT_CLASS}
                            value={statusFilter}
                            onChange={(e) => setStatusFilter(e.target.value)}
                        >
                            <option value="">Any status</option>
                            {(
                                Object.keys(STATUS_LABEL) as ScheduleStatus[]
                            ).map((status) => (
                                <option key={status} value={status}>
                                    {STATUS_LABEL[status]}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>

                {loading ? (
                    <div className="flex justify-center p-8">
                        <Spinner />
                    </div>
                ) : schedules.length === 0 ? (
                    <p className="rounded-md border border-dashed p-8 text-center text-sm text-muted-foreground">
                        No fee schedules match this filter.
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-2">Schedule</th>
                                    <th className="p-2">Term</th>
                                    <th className="p-2">Class level</th>
                                    <th className="p-2">Status</th>
                                    <th className="p-2 text-right">Lines</th>
                                    <th className="p-2 text-right">Total</th>
                                    <th className="p-2 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {schedules.map((schedule) => (
                                    <tr
                                        key={schedule.id}
                                        className="border-t align-top"
                                        data-testid="fee-schedule-row"
                                    >
                                        <td className="p-2 font-medium">
                                            {schedule.label}
                                        </td>
                                        <td className="p-2">
                                            {schedule.term_label ?? '—'}
                                        </td>
                                        <td className="p-2">
                                            {schedule.class_level_label ?? '—'}
                                        </td>
                                        <td className="p-2">
                                            <span
                                                className={`rounded-full px-2 py-0.5 text-xs ${STATUS_CLASS[schedule.status]}`}
                                            >
                                                {STATUS_LABEL[schedule.status]}
                                            </span>
                                        </td>
                                        <td className="p-2 text-right">
                                            {schedule.items.length}
                                        </td>
                                        <td className="p-2 text-right font-mono">
                                            {/*
                                             * DISPLAYED, NEVER COMPUTED. The resource sums the
                                             * items in PHP; null means those items do not agree
                                             * on a currency, which is a fact worth naming rather
                                             * than a blank.
                                             */}
                                            {schedule.total === null ? (
                                                <span className="text-destructive">
                                                    Mixed currencies
                                                </span>
                                            ) : (
                                                formatNaira(schedule.total)
                                            )}
                                        </td>
                                        <td className="space-x-1 p-2 text-right whitespace-nowrap">
                                            {/*
                                             * BUTTONS BY STATUS. SubmitFeeScheduleChange already
                                             * refuses the wrong ones, so this is not the control
                                             * — it is not offering an operator a button that
                                             * 422s. `pending_approval` deliberately offers
                                             * nothing and says where the schedule is instead.
                                             */}
                                            {schedule.status === 'draft' && (
                                                <>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            openFrom(
                                                                schedule,
                                                                'edit',
                                                            )
                                                        }
                                                    >
                                                        <Pencil className="mr-1 h-3.5 w-3.5" />
                                                        Edit
                                                    </Button>
                                                    {canPropose && (
                                                        <Button
                                                            size="sm"
                                                            onClick={() => {
                                                                setProposal({
                                                                    schedule,
                                                                    kind: 'publish',
                                                                });
                                                                setReason('');
                                                            }}
                                                        >
                                                            <Send className="mr-1 h-3.5 w-3.5" />
                                                            Submit for approval
                                                        </Button>
                                                    )}
                                                </>
                                            )}

                                            {schedule.status ===
                                                'pending_approval' && (
                                                <span className="text-xs text-muted-foreground">
                                                    With the executive director
                                                    — the lines are frozen until
                                                    the decision.
                                                </span>
                                            )}

                                            {schedule.status === 'active' && (
                                                <>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            openFrom(
                                                                schedule,
                                                                'supersede',
                                                            )
                                                        }
                                                    >
                                                        <Copy className="mr-1 h-3.5 w-3.5" />
                                                        Re-price
                                                    </Button>
                                                    {canPropose && (
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() => {
                                                                setProposal({
                                                                    schedule,
                                                                    kind: 'retire',
                                                                });
                                                                setReason('');
                                                            }}
                                                        >
                                                            <Trash2 className="mr-1 h-3.5 w-3.5" />
                                                            Retire
                                                        </Button>
                                                    )}
                                                </>
                                            )}

                                            {(schedule.status ===
                                                'superseded' ||
                                                schedule.status ===
                                                    'retired') && (
                                                <span className="text-xs text-muted-foreground">
                                                    Read only.
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
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
            >
                <div className="space-y-4">
                    {errors.message && (
                        <p className="rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive">
                            {errors.message}
                        </p>
                    )}

                    {mode === 'supersede' && (
                        <p className="rounded-md bg-muted/50 p-3 text-xs text-muted-foreground">
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
                                <select
                                    id="fs-term"
                                    className={SELECT_CLASS}
                                    value={termId}
                                    onChange={(e) => setTermId(e.target.value)}
                                >
                                    {terms.map((term) => (
                                        <option key={term.id} value={term.id}>
                                            {term.label}
                                        </option>
                                    ))}
                                </select>
                            ) : (
                                // NOT a disabled input. The slot is a fact about the row, not a
                                // field the operator may argue with — and a disabled input that
                                // still posts its value is not a guard.
                                <p className="pt-2 text-sm">
                                    {target?.term_label ?? '—'}
                                </p>
                            )}
                            {errors.fields.term_id && (
                                <p className="mt-0.5 text-xs text-destructive">
                                    {errors.fields.term_id}
                                </p>
                            )}
                        </div>

                        <div>
                            <Label htmlFor="fs-class-level">Class level</Label>
                            {mode === 'create' ? (
                                <select
                                    id="fs-class-level"
                                    className={SELECT_CLASS}
                                    value={classLevelId}
                                    onChange={(e) =>
                                        setClassLevelId(e.target.value)
                                    }
                                >
                                    {classLevels.map((level) => (
                                        <option key={level.id} value={level.id}>
                                            {level.name}
                                        </option>
                                    ))}
                                </select>
                            ) : (
                                <p className="pt-2 text-sm">
                                    {target?.class_level_label ?? '—'}
                                </p>
                            )}
                            {errors.fields.class_level_id && (
                                <p className="mt-0.5 text-xs text-destructive">
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
                            <p className="mt-0.5 text-xs text-destructive">
                                {errors.fields.label}
                            </p>
                        )}
                    </div>

                    <div className="space-y-2">
                        <div className="flex items-center justify-between">
                            <Label>Fee lines</Label>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    setRows((current) => [...current, newRow()])
                                }
                            >
                                <Plus className="mr-1 h-3.5 w-3.5" />
                                Add line
                            </Button>
                        </div>

                        {errors.fields.items && (
                            <p className="text-xs text-destructive">
                                {errors.fields.items}
                            </p>
                        )}

                        {rows.map((row, index) => (
                            <div
                                key={row.key}
                                className="space-y-2 rounded-md border p-3"
                                data-testid="fee-item-row"
                            >
                                <div className="grid gap-2 sm:grid-cols-12">
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
                                            <p className="mt-0.5 text-xs text-destructive">
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
                                            onChange={(e) =>
                                                patchRow(row.key, {
                                                    amount: e.target.value,
                                                })
                                            }
                                        />
                                        {errors.items[index]?.amount_minor && (
                                            <p className="mt-0.5 text-xs text-destructive">
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
                                        <select
                                            id={`fs-account-${row.key}`}
                                            className={SELECT_CLASS}
                                            value={row.bank_account_id}
                                            onChange={(e) =>
                                                patchRow(row.key, {
                                                    bank_account_id:
                                                        e.target.value,
                                                })
                                            }
                                        >
                                            <option value="">
                                                Choose an account…
                                            </option>
                                            {accountOptions(
                                                row.bank_account_id,
                                            ).map((account) => (
                                                <option
                                                    key={account.id}
                                                    value={account.id}
                                                >
                                                    {account.label} ·{' '}
                                                    {account.bank_name}
                                                </option>
                                            ))}
                                        </select>
                                        {errors.items[index]
                                            ?.bank_account_id && (
                                            <p className="mt-0.5 text-xs text-destructive">
                                                {
                                                    errors.items[index]
                                                        .bank_account_id
                                                }
                                            </p>
                                        )}
                                    </div>
                                </div>

                                <div className="flex flex-wrap items-center gap-4 text-sm">
                                    <label className="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            checked={row.is_mandatory}
                                            onChange={(e) =>
                                                patchRow(row.key, {
                                                    is_mandatory:
                                                        e.target.checked,
                                                })
                                            }
                                        />
                                        Mandatory
                                    </label>
                                    <label className="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            checked={row.is_discountable}
                                            onChange={(e) =>
                                                patchRow(row.key, {
                                                    is_discountable:
                                                        e.target.checked,
                                                })
                                            }
                                        />
                                        Discountable
                                    </label>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="ml-auto"
                                        disabled={rows.length === 1}
                                        onClick={() =>
                                            setRows((current) =>
                                                current.filter(
                                                    (r) => r.key !== row.key,
                                                ),
                                            )
                                        }
                                    >
                                        <X className="mr-1 h-3.5 w-3.5" />
                                        Remove line
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </div>

                    <div className="flex justify-end gap-2 border-t pt-3">
                        <Button
                            variant="outline"
                            onClick={() => setModalOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button onClick={submit} disabled={submitting}>
                            {submitting
                                ? 'Saving…'
                                : mode === 'edit'
                                  ? 'Save draft'
                                  : 'Create draft'}
                        </Button>
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
            >
                <div className="space-y-4">
                    <p className="text-sm text-muted-foreground">
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

                    <div className="flex justify-end gap-2 border-t pt-3">
                        <Button
                            variant="outline"
                            onClick={() => setProposal(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={sendProposal}
                            disabled={proposing || reason.trim() === ''}
                        >
                            {proposing ? 'Sending…' : 'Send to the ED'}
                        </Button>
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
