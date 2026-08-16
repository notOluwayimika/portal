import { Head } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertCircle,
    Ban,
    CheckCircle2,
    Landmark,
    Pencil,
    Plus,
    RefreshCw,
    RotateCcw,
    Save,
    Search,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';

import { FinanceStatCard } from '@/components/finance/finance-stat-card';
import { StatusPill } from '@/components/finance/status-pill';
import Select from '@/components/ui/base-dropdown';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import Modal from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/spinner';

/**
 * The school's bank accounts — where money lands, and the key a bursar reconciles a statement
 * against. S6/U3 commit 1: this screen exists so that commit 2, which makes `bank_account_id`
 * required on payments and fee items, has something to point at on the day it ships.
 *
 * THERE IS NO DELETE, and its absence is deliberate rather than unfinished. An account that has
 * received money must stay nameable forever — a March payment reconciled against it is
 * unexplainable in September if the account has been erased, and finance_payments is append-only so
 * the reference cannot be rewritten. Deactivating withdraws an account from choice and leaves it
 * readable, which is why a deactivated row stays on this list rather than disappearing from it.
 *
 * LAID OUT TO docs/ui-ux-design-system.md: page shell → hero card → KPI stat cards → filter/table
 * card, with loading, error and empty as three DISTINCT spanning rows rather than one spinner and
 * a toast. Nothing about the API contract or the write paths moved; this is the same five calls.
 */

type BankAccount = {
    id: string;
    label: string;
    bank_name: string;
    account_number: string;
    account_name: string | null;
    is_active: boolean;
    deactivated_at: string | null;
};

type FormState = {
    label: string;
    bank_name: string;
    account_number: string;
    account_name: string;
};

const EMPTY: FormState = {
    label: '',
    bank_name: '',
    account_number: '',
    account_name: '',
};

type StatusFilter = '' | 'active' | 'inactive';

const STATUS_OPTIONS: { label: string; value: StatusFilter }[] = [
    { label: 'All accounts', value: '' },
    { label: 'Active', value: 'active' },
    { label: 'Deactivated', value: 'inactive' },
];

export default function FinanceBankAccounts() {
    const [accounts, setAccounts] = useState<BankAccount[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);

    // FILTERED IN THE BROWSER, AND THAT IS SOUND HERE ONLY BECAUSE THERE IS NO PAGINATION.
    // GET /v1/finance/bank-accounts returns the School's whole list in one response and takes no
    // query parameters (BankAccountController::index), so the counts below are drawn from the same
    // array the rows are — there is no server total for a client filter to disagree with. The
    // design system's ban (§ 7) is on filtering a *paginated* list client-side; the day this
    // endpoint paginates, both of these move into the query string.
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<StatusFilter>('');

    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState<BankAccount | null>(null);
    const [form, setForm] = useState<FormState>(EMPTY);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [submitting, setSubmitting] = useState(false);

    const load = useCallback(async () => {
        setLoading(true);
        setError(false);

        try {
            const { data } = await axios.get('/api/v1/finance/bank-accounts');
            setAccounts(data.bank_accounts ?? []);
        } catch {
            setError(true);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        // Same disable the sibling accounts page carries (finance/index.tsx:95): the initial fetch
        // is the effect's whole purpose, and its loading flag is set synchronously inside it.
        // eslint-disable-next-line react-hooks/set-state-in-effect
        void load();
    }, [load]);

    const openCreate = () => {
        setEditing(null);
        setForm(EMPTY);
        setErrors({});
        setModalOpen(true);
    };

    const openEdit = (account: BankAccount) => {
        setEditing(account);
        setForm({
            label: account.label,
            bank_name: account.bank_name,
            account_number: account.account_number,
            account_name: account.account_name ?? '',
        });
        setErrors({});
        setModalOpen(true);
    };

    const submit = async () => {
        setSubmitting(true);
        setErrors({});

        try {
            if (editing) {
                await axios.patch(
                    `/api/v1/finance/bank-accounts/${editing.id}`,
                    form,
                );
                toast.success('Bank account updated.');
            } else {
                await axios.post('/api/v1/finance/bank-accounts', form);
                toast.success('Bank account added.');
            }

            setModalOpen(false);
            await load();
        } catch (err: unknown) {
            if (axios.isAxiosError(err) && err.response?.status === 422) {
                const bag = err.response.data?.errors ?? {};
                setErrors(
                    Object.fromEntries(
                        Object.entries(bag).map(([k, v]) => [
                            k,
                            Array.isArray(v) ? v[0] : String(v),
                        ]),
                    ),
                );
            } else {
                toast.error('Could not save the bank account.');
            }
        } finally {
            setSubmitting(false);
        }
    };

    // Deactivate and reactivate are the only retirement controls. Neither removes a row.
    const setActive = async (account: BankAccount, active: boolean) => {
        try {
            await axios.post(
                `/api/v1/finance/bank-accounts/${account.id}/${active ? 'reactivate' : 'deactivate'}`,
            );
            toast.success(
                active
                    ? 'Bank account reactivated.'
                    : 'Bank account deactivated.',
            );
            await load();
        } catch {
            toast.error('Could not change the account’s status.');
        }
    };

    const activeCount = accounts.filter((a) => a.is_active).length;
    const deactivatedCount = accounts.filter((a) => !a.is_active).length;

    const term = search.trim().toLowerCase();
    const rows = accounts.filter((a) => {
        if (status === 'active' && !a.is_active) {
            return false;
        }

        if (status === 'inactive' && a.is_active) {
            return false;
        }

        if (term === '') {
            return true;
        }

        return [a.label, a.bank_name, a.account_number, a.account_name ?? '']
            .join(' ')
            .toLowerCase()
            .includes(term);
    });

    const hasFilters = term !== '' || status !== '';

    const clearFilters = () => {
        setSearch('');
        setStatus('');
    };

    return (
        <>
            <Head title="Bank accounts" />

            <div className="min-h-screen bg-[#f5f7fb] px-4 py-5 pb-24 sm:px-6 lg:px-8 dark:bg-background">
                <div className="mx-auto max-w-7xl space-y-5">
                    {/* ── Hero Card ─────────────────────────────────────────────── */}
                    <div className="relative overflow-hidden rounded-2xl border border-white bg-white px-6 py-4 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:border-white/5 dark:bg-card">
                        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div className="flex items-center gap-4">
                                <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-linear-to-br from-indigo-50 to-violet-50 shadow-sm ring-1 ring-black/5 dark:from-indigo-950/50 dark:to-violet-950/50">
                                    <Landmark className="h-6 w-6 text-indigo-600 dark:text-indigo-400" />
                                </div>
                                <div>
                                    <h1 className="text-xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                                        Bank accounts
                                    </h1>
                                    <p className="text-xs text-slate-500">
                                        Where this school receives money — the
                                        accounts a bursar reconciles a bank
                                        statement against.
                                    </p>
                                </div>
                            </div>

                            {/* Every bank-account route — read and write alike — is gated on the
                                one ability `finance.bank-account.manage` (routes/endpoints/
                                finance.php), which the page route already requires. A <Can> here
                                would gate on the permission that got the operator to this screen,
                                so there is no action below the backend would 403. */}
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
                                    Add account
                                </Button>
                            </div>
                        </div>
                    </div>

                    {/* ── KPI Stat Cards ───────────────────────────────────────── */}
                    {/* Row counts, not money — nothing here is summed, and the whole list is in
                        hand (the endpoint does not paginate), so these describe every account the
                        school has rather than a page of them.

                        A FAILED LOAD RENDERS AN EM DASH, NEVER A NUMBER. `accounts` is [] both when
                        the school has no accounts and when the fetch died, so `String(0)` here is a
                        count the page does not actually have — the exact empty-vs-error confusion
                        the table's error state was added to remove, one region higher up the page.
                        A dash is the repo's existing "no value" convention (the table's own cells
                        render `account_name ?? '—'`) and cannot be read as a real zero. It is not a
                        skeleton, because a skeleton says "still arriving" and would pulse forever
                        beside a Retry button; and the card is not suppressed, because the label is
                        what tells the reader WHICH figure is missing. */}
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <FinanceStatCard
                            icon={Landmark}
                            tone="indigo"
                            label="Bank accounts"
                            value={error ? '—' : String(accounts.length)}
                            subText="Every account this school has added"
                            loading={loading && accounts.length === 0}
                        />
                        <FinanceStatCard
                            icon={CheckCircle2}
                            tone="emerald"
                            label="Active"
                            value={error ? '—' : String(activeCount)}
                            subText="Offered as a destination for fees and payments"
                            loading={loading && accounts.length === 0}
                        />
                        <FinanceStatCard
                            icon={Ban}
                            tone="slate"
                            label="Deactivated"
                            value={error ? '—' : String(deactivatedCount)}
                            subText="Withdrawn from choice, still nameable on old payments"
                            loading={loading && accounts.length === 0}
                        />
                    </div>

                    {/* ── Filters + Table Card ─────────────────────────────────── */}
                    <div className="overflow-hidden rounded-xl border-none bg-white shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card">
                        <div className="border-b border-slate-100 dark:border-slate-800">
                            <div className="flex flex-col gap-3 px-5 py-3 sm:flex-row sm:items-center">
                                <div className="relative w-full sm:max-w-md sm:flex-1">
                                    <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                                    <Input
                                        placeholder="Search by label, bank or account number…"
                                        aria-label="Search bank accounts"
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
                                        placeholder="All accounts"
                                        options={STATUS_OPTIONS}
                                    />
                                </div>

                                <div className="flex items-center gap-2 sm:ml-auto">
                                    {/* SUPPRESSED ON A FAILED LOAD, for the same reason the cards
                                        render a dash: both numbers come from an array that is empty
                                        because the fetch died, so "Showing 0 of 0" states a count
                                        the page does not have. There is no honest number to show —
                                        the error row below says what happened — so the counter is
                                        not rendered at all rather than dashed. */}
                                    {!error && (
                                        <span className="hidden text-xs font-medium text-slate-500 sm:inline">
                                            Showing{' '}
                                            <span className="font-bold text-slate-700 dark:text-slate-200">
                                                {rows.length}
                                            </span>{' '}
                                            of{' '}
                                            <span className="font-bold text-slate-700 dark:text-slate-200">
                                                {accounts.length}
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
                                            Account
                                        </th>
                                        <th className="px-4 py-2.5 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Account number
                                        </th>
                                        <th className="px-4 py-2.5 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Account name
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
                                                colSpan={5}
                                                className="py-12 text-center"
                                            >
                                                <Spinner className="mx-auto" />
                                            </td>
                                        </tr>
                                    ) : error ? (
                                        <tr>
                                            <td colSpan={5} className="py-12">
                                                <div className="flex flex-col items-center gap-3 text-center">
                                                    <div className="flex size-12 items-center justify-center rounded-full bg-red-50 text-red-500 dark:bg-red-900/20">
                                                        <AlertCircle className="h-6 w-6" />
                                                    </div>
                                                    <div>
                                                        <p className="text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                            Could not load bank
                                                            accounts
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
                                    ) : rows.length === 0 ? (
                                        <tr>
                                            <td colSpan={5} className="py-12">
                                                <div className="flex flex-col items-center gap-3 text-center">
                                                    <div className="flex size-12 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-slate-800">
                                                        <Landmark className="h-6 w-6" />
                                                    </div>
                                                    <div>
                                                        <p className="text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                            No bank accounts to
                                                            show
                                                        </p>
                                                        <p className="text-xs text-slate-500">
                                                            {hasFilters
                                                                ? 'No accounts match this view. Try clearing the filters.'
                                                                : 'Add the account fees are paid into — it is what a fee line and a payment point at.'}
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
                                                            Add account
                                                        </Button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ) : (
                                        rows.map((a) => (
                                            <tr
                                                key={a.id}
                                                className="transition-colors hover:bg-slate-50/60 dark:hover:bg-slate-900/30"
                                                data-testid="bank-account-row"
                                            >
                                                <td className="px-4 py-2.5">
                                                    <p className="font-semibold text-slate-700 dark:text-slate-200">
                                                        {a.label}
                                                    </p>
                                                    <p className="text-[11px] text-slate-400">
                                                        {a.bank_name}
                                                    </p>
                                                </td>
                                                <td className="px-4 py-2.5 font-mono text-slate-600 tabular-nums dark:text-slate-300">
                                                    {a.account_number}
                                                </td>
                                                <td className="px-4 py-2.5 text-slate-600 dark:text-slate-300">
                                                    {a.account_name ?? '—'}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    {/*
                                                     * A DEACTIVATED ACCOUNT STAYS ON THIS LIST. It is
                                                     * withdrawn from choice, not erased — the row is still
                                                     * the only thing that can explain a payment reconciled
                                                     * against it months ago.
                                                     */}
                                                    <StatusPill
                                                        tone={
                                                            a.is_active
                                                                ? 'emerald'
                                                                : 'slate'
                                                        }
                                                        label={
                                                            a.is_active
                                                                ? 'Active'
                                                                : 'Deactivated'
                                                        }
                                                    />
                                                </td>
                                                <td className="px-4 py-2.5 text-right whitespace-nowrap">
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="h-7 w-7"
                                                        title={`Edit ${a.label}`}
                                                        aria-label={`Edit ${a.label}`}
                                                        onClick={() =>
                                                            openEdit(a)
                                                        }
                                                    >
                                                        <Pencil className="h-3.5 w-3.5" />
                                                    </Button>
                                                    {a.is_active ? (
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="h-7 w-7 text-slate-500 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/20 dark:hover:text-red-400"
                                                            title={`Deactivate ${a.label}`}
                                                            aria-label={`Deactivate ${a.label}`}
                                                            onClick={() =>
                                                                void setActive(
                                                                    a,
                                                                    false,
                                                                )
                                                            }
                                                        >
                                                            <Ban className="h-3.5 w-3.5" />
                                                        </Button>
                                                    ) : (
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="h-7 w-7 text-slate-500 hover:bg-emerald-50 hover:text-emerald-600 dark:hover:bg-emerald-900/20 dark:hover:text-emerald-400"
                                                            title={`Reactivate ${a.label}`}
                                                            aria-label={`Reactivate ${a.label}`}
                                                            onClick={() =>
                                                                void setActive(
                                                                    a,
                                                                    true,
                                                                )
                                                            }
                                                        >
                                                            <RotateCcw className="h-3.5 w-3.5" />
                                                        </Button>
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
                title={editing ? `Edit ${editing.label}` : 'Add a bank account'}
                size="lg"
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
                                : editing
                                  ? 'Save changes'
                                  : 'Add account'}
                        </Button>
                    </div>
                }
            >
                <div className="space-y-4">
                    {!editing && (
                        <p className="rounded-lg bg-slate-50 p-3 text-xs text-slate-500 dark:bg-slate-900/40 dark:text-slate-400">
                            The bank name and account number are what a bursar
                            matches against a bank statement, so they are
                            required and cannot be changed afterwards. The label
                            is what appears everywhere else in the portal.
                        </p>
                    )}

                    {/*
                     * THE IDENTITY FIELDS ARE NOT RENDERED WHEN EDITING — not rendered disabled,
                     * not rendered readonly. A disabled input that still posts its value is not a
                     * guard, and a readonly one still tells the operator the field is theirs to
                     * argue with. On edit they are shown below as plain text, which is what they
                     * are: a fact about the account, not a field.
                     *
                     * KEPT INLINE, not hoisted to a `const` above the return. BankAccountTest's
                     * LAYER 3 arm reads this file as text and splits it on `{(editing` to isolate
                     * the edit branch — the guard is asserted on the SHAPE that produces it, so
                     * moving the ternary out of the JSX blinds the arm rather than failing it.
                     */}
                    {(editing
                        ? ([
                              ['label', 'Label', 'Zenith — Fees'],
                              [
                                  'account_name',
                                  'Account name (optional)',
                                  'Brookstone Schools Ltd',
                              ],
                          ] as const)
                        : ([
                              ['label', 'Label', 'Zenith — Fees'],
                              ['bank_name', 'Bank', 'Zenith Bank'],
                              [
                                  'account_number',
                                  'Account number',
                                  '1234567890',
                              ],
                              [
                                  'account_name',
                                  'Account name (optional)',
                                  'Brookstone Schools Ltd',
                              ],
                          ] as const)
                    ).map(([field, labelText, placeholder]) => (
                        <div key={field}>
                            <Label htmlFor={`ba-${field}`}>{labelText}</Label>
                            <Input
                                id={`ba-${field}`}
                                value={form[field]}
                                placeholder={placeholder}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        [field]: e.target.value,
                                    })
                                }
                            />
                            {errors[field] && (
                                <p className="mt-1 text-xs text-destructive">
                                    {errors[field]}
                                </p>
                            )}
                        </div>
                    ))}

                    {editing && (
                        <div className="rounded-lg border border-slate-100 bg-slate-50 p-3 dark:border-slate-800 dark:bg-slate-900/40">
                            <p className="text-sm font-semibold text-slate-700 dark:text-slate-200">
                                {editing.bank_name} · {editing.account_number}
                            </p>
                            <p className="mt-1 text-xs text-slate-500">
                                The bank and account number cannot be changed —
                                they are what a bank statement is matched
                                against, so changing them would rewrite where
                                past payments went. If this school&rsquo;s
                                banking details have changed, deactivate this
                                account and add a new one.
                            </p>
                        </div>
                    )}
                </div>
            </Modal>
        </>
    );
}

FinanceBankAccounts.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'Bank accounts', href: '/finance/bank-accounts' },
    ],
};
