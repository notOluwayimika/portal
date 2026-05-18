import {
    Activity,
    FilePlus2,
    FilePenLine,
    Trash2,
    LogIn,
    LogOut,
    ShieldAlert,
    KeyRound,
    Users,
} from 'lucide-react';
import type { Severity } from './types';

const ICONS: Record<string, typeof Activity> = {
    created: FilePlus2,
    updated: FilePenLine,
    deleted: Trash2,
    login: LogIn,
    logout: LogOut,
    login_failed: ShieldAlert,
    password_reset: KeyRound,
    role_assigned: Users,
    role_revoked: Users,
};

const SEVERITY_COLOR: Record<Severity, string> = {
    critical: 'text-red-600 dark:text-red-400',
    warning: 'text-amber-600 dark:text-amber-400',
    notice: 'text-blue-600 dark:text-blue-400',
    info: 'text-slate-400 dark:text-slate-500',
};

export function EventIcon({
    event,
    severity,
}: {
    event: string | null;
    severity: Severity;
}) {
    const Icon =
        (event &&
            (ICONS[event] ??
                ICONS[Object.keys(ICONS).find((k) => event.includes(k)) ?? ''])) ||
        Activity;

    return <Icon className={`h-4 w-4 shrink-0 ${SEVERITY_COLOR[severity]}`} aria-hidden />;
}
