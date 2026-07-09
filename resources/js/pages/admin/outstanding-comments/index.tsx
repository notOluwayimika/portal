import axios from 'axios';
import {
    AlertTriangle,
    CheckCircle2,
    ClipboardList,
    Heart,
    MessageSquare,
    Shield,
    Users,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { TermFilterSelect } from '@/components/term-filter-select';
import { Skeleton } from '@/components/ui/skeleton';

interface TeacherSummary {
    id: string;
    first_name: string;
    last_name: string;
    staff_number?: string;
    photo?: string;
}

interface AssignmentRow {
    teacher: TeacherSummary;
    class_name: string;
    gender?: string;
    total: number;
    completed: number;
    outstanding: number;
}

interface OutstandingData {
    form_teachers: AssignmentRow[];
    boarding_parents: AssignmentRow[];
    head_of_schools: AssignmentRow[];
    term: string | null;
}

function ProgressBar({ completed, total }: { completed: number; total: number }) {
    const pct = total > 0 ? Math.round((completed / total) * 100) : 0;
    const color =
        pct === 100
            ? 'bg-emerald-500'
            : pct >= 50
              ? 'bg-amber-500'
              : 'bg-red-500';

    return (
        <div className="flex items-center gap-2">
            <div className="h-2 w-24 overflow-hidden rounded-full bg-gray-100">
                <div
                    className={`h-full rounded-full transition-all ${color}`}
                    style={{ width: `${pct}%` }}
                />
            </div>
            <span className="text-xs tabular-nums text-gray-500">
                {completed}/{total}
            </span>
        </div>
    );
}

function Avatar({ teacher }: { teacher: TeacherSummary }) {
    const initials =
        `${teacher.first_name[0]}${teacher.last_name[0]}`.toUpperCase();

    return teacher.photo ? (
        <img
            src={teacher.photo}
            alt={`${teacher.first_name} ${teacher.last_name}`}
            className="h-9 w-9 rounded-full object-cover ring-2 ring-white"
        />
    ) : (
        <span className="flex h-9 w-9 items-center justify-center rounded-full bg-indigo-100 text-sm font-semibold text-indigo-700 ring-2 ring-white select-none">
            {initials}
        </span>
    );
}

function StatCard({
    label,
    value,
    icon: Icon,
    color,
}: {
    label: string;
    value: number;
    icon: React.ElementType;
    color: string;
}) {
    return (
        <div className="flex items-center gap-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <div className={`flex h-10 w-10 items-center justify-center rounded-lg ${color}`}>
                <Icon className="h-5 w-5 text-white" />
            </div>
            <div>
                <p className="text-2xl font-bold text-gray-900">{value}</p>
                <p className="text-xs text-gray-500">{label}</p>
            </div>
        </div>
    );
}

function SectionTable({
    title,
    icon: Icon,
    rows,
    showGender,
}: {
    title: string;
    icon: React.ElementType;
    rows: AssignmentRow[];
    showGender?: boolean;
}) {
    const totalOutstanding = rows.reduce((sum, r) => sum + r.outstanding, 0);

    return (
        <div className="rounded-2xl border border-gray-200 bg-white shadow-sm">
            <div className="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                <div className="flex items-center gap-3">
                    <Icon className="h-5 w-5 text-gray-500" />
                    <div>
                        <h2 className="text-base font-semibold text-gray-900">
                            {title}
                        </h2>
                        <p className="mt-0.5 text-xs text-gray-500">
                            {rows.length} assignment{rows.length !== 1 ? 's' : ''}
                        </p>
                    </div>
                </div>
                {totalOutstanding > 0 ? (
                    <span className="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-3 py-1 text-xs font-medium text-red-700">
                        <AlertTriangle className="h-3.5 w-3.5" />
                        {totalOutstanding} outstanding
                    </span>
                ) : (
                    <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700">
                        <CheckCircle2 className="h-3.5 w-3.5" />
                        All complete
                    </span>
                )}
            </div>

            {rows.length === 0 ? (
                <div className="flex flex-col items-center justify-center py-12 text-center">
                    <p className="text-sm font-medium text-gray-700">
                        No assignments found
                    </p>
                    <p className="mt-1 text-xs text-gray-400">
                        No teachers have been assigned this role for the active term
                    </p>
                </div>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-gray-100 bg-gray-50/50">
                                <th className="px-6 py-3 text-left text-xs font-medium tracking-wide text-gray-500 uppercase">
                                    Teacher
                                </th>
                                <th className="px-6 py-3 text-left text-xs font-medium tracking-wide text-gray-500 uppercase">
                                    Class
                                </th>
                                {showGender && (
                                    <th className="px-6 py-3 text-left text-xs font-medium tracking-wide text-gray-500 uppercase">
                                        Gender
                                    </th>
                                )}
                                <th className="px-6 py-3 text-left text-xs font-medium tracking-wide text-gray-500 uppercase">
                                    Progress
                                </th>
                                <th className="px-6 py-3 text-right text-xs font-medium tracking-wide text-gray-500 uppercase">
                                    Outstanding
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {rows.map((row, idx) => (
                                <tr
                                    key={`${row.teacher.id}-${row.class_name}-${row.gender ?? ''}-${idx}`}
                                    className="hover:bg-gray-50/50"
                                >
                                    <td className="px-6 py-3">
                                        <div className="flex items-center gap-3">
                                            <Avatar teacher={row.teacher} />
                                            <div>
                                                <p className="font-medium text-gray-900">
                                                    {row.teacher.first_name}{' '}
                                                    {row.teacher.last_name}
                                                </p>
                                                {row.teacher.staff_number && (
                                                    <p className="text-xs text-gray-400">
                                                        #{row.teacher.staff_number}
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                    </td>
                                    <td className="px-6 py-3 text-gray-700">
                                        {row.class_name}
                                    </td>
                                    {showGender && (
                                        <td className="px-6 py-3">
                                            <span className="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700 capitalize">
                                                {row.gender ?? '—'}
                                            </span>
                                        </td>
                                    )}
                                    <td className="px-6 py-3">
                                        <ProgressBar
                                            completed={row.completed}
                                            total={row.total}
                                        />
                                    </td>
                                    <td className="px-6 py-3 text-right">
                                        {row.outstanding > 0 ? (
                                            <span className="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-semibold text-red-700">
                                                {row.outstanding}
                                            </span>
                                        ) : (
                                            <span className="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-700">
                                                0
                                            </span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

function LoadingSkeleton() {
    return (
        <div className="space-y-6 p-4">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                {[1, 2, 3].map((i) => (
                    <Skeleton key={i} className="h-20 rounded-xl" />
                ))}
            </div>
            {[1, 2, 3].map((i) => (
                <Skeleton key={i} className="h-48 rounded-2xl" />
            ))}
        </div>
    );
}

export default function OutstandingComments() {
    const [data, setData] = useState<OutstandingData | null>(null);
    const [loading, setLoading] = useState(true);
    const [termId, setTermId] = useState('');

    useEffect(() => {
        setLoading(true);
        axios
            .get<{ data: OutstandingData }>('/api/outstanding-comments', {
                params: termId ? { term_id: termId } : {},
            })
            .then((res) => setData(res.data.data))
            .finally(() => setLoading(false));
    }, [termId]);

    if (loading) {
        return <LoadingSkeleton />;
    }

    if (!data || !data.term) {
        return (
            <div className="flex flex-col items-center justify-center py-24 text-center">
                <ClipboardList className="mb-4 h-12 w-12 text-gray-300" />
                <p className="text-sm font-medium text-gray-700">
                    No active term
                </p>
                <p className="mt-1 text-xs text-gray-400">
                    Outstanding comments are tracked against the active term
                </p>
                <div className="mt-4">
                    <TermFilterSelect value={termId} onChange={setTermId} />
                </div>
            </div>
        );
    }

    const totalOutstanding =
        data.form_teachers.reduce((s, r) => s + r.outstanding, 0) +
        data.boarding_parents.reduce((s, r) => s + r.outstanding, 0) +
        data.head_of_schools.reduce((s, r) => s + r.outstanding, 0);

    const totalCompleted =
        data.form_teachers.reduce((s, r) => s + r.completed, 0) +
        data.boarding_parents.reduce((s, r) => s + r.completed, 0) +
        data.head_of_schools.reduce((s, r) => s + r.completed, 0);

    const totalAssignments =
        data.form_teachers.length +
        data.boarding_parents.length +
        data.head_of_schools.length;

    return (
        <div className="space-y-6 p-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="text-lg font-semibold text-gray-900">
                        Outstanding Comments & Assessments
                    </h1>
                    <p className="mt-0.5 text-sm text-gray-500">
                        Track completion status for {data.term}
                    </p>
                </div>
                <TermFilterSelect value={termId} onChange={setTermId} />
            </div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <StatCard
                    label="Total Assignments"
                    value={totalAssignments}
                    icon={Users}
                    color="bg-indigo-600"
                />
                <StatCard
                    label="Completed"
                    value={totalCompleted}
                    icon={CheckCircle2}
                    color="bg-emerald-600"
                />
                <StatCard
                    label="Outstanding"
                    value={totalOutstanding}
                    icon={AlertTriangle}
                    color={totalOutstanding > 0 ? 'bg-red-600' : 'bg-emerald-600'}
                />
            </div>

            <SectionTable
                title="Form Teachers"
                icon={MessageSquare}
                rows={data.form_teachers}
            />

            <SectionTable
                title="Boarding Parents"
                icon={Heart}
                rows={data.boarding_parents}
                showGender
            />

            <SectionTable
                title="Head of Schools"
                icon={Shield}
                rows={data.head_of_schools}
            />
        </div>
    );
}
