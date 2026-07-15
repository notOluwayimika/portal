import { Head } from '@inertiajs/react';
import axios from 'axios';
import { ChevronDown, ChevronUp, Heart } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { AssessmentGradeFields } from '@/components/assessment-grade-fields';
import { TermFilterSelect } from '@/components/term-filter-select';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import EmptyState from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/spinner';
import { formatDate } from '@/hooks/use-helper';
import { useInitials } from '@/hooks/use-initials';
import {
    PILLARS,
    PSYCHOMOTOR_CATEGORIES,
    PSYCHOMOTOR_LABELS
    
    
} from '@/lib/assessment';
import type {Pillar, PsychomotorCategory} from '@/lib/assessment';
import type {
    BehavioralAssessment,
    BehavioralGrade,
    PsychomotorSkill,
    Student,
} from '@/types/models';

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

type PillarGrades = Record<Pillar, BehavioralGrade | ''>;
type PsychomotorGrades = Record<PsychomotorCategory, BehavioralGrade | ''>;

const defaultGrades: PillarGrades = PILLARS.reduce((acc, pillar) => {
    acc[pillar] = '';

    return acc;
}, {} as PillarGrades);

const defaultPsychomotorGrades: PsychomotorGrades = PSYCHOMOTOR_CATEGORIES.reduce(
    (acc, category) => {
        acc[category] = '';

        return acc;
    },
    {} as PsychomotorGrades,
);

interface AssessmentRow {
    student_curriculum_id: string;
    student: Student;
    class_name: string | null;
    assessment: BehavioralAssessment | null;
    uses_categorical_grading: boolean;
    psychomotor: PsychomotorSkill | null;
}

interface FormState {
    grades: PillarGrades;
    comment: string;
}

interface PsychomotorFormState {
    grades: PsychomotorGrades;
    comment: string;
}

function rowToFormState(row: AssessmentRow): FormState {
    if (!row.assessment) {
        return { grades: { ...defaultGrades }, comment: '' };
    }

    const grades = PILLARS.reduce((acc, pillar) => {
        acc[pillar] = row.assessment![pillar];

        return acc;
    }, {} as PillarGrades);

    return { grades, comment: row.assessment.comment ?? '' };
}

function rowToPsychomotorFormState(row: AssessmentRow): PsychomotorFormState {
    if (!row.psychomotor) {
        return { grades: { ...defaultPsychomotorGrades }, comment: '' };
    }

    const grades = PSYCHOMOTOR_CATEGORIES.reduce((acc, category) => {
        acc[category] = row.psychomotor![category];

        return acc;
    }, {} as PsychomotorGrades);

    return { grades, comment: row.psychomotor.comment ?? '' };
}

// ---------------------------------------------------------------------------
// Assessment Card
// ---------------------------------------------------------------------------

interface AssessmentCardProps {
    row: AssessmentRow;
    form: FormState;
    psychomotorForm: PsychomotorFormState;
    expanded: boolean;
    saving: boolean;
    savingPsychomotor: boolean;
    onToggle: () => void;
    onChange: (form: FormState) => void;
    onPsychomotorChange: (form: PsychomotorFormState) => void;
    onSave: () => void;
    onSavePsychomotor: () => void;
}

function AssessmentCard({
    row,
    form,
    psychomotorForm,
    expanded,
    saving,
    savingPsychomotor,
    onToggle,
    onChange,
    onPsychomotorChange,
    onSave,
    onSavePsychomotor,
}: AssessmentCardProps) {
    const getInitials = useInitials();
    const { student, assessment } = row;

    return (
        <div className="rounded-xl border border-gray-200">
            <button
                type="button"
                onClick={onToggle}
                className="flex w-full items-center gap-3 p-3 text-left"
            >
                <Avatar>
                    <AvatarImage src={student.photo ?? undefined} />
                    <AvatarFallback className="bg-indigo-100 text-sm font-semibold text-indigo-700">
                        {getInitials(
                            `${student.first_name} ${student.last_name}`,
                        )}
                    </AvatarFallback>
                </Avatar>
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium text-gray-900">
                        {student.first_name} {student.last_name}
                    </p>
                    <p className="text-xs text-gray-400">
                        {student.admission_number}
                    </p>
                </div>
                {assessment ? (
                    <Badge
                        variant="secondary"
                        className="bg-emerald-50 text-emerald-700"
                    >
                        Assessed {formatDate(assessment.updated_at, 'd MMM')}
                    </Badge>
                ) : (
                    <Badge variant="outline" className="text-gray-500">
                        Not assessed
                    </Badge>
                )}
                {expanded ? (
                    <ChevronUp className="h-4 w-4 text-gray-400" />
                ) : (
                    <ChevronDown className="h-4 w-4 text-gray-400" />
                )}
            </button>

            {expanded && (
                <div className="space-y-4 border-t border-gray-100 p-4">
                    <AssessmentGradeFields
                        fields={PILLARS}
                        values={form.grades}
                        onChange={(pillar, value) =>
                            onChange({
                                ...form,
                                grades: { ...form.grades, [pillar]: value },
                            })
                        }
                    />

                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-gray-600">
                            Comment
                        </span>
                        <textarea
                            value={form.comment}
                            onChange={(e) =>
                                onChange({ ...form, comment: e.target.value })
                            }
                            rows={3}
                            placeholder="Optional remarks about this student's behavior this term…"
                            className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 focus:outline-none"
                        />
                    </label>

                    <div className="flex justify-end">
                        <Button onClick={onSave} disabled={saving}>
                            {saving && <Spinner className="size-4" />}
                            Save Assessment
                        </Button>
                    </div>

                    {row.uses_categorical_grading && (
                        <div className="space-y-4 border-t border-gray-100 pt-4">
                            <div>
                                <p className="text-sm font-semibold text-gray-800">
                                    Psychomotor Skills
                                </p>
                                <p className="text-xs text-gray-400">
                                    Recorded for categorical-grading classes
                                    alongside the behavioral assessment.
                                </p>
                            </div>

                            <AssessmentGradeFields
                                fields={PSYCHOMOTOR_CATEGORIES}
                                labels={PSYCHOMOTOR_LABELS}
                                values={psychomotorForm.grades}
                                onChange={(category, value) =>
                                    onPsychomotorChange({
                                        ...psychomotorForm,
                                        grades: {
                                            ...psychomotorForm.grades,
                                            [category]: value,
                                        },
                                    })
                                }
                            />

                            <label className="block">
                                <span className="mb-1 block text-xs font-medium text-gray-600">
                                    Comment
                                </span>
                                <textarea
                                    value={psychomotorForm.comment}
                                    onChange={(e) =>
                                        onPsychomotorChange({
                                            ...psychomotorForm,
                                            comment: e.target.value,
                                        })
                                    }
                                    rows={3}
                                    placeholder="Optional remarks about this student's psychomotor skills this term…"
                                    className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 focus:outline-none"
                                />
                            </label>

                            <div className="flex justify-end">
                                <Button
                                    onClick={onSavePsychomotor}
                                    disabled={savingPsychomotor}
                                >
                                    {savingPsychomotor && (
                                        <Spinner className="size-4" />
                                    )}
                                    Save Psychomotor Skills
                                </Button>
                            </div>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

// ---------------------------------------------------------------------------
// Main Page
// ---------------------------------------------------------------------------

export default function BehavioralAssessmentsIndex() {
    const [rows, setRows] = useState<AssessmentRow[]>([]);
    const [forms, setForms] = useState<Record<string, FormState>>({});
    const [psychomotorForms, setPsychomotorForms] = useState<
        Record<string, PsychomotorFormState>
    >({});
    const [loading, setLoading] = useState(true);
    const [expanded, setExpanded] = useState<Record<string, boolean>>({});
    const [savingId, setSavingId] = useState<string | null>(null);
    const [savingPsychomotorId, setSavingPsychomotorId] = useState<
        string | null
    >(null);
    const [termId, setTermId] = useState('');

    useEffect(() => {
        async function fetchData() {
            setLoading(true);

            try {
                const res = await axios.get('/api/behavioral-assessments', {
                    params: termId ? { term_id: termId } : {},
                });
                const data: AssessmentRow[] = res.data.data ?? [];

                setRows(data);
                setForms(
                    Object.fromEntries(
                        data.map((row) => [
                            row.student_curriculum_id,
                            rowToFormState(row),
                        ]),
                    ),
                );
                setPsychomotorForms(
                    Object.fromEntries(
                        data.map((row) => [
                            row.student_curriculum_id,
                            rowToPsychomotorFormState(row),
                        ]),
                    ),
                );
            } catch {
                toast.error('Failed to load students.');
            } finally {
                setLoading(false);
            }
        }

        fetchData();
    }, [termId]);

    const grouped = useMemo(() => {
        const map = new Map<string, AssessmentRow[]>();

        for (const row of rows) {
            const key = row.class_name ?? 'Unassigned';

            if (!map.has(key)) {
                map.set(key, []);
            }

            map.get(key)!.push(row);
        }

        return Array.from(map.entries());
    }, [rows]);

    async function handleSave(row: AssessmentRow) {
        const form = forms[row.student_curriculum_id];

        if (!form) {
            return;
        }

        setSavingId(row.student_curriculum_id);

        try {
            const res = await axios.post('/api/behavioral-assessments', {
                student_curriculum_id: row.student_curriculum_id,
                ...form.grades,
                comment: form.comment || null,
            });

            const updated: BehavioralAssessment = res.data.data;

            setRows((prev) =>
                prev.map((r) =>
                    r.student_curriculum_id === row.student_curriculum_id
                        ? { ...r, assessment: updated }
                        : r,
                ),
            );
            toast.success(
                `Saved assessment for ${row.student.first_name} ${row.student.last_name}.`,
            );
        } catch {
            toast.error('Failed to save assessment.');
        } finally {
            setSavingId(null);
        }
    }

    async function handleSavePsychomotor(row: AssessmentRow) {
        const form = psychomotorForms[row.student_curriculum_id];

        if (!form) {
            return;
        }

        setSavingPsychomotorId(row.student_curriculum_id);

        try {
            const res = await axios.post('/api/psychomotor-skills', {
                student_curriculum_id: row.student_curriculum_id,
                ...form.grades,
                comment: form.comment || null,
            });

            const updated: PsychomotorSkill = res.data.data;

            setRows((prev) =>
                prev.map((r) =>
                    r.student_curriculum_id === row.student_curriculum_id
                        ? { ...r, psychomotor: updated }
                        : r,
                ),
            );
            toast.success(
                `Saved psychomotor skills for ${row.student.first_name} ${row.student.last_name}.`,
            );
        } catch {
            toast.error('Failed to save psychomotor skills.');
        } finally {
            setSavingPsychomotorId(null);
        }
    }

    return (
        <>
            <Head title="Behavioral Assessments" />
            <div className="space-y-6 p-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h1 className="text-xl font-semibold text-gray-900">
                            Behavioral Assessments
                        </h1>
                        <p className="mt-1 text-sm text-gray-500">
                            Record the behavioral pillar grades for the
                            students in your care for the selected term.
                        </p>
                    </div>
                    <TermFilterSelect value={termId} onChange={setTermId} />
                </div>

                {loading ? (
                    <div className="flex items-center justify-center py-24">
                        <Spinner className="size-6 text-gray-400" />
                    </div>
                ) : rows.length === 0 ? (
                    <EmptyState
                        icon={<Heart className="h-8 w-8" />}
                        title="No students to assess"
                        description="You don't have any boarding parent assignments for the current term yet, or no students are currently enrolled in your assigned arm(s)."
                    />
                ) : (
                    grouped.map(([className, classRows]) => (
                        <Card key={className}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    {className}
                                </CardTitle>
                                <p className="text-sm text-muted-foreground">
                                    {classRows.length} student
                                    {classRows.length !== 1 ? 's' : ''}
                                </p>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                {classRows.map((row) => (
                                    <AssessmentCard
                                        key={row.student_curriculum_id}
                                        row={row}
                                        form={
                                            forms[row.student_curriculum_id] ??
                                            rowToFormState(row)
                                        }
                                        psychomotorForm={
                                            psychomotorForms[
                                                row.student_curriculum_id
                                            ] ?? rowToPsychomotorFormState(row)
                                        }
                                        expanded={
                                            !!expanded[
                                                row.student_curriculum_id
                                            ]
                                        }
                                        saving={
                                            savingId ===
                                            row.student_curriculum_id
                                        }
                                        savingPsychomotor={
                                            savingPsychomotorId ===
                                            row.student_curriculum_id
                                        }
                                        onToggle={() =>
                                            setExpanded((prev) => ({
                                                ...prev,
                                                [row.student_curriculum_id]:
                                                    !prev[
                                                        row
                                                            .student_curriculum_id
                                                    ],
                                            }))
                                        }
                                        onChange={(form) =>
                                            setForms((prev) => ({
                                                ...prev,
                                                [row.student_curriculum_id]:
                                                    form,
                                            }))
                                        }
                                        onPsychomotorChange={(form) =>
                                            setPsychomotorForms((prev) => ({
                                                ...prev,
                                                [row.student_curriculum_id]:
                                                    form,
                                            }))
                                        }
                                        onSave={() => handleSave(row)}
                                        onSavePsychomotor={() =>
                                            handleSavePsychomotor(row)
                                        }
                                    />
                                ))}
                            </CardContent>
                        </Card>
                    ))
                )}
            </div>
        </>
    );
}
