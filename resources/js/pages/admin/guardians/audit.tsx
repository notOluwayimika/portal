import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { ArrowLeft, Download } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { AuditEntry } from '@/components/guardians/audit-timeline';
import { AuditTimeline } from '@/components/guardians/audit-timeline';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import type { Guardian } from '@/types/models';

interface Props {
    guardian: { data: Guardian };
}

const EVENT_OPTIONS = [
    { label: 'All Events', value: '' },
    { label: 'Created',            value: 'created' },
    { label: 'Updated',            value: 'updated' },
    { label: 'Login enabled',      value: 'login_enabled' },
    { label: 'Login disabled',     value: 'login_disabled' },
    { label: 'Invitation resent',  value: 'login_resent' },
    { label: 'Bulk login enabled', value: 'bulk_login_enabled' },
    { label: 'Status changed',     value: 'status_updated' },
    { label: 'Relationship updated', value: 'pivot_updated' },
    { label: 'Removed from student', value: 'detached' },
];

const DEFAULT_PAGINATION = { current_page: 1, last_page: 1, per_page: 50, total: 0, prev_page_url: null, next_page_url: null };

export default function GuardianAudit({ guardian: { data: g } }: Props) {
    const [entries, setEntries]     = useState<AuditEntry[]>([]);
    const [loading, setLoading]     = useState(true);
    const [pagination, setPagination] = useState(DEFAULT_PAGINATION);
    const [page, setPage]           = useState(1);
    const [limit, setLimit]         = useState(50);
    const [event, setEvent]         = useState('');
    const [dateFrom, setDateFrom]   = useState('');
    const [dateTo, setDateTo]       = useState('');

    useEffect(() => {
        setLoading(true);
        axios.get(`/api/guardians/${g.id}/audit`, {
            params: {
                page, per_page: limit,
                event: event || undefined,
                date_from: dateFrom || undefined,
                date_to: dateTo || undefined,
            },
        })
            .then(res => {
                setEntries(res.data.data ?? []);
                setPagination(res.data.pagination ?? DEFAULT_PAGINATION);
            })
            .catch(() => setEntries([]))
            .finally(() => setLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [g.id, page, limit, event, dateFrom, dateTo]);

    const handleExport = () => {
        const params = new URLSearchParams({
            event, date_from: dateFrom, date_to: dateTo,
        });
        window.location.href = `/api/guardians/${g.id}/audit/export?${params}`;
    };

    return (
        <>
            <Head title={`Audit History — ${g.full_name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                {/* Header */}
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <Link href={`/guardians/${g.id}`} className="flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
                            <ArrowLeft className="h-3.5 w-3.5" />
                            Back to Profile
                        </Link>
                        <h1 className="mt-2 text-2xl font-bold">Audit History</h1>
                        <p className="text-sm text-muted-foreground">{g.full_name}</p>
                    </div>
                    <Button variant="outline" onClick={handleExport}>
                        <Download className="mr-1.5 h-4 w-4" />
                        Export CSV
                    </Button>
                </div>

                {/* Filters */}
                <div className="flex flex-wrap items-center gap-3 rounded-lg border bg-background p-4">
                    <select
                        className="h-9 rounded-md border bg-background px-3 text-sm"
                        value={event}
                        onChange={(e) => { setEvent(e.target.value); setPage(1); }}
                    >
                        {EVENT_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                    </select>

                    <Input
                        type="date"
                        className="h-9 w-auto"
                        value={dateFrom}
                        onChange={(e) => { setDateFrom(e.target.value); setPage(1); }}
                        title="From date"
                    />
                    <Input
                        type="date"
                        className="h-9 w-auto"
                        value={dateTo}
                        onChange={(e) => { setDateTo(e.target.value); setPage(1); }}
                        title="To date"
                    />

                    {(event || dateFrom || dateTo) && (
                        <button
                            className="text-xs text-primary underline-offset-2 hover:underline"
                            onClick={() => { setEvent(''); setDateFrom(''); setDateTo(''); setPage(1); }}
                        >
                            Clear filters
                        </button>
                    )}

                    <span className="ml-auto text-xs text-muted-foreground">{pagination.total} total events</span>
                </div>

                {/* Timeline */}
                <div className="rounded-lg border bg-background p-6 shadow-sm">
                    {loading ? (
                        <div className="space-y-4">
                            {[...Array(6)].map((_, i) => (
                                <div key={i} className="flex gap-4">
                                    <Skeleton className="h-4 w-24" />
                                    <div className="flex-1 space-y-1">
                                        <Skeleton className="h-4 w-40" />
                                        <Skeleton className="h-3 w-64" />
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <AuditTimeline entries={entries} />
                    )}
                </div>

                <div className="mt-auto border-t bg-background/50 p-4">
                    <Pagination meta={pagination} setPage={setPage} setLimit={setLimit} />
                </div>
            </div>
        </>
    );
}

GuardianAudit.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Guardians', href: '/guardians' },
        { title: 'Profile', href: '' },
        { title: 'Audit History' },
    ],
};
