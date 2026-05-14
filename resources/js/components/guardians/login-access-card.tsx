import axios from 'axios';
import { KeyRound, LogIn, LogOut, Mail, RotateCcw, ShieldOff } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import type { Guardian } from '@/types/models';

interface LoginAccessCardProps {
    guardian: Guardian;
    onUpdate: (updated: Guardian) => void;
    onError: (msg: string) => void;
}

type ActionKey = 'enable' | 'disable' | 'reset' | 'resend';

function isSyntheticEmail(email?: string | null): boolean {
    return !!email && email.endsWith('@no-email.local');
}

export function LoginAccessCard({ guardian, onUpdate, onError }: LoginAccessCardProps) {
    const [busy, setBusy] = useState<ActionKey | null>(null);

    const user = guardian;
    const hasRealEmail = !!guardian.email && !isSyntheticEmail(guardian.email);

    const hasLogin    = !!guardian.has_login;
    const isDisabled  = !guardian.has_login && !!guardian.user_disabled_at;
    const noAccount   = !guardian.has_login && !guardian.user_disabled_at;

    const perform = async (key: ActionKey, endpoint: string) => {
        if (busy) return;
        setBusy(key);
        try {
            const res = await axios.post(`/api/guardians/${guardian.id}/${endpoint}`);
            const updated = res.data?.data;
            if (updated) onUpdate(updated);
        } catch (err: unknown) {
            const msg =
                (err as { response?: { data?: { message?: string } } })?.response?.data?.message ??
                'Action failed. Please try again.';
            onError(msg);
            setBusy(null);
            return;
        }
        setBusy(null);
    };

    const confirm = (msg: string, action: () => void) => {
        if (window.confirm(msg)) action();
    };

    const loginStatusBadge = () => {
        if (hasLogin)    return <Badge className="bg-emerald-100 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-900/40 dark:text-emerald-400">Login enabled</Badge>;
        if (isDisabled)  return <Badge className="bg-amber-100 text-amber-700 hover:bg-amber-100 dark:bg-amber-900/20 dark:text-amber-400">Login disabled</Badge>;
        return <Badge variant="secondary">No login access</Badge>;
    };

    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle className="text-base">Login Access</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="flex flex-wrap items-center gap-3">
                    {loginStatusBadge()}
                    {guardian.email && !isSyntheticEmail(guardian.email) && (
                        <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                            <Mail className="h-3 w-3" />
                            {guardian.email}
                        </span>
                    )}
                </div>

                {guardian.email_verified_at && (
                    <p className="text-xs text-muted-foreground">
                        Account activated: {new Date(guardian.email_verified_at).toLocaleDateString()}
                    </p>
                )}

                <div className="flex flex-wrap gap-2">
                    {/* Enable login */}
                    {(noAccount || isDisabled) && (
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={!!busy}
                            onClick={() =>
                                confirm(
                                    `Enable login access for ${guardian.full_name}?`,
                                    () => perform('enable', 'enable-login'),
                                )
                            }
                        >
                            {busy === 'enable' ? (
                                <Spinner className="mr-2 h-3.5 w-3.5 animate-spin" />
                            ) : (
                                <LogIn className="mr-2 h-3.5 w-3.5" />
                            )}
                            Enable Login
                        </Button>
                    )}

                    {/* Disable login */}
                    {hasLogin && (
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={!!busy}
                            onClick={() =>
                                confirm(
                                    `Disable login access for ${guardian.full_name}? They will no longer be able to sign in.`,
                                    () => perform('disable', 'disable-login'),
                                )
                            }
                        >
                            {busy === 'disable' ? (
                                <Spinner className="mr-2 h-3.5 w-3.5 animate-spin" />
                            ) : (
                                <ShieldOff className="mr-2 h-3.5 w-3.5" />
                            )}
                            Disable Login
                        </Button>
                    )}

                    {/* Reset password */}
                    {hasLogin && hasRealEmail && (
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={!!busy}
                            onClick={() =>
                                confirm(
                                    `Send a password reset link to ${guardian.email}?`,
                                    () => perform('reset', 'reset-password'),
                                )
                            }
                        >
                            {busy === 'reset' ? (
                                <Spinner className="mr-2 h-3.5 w-3.5 animate-spin" />
                            ) : (
                                <KeyRound className="mr-2 h-3.5 w-3.5" />
                            )}
                            Reset Password
                        </Button>
                    )}

                    {/* Resend invitation */}
                    {!!guardian.never_activated && hasRealEmail && (
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={!!busy}
                            onClick={() =>
                                confirm(
                                    `Resend the login invitation to ${guardian.email}?`,
                                    () => perform('resend', 'resend-invitation'),
                                )
                            }
                        >
                            {busy === 'resend' ? (
                                <Spinner className="mr-2 h-3.5 w-3.5 animate-spin" />
                            ) : (
                                <RotateCcw className="mr-2 h-3.5 w-3.5" />
                            )}
                            Resend Invitation
                        </Button>
                    )}
                </div>

                {noAccount && (
                    <p className="text-xs text-muted-foreground">
                        This guardian has no login account.
                        {!hasRealEmail && ' A valid email address is required to enable login.'}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}
