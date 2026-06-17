import { Head } from '@inertiajs/react';
import axios from 'axios';
import { ChevronDown, ChevronUp, Heart } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import EmptyState from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/spinner';
import { formatDate, snakeToTitleCase } from '@/hooks/use-helper';
import { useInitials } from '@/hooks/use-initials';
import type { BehavioralAssessment, BehavioralGrade, Student } from '@/types/models';

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

const PILLARS = [
    'punctuality',
    'mental_alertness',
    'respect',
    'neatness',
    'politeness',
    'honesty',
    'relationship_with_peers',
    'teamwork',
    'perseverance',
] as const;

type Pillar = (typeof PILLARS)[number];

const GRADES: BehavioralGrade[] = ['A', 'B', 'C', 'D', 'E'];

const GRADE_MAPPING = {
    'A': 'Excellent',
    'B': 'Very Good',
    'C': 'Good',
    'D': 'Below Average',
    'E': 'Poor'
};

type PillarGrades = Record<Pillar, BehavioralGrade>;

const defaultGrades: PillarGrades = PILLARS.reduce((acc, pillar) => {
    acc[pillar] = 'B';

    return acc;
}, {} as PillarGrades);

interface AssessmentRow {
    student_curriculum_id: string;
    student: Student;
    class_name: string | null;
    assessment: BehavioralAssessment | null;
}

interface FormState {
    grades: PillarGrades;
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

// ---------------------------------------------------------------------------
// Assessment Card
// ---------------------------------------------------------------------------

interface AssessmentCardProps {
    row: AssessmentRow;
    form: FormState;
    expanded: boolean;
    saving: boolean;
    onToggle: () => void;
    onChange: (form: FormState) => void;
    onSave: () => void;
}

function AssessmentCard({ row, form, expanded, saving, onToggle, onChange, onSave }: AssessmentCardProps) {
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
                        {getInitials(`${student.first_name} ${student.last_name}`)}
                    </AvatarFallback>
                </Avatar>
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium text-gray-900">
                        {student.first_name} {student.last_name}
                    </p>
                    <p className="text-xs text-gray-400">{student.admission_number}</p>
                </div>
                {assessment ? (
                    <Badge variant="secondary" className="bg-emerald-50 text-emerald-700">
                        Assessed {formatDate(assessment.updated_at, 'd MMM')}
                    </Badge>
                ) : (
                    <Badge variant="outline" className="text-gray-500">
                        Not assessed
                    </Badge>
                )}
                {expanded ? <ChevronUp className="h-4 w-4 text-gray-400" /> : <ChevronDown className="h-4 w-4 text-gray-400" />}
            </button>

            {expanded && (
                <div className="space-y-4 border-t border-gray-100 p-4">
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        {PILLARS.map((pillar) => (
                            <label key={pillar} className="block">
                                <span className="mb-1 block text-xs font-medium text-gray-600">
                                    {snakeToTitleCase(pillar)}
                                </span>
                                <select
                                    value={form.grades[pillar]}
                                    onChange={(e) =>
                                        onChange({
                                            ...form,
                                            grades: { ...form.grades, [pillar]: e.target.value as BehavioralGrade },
                                        })
                                    }
                                    className="w-full rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 focus:outline-none"
                                >
                                    {GRADES.map((grade) => (
                                        <option key={grade} value={grade}>
                                            {grade} - {GRADE_MAPPING[grade]}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        ))}
                    </div>

                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-gray-600">Comment</span>
                        <textarea
                            value={form.comment}
                            onChange={(e) => onChange({ ...form, comment: e.target.value })}
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
    const [loading, setLoading] = useState(true);
    const [expanded, setExpanded] = useState<Record<string, boolean>>({});
    const [savingId, setSavingId] = useState<string | null>(null);

    useEffect(() => {
        async function fetchData() {
            setLoading(true);

            try {
                const res = await axios.get('/api/behavioral-assessments');
                const data: AssessmentRow[] = res.data.data ?? [];

                setRows(data);
                setForms(Object.fromEntries(data.map((row) => [row.student_curriculum_id, rowToFormState(row)])));
            } catch {
                toast.error('Failed to load students.');
            } finally {
                setLoading(false);
            }
        }

        fetchData();
    }, []);

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
                prev.map((r) => (r.student_curriculum_id === row.student_curriculum_id ? { ...r, assessment: updated } : r)),
            );
            toast.success(`Saved assessment for ${row.student.first_name} ${row.student.last_name}.`);
        } catch {
            toast.error('Failed to save assessment.');
        } finally {
            setSavingId(null);
        }
    }

    return (
        <>
            <Head title="Behavioral Assessments" />
            <div className="space-y-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold text-gray-900">Behavioral Assessments</h1>
                    <p className="mt-1 text-sm text-gray-500">
                        Record this term's behavioral pillar grades for the students in your care.
                    </p>
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
                                <CardTitle className="text-base">{className}</CardTitle>
                                <p className="text-sm text-muted-foreground">
                                    {classRows.length} student{classRows.length !== 1 ? 's' : ''}
                                </p>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                {classRows.map((row) => (
                                    <AssessmentCard
                                        key={row.student_curriculum_id}
                                        row={row}
                                        form={forms[row.student_curriculum_id] ?? rowToFormState(row)}
                                        expanded={!!expanded[row.student_curriculum_id]}
                                        saving={savingId === row.student_curriculum_id}
                                        onToggle={() =>
                                            setExpanded((prev) => ({
                                                ...prev,
                                                [row.student_curriculum_id]: !prev[row.student_curriculum_id],
                                            }))
                                        }
                                        onChange={(form) =>
                                            setForms((prev) => ({ ...prev, [row.student_curriculum_id]: form }))
                                        }
                                        onSave={() => handleSave(row)}
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
