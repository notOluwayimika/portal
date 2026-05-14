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
        <Card>
            <CardHeader className="pb-3">
                <div className="flex items-center justify-between">
                    <CardTitle className="text-base">Recent Activity</CardTitle>
                    <Link href={`/guardians/${guardianId}/audit`} className="text-xs text-primary hover:underline">View Full History →</Link>
                </div>
            </CardHeader>
            <CardContent>
                {loading ? (
                    <div className="space-y-3">
                        {[...Array(4)].map((_, i) => (
                            <div key={i} className="flex gap-3">
                                <Skeleton className="h-4 w-24" />
                                <Skeleton className="h-4 flex-1" />
                            </div>
                        ))}
                    </div>
                ) : entries.length === 0 ? (
                    <p className="text-xs text-muted-foreground">No activity recorded yet.</p>
                ) : (
                    <ol className="space-y-2">
                        {entries.map((entry) => (
                            <li key={entry.id} className="flex gap-3 text-xs">
                                <time
                                    className="w-20 shrink-0 text-muted-foreground"
                                    title={new Date(entry.created_at).toLocaleString()}
                                >
                                    {relativeTime(entry.created_at)}
                                </time>
                                <span className="flex-1">
                                    <span className="font-medium">{eventLabel(entry.event)}</span>
                                    {entry.causer_name && (
                                        <span className="text-muted-foreground"> by {entry.causer_name}</span>
                                    )}
                                    {entry.description && (
                                        <span className="text-muted-foreground"> — {entry.description}</span>
                                    )}
                                </span>
                            </li>
                        ))}
                    </ol>
                )}
            </CardContent>
        </Card>
    );
}
