import type { LucideIcon } from 'lucide-react';

export function ActivityStatCard({
    label,
    value,
    icon: Icon,
    tone = 'neutral',
    badge,
    onClick,
    active = false,
}: {
    label: string;
    value: number | string;
    icon: LucideIcon;
    tone?: 'neutral' | 'danger' | 'warning';
    badge?: string | null;
    onClick?: () => void;
    active?: boolean;
}) {
    const iconBg =
        tone === 'danger'
            ? 'bg-red-50 ring-red-100 dark:bg-red-950/40 dark:ring-red-900'
            : tone === 'warning'
              ? 'bg-amber-50 ring-amber-100 dark:bg-amber-950/40 dark:ring-amber-900'
              : 'bg-white ring-slate-200 dark:bg-slate-900 dark:ring-slate-700';

    const iconColor =
        tone === 'danger'
            ? 'text-red-500 dark:text-red-400'
            : tone === 'warning'
              ? 'text-amber-500 dark:text-amber-400'
              : 'text-primary dark:text-primary';

    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={`flex w-full items-center gap-3 rounded-xl border-none bg-white p-4 text-left shadow-[0_8px_30px_rgb(0,0,0,0.04)] transition hover:shadow-md dark:bg-slate-900/40 ${
                active ? 'ring-2 ring-primary' : ''
            }`}
        >
            <span className={`flex size-10 shrink-0 items-center justify-center rounded-xl shadow-sm ring-1 ${iconBg}`}>
                <Icon className={`h-5 w-5 ${iconColor}`} aria-hidden />
            </span>
            <span className="min-w-0">
                <span className="block text-2xl font-bold tabular-nums text-slate-900 dark:text-white">{value}</span>
                <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    {label}
                    {badge && (
                        <span className="rounded-full bg-red-100 px-1.5 py-px text-[10px] font-semibold text-red-700 dark:bg-red-950 dark:text-red-300">
                            {badge}
                        </span>
                    )}
                </span>
            </span>
        </button>
    );
}
