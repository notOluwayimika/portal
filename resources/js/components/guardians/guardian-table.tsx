import { Link } from '@inertiajs/react';
import { ArrowUpDown, ChevronDown, ChevronUp, MoreHorizontal } from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import { useInitials } from '@/hooks/use-initials';
import type { Guardian } from '@/types/models';

type SortCol = 'name' | 'phone' | 'students_count' | 'created_at';

interface GuardianTableProps {
    guardians: Guardian[];
    loading: boolean;
    selectedIds: Set<string>;
    onToggleSelect: (id: string) => void;
    onToggleAll: (checked: boolean) => void;
    isAllSelected: boolean;
    sortBy: SortCol;
    sortDir: 'asc' | 'desc';
    onSort: (col: SortCol) => void;
    onSingleAction: (action: string, guardian: Guardian) => void;
}

function loginBadge(guardian: Guardian) {
    const base = 'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold';
    if (guardian.has_login) {
        return <span className={`${base} bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400`}>Enabled</span>;
    }
    if (guardian.user_disabled_at) {
        return <span className={`${base} bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400`}>Disabled</span>;
    }
    return <span className={`${base} bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400`}>No Account</span>;
}

function statusBadge(status?: string) {
    const cls =
        status === 'active'   ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' :
        status === 'inactive' ? 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400' :
        status === 'blocked'  ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' :
                                'bg-gray-100 text-gray-500';
    return <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold capitalize ${cls}`}>{status ?? '—'}</span>;
}

export function GuardianTable({
    guardians, loading, selectedIds, onToggleSelect, onToggleAll,
    isAllSelected, sortBy, sortDir, onSort, onSingleAction,
}: GuardianTableProps) {
    const getInitials = useInitials();

    const SortBtn = ({ col, label, hidden = false }: { col: SortCol; label: string; hidden?: boolean }) => {
        const active = col === sortBy;
        const Icon = active ? (sortDir === 'asc' ? ChevronUp : ChevronDown) : ArrowUpDown;
        return (
            <th
                className={`px-3 py-2 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase${hidden ? ' hidden sm:table-cell' : ''}`}
            >
                <button
                    type="button"
                    onClick={() => onSort(col)}
                    className="flex items-center gap-1 whitespace-nowrap select-none hover:text-slate-600"
                >
                    {label}
                    <Icon className={`h-3 w-3 shrink-0${active ? '' : ' opacity-40'}`} />
                </button>
            </th>
        );
    };

    return (
        <div className="overflow-x-auto custom-scrollbar">
            <table className="w-full text-xs">
                <thead>
                    <tr className="border-b border-slate-100 bg-slate-50/50 dark:border-slate-800 dark:bg-slate-900/30">
                        <th className="px-3 py-2 text-left">
                            <Checkbox
                                checked={isAllSelected}
                                onCheckedChange={(c) => onToggleAll(!!c)}
                            />
                        </th>
                        <SortBtn col="name" label="Name" />
                        <SortBtn col="phone" label="Phone" />
                        <th className="hidden px-3 py-2 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase sm:table-cell whitespace-nowrap">Email</th>
                        <SortBtn col="students_count" label="Children" />
                        <th className="px-3 py-2 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase whitespace-nowrap">Login</th>
                        <th className="px-3 py-2 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase whitespace-nowrap">Status</th>
                        <SortBtn col="created_at" label="Created" hidden />
                        <th className="px-3 py-2 text-right text-[10px] font-bold tracking-wide text-slate-400 uppercase whitespace-nowrap">Actions</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                    {loading ? (
                        [...Array(5)].map((_, i) => (
                            <tr key={i}>
                                <td className="px-3 py-2.5"><Skeleton className="h-4 w-4" /></td>
                                <td className="px-3 py-2.5"><div className="flex items-center gap-2.5"><Skeleton className="h-7 w-7 rounded-full" /><Skeleton className="h-3 w-32" /></div></td>
                                <td className="px-3 py-2.5"><Skeleton className="h-3 w-24" /></td>
                                <td className="hidden px-3 py-2.5 sm:table-cell"><Skeleton className="h-3 w-40" /></td>
                                <td className="px-3 py-2.5"><Skeleton className="h-3 w-8" /></td>
                                <td className="px-3 py-2.5"><Skeleton className="h-4 w-16 rounded-full" /></td>
                                <td className="px-3 py-2.5"><Skeleton className="h-4 w-14 rounded-full" /></td>
                                <td className="hidden px-3 py-2.5 sm:table-cell"><Skeleton className="h-3 w-20" /></td>
                                <td className="px-3 py-2.5"><Skeleton className="h-4 w-8 ml-auto" /></td>
                            </tr>
                        ))
                    ) : guardians.length === 0 ? (
                        <tr>
                            <td colSpan={9} className="py-10 text-center text-xs text-muted-foreground">
                                No guardians found.
                            </td>
                        </tr>
                    ) : guardians.map((g) => (
                        <tr key={g.id} className="transition-colors hover:bg-slate-50/60 dark:hover:bg-slate-900/30">
                            <td className="px-3 py-2.5">
                                <Checkbox
                                    checked={selectedIds.has(g.id)}
                                    onCheckedChange={() => onToggleSelect(g.id)}
                                />
                            </td>
                            <td className="px-3 py-2.5 font-semibold text-slate-700 dark:text-slate-200">
                                <div className="flex items-center gap-2.5">
                                    <Avatar className="size-7 shrink-0 overflow-hidden rounded-full">
                                        <AvatarImage src={g.photo ?? undefined} alt={g.full_name} />
                                        <AvatarFallback className="rounded-full bg-neutral-200 text-[10px] text-black dark:bg-neutral-700 dark:text-white">
                                            {getInitials(g.full_name)}
                                        </AvatarFallback>
                                    </Avatar>
                                    <Link href={`/guardians/${g.id}`} className="hover:underline transition-colors hover:text-primary">
                                        {g.full_name}
                                    </Link>
                                </div>
                            </td>
                            <td className="px-3 py-2.5 text-muted-foreground">{g.phone ?? '—'}</td>
                            <td className="hidden px-3 py-2.5 text-muted-foreground sm:table-cell">
                                {g.email && !g.email.endsWith('@no-email.local') ? g.email : '—'}
                            </td>
                            <td className="px-3 py-2.5 text-center text-slate-600 dark:text-slate-300">{g.students_count ?? 0}</td>
                            <td className="px-3 py-2.5">{loginBadge(g)}</td>
                            <td className="px-3 py-2.5">{statusBadge(g.status)}</td>
                            <td className="hidden px-3 py-2.5 text-muted-foreground sm:table-cell">
                                {g.created_at ? new Date(g.created_at as string).toLocaleDateString() : '—'}
                            </td>
                            <td className="px-3 py-2.5 text-right">
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button variant="ghost" size="icon" className="h-7 w-7">
                                            <MoreHorizontal className="h-3.5 w-3.5" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem asChild>
                                            <Link href={`/guardians/${g.id}`}>View Profile</Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator />
                                        {!g.has_login && (
                                            <DropdownMenuItem onClick={() => onSingleAction('enable-login', g)}>
                                                Enable Login
                                            </DropdownMenuItem>
                                        )}
                                        {g.has_login && (
                                            <DropdownMenuItem onClick={() => onSingleAction('disable-login', g)}>
                                                Disable Login
                                            </DropdownMenuItem>
                                        )}
                                        <DropdownMenuItem onClick={() => onSingleAction('status-inactive', g)}>
                                            Set Inactive
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
