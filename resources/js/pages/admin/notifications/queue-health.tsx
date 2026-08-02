import { Head } from '@inertiajs/react';
import axios from 'axios';
import { AlertTriangle, CheckCircle2, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

type Health = {
    healthy: boolean;
    stuck_after_minutes: number;
    stuck_deliveries: number;
    oldest_pending_at: string | null;
    queue_depth: number;
    queue_reserved: number;
    failed_jobs: number;
    deliveries_by_status: Record<string, Record<string, number>>;
};

/**
 * "Did the worker run?"
 *
 * WHY THIS EXISTS IN v1 rather than with the rest of the observability work.
 * There is no Horizon here — it needs Redis and a supervised process, and this
 * host has neither — and the worker is a cron-invoked `queue:work` rather than a
 * daemon. So the most likely failure in the whole subsystem is the silent one:
 * cron stops, nothing throws, nothing is logged, every page still returns 200,
 * and notifications simply stop arriving. Nothing else in the system would
 * notice. This page is the thing that notices.
 */
export default function NotificationQueueHealth() {
    const [health, setHealth] = useState<Health | null>(null);
    const [loading, setLoading] = useState(true);

    const load = useCallback(async () => {
        setLoading(true);

        try {
            const { data } = await axios.get('/api/notifications-queue-health');
            setHealth(data);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        // Fetch-on-mount; the page's entire content is this one request.
        // eslint-disable-next-line react-hooks/set-state-in-effect
        void load();
    }, [load]);

    return (
        <>
            <Head title="Notification queue health" />

            <div className="mx-auto max-w-4xl space-y-5 p-4">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold text-slate-900">
                            Notification queue health
                        </h1>
                        <p className="mt-1 text-sm text-slate-500">
                            The worker is invoked by cron, not supervised. If it
                            stops, nothing throws — deliveries just sit here.
                        </p>
                    </div>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => void load()}
                        disabled={loading}
                    >
                        <RefreshCw
                            className={`mr-1.5 size-4 ${loading ? 'animate-spin' : ''}`}
                        />
                        Refresh
                    </Button>
                </div>

                {loading && health === null ? (
                    <div className="flex justify-center py-16">
                        <Spinner className="size-6 text-slate-400" />
                    </div>
                ) : health === null ? (
                    <p className="py-16 text-center text-sm text-slate-500">
                        Could not load queue health.
                    </p>
                ) : (
                    <>
                        <div
                            className={`flex items-center gap-3 rounded-xl border p-4 ${
                                health.healthy
                                    ? 'border-emerald-200 bg-emerald-50'
                                    : 'border-red-200 bg-red-50'
                            }`}
                        >
                            {health.healthy ? (
                                <CheckCircle2 className="size-6 text-emerald-600" />
                            ) : (
                                <AlertTriangle className="size-6 text-red-600" />
                            )}
                            <div>
                                <p className="text-sm font-semibold text-slate-900">
                                    {health.healthy
                                        ? 'Deliveries are moving'
                                        : `${health.stuck_deliveries} delivery(s) stuck`}
                                </p>
                                <p className="text-xs text-slate-600">
                                    {health.healthy
                                        ? `Nothing has been pending longer than ${health.stuck_after_minutes} minutes.`
                                        : `Pending longer than ${health.stuck_after_minutes} minutes — the scheduler has probably stopped running queue:work.`}
                                </p>
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            {[
                                // Queue DEPTH matters separately from pending
                                // deliveries: a fan-out job that never ran leaves
                                // NO pending delivery at all, so deliveries alone
                                // would report a healthy zero.
                                ['Queued jobs', health.queue_depth],
                                ['Reserved', health.queue_reserved],
                                ['Stuck deliveries', health.stuck_deliveries],
                                ['Failed jobs', health.failed_jobs],
                            ].map(([label, value]) => (
                                <div
                                    key={String(label)}
                                    className="rounded-xl border border-slate-200 bg-white p-3"
                                >
                                    <p className="text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                        {label}
                                    </p>
                                    <p className="mt-1 text-2xl font-bold text-slate-900 tabular-nums">
                                        {value}
                                    </p>
                                </div>
                            ))}
                        </div>

                        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
                            <div className="border-b border-slate-100 px-4 py-2.5">
                                <p className="text-sm font-semibold text-slate-900">
                                    Deliveries by status
                                </p>
                                <p className="text-xs text-slate-500">
                                    A refusal is a row, not an absence —
                                    &ldquo;skipped&rdquo; carries the reason.
                                </p>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full text-xs">
                                    <thead>
                                        <tr className="bg-slate-50/50 text-left">
                                            <th className="px-4 py-2 font-semibold text-slate-500">
                                                Status
                                            </th>
                                            <th className="px-4 py-2 font-semibold text-slate-500">
                                                Channel
                                            </th>
                                            <th className="px-4 py-2 text-right font-semibold text-slate-500">
                                                Count
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {Object.entries(
                                            health.deliveries_by_status,
                                        ).flatMap(([status, byChannel]) =>
                                            Object.entries(byChannel).map(
                                                ([channel, total]) => (
                                                    <tr
                                                        key={`${status}:${channel}`}
                                                    >
                                                        <td className="px-4 py-2 font-medium text-slate-700">
                                                            {status}
                                                        </td>
                                                        <td className="px-4 py-2 text-slate-500">
                                                            {channel}
                                                        </td>
                                                        <td className="px-4 py-2 text-right tabular-nums">
                                                            {total}
                                                        </td>
                                                    </tr>
                                                ),
                                            ),
                                        )}
                                        {Object.keys(
                                            health.deliveries_by_status,
                                        ).length === 0 && (
                                            <tr>
                                                <td
                                                    colSpan={3}
                                                    className="px-4 py-8 text-center text-slate-400"
                                                >
                                                    No deliveries yet.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </>
                )}
            </div>
        </>
    );
}
