import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'react-toastify';
import { index as accountsIndex } from '@/actions/App/Finance/Http/Controllers/FinanceAccountController';
import { Pagination } from '@/components/pagination';
import { Card } from '@/components/ui/card';
import { formatNaira } from '@/lib/format';
import { statement } from '@/routes/admin/finance';
import type { AccountsPage, AccountStatus } from '@/types/finance';

const STATUS_OPTIONS: { value: '' | AccountStatus; label: string }[] = [
    { value: '', label: 'All accounts' },
    { value: 'outstanding', label: 'Outstanding' },
    { value: 'in_credit', label: 'In credit' },
    { value: 'settled', label: 'Settled' },
];

const EMPTY_META = { total: 0, per_page: 20, current_page: 1, last_page: 1 };

/**
 * The bursar's front door — a PURE CONSUMER of GET /api/v1/finance/accounts, bound to the
 * exact shapes the acceptance harness proves. It never computes money: the per-row balance
 * and available credit, and the two KPI totals, all arrive already-computed and are shown
 * via formatNaira. The KPIs are School-wide (all accounts), deliberately unaffected by the
 * search box or the status filter — those narrow only the table. Each row links to that
 * student's statement.
 */
export default function FinanceAccountsIndex() {
    const [page, setPageData] = useState<AccountsPage | null>(null);
    const [loading, setLoading] = useState(true);

    // The applied query (drives the fetch). `searchInput` is the live text box; it is only
    // committed to `search` on submit, so the list does not thrash on every keystroke.
    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<'' | AccountStatus>('');
    const [pageNo, setPageNo] = useState(1);
    const [limit, setLimit] = useState(20);

    const load = useCallback(async () => {
        setLoading(true);

        try {
            const { data } = await axios.get<AccountsPage>(
                accountsIndex.url({
                    query: {
                        search: search || undefined,
                        status: status || undefined,
                        page: pageNo,
                        per_page: limit,
                    },
                }),
            );
            setPageData(data);
        } catch {
            toast.error('Could not load accounts.');
        } finally {
            setLoading(false);
        }
    }, [search, status, pageNo, limit]);

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        void load();
    }, [load]);

    const meta = page?.pagination ?? EMPTY_META;
    const kpis = page?.kpis;
    const rows = page?.data ?? [];

    return (
        <>
            <Head title="Finance — accounts" />

            <div className="space-y-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Finance accounts</h1>
                    <p className="text-sm text-muted-foreground">
                        Every student account with ledger activity in your
                        school.
                    </p>
                </div>

                {/* KPI tiles — School-wide totals over ALL accounts, straight from the API. */}
                <div className="grid gap-3 sm:grid-cols-2">
                    <Card className="p-4">
                        <p className="text-xs text-muted-foreground">
                            Total receivables
                        </p>
                        <p className="text-lg font-semibold">
                            {kpis ? formatNaira(kpis.total_receivables) : '—'}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            Owed to the school across all accounts.
                        </p>
                    </Card>
                    <Card className="p-4">
                        <p className="text-xs text-muted-foreground">
                            Total credit
                        </p>
                        <p className="text-lg font-semibold">
                            {kpis ? formatNaira(kpis.total_credit) : '—'}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            Held on account for students (carries forward).
                        </p>
                    </Card>
                </div>

                {/* Filters — narrow the table only; the KPIs above are unaffected. */}
                <div className="flex flex-wrap items-center gap-3">
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            setPageNo(1);
                            setSearch(searchInput.trim());
                        }}
                        className="flex items-center gap-2"
                    >
                        <input
                            type="search"
                            value={searchInput}
                            onChange={(event) =>
                                setSearchInput(event.target.value)
                            }
                            placeholder="Search name or admission #"
                            className="h-9 w-64 rounded-md border border-input bg-background px-3 text-sm"
                        />
                    </form>
                    <select
                        value={status}
                        onChange={(event) => {
                            setPageNo(1);
                            setStatus(event.target.value as '' | AccountStatus);
                        }}
                        className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                    >
                        {STATUS_OPTIONS.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                </div>

                {loading && (
                    <p className="text-sm text-muted-foreground">Loading…</p>
                )}

                {!loading && rows.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        No accounts match this view.
                    </p>
                )}

                {rows.length > 0 && (
                    <div className="overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Student
                                    </th>
                                    <th className="px-3 py-2 text-right font-medium">
                                        Balance
                                    </th>
                                    <th className="px-3 py-2 text-right font-medium">
                                        Available credit
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Last activity
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => (
                                    <tr
                                        key={
                                            row.student.uuid ?? row.student.name
                                        }
                                        className="border-t"
                                    >
                                        <td className="px-3 py-2">
                                            {row.student.uuid ? (
                                                <Link
                                                    href={statement.url(
                                                        row.student.uuid,
                                                    )}
                                                    className="font-medium text-primary hover:underline"
                                                >
                                                    {row.student.name}
                                                </Link>
                                            ) : (
                                                <span className="font-medium">
                                                    {row.student.name}
                                                </span>
                                            )}
                                            {row.student.admission_number && (
                                                <span className="block text-xs text-muted-foreground">
                                                    {
                                                        row.student
                                                            .admission_number
                                                    }
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2 text-right font-semibold">
                                            {formatNaira(row.balance)}
                                        </td>
                                        <td className="px-3 py-2 text-right">
                                            {formatNaira(row.available_credit)}
                                        </td>
                                        <td className="px-3 py-2 text-muted-foreground">
                                            {row.last_activity
                                                ? new Date(
                                                      row.last_activity,
                                                  ).toLocaleDateString()
                                                : '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {meta.last_page > 1 && (
                    <Pagination
                        meta={meta}
                        setPage={setPageNo}
                        setLimit={(newLimit) => {
                            setPageNo(1);
                            setLimit(newLimit);
                        }}
                    />
                )}
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
