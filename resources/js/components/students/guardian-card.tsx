import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    Edit,
    ExternalLink,
    LogIn,
    Mail,
    MoreHorizontal,
    Phone,
    UserMinus,
} from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useInitials } from '@/hooks/use-initials';
import type { Guardian } from '@/types/models';

interface GuardianCardProps {
    guardian: Guardian;
    studentUuid: string;
    isOnlyGuardian: boolean;
    onEditPivot: (guardian: Guardian) => void;
    onDetach: (guardian: Guardian) => void;
    onEnableLogin?: (guardian: Guardian) => void;
}

export function GuardianCard({
    guardian,
    isOnlyGuardian,
    onEditPivot,
    onDetach,
    onEnableLogin,
}: GuardianCardProps) {
    const getInitials = useInitials();
    const isSoftDeleted = !!guardian.deleted_at;

    return (
        <div className={`group relative flex items-center gap-5 rounded-2xl border border-slate-100 bg-white p-5 transition-all hover:border-primary/20 hover:shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:border-white/5 dark:bg-card dark:hover:border-primary/30 ${isSoftDeleted ? 'opacity-60' : ''}`}>
            <div className="relative">
                <Avatar className="size-14 shrink-0 rounded-full border-2 border-white shadow-sm ring-1 ring-slate-100 dark:ring-slate-700">
                    <AvatarImage src={guardian.photo ?? undefined} alt={guardian.full_name} className="object-cover" />
                    <AvatarFallback className="rounded-full bg-slate-50 text-base font-bold text-slate-400 dark:bg-slate-800 dark:text-slate-400">
                        {getInitials(guardian.full_name)}
                    </AvatarFallback>
                </Avatar>
                {guardian.is_primary && (
                    <div className="absolute -right-1 -top-1 flex size-6 items-center justify-center rounded-full bg-primary text-white shadow-sm ring-2 ring-white">
                        <span className="text-[10px] font-bold">P</span>
                    </div>
                )}
            </div>

            <div className="min-w-0 flex-1 space-y-2">
                <div className="flex flex-wrap items-center gap-3">
                    <Link
                        href={`/guardians/${guardian.id}`}
                        className="text-[15px] font-bold tracking-tight text-slate-800 hover:text-primary dark:text-white dark:hover:text-primary"
                    >
                        {guardian.full_name}
                    </Link>
                    <div className="flex gap-2">
                        <Badge className="rounded-full bg-slate-50 px-2.5 py-0.5 text-[10px] font-bold text-slate-500 shadow-sm hover:bg-slate-50 dark:bg-slate-800 dark:text-slate-400">
                            {guardian.relationship}
                        </Badge>
                        {guardian.can_login && (
                            <Badge className="rounded-full bg-emerald-50 px-2.5 py-0.5 text-[10px] font-bold text-emerald-600 shadow-sm hover:bg-emerald-50 dark:bg-emerald-500/10 dark:text-emerald-400">
                                Can Login
                            </Badge>
                        )}
                        {isSoftDeleted && <Badge variant="destructive" className="rounded-full px-2.5 py-0.5 text-[10px] font-bold">Deleted</Badge>}
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs font-semibold text-slate-400">
                    {guardian.phone && (
                        <span className="inline-flex items-center gap-1.5 text-slate-500">
                            <Phone className="h-3.5 w-3.5 text-slate-300" />
                            {guardian.phone}
                        </span>
                    )}
                    {guardian.email && (
                        <span className="inline-flex items-center gap-1.5 text-slate-500">
                            <Mail className="h-3.5 w-3.5 text-slate-300" />
                            {guardian.email}
                        </span>
                    )}
                </div>
            </div>

            <div className="flex items-center gap-1">
                {!isSoftDeleted && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon" className="h-9 w-9 rounded-xl text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800">
                                <MoreHorizontal className="h-4 w-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-56 rounded-xl p-1 shadow-xl">
                            <DropdownMenuItem asChild className="rounded-lg py-2 cursor-pointer">
                                <Link href={`/guardians/${guardian.id}`}>
                                    <ExternalLink className="mr-2 h-4 w-4 text-slate-500" />
                                    View Full Details
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem onClick={() => onEditPivot(guardian)} className="rounded-lg py-2 cursor-pointer">
                                <Edit className="mr-2 h-4 w-4 text-slate-500" />
                                Edit Relationship
                            </DropdownMenuItem>
                            {!guardian.can_login && onEnableLogin && (
                                <DropdownMenuItem onClick={() => onEnableLogin(guardian)} className="rounded-lg py-2 cursor-pointer">
                                    <LogIn className="mr-2 h-4 w-4 text-slate-500" />
                                    Enable Login
                                </DropdownMenuItem>
                            )}
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                onClick={() => onDetach(guardian)}
                                disabled={isOnlyGuardian}
                                className="rounded-lg py-2 cursor-pointer text-red-600 focus:text-red-600"
                            >
                                <UserMinus className="mr-2 h-4 w-4" />
                                Remove from Student
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}

                <Button asChild variant="ghost" size="sm" className="shrink-0 rounded-xl px-3 text-slate-400 transition-all hover:bg-primary/10 hover:text-primary dark:hover:bg-primary/10 dark:hover:text-primary">
                    <Link href={`/guardians/${guardian.id}`}>
                        <span className="mr-1.5 text-xs font-bold">Details</span>
                        <ArrowRight className="h-4 w-4" />
                    </Link>
                </Button>
            </div>
        </div>
    );
}
