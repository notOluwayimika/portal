import { Calendar, ListFilter, Search, SlidersHorizontal, Tag, X } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type {
    ActivityCapabilities,
    ActivityFilters,
    FilterOptions,
    Severity,
} from './types';

const SEVERITIES: Severity[] = ['critical', 'warning', 'notice', 'info'];

const DATE_PRESETS: { label: string; from: () => string }[] = [
    { label: 'Today', from: () => new Date().toISOString().slice(0, 10) },
    {
        label: 'Last 7 days',
        from: () =>
            new Date(Date.now() - 6 * 864e5).toISOString().slice(0, 10),
    },
    {
        label: 'Last 30 days',
        from: () =>
            new Date(Date.now() - 29 * 864e5).toISOString().slice(0, 10),
    },
];

function toggle<T>(arr: T[] | undefined, v: T): T[] {
    const set = new Set(arr ?? []);
    set.has(v) ? set.delete(v) : set.add(v);
    return [...set];
}

function MultiSelect<T extends string | number>({
    label,
    values,
    selected,
    render,
    onChange,
}: {
    label: string;
    values: T[];
    selected: T[] | undefined;
    render?: (v: T) => string;
    onChange: (next: T[]) => void;
}) {
    const [open, setOpen] = useState(false);
    const count = selected?.length ?? 0;
    return (
        <div className="relative">
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                className="flex w-full items-center justify-between rounded-md border bg-white px-3 py-2 text-sm dark:bg-slate-900/40"
            >
                <span>
                    {label}
                    {count > 0 && (
                        <span className="ml-1 rounded-full bg-primary/10 px-1.5 text-xs text-primary">
                            {count}
                        </span>
                    )}
                </span>
            </button>
            {open && (
                <div className="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-md border bg-white p-1 shadow-lg dark:bg-slate-900">
                    {values.length === 0 && (
                        <p className="px-2 py-1.5 text-xs text-muted-foreground">
                            No options
                        </p>
                    )}
                    {Array.isArray(values) &&
                        values.map((v) => (
                            <label
                                key={String(v)}
                                className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-slate-100 dark:hover:bg-slate-800"
                            >
                                <input
                                    type="checkbox"
                                    checked={(selected ?? []).includes(v)}
                                    onChange={() =>
                                        onChange(toggle(selected, v))
                                    }
                                />
                                {render ? render(v) : String(v)}
                            </label>
                        ))}
                </div>
            )}
        </div>
    );
}

export function ActivityFilterBar({
    filters,
    options,
    capabilities,
    onChange,
    onClear,
    onSavePreset,
}: {
    filters: ActivityFilters;
    options: FilterOptions | null;
    capabilities: ActivityCapabilities;
    onChange: (next: ActivityFilters) => void;
    onClear: () => void;
    onSavePreset: () => void;
}) {
    const set = (patch: Partial<ActivityFilters>) =>
        onChange({ ...filters, ...patch });

    const activeCount = Object.entries(filters).filter(
        ([k, v]) =>
            k !== 'search' &&
            v !== undefined &&
            v !== '' &&
            !(Array.isArray(v) && v.length === 0),
    ).length;

    return (
        <div className="overflow-hidden rounded-xl border-none bg-white shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-slate-900/40">
            {/* Card header */}
            <div className="flex items-center justify-between border-b border-slate-50 bg-slate-50/30 px-5 py-3">
                <div className="flex items-center gap-2.5">
                    <div className="flex size-7 items-center justify-center rounded-lg bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700">
                        <ListFilter className="h-4 w-4 text-primary" />
                    </div>
                    <span className="text-sm font-bold text-slate-800 dark:text-slate-100">Filters</span>
                </div>
                {activeCount > 0 && (
                    <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-semibold text-primary dark:bg-primary/10 dark:text-primary">
                        {activeCount} active
                    </span>
                )}
            </div>

            <div className="space-y-3 p-4">
                {/* Search */}
                <div className="relative">
                    <Search className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
                    <Input
                        value={filters.search ?? ''}
                        onChange={(e) => set({ search: e.target.value })}
                        placeholder="Search description or user… (Ctrl+K)"
                        className="pl-9"
                    />
                </div>

                {/* ── Date Range ── */}
                <div className="flex items-center gap-2 border-t border-slate-100 pt-3 text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                    <Calendar className="h-3.5 w-3.5" />
                    Date Range
                </div>
                <div className="flex flex-wrap gap-2">
                    {DATE_PRESETS.map((p) => (
                        <button
                            key={p.label}
                            type="button"
                            onClick={() => set({ date_from: p.from(), date_to: undefined })}
                            className="rounded-full border px-3 py-1 text-xs hover:bg-slate-50 dark:hover:bg-slate-800"
                        >
                            {p.label}
                        </button>
                    ))}
                </div>
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <input
                        type="date"
                        value={filters.date_from ?? ''}
                        onChange={(e) => set({ date_from: e.target.value || undefined })}
                        className="rounded-md border bg-white px-3 py-2 text-sm dark:bg-slate-900/40"
                        aria-label="Date from"
                    />
                    <input
                        type="date"
                        value={filters.date_to ?? ''}
                        onChange={(e) => set({ date_to: e.target.value || undefined })}
                        className="rounded-md border bg-white px-3 py-2 text-sm dark:bg-slate-900/40"
                        aria-label="Date to"
                    />
                </div>

                {/* ── Filters ── */}
                <div className="flex items-center gap-2 border-t border-slate-100 pt-3 text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                    <Tag className="h-3.5 w-3.5" />
                    Filters
                </div>
                <MultiSelect<Severity>
                    label="Severity"
                    values={SEVERITIES}
                    selected={filters.severity}
                    onChange={(v) => set({ severity: v })}
                />
                <MultiSelect<string>
                    label="Event"
                    values={options?.events ?? []}
                    selected={filters.event}
                    onChange={(v) => set({ event: v })}
                />
                <MultiSelect<string>
                    label="Category"
                    values={options?.log_names ?? []}
                    selected={filters.log_name}
                    onChange={(v) => set({ log_name: v })}
                />
                <MultiSelect<number | string>
                    label="User"
                    values={(Array.isArray(options?.causers) ? options.causers : []).map((c) => c.id)}
                    selected={filters.causer_id}
                    render={(id) =>
                        (Array.isArray(options?.causers) ? options.causers : []).find(
                            (c) => c.id === id,
                        )?.name ?? String(id)
                    }
                    onChange={(v) => set({ causer_id: v })}
                />

                {/* ── Advanced ── */}
                <div className="flex items-center gap-2 border-t border-slate-100 pt-3 text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                    <SlidersHorizontal className="h-3.5 w-3.5" />
                    Advanced
                </div>
                <Input
                    value={filters.batch_uuid ?? ''}
                    onChange={(e) => set({ batch_uuid: e.target.value || undefined })}
                    placeholder="Batch UUID"
                />
                {capabilities.canViewSystem && (
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={filters.include_system ?? false}
                            onChange={(e) => set({ include_system: e.target.checked })}
                        />
                        Include system events
                    </label>
                )}

                {/* Actions */}
                <div className="flex items-center gap-2 border-t border-slate-100 pt-3">
                    {activeCount > 0 && (
                        <Button variant="outline" size="sm" onClick={onClear} className="gap-1">
                            <X className="h-3 w-3" />
                            Clear ({activeCount})
                        </Button>
                    )}
                    <Button variant="outline" size="sm" onClick={onSavePreset}>
                        Save filter
                    </Button>
                </div>
            </div>
        </div>
    );
}
