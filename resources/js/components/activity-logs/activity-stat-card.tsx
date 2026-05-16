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
    const toneRing =
        tone === 'danger'
            ? 'ring-red-200 dark:ring-red-900'
            : tone === 'warning'
              ? 'ring-amber-200 dark:ring-amber-900'
              : 'ring-slate-200 dark:ring-slate-800';

    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={`flex w-full items-center gap-3 rounded-xl border bg-white p-4 text-left shadow-[0_4px_20px_rgb(0,0,0,0.03)] ring-1 transition hover:shadow-md dark:bg-slate-900/40 ${toneRing} ${
                active ? 'border-primary' : 'border-transparent'
            }`}
        >
            <span className="rounded-lg bg-slate-100 p-2 dark:bg-slate-800">
                <Icon className="h-5 w-5 text-slate-500 dark:text-slate-300" aria-hidden />
            </span>
            <span className="min-w-0">
                <span className="block text-2xl font-semibold tabular-nums">{value}</span>
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
