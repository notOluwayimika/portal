import { useState } from 'react';
import { ChevronDown, ChevronRight } from 'lucide-react';

export interface AuditEntry {
    id: number;
    event: string;
    description: string;
    properties: Record<string, unknown>;
    causer_name: string | null;
    created_at: string;
}

const EVENT_LABELS: Record<string, string> = {
    created:              'Created',
    updated:              'Updated',
    deleted:              'Deleted',
    login_enabled:        'Login enabled',
    login_disabled:       'Login disabled',
    login_resent:         'Invitation resent',
    bulk_login_enabled:   'Login enabled (bulk)',
    pivot_updated:        'Relationship updated',
    detached:             'Removed from student',
    status_updated:       'Status changed',
};

function relativeTime(iso: string): string {
    const diff = Date.now() - new Date(iso).getTime();
    const m = Math.floor(diff / 60_000);
    if (m < 1)   return 'just now';
    if (m < 60)  return `${m}m ago`;
    const h = Math.floor(m / 60);
    if (h < 24)  return `${h}h ago`;
    const d = Math.floor(h / 24);
    if (d < 7)   return `${d}d ago`;
    return new Date(iso).toLocaleDateString();
}

function DiffTable({ old: oldVals, attrs }: { old: Record<string, unknown>; attrs: Record<string, unknown> }) {
    const keys = Array.from(new Set([...Object.keys(oldVals), ...Object.keys(attrs)]));
    const changed = keys.filter(k => JSON.stringify(oldVals[k]) !== JSON.stringify(attrs[k]));
    if (changed.length === 0) return null;
    return (
        <table className="mt-2 w-full border-collapse text-xs">
            <thead>
                <tr className="border-b text-muted-foreground">
                    <th className="py-1 text-left font-normal">Field</th>
                    <th className="py-1 text-left font-normal">Before</th>
                    <th className="py-1 text-left font-normal">After</th>
                </tr>
            </thead>
            <tbody>
                {changed.map(k => (
                    <tr key={k} className="border-b last:border-0">
                        <td className="py-1 pr-3 font-medium">{k}</td>
                        <td className="py-1 pr-3 text-red-600 dark:text-red-400">{String(oldVals[k] ?? '—')}</td>
                        <td className="py-1 text-green-700 dark:text-green-400">{String(attrs[k] ?? '—')}</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

function AuditEntryRow({ entry }: { entry: AuditEntry }) {
    const [expanded, setExpanded] = useState(false);
    const hasDiff = entry.properties?.old && entry.properties?.attributes;
    const label = EVENT_LABELS[entry.event] ?? entry.event;

    return (
        <li className="relative pl-6 pb-4 last:pb-0">
            <span className="absolute left-0 top-1.5 h-2 w-2 rounded-full bg-border ring-2 ring-background" />
            {/* vertical line */}
            <span className="absolute left-[3px] top-3.5 bottom-0 w-px bg-border last:hidden" />

            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0 flex-1">
                    <span className="text-sm font-medium">{label}</span>
                    {entry.causer_name && (
                        <span className="text-xs text-muted-foreground"> by {entry.causer_name}</span>
                    )}
                    {entry.description && (
                        <p className="mt-0.5 text-xs text-muted-foreground">{entry.description}</p>
                    )}
                    {hasDiff && (
                        <button
                            className="mt-1 flex items-center gap-0.5 text-xs text-primary"
                            onClick={() => setExpanded(v => !v)}
                        >
                            {expanded ? <ChevronDown className="h-3 w-3" /> : <ChevronRight className="h-3 w-3" />}
                            {expanded ? 'Hide changes' : 'Show changes'}
                        </button>
                    )}
                    {expanded && hasDiff && (
                        <DiffTable
                            old={entry.properties.old as Record<string, unknown>}
                            attrs={entry.properties.attributes as Record<string, unknown>}
                        />
                    )}
                </div>
                <time
                    className="shrink-0 text-xs text-muted-foreground"
                    title={new Date(entry.created_at).toLocaleString()}
                >
                    {relativeTime(entry.created_at)}
                </time>
            </div>
        </li>
    );
}

interface AuditTimelineProps {
    entries: AuditEntry[];
}

export function AuditTimeline({ entries }: AuditTimelineProps) {
    if (entries.length === 0) {
        return <p className="text-sm text-muted-foreground">No activity recorded for this period.</p>;
    }

    return (
        <ol className="relative space-y-0 border-l-0">
            {entries.map(entry => <AuditEntryRow key={entry.id} entry={entry} />)}
        </ol>
    );
}
