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
        <div className="group relative flex items-center gap-5 rounded-2xl border border-slate-100 bg-white p-5 transition-all hover:border-indigo-100 hover:shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:border-white/5 dark:bg-card">
            <div className="relative">
                <Avatar className="size-14 shrink-0 rounded-full border-2 border-white shadow-sm ring-1 ring-slate-100">
                    <AvatarImage src={student.photo ?? undefined} alt={student.full_name} className="object-cover" />
                    <AvatarFallback className="rounded-full bg-slate-50 text-base font-bold text-slate-400">
                        {getInitials(student.full_name)}
                    </AvatarFallback>
                </Avatar>
                {student.pivot.is_primary && (
                    <div className="absolute -right-1 -top-1 flex size-6 items-center justify-center rounded-full bg-indigo-600 text-white shadow-sm ring-2 ring-white">
                        <span className="text-[10px] font-bold">P</span>
                    </div>
                )}
            </div>

            <div className="min-w-0 flex-1 space-y-2">
                <div className="flex flex-wrap items-center gap-3">
                    <span className="text-[15px] font-bold tracking-tight text-slate-800 dark:text-white">
                        {student.full_name}
                    </span>
                    {student.pivot.is_primary && (
                        <Badge className="rounded-full bg-indigo-50 px-2.5 py-0.5 text-[10px] font-bold text-indigo-600 shadow-sm hover:bg-indigo-50 dark:bg-indigo-500/10 dark:text-indigo-400">
                            Primary Guardian
                        </Badge>
                    )}
                </div>

                <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs font-semibold text-slate-400">
                    <span className="inline-flex items-center gap-1.5 text-slate-500">
                        <GraduationCap className="h-3.5 w-3.5 text-slate-300" />
                        {classLabel(student)}
                    </span>
                    {student.admission_number && (
                        <span className="text-slate-400">Adm: {student.admission_number}</span>
                    )}
                    <Badge variant="outline" className="rounded-md border-slate-200 px-2 py-0 text-[10px] font-bold capitalize text-slate-500">
                        {student.pivot.relationship}
                    </Badge>
                    {student.status && (
                        <Badge className={`rounded-full px-2 py-0 text-[10px] font-bold capitalize shadow-none ${student.status === 'active' ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-50 text-slate-500'}`}>
                            {student.status}
                        </Badge>
                    )}
                </div>
            </div>

            <Button asChild variant="ghost" size="sm" className="shrink-0 rounded-xl px-3 text-slate-400 transition-all hover:bg-indigo-50 hover:text-indigo-600">
                <Link href={`/students/${student.id}`}>
                    <span className="mr-1.5 text-xs font-bold">View</span>
                    <ArrowRight className="h-4 w-4" />
                </Link>
            </Button>
        </div>
    );
}
