import axios from 'axios';
import { Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ToastType } from '@/components/toast-item';
import { Button } from '@/components/ui/button';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { Spinner } from '@/components/ui/spinner';
import type { Teacher, TeacherSubjectAssignment } from '@/types/models';

interface CurriculumOption {
    id: number;
    uuid: string;
    class_level: string;
    arm: string;
    stream?: string;
    full_name: string;
}

interface CurriculumSubjectOption {
    id: string;
    subject_name: string;
    subject_code?: string;
    is_compulsory: boolean;
}

interface Props {
    teacher: Teacher;
    curricula: CurriculumOption[];
    addToast: (message: string, type?: ToastType) => void;
}

export function TeacherSubjectsModal({ teacher, curricula, addToast }: Props) {
    const [assignments, setAssignments] = useState<TeacherSubjectAssignment[]>(
        [],
    );
    const [loading, setLoading] = useState(true);
    const [selectedCurriculum, setSelectedCurriculum] =
        useState<CurriculumOption | null>(null);
    const [subjectOptions, setSubjectOptions] = useState<
        CurriculumSubjectOption[]
    >([]);
    const [selectedSubject, setSelectedSubject] =
        useState<CurriculumSubjectOption | null>(null);
    const [assigning, setAssigning] = useState(false);
    const [removing, setRemoving] = useState<string | null>(null);
    const [subjectsLoading, setSubjectsLoading] = useState(false);
    const [filterClass, setFilterClass] = useState<string>('all');

    useEffect(() => {
        axios
            .get(`/api/teachers/${teacher.id}/subjects`)
            .then((res) => setAssignments(res.data))
            .catch(() =>
                addToast('Failed to load subject assignments', 'error'),
            )
            .finally(() => setLoading(false));
    }, [teacher.id]);

    useEffect(() => {
        if (!selectedCurriculum) {
            setSubjectOptions([]);
            setSelectedSubject(null);
            return;
        }
        setSubjectsLoading(true);
        axios
            .get(`/api/curricula/${selectedCurriculum.uuid}/subjects`)
            .then((res) => setSubjectOptions(res.data.data || []))
            .catch(() => addToast('Failed to load subjects', 'error'))
            .finally(() => setSubjectsLoading(false));
    }, [selectedCurriculum]);

    const assignedIds = new Set(
        assignments.map((a) => a.curriculum_subject.id),
    );

    const classOptions = Array.from(
        new Map(
            assignments
                .map((a) => a.curriculum_subject.curriculum?.class_level_arm)
                .filter(Boolean)
                .map((arm) => [arm!.name, arm!.name]),
        ).entries(),
    ).sort(([a], [b]) => a.localeCompare(b));

    const visibleAssignments =
        filterClass === 'all'
            ? assignments
            : assignments.filter(
                  (a) =>
                      a.curriculum_subject.curriculum?.class_level_arm?.name ===
                      filterClass,
              );

    const handleAssign = async () => {
        if (!selectedSubject) return;
        setAssigning(true);
        try {
            await axios.post(`/api/teachers/${teacher.id}/subjects`, {
                curriculum_subject_id: selectedSubject.id,
            });
            const res = await axios.get(`/api/teachers/${teacher.id}/subjects`);
            setAssignments(res.data);
            setSelectedSubject(null);
            addToast('Subject assigned successfully');
        } catch (err: any) {
            const msg =
                err?.response?.data?.message ||
                err?.response?.data?.errors?.curriculum_subject_id?.[0];
            addToast(msg || 'Failed to assign subject', 'error');
        } finally {
            setAssigning(false);
        }
    };

    const handleRemove = async (assignmentId: string) => {
        setRemoving(assignmentId);
        try {
            await axios.delete(
                `/api/teachers/${teacher.id}/subjects/${assignmentId}`,
            );
            setAssignments((prev) => prev.filter((a) => a.id !== assignmentId));
            addToast('Subject removed successfully');
        } catch {
            addToast('Failed to remove subject', 'error');
        } finally {
            setRemoving(null);
        }
    };

    const curriculaSelectOptions = curricula.map((c) => ({
        value: c.uuid,
        label: c.full_name,
        data: c,
    }));

    const filteredSubjectOptions = subjectOptions
        .filter((s) => !assignedIds.has(s.id))
        .map((s) => ({
            value: s.id,
            label: `${s.subject_name}${s.subject_code ? ` (${s.subject_code})` : ''}`,
            data: s,
        }));

    return (
        <div className="space-y-6">
            <div className="space-y-3 rounded-lg border p-4">
                <h3 className="text-sm font-medium">Assign a Subject</h3>
                <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <SearchableSelect
                        placeholder="Select class / curriculum..."
                        options={curriculaSelectOptions}
                        value={
                            selectedCurriculum
                                ? curriculaSelectOptions.find(
                                      (o) =>
                                          o.value === selectedCurriculum.uuid,
                                  )
                                : null
                        }
                        onChange={(opt: any) => {
                            setSelectedCurriculum(opt ? opt.data : null);
                            setSelectedSubject(null);
                        }}
                    />
                    <SearchableSelect
                        placeholder={
                            subjectsLoading
                                ? 'Loading subjects…'
                                : 'Select subject…'
                        }
                        options={filteredSubjectOptions}
                        value={
                            selectedSubject
                                ? (filteredSubjectOptions.find(
                                      (o) => o.value === selectedSubject.id,
                                  ) ?? null)
                                : null
                        }
                        onChange={(opt: any) =>
                            setSelectedSubject(opt ? opt.data : null)
                        }
                        isDisabled={!selectedCurriculum || subjectsLoading}
                    />
                </div>
                <Button
                    type="button"
                    size="sm"
                    onClick={handleAssign}
                    disabled={!selectedSubject || assigning}
                >
                    {assigning && (
                        <Spinner className="mr-2 h-4 w-4 animate-spin" />
                    )}
                    Assign Subject
                </Button>
            </div>

            <div>
                <div className="mb-3 flex items-center justify-between gap-3">
                    <h3 className="text-sm font-medium">
                        Assigned Subjects ({visibleAssignments.length}
                        {filterClass !== 'all' && ` of ${assignments.length}`})
                    </h3>
                    {assignments.length > 0 && (
                        <div className="flex items-center gap-2">
                            <label className="text-sm text-muted-foreground">
                                Filter:
                            </label>
                            <div className="w-48">
                                <SearchableSelect
                                    placeholder="All classes"
                                    isClearable
                                    options={classOptions.map(
                                        ([value, label]) => ({ value, label }),
                                    )}
                                    value={
                                        filterClass === 'all'
                                            ? null
                                            : {
                                                  value: filterClass,
                                                  label: filterClass,
                                              }
                                    }
                                    onChange={(opt: any) =>
                                        setFilterClass(opt ? opt.value : 'all')
                                    }
                                />
                            </div>
                        </div>
                    )}
                </div>

                {loading ? (
                    <div className="py-8 text-center">
                        <Spinner className="mx-auto" />
                    </div>
                ) : assignments.length === 0 ? (
                    <p className="py-6 text-center text-sm text-muted-foreground">
                        No subjects assigned yet.
                    </p>
                ) : visibleAssignments.length === 0 ? (
                    <p className="py-6 text-center text-sm text-muted-foreground">
                        No subjects assigned for this class.
                    </p>
                ) : (
                    <div className="divide-y rounded-lg border">
                        {visibleAssignments.map((a) => (
                            <div
                                key={a.id}
                                className="flex items-center justify-between px-4 py-3"
                            >
                                <div>
                                    <p className="text-sm font-medium">
                                        {a.curriculum_subject.subject.name}
                                        {a.curriculum_subject.subject.code && (
                                            <span className="ml-2 text-xs text-muted-foreground">
                                                (
                                                {
                                                    a.curriculum_subject.subject
                                                        .code
                                                }
                                                )
                                            </span>
                                        )}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {a.curriculum_subject.curriculum
                                            ?.class_level_arm?.name ?? '—'}
                                        {a.curriculum_subject.curriculum?.term
                                            ?.name
                                            ? ` · ${a.curriculum_subject.curriculum.term.name}`
                                            : ''}
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="text-destructive hover:bg-destructive/10"
                                    disabled={removing === a.id}
                                    onClick={() => handleRemove(a.id)}
                                >
                                    {removing === a.id ? (
                                        <Spinner className="h-4 w-4 animate-spin" />
                                    ) : (
                                        <Trash2 className="h-4 w-4" />
                                    )}
                                </Button>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}
