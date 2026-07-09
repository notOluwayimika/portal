import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useMemo, useState } from 'react';
import { convertNameToResultFmt, fmtDate, toShortName } from '@/helpers';
import type {
    CurriculumCardProps,
    ResultRow,
} from '@/pages/student/results/active';
import {
    gradeForScore,
    GradeKeyTable,
    gradePointForScore,
    toNum,
    totalGradePoint,
} from '@/pages/student/results/active';
import type {
    CurriculumSubject,
    GradeBoundary,
    Score,
    StudentCurriculum,
    TeacherCurriculumSubject,
} from '@/types/models';
import { BehavioralAssessmentTable } from './behavioral-assessment';

function SubjectRow({
    r,
    i,
    boundaries,
}: {
    r: ResultRow;
    i: number;
    boundaries: GradeBoundary[];
}) {
    const csScIds = r.key?.split(',');
    const csId = csScIds?.[0];
    const scId = csScIds?.[1];

    const [scores, setScores] = useState<Score[] | null>(null);
    const [examScore, setExamScore] = useState<string>('-');
    const [caScore, setCaScore] = useState<string>('-');
    const [yearAvg, setYearAvg] = useState<string>('-');
    const [teachers, setTeachers] = useState<TeacherCurriculumSubject[] | null>(
        null,
    );
    useEffect(() => {
        const getScores = async () => {
            if (csId && scId) {
                const response = await axios.get(
                    `/api/student-curricula/${scId}/curriculum-subject/${csId}`,
                );
                setScores(response.data.data);
            }
        };
        const getYearAverage = async () => {
            if (csId) {
                const response = await axios.get(
                    `/api/curriculum-subjects/${csId}/year-average`,
                );
                setYearAvg(response.data.year_average ?? '-');
            }
        };
        const getTeachers = async () => {
            if (csId) {
                const response = await axios.get(
                    `/api/curriculum-subjects/${csId}/teachers`,
                );
                setTeachers(response.data);
            }
        };
        getScores();
        getYearAverage();
        getTeachers();
    }, [csId, scId]);

    useEffect(() => {
        if (!scores) {
            return;
        }

        const examination = scores
            ? scores?.filter(
                  (s: Score) => s.marking_component.name === 'Examination',
              )[0]
            : null;
        // eslint-disable-next-line react-hooks/set-state-in-effect
        setExamScore(examination?.score ? String(Number(examination?.score).toFixed(1)) : '-');
        const CA = scores
            ? scores.filter(
                  (s: Score) => s.marking_component.name !== 'Examination',
              )
            : null;
        const CAPercentage = CA?.map((ca) => ca.score).reduce(
            (a, b) => Number(a) + Number(b),
            0,
        );
        setCaScore(String(CAPercentage?.toFixed(1)) || '-');
    }, [scores]);

    return (
        <tr key={r.key} className={i % 2 ? 'bg-slate-50' : 'bg-white'}>
            <td className="w-px border border-slate-300 px-1 text-center">
                {i + 1}
            </td>
            <td className="border border-slate-300 px-1">
                <span className="font-medium text-slate-800">{r.name}</span>
            </td>
            <td className="border border-slate-300 px-1 text-center tabular-nums">
                {/* CA */}
                {caScore}
            </td>
            <td className="border border-slate-300 px-1 text-center tabular-nums">
                {/* Exam */}
                {examScore}
            </td>
            <td className="border border-slate-300 px-1 text-center tabular-nums">
                {/* total */}
                {r.score != null ? r.score.toFixed(1) : '—'}
            </td>
            <td
                className={`border border-slate-300 px-1 text-center font-semibold text-black`}
            >
                {r.grade}
            </td>
            <td
                className={`border border-slate-300 px-1 text-center font-semibold text-black`}
            >
                {gradePointForScore(r.score, boundaries)}
            </td>

            <td className="border border-slate-300 px-1 text-center text-slate-600 tabular-nums">
                {r.classAvg != null ? r.classAvg.toFixed(1) : '—'}
            </td>
            <td className="border border-slate-300 px-1 text-center text-slate-600 tabular-nums">
                {/* average year */}
                {yearAvg != null ? yearAvg : '—'}
            </td>
            <td className="border border-slate-300 px-1 text-left text-slate-600 tabular-nums">
                {/* teacher */}
                {convertNameToResultFmt(teachers?.[0]?.teacher?.full_name ?? '')}
            </td>
            <td className="border border-slate-300 px-1 text-left text-slate-600 tabular-nums">
                {/* comment */}
                {r.comment}
            </td>
        </tr>
    );
}

export function CurriculumCardFinal({
    sc,
    defaultBoundaries,
    studentId,
    student,
    boundaries,
}: CurriculumCardProps) {
    const { auth } = usePage().props;
    const roles = auth.roles;
    const [scDetails, setScDetails] = useState<any | null>(null);
    useEffect(() => {
        const getScDetails = async (scId: string) => {
            const response = await axios.get(`/api/student-curricula/${scId}`);
            setScDetails(response.data);
        };
        getScDetails(sc.id);
    }, [sc]);

    const rows = useMemo<ResultRow[]>(() => {
        const subjects = (sc.subjects || [])
            .slice()
            .sort(
                (a, b) =>
                    (a.curriculum_subject?.display_order ?? 0) -
                    (b.curriculum_subject?.display_order ?? 0),
            );

        return subjects.map((ss): ResultRow => {
            const cs = ss.curriculum_subject || ({} as CurriculumSubject);
            const name =
                cs.subject?.name || `Subject ${cs.subject_id ?? ''}`.trim();
            const code = cs.subject?.code || '';

            const own = ss.own_result;
            const score = own ? toNum(own.total_score) : null;
            const grade =
                own?.grade ||
                gradeForScore(score, boundaries ?? defaultBoundaries);

            const classAvg =
                cs.class_average != null ? toNum(cs.class_average) : null;

            return {
                key: cs.id + ',' + sc.id,
                name,
                code,
                compulsory: cs.is_compulsory,
                score,
                grade,
                classAvg,
                classAvgGrade: gradeForScore(
                    classAvg,
                    boundaries ?? defaultBoundaries,
                ),
                comment: ss.comment || '',
                commented_by: ss.commented_by || '',
            };
        });
    }, [sc, studentId, boundaries]);
    const isGuardian = roles.includes('guardian');
    const hasIncompleteResults = rows.some((r) => r.score === null);
    const resultsIncomplete = isGuardian && hasIncompleteResults;

    const overall = useMemo<number | null>(() => {
        const vals = rows
            .map((r) => r.score)
            .filter((n): n is number => n != null && !Number.isNaN(n));

        if (!vals.length) {
            return null;
        }

        return vals.reduce((s, n) => s + n, 0) / vals.length;
    }, [rows]);
    const currentClass = student.class_details.full_class.split(' ');
    const promotedClass = Number(currentClass[1]) + 1;

    return (
        <div className="student-result-card overflow-hidden border border-slate-300">
            <div className="p-0">
                <div className="grid grid-cols-3 bg-blue-100">
                    <p className="flex border border-slate-300 p-px text-xs text-black">
                        <span className="inline-block pr-2 font-bold">
                            Name:{' '}
                        </span>
                        {student.last_name}, {student.first_name}{' '}
                        {student.middle_name}
                    </p>

                    <p className="flex border border-slate-300 p-px text-xs text-black">
                        <span className="inline-block pr-2 font-bold">
                            Admission No:{' '}
                        </span>
                        {student.admission_number}
                    </p>
                    <p className="flex border border-slate-300 p-px text-xs text-black">
                        <span className="inline-block pr-2 font-bold">
                            Date Of Birth:{' '}
                        </span>
                        {fmtDate(student.date_of_birth)}
                    </p>
                    <p className="flex border border-slate-300 p-px text-xs text-black">
                        <span className="inline-block pr-2 font-bold">
                            Year Group:{' '}
                        </span>
                        {student.class_details.full_class}
                    </p>
                    <p className="flex border border-slate-300 p-px text-xs text-black">
                        <span className="inline-block pr-2 font-bold">
                            Sport House:{' '}
                        </span>
                        {student.sport_house?.name}
                    </p>
                </div>
                {/* <span className="rounded bg-blue-700 px-2 py-1 text-xs font-medium text-white">
                    {rows.length} subjects
                </span> */}
            </div>

            <div className="overflow-x-auto">
                <table className="w-full border-collapse text-xs">
                    <thead>
                        <tr className="bg-blue-100 text-center text-black">
                            <th className="w-fit border border-slate-300 px-1 font-semibold">
                                S/N
                            </th>
                            <th className="border border-slate-300 px-1 font-semibold">
                                Subject
                            </th>
                            <th className="border border-slate-300 px-1 text-center font-semibold">
                                <div>CA</div>
                                <div>30%</div>
                            </th>
                            <th className="border border-slate-300 px-1 text-center font-semibold">
                                <div>Exam</div>
                                <div>70%</div>
                            </th>
                            <th className="border border-slate-300 px-1 text-center font-semibold">
                                <div>Total</div>
                                <div>100%</div>
                            </th>
                            <th className="border border-slate-300 px-1 text-center font-semibold">
                                Grade
                            </th>
                            <th className="border border-slate-300 px-1 text-center font-semibold">
                                GP
                            </th>
                            <th className="border border-slate-300 px-1 text-center font-semibold">
                                <div>Sub</div>
                                <div>Av</div>
                                <div>(Cl)</div>
                            </th>
                            <th className="border border-slate-300 px-1 text-center font-semibold">
                                <div>Sub</div>
                                <div>Av</div>
                                <div>(Yr)</div>
                            </th>
                            <th className="border border-slate-300 px-1 text-center font-semibold">
                                Teacher
                            </th>
                            <th className="border border-slate-300 px-1 text-center font-semibold">
                                Comments
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {resultsIncomplete ? (
                            <tr>
                                <td
                                    colSpan={6}
                                    className="border border-slate-300 px-4 py-6 text-center text-xs text-slate-500"
                                >
                                    Result incomplete — please check back later.
                                </td>
                            </tr>
                        ) : (
                            rows.map((r, i) => (
                                <SubjectRow
                                    r={r}
                                    i={i}
                                    boundaries={boundaries ?? defaultBoundaries}
                                />
                            ))
                        )}
                    </tbody>
                    {/* {overall != null && !resultsIncomplete && ( */}
                    <tfoot>
                        <tr className="bg-blue-300 font-semibold text-black">
                            <td></td>
                            <td className="border border-slate-300 px-1">
                                Term GPA
                            </td>

                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td className="border border-slate-300 px-1 text-center">
                                {totalGradePoint(
                                    rows,
                                    boundaries ?? defaultBoundaries,
                                )}
                            </td>

                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                        </tr>
                    </tfoot>
                    {/* )} */}
                </table>
            </div>
            <div className="grid grid-cols-2">
                <div>
                    <BehavioralAssessmentTable
                        assessment={scDetails?.behavioralAssessments[0]}
                    />
                </div>

                <div className="px-8 text-xs">
                    <GradeKeyTable
                        boundaries={boundaries ?? defaultBoundaries}
                    />
                    <p>Sub Av. (Cl): Subject Average for the class</p>
                    <p>Sub Av. (Yr): Subject Average for the year group</p>
                </div>
            </div>
            <div className="grid grid-cols-4 text-xs">
                <div className="col-span-1 border font-bold">
                    Form Tutor's Name:
                </div>
                <div className="col-span-3 border">
                    {scDetails?.formTeacher?.full_name}
                </div>
                <div className="col-span-1 border font-bold">Comment:</div>
                <div className="col-span-3 border">
                    {scDetails?.studentCurriculum?.form_teacher_comment}
                </div>
                <div className="col-span-1 border font-bold">
                    Boarding Parent's Name:
                </div>
                <div className="col-span-3 border">
                    {scDetails?.boardingParent?.full_name}
                </div>
                <div className="col-span-1 border font-bold">Comment:</div>
                <div className="col-span-3 border">
                    {scDetails?.behavioralAssessments[0]?.comment}
                </div>
                <div className="col-span-1 border font-bold">
                    Head of School Name:
                </div>
                <div className="col-span-3 border">
                    {scDetails?.headOfSchool?.full_name}
                </div>
                <div className="col-span-1 border font-bold">Comment:</div>
                <div className="col-span-3 border">
                    {scDetails?.studentCurriculum?.head_of_school_comment}
                </div>
                <div className="col-span-1 border font-bold">
                    Approved by the Principal:
                </div>
                <div className="col-span-3 border">
                    <img
                        src="/assets/images/signature_secondary.png"
                        alt="Brookstone School"
                        className={`h-8 w-auto sm:h-10`}
                        draggable={false}
                    />
                </div>
                {promotedClass < 12 && (
                    <div className="col-span-4 border text-center text-sm font-bold">
                        Promoted To Year {promotedClass}
                    </div>
                )}
            </div>
        </div>
    );
}
