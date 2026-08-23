import { Download, GraduationCap } from 'lucide-react';
import { Button } from '@/components/ui/button';

interface StudentBulkActionBarProps {
    count: number;
    /** How many distinct classes the selection spans. 1 is the only reassignable value. */
    cohortCount: number;
    exporting: boolean;
    onClearSelection: () => void;
    onExportSelected: () => void;
    onReassign: () => void;
}

/**
 * The footer that appears once pupils are ticked.
 *
 * ── SELECTION IS PAGE-SCOPED, AND THERE IS NO "SELECT ALL MATCHING" ──────────────────────────────
 * The guardians index has that control and it is a lie there: it claims a scope the browser cannot
 * hold, because the client only ever has the ids it was sent. Nothing here needs it — the toolbar's
 * Export already covers "everything the filters select", computed server-side, so the only job left
 * for this bar is "exactly these ticked rows". Two orthogonal scopes, each named in its own control,
 * neither implied by where the button sits.
 *
 * ── THE DISABLED REASON IS THE USABILITY HALF OF THE COHORT LOCK ─────────────────────────────────
 * A reassignment moves one class into a sibling arm, so a selection spanning two classes has no
 * single legal destination. Without the reason rendered next to it, an operator ticks a mixed set,
 * finds the button dead, and cannot tell whether the feature is broken or they are holding it wrong.
 * The server enforces the same rule; this only explains it.
 */
export function StudentBulkActionBar({
    count,
    cohortCount,
    exporting,
    onClearSelection,
    onExportSelected,
    onReassign,
}: StudentBulkActionBarProps) {
    if (count === 0) {
        return null;
    }

    const spansCohorts = cohortCount > 1;

    return (
        <div className="fixed inset-x-0 bottom-0 z-40 border-t bg-background/95 px-6 py-3 shadow-lg backdrop-blur">
            <div className="flex flex-wrap items-center gap-3">
                <span className="text-sm font-medium">{count} selected</span>

                <button
                    type="button"
                    className="text-xs text-muted-foreground underline-offset-2 hover:underline"
                    onClick={onClearSelection}
                >
                    Clear
                </button>

                {spansCohorts && (
                    <span className="text-xs text-amber-600 dark:text-amber-500">
                        Reassign moves one class at a time; your selection spans{' '}
                        {cohortCount} classes.
                    </span>
                )}

                <div className="ml-auto flex flex-wrap gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={onExportSelected}
                        disabled={exporting}
                    >
                        <Download className="mr-1.5 h-3.5 w-3.5" />
                        {/* The count lives IN the label: this button's scope is the selection, and
                            saying so in the control is the whole difference between it and the
                            toolbar's filter-scoped Export. */}
                        {exporting
                            ? 'Exporting…'
                            : `Export selected (${count})`}
                    </Button>

                    <Button
                        size="sm"
                        onClick={onReassign}
                        disabled={spansCohorts}
                        title={
                            spansCohorts
                                ? `Your selection spans ${cohortCount} classes. Select pupils from a single class.`
                                : undefined
                        }
                    >
                        <GraduationCap className="mr-1.5 h-3.5 w-3.5" />
                        Reassign
                    </Button>
                </div>
            </div>
        </div>
    );
}
