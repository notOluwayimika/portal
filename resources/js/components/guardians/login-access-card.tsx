import axios from 'axios';
import { KeyRound, LogIn, Mail, RotateCcw, ShieldOff } from 'lucide-react';
import { useState } from 'react';
import { useApiSweetAlertConfirmation } from '@/hooks/use-sweetalert-confirmation';
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
    const { confirmAndExecute } = useApiSweetAlertConfirmation();

    const hasRealEmail = !!guardian.email && !isSyntheticEmail(guardian.email);

    const hasLogin    = !!guardian.has_login;
    const isDisabled  = !guardian.has_login && !!guardian.user_disabled_at;
    const noAccount   = !guardian.has_login && !guardian.user_disabled_at;

    const swalAction = async (
        key: ActionKey,
        endpoint: string,
        title: string,
        text: string,
        confirmText = 'Yes, proceed',
    ) => {
        await confirmAndExecute({
            sweetAlertTitle:   title,
            sweetAlertText:    text,
            sweetAlertIcon:    'warning',
            confirmButtonText: confirmText,
            showSuccessAlert:  false,
            showErrorAlert:    false,
            onConfirm: async () => {
                setBusy(key);
                try {
                    const res = await axios.post(`/api/guardians/${guardian.id}/${endpoint}`);
                    // enable/disable-login return a flat JsonResource (no data wrapper)
                    const updated: Guardian = res.data?.data ?? res.data;
                    if (updated) onUpdate(updated);
                } catch (err: unknown) {
                    const msg =
                        (err as { response?: { data?: { message?: string } } })?.response?.data?.message ??
                        'Action failed. Please try again.';
                    onError(msg);
                } finally {
                    setBusy(null);
                }
            },
        });
    };

    const loginStatusBadge = () => {
        if (hasLogin) {
            return (
                <Badge className="rounded-full bg-emerald-50 px-2.5 py-0.5 text-[10px] font-bold text-emerald-600 shadow-sm hover:bg-emerald-50 dark:bg-emerald-500/10 dark:text-emerald-400">
                    <LogIn className="mr-1 h-3 w-3" />
                    Login Enabled
                </Badge>
            );
        }
        if (isDisabled) {
            return (
                <Badge className="rounded-full bg-amber-50 px-2.5 py-0.5 text-[10px] font-bold text-amber-600 shadow-sm hover:bg-amber-50 dark:bg-amber-500/10 dark:text-amber-400">
                    <ShieldOff className="mr-1 h-3 w-3" />
                    Login Disabled
                </Badge>
            );
        }
        return (
            <Badge variant="secondary" className="rounded-full px-2.5 py-0.5 text-[10px] font-bold text-slate-500 shadow-sm">
                No Account
            </Badge>
        );
    };

    return (
        <Card className="overflow-hidden rounded-xl border-none shadow-[0_8px_30px_rgb(0,0,0,0.04)]">
            <CardHeader className="border-b border-slate-50 bg-slate-50/30 px-5 py-3">
                <CardTitle className="flex items-center gap-2.5 text-sm font-bold text-slate-800 dark:text-slate-100">
                    <div className="flex size-7 items-center justify-center rounded-lg bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700">
                        <KeyRound className="h-4 w-4 text-primary" />
                    </div>
                    Login Access
                </CardTitle>
            </CardHeader>
            <CardContent className="p-4 space-y-4">
                <div className="flex flex-col gap-3">
                    <div className="flex items-center justify-between">
                        <span className="text-[10px] font-bold tracking-wide text-slate-400 uppercase">Status</span>
                        {loginStatusBadge()}
                    </div>

                    {guardian.email && !isSyntheticEmail(guardian.email) && (
                        <div className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 ring-1 ring-slate-100 dark:bg-slate-800 dark:ring-slate-700">
                            <div className="flex items-center gap-2 overflow-hidden">
                                <Mail className="h-3.5 w-3.5 shrink-0 text-slate-400" />
                                <span className="truncate text-xs font-semibold text-slate-600 dark:text-slate-300">{guardian.email}</span>
                            </div>
                        </div>
                    )}
                </div>

                {guardian.email_verified_at && (
                    <div className="rounded-lg border border-emerald-100 bg-emerald-50/30 px-3 py-2 text-center dark:border-emerald-900/40 dark:bg-emerald-950/20">
                        <p className="text-[10px] font-bold text-emerald-700 dark:text-emerald-400">
                            ✓ Account activated on {new Date(guardian.email_verified_at).toLocaleDateString()}
                        </p>
                    </div>
                )}

                <div className="grid grid-cols-1 gap-2">
                    {/* Enable login */}
                    {(noAccount || isDisabled) && (
                        <Button
                            size="sm"
                            variant="outline"
                            className="justify-start rounded-lg border-slate-200 text-xs font-semibold text-slate-700 transition-all hover:bg-primary/10 hover:text-primary hover:border-primary/20 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-primary/10 dark:hover:text-primary dark:hover:border-primary/30"
                            disabled={!!busy}
                            onClick={() => swalAction(
                                'enable',
                                'enable-login',
                                'Enable Login Access',
                                `${guardian.full_name} will receive their credentials and be able to sign in.`,
                                'Yes, enable',
                            )}
                        >
                            {busy === 'enable' ? (
                                <Spinner className="mr-2 h-3.5 w-3.5 animate-spin text-primary" />
                            ) : (
                                <LogIn className="mr-2 h-3.5 w-3.5 text-slate-400 group-hover:text-primary" />
                            )}
                            Enable Access
                        </Button>
                    )}

                    {/* Disable login */}
                    {hasLogin && (
                        <Button
                            size="sm"
                            variant="outline"
                            className="justify-start rounded-lg border-slate-200 text-xs font-semibold text-slate-700 transition-all hover:bg-amber-50 hover:text-amber-600 hover:border-amber-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-amber-950/40 dark:hover:text-amber-400 dark:hover:border-amber-900/60"
                            disabled={!!busy}
                            onClick={() => swalAction(
                                'disable',
                                'disable-login',
                                'Disable Login Access',
                                `${guardian.full_name} will no longer be able to sign in.`,
                                'Yes, disable',
                            )}
                        >
                            {busy === 'disable' ? (
                                <Spinner className="mr-2 h-3.5 w-3.5 animate-spin text-amber-600" />
                            ) : (
                                <ShieldOff className="mr-2 h-3.5 w-3.5 text-slate-400" />
                            )}
                            Disable Access
                        </Button>
                    )}

                    {/* Reset password */}
                    {hasRealEmail && (
                        <Button
                            size="sm"
                            variant="outline"
                            className="justify-start rounded-lg border-slate-200 text-xs font-semibold text-slate-700 transition-all hover:bg-primary/10 hover:text-primary hover:border-primary/20 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-primary/10 dark:hover:text-primary dark:hover:border-primary/30"
                            disabled={!!busy}
                            onClick={() => swalAction(
                                'reset',
                                'reset-password',
                                'Reset Password',
                                `A password reset link will be sent to ${guardian.email}.`,
                                'Yes, send link',
                            )}
                        >
                            {busy === 'reset' ? (
                                <Spinner className="mr-2 h-3.5 w-3.5 animate-spin text-primary" />
                            ) : (
                                <RotateCcw className="mr-2 h-3.5 w-3.5 text-slate-400" />
                            )}
                            Reset Password
                        </Button>
                    )}

                    {/* Resend invitation */}
                    {!!guardian.never_activated && hasRealEmail && (
                        <Button
                            size="sm"
                            variant="outline"
                            className="justify-start rounded-lg border-slate-200 text-xs font-semibold text-slate-700 transition-all hover:bg-primary/10 hover:text-primary hover:border-primary/20 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-primary/10 dark:hover:text-primary dark:hover:border-primary/30"
                            disabled={!!busy}
                            onClick={() => swalAction(
                                'resend',
                                'resend-invitation',
                                'Resend Invitation',
                                `A new invitation will be sent to ${guardian.email}.`,
                                'Yes, resend',
                            )}
                        >
                            {busy === 'resend' ? (
                                <Spinner className="mr-2 h-3.5 w-3.5 animate-spin text-primary" />
                            ) : (
                                <RotateCcw className="mr-2 h-3.5 w-3.5 text-slate-400" />
                            )}
                            Resend Invitation
                        </Button>
                    )}
                </div>

                {noAccount && (
                    <div className="rounded-lg bg-slate-50 px-3 py-2 ring-1 ring-slate-100 dark:bg-slate-800 dark:ring-slate-700">
                        <p className="text-center text-[11px] font-medium leading-relaxed text-slate-500 dark:text-slate-400">
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
