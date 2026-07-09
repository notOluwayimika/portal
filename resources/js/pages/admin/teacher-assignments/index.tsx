import { Head } from '@inertiajs/react';
import axios from 'axios';
import {
    Check,
    ChevronLeft,
    ChevronRight,
    Heart,
    Plus,
    RefreshCw,
    Search,
    ShieldCheck,
    Trash2,
    UserCog,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import ConfirmDialog from '@/components/ui/ConfirmDialog';
import { Input } from '@/components/ui/input';
import Modal from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/spinner';
import { useInitials, type GetInitialsFn } from '@/hooks/use-initials';
import type { ClassLevelArm, ClassLevelArmTeacher, Teacher, TeacherAssignmentRole } from '@/types/models';

// ---------------------------------------------------------------------------
// Constants & helpers
// ---------------------------------------------------------------------------

const ROLE_META: Record<TeacherAssignmentRole, { label: string; description: string; icon: LucideIcon }> = {
    form_teacher: {
        label: 'Form Teacher',
        description: 'Manages a single class arm and writes the term comment for each student.',
        icon: UserCog,
    },
    boarding_parent: {
        label: 'Boarding Parent',
        description: 'Records behavioral assessments for students of one gender in an arm. Up to one male and one female per arm.',
        icon: Heart,
    },
    head_of_school: {
        label: 'Head of School',
        description: 'May supervise several class levels and writes the term comment for each student.',
        icon: ShieldCheck,
    },
};

type WizardStep = 'role' | 'teacher' | 'classes' | 'review';

const STEP_ORDER: WizardStep[] = ['role', 'teacher', 'classes', 'review'];

interface WizardState {
    role: TeacherAssignmentRole | null;
    teacher: Teacher | null;
    classLevelArmIds: string[];
    gender: 'male' | 'female' | null;
}

const emptyWizardState: WizardState = {
    role: null,
    teacher: null,
    classLevelArmIds: [],
    gender: null,
};

interface ClassLevelGroup {
    id: string;
    name: string;
    order: number;
    arms: ClassLevelArm[];
}

function armLabel(cla: ClassLevelArm): string {
    return cla.stream ? `${cla.arm.label} (${cla.stream.name})` : cla.arm.label;
}

// ---------------------------------------------------------------------------
// Assignment Wizard
// ---------------------------------------------------------------------------

interface AssignmentWizardProps {
    classLevelGroups: ClassLevelGroup[];
    initialStep: WizardStep;
    initialState: WizardState;
    onClose: () => void;
    onSaved: () => Promise<void> | void;
}

function AssignmentWizard({ classLevelGroups, initialStep, initialState, onClose, onSaved }: AssignmentWizardProps) {
    const getInitials = useInitials();
    const [step, setStep] = useState<WizardStep>(initialStep);
    const [state, setState] = useState<WizardState>(initialState);
    const [submitting, setSubmitting] = useState(false);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    const [teacherQuery, setTeacherQuery] = useState('');
    const [teacherResults, setTeacherResults] = useState<Teacher[]>([]);
    const [searchingTeachers, setSearchingTeachers] = useState(false);

    useEffect(() => {
        if (step !== 'teacher') {
            return;
        }

        let active = true;
        setSearchingTeachers(true);

        const handle = setTimeout(async () => {
            try {
                const res = await axios.get('/api/teacher-assignments/teachers', {
                    params: teacherQuery ? { search: teacherQuery } : {},
                });

                if (active) {
                    setTeacherResults(res.data.data ?? []);
                }
            } catch {
                if (active) {
                    setTeacherResults([]);
                }
            } finally {
                if (active) {
                    setSearchingTeachers(false);
                }
            }
        }, 300);

        return () => {
            active = false;
            clearTimeout(handle);
        };
    }, [step, teacherQuery]);

    const minStepIndex = STEP_ORDER.indexOf(initialStep);
    const stepIndex = STEP_ORDER.indexOf(step);

    const selectedArms = useMemo(
        () => classLevelGroups.flatMap((group) => group.arms).filter((cla) => state.classLevelArmIds.includes(cla.id)),
        [classLevelGroups, state.classLevelArmIds],
    );

    function toggleArm(armId: string) {
        setState((s) => ({
            ...s,
            classLevelArmIds: s.classLevelArmIds.includes(armId)
                ? s.classLevelArmIds.filter((id) => id !== armId)
                : [...s.classLevelArmIds, armId],
        }));
    }

    function toggleClassLevel(group: ClassLevelGroup) {
        const armIds = group.arms.map((a) => a.id);
        const allSelected = armIds.length > 0 && armIds.every((id) => state.classLevelArmIds.includes(id));

        setState((s) => ({
            ...s,
            classLevelArmIds: allSelected
                ? s.classLevelArmIds.filter((id) => !armIds.includes(id))
                : Array.from(new Set([...s.classLevelArmIds, ...armIds])),
        }));
    }

    function canProceed(): boolean {
        switch (step) {
            case 'role':
                return state.role !== null;
            case 'teacher':
                return state.teacher !== null;
            case 'classes':
                if (state.classLevelArmIds.length === 0) {
                    return false;
                }

                if (state.role === 'form_teacher' && state.classLevelArmIds.length !== 1) {
                    return false;
                }

                if (state.role === 'boarding_parent' && !state.gender) {
                    return false;
                }

                return true;
            default:
                return true;
        }
    }

    function goNext() {
        const nextIndex = stepIndex + 1;

        if (nextIndex < STEP_ORDER.length) {
            setErrorMessage(null);
            setStep(STEP_ORDER[nextIndex]);
        }
    }

    function goBack() {
        const prevIndex = stepIndex - 1;

        if (prevIndex >= minStepIndex) {
            setErrorMessage(null);
            setStep(STEP_ORDER[prevIndex]);
        }
    }

    async function handleSubmit() {
        if (!state.role || !state.teacher) {
            return;
        }

        setSubmitting(true);
        setErrorMessage(null);

        try {
            await axios.post('/api/teacher-assignments', {
                teacher_id: state.teacher.id,
                role: state.role,
                gender: state.role === 'boarding_parent' ? state.gender : undefined,
                class_level_arm_ids: state.classLevelArmIds,
            });

            await onSaved();
        } catch (error) {
            if (axios.isAxiosError(error)) {
                const errors = error.response?.data?.errors as Record<string, string[]> | undefined;
                const firstError = errors ? Object.values(errors)[0]?.[0] : undefined;

                setErrorMessage(firstError ?? error.response?.data?.message ?? 'Failed to save assignment.');
            } else {
                setErrorMessage('Failed to save assignment.');
            }
        } finally {
            setSubmitting(false);
        }
    }

    const stepTitles: Record<WizardStep, string> = {
        role: 'Select a role',
        teacher: 'Select a teacher',
        classes: state.role === 'boarding_parent' ? 'Select class arm and gender' : 'Select class arm(s)',
        review: 'Review and save',
    };

    return (
        <Modal
            isOpen
            onClose={onClose}
            title={stepTitles[step]}
            size="lg"
            footer={
                <div className="flex items-center justify-between">
                    <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
                        Cancel
                    </Button>
                    <div className="flex gap-2">
                        {stepIndex > minStepIndex && (
                            <Button type="button" variant="outline" onClick={goBack} disabled={submitting}>
                                <ChevronLeft className="h-4 w-4" />
                                Back
                            </Button>
                        )}
                        {step !== 'review' ? (
                            <Button type="button" onClick={goNext} disabled={!canProceed()}>
                                Next
                                <ChevronRight className="h-4 w-4" />
                            </Button>
                        ) : (
                            <Button type="button" onClick={handleSubmit} disabled={submitting}>
                                {submitting && <Spinner className="size-4" />}
                                Save Assignment
                            </Button>
                        )}
                    </div>
                </div>
            }
        >
            {/* Step indicator */}
            <div className="mb-4 flex items-center gap-1.5">
                {STEP_ORDER.map((s, index) => (
                    <span
                        key={s}
                        className={`h-1.5 flex-1 rounded-full ${
                            index <= stepIndex ? 'bg-indigo-500' : 'bg-gray-100'
                        }`}
                    />
                ))}
            </div>

            {/* Step: Role */}
            {step === 'role' && (
                <div className="space-y-3">
                    {(Object.keys(ROLE_META) as TeacherAssignmentRole[]).map((role) => {
                        const meta = ROLE_META[role];
                        const Icon = meta.icon;
                        const selected = state.role === role;

                        return (
                            <button
                                key={role}
                                type="button"
                                onClick={() => setState({ ...emptyWizardState, role })}
                                className={`flex w-full items-start gap-3 rounded-xl border p-4 text-left transition ${
                                    selected
                                        ? 'border-indigo-500 bg-indigo-50 ring-2 ring-indigo-100'
                                        : 'border-gray-200 hover:border-gray-300'
                                }`}
                            >
                                <Icon className={`mt-0.5 h-5 w-5 ${selected ? 'text-indigo-600' : 'text-gray-400'}`} />
                                <div>
                                    <p className="text-sm font-semibold text-gray-900">{meta.label}</p>
                                    <p className="mt-0.5 text-xs text-gray-500">{meta.description}</p>
                                </div>
                            </button>
                        );
                    })}
                </div>
            )}

            {/* Step: Teacher */}
            {step === 'teacher' && (
                <div className="space-y-3">
                    <div className="relative">
                        <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-gray-400" />
                        <Input
                            value={teacherQuery}
                            onChange={(e) => setTeacherQuery(e.target.value)}
                            placeholder="Search by name, staff number or email…"
                            className="pl-9"
                        />
                    </div>

                    <div className="max-h-80 space-y-1 overflow-y-auto">
                        {searchingTeachers ? (
                            <div className="flex items-center justify-center py-8">
                                <Spinner className="size-5 text-gray-400" />
                            </div>
                        ) : teacherResults.length === 0 ? (
                            <p className="py-8 text-center text-sm text-gray-400">No teachers found.</p>
                        ) : (
                            teacherResults.map((teacher) => {
                                const selected = state.teacher?.id === teacher.id;

                                return (
                                    <button
                                        key={teacher.id}
                                        type="button"
                                        onClick={() => setState((s) => ({ ...s, teacher }))}
                                        className={`flex w-full items-center gap-3 rounded-lg border p-2.5 text-left transition ${
                                            selected
                                                ? 'border-indigo-500 bg-indigo-50 ring-2 ring-indigo-100'
                                                : 'border-gray-200 hover:border-gray-300'
                                        }`}
                                    >
                                        <Avatar className="h-9 w-9">
                                            <AvatarImage src={teacher.photo ?? undefined} />
                                            <AvatarFallback className="bg-indigo-100 text-sm font-semibold text-indigo-700">
                                                {getInitials(`${teacher.first_name} ${teacher.last_name}`)}
                                            </AvatarFallback>
                                        </Avatar>
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium text-gray-900">
                                                {teacher.first_name} {teacher.last_name}
                                            </p>
                                            <div className="mt-0.5 flex items-center gap-2">
                                                {teacher.staff_number && (
                                                    <span className="text-xs text-gray-400">#{teacher.staff_number}</span>
                                                )}
                                                {!teacher.user?.id && (
                                                    <Badge variant="outline" className="text-amber-700">
                                                        No account
                                                    </Badge>
                                                )}
                                            </div>
                                        </div>
                                        {selected && <Check className="h-4 w-4 shrink-0 text-indigo-600" />}
                                    </button>
                                );
                            })
                        )}
                    </div>
                </div>
            )}

            {/* Step: Classes (+ gender for boarding parents) */}
            {step === 'classes' && (
                <div className="space-y-5">
                    {state.role === 'form_teacher' ? (
                        <div className="space-y-4">
                            {classLevelGroups.map((group) => (
                                <div key={group.id}>
                                    <p className="mb-2 text-xs font-semibold tracking-wide text-gray-500 uppercase">
                                        {group.name}
                                    </p>
                                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                        {group.arms.map((cla) => {
                                            const selected = state.classLevelArmIds.includes(cla.id);

                                            return (
                                                <label
                                                    key={cla.id}
                                                    className={`flex cursor-pointer items-center gap-2 rounded-lg border p-2.5 text-sm transition ${
                                                        selected
                                                            ? 'border-indigo-500 bg-indigo-50'
                                                            : 'border-gray-200 hover:border-gray-300'
                                                    }`}
                                                >
                                                    <input
                                                        type="radio"
                                                        name="form-teacher-arm"
                                                        className="h-4 w-4 text-indigo-600"
                                                        checked={selected}
                                                        onChange={() => setState((s) => ({ ...s, classLevelArmIds: [cla.id] }))}
                                                    />
                                                    {armLabel(cla)}
                                                </label>
                                            );
                                        })}
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <div className="space-y-4">
                            {classLevelGroups.map((group) => {
                                const armIds = group.arms.map((a) => a.id);
                                const allSelected = armIds.length > 0 && armIds.every((id) => state.classLevelArmIds.includes(id));
                                const someSelected = armIds.some((id) => state.classLevelArmIds.includes(id));

                                return (
                                    <div key={group.id} className="rounded-lg border border-gray-200 p-3">
                                        <label className="flex cursor-pointer items-center gap-2 text-sm font-semibold text-gray-800">
                                            <input
                                                type="checkbox"
                                                className="h-4 w-4 rounded text-indigo-600"
                                                checked={allSelected}
                                                onChange={() => toggleClassLevel(group)}
                                            />
                                            {group.name}
                                            {someSelected && !allSelected && (
                                                <span className="text-xs font-normal text-gray-400">partially selected</span>
                                            )}
                                        </label>
                                        <div className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3">
                                            {group.arms.map((cla) => {
                                                const selected = state.classLevelArmIds.includes(cla.id);

                                                return (
                                                    <label
                                                        key={cla.id}
                                                        className={`flex cursor-pointer items-center gap-2 rounded-lg border p-2.5 text-sm transition ${
                                                            selected
                                                                ? 'border-indigo-500 bg-indigo-50'
                                                                : 'border-gray-200 hover:border-gray-300'
                                                        }`}
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            className="h-4 w-4 rounded text-indigo-600"
                                                            checked={selected}
                                                            onChange={() => toggleArm(cla.id)}
                                                        />
                                                        {armLabel(cla)}
                                                    </label>
                                                );
                                            })}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}

                    {state.role === 'boarding_parent' && (
                        <div>
                            <p className="mb-2 text-xs font-semibold tracking-wide text-gray-500 uppercase">Gender</p>
                            <div className="flex gap-3">
                                {(['male', 'female'] as const).map((gender) => {
                                    const selected = state.gender === gender;

                                    return (
                                        <button
                                            key={gender}
                                            type="button"
                                            onClick={() => setState((s) => ({ ...s, gender }))}
                                            className={`flex-1 rounded-lg border p-3 text-center text-sm font-medium capitalize transition ${
                                                selected
                                                    ? 'border-indigo-500 bg-indigo-50 text-indigo-700 ring-2 ring-indigo-100'
                                                    : 'border-gray-200 text-gray-600 hover:border-gray-300'
                                            }`}
                                        >
                                            {gender}
                                        </button>
                                    );
                                })}
                            </div>
                            <p className="mt-2 text-xs text-gray-400">
                                This teacher will assess {state.gender ?? 'the selected'} students in the chosen arm. Up to one
                                male and one female boarding parent are allowed per arm.
                            </p>
                        </div>
                    )}
                </div>
            )}

            {/* Step: Review */}
            {step === 'review' && state.role && (
                <div className="space-y-4">
                    <dl className="space-y-3 rounded-lg border border-gray-200 p-4 text-sm">
                        <div className="flex items-center justify-between">
                            <dt className="text-gray-500">Role</dt>
                            <dd className="font-medium text-gray-900">{ROLE_META[state.role].label}</dd>
                        </div>
                        <div className="flex items-center justify-between">
                            <dt className="text-gray-500">Teacher</dt>
                            <dd className="font-medium text-gray-900">
                                {state.teacher ? `${state.teacher.first_name} ${state.teacher.last_name}` : '—'}
                            </dd>
                        </div>
                        {state.role === 'boarding_parent' && (
                            <div className="flex items-center justify-between">
                                <dt className="text-gray-500">Gender</dt>
                                <dd className="font-medium text-gray-900 capitalize">{state.gender ?? '—'}</dd>
                            </div>
                        )}
                        <div>
                            <dt className="text-gray-500">Class arm{selectedArms.length > 1 ? 's' : ''}</dt>
                            <dd className="mt-1.5 flex flex-wrap gap-1.5">
                                {selectedArms.map((cla) => (
                                    <Badge key={cla.id} variant="secondary">
                                        {cla.name}
                                    </Badge>
                                ))}
                            </dd>
                        </div>
                    </dl>

                    <p className="text-xs text-gray-400">
                        Saving will replace any existing {ROLE_META[state.role].label.toLowerCase()}
                        {state.role === 'boarding_parent' && state.gender ? ` (${state.gender})` : ''} assigned to the selected
                        arm{selectedArms.length > 1 ? 's' : ''}, and grant the {ROLE_META[state.role].label} role to{' '}
                        {state.teacher?.first_name ?? 'this teacher'}.
                    </p>

                    {errorMessage && <p className="text-sm text-red-600">{errorMessage}</p>}
                </div>
            )}
        </Modal>
    );
}

// ---------------------------------------------------------------------------
// Assignment Section (current assignments table)
// ---------------------------------------------------------------------------

interface AssignmentSectionProps {
    title: string;
    description: string;
    icon: LucideIcon;
    assignments: ClassLevelArmTeacher[];
    showGender: boolean;
    getInitials: GetInitialsFn;
    onReplace: (assignment: ClassLevelArmTeacher) => void;
    onRemove: (assignment: ClassLevelArmTeacher) => void;
}

function AssignmentSection({
    title,
    description,
    icon: Icon,
    assignments,
    showGender,
    getInitials,
    onReplace,
    onRemove,
}: AssignmentSectionProps) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                    <Icon className="h-4 w-4 text-indigo-600" />
                    {title}
                    <Badge variant="secondary">{assignments.length}</Badge>
                </CardTitle>
                <p className="text-sm text-muted-foreground">{description}</p>
            </CardHeader>
            <CardContent>
                {assignments.length === 0 ? (
                    <p className="py-6 text-center text-sm text-gray-400">No assignments yet.</p>
                ) : (
                    <div className="divide-y divide-gray-100">
                        {assignments.map((assignment) => (
                            <div key={assignment.id} className="flex items-center gap-4 py-3">
                                <Avatar>
                                    <AvatarImage src={assignment.teacher?.photo ?? undefined} />
                                    <AvatarFallback className="bg-indigo-100 text-sm font-semibold text-indigo-700">
                                        {assignment.teacher
                                            ? getInitials(`${assignment.teacher.first_name} ${assignment.teacher.last_name}`)
                                            : '?'}
                                    </AvatarFallback>
                                </Avatar>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium text-gray-900">
                                        {assignment.teacher
                                            ? `${assignment.teacher.first_name} ${assignment.teacher.last_name}`
                                            : 'Unknown teacher'}
                                    </p>
                                    <div className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-gray-500">
                                        <span>{assignment.class_level_arm?.name ?? 'Unknown class'}</span>
                                        {showGender && assignment.gender && (
                                            <Badge variant="outline" className="capitalize">
                                                {assignment.gender}
                                            </Badge>
                                        )}
                                    </div>
                                </div>
                                <div className="flex shrink-0 items-center gap-2">
                                    <Button variant="outline" size="sm" onClick={() => onReplace(assignment)}>
                                        <RefreshCw className="h-3.5 w-3.5" />
                                        Replace
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="text-red-600 hover:bg-red-50 hover:text-red-700"
                                        onClick={() => onRemove(assignment)}
                                    >
                                        <Trash2 className="h-3.5 w-3.5" />
                                        Remove
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

// ---------------------------------------------------------------------------
// Main Page
// ---------------------------------------------------------------------------

export default function TeacherAssignmentsIndex() {
    const getInitials = useInitials();
    const [assignments, setAssignments] = useState<ClassLevelArmTeacher[]>([]);
    const [classLevelArms, setClassLevelArms] = useState<ClassLevelArm[]>([]);
    const [loading, setLoading] = useState(true);

    const [wizardOpen, setWizardOpen] = useState(false);
    const [wizardInitialStep, setWizardInitialStep] = useState<WizardStep>('role');
    const [wizardInitialState, setWizardInitialState] = useState<WizardState>(emptyWizardState);

    const [removing, setRemoving] = useState<ClassLevelArmTeacher | null>(null);
    const [removeLoading, setRemoveLoading] = useState(false);

    async function fetchData() {
        setLoading(true);

        try {
            const [assignmentsRes, structureRes] = await Promise.all([
                axios.get('/api/teacher-assignments'),
                axios.get('/api/class-structure'),
            ]);

            setAssignments(assignmentsRes.data.data ?? []);
            setClassLevelArms(structureRes.data.class_level_arms ?? []);
        } catch {
            toast.error('Failed to load teacher assignments.');
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => {
        fetchData();
    }, []);

    const classLevelGroups = useMemo<ClassLevelGroup[]>(() => {
        const map = new Map<string, ClassLevelGroup>();

        for (const cla of classLevelArms) {
            const level = cla.class_level;

            if (!map.has(level.id)) {
                map.set(level.id, { id: level.id, name: level.name, order: level.order, arms: [] });
            }

            map.get(level.id)!.arms.push(cla);
        }

        return Array.from(map.values()).sort((a, b) => a.order - b.order);
    }, [classLevelArms]);

    const grouped = useMemo(
        () => ({
            form_teacher: assignments.filter((a) => a.role === 'form_teacher'),
            boarding_parent: assignments.filter((a) => a.role === 'boarding_parent'),
            head_of_school: assignments.filter((a) => a.role === 'head_of_school'),
        }),
        [assignments],
    );

    function openNewAssignment() {
        setWizardInitialState(emptyWizardState);
        setWizardInitialStep('role');
        setWizardOpen(true);
    }

    function openReplace(assignment: ClassLevelArmTeacher) {
        if (!assignment.class_level_arm) {
            return;
        }

        setWizardInitialState({
            role: assignment.role,
            teacher: null,
            classLevelArmIds: [assignment.class_level_arm.id],
            gender: assignment.gender ?? null,
        });
        setWizardInitialStep('teacher');
        setWizardOpen(true);
    }

    async function handleRemove() {
        if (!removing) {
            return;
        }

        setRemoveLoading(true);

        try {
            await axios.delete(`/api/teacher-assignments/${removing.id}`);
            toast.success('Assignment removed.');
            setRemoving(null);
            await fetchData();
        } catch {
            toast.error('Failed to remove assignment.');
        } finally {
            setRemoveLoading(false);
        }
    }

    return (
        <>
            <Head title="Teacher Assignments" />
            <div className="space-y-6 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold text-gray-900">Teacher Assignments</h1>
                        <p className="mt-1 text-sm text-gray-500">
                            Assign Form Teachers, Boarding Parents and Heads of School to class arms.
                        </p>
                    </div>
                    <Button onClick={openNewAssignment}>
                        <Plus className="h-4 w-4" />
                        New Assignment
                    </Button>
                </div>

                {loading ? (
                    <div className="flex items-center justify-center py-24">
                        <Spinner className="size-6 text-gray-400" />
                    </div>
                ) : (
                    <div className="space-y-6">
                        <AssignmentSection
                            title="Form Teachers"
                            description="One teacher per class arm. Writes the term comment for each student."
                            icon={UserCog}
                            assignments={grouped.form_teacher}
                            showGender={false}
                            getInitials={getInitials}
                            onReplace={openReplace}
                            onRemove={setRemoving}
                        />
                        <AssignmentSection
                            title="Boarding Parents"
                            description="Up to one male and one female teacher per class arm. Records behavioral assessments."
                            icon={Heart}
                            assignments={grouped.boarding_parent}
                            showGender
                            getInitials={getInitials}
                            onReplace={openReplace}
                            onRemove={setRemoving}
                        />
                        <AssignmentSection
                            title="Heads of School"
                            description="May supervise multiple class levels. Writes the term comment for each student."
                            icon={ShieldCheck}
                            assignments={grouped.head_of_school}
                            showGender={false}
                            getInitials={getInitials}
                            onReplace={openReplace}
                            onRemove={setRemoving}
                        />
                    </div>
                )}
            </div>

            {wizardOpen && (
                <AssignmentWizard
                    classLevelGroups={classLevelGroups}
                    initialStep={wizardInitialStep}
                    initialState={wizardInitialState}
                    onClose={() => setWizardOpen(false)}
                    onSaved={async () => {
                        setWizardOpen(false);
                        toast.success('Assignment saved.');
                        await fetchData();
                    }}
                />
            )}

            <ConfirmDialog
                isOpen={!!removing}
                onClose={() => setRemoving(null)}
                onConfirm={handleRemove}
                title="Remove assignment"
                message={
                    removing
                        ? `Remove ${removing.teacher?.first_name ?? ''} ${removing.teacher?.last_name ?? ''} as ${ROLE_META[removing.role].label} for ${removing.class_level_arm?.name ?? 'this class'}? They will lose the role if this is their last assignment of this type.`
                        : ''
                }
                confirmLabel={removeLoading ? 'Removing…' : 'Remove'}
                dangerous
            />
        </>
    );
}
