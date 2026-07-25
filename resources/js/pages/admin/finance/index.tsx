import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertCircle,
    ArrowDownWideNarrow,
    ArrowUpNarrowWide,
    Eye,
    Landmark,
    RefreshCw,
    Search,
    ShieldCheck,
    TrendingUp,
    Wallet,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { index as accountsIndex } from '@/actions/App/Finance/Http/Controllers/FinanceAccountController';
import { Can } from '@/components/can';
import { AccountStatusBadge } from '@/components/finance/account-status-badge';
import { FinanceStatCard } from '@/components/finance/finance-stat-card';
import { Pagination } from '@/components/pagination';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import Select from '@/components/ui/base-dropdown';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useInitials } from '@/hooks/use-initials';
import { formatNaira } from '@/lib/format';
import type { AccountsPage, AccountStatus } from '@/types/finance';

type SortDir = 'asc' | 'desc';

const STATUS_OPTIONS: { label: string; value: '' | AccountStatus }[] = [
    { label: 'All accounts', value: '' },
    { label: 'Outstanding', value: 'outstanding' },
    { label: 'In credit', value: 'in_credit' },
    { label: 'Settled', value: 'settled' },
];

const EMPTY_META = {
    total: 0,
    per_page: 20,
    current_page: 1,
    last_page: 1,
};

/**
 * The bursar's front door — a PURE CONSUMER of GET /api/v1/finance/accounts, restyled to the
 * Student-module design language (hero card + KPI stat cards + filter/table card). It never
 * computes money: the per-row balance / available credit and the two KPI totals arrive
 * already-computed and are shown via formatNaira. The KPIs are School-wide (all accounts),
 * deliberately unaffected by the search box or status filter — those narrow only the table.
 * Balance is the one server-sortable column; each row links to that student's statement.
 */
export default function FinanceAccountsIndex() {
    const getInitials = useInitials();
    const [page, setPageData] = useState<AccountsPage | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);

    // The applied query (drives the fetch). `searchInput` is the live text box; it commits to
    // `search` on submit so the list does not thrash on every keystroke.
    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<'' | AccountStatus>('');
    const [sort, setSort] = useState<SortDir>('desc');
    const [pageNo, setPageNo] = useState(1);
    const [limit, setLimit] = useState(20);

    const load = useCallback(async () => {
        setLoading(true);
        setError(false);

        try {
            const { data } = await axios.get<AccountsPage>(
                accountsIndex.url({
                    query: {
                        search: search || undefined,
                        status: status || undefined,
                        sort,
                        page: pageNo,
                        per_page: limit,
                    },
                }),
            );
            setPageData(data);
        } catch {
            setError(true);
        } finally {
            setLoading(false);
        }
    }, [search, status, sort, pageNo, limit]);

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        void load();
    }, [load]);

    const meta = page?.pagination ?? EMPTY_META;
    const kpis = page?.kpis;
    const rows = page?.data ?? [];
    const hasFilters = search !== '' || status !== '';

    const toggleSort = () => {
        setPageNo(1);
        setSort((prev) => (prev === 'desc' ? 'asc' : 'desc'));
    };

    const clearFilters = () => {
        setSearchInput('');
        setSearch('');
        setStatus('');
        setPageNo(1);
    };

    return (
        <>
            <Head title="Finance" />

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
                                        Finance accounts
                                    </h1>
                                    <p className="text-xs text-slate-500">
                                        Every student account with ledger
                                        activity in your school.
                                    </p>
                                </div>
                            </div>

                            <div className="flex shrink-0 flex-wrap items-center gap-2">
                                <Can permission="finance.credit-note.approve">
                                    <Button
                                        asChild
                                        size="sm"
                                        variant="outline"
                                        className="rounded-lg border-slate-200 font-semibold text-slate-700 transition-all hover:bg-slate-50 hover:text-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-white"
                                    >
                                        <Link href="/finance/approvals">
                                            <ShieldCheck className="mr-1.5 h-4 w-4" />
                                            Pending approvals
                                        </Link>
                                    </Button>
                                </Can>
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
                            </div>
                        </div>
                    </div>

                    {/* ── KPI Stat Cards ───────────────────────────────────────── */}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FinanceStatCard
                            icon={TrendingUp}
                            tone="amber"
                            label="Total receivables"
                            value={
                                kpis ? formatNaira(kpis.total_receivables) : '—'
                            }
                            subText="Owed to the school across all accounts"
                            loading={loading && !page}
                        />
                        <FinanceStatCard
                            icon={Wallet}
                            tone="emerald"
                            label="Total credit"
                            value={kpis ? formatNaira(kpis.total_credit) : '—'}
                            subText="Held on account for students (carries forward)"
                            loading={loading && !page}
                        />
                    </div>

                    {/* ── Filters + Table Card ─────────────────────────────────── */}
                    <div className="overflow-hidden rounded-xl border-none bg-white shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card">
                        {/* Search / filter row */}
                        <div className="border-b border-slate-100 dark:border-slate-800">
                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    setPageNo(1);
                                    setSearch(searchInput.trim());
                                }}
                                className="flex flex-col gap-3 px-5 py-3 sm:flex-row sm:items-center"
                            >
                                <div className="relative w-full sm:max-w-md sm:flex-1">
                                    <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                                    <Input
                                        placeholder="Search by name or admission number…"
                                        className="h-9 rounded-lg border-slate-200 bg-white pl-9 text-sm focus-visible:ring-2 focus-visible:ring-indigo-100 dark:border-slate-700 dark:bg-slate-900"
                                        value={searchInput}
                                        onChange={(e) =>
                                            setSearchInput(e.target.value)
                                        }
                                    />
                                </div>

                                <div className="w-full sm:w-44">
                                    <Select
                                        value={status}
                                        onChange={(val) => {
                                            setStatus(
                                                (val ? String(val) : '') as
                                                    | ''
                                                    | AccountStatus,
                                            );
                                            setPageNo(1);
                                        }}
                                        placeholder="All accounts"
                                        options={STATUS_OPTIONS}
                                    />
                                </div>

                                <div className="flex items-center gap-2 sm:ml-auto">
                                    <span className="hidden text-xs font-medium text-slate-500 sm:inline">
                                        Showing{' '}
                                        <span className="font-bold text-slate-700 dark:text-slate-200">
                                            {rows.length}
                                        </span>{' '}
                                        of{' '}
                                        <span className="font-bold text-slate-700 dark:text-slate-200">
                                            {meta.total}
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
                            </form>
                        </div>

                        {/* Table */}
                        <div className="custom-scrollbar overflow-x-auto">
                            <table className="w-full text-xs">
                                <thead>
                                    <tr className="border-b border-slate-100 bg-slate-50/50 dark:border-slate-800 dark:bg-slate-900/30">
                                        <th className="sticky top-0 px-4 py-2.5 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Student
                                        </th>
                                        <th className="px-4 py-2.5 text-right text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            <button
                                                type="button"
                                                onClick={toggleSort}
                                                className="ml-auto inline-flex items-center gap-1 transition-colors hover:text-slate-600 dark:hover:text-slate-200"
                                            >
                                                Balance
                                                {sort === 'desc' ? (
                                                    <ArrowDownWideNarrow className="h-3.5 w-3.5" />
                                                ) : (
                                                    <ArrowUpNarrowWide className="h-3.5 w-3.5" />
                                                )}
                                            </button>
                                        </th>
                                        <th className="px-4 py-2.5 text-right text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Available credit
                                        </th>
                                        <th className="px-4 py-2.5 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Status
                                        </th>
                                        <th className="px-4 py-2.5 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Last activity
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
                                            <td colSpan={6} className="py-12">
                                                <div className="flex flex-col items-center gap-3 text-center">
                                                    <div className="flex size-12 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-slate-800">
                                                        <Wallet className="h-6 w-6" />
                                                    </div>
                                                    <div>
                                                        <p className="text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                            No accounts to show
                                                        </p>
                                                        <p className="text-xs text-slate-500">
                                                            {hasFilters
                                                                ? 'No accounts match this view. Try clearing the filters.'
                                                                : 'Accounts appear here once students have ledger activity.'}
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
                                        rows.map((row) => (
                                            <tr
                                                key={
                                                    row.student.uuid ??
                                                    row.student.name
                                                }
                                                className="transition-colors hover:bg-slate-50/60 dark:hover:bg-slate-900/30"
                                            >
                                                <td className="px-4 py-2.5">
                                                    <div className="flex items-center gap-2.5">
                                                        <Avatar className="size-7 shrink-0 overflow-hidden rounded-full">
                                                            <AvatarFallback className="rounded-full bg-neutral-200 text-[10px] text-black dark:bg-neutral-700 dark:text-white">
                                                                {getInitials(
                                                                    row.student
                                                                        .name,
                                                                )}
                                                            </AvatarFallback>
                                                        </Avatar>
                                                        <div className="min-w-0">
                                                            {row.student
                                                                .uuid ? (
                                                                <Link
                                                                    href={`/finance/students/${row.student.uuid}/statement`}
                                                                    className="font-semibold text-slate-700 transition-colors hover:text-primary hover:underline dark:text-slate-200"
                                                                >
                                                                    {
                                                                        row
                                                                            .student
                                                                            .name
                                                                    }
                                                                </Link>
                                                            ) : (
                                                                <span className="font-semibold text-slate-700 dark:text-slate-200">
                                                                    {
                                                                        row
                                                                            .student
                                                                            .name
                                                                    }
                                                                </span>
                                                            )}
                                                            <p className="text-[11px] text-slate-400">
                                                                {row.student
                                                                    .admission_number ??
                                                                    '—'}
                                                            </p>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="px-4 py-2.5 text-right font-semibold text-slate-800 tabular-nums dark:text-slate-100">
                                                    {formatNaira(row.balance)}
                                                </td>
                                                <td className="px-4 py-2.5 text-right text-slate-600 tabular-nums dark:text-slate-300">
                                                    {formatNaira(
                                                        row.available_credit,
                                                    )}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    <AccountStatusBadge
                                                        balance={row.balance}
                                                    />
                                                </td>
                                                <td className="px-4 py-2.5 text-slate-500">
                                                    {row.last_activity
                                                        ? new Date(
                                                              row.last_activity,
                                                          ).toLocaleDateString()
                                                        : '—'}
                                                </td>
                                                <td className="px-4 py-2.5 text-right">
                                                    {row.student.uuid && (
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="h-7 w-7"
                                                            title="View statement"
                                                            asChild
                                                        >
                                                            <Link
                                                                href={`/finance/students/${row.student.uuid}/statement`}
                                                            >
                                                                <Eye className="h-3.5 w-3.5" />
                                                            </Link>
                                                        </Button>
                                                    )}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>

                        <div className="border-t border-slate-50 bg-slate-50/30 px-5 py-3 dark:border-slate-800 dark:bg-slate-900/30">
                            <Pagination
                                meta={meta}
                                setPage={setPageNo}
                                setLimit={(newLimit) => {
                                    setPageNo(1);
                                    setLimit(newLimit);
                                }}
                            />
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}

FinanceAccountsIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
    ],
};
