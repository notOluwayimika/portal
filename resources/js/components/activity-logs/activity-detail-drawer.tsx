import axios from 'axios';
import { Copy, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ActivityDiff } from './activity-diff';
import { SeverityBadge } from './severity-badge';
import type { ActivityDetail } from './types';

export function ActivityDetailDrawer({
    activityId,
    onClose,
    onOpenRelated,
}: {
    activityId: number | null;
    onClose: () => void;
    onOpenRelated: (id: number) => void;
}) {
    const [detail, setDetail] = useState<ActivityDetail | null>(null);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (activityId === null) return;
        setDetail(null);
        setLoading(true);
        axios
            .get<{ data: ActivityDetail }>(`/api/activity-logs/${activityId}`)
            .then((r) => setDetail(r.data.data))
            .catch(() => setDetail(null))
            .finally(() => setLoading(false));
    }, [activityId]);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        if (activityId !== null) window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [activityId, onClose]);

    if (activityId === null) return null;

    const permalink = `${window.location.origin}/activity-logs/${activityId}`;

    return (
        <div className="fixed inset-0 z-50 flex justify-end">
            <div
                className="absolute inset-0 bg-black/30"
                onClick={onClose}
                aria-hidden
            />
            <aside
                role="dialog"
                aria-modal="true"
                aria-label="Activity detail"
                className="relative flex h-full w-full max-w-md flex-col overflow-y-auto bg-white shadow-xl dark:bg-slate-950"
            >
                <header className="flex items-center justify-between border-b p-4 dark:border-slate-800">
                    <h2 className="text-sm font-semibold">Activity detail</h2>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Close"
                        className="rounded p-1 hover:bg-slate-100 dark:hover:bg-slate-800"
                    >
                        <X className="h-4 w-4" />
                    </button>
                </header>

                {loading && (
                    <p className="p-4 text-sm text-muted-foreground">Loading…</p>
                )}

                {detail && (
                    <div className="space-y-5 p-4 text-sm">
                        <div>
                            <div className="flex items-center gap-2">
                                <SeverityBadge severity={detail.severity} />
                                {detail.is_system && (
                                    <span className="rounded bg-slate-200 px-1.5 py-px text-[9px] font-bold dark:bg-slate-700">
                                        SYSTEM
                                    </span>
                                )}
                            </div>
                            <p className="mt-2 font-medium">
                                {detail.description}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {new Date(detail.created_at).toLocaleString()}
                            </p>
                        </div>

                        <section className="rounded-lg border p-3 dark:border-slate-800">
                            <h3 className="mb-1 text-xs font-semibold uppercase text-muted-foreground">
                                Causer
                            </h3>
                            <p className="font-medium">{detail.causer.name}</p>
                            {detail.causer.role && (
                                <p className="text-xs text-muted-foreground">
                                    {detail.causer.role}
                                </p>
                            )}
                        </section>

                        {detail.subject && (
                            <section className="rounded-lg border p-3 dark:border-slate-800">
                                <h3 className="mb-1 text-xs font-semibold uppercase text-muted-foreground">
                                    Subject
                                </h3>
                                <p className="font-medium">
                                    {detail.subject.label}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {detail.subject.type}
                                    {!detail.subject.exists && ' · deleted'}
                                </p>
                            </section>
                        )}

                        {detail.diff.length > 0 && (
                            <section>
                                <h3 className="mb-1 text-xs font-semibold uppercase text-muted-foreground">
                                    Changes
                                </h3>
                                <ActivityDiff diff={detail.diff} />
                            </section>
                        )}

                        {detail.batch && detail.batch.count > 1 && (
                            <section className="rounded-lg border p-3 dark:border-slate-800">
                                <h3 className="mb-1 text-xs font-semibold uppercase text-muted-foreground">
                                    Batch · {detail.batch.count} events
                                </h3>
                                <ul className="space-y-1">
                                    {detail.batch.related.map((r) => (
                                        <li key={r.id}>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    onOpenRelated(r.id)
                                                }
                                                className="text-left text-xs text-primary hover:underline"
                                            >
                                                {r.description}
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}

                        <section>
                            <h3 className="mb-1 text-xs font-semibold uppercase text-muted-foreground">
                                Properties
                            </h3>
                            <pre className="max-h-64 overflow-auto rounded-md bg-slate-50 p-2 font-mono text-[11px] dark:bg-slate-900">
                                {JSON.stringify(detail.properties, null, 2)}
                            </pre>
                        </section>

                        <footer className="space-y-1 border-t pt-3 text-xs text-muted-foreground dark:border-slate-800">
                            <p>
                                Log:{' '}
                                <span className="font-mono">
                                    {detail.log_name}
                                </span>{' '}
                                · Event:{' '}
                                <span className="font-mono">
                                    {detail.event}
                                </span>
                            </p>
                            {detail.batch_uuid && (
                                <p className="font-mono">
                                    batch: {detail.batch_uuid}
                                </p>
                            )}
                            <p>Activity ID: {detail.id}</p>
                            <button
                                type="button"
                                onClick={() =>
                                    navigator.clipboard?.writeText(permalink)
                                }
                                className="inline-flex items-center gap-1 text-primary hover:underline"
                            >
                                <Copy className="h-3 w-3" /> Copy permalink
                            </button>
                        </footer>
                    </div>
                )}
            </aside>
        </div>
    );
}
