import { Link } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useState } from 'react';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

interface ActivityEntry {
    id: number;
    event: string;
    description: string;
    properties: Record<string, unknown>;
    causer_name: string | null;
    created_at: string;
}

interface ActivityLogCardProps {
    guardianId: string;
    refreshKey?: number;
}

function eventLabel(event: string): string {
    const map: Record<string, string> = {
        updated:        'Updated',
        created:        'Created',
        deleted:        'Deleted',
        login_enabled:  'Login enabled',
        login_disabled: 'Login disabled',
        login_resent:   'Invitation resent',
        pivot_updated:  'Relationship updated',
        detached:       'Removed from student',
    };
    return map[event] ?? event;
}

function relativeTime(iso: string): string {
    const diff = Date.now() - new Date(iso).getTime();
    const minutes = Math.floor(diff / 60_000);
    if (minutes < 1)   return 'just now';
    if (minutes < 60)  return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24)    return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    if (days < 7)      return `${days}d ago`;
    return new Date(iso).toLocaleDateString();
}

import { Clock, History } from 'lucide-react';

export function ActivityLogCard({ guardianId, refreshKey }: ActivityLogCardProps) {
    const [entries, setEntries] = useState<ActivityEntry[]>([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        setLoading(true);
        axios
            .get(`/api/guardians/${guardianId}/activity`)
            .then((res) => setEntries(res.data?.data ?? []))
            .catch(() => setEntries([]))
            .finally(() => setLoading(false));
    }, [guardianId, refreshKey]);

    return (
        <Card className="overflow-hidden rounded-xl border-none shadow-[0_8px_30px_rgb(0,0,0,0.04)]">
            <CardHeader className="border-b border-slate-50 bg-slate-50/30 px-5 py-3">
                <div className="flex items-center justify-between">
                    <CardTitle className="flex items-center gap-2.5 text-sm font-bold text-slate-800 dark:text-slate-100">
                        <div className="flex size-7 items-center justify-center rounded-lg bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700">
                            <History className="h-4 w-4 text-indigo-600" />
                        </div>
                        Recent Activity
                    </CardTitle>
                    <Link
                        href={`/guardians/${guardianId}/audit`}
                        className="text-[10px] font-bold tracking-wide text-indigo-600 uppercase hover:text-indigo-700"
                    >
                        Full History →
                    </Link>
                </div>
            </CardHeader>
            <CardContent className="p-4 max-h-[400px] overflow-y-auto custom-scrollbar">
                {loading ? (
                    <div className="space-y-4">
                        {[...Array(4)].map((_, i) => (
                            <div key={i} className="flex gap-3">
                                <Skeleton className="h-3 w-14" />
                                <Skeleton className="h-3 flex-1" />
                            </div>
                        ))}
                    </div>
                ) : entries.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-6 text-center">
                        <div className="mb-2 flex size-10 items-center justify-center rounded-full bg-slate-50 text-slate-300">
                            <Clock className="h-5 w-5" />
                        </div>
                        <p className="text-xs font-medium text-slate-500">No activity recorded yet.</p>
                    </div>
                ) : (
                    <div className="relative space-y-4 before:absolute before:left-[3px] before:top-2 before:h-[calc(100%-16px)] before:w-[2px] before:bg-slate-100">
                        {entries.map((entry) => (
                            <div key={entry.id} className="relative pl-6">
                                {/* Marker */}
                                <div className="absolute left-0 top-1.5 size-[8px] rounded-full border-2 border-white bg-indigo-500 ring-4 ring-indigo-50 dark:ring-indigo-950/30" />

                                <div className="space-y-0.5">
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="text-xs font-bold text-slate-800 dark:text-slate-100">
                                            {eventLabel(entry.event)}
                                        </span>
                                        <time
                                            className="shrink-0 text-[10px] font-bold tracking-wider text-slate-400 uppercase"
                                            title={new Date(entry.created_at).toLocaleString()}
                                        >
                                            {relativeTime(entry.created_at)}
                                        </time>
                                    </div>

                                    <p className="text-[11px] leading-relaxed text-slate-500">
                                        {entry.description || "System action performed"}
                                        {entry.causer_name && (
                                            <span className="font-semibold text-slate-400"> • By {entry.causer_name}</span>
                                        )}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
