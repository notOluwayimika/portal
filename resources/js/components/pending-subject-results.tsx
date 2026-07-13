import axios from 'axios';
import { useEffect, useMemo, useState } from 'react';
import type { ClassLevelArm, CurriculumSubject, Teacher } from '@/types/models';
import ClassLevelArmFilter from './class-level-arm-filter';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function TeacherPill({ teacher }: { teacher: Teacher }) {
    const initials =
        `${teacher.first_name[0]}${teacher.last_name[0]}`.toUpperCase();

    return (
        <span className="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-gray-50 py-0.5 pr-2.5 pl-0.5 text-xs text-gray-700">
            {teacher.photo ? (
                <img
                    src={teacher.photo}
                    alt=""
                    className="h-4 w-4 rounded-full object-cover"
                />
            ) : (
                <span className="flex h-4 w-4 items-center justify-center rounded-full bg-indigo-100 text-[9px] font-bold text-indigo-600">
                    {initials}
                </span>
            )}
            {teacher.first_name} {teacher.last_name}
            {teacher.staff_number && (
                <span className="text-gray-400">#{teacher.staff_number}</span>
            )}
        </span>
    );
}

function StatusBadge({
    status,
}: {
    status: 'draft' | 'no-entry' | 'rejected';
}) {
    if (status === 'no-entry') {
        return (
            <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">
                Not started
            </span>
        );
    }

    if (status === 'rejected') {
        return (
            <span className="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-700">
                Rejected
            </span>
        );
    }

    return (
        <span className="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-700">
            Draft
        </span>
    );
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export default function PendingSubjectResults() {
    const [curriculumSubjects, setCurriculumSubjects] = useState<
        CurriculumSubject[]
    >([]);
    const [classLevelArms, setClassLevelArms] = useState<ClassLevelArm[]>([]);
    const [selectedClassLevelArms, setSelectedClassLevelArms] = useState<
        string[]
    >([]);

    useEffect(() => {
        const fetchClassLevelArms = async () => {
            const response = await axios.get<ClassLevelArm[]>(
                '/api/class-level-arms',
            );
            setClassLevelArms(response.data);
        };

        fetchClassLevelArms();
    }, []);

    useEffect(() => {
        const fetchCurriculumSubjects = async () => {
            const response = await axios.get<CurriculumSubject[]>(
                '/api/curriculum-subjects',
                {
                    params: {
                        class_level_arms: selectedClassLevelArms,
                    },
                },
            );
            setCurriculumSubjects(response.data);
        };

        if (selectedClassLevelArms.length > 0) {
            fetchCurriculumSubjects();
        } else {
            // eslint-disable-next-line react-hooks/set-state-in-effect
            setCurriculumSubjects([]);
        }
    }, [selectedClassLevelArms]);

    const pending = useMemo(
        () =>
            curriculumSubjects
                .filter((cs) => {
                    const s = cs.result_status?.status;

                    return !s || s === 'draft' || s === 'rejected';
                })
                .sort(
                    (a, b) => (a.display_order ?? 0) - (b.display_order ?? 0),
                ),
        [curriculumSubjects],
    );

    if (pending.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center space-y-4 rounded-xl border border-gray-200 bg-white p-4 py-14 text-center">
                <div className="w-full">
                    <ClassLevelArmFilter
                        classLevelArms={classLevelArms}
                        selected={selectedClassLevelArms}
                        setFilters={setSelectedClassLevelArms}
                    />
                </div>
                <span className="text-4xl">✅</span>
                <p className="mt-3 text-sm font-semibold text-gray-700">
                    All results submitted
                </p>
                <p className="mt-1 text-xs text-gray-400">
                    Every subject has a result entry beyond draft.
                </p>
            </div>
        );
    }

    return (
        <div className="overflow-hidden rounded-xl border border-gray-200 bg-white">
            {/* Header */}
            <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                <div>
                    <h2 className="text-sm font-semibold text-gray-900">
                        Pending Results
                    </h2>
                    <p className="mt-0.5 text-xs text-gray-500">
                        Subjects with no entry or still in draft
                    </p>
                </div>
                <span className="flex h-6 min-w-6 items-center justify-center rounded-full bg-amber-500 px-1.5 text-xs font-bold text-white">
                    {pending.length}
                </span>
            </div>
            <div>
                <ClassLevelArmFilter
                    classLevelArms={classLevelArms}
                    selected={selectedClassLevelArms}
                    setFilters={setSelectedClassLevelArms}
                />
            </div>

            {/* Table */}
            <div className="overflow-x-auto">
                <table className="w-full border-collapse text-sm">
                    <thead>
                        <tr className="border-b border-gray-100 bg-gray-50 text-left text-xs font-semibold tracking-wide text-gray-500 uppercase">
                            <th className="px-5 py-3">Subject</th>
                            <th className="px-5 py-3">Class Level Arm</th>
                            <th className="px-5 py-3">Session</th>
                            <th className="px-5 py-3">Term</th>
                            <th className="px-5 py-3">Status</th>
                            <th className="px-5 py-3">Assigned Teachers</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-50">
                        {pending.map((cs) => {
                            const subject = cs.subject;
                            const teachers = (cs.teachers ?? []).map(
                                (ta) => ta.teacher,
                            );
                            const statusKey = cs.result_status?.status
                                ? cs.result_status.status
                                : 'no-entry';

                            return (
                                <tr
                                    key={cs.id}
                                    className="group hover:bg-gray-50"
                                >
                                    {/* Subject */}
                                    <td className="px-5 py-3">
                                        <div className="flex items-center gap-2">
                                            <span className="font-medium text-gray-900">
                                                {subject?.name ??
                                                    'Unknown Subject'}
                                            </span>
                                            {subject?.code && (
                                                <span className="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-500">
                                                    {subject.code}
                                                </span>
                                            )}
                                            {cs.is_compulsory && (
                                                <span className="rounded bg-amber-50 px-1.5 py-0.5 text-xs font-medium text-amber-600">
                                                    Core
                                                </span>
                                            )}
                                        </div>
                                    </td>
                                    <td className="px-5 py-3">
                                        {cs.curriculum?.class_level_arm?.name ??
                                            'Unknown Class Level Arm'}
                                    </td>
                                    <td className="px-5 py-3 whitespace-nowrap text-gray-600">
                                        {cs.curriculum?.academic_session
                                            ?.name ?? '—'}
                                    </td>
                                    <td className="px-5 py-3 whitespace-nowrap text-gray-600">
                                        {cs.curriculum?.term?.name ?? '—'}
                                    </td>

                                    {/* Status */}
                                    <td className="px-5 py-3">
                                        <StatusBadge status={statusKey} />
                                    </td>

                                    {/* Teachers */}
                                    <td className="px-5 py-3">
                                        {teachers.length > 0 ? (
                                            <div className="flex flex-wrap gap-1.5">
                                                {teachers.map((t) => (
                                                    <TeacherPill
                                                        key={t.id}
                                                        teacher={t}
                                                    />
                                                ))}
                                            </div>
                                        ) : (
                                            <span className="text-xs text-gray-400 italic">
                                                No teacher assigned
                                            </span>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
