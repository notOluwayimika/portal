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
        <Card className="overflow-hidden rounded-[1.5rem] border-none shadow-[0_8px_30px_rgb(0,0,0,0.04)]">
            <CardHeader className="border-b border-slate-50 bg-slate-50/30 px-6 py-5">
                <div className="flex items-center justify-between">
                    <CardTitle className="flex items-center gap-3 text-base font-bold text-slate-800">
                        <div className="flex size-8 items-center justify-center rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
                            <History className="h-4 w-4 text-indigo-600" />
                        </div>
                        Recent Activity
                    </CardTitle>
                    <Link
                        href={`/guardians/${guardianId}/audit`}
                        className="text-[11px] font-bold tracking-wide text-indigo-600 uppercase hover:text-indigo-700"
                    >
                        Full History →
                    </Link>
                </div>
            </CardHeader>
            <CardContent className="p-6 max-h-[500px] overflow-y-auto custom-scrollbar">
                {loading ? (
                    <div className="space-y-6">
                        {[...Array(4)].map((_, i) => (
                            <div key={i} className="flex gap-4">
                                <Skeleton className="h-4 w-16" />
                                <Skeleton className="h-4 flex-1" />
                            </div>
                        ))}
                    </div>
                ) : entries.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-8 text-center">
                        <div className="mb-3 flex size-12 items-center justify-center rounded-full bg-slate-50 text-slate-300">
                            <Clock className="h-6 w-6" />
                        </div>
                        <p className="text-xs font-medium text-slate-500">No activity recorded yet.</p>
                    </div>
                ) : (
                    <div className="relative space-y-6 before:absolute before:left-[3px] before:top-2 before:h-[calc(100%-16px)] before:w-[2px] before:bg-slate-100">
                        {entries.map((entry) => (
                            <div key={entry.id} className="relative pl-7">
                                {/* Marker */}
                                <div className="absolute left-0 top-1.5 size-[8px] rounded-full border-2 border-white bg-indigo-500 ring-4 ring-indigo-50 dark:ring-indigo-950/30" />

                                <div className="space-y-1">
                                    <div className="flex items-center justify-between">
                                        <span className="text-[13px] font-bold text-slate-800">
                                            {eventLabel(entry.event)}
                                        </span>
                                        <time
                                            className="text-[10px] font-bold tracking-wider text-slate-400 uppercase"
                                            title={new Date(entry.created_at).toLocaleString()}
                                        >
                                            {relativeTime(entry.created_at)}
                                        </time>
                                    </div>

                                    <p className="text-[12px] leading-relaxed text-slate-500">
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
