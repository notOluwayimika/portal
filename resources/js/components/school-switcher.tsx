import { router, usePage } from '@inertiajs/react';
import { Building2, Check, ChevronsUpDown } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { Auth } from '@/types';

/**
 * Shows the currently active school and, when the user can access more
 * than one school, lets them switch. The switch is a full server-side
 * context change (session school_id), so all scoped data reloads.
 */
export function SchoolSwitcher() {
    const { auth } = usePage<{ auth: Auth }>().props;

    const schools = auth.schools ?? [];
    const current = auth.school;
    const canSwitch = schools.length > 1 || (auth.isSuperAdmin && schools.length > 0);

    if (!current && !auth.isSuperAdmin) return null;

    if (!canSwitch) {
        return (
            <div className="flex items-center gap-2 rounded-md border border-sidebar-border/60 px-3 py-1.5 text-sm font-medium">
                <Building2 className="h-4 w-4 text-muted-foreground" />
                <span className="max-w-40 truncate">{current?.name}</span>
            </div>
        );
    }

    const switchTo = (uuid: string) => {
        router.post('/select-school', { school: uuid });
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger className="flex items-center gap-2 rounded-md border border-sidebar-border/60 px-3 py-1.5 text-sm font-medium transition hover:bg-accent">
                <Building2 className="h-4 w-4 text-muted-foreground" />
                <span className="max-w-40 truncate">
                    {current?.name ?? 'All schools'}
                </span>
                <ChevronsUpDown className="h-3.5 w-3.5 text-muted-foreground" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-64">
                <DropdownMenuLabel>Switch school</DropdownMenuLabel>
                <DropdownMenuSeparator />
                {schools.map((school) => {
                    const isCurrent = current?.uuid === school.uuid;
                    return (
                        <DropdownMenuItem
                            key={school.uuid}
                            disabled={isCurrent}
                            onSelect={() => switchTo(school.uuid)}
                            className="flex items-center justify-between"
                        >
                            <span className="truncate">{school.name}</span>
                            {isCurrent && <Check className="h-4 w-4" />}
                        </DropdownMenuItem>
                    );
                })}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
