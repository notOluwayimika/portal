import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

export type StatTone = 'indigo' | 'emerald' | 'amber' | 'rose' | 'slate';

// Tile gradient + icon colour per tone — mirrors the Student module's hero icon treatment
// (bg-linear-to-br tile, ring-1 ring-black/5) so Finance reads as the same design language.
const toneStyles: Record<StatTone, { tile: string; icon: string }> = {
    indigo: {
        tile: 'from-indigo-50 to-violet-50 dark:from-indigo-950/50 dark:to-violet-950/50',
        icon: 'text-indigo-600 dark:text-indigo-400',
    },
    emerald: {
        tile: 'from-emerald-50 to-teal-50 dark:from-emerald-950/50 dark:to-teal-950/50',
        icon: 'text-emerald-600 dark:text-emerald-400',
    },
    amber: {
        tile: 'from-amber-50 to-orange-50 dark:from-amber-950/50 dark:to-orange-950/50',
        icon: 'text-amber-600 dark:text-amber-400',
    },
    rose: {
        tile: 'from-rose-50 to-pink-50 dark:from-rose-950/50 dark:to-pink-950/50',
        icon: 'text-rose-600 dark:text-rose-400',
    },
    slate: {
        tile: 'from-slate-50 to-slate-100 dark:from-slate-900 dark:to-slate-800',
        icon: 'text-slate-600 dark:text-slate-300',
    },
};

type Props = {
    icon: LucideIcon;
    label: string;
    value: string;
    subText?: string;
    tone?: StatTone;
    loading?: boolean;
};

/**
 * A premium metric card shared by the Finance pages (accounts KPIs + statement account
 * position). Card chrome matches the Student module (rounded-2xl, soft shadow, gradient
 * icon tile); the value is rendered by the caller — money always via formatNaira, so this
 * component performs no money arithmetic and just displays the already-formatted string.
 */
export function FinanceStatCard({
    icon: Icon,
    label,
    value,
    subText,
    tone = 'indigo',
    loading = false,
}: Props) {
    return (
        <div className="relative overflow-hidden rounded-2xl border border-white bg-white px-5 py-4 shadow-[0_8px_30px_rgb(0,0,0,0.04)] transition-shadow hover:shadow-[0_10px_40px_rgb(0,0,0,0.07)] dark:border-white/5 dark:bg-card">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-xs font-medium text-slate-500">
                        {label}
                    </p>
                    {loading ? (
                        <div className="mt-2 h-7 w-28 animate-pulse rounded-md bg-slate-100 dark:bg-slate-800" />
                    ) : (
                        <p className="mt-1 truncate text-2xl font-extrabold tracking-tight text-slate-900 tabular-nums dark:text-white">
                            {value}
                        </p>
                    )}
                    {subText && (
                        <p className="mt-1 text-xs text-slate-400">{subText}</p>
                    )}
                </div>
                <div
                    className={cn(
                        'flex size-11 shrink-0 items-center justify-center rounded-xl bg-linear-to-br shadow-sm ring-1 ring-black/5',
                        toneStyles[tone].tile,
                    )}
                >
                    <Icon className={cn('h-5 w-5', toneStyles[tone].icon)} />
                </div>
            </div>
        </div>
    );
}
