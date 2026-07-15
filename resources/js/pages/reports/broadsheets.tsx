import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { Download, Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { Broadsheet, BroadsheetGroup, ClassLevel } from '@/types/models';

interface BroadsheetsPageProps {
    classLevels: { data: ClassLevel[] };
    [key: string]: unknown;
}

export default function Broadsheets() {
    const { classLevels } = usePage<BroadsheetsPageProps>().props;
    const levels = [...classLevels.data].sort((a, b) => a.order - b.order);

    const [classLevelId, setClassLevelId] = useState('');
    const [status, setStatus] = useState('');
    const [isCcm, setIsCcm] = useState('');
    const [groups, setGroups] = useState<BroadsheetGroup[]>([]);
    const [selectedCurriculum, setSelectedCurriculum] = useState<string | null>(
        null,
    );
    const [broadsheet, setBroadsheet] = useState<Broadsheet | null>(null);
    const [loadingGroups, setLoadingGroups] = useState(false);
    const [loadingSheet, setLoadingSheet] = useState(false);
    const [exporting, setExporting] = useState(false);

    useEffect(() => {
        if (!classLevelId) {
            return;
        }

        const fetchGroups = async () => {
            setLoadingGroups(true);
            setSelectedCurriculum(null);
            setBroadsheet(null);

            try {
                const params: Record<string, string> = {
                    class_level: classLevelId,
                };

                if (status) {
                    params.status = status;
                }

                if (isCcm) {
                    params.is_ccm = isCcm;
                }

                const response = await axios.get('/api/broadsheets/groups', {
                    params,
                });
                setGroups(response.data.groups);
            } finally {
                setLoadingGroups(false);
            }
        };

        fetchGroups();
    }, [classLevelId, status, isCcm]);

    useEffect(() => {
        if (!selectedCurriculum) {
            return;
        }

        const fetchBroadsheet = async () => {
            setLoadingSheet(true);

            try {
                const response = await axios.get(
                    `/api/broadsheets/${selectedCurriculum}`,
                );
                setBroadsheet(response.data);
            } finally {
                setLoadingSheet(false);
            }
        };

        fetchBroadsheet();
    }, [selectedCurriculum]);

    const handleExport = async () => {
        if (!selectedCurriculum || !broadsheet) {
            return;
        }

        setExporting(true);

        try {
            const response = await axios.get(
                `/api/broadsheets/${selectedCurriculum}/export`,
                {
                    responseType: 'blob',
                },
            );
            const url = URL.createObjectURL(
                new Blob([response.data], {
                    type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                }),
            );
            const link = document.createElement('a');
            const type = broadsheet.is_ccm ? 'ccm' : 'end-of-term';
            link.href = url;
            link.download = `${broadsheet.class_level.toLowerCase().replace(/\s+/g, '-')}-${type}-broadsheet.xlsx`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        } finally {
            setExporting(false);
        }
    };

    return (
        <div className="flex flex-col gap-4 p-4">
            <h2 className="text-lg font-semibold text-gray-900">Broadsheets</h2>

            <div className="flex flex-wrap items-end gap-3">
                <div className="flex flex-col gap-1">
                    <label className="text-xs font-medium text-gray-500">
                        Class Level
                    </label>
                    <select
                        value={classLevelId}
                        onChange={(e) => {
                            setClassLevelId(e.target.value);
                            setGroups([]);
                            setSelectedCurriculum(null);
                            setBroadsheet(null);
                        }}
                        className="rounded-md border border-gray-200 px-3 py-1.5 text-sm text-gray-900"
                    >
                        <option value="">Select a class level</option>
                        {levels.map((level) => (
                            <option key={level.id} value={level.id}>
                                {level.name}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="flex flex-col gap-1">
                    <label className="text-xs font-medium text-gray-500">
                        Status
                    </label>
                    <select
                        value={status}
                        onChange={(e) => {
                            setStatus(e.target.value);
                            setSelectedCurriculum(null);
                            setBroadsheet(null);
                        }}
                        className="rounded-md border border-gray-200 px-3 py-1.5 text-sm text-gray-900"
                    >
                        <option value="">All</option>
                        <option value="active">Active</option>
                        <option value="draft">Draft</option>
                        <option value="closed">Closed</option>
                    </select>
                </div>

                <div className="flex flex-col gap-1">
                    <label className="text-xs font-medium text-gray-500">
                        Type
                    </label>
                    <select
                        value={isCcm}
                        onChange={(e) => {
                            setIsCcm(e.target.value);
                            setSelectedCurriculum(null);
                            setBroadsheet(null);
                        }}
                        className="rounded-md border border-gray-200 px-3 py-1.5 text-sm text-gray-900"
                    >
                        <option value="">All</option>
                        <option value="true">CCM</option>
                        <option value="false">End of Term</option>
                    </select>
                </div>
            </div>

            {classLevelId && (
                <div className="flex flex-col gap-2">
                    {loadingGroups && (
                        <p className="flex items-center gap-2 text-sm text-gray-500">
                            <Loader2 className="h-4 w-4 animate-spin" /> Loading
                            curricula...
                        </p>
                    )}
                    {!loadingGroups && groups.length === 0 && (
                        <p className="text-sm text-gray-500">
                            No curricula found for this class level.
                        </p>
                    )}
                    {groups.map((group) => (
                        <button
                            key={group.curriculum_id}
                            onClick={() => {
                                setSelectedCurriculum(group.curriculum_id);
                                setBroadsheet(null);
                            }}
                            className={`flex flex-wrap items-center justify-between gap-3 rounded-xl border px-5 py-3 text-left transition-colors ${
                                selectedCurriculum === group.curriculum_id
                                    ? 'border-blue-300 bg-blue-50'
                                    : 'border-gray-200 bg-white hover:border-gray-300'
                            }`}
                        >
                            <div className="flex flex-wrap items-center gap-3">
                                <span className="text-sm font-medium text-gray-900">
                                    {group.term.full_name}
                                </span>
                                <span className="text-sm text-gray-500">
                                    {group.exam_type}
                                </span>
                                <span
                                    className={`rounded-full px-2 py-0.5 text-xs font-medium ${
                                        group.is_ccm
                                            ? 'bg-purple-100 text-purple-700'
                                            : 'bg-emerald-100 text-emerald-700'
                                    }`}
                                >
                                    {group.is_ccm ? 'CCM' : 'End of Term'}
                                </span>
                                <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 capitalize">
                                    {group.status}
                                </span>
                                <span className="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700 capitalize">
                                    {group.grading_mode} grading
                                </span>
                            </div>
                            <div className="flex flex-wrap gap-1.5">
                                {group.arms.map((arm) => (
                                    <span
                                        key={arm}
                                        className="inline-flex h-7 items-center justify-center rounded-md border border-gray-200 bg-gray-50 px-2 text-xs font-medium text-gray-500"
                                    >
                                        {arm}
                                    </span>
                                ))}
                            </div>
                        </button>
                    ))}
                </div>
            )}

            {selectedCurriculum && (
                <div className="flex flex-col gap-3">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h3 className="text-base font-semibold text-gray-900">
                            {broadsheet
                                ? `${broadsheet.class_level} ${broadsheet.is_ccm ? 'CCM' : 'End of Term'} Broadsheet`
                                : 'Loading broadsheet...'}
                        </h3>
                        <button
                            onClick={handleExport}
                            disabled={exporting || !broadsheet}
                            className="inline-flex items-center gap-1.5 rounded-md border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 disabled:opacity-50"
                        >
                            {exporting ? (
                                <Loader2 className="h-4 w-4 animate-spin" />
                            ) : (
                                <Download className="h-4 w-4" />
                            )}
                            {exporting ? 'Exporting...' : 'Export to Excel'}
                        </button>
                    </div>

                    {loadingSheet && (
                        <p className="flex items-center gap-2 text-sm text-gray-500">
                            <Loader2 className="h-4 w-4 animate-spin" />{' '}
                            Loading...
                        </p>
                    )}

                    {broadsheet && (
                        <div className="overflow-x-auto rounded-xl border border-gray-200">
                            <table className="min-w-full border-collapse text-xs">
                                <thead>
                                    <tr className="bg-gray-50">
                                        <th
                                            rowSpan={2}
                                            className="border border-gray-200 px-2 py-1 font-medium text-gray-600"
                                        >
                                            S/N
                                        </th>
                                        <th
                                            rowSpan={2}
                                            className="border border-gray-200 px-2 py-1 font-medium text-gray-600"
                                        >
                                            Student Name
                                        </th>
                                        <th
                                            rowSpan={2}
                                            className="border border-gray-200 px-2 py-1 font-medium text-gray-600"
                                        >
                                            Class
                                        </th>
                                        <th
                                            rowSpan={2}
                                            className="border border-gray-200 px-2 py-1 font-medium text-gray-600"
                                        >
                                            Gender
                                        </th>
                                        {broadsheet.subjects.map((subject) => (
                                            <th
                                                key={subject.subject_id}
                                                colSpan={subject.columns.length}
                                                className="border border-gray-200 px-2 py-1 font-medium text-gray-600"
                                            >
                                                {subject.name}
                                            </th>
                                        ))}
                                        {broadsheet.grading_mode ===
                                            'numeric' && (
                                            <th
                                                rowSpan={2}
                                                className="border border-gray-200 px-2 py-1 font-medium text-gray-600"
                                            >
                                                Term GPA
                                            </th>
                                        )}
                                    </tr>
                                    <tr className="bg-gray-50">
                                        {broadsheet.subjects.flatMap(
                                            (subject) =>
                                                subject.columns.map((col) => (
                                                    <th
                                                        key={`${subject.subject_id}-${col.key}`}
                                                        className="border border-gray-200 px-2 py-1 font-medium text-gray-600"
                                                    >
                                                        {col.label}
                                                    </th>
                                                )),
                                        )}
                                    </tr>
                                </thead>
                                <tbody>
                                    {broadsheet.classes.flatMap((cls) =>
                                        cls.students.map((student) => (
                                            <tr
                                                key={student.sn}
                                                className="text-center"
                                            >
                                                <td className="border border-gray-200 px-2 py-1">
                                                    {student.sn}
                                                </td>
                                                <td className="border border-gray-200 px-2 py-1 text-left whitespace-nowrap">
                                                    {student.name}
                                                </td>
                                                <td className="border border-gray-200 px-2 py-1">
                                                    {cls.label}
                                                </td>
                                                <td className="border border-gray-200 px-2 py-1">
                                                    {student.gender}
                                                </td>
                                                {broadsheet.subjects.flatMap(
                                                    (subject) =>
                                                        subject.columns.map(
                                                            (col) => {
                                                                const cell =
                                                                    student
                                                                        .subjects[
                                                                        String(
                                                                            subject.subject_id,
                                                                        )
                                                                    ] ?? {};
                                                                const value =
                                                                    cell[
                                                                        col.key
                                                                    ];

                                                                return (
                                                                    <td
                                                                        key={`${subject.subject_id}-${col.key}`}
                                                                        className="border border-gray-200 px-2 py-1"
                                                                    >
                                                                        {value ??
                                                                            '-'}
                                                                    </td>
                                                                );
                                                            },
                                                        ),
                                                )}
                                                {broadsheet.grading_mode ===
                                                    'numeric' && (
                                                    <td className="border border-gray-200 px-2 py-1 font-medium">
                                                        {student.gpa ?? '-'}
                                                    </td>
                                                )}
                                            </tr>
                                        )),
                                    )}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
