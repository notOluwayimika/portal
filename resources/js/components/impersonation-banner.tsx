import { router, usePage } from '@inertiajs/react';
import { UserCog } from 'lucide-react';
import { useState } from 'react';

interface ImpersonationState {
    operator: string;
    target: string;
    school: string | null;
}

/**
 * The "you are not who this page says you are" banner.
 *
 * Rendered from the app layout so it is present on EVERY page — including the
 * ones the impersonated user's own roles reach, which is most of them. While a
 * session is active the whole UI (sidebar, permissions, school data) is the
 * target's, so this is the only thing distinguishing an impersonated session
 * from really being that person, and it is also the only way back: /super-admin
 * is unreachable while impersonating, because the role: gate sees the target.
 */
export default function ImpersonationBanner() {
    const impersonation = usePage().props.impersonation as
        | ImpersonationState
        | null
        | undefined;
    const [stopping, setStopping] = useState(false);

    if (!impersonation) {
        return null;
    }

    return (
        <div
            role="status"
            className="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100"
        >
            <UserCog className="h-4 w-4 shrink-0" aria-hidden />
            {/* A text label, never colour alone — the amber is the emphasis, not the message. */}
            <span className="font-semibold">Impersonating</span>
            <span className="min-w-0">
                You are acting as{' '}
                <strong className="font-semibold">
                    {impersonation.target}
                </strong>
                {impersonation.school ? ` at ${impersonation.school}` : ''}.
                Signed in as {impersonation.operator}. Every action is recorded
                against you.
            </span>
            <button
                type="button"
                disabled={stopping}
                onClick={() => {
                    setStopping(true);
                    router.delete('/impersonation', {
                        preserveScroll: false,
                        onFinish: () => setStopping(false),
                    });
                }}
                className="ml-auto shrink-0 rounded-md bg-amber-900 px-3 py-1 text-xs font-semibold text-amber-50 transition-colors hover:bg-amber-800 focus-visible:ring-2 focus-visible:ring-amber-600 focus-visible:outline-none disabled:opacity-60 dark:bg-amber-200 dark:text-amber-950 dark:hover:bg-amber-100"
            >
                {stopping ? 'Stopping…' : 'Stop impersonating'}
            </button>
        </div>
    );
}
