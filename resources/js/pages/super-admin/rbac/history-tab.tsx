// ═══════════════════════════════════════════════════════════════════════════
// HISTORY TAB — who changed a grant, and when.
//
// Reuses the existing activity-log API rather than adding a route. The C1
// listener already writes every attach/detach under log_name='rbac', and the
// endpoint already accepts a log_name filter — so this is a query, not a
// feature. Adding a route would have meant regenerating two RBAC fixtures for
// data that was already reachable.
//
// Fetched on tab activation only, so the default page payload stays small.
// ═══════════════════════════════════════════════════════════════════════════

import axios from 'axios';
import { AlertCircle, Loader2, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { RbacBadge } from '@/components/rbac/rbac-ui';

interface ActivityRow {
    id: number;
    event: string | null;
    description: string | null;
    causer_name: string | null;
    created_at: string;
    properties?: {
        roles?: string[];
        permissions?: string[];
        team_school_id?: number | null;
    };
}

const EVENT_TONE: Record<string, 'emerald' | 'rose' | 'slate'> = {
    role_attached: 'emerald',
    permission_attached: 'emerald',
    role_detached: 'rose',
    permission_detached: 'rose',
};

export function HistoryTab() {
    const [rows, setRows] = useState<ActivityRow[] | null>(null);
    const [failed, setFailed] = useState(false);
    const [token, setToken] = useState(0);

    const reload = useCallback(() => setToken((t) => t + 1), []);

    useEffect(() => {
        let cancelled = false;

        const fetchRows = async () => {
            setFailed(false);

            try {
                const response = await axios.get('/api/activity-logs', {
                    params: { log_name: 'rbac', per_page: 50 },
                });

                if (!cancelled) {
                    setRows(response.data.data ?? []);
                }
            } catch {
                if (!cancelled) {
                    setFailed(true);
                    setRows([]);
                }
            }
        };

        fetchRows();

        return () => {
            cancelled = true;
        };
    }, [token]);

    return (
        <div className="overflow-hidden rounded-xl border-none bg-white shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card">
            <div className="flex items-center gap-2 border-b border-slate-100 px-5 py-3 dark:border-slate-800">
                <p className="text-[11px] text-slate-500">
                    Every role and permission change, newest first. Seed-time
                    changes are deliberately not recorded — they are provenance
                    by code review.
                </p>
                <button
                    type="button"
                    onClick={reload}
                    className="ml-auto inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-[11px] font-semibold text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800"
                >
                    <RefreshCw className="h-3 w-3" aria-hidden />
                    Refresh
                </button>
            </div>

            <div className="custom-scrollbar overflow-x-auto">
                <table className="w-full text-xs">
                    <thead className="bg-slate-50/50 dark:bg-slate-900/40">
                        <tr className="text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                            <th className="px-4 py-2.5 text-left">When</th>
                            <th className="px-4 py-2.5 text-left">Change</th>
                            <th className="px-4 py-2.5 text-left">What</th>
                            <th className="px-4 py-2.5 text-left">By</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                        {rows === null && (
                            <tr>
                                <td
                                    colSpan={4}
                                    className="px-5 py-12 text-center"
                                >
                                    <Loader2
                                        className="mx-auto h-5 w-5 animate-spin text-slate-400"
                                        aria-hidden
                                    />
                                    <p className="mt-2 text-[11px] text-slate-500">
                                        Loading history…
                                    </p>
                                </td>
                            </tr>
                        )}

                        {failed && (
                            <tr>
                                <td
                                    colSpan={4}
                                    className="px-5 py-12 text-center"
                                >
                                    <div className="mx-auto flex size-12 items-center justify-center rounded-full bg-red-50 dark:bg-red-950/40">
                                        <AlertCircle
                                            className="h-5 w-5 text-red-600"
                                            aria-hidden
                                        />
                                    </div>
                                    <p className="mt-2 text-xs font-bold text-slate-900 dark:text-white">
                                        Could not load history
                                    </p>
                                    <button
                                        type="button"
                                        onClick={reload}
                                        className="mt-1 rounded-lg px-2 py-1 text-[11px] font-semibold text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-950"
                                    >
                                        Retry
                                    </button>
                                </td>
                            </tr>
                        )}

                        {rows !== null && !failed && rows.length === 0 && (
                            <tr>
                                <td
                                    colSpan={4}
                                    className="px-5 py-12 text-center text-[11px] text-slate-500"
                                >
                                    No RBAC changes recorded yet.
                                </td>
                            </tr>
                        )}

                        {(rows ?? []).map((row) => {
                            const subjects = [
                                ...(row.properties?.permissions ?? []),
                                ...(row.properties?.roles ?? []),
                            ];

                            return (
                                <tr
                                    key={row.id}
                                    className="hover:bg-slate-50/60 dark:hover:bg-slate-900/40"
                                >
                                    <td className="px-4 py-2.5 whitespace-nowrap text-slate-500 tabular-nums">
                                        {new Date(
                                            row.created_at,
                                        ).toLocaleString()}
                                    </td>
                                    <td className="px-4 py-2.5">
                                        <RbacBadge
                                            tone={
                                                EVENT_TONE[row.event ?? ''] ??
                                                'slate'
                                            }
                                        >
                                            {(row.event ?? 'changed').replace(
                                                /_/g,
                                                ' ',
                                            )}
                                        </RbacBadge>
                                    </td>
                                    <td className="px-4 py-2.5">
                                        {subjects.length > 0 ? (
                                            <div className="flex flex-wrap gap-1">
                                                {subjects.map((s) => (
                                                    <code
                                                        key={s}
                                                        className="font-mono text-[10px] text-slate-600 dark:text-slate-300"
                                                    >
                                                        {s}
                                                    </code>
                                                ))}
                                            </div>
                                        ) : (
                                            <span className="text-slate-500">
                                                {row.description ?? '—'}
                                            </span>
                                        )}
                                        {row.properties?.team_school_id && (
                                            <span className="block text-[10px] text-slate-400">
                                                school #
                                                {row.properties.team_school_id}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-2.5 text-slate-500">
                                        {row.causer_name ?? 'system'}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
