import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useMemo, useState } from 'react';
import type {
    CurriculumCardProps,
    ResultRow,
} from '@/components/student-results/shared';
import {
    gradeForScore,
    GradeKeyTable,
    gradePointForScore,
    toNum,
    totalGradePoint,
} from '@/components/student-results/shared';
import { convertNameToResultFmt, fmtDate } from '@/helpers';
import type {
    CurriculumSubject,
    GradeBoundary,
    Score,
    TeacherCurriculumSubject,
} from '@/types/models';
import { BehavioralAssessmentTable } from './behavioral-assessment';
import { PsychomotorSkillsTable } from './psychomotor-skills';

export type ResultSignature = {
    url: string;
    label: string;
    signer_name: string | null;
    approval_date: string | null;
    source: 'principal' | 'head_of_school' | 'fallback';
};

type CurriculumCardFinalProps = CurriculumCardProps & {
    resultSignature?: ResultSignature | null;
};

/**
 * Exported for the CCM sheets, which print the signature outside the card rather
 * than as one of its rows — and which, on the class-level sheet, used to print a
 * hard-coded image instead.
 */
export function ResultSignatureBlock({
    signature,
    showCaption = true,
}: {
    signature?: ResultSignature | null;
    showCaption?: boolean;
}) {
    if (!signature) {
        return null;
    }

    return (
        <div>
            <img
                src={signature.url}
                alt={signature.label}
                className="h-10 w-auto object-contain sm:h-16"
                draggable={false}
            />
            {showCaption && <p>{signature.label}</p>}
            {signature.approval_date && (
                <p>Approval date: {fmtDate(signature.approval_date)}</p>
            )}
        </div>
    );
}

function DetailRow({ label, value }: { label: string; value?: string | null }) {
    if (!value) {
        return null;
    }

    return (
        <>
            <div className="col-span-1 border font-bold">{label}</div>
            <div className="col-span-3 border">{value}</div>
        </>
    );
}

/**
 * The comment-attribution rows, shared by both card layouts below so the two can
 * never disagree about who wrote what.
 *
 * BOARDING IS CONDITIONAL, AND ON TWO LEVELS. `schoolHasBoardingParents` says
 * whether this school runs boarding at all; `boardingParent` says whether one is
 * assigned for THIS student's arm and gender. Both must hold before a comment is
 * attributed to a boarding parent.
 *
 * The bug this fixes: the comment row was gated on nothing, so it rendered
 * whenever a behavioural assessment existed. In a school with no boarding parents
 * the FORM TUTOR writes that assessment (see ResolvesAssessmentAccess —
 * canRecordAssessmentFor falls through to the form teacher exactly then), so a
 * printed result credited their comment to a "Boarding Parent" who does not exist.
 * A day school now gets the same comment under a neutral label rather than losing
 * it.
 */
function AttributionRows({ scDetails }: { scDetails: any }) {
    const hasBoarding = Boolean(scDetails?.schoolHasBoardingParents);
    const boardingParentName = scDetails?.boardingParent?.full_name;
    const assessmentComment = scDetails?.behavioralAssessments?.[0]?.comment;
    // Default TRUE when the payload has not arrived yet, so the rows do not
    // flicker in and then out on a school that shows them.
    const showHeadOfSchoolComment =
        scDetails?.showHeadOfSchoolComment !== false;
    const showBehaviourComment = scDetails?.showBehaviourComment !== false;

    return (
        <>
            <DetailRow
                label="Form Tutor's Name:"
                value={scDetails?.formTeacher?.full_name}
            />
            <DetailRow
                label="Form Tutor Comment:"
                value={scDetails?.studentCurriculum?.form_teacher_comment}
            />
            {/*
                Primary's senior comment. Independent of the Head of School rows
                below: an arm prints whichever seats it actually has, and in
                practice primary has only a coordinator while secondary has only a
                head. DetailRow hides empty values, so an unassigned arm prints
                nothing here without a further condition.
            */}
            <DetailRow
                label="Key Stage Coordinator's Name:"
                value={scDetails?.keyStageCoordinator?.full_name}
            />
            <DetailRow
                label="Key Stage Coordinator's Comment:"
                value={
                    scDetails?.keyStageCoordinator
                        ? scDetails?.studentCurriculum
                              ?.key_stage_coordinator_comment
                        : null
                }
            />
            {/*
                THE COMMENT IS GATED, THE NAME IS NOT. `showBehaviourComment` was
                asked for by primary, which prints no behaviour remark; the Boarding
                Parent's NAME is an attribution the same school still wants, so only
                the comment rows below it are suppressed. Defaults true (the payload
                key is absent until it arrives), so secondary is untouched.
            */}
            {hasBoarding ? (
                <>
                    <DetailRow
                        label="Boarding Parent's Name:"
                        value={boardingParentName}
                    />
                    {showBehaviourComment && (
                        <DetailRow
                            label="Boarding Parent Comment:"
                            value={
                                boardingParentName ? assessmentComment : null
                            }
                        />
                    )}
                </>
            ) : (
                showBehaviourComment && (
                    <DetailRow
                        label="Behaviour Comment:"
                        value={assessmentComment}
                    />
                )
            )}
            {/*
                Off for primary, where the Head of School APPROVES with a signature
                rather than commenting — the signature block below carries them.
                Defaults true, so secondary is untouched.
            */}
            {showHeadOfSchoolComment && (
                <>
                    <DetailRow
                        label="Head of School Name:"
                        value={scDetails?.headOfSchool?.full_name}
                    />
                    <DetailRow
                        label="Head of School Comment:"
                        value={
                            scDetails?.studentCurriculum?.head_of_school_comment
                        }
                    />
                </>
            )}
        </>
    );
}

function SubjectRow({
    r,
    i,
    boundaries,
    showComment,
}: {
    r: ResultRow;
    i: number;
    boundaries: GradeBoundary[];
    showComment: boolean;
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
        setExamScore(
            examination?.score
                ? String(Number(examination?.score).toFixed(1))
                : '-',
        );
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
                {convertNameToResultFmt(
                    teachers?.[0]?.teacher?.full_name ?? '',
                )}
            </td>
            {showComment && (
                <td className="border border-slate-300 px-1 text-left text-slate-600 tabular-nums">
                    {/* comment */}
                    {r.comment}
                </td>
            )}
        </tr>
    );
}

export function CurriculumCardFinal({ ...props }: CurriculumCardFinalProps) {
    if (props.sc.curriculum.grading_mode === 'categorical') {
        return <CategoricalCurriculumCard {...props} />;
    }

    return <NumericCurriculumCardFinal {...props} />;
}

function NumericCurriculumCardFinal({
    sc,
    defaultBoundaries,
    student,
    boundaries,
    resultSignature,
}: CurriculumCardFinalProps) {
    const { auth } = usePage().props;
    const roles = auth.roles;
    const [scDetails, setScDetails] = useState<any | null>(null);
    // Defaults TRUE while the payload is in flight, so the column does not appear
    // and then vanish on a school that prints it.
    const showSubjectComments = scDetails?.showSubjectComments !== false;
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
    }, [sc, boundaries, defaultBoundaries]);
    const isGuardian = roles.includes('guardian');
    const hasIncompleteResults = rows.some((r) => r.score === null);
    const resultsIncomplete = isGuardian && hasIncompleteResults;

    const currentClass = student.class_details.full_class.split(' ');
    const promotedClass = Number(currentClass[1]) + 1;
    const markingComponents =
        sc.subjects?.[0]?.curriculum_subject?.marking_components ?? [];
    const examinationWeight = Number(
        markingComponents.find(
            (component) => component.name.toLowerCase() === 'examination',
        )?.weight ?? 0.7,
    );
    const examinationPercentage = Math.round(
        examinationWeight <= 1 ? examinationWeight * 100 : examinationWeight,
    );
    const caPercentage = 100 - examinationPercentage;

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
                                <div>{caPercentage}%</div>
                            </th>
                            <th className="border border-slate-300 px-1 text-center font-semibold">
                                <div>Exam</div>
                                <div>{examinationPercentage}%</div>
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
                            {/*
                                Primary prints no per-subject teacher comments —
                                only the class teacher writes on the report. The
                                column is dropped entirely rather than blanked, so
                                the remaining columns take the width back.
                            */}
                            {showSubjectComments && (
                                <th className="border border-slate-300 px-1 text-center font-semibold">
                                    Comments
                                </th>
                            )}
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
                                    showComment={showSubjectComments}
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
                <AttributionRows scDetails={scDetails} />
                {resultSignature && (
                    <>
                        <div className="col-span-1 border font-bold">
                            {resultSignature.label}
                        </div>
                        <div className="col-span-3 border">
                            <ResultSignatureBlock
                                signature={resultSignature}
                                showCaption={false}
                            />
                        </div>
                    </>
                )}
                {sc.curriculum.term?.is_last_term && promotedClass <= 12 && (
                    <div className="col-span-4 border text-center text-sm font-bold">
                        Promoted To Year {promotedClass}
                    </div>
                )}
            </div>
        </div>
    );
}

function CategoricalCurriculumCard({
    sc,
    student,
    resultSignature,
}: CurriculumCardFinalProps) {
    const [scDetails, setScDetails] = useState<any | null>(null);
    useEffect(() => {
        const getScDetails = async (scId: string) => {
            const response = await axios.get(`/api/student-curricula/${scId}`);
            setScDetails(response.data);
        };
        getScDetails(sc.id);
    }, [sc]);

    const rows = (sc.subjects ?? [])
        .slice()
        .sort(
            (left, right) =>
                (left.curriculum_subject?.display_order ?? 0) -
                (right.curriculum_subject?.display_order ?? 0),
        );
    const items = sc.curriculum.grading_scheme?.items ?? [];
    const currentClass = student.class_details.full_class.split(' ');
    const promotedClass = Number(currentClass[1]) + 1;

    return (
        <div className="student-result-card overflow-hidden border border-slate-300">
            {/*
                Same five identifying fields the numeric card prints. The
                categorical card carried only Name and Class, so a printed
                progress-rating sheet could not be matched back to a pupil by
                admission number — the one field the office actually files by.
                grid-cols-3 mirrors the numeric header so the two print alike.
            */}
            <div className="grid grid-cols-3 bg-blue-100 text-xs text-black">
                <p className="border border-slate-300 p-1">
                    <strong>Name: </strong>
                    {student.last_name}, {student.first_name}{' '}
                    {student.middle_name}
                </p>
                <p className="border border-slate-300 p-1">
                    <strong>Admission No: </strong>
                    {student.admission_number}
                </p>
                <p className="border border-slate-300 p-1">
                    <strong>Date Of Birth: </strong>
                    {student.date_of_birth
                        ? fmtDate(student.date_of_birth)
                        : '—'}
                </p>
                <p className="border border-slate-300 p-1">
                    <strong>Year Group: </strong>
                    {student.class_details.full_class}
                </p>
                <p className="border border-slate-300 p-1">
                    <strong>Sport House: </strong>
                    {student.sport_house?.name}
                </p>
            </div>
            <table className="w-full border-collapse text-xs">
                <thead>
                    <tr className="bg-blue-100 text-black">
                        <th className="border border-slate-300 px-2 py-1 text-left">
                            Subjects
                        </th>
                        <th className="border border-slate-300 px-2 py-1 text-center">
                            Evaluation
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((subject) => (
                        <tr key={subject.id}>
                            <td className="border border-slate-300 px-2 py-1">
                                {subject.curriculum_subject?.subject?.name}
                            </td>
                            {/*
                                Code alone, and it is now the row's only content
                                besides the subject. The Subject Teacher and
                                Comments columns that used to sit beside it were
                                removed at the school's instruction: subject
                                teachers do not comment on this report, and only
                                ONE comment — the form tutor's overall remark in
                                the attribution block below — is allowed on the
                                sheet. The code's meaning is carried by the
                                code/label legend printed under the table, so
                                nothing is lost by not repeating it per row.
                            */}
                            <td className="border border-slate-300 px-2 py-1 text-center font-bold">
                                {subject.own_result?.grading_item?.code ??
                                    'Not assessed'}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
            <div className="flex flex-wrap gap-3 border-t border-slate-300 p-2 text-xs">
                {items.map((item) => (
                    <span key={item.id}>
                        <strong>{item.code}</strong> — {item.label}
                    </span>
                ))}
            </div>
            <div className="grid grid-cols-2 gap-4 p-2">
                <BehavioralAssessmentTable
                    assessment={scDetails?.behavioralAssessments?.[0]}
                />
                <PsychomotorSkillsTable
                    skill={scDetails?.psychomotorSkills?.[0]}
                />
            </div>
            <div className="grid grid-cols-4 text-xs">
                <AttributionRows scDetails={scDetails} />
                {resultSignature && (
                    <>
                        <div className="col-span-1 border font-bold">
                            {resultSignature.label}
                        </div>
                        <div className="col-span-3 border">
                            <ResultSignatureBlock
                                signature={resultSignature}
                                showCaption={false}
                            />
                        </div>
                    </>
                )}
                {sc.curriculum.term?.is_last_term && promotedClass <= 12 && (
                    <div className="col-span-4 border text-center text-sm font-bold">
                        Promoted To Year {promotedClass}
                    </div>
                )}
            </div>
        </div>
    );
}
