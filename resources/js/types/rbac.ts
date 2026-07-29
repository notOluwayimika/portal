/**
 * The super-admin RBAC console payload, assembled by `App\Support\RbacOverview`.
 *
 * The whole grant graph ships with the page — 74 permissions and a dozen roles is a few KB — so
 * every count here is server-computed and every filter is client-side over the same arrays. That
 * pairing is deliberate: it is the only way the "showing X of Y" counters cannot disagree with the
 * numbers on the stat cards.
 */

export interface RbacPermission {
    name: string;
    /** Derived from the name, for reading and searching. The name remains the identifier. */
    label: string;
    /** Roles holding this permission, from the inverted grant map. */
    roles: string[];
    roleCount: number;
    /** Granted to no role at all — either dead, or revoked and never replaced. */
    unused: boolean;
    /** An approve/reject action, excluded from the super-admin bypass (ADR 0040). */
    isChecker: boolean;
    /** The maker whose work this checker signs off, derived by convention. */
    matchingMaker: string | null;
}

export interface RbacGroup {
    key: string;
    label: string;
    description: string;
    /** A lucide component name; mapped to a component in `group-icon.tsx`. */
    icon: string;
    permissionCount: number;
    grantedCount: number;
    permissions: RbacPermission[];
}

export interface RbacRole {
    name: string;
    /** False only for super_admin, which is structurally immutable here (D1 / ADR 0045). */
    editable: boolean;
    immutableReason: string | null;
    twoFactorRequired: boolean;
    permissions: string[];
    permissionCount: number;
    /** Distinct PEOPLE. A user holding this role in three schools counts once. */
    holderCount: number;
    /** Pivot rows — always >= holderCount. Shown beside it, never instead of it. */
    assignmentCount: number;
    schoolCount: number;
    holdsMaker: boolean;
    holdsChecker: boolean;
    lastChangedAt: string | null;
}

export interface RbacSodPair {
    maker: string;
    checker: string;
}

export interface RbacStats {
    permissionCount: number;
    groupCount: number;
    roleCount: number;
    grantCount: number;
    unusedPermissionCount: number;
    rolesWithoutHolders: number;
    twoFactorRoleCount: number;
}

export type RbacTab = 'catalog' | 'roles' | 'history';

/**
 * A type alias, not an interface, on purpose: Inertia's `usePage<T>` constrains T to `PageProps`,
 * which carries an index signature. Type aliases get one implicitly; interfaces do not, so an
 * interface here fails the constraint with a confusing error.
 */
export type RbacPageProps = {
    groups: RbacGroup[];
    roles: RbacRole[];
    sodPairs: RbacSodPair[];
    stats: RbacStats;
    tab: RbacTab;
};

// ─── School-admin console (/setup/users) ───────────────────────────────────

export interface SchoolUser {
    uuid: string;
    name: string;
    email: string;
    /** Role names held IN THIS SCHOOL. */
    roles: string[];
    /** False for super admins and for yourself — mirrors the server's structural guards. */
    editable: boolean;
    lockReason: string | null;
    /**
     * True only when the VIEWER may start an impersonation session as this user
     * (ADR 0045): viewer is a super admin holding `rbac.impersonate`, and the
     * target is neither a super admin nor the viewer. Mirrors
     * ImpersonationController's refusals so the UI never offers a rejected write.
     */
    impersonable: boolean;
    schoolId: number;
}

export interface SchoolRole {
    name: string;
    permissions: string[];
    permissionCount: number;
    /** Users in THIS school holding it. A role held widely elsewhere reads as zero here. */
    holderCount: number;
    assignable: boolean;
    unassignableReason: string | null;
    twoFactorRequired: boolean;
    holdsMaker: boolean;
    holdsChecker: boolean;
}

export interface SchoolRbacStats {
    userCount: number;
    roleCount: number;
    assignableRoleCount: number;
    unusedRoleCount: number;
    multiRoleUserCount: number;
}

export type SchoolRbacTab = 'users' | 'roles' | 'permissions' | 'history';

/**
 * Type alias, not interface — Inertia's usePage<T> needs the implicit index signature.
 */
export type SchoolRbacPageProps = {
    users: {
        data: SchoolUser[];
        pagination: {
            total: number;
            per_page: number;
            current_page: number;
            last_page: number;
        };
    };
    roles: SchoolRole[];
    /** The same catalogue the super-admin console renders, from one shared builder. */
    groups: RbacGroup[];
    sodPairs: RbacSodPair[];
    assignableRoles: string[];
    stats: SchoolRbacStats;
    filters: { search: string | null; role: string | null };
    school: { name: string };
    tab: SchoolRbacTab;
};
