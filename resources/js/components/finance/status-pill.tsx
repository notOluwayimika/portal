import { cn } from '@/lib/utils';

/**
 * The design system's status pill (docs/ui-ux-design-system.md § 14), factored out of the two
 * screens that were each carrying their own copy of the same markup.
 *
 * Tone is MEANING, never decoration — the map is the one in § 2: emerald = active/paid,
 * amber = pending/attention, red = error/withdrawn, blue = informational, violet = secondary
 * financial document, slate = neutral/settled/void. Colour is always paired with the text
 * label the caller passes, because colour alone is not a signal (§ 21).
 *
 * {@see AccountStatusBadge} is the sibling that derives its own tone from a balance's sign;
 * this one is for statuses that arrive already named by the API.
 */
export type StatusTone =
    | 'emerald'
    | 'amber'
    | 'red'
    | 'blue'
    | 'violet'
    | 'slate';

const TONES: Record<StatusTone, string> = {
    emerald:
        'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
    amber: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
    red: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
    blue: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    violet: 'bg-violet-100 text-violet-700 dark:bg-violet-900/30 dark:text-violet-400',
    slate: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
};

export function StatusPill({
    tone,
    label,
    className,
}: {
    tone: StatusTone;
    label: string;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold whitespace-nowrap',
                TONES[tone],
                className,
            )}
        >
            {label}
        </span>
    );
}
