import { Search, X } from 'lucide-react';
import { useEffect, useRef } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { cn } from '@/lib/utils';

interface Option {
    name: string;
    value: string;
}

interface SelectOption {
    label: string;
    value: string;
}

interface GuardianFilterBarProps {
    search: string;
    onSearch: (v: string) => void;
    statusFilter: string;
    onStatusFilter: (v: string) => void;
    loginFilter: string;
    onLoginFilter: (v: string) => void;
    childrenFilter: string;
    onChildrenFilter: (v: string) => void;
    dateFrom: string;
    onDateFrom: (v: string) => void;
    dateTo: string;
    onDateTo: (v: string) => void;
    total: number;
    showing: number;
    guardianStatuses: Option[];
}

const LOGIN_OPTIONS: SelectOption[] = [
    { label: 'All Login Status', value: '' },
    { label: 'Has Login',        value: 'has_login' },
    { label: 'No Login',         value: 'no_login' },
];

const CHILDREN_OPTIONS: SelectOption[] = [
    { label: 'All Children',  value: '' },
    { label: '1 child',       value: '1' },
    { label: '2–3 children',  value: '2-3' },
    { label: '4+ children',   value: '4+' },
];

/* ─── Small labelled control wrapper ─────────────────────────────────────── */

function Field({
    label, htmlFor, children, className,
}: { label: string; htmlFor?: string; children: React.ReactNode; className?: string }) {
    return (
        <div className={cn('flex flex-col gap-1', className)}>
            <label
                htmlFor={htmlFor}
                className="text-[10px] font-bold tracking-wide text-slate-400 uppercase"
            >
                {label}
            </label>
            {children}
        </div>
    );
}

/* ─── Component ──────────────────────────────────────────────────────────── */

export function GuardianFilterBar({
    search, onSearch,
    statusFilter, onStatusFilter,
    loginFilter, onLoginFilter,
    childrenFilter, onChildrenFilter,
    dateFrom, onDateFrom,
    dateTo, onDateTo,
    total, showing,
    guardianStatuses,
}: GuardianFilterBarProps) {
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const handleSearch = (val: string) => {
        if (debounceRef.current) clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(() => onSearch(val), 300);
    };

    useEffect(() => () => { if (debounceRef.current) clearTimeout(debounceRef.current); }, []);

    const activeCount =
        (statusFilter   ? 1 : 0) +
        (loginFilter    ? 1 : 0) +
        (childrenFilter ? 1 : 0) +
        (dateFrom       ? 1 : 0) +
        (dateTo         ? 1 : 0);
    const isFiltered = !!search || activeCount > 0;

    const statusOptions: SelectOption[] = [
        { label: 'All Status', value: '' },
        ...guardianStatuses.map(s => ({ label: s.name, value: s.value })),
    ];

    const clearAll = () => {
        onSearch(''); onStatusFilter(''); onLoginFilter('');
        onChildrenFilter(''); onDateFrom(''); onDateTo('');
    };

    const findOption = (opts: SelectOption[], value: string) =>
        opts.find(o => o.value === value) ?? opts[0];

    return (
        <div className="border-b border-slate-100 dark:border-slate-800">
            {/* ── Row 1: search + summary + clear ───────────────────────── */}
            <div className="flex flex-col gap-3 px-5 py-3 sm:flex-row sm:items-center">
                <div className="relative w-full sm:max-w-md sm:flex-1">
                    <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <Input
                        placeholder="Search by name, phone, or email…"
                        className="h-9 rounded-lg border-slate-200 bg-white pl-9 text-sm focus-visible:ring-2 focus-visible:ring-primary/20 dark:border-slate-700 dark:bg-slate-900"
                        defaultValue={search}
                        onChange={(e) => handleSearch(e.target.value)}
                    />
                </div>

                <div className="flex items-center gap-2 sm:ml-auto">
                    {activeCount > 0 && (
                        <span className="inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-primary/10 px-1.5 text-[10px] font-bold text-primary dark:bg-primary/10 dark:text-primary">
                            {activeCount} active
                        </span>
                    )}

                    {isFiltered && (
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={clearAll}
                            className="rounded-lg text-slate-500 hover:bg-slate-50 hover:text-slate-700 dark:hover:bg-slate-800 dark:hover:text-slate-200"
                        >
                            <X className="mr-1 h-3.5 w-3.5" />
                            Clear
                        </Button>
                    )}
                </div>
            </div>

            {/* ── Row 2: filter controls ────────────────────────────────── */}
            <div className="border-t border-slate-50 bg-slate-50/40 px-5 py-3 dark:border-slate-800 dark:bg-slate-900/30">
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5 lg:gap-4">
                    <Field label="Status" htmlFor="filter-status">
                        <SearchableSelect
                            inputId="filter-status"
                            options={statusOptions}
                            value={findOption(statusOptions, statusFilter)}
                            onChange={(opt: any) => onStatusFilter(opt?.value ?? '')}
                            isSearchable={false}
                        />
                    </Field>

                    <Field label="Login Access" htmlFor="filter-login">
                        <SearchableSelect
                            inputId="filter-login"
                            options={LOGIN_OPTIONS}
                            value={findOption(LOGIN_OPTIONS, loginFilter)}
                            onChange={(opt: any) => onLoginFilter(opt?.value ?? '')}
                            isSearchable={false}
                        />
                    </Field>

                    <Field label="Children" htmlFor="filter-children">
                        <SearchableSelect
                            inputId="filter-children"
                            options={CHILDREN_OPTIONS}
                            value={findOption(CHILDREN_OPTIONS, childrenFilter)}
                            onChange={(opt: any) => onChildrenFilter(opt?.value ?? '')}
                            isSearchable={false}
                        />
                    </Field>

                    <Field label="Created From" htmlFor="filter-date-from">
                        <Input
                            id="filter-date-from"
                            type="date"
                            className="h-9 rounded-md border-input bg-transparent text-sm shadow-sm focus-visible:ring-1 focus-visible:ring-ring"
                            value={dateFrom}
                            onChange={(e) => onDateFrom(e.target.value)}
                            max={dateTo || undefined}
                        />
                    </Field>

                    <Field label="Created To" htmlFor="filter-date-to">
                        <Input
                            id="filter-date-to"
                            type="date"
                            className="h-9 rounded-md border-input bg-transparent text-sm shadow-sm focus-visible:ring-1 focus-visible:ring-ring"
                            value={dateTo}
                            onChange={(e) => onDateTo(e.target.value)}
                            min={dateFrom || undefined}
                        />
                    </Field>
                </div>

                {/* Summary on mobile */}
                <p className="mt-3 text-xs text-slate-500 sm:hidden">
                    Showing <span className="font-bold text-slate-700 dark:text-slate-200">{showing}</span> of{' '}
                    <span className="font-bold text-slate-700 dark:text-slate-200">{total}</span> guardians
                </p>
            </div>
        </div>
    );
}
