import type { ReactNode } from 'react';
import { usePermissions } from '@/hooks/use-permissions';

type CanProps = {
    /** Render children only if the user holds ALL of these permissions. */
    permission?: string | string[];
    /** Render children if the user holds ANY of these permissions. */
    permissionAny?: string | string[];
    /** Optional fallback when the check fails. */
    fallback?: ReactNode;
    children: ReactNode;
};

const toArray = (value: string | string[] | undefined): string[] =>
    value === undefined ? [] : Array.isArray(value) ? value : [value];

/**
 * Gate an ACTION on the user's effective permissions (C4). Reflects what the
 * backend Gate will allow — including the super-admin bypass and ADR 0040's
 * checker exclusion — so a control never shows for something the server denies,
 * and never hides something the server would allow (the super_admin case).
 *
 *   <Can permission="admin_area.access"><ImportButton /></Can>
 *   <Can permissionAny={['result.approve', 'result.reject']}>…</Can>
 *
 * For persona menu selection use roles, not this (c4-brief D2).
 */
export function Can({
    permission,
    permissionAny,
    fallback = null,
    children,
}: CanProps) {
    const { can, canAny } = usePermissions();

    const all = toArray(permission);
    const any = toArray(permissionAny);

    const allowed =
        (all.length > 0 || any.length > 0) &&
        (all.length === 0 || can(...all)) &&
        (any.length === 0 || canAny(...any));

    return <>{allowed ? children : fallback}</>;
}
