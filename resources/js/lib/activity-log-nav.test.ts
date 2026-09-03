import { describe, expect, it } from 'vitest';
import { adminNavGroups } from '@/components/app-sidebar';
import type { NavGroup } from '@/types/navigation';
import { activityLogCapabilities } from './activity-log-capabilities';
import { activityLogNavGroup } from './activity-log-nav';

/** A `can` that answers only for the abilities this seat actually holds. */
const seat =
    (...held: string[]) =>
    (ability: string) =>
        held.includes(ability);

const hrefs = (groups: NavGroup[]): string[] =>
    groups.flatMap((g) => g.items.map((i) => String(i.href)));

describe('the Activity Log sidebar entry', () => {
    it('renders for a seat holding activity_log.view_all', () => {
        const group = activityLogNavGroup(seat('activity_log.view_all'));

        expect(group).not.toBeNull();
        expect(group?.items).toHaveLength(1);
        // The href must be the route's, or the entry is navigation to a 404.
        expect(group?.items[0].href).toBe('/activity-logs');
    });

    it('does NOT render for a seat without it', () => {
        expect(activityLogNavGroup(seat())).toBeNull();
    });

    it('does not render for a seat holding only activity_log.view', () => {
        // BOTH DIRECTIONS, because either alone passes on a gate that is simply always-true or
        // always-false. `teacher` holds `view` and not `view_all`; this is the school-wide feed,
        // and gating on `view` would put it in front of every teacher.
        expect(activityLogNavGroup(seat('activity_log.view'))).toBeNull();
    });

    it('does not render for a seat holding only admin_area.access', () => {
        // The OLD gate. An entry still keyed on it would be invisible to internal_auditor — which
        // is the whole defect being fixed — and visible to seats the route now refuses.
        expect(activityLogNavGroup(seat('admin_area.access'))).toBeNull();
    });
});

describe('the entry was MOVED, not copied', () => {
    it('no longer appears in adminNavGroups', () => {
        // A duplicate is the failure mode a MOVE has and a copy does not. It was the sole item of
        // that array's System group; re-adding it there would give every admin the entry twice.
        expect(hrefs(adminNavGroups)).not.toContain('/activity-logs');
    });

    it('leaves no empty System group behind', () => {
        // Activity Log was the only item under that heading, so the group had to be pruned with it
        // — a header with nothing under it is the other half of a careless move.
        expect(adminNavGroups.some((g) => g.label === 'System')).toBe(false);
        expect(adminNavGroups.every((g) => g.items.length > 0)).toBe(true);
    });

    it('an admin sees the entry exactly once across the assembled sidebar', () => {
        // admin holds BOTH admin_area.access and activity_log.view_all, so it is the seat a
        // duplicate would show up on. Assembled the way the sidebar assembles it: the admin array,
        // plus the gated group.
        const can = seat('admin_area.access', 'activity_log.view_all');
        const auditGroup = activityLogNavGroup(can);
        const assembled = [
            ...adminNavGroups,
            ...(auditGroup === null ? [] : [auditGroup]),
        ];

        expect(
            hrefs(assembled).filter((h) => h === '/activity-logs'),
        ).toHaveLength(1);
    });
});

describe('the activity-log capabilities', () => {
    it('shows Export to a seat holding activity_log.export', () => {
        // TICKET 1, ENTIRE. internal_auditor holds activity_log.export and holds NO role named in
        // the list this replaced, so the Export button was invisible to the one seat that exists to
        // export this log — while the route and the API both admitted it.
        expect(
            activityLogCapabilities(seat('activity_log.export')).canExport,
        ).toBe(true);
    });

    it('does NOT show Export to a seat holding activity_log.view alone', () => {
        expect(
            activityLogCapabilities(seat('activity_log.view')).canExport,
        ).toBe(false);
    });

    it('derives canViewSystem from activity_log.view_system, not from a role name', () => {
        // IT HAS NOT DRIFTED, AND THAT IS WHY IT NEEDS AN ARM. `activity_log.view_system` has one
        // holder today (super_admin, via RbacSeeder::SUPER_ADMIN_PLATFORM), so the permission and
        // the role name select the same set — equivalent-today is exactly the condition under
        // which a copy survives long enough to drift. Granting that permission to a second seat
        // must switch this on for them, and a role-name check never would.
        expect(
            activityLogCapabilities(seat('activity_log.view_system'))
                .canViewSystem,
        ).toBe(true);
        expect(activityLogCapabilities(seat()).canViewSystem).toBe(false);
        // A seat holding every OTHER activity-log ability still does not get it.
        expect(
            activityLogCapabilities(
                seat(
                    'activity_log.view',
                    'activity_log.view_all',
                    'activity_log.export',
                ),
            ).canViewSystem,
        ).toBe(false);
    });
});
