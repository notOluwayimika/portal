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
        if (hasLogin) {
            return (
                <Badge className="rounded-full bg-emerald-50 px-3 py-1 text-[11px] font-bold text-emerald-600 shadow-sm hover:bg-emerald-50 dark:bg-emerald-500/10 dark:text-emerald-400">
                    <LogIn className="mr-1.5 h-3 w-3" />
                    Login Enabled
                </Badge>
            );
        }
        if (isDisabled) {
            return (
                <Badge className="rounded-full bg-amber-50 px-3 py-1 text-[11px] font-bold text-amber-600 shadow-sm hover:bg-amber-50 dark:bg-amber-500/10 dark:text-amber-400">
                    <ShieldOff className="mr-1.5 h-3 w-3" />
                    Login Disabled
                </Badge>
            );
        }
        return (
            <Badge variant="secondary" className="rounded-full px-3 py-1 text-[11px] font-bold text-slate-500 shadow-sm">
                No Account
            </Badge>
        );
    };

    return (
        <Card className="overflow-hidden rounded-[1.5rem] border-none shadow-[0_8px_30px_rgb(0,0,0,0.04)]">
            <CardHeader className="border-b border-slate-50 bg-slate-50/30 px-6 py-5">
                <CardTitle className="flex items-center gap-3 text-base font-bold text-slate-800">
                    <div className="flex size-8 items-center justify-center rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
                        <KeyRound className="h-4 w-4 text-indigo-600" />
                    </div>
                    Login Access
                </CardTitle>
            </CardHeader>
            <CardContent className="p-6 space-y-6">
                <div className="flex flex-col gap-4">
                    <div className="flex items-center justify-between">
                        <span className="text-xs font-bold tracking-wide text-slate-400 uppercase">Status</span>
                        {loginStatusBadge()}
                    </div>

                    {guardian.email && !isSyntheticEmail(guardian.email) && (
                        <div className="flex items-center justify-between rounded-xl bg-slate-50 p-3 ring-1 ring-slate-100">
                            <div className="flex items-center gap-2 overflow-hidden">
                                <Mail className="h-4 w-4 shrink-0 text-slate-400" />
                                <span className="truncate text-sm font-semibold text-slate-600">{guardian.email}</span>
                            </div>
                        </div>
                    )}
                </div>

                {guardian.email_verified_at && (
                    <div className="rounded-xl border border-emerald-100 bg-emerald-50/30 p-3 text-center">
                        <p className="text-[11px] font-bold text-emerald-700">
                            ✓ Account activated on {new Date(guardian.email_verified_at).toLocaleDateString()}
                        </p>
                    </div>
                )}

                <div className="grid grid-cols-1 gap-2">
                    {/* Enable login */}
                    {(noAccount || isDisabled) && (
                        <Button
                            variant="outline"
                            className="h-11 justify-start rounded-xl border-slate-200 font-semibold text-slate-700 transition-all hover:bg-indigo-50 hover:text-indigo-600 hover:border-indigo-100"
                            disabled={!!busy}
                            onClick={() =>
                                confirm(
                                    `Enable login access for ${guardian.full_name}?`,
                                    () => perform('enable', 'enable-login'),
                                )
                            }
                        >
                            {busy === 'enable' ? (
                                <Spinner className="mr-3 h-4 w-4 animate-spin text-indigo-600" />
                            ) : (
                                <LogIn className="mr-3 h-4 w-4 text-slate-400 group-hover:text-indigo-600" />
                            )}
                            Enable Access
                        </Button>
                    )}

                    {/* Disable login */}
                    {hasLogin && (
                        <Button
                            variant="outline"
                            className="h-11 justify-start rounded-xl border-slate-200 font-semibold text-slate-700 transition-all hover:bg-amber-50 hover:text-amber-600 hover:border-amber-100"
                            disabled={!!busy}
                            onClick={() =>
                                confirm(
                                    `Disable login access for ${guardian.full_name}? They will no longer be able to sign in.`,
                                    () => perform('disable', 'disable-login'),
                                )
                            }
                        >
                            {busy === 'disable' ? (
                                <Spinner className="mr-3 h-4 w-4 animate-spin text-amber-600" />
                            ) : (
                                <ShieldOff className="mr-3 h-4 w-4 text-slate-400" />
                            )}
                            Disable Access
                        </Button>
                    )}

                    {/* Reset password */}
                    {hasRealEmail && (
                        <Button
                            variant="outline"
                            className="h-11 justify-start rounded-xl border-slate-200 font-semibold text-slate-700 transition-all hover:bg-indigo-50 hover:text-indigo-600 hover:border-indigo-100"
                            disabled={!!busy}
                            onClick={() =>
                                confirm(
                                    `Send a password reset link to ${guardian.email}?`,
                                    () => perform('reset', 'reset-password'),
                                )
                            }
                        >
                            {busy === 'reset' ? (
                                <Spinner className="mr-3 h-4 w-4 animate-spin text-indigo-600" />
                            ) : (
                                <RotateCcw className="mr-3 h-4 w-4 text-slate-400" />
                            )}
                            Reset Password
                        </Button>
                    )}

                    {/* Resend invitation */}
                    {!!guardian.never_activated && hasRealEmail && (
                        <Button
                            variant="outline"
                            className="h-11 justify-start rounded-xl border-slate-200 font-semibold text-slate-700 transition-all hover:bg-indigo-50 hover:text-indigo-600 hover:border-indigo-100"
                            disabled={!!busy}
                            onClick={() =>
                                confirm(
                                    `Resend the login invitation to ${guardian.email}?`,
                                    () => perform('resend', 'resend-invitation'),
                                )
                            }
                        >
                            {busy === 'resend' ? (
                                <Spinner className="mr-3 h-4 w-4 animate-spin text-indigo-600" />
                            ) : (
                                <RotateCcw className="mr-3 h-4 w-4 text-slate-400" />
                            )}
                            Resend Invitation
                        </Button>
                    )}
                </div>

                {noAccount && (
                    <div className="rounded-xl bg-slate-50 p-4 ring-1 ring-slate-100">
                        <p className="text-center text-xs font-medium leading-relaxed text-slate-500">
                            {hasRealEmail
                                ? "This guardian doesn't have an active login account yet."
                                : "A valid email address is required to enable login access for this guardian."}
                        </p>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
