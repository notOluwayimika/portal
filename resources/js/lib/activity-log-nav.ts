import { History } from 'lucide-react';
import type { NavGroup } from '@/types/navigation';

/**
 * THE AUDIT FEED'S SIDEBAR GROUP.
 *
 * MOVED OUT OF `adminNavGroups`, NOT COPIED. It used to be the sole item of that array's `System`
 * group, pushed behind `can('admin_area.access')` — and `internal_auditor` does not hold that, so
 * the entry was invisible to the one seat that exists to read the log. The enclosing gate wins over
 * the item, which is the same trap that made the review-queue entry useless and the same one that
 * put both routes in their own top-level groups.
 *
 * THE `System` GROUP WAS PRUNED WITH IT, header and all: Activity Log was its only item, so leaving
 * the group behind would render a section heading with nothing under it.
 *
 * THE ABILITY IS `activity_log.view_all`, THE SAME ONE THE ROUTE CARRIES. Not `activity_log.view`:
 * `teacher` holds that, and this is the school-wide feed. `view_all` is what
 * ActivityLogQueryService::baseQuery keys on to drop the self-filter, so the menu, the route and the
 * query all now ask one question — a viewer without it would see nothing here but their own trail.
 *
 * IT MUST BE CALLED OUTSIDE `can('admin_area.access')`. This function cannot enforce that;
 * `app-sidebar.tsx` carries the comment at the call site, and `activity-log-nav.test.ts` asserts the
 * item appears exactly once across the assembled groups so a re-add to `adminNavGroups` reds.
 */
export function activityLogNavGroup(
    can: (ability: string) => boolean,
): NavGroup | null {
    if (!can('activity_log.view_all')) {
        return null;
    }

    return {
        label: 'System',
        items: [
            {
                title: 'Activity Log',
                href: '/activity-logs',
                icon: History,
            },
        ],
    };
}
