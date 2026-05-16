import axios from 'axios';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { ActivityDiff } from './activity-diff';
import { EventIcon } from './event-icon';
import { CategoryBadge, SeverityBadge } from './severity-badge';
import type { ActivityDetail, ActivityItem as Item, DiffRow } from './types';

function dayLabel(iso: string): string {
    const d = new Date(iso);
    const today = new Date();
    const yest = new Date();
    yest.setDate(today.getDate() - 1);
    if (d.toDateString() === today.toDateString()) return 'Today';
    if (d.toDateString() === yest.toDateString()) return 'Yesterday';
    return d.toLocaleDateString(undefined, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}

function time(iso: string): string {
    return new Date(iso).toLocaleTimeString(undefined, {
        hour: '2-digit',
        minute: '2-digit',
    });
}

function relative(iso: string): string {
    const m = Math.floor((Date.now() - new Date(iso).getTime()) / 60000);
    if (m < 1) return 'just now';
    if (m < 60) return `${m}m ago`;
    if (m < 1440) return `${Math.floor(m / 60)}h ago`;
    return `${Math.floor(m / 1440)}d ago`;
}

function ActivityRow({
    item,
    onOpen,
}: {
    item: Item;
    onOpen: (id: number) => void;
}) {
    const [expanded, setExpanded] = useState(false);
    const [diff, setDiff] = useState<DiffRow[] | null>(null);
    const [loading, setLoading] = useState(false);

    const toggleDiff = async () => {
        if (expanded) {
            setExpanded(false);
            return;
        }
        setExpanded(true);
        if (diff === null) {
            setLoading(true);
            try {
                const res = await axios.get<{ data: ActivityDetail }>(
                    `/api/activity-logs/${item.id}`,
                );
                setDiff(res.data.data.diff ?? []);
            } catch {
                setDiff([]);
            } finally {
                setLoading(false);
            }
        }
    };

    return (
        <li
            className={`relative rounded-lg pl-7 pr-3 py-3 transition hover:bg-slate-50/70 dark:hover:bg-slate-900/30 ${
                item.severity === 'critical'
                    ? 'bg-red-50/40 dark:bg-red-950/20'
                    : ''
            }`}
        >
            <span className="absolute left-2 top-4 h-2 w-2 rounded-full bg-border ring-2 ring-background" />

            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                        <EventIcon event={item.event} severity={item.severity} />
                        <button
                            type="button"
                            onClick={() => onOpen(item.id)}
                            className="truncate text-left text-sm font-semibold hover:underline"
                        >
                            {item.causer.name}
                        </button>
                        {item.causer.role && (
                            <span className="text-xs text-muted-foreground">
                                ({item.causer.role})
                            </span>
                        )}
                        {item.is_system && (
                            <span className="rounded bg-slate-200 px-1.5 py-px text-[9px] font-bold tracking-wide text-slate-600 dark:bg-slate-700 dark:text-slate-200">
                                SYSTEM
                            </span>
                        )}
                    </div>

                    <p className="mt-0.5 text-sm text-slate-700 dark:text-slate-300">
                        {item.description}
                        {item.subject && (
                            <span className="text-muted-foreground">
                                {' '}
                                ·{' '}
                                {item.subject.exists ? (
                                    <span className="font-medium text-slate-600 dark:text-slate-300">
                                        {item.subject.label}
                                    </span>
                                ) : (
                                    <span className="italic">
                                        {item.subject.label} [deleted]
                                    </span>
                                )}
                            </span>
                        )}
                    </p>

                    <div className="mt-1.5 flex flex-wrap items-center gap-2">
                        <SeverityBadge severity={item.severity} />
                        <CategoryBadge category={item.log_name} />
                        {item.has_diff && (
                            <button
                                type="button"
                                onClick={toggleDiff}
                                aria-expanded={expanded}
                                className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300"
                            >
                                {expanded ? (
                                    <ChevronDown className="h-3 w-3" />
                                ) : (
                                    <ChevronRight className="h-3 w-3" />
                                )}
                                changes
                            </button>
                        )}
                    </div>

                    {expanded && (
                        <div className="mt-2 rounded-md border bg-slate-50/60 p-2 dark:border-slate-800 dark:bg-slate-900/40">
                            {loading ? (
                                <p className="px-1 py-2 text-xs text-muted-foreground">
                                    Loading changes…
                                </p>
                            ) : (
                                <ActivityDiff diff={diff ?? []} />
                            )}
                        </div>
                    )}
                </div>

                <time
                    dateTime={item.created_at}
                    title={relative(item.created_at)}
                    className="shrink-0 font-mono text-xs text-muted-foreground"
                >
                    {time(item.created_at)}
                </time>
            </div>
        </li>
    );
}

export function ActivityTimeline({
    items,
    onOpen,
}: {
    items: Item[];
    onOpen: (id: number) => void;
}) {
    const groups = items.reduce<Record<string, Item[]>>((acc, it) => {
        const key = dayLabel(it.created_at);
        (acc[key] ??= []).push(it);
        return acc;
    }, {});

    return (
        <div className="space-y-6">
            {Object.entries(groups).map(([label, rows]) => (
                <section key={label}>
                    <header className="sticky top-0 z-10 mb-1 flex items-center justify-between bg-[#f5f7fb]/90 py-1.5 backdrop-blur dark:bg-background/90">
                        <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            {label}
                        </h3>
                        <span className="text-xs text-muted-foreground">
                            {rows.length} event{rows.length === 1 ? '' : 's'}
                        </span>
                    </header>
                    <ul className="relative before:absolute before:left-3 before:top-2 before:bottom-2 before:w-px before:bg-border">
                        {rows.map((it) => (
                            <ActivityRow key={it.id} item={it} onOpen={onOpen} />
                        ))}
                    </ul>
                </section>
            ))}
        </div>
    );
}
