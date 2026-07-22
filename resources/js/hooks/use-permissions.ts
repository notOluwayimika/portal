import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import type { Auth } from '@/types/auth';

export type UsePermissionsReturn = {
    /** True if the current user's effective set holds every named permission. */
    can: (...permissions: string[]) => boolean;
    /** True if the current user's effective set holds at least one named permission. */
    canAny: (...permissions: string[]) => boolean;
    permissions: ReadonlySet<string>;
};

/**
 * The current user's EFFECTIVE permissions (C4) — what the backend Gate will
 * actually allow in the active school, shared by HandleInertiaRequests via
 * EffectivePermissions. Gate ACTIONS on these (buttons, mutating controls).
 *
 * Do NOT gate sidebar persona menus on them: super_admin's effective set is
 * ~everything, so it would surface every persona menu (c4-brief D2). Menu
 * selection is persona presentation and stays role-driven.
 */
export function usePermissions(): UsePermissionsReturn {
    const { auth } = usePage<{ auth: Auth }>().props;

    return useMemo(() => {
        const permissions = new Set(auth?.permissions ?? []);

        return {
            permissions,
            can: (...names: string[]) =>
                names.every((name) => permissions.has(name)),
            canAny: (...names: string[]) =>
                names.some((name) => permissions.has(name)),
        };
    }, [auth?.permissions]);
}
