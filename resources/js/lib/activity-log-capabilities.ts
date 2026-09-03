import type { ActivityCapabilities } from '@/components/activity-logs/types';

/**
 * WHAT THE ACTIVITY LOG SCREEN MAY OFFER — derived from PERMISSIONS, never from role names.
 *
 * ── THE RULE, STATED ONCE FOR THE WHOLE OBJECT ─────────────────────────────────────────────────
 *
 * **No capability in `ActivityCapabilities` derives from a role name.**
 *
 * Stated at the object and not per field, because per-field is exactly how the second one was
 * missed: the ticket that raised this named `canExport` alone, and `canViewSystem` — the same
 * defect, one line above it — went unnoticed until someone re-read the object. A rule written once
 * per member is a rule that covers the members someone happened to look at.
 *
 * ── WHY IT MATTERS, WITH THE LIVE INSTANCE ─────────────────────────────────────────────────────
 *
 * These are second spellings of authorities whose canonical spelling is a permission. The server
 * answers "may this user export?" with `activity_log.export`; a hand-maintained list of role names
 * answers it again, and nothing keeps the two in step — no lint, no test, no type.
 *
 * `canExport` HAD ALREADY DRIFTED. It listed admin, head_of_school and super_admin.
 * `internal_auditor` holds `activity_log.export` and was not in the list, so the seat that exists to
 * read and export this log could not see the Export button — while the route and the API both
 * admitted it.
 *
 * `canViewSystem` had NOT drifted, and that is the more instructive half. `activity_log.view_system`
 * has exactly one holder (`super_admin`, via `RbacSeeder::SUPER_ADMIN_PLATFORM`), so
 * `roles.includes('super_admin')` and `can('activity_log.view_system')` name the same set TODAY.
 * Equivalent-today is precisely the condition under which a copy survives long enough to drift: the
 * day that permission is granted to a second seat, the control silently stops appearing for them
 * and nothing fails. It gets its own arm for that reason, not because it is currently wrong.
 */
export function activityLogCapabilities(
    can: (ability: string) => boolean,
): ActivityCapabilities {
    return {
        canViewSystem: can('activity_log.view_system'),
        canExport: can('activity_log.export'),
    };
}
