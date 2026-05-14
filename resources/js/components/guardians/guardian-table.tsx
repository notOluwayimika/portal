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
    if (!guardian.has_login && guardian.user_disabled_at === undefined && !guardian.email) {
        return <span className="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-400">No Account</span>;
    }
    if (guardian.has_login) {
        return <span className="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-700 dark:bg-green-900/30 dark:text-green-400">Enabled</span>;
    }
    if (guardian.user_disabled_at) {
        return <span className="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">Disabled</span>;
    }
    return <span className="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-400">No Account</span>;
}

function statusBadge(status?: string) {
    const cls =
        status === 'active'   ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' :
        status === 'inactive' ? 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400' :
        status === 'blocked'  ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' :
                                'bg-gray-100 text-gray-500';
    return <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs capitalize ${cls}`}>{status ?? '—'}</span>;
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
                className={`px-4 py-3 text-left text-sm font-medium${hidden ? ' hidden sm:table-cell' : ''}`}
            >
                <button
                    type="button"
                    onClick={() => onSort(col)}
                    className="flex items-center gap-1 whitespace-nowrap select-none hover:text-foreground"
                >
                    {label}
                    <Icon className={`h-3 w-3 shrink-0${active ? '' : ' opacity-40'}`} />
                </button>
            </th>
        );
    };

    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b bg-muted/50">
                        <th className="px-4 py-3 text-left">
                            <Checkbox
                                checked={isAllSelected}
                                onCheckedChange={(c) => onToggleAll(!!c)}
                            />
                        </th>
                        <SortBtn col="name" label="Name" />
                        <SortBtn col="phone" label="Phone" />
                        <th className="hidden px-4 py-3 text-left text-sm font-medium sm:table-cell whitespace-nowrap">Email</th>
                        <SortBtn col="students_count" label="Children" />
                        <th className="px-4 py-3 text-left text-sm font-medium whitespace-nowrap">Login</th>
                        <th className="px-4 py-3 text-left text-sm font-medium whitespace-nowrap">Status</th>
                        <SortBtn col="created_at" label="Created" hidden />
                        <th className="px-4 py-3 text-right text-sm font-medium whitespace-nowrap">Actions</th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {loading ? (
                        [...Array(5)].map((_, i) => (
                            <tr key={i}>
                                <td className="px-4 py-3"><Skeleton className="h-4 w-4" /></td>
                                <td className="px-4 py-3"><div className="flex items-center gap-3"><Skeleton className="h-8 w-8 rounded-full" /><Skeleton className="h-4 w-32" /></div></td>
                                <td className="px-4 py-3"><Skeleton className="h-4 w-24" /></td>
                                <td className="hidden px-4 py-3 sm:table-cell"><Skeleton className="h-4 w-40" /></td>
                                <td className="px-4 py-3"><Skeleton className="h-4 w-8" /></td>
                                <td className="px-4 py-3"><Skeleton className="h-5 w-16 rounded-full" /></td>
                                <td className="px-4 py-3"><Skeleton className="h-5 w-14 rounded-full" /></td>
                                <td className="hidden px-4 py-3 sm:table-cell"><Skeleton className="h-4 w-20" /></td>
                                <td className="px-4 py-3"><Skeleton className="h-4 w-8 ml-auto" /></td>
                            </tr>
                        ))
                    ) : guardians.length === 0 ? (
                        <tr>
                            <td colSpan={9} className="py-12 text-center text-muted-foreground">
                                No guardians found.
                            </td>
                        </tr>
                    ) : guardians.map((g) => (
                        <tr key={g.id} className="transition-colors hover:bg-muted/30">
                            <td className="px-4 py-3">
                                <Checkbox
                                    checked={selectedIds.has(g.id)}
                                    onCheckedChange={() => onToggleSelect(g.id)}
                                />
                            </td>
                            <td className="px-4 py-3 font-medium">
                                <div className="flex items-center gap-3">
                                    <Avatar className="size-8 shrink-0 overflow-hidden rounded-full">
                                        <AvatarImage src={g.photo ?? undefined} alt={g.full_name} />
                                        <AvatarFallback className="rounded-lg bg-neutral-200 text-xs text-black dark:bg-neutral-700 dark:text-white">
                                            {getInitials(g.full_name)}
                                        </AvatarFallback>
                                    </Avatar>
                                    <Link href={`/guardians/${g.id}`} className="hover:underline transition-colors hover:text-primary">
                                        {g.full_name}
                                    </Link>
                                </div>
                            </td>
                            <td className="px-4 py-3 text-muted-foreground">{g.phone ?? '—'}</td>
                            <td className="hidden px-4 py-3 text-muted-foreground sm:table-cell">
                                {g.email && !g.email.endsWith('@no-email.local') ? g.email : '—'}
                            </td>
                            <td className="px-4 py-3 text-center">{g.students_count ?? 0}</td>
                            <td className="px-4 py-3">{loginBadge(g)}</td>
                            <td className="px-4 py-3">{statusBadge(g.status)}</td>
                            <td className="hidden px-4 py-3 text-muted-foreground sm:table-cell">
                                {g.created_at ? new Date(g.created_at as string).toLocaleDateString() : '—'}
                            </td>
                            <td className="px-4 py-3 text-right">
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button variant="ghost" size="icon">
                                            <MoreHorizontal className="h-4 w-4" />
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
