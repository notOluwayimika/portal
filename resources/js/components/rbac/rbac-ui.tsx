// ═══════════════════════════════════════════════════════════════════════════
// Shared RBAC console primitives.
//
// Every badge here carries a TEXT label as well as a colour, and every colour
// has a dark: counterpart — §14 and §20 of docs/ui-ux-design-system.md. Colour
// alone is not information.
// ═══════════════════════════════════════════════════════════════════════════

import type { LucideIcon } from 'lucide-react';
import {
    BookOpen,
    ChevronRight,
    ClipboardCheck,
    DoorOpen,
    GraduationCap,
    ScrollText,
    ShieldAlert,
    UserPlus,
    Users,
    Wallet,
} from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Maps the lucide icon NAME chosen server-side (PermissionGroup::icon()) to a component.
 * Kept as an explicit map rather than a dynamic lookup so the bundler can tree-shake, and so an
 * unknown name degrades to a sensible default instead of crashing the page.
 */
const GROUP_ICONS: Record<string, LucideIcon> = {
    ScrollText,
    Users,
    BookOpen,
    ClipboardCheck,
    UserPlus,
    DoorOpen,
    Wallet,
    ShieldAlert,
    GraduationCap,
};

export function GroupIcon({
    name,
    className,
}: {
    name: string;
    className?: string;
}) {
    const Icon = GROUP_ICONS[name] ?? ShieldAlert;

    return <Icon className={className} aria-hidden />;
}

type BadgeTone = 'slate' | 'indigo' | 'emerald' | 'amber' | 'rose' | 'violet';

const BADGE_TONES: Record<BadgeTone, string> = {
    slate: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
    indigo: 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300',
    emerald:
        'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    amber: 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
    rose: 'bg-rose-50 text-rose-700 dark:bg-rose-950 dark:text-rose-300',
    violet: 'bg-violet-50 text-violet-700 dark:bg-violet-950 dark:text-violet-300',
};

export function RbacBadge({
    tone = 'slate',
    children,
    title,
    className,
}: {
    tone?: BadgeTone;
    children: React.ReactNode;
    title?: string;
    className?: string;
}) {
    return (
        <span
            title={title}
            className={cn(
                'inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold',
                BADGE_TONES[tone],
                className,
            )}
        >
            {children}
        </span>
    );
}

/** The permission name itself — monospaced, because it is an identifier, not prose. */
export function PermissionName({ name }: { name: string }) {
    return (
        <code className="font-mono text-[11px] text-slate-700 dark:text-slate-200">
            {name}
        </code>
    );
}

export function ExpandChevron({ open }: { open: boolean }) {
    return (
        <ChevronRight
            className={cn(
                'h-4 w-4 shrink-0 text-slate-400 transition-transform',
                open && 'rotate-90',
            )}
            aria-hidden
        />
    );
}

/**
 * A granted/total coverage bar. Purely supplementary — the same numbers are always rendered as
 * text beside it, so the bar never carries information on its own.
 */
export function CoverageBar({
    granted,
    total,
}: {
    granted: number;
    total: number;
}) {
    const pct = total === 0 ? 0 : Math.round((granted / total) * 100);

    return (
        <div
            className="h-1.5 w-16 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"
            aria-hidden
        >
            <div
                className={cn(
                    'h-full rounded-full',
                    pct === 100 ? 'bg-emerald-500' : 'bg-indigo-500',
                )}
                style={{ width: `${pct}%` }}
            />
        </div>
    );
}

/** The single spanning row §13 requires for empty state inside a table. */
export function TableEmptyRow({
    colSpan,
    title,
    description,
    onClear,
}: {
    colSpan: number;
    title: string;
    description: string;
    onClear?: () => void;
}) {
    return (
        <tr>
            <td colSpan={colSpan} className="px-5 py-12">
                <div className="flex flex-col items-center gap-2 text-center">
                    <div className="flex size-12 items-center justify-center rounded-full bg-slate-50 dark:bg-slate-800">
                        <ShieldAlert
                            className="h-5 w-5 text-slate-400"
                            aria-hidden
                        />
                    </div>
                    <p className="text-xs font-bold text-slate-900 dark:text-white">
                        {title}
                    </p>
                    <p className="text-[11px] text-slate-500">{description}</p>
                    {onClear && (
                        <button
                            type="button"
                            onClick={onClear}
                            className="mt-1 rounded-lg px-2 py-1 text-[11px] font-semibold text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-950"
                        >
                            Clear filters
                        </button>
                    )}
                </div>
            </td>
        </tr>
    );
}

/** Search input + optional right-hand slot, per §7. */
export function FilterRow({
    value,
    onChange,
    placeholder,
    children,
}: {
    value: string;
    onChange: (v: string) => void;
    placeholder: string;
    children?: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-3 border-b border-slate-100 px-5 py-3 sm:flex-row sm:items-center dark:border-slate-800">
            <div className="relative w-full sm:max-w-xs">
                <svg
                    className="absolute top-1/2 left-3 h-3.5 w-3.5 -translate-y-1/2 text-slate-400"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="2"
                    viewBox="0 0 24 24"
                    aria-hidden
                >
                    <circle cx="11" cy="11" r="7" />
                    <path d="m21 21-4.3-4.3" />
                </svg>
                <input
                    type="search"
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    placeholder={placeholder}
                    className="h-9 w-full rounded-lg border border-slate-200 bg-white pr-3 pl-9 text-xs text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 focus:outline-none dark:border-slate-700 dark:bg-slate-900 dark:text-white"
                />
            </div>
            {children}
        </div>
    );
}
