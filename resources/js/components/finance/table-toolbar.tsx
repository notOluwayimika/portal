import { Search, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Props = {
    value: string;
    onChange: (value: string) => void;
    shown: number;
    total: number;
    placeholder?: string;
};

/**
 * The finance-module datatable filter row: a search box on the left, a "Showing X of Y"
 * count (+ Clear) on the right. Shared by the accounts index, the approvals queue and the
 * statement's section tables so they all read as one datatable.
 */
export function TableToolbar({
    value,
    onChange,
    shown,
    total,
    placeholder = 'Search…',
}: Props) {
    return (
        <div className="border-b border-slate-100 dark:border-slate-800">
            <div className="flex flex-col gap-3 px-5 py-3 sm:flex-row sm:items-center">
                <div className="relative w-full sm:max-w-md sm:flex-1">
                    <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <Input
                        placeholder={placeholder}
                        className="h-9 rounded-lg border-slate-200 bg-white pl-9 text-sm focus-visible:ring-2 focus-visible:ring-indigo-100 dark:border-slate-700 dark:bg-slate-900"
                        value={value}
                        onChange={(e) => onChange(e.target.value)}
                    />
                </div>

                <div className="flex items-center gap-2 sm:ml-auto">
                    <span className="hidden text-xs font-medium text-slate-500 sm:inline">
                        Showing{' '}
                        <span className="font-bold text-slate-700 dark:text-slate-200">
                            {shown}
                        </span>{' '}
                        of{' '}
                        <span className="font-bold text-slate-700 dark:text-slate-200">
                            {total}
                        </span>
                    </span>
                    {value !== '' && (
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => onChange('')}
                            className="rounded-lg text-slate-500 hover:bg-slate-50 hover:text-slate-700"
                        >
                            <X className="mr-1 h-3.5 w-3.5" />
                            Clear
                        </Button>
                    )}
                </div>
            </div>
        </div>
    );
}
