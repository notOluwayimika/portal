import axios from 'axios';
import { Save } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { AssessmentGradeFields } from '@/components/assessment-grade-fields';
import { Button } from '@/components/ui/button';
import Modal from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/spinner';
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

export interface AssessableRow {
    student_curriculum_id: string;
    student: Student;
    uses_categorical_grading: boolean;
    assessment: BehavioralAssessment | null;
    psychomotor: PsychomotorSkill | null;
}

type Tab = 'behavioral' | 'psychomotor';

interface GradeForm<F extends string> {
    grades: Record<F, BehavioralGrade | ''>;
    comment: string;
}

function buildForm<F extends string>(
    fields: readonly F[],
    source: Partial<Record<F, BehavioralGrade>> | null,
    comment: string | null | undefined,
): GradeForm<F> {
    const grades = Object.fromEntries(
        fields.map((field) => [field, source?.[field] ?? '']),
    ) as Record<F, BehavioralGrade | ''>;

    return { grades, comment: comment ?? '' };
}

function allSelected<F extends string>(form: GradeForm<F>): boolean {
    return Object.values(form.grades).every((grade) => grade !== '');
}

/**
 * Per-student assessment editor used by form teachers when the school has
 * no boarding parents. Behavioral assessment is always available; the
 * psychomotor tab only appears for categorical-grading curricula.
 */
export function AssessmentModal({
    row,
    isOpen,
    onClose,
    onSaved,
}: {
    row: AssessableRow;
    isOpen: boolean;
    onClose: () => void;
    onSaved: (updates: {
        assessment?: BehavioralAssessment;
        psychomotor?: PsychomotorSkill;
    }) => void;
}) {
    const [activeTab, setActiveTab] = useState<Tab>('behavioral');
    const [saving, setSaving] = useState(false);
    const [behavioral, setBehavioral] = useState<GradeForm<Pillar>>(() =>
        buildForm(PILLARS, row.assessment, row.assessment?.comment),
    );
    const [psychomotor, setPsychomotor] = useState<
        GradeForm<PsychomotorCategory>
    >(() =>
        buildForm(
            PSYCHOMOTOR_CATEGORIES,
            row.psychomotor,
            row.psychomotor?.comment,
        ),
    );

    const tabs: { key: Tab; label: string }[] = [
        { key: 'behavioral', label: 'Behavioral Assessment' },
        ...(row.uses_categorical_grading
            ? [{ key: 'psychomotor' as Tab, label: 'Psychomotor Skills' }]
            : []),
    ];

    const canSave =
        (activeTab === 'behavioral'
            ? allSelected(behavioral)
            : allSelected(psychomotor)) && !saving;

    async function handleSave() {
        setSaving(true);

        try {
            if (activeTab === 'behavioral') {
                const res = await axios.post('/api/behavioral-assessments', {
                    student_curriculum_id: row.student_curriculum_id,
                    ...behavioral.grades,
                    comment: behavioral.comment || null,
                });
                onSaved({ assessment: res.data.data });
                toast.success('Behavioral assessment saved.');
            } else {
                const res = await axios.post('/api/psychomotor-skills', {
                    student_curriculum_id: row.student_curriculum_id,
                    ...psychomotor.grades,
                    comment: psychomotor.comment || null,
                });
                onSaved({ psychomotor: res.data.data });
                toast.success('Psychomotor skills saved.');
            }
        } catch (error) {
            const message = axios.isAxiosError(error)
                ? error.response?.data?.message
                : null;
            toast.error(message || 'Failed to save assessment.');
        } finally {
            setSaving(false);
        }
    }

    return (
        <Modal
            isOpen={isOpen}
            onClose={onClose}
            title={`Assess ${row.student.first_name} ${row.student.last_name}`}
            size="3xl"
            footer={
                <div className="flex justify-end gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={onClose}
                        disabled={saving}
                    >
                        Close
                    </Button>
                    <Button type="button" onClick={handleSave} disabled={!canSave}>
                        {saving ? (
                            <Spinner className="mr-2 h-4 w-4 animate-spin" />
                        ) : (
                            <Save className="mr-2 h-4 w-4" />
                        )}
                        {activeTab === 'behavioral'
                            ? 'Save Behavioral Assessment'
                            : 'Save Psychomotor Skills'}
                    </Button>
                </div>
            }
        >
            <div className="space-y-4">
                {tabs.length > 1 && (
                    <div className="inline-flex gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800">
                        {tabs.map((tab) => (
                            <button
                                key={tab.key}
                                type="button"
                                onClick={() => setActiveTab(tab.key)}
                                className={`rounded-md px-3.5 py-1.5 text-sm font-medium transition-colors ${
                                    activeTab === tab.key
                                        ? 'bg-white text-gray-900 shadow-xs dark:bg-neutral-700 dark:text-white'
                                        : 'text-gray-500 hover:text-gray-700 dark:text-neutral-400 dark:hover:text-neutral-200'
                                }`}
                            >
                                {tab.label}
                            </button>
                        ))}
                    </div>
                )}

                {activeTab === 'behavioral' ? (
                    <div className="space-y-4">
                        <AssessmentGradeFields
                            fields={PILLARS}
                            values={behavioral.grades}
                            onChange={(field, value) =>
                                setBehavioral((prev) => ({
                                    ...prev,
                                    grades: { ...prev.grades, [field]: value },
                                }))
                            }
                        />
                        <label className="block">
                            <span className="mb-1 block text-xs font-medium text-gray-600">
                                Comment
                            </span>
                            <textarea
                                value={behavioral.comment}
                                onChange={(e) =>
                                    setBehavioral((prev) => ({
                                        ...prev,
                                        comment: e.target.value,
                                    }))
                                }
                                rows={3}
                                placeholder="Optional remarks about this student's behavior this term…"
                                className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 focus:outline-none"
                            />
                        </label>
                    </div>
                ) : (
                    <div className="space-y-4">
                        <AssessmentGradeFields
                            fields={PSYCHOMOTOR_CATEGORIES}
                            labels={PSYCHOMOTOR_LABELS}
                            values={psychomotor.grades}
                            onChange={(field, value) =>
                                setPsychomotor((prev) => ({
                                    ...prev,
                                    grades: { ...prev.grades, [field]: value },
                                }))
                            }
                        />
                        <label className="block">
                            <span className="mb-1 block text-xs font-medium text-gray-600">
                                Comment
                            </span>
                            <textarea
                                value={psychomotor.comment}
                                onChange={(e) =>
                                    setPsychomotor((prev) => ({
                                        ...prev,
                                        comment: e.target.value,
                                    }))
                                }
                                rows={3}
                                placeholder="Optional remarks about this student's psychomotor skills this term…"
                                className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 focus:outline-none"
                            />
                        </label>
                    </div>
                )}
            </div>
        </Modal>
    );
}
