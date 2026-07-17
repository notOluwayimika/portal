import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { Download, Upload, UserPlus, Users } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'react-toastify';
import { AddStandaloneGuardianModal } from '@/components/guardians/add-standalone-guardian-modal';
import { BulkActionBar } from '@/components/guardians/bulk-action-bar';
import { GuardianFilterBar } from '@/components/guardians/guardian-filter-bar';
import { GuardianTable } from '@/components/guardians/guardian-table';
import { MessageComposerModal } from '@/components/guardians/message-composer-modal';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import type { Guardian } from '@/types/models';

interface Option { name: string; value: string; }

interface Props {
    guardian_statuses: Option[];
}

type SortCol = 'name' | 'phone' | 'students_count' | 'login' | 'created_at';

const DEFAULT_PAGINATION = {
    current_page: 1, last_page: 1, per_page: 25,
    total: 0, prev_page_url: null, next_page_url: null,
};

export default function GuardianIndex({ guardian_statuses }: Props) {
    const [guardians, setGuardians]     = useState<Guardian[]>([]);
    const [loading, setLoading]         = useState(true);
    const [pagination, setPagination]   = useState(DEFAULT_PAGINATION);
    const [page, setPage]               = useState(1);
    const [limit, setLimit]             = useState(25);

    // Filters
    const [search, setSearch]                   = useState('');
    const [statusFilter, setStatusFilter]       = useState('');
    const [loginFilter, setLoginFilter]         = useState('');
    const [childrenFilter, setChildrenFilter]   = useState('');
    const [dateFrom, setDateFrom]               = useState('');
    const [dateTo, setDateTo]                   = useState('');
    const [sortBy, setSortBy]                   = useState<SortCol>('created_at');
    const [sortDir, setSortDir]                 = useState<'asc' | 'desc'>('desc');

    // Selection
    const [selectedIds, setSelectedIds]         = useState<Set<string>>(new Set());
    const [selectAllMatching, setSelectAllMatching] = useState(false);

    // Modals
    const [showAdd, setShowAdd]         = useState(false);
    const [showMessage, setShowMessage] = useState(false);


    const fetchGuardians = async () => {
        setLoading(true);

        try {
            const res = await axios.get('/api/guardians', {
                params: {
                    search, page, per_page: limit,
                    status: statusFilter || undefined,
                    login_access: loginFilter || undefined,
                    children_count: childrenFilter || undefined,
                    date_from: dateFrom || undefined,
                    date_to: dateTo || undefined,
                    sort_by: sortBy,
                    sort_dir: sortDir,
                },
            });
            setGuardians(res.data.data ?? []);
            setPagination(res.data.pagination ?? DEFAULT_PAGINATION);
        } catch {
            toast.error('Failed to fetch guardians');
        } finally {
            setLoading(false);
        }
    };

    // Reset to page 1 + clear selection when filters change
    const filtersRef = useRef({ search, statusFilter, loginFilter, childrenFilter, dateFrom, dateTo, sortBy, sortDir });
    useEffect(() => {
        const prev = filtersRef.current;
        const filterChanged = prev.search !== search || prev.statusFilter !== statusFilter ||
            prev.loginFilter !== loginFilter || prev.childrenFilter !== childrenFilter ||
            prev.dateFrom !== dateFrom || prev.dateTo !== dateTo ||
            prev.sortBy !== sortBy || prev.sortDir !== sortDir;
        filtersRef.current = { search, statusFilter, loginFilter, childrenFilter, dateFrom, dateTo, sortBy, sortDir };

        if (filterChanged) {
            setPage(1);
            setSelectedIds(new Set());
            setSelectAllMatching(false);
        }

    }, [search, statusFilter, loginFilter, childrenFilter, dateFrom, dateTo, sortBy, sortDir]);

    useEffect(() => {
        fetchGuardians();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search, page, limit, statusFilter, loginFilter, childrenFilter, dateFrom, dateTo, sortBy, sortDir]);

    const handleSort = (col: SortCol) => {
        if (col === sortBy) {
setSortDir(d => d === 'asc' ? 'desc' : 'asc');
} else {
 setSortBy(col); setSortDir('desc');
}
    };

    const toggleSelect = (id: string) => {
        setSelectAllMatching(false);
        setSelectedIds(prev => {
            const next = new Set(prev);
            next.has(id) ? next.delete(id) : next.add(id);

            return next;
        });
    };

    const toggleAll = (checked: boolean) => {
        setSelectAllMatching(false);
        setSelectedIds(checked ? new Set(guardians.map(g => g.id)) : new Set());
    };

    const isAllSelected = guardians.length > 0 && guardians.every(g => selectedIds.has(g.id));

    // Resolve which guardian IDs to send for bulk ops.
    // When selectAllMatching=true we send the IDs of the current page as a proxy;
    // the server side should ideally support a "select all matching" flag, but for now
    // we just send the visible page selection.
    const selectedNumericIds = () => {
        // GuardianTable uses uuid as id; backend bulk routes accept numeric ids.
        // We need the numeric id from the guardian objects.
        const uuids = selectAllMatching ? guardians.map(g => g.id) : Array.from(selectedIds);

        return guardians.filter(g => uuids.includes(g.id)).map(g => g.id);
    };

    const bulkPost = async (url: string, extra: Record<string, unknown> = {}) => {
        try {
            const ids = selectedNumericIds();
            await axios.post(url, { guardian_ids: ids, ...extra });
            toast.success('Action completed successfully');
            setSelectedIds(new Set());
            setSelectAllMatching(false);
            fetchGuardians();
        } catch {
            toast.error('Action failed');
        }
    };

    const handleExport = () => {
        const params = new URLSearchParams({
            search, status: statusFilter, login_access: loginFilter,
            children_count: childrenFilter, date_from: dateFrom, date_to: dateTo,
        });
        window.location.href = `/api/guardians/export?${params}`;
    };

    const handleSingleAction = async (action: string, guardian: Guardian) => {
        if (action === 'enable-login') {
            if (!window.confirm(`Enable login for ${guardian.full_name}?`)) {
return;
}

            try {
                await axios.post(`/api/guardians/${guardian.id}/enable-login`);
                toast.success('Login enabled');
                fetchGuardians();
            } catch {
 toast.error('Failed');
}
        } else if (action === 'disable-login') {
            if (!window.confirm(`Disable login for ${guardian.full_name}?`)) {
return;
}

            try {
                await axios.post(`/api/guardians/${guardian.id}/disable-login`);
                toast.success('Login disabled');
                fetchGuardians();
            } catch {
 toast.error('Failed');
}
        } else if (action.startsWith('status-')) {
            const status = action.replace('status-', '');

            try {
                await axios.patch(`/api/guardians/${guardian.id}`, { status });
                toast.success(`Status updated to ${status}`);
                fetchGuardians();
            } catch {
 toast.error('Failed');
}
        }
    };

    const selectedCount = selectAllMatching ? pagination.total : selectedIds.size;

    return (
        <>
            <Head title="Guardians" />

            <div className="min-h-screen bg-[#f5f7fb] py-5 px-4 sm:px-6 lg:px-8 pb-24 dark:bg-background">
                <div className="mx-auto max-w-7xl space-y-5">

                    {/* ── Hero Card ─────────────────────────────────────────────── */}
                    <div className="relative overflow-hidden rounded-2xl border border-white bg-white px-6 py-4 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:border-white/5 dark:bg-card">
                        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div className="flex items-center gap-4">
                                <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-50 to-violet-50 shadow-sm ring-1 ring-black/5 dark:from-indigo-950/50 dark:to-violet-950/50">
                                    <Users className="h-6 w-6 text-indigo-600" />
                                </div>
                                <div>
                                    <h1 className="text-xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                                        Guardians
                                    </h1>
                                    <p className="text-xs text-slate-500">
                                        Manage guardian accounts, login access, and student links.
                                    </p>
                                </div>
                            </div>

                            <div className="flex shrink-0 flex-wrap items-center gap-2">
                                <Link href="/guardians/import">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        type="button"
                                        className="rounded-lg border-slate-200 font-semibold text-slate-700 transition-all hover:bg-slate-50 hover:text-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-white"
                                    >
                                        <Upload className="mr-1.5 h-4 w-4" />
                                        Import
                                    </Button>
                                </Link>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={handleExport}
                                    className="rounded-lg border-slate-200 font-semibold text-slate-700 transition-all hover:bg-slate-50 hover:text-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-white"
                                >
                                    <Download className="mr-1.5 h-4 w-4" />
                                    Export
                                </Button>
                                <Button
                                    size="sm"
                                    onClick={() => setShowAdd(true)}
                                    className="rounded-lg bg-indigo-600 px-4 font-semibold text-white shadow-md transition-all hover:bg-indigo-700 hover:shadow-lg active:scale-95"
                                >
                                    <UserPlus className="mr-1.5 h-4 w-4" />
                                    Add Guardian
                                </Button>
                            </div>
                        </div>
                    </div>

                    {/* ── Filters + Table Card ─────────────────────────────────── */}
                    <div className="overflow-hidden rounded-xl border-none bg-white shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card">
                        <GuardianFilterBar
                            search={search}
                            onSearch={setSearch}
                            statusFilter={statusFilter}
                            onStatusFilter={setStatusFilter}
                            loginFilter={loginFilter}
                            onLoginFilter={setLoginFilter}
                            childrenFilter={childrenFilter}
                            onChildrenFilter={setChildrenFilter}
                            dateFrom={dateFrom}
                            onDateFrom={setDateFrom}
                            dateTo={dateTo}
                            onDateTo={setDateTo}
                            total={pagination.total}
                            showing={guardians.length}
                            guardianStatuses={guardian_statuses}
                        />

                        <GuardianTable
                            guardians={guardians}
                            loading={loading}
                            selectedIds={selectedIds}
                            onToggleSelect={toggleSelect}
                            onToggleAll={toggleAll}
                            isAllSelected={isAllSelected}
                            sortBy={sortBy}
                            sortDir={sortDir}
                            onSort={handleSort}
                            onSingleAction={handleSingleAction}
                        />

                        <div className="border-t border-slate-50 bg-slate-50/30 px-5 py-3 dark:border-slate-800 dark:bg-slate-900/30">
                            <Pagination meta={pagination} setPage={setPage} setLimit={setLimit} />
                        </div>
                    </div>
                </div>
            </div>

            <BulkActionBar
                count={selectedCount}
                totalMatching={pagination.total}
                selectAllMatching={selectAllMatching}
                onSelectAllMatching={() => setSelectAllMatching(true)}
                onClearSelection={() => {
 setSelectedIds(new Set()); setSelectAllMatching(false);
}}
                onMessage={() => setShowMessage(true)}
                onExport={handleExport}
                onEnableLogin={() => bulkPost('/api/guardians/bulk-enable-login')}
                onDisableLogin={() => bulkPost('/api/guardians/bulk-disable-login')}
                onChangeStatus={(s) => bulkPost('/api/guardians/bulk-status', { status: s })}
            />

            <AddStandaloneGuardianModal
                isOpen={showAdd}
                onClose={() => setShowAdd(false)}
            />

            <MessageComposerModal
                isOpen={showMessage}
                onClose={() => setShowMessage(false)}
                guardianIds={Array.from(selectedIds) as unknown as number[]}
                onSent={() => toast.success('Message queued successfully')}
            />

        </>
    );
}

GuardianIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Guardians', href: '/guardians' },
    ],
};
