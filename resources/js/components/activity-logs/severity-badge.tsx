import type { Severity } from './types';

const STYLES: Record<Severity, string> = {
    critical:
        'bg-red-100 text-red-700 dark:bg-red-950/60 dark:text-red-300 ring-1 ring-red-200 dark:ring-red-900',
    warning:
        'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300 ring-1 ring-amber-200 dark:ring-amber-900',
    notice: 'bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300 ring-1 ring-blue-200 dark:ring-blue-900',
    info: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300 ring-1 ring-slate-200 dark:ring-slate-700',
};

export function SeverityBadge({ severity }: { severity: Severity }) {
    return (
        <span
            className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ${STYLES[severity]}`}
        >
            {severity}
        </span>
    );
}

export function CategoryBadge({ category }: { category: string | null }) {
    if (!category) return null;
    return (
        <span className="inline-flex items-center rounded-full bg-slate-50 px-2 py-0.5 text-[10px] font-medium text-slate-500 ring-1 ring-slate-200 dark:bg-slate-900/40 dark:text-slate-400 dark:ring-slate-800">
            {category}
        </span>
    );
}
