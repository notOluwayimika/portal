import { Search, X } from 'lucide-react';
import { useEffect, useRef } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

interface Option {
    name: string;
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

const LOGIN_OPTIONS = [
    { label: 'All Login Status', value: '' },
    { label: 'Has Login',        value: 'has_login' },
    { label: 'No Login',         value: 'no_login' },
];

const CHILDREN_OPTIONS = [
    { label: 'All Children',  value: '' },
    { label: '1 child',       value: '1' },
    { label: '2–3 children',  value: '2-3' },
    { label: '4+ children',   value: '4+' },
];

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

    const isFiltered = search || statusFilter || loginFilter || childrenFilter || dateFrom || dateTo;

    const statusOptions = [{ label: 'All Status', value: '' }, ...guardianStatuses.map(s => ({ label: s.name, value: s.value }))];

    return (
        <div className="space-y-3 border-b p-4">
            <div className="flex flex-wrap items-center gap-3">
                <div className="relative min-w-[220px] flex-1">
                    <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        placeholder="Search name, phone, email…"
                        className="pl-9"
                        defaultValue={search}
                        onChange={(e) => handleSearch(e.target.value)}
                    />
                </div>

                <select
                    className="h-9 rounded-md border bg-background px-3 text-sm"
                    value={statusFilter}
                    onChange={(e) => onStatusFilter(e.target.value)}
                >
                    {statusOptions.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                </select>

                <select
                    className="h-9 rounded-md border bg-background px-3 text-sm"
                    value={loginFilter}
                    onChange={(e) => onLoginFilter(e.target.value)}
                >
                    {LOGIN_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                </select>

                <select
                    className="h-9 rounded-md border bg-background px-3 text-sm"
                    value={childrenFilter}
                    onChange={(e) => onChildrenFilter(e.target.value)}
                >
                    {CHILDREN_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                </select>

                <Input
                    type="date"
                    className="h-9 w-auto"
                    value={dateFrom}
                    onChange={(e) => onDateFrom(e.target.value)}
                    title="From date"
                />
                <Input
                    type="date"
                    className="h-9 w-auto"
                    value={dateTo}
                    onChange={(e) => onDateTo(e.target.value)}
                    title="To date"
                />

                {isFiltered && (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => { onSearch(''); onStatusFilter(''); onLoginFilter(''); onChildrenFilter(''); onDateFrom(''); onDateTo(''); }}
                    >
                        <X className="mr-1 h-3.5 w-3.5" />
                        Clear
                    </Button>
                )}
            </div>

            <p className="text-xs text-muted-foreground">
                Showing {showing} of {total} guardians
            </p>
        </div>
    );
}
