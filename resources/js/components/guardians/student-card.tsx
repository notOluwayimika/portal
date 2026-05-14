import { Link } from '@inertiajs/react';
import { ArrowRight, GraduationCap } from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useInitials } from '@/hooks/use-initials';
import type { Guardian, GuardianPivot, Student } from '@/types/models';

interface StudentCardProps {
    student: Student & { pivot: GuardianPivot };
}

function classLabel(student: Student): string {
    return student.class_details?.full_class ?? student.class_details?.level ?? '—';
}

export function StudentCard({ student }: StudentCardProps) {
    const getInitials = useInitials();

    return (
        <div className="flex items-center gap-4 rounded-xl border bg-card p-4 transition-shadow hover:shadow-sm">
            <Avatar className="size-11 shrink-0 rounded-full">
                <AvatarImage src={student.photo ?? undefined} alt={student.full_name} />
                <AvatarFallback className="rounded-full bg-neutral-200 text-sm font-semibold text-black dark:bg-neutral-700 dark:text-white">
                    {getInitials(student.full_name)}
                </AvatarFallback>
            </Avatar>

            <div className="min-w-0 flex-1 space-y-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm font-semibold">{student.full_name}</span>
                    {student.pivot.is_primary && (
                        <Badge className="bg-emerald-100 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-900/40 dark:text-emerald-400">
                            Primary
                        </Badge>
                    )}
                </div>

                <div className="flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
                    <span className="inline-flex items-center gap-1">
                        <GraduationCap className="h-3 w-3" />
                        {classLabel(student)}
                    </span>
                    {student.admission_number && (
                        <span>Adm: {student.admission_number}</span>
                    )}
                    <span className="capitalize">{student.pivot.relationship}</span>
                    <span className="capitalize">{student.status}</span>
                </div>
            </div>

            <Button asChild variant="ghost" size="sm" className="shrink-0">
                <Link href={`/students/${student.id}`}>
                    View
                    <ArrowRight className="ml-1 h-3.5 w-3.5" />
                </Link>
            </Button>
        </div>
    );
}
