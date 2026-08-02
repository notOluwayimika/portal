import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { Bell, CheckCheck } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import type { NotificationRow } from '@/hooks/use-notifications';
import { useUnreadCount } from '@/hooks/use-notifications';

/**
 * The in-app feed, in the header.
 *
 * TWO COUNTERS, NOT ONE. The badge is driven by UNREAD, but opening the bell
 * marks everything SEEN — so the badge stops nagging immediately while each item
 * stays unread until it is actually clicked. Conflating the two gives you either
 * a badge that never clears or a feed that marks itself read on sight.
 */
export function NotificationBell() {
    const { notifications } = usePage<{
        notifications?: { enabled: boolean; pollSeconds: number };
    }>().props;

    const pollSeconds = notifications?.pollSeconds ?? 45;
    const { count, setCount, refresh } = useUnreadCount(pollSeconds);

    const [open, setOpen] = useState(false);
    const [rows, setRows] = useState<NotificationRow[]>([]);
    const [loading, setLoading] = useState(false);
    // STATE, not a ref: this is read during render (to show the spinner only on
    // the first open, never on a refetch), and a ref read during render is not
    // safe under concurrent rendering — React may render without committing.
    const [loadedOnce, setLoadedOnce] = useState(false);

    const load = useCallback(async () => {
        setLoading(true);

        try {
            const { data } = await axios.get('/api/notifications');
            setRows(data.data ?? []);
            setCount(data.unread_count ?? 0);
            setLoadedOnce(true);
        } catch {
            // Same reasoning as the poll: a failed open leaves the last known
            // list rather than throwing a toast into the header.
        } finally {
            setLoading(false);
        }
    }, [setCount]);

    useEffect(() => {
        if (!open) {
            return;
        }

        // Fetch-on-open: the panel has no data until it is opened, and there is no
        // external store to subscribe to. Same pattern, and the same disable, as the
        // other fetch-on-mount views in this codebase.
        // eslint-disable-next-line react-hooks/set-state-in-effect
        void load();
        // Clears the badge without marking anything read.
        void axios.post('/api/notifications/seen').catch(() => {});
    }, [open, load]);

    const markRead = async (row: NotificationRow) => {
        if (row.read_at !== null) {
            return;
        }

        // Optimistic: the row greys out immediately, and the authoritative count
        // comes back in the response.
        setRows((prev) =>
            prev.map((r) =>
                r.id === row.id
                    ? { ...r, read_at: new Date().toISOString() }
                    : r,
            ),
        );

        try {
            const { data } = await axios.patch(
                `/api/notifications/${row.id}/read`,
            );
            setCount(data.unread_count ?? 0);
        } catch {
            void refresh();
        }
    };

    const markAllRead = async () => {
        // BOUNDED by the newest row actually rendered. Unbounded, this would also
        // clear notifications that arrived while the panel was open — marking read
        // something the user has never been shown.
        const newest = rows[0]?.sort_id;

        try {
            const { data } = await axios.post('/api/notifications/read-all', {
                before_id: newest,
            });
            setCount(data.unread_count ?? 0);
            setRows((prev) =>
                prev.map((r) =>
                    newest !== undefined && r.sort_id <= newest
                        ? {
                              ...r,
                              read_at: r.read_at ?? new Date().toISOString(),
                          }
                        : r,
                ),
            );
        } catch {
            void refresh();
        }
    };

    // Ships dark: no bell at all until the subsystem is switched on.
    if (!notifications?.enabled) {
        return null;
    }

    return (
        <DropdownMenu open={open} onOpenChange={setOpen}>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="relative"
                    aria-label={
                        count > 0
                            ? `Notifications, ${count} unread`
                            : 'Notifications'
                    }
                >
                    <Bell className="size-5" />
                    {count > 0 && (
                        <span className="absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white">
                            {count > 99 ? '99+' : count}
                        </span>
                    )}
                </Button>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end" className="w-88 p-0">
                <div className="flex items-center justify-between border-b px-3 py-2">
                    <p className="text-sm font-semibold">Notifications</p>
                    {rows.some((r) => r.read_at === null) && (
                        <button
                            type="button"
                            onClick={() => void markAllRead()}
                            className="flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-800"
                        >
                            <CheckCheck className="size-3.5" />
                            Mark all read
                        </button>
                    )}
                </div>

                <div className="max-h-96 overflow-y-auto">
                    {loading && !loadedOnce ? (
                        <div className="flex justify-center py-8">
                            <Spinner className="size-5 text-gray-400" />
                        </div>
                    ) : rows.length === 0 ? (
                        <p className="px-3 py-8 text-center text-xs text-gray-400">
                            Nothing to catch up on.
                        </p>
                    ) : (
                        rows.map((row) => (
                            <button
                                key={row.id}
                                type="button"
                                onClick={() => void markRead(row)}
                                className={`flex w-full flex-col items-start gap-0.5 border-b px-3 py-2.5 text-left last:border-b-0 hover:bg-slate-50 ${
                                    row.read_at === null
                                        ? 'bg-indigo-50/40'
                                        : ''
                                }`}
                            >
                                <span className="flex w-full items-start gap-2">
                                    {row.read_at === null && (
                                        <span className="mt-1.5 size-1.5 shrink-0 rounded-full bg-indigo-500" />
                                    )}
                                    <span
                                        className={`text-xs ${row.read_at === null ? 'font-semibold text-slate-900' : 'text-slate-600'}`}
                                    >
                                        {row.title}
                                    </span>
                                </span>
                                <span className="pl-3.5 text-[10px] text-slate-400">
                                    {row.created_at
                                        ? new Date(
                                              row.created_at,
                                          ).toLocaleString()
                                        : ''}
                                </span>
                            </button>
                        ))
                    )}
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
