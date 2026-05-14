import { Link } from '@inertiajs/react';
import {
    Edit,
    ExternalLink,
    LogIn,
    Mail,
    MapPin,
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

    const location = [guardian.city, guardian.country]
        .filter(Boolean)
        .join(', ');

    return (
        <div
            className={`relative rounded-xl border p-5 transition-shadow hover:shadow-md ${
                isSoftDeleted
                    ? 'border-dashed border-muted-foreground/30 bg-muted/30 opacity-60'
                    : 'bg-card'
            }`}
        >
            <div className="flex items-start gap-4">
                {/* Avatar */}
                <Avatar className="size-12 shrink-0 rounded-full">
                    <AvatarImage
                        src={guardian.photo ?? undefined}
                        alt={guardian.full_name}
                    />
                    <AvatarFallback className="rounded-full bg-neutral-200 text-sm font-semibold text-black dark:bg-neutral-700 dark:text-white">
                        {getInitials(guardian.full_name)}
                    </AvatarFallback>
                </Avatar>

                {/* Details */}
                <div className="min-w-0 flex-1 space-y-2">
                    {/* Name + badges */}
                    <div className="flex flex-wrap items-center gap-2">
                        <Link
                            href={`/guardians/${guardian.id}`}
                            className="text-sm font-semibold hover:underline"
                        >
                            {guardian.full_name}
                        </Link>
                        <Badge
                            variant="outline"
                            className="text-xs capitalize"
                        >
                            {guardian.relationship}
                        </Badge>
                        {guardian.is_primary && (
                            <Badge className="bg-emerald-100 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-900/40 dark:text-emerald-400">
                                Primary
                            </Badge>
                        )}
                        {guardian.can_login && (
                            <Badge className="bg-blue-100 text-blue-700 hover:bg-blue-100 dark:bg-blue-900/40 dark:text-blue-400">
                                Can Login
                            </Badge>
                        )}
                        {isSoftDeleted && (
                            <Badge variant="destructive">Deleted</Badge>
                        )}
                    </div>

                    {/* Contact info */}
                    <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                        {guardian.phone && (
                            <span className="inline-flex items-center gap-1">
                                <Phone className="h-3 w-3" />
                                {guardian.phone}
                            </span>
                        )}
                        {guardian.email && (
                            <span className="inline-flex items-center gap-1">
                                <Mail className="h-3 w-3" />
                                {guardian.email}
                            </span>
                        )}
                        {location && (
                            <span className="inline-flex items-center gap-1">
                                <MapPin className="h-3 w-3" />
                                {location}
                            </span>
                        )}
                    </div>

                    {/* Action buttons — hidden on mobile, shown via dropdown instead */}
                    {!isSoftDeleted && (
                        <div className="hidden gap-2 pt-1 sm:flex">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => onEditPivot(guardian)}
                                className="h-7 text-xs"
                            >
                                <Edit className="mr-1 h-3 w-3" />
                                Edit Relationship
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="text-destructive h-7 text-xs"
                                onClick={() => onDetach(guardian)}
                                disabled={isOnlyGuardian}
                                title={
                                    isOnlyGuardian
                                        ? 'Add another guardian before removing this one'
                                        : undefined
                                }
                            >
                                <UserMinus className="mr-1 h-3 w-3" />
                                Remove
                            </Button>
                        </div>
                    )}
                </div>

                {/* Dropdown actions (always visible, primary action entry on mobile) */}
                {!isSoftDeleted && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="h-8 w-8 shrink-0"
                                aria-label={`Actions for ${guardian.full_name}`}
                            >
                                <MoreHorizontal className="h-4 w-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem asChild>
                                <Link href={`/guardians/${guardian.id}`}>
                                    <ExternalLink className="mr-2 h-4 w-4" />
                                    View Full Details
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                onClick={() => onEditPivot(guardian)}
                            >
                                <Edit className="mr-2 h-4 w-4" />
                                Edit Relationship
                            </DropdownMenuItem>
                            {!guardian.can_login && onEnableLogin && (
                                <DropdownMenuItem
                                    onClick={() => onEnableLogin(guardian)}
                                >
                                    <LogIn className="mr-2 h-4 w-4" />
                                    Enable Login
                                </DropdownMenuItem>
                            )}
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                variant="destructive"
                                onClick={() => onDetach(guardian)}
                                disabled={isOnlyGuardian}
                            >
                                <UserMinus className="mr-2 h-4 w-4" />
                                Remove from Student
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </div>
        </div>
    );
}
