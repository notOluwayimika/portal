// ═══════════════════════════════════════════════════════════════════════════
// CURRICULUM DETAIL — subject assignment + teacher assignment
// ═══════════════════════════════════════════════════════════════════════════

import axios from 'axios';
import { FileWarningIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { SelectOption } from '@/components/single-select';
import SingleSelect from '@/components/single-select';
import type { ToastType } from '@/components/toast-item';
import { fmtDate } from '@/helpers';
import { Confirm, Modal } from '@/pages/admin/school-setup';
import type {
    Curriculum,
    CurriculumSubject,
    Subject,
    Teacher,
    TeacherCurriculumSubject,
} from '@/types/models';
import { SubjectsTable } from './subjects-table';
import EmptyState from './ui/EmptyState';
import { Link } from '@inertiajs/react';

// ─── Local types ───────────────────────────────────────────────────────────

// ─── StatusPill ────────────────────────────────────────────────────────────

function StatusPill({ status }: { status: Curriculum['status'] }) {
    const map: Record<Curriculum['status'], [string, string]> = {
        active: ['pill-green', 'Active'],
        draft: ['pill-amber', 'Draft'],
        closed: ['pill-slate', 'Closed'],
    };
    const [cls, lbl] = map[status] ?? ['pill-slate', status];

    return <span className={`pill ${cls}`}>{lbl}</span>;
}

// ─── InfoRow ───────────────────────────────────────────────────────────────

function InfoRow({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
            <span
                style={{
                    fontSize: 11,
                    fontWeight: 600,
                    textTransform: 'uppercase',
                    letterSpacing: '0.06em',
                    color: 'var(--text3, #9ca3af)',
                }}
            >
                {label}
            </span>
            <span style={{ fontSize: 13.5, color: 'var(--text1)' }}>
                {children}
            </span>
        </div>
    );
}

// ─── AssignSubjectModal ────────────────────────────────────────────────────

interface AssignSubjectModalProps {
    availableSubjects: SelectOption[];
    onSave: (
        subjectId: string,
        isCompulsory: boolean,
        displayOrder: number,
    ) => Promise<void>;
    onClose: () => void;
    loading: boolean;
}

function AssignSubjectModal({
    availableSubjects,
    onSave,
    onClose,
    loading,
}: AssignSubjectModalProps) {
    const [subjectId, setSubjectId] = useState('');
    const [isCompulsory, setIsCompulsory] = useState(false);
    const [displayOrder, setDisplayOrder] = useState('0');

    const handleSave = () => {
        if (!subjectId) {
            return;
        }

        onSave(subjectId, isCompulsory, Number(displayOrder));
    };

    return (
        <Modal
            title="Assign Subject"
            onClose={onClose}
            footer={
                <>
                    <button className="btn btn-outline" onClick={onClose}>
                        Cancel
                    </button>
                    <button
                        className="btn btn-primary"
                        disabled={loading || !subjectId}
                        onClick={handleSave}
                    >
                        Assign subject
                    </button>
                </>
            }
        >
            <div className="form-grid form-grid-2">
                <div className="field span-2">
                    <label>Subject</label>
                    <SingleSelect
                        options={availableSubjects}
                        value={subjectId}
                        onChange={(v) => setSubjectId(String(v))}
                        label="Select a subject"
                    />
                </div>
                <div className="field">
                    <label>Display order</label>
                    <input
                        type="number"
                        min="0"
                        value={displayOrder}
                        onChange={(e) => setDisplayOrder(e.target.value)}
                    />
                </div>
                <div
                    className="field"
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: 8,
                        paddingTop: 22,
                    }}
                >
                    <input
                        id="compulsory-chk"
                        type="checkbox"
                        checked={isCompulsory}
                        onChange={(e) => setIsCompulsory(e.target.checked)}
                        style={{ width: 16, height: 16, cursor: 'pointer' }}
                    />
                    <label
                        htmlFor="compulsory-chk"
                        style={{ marginBottom: 0, cursor: 'pointer' }}
                    >
                        Compulsory subject
                    </label>
                </div>
            </div>
        </Modal>
    );
}

// ─── AssignTeacherModal ────────────────────────────────────────────────────

interface AssignTeacherModalProps {
    subjectName: string;
    availableTeachers: SelectOption[];
    onSave: (teacherId: string) => Promise<void>;
    onClose: () => void;
    loading: boolean;
}

function AssignTeacherModal({
    subjectName,
    availableTeachers,
    onSave,
    onClose,
    loading,
}: AssignTeacherModalProps) {
    const [teacherId, setTeacherId] = useState('');

    return (
        <Modal
            title={`Assign Teacher — ${subjectName}`}
            onClose={onClose}
            footer={
                <>
                    <button className="btn btn-outline" onClick={onClose}>
                        Cancel
                    </button>
                    <button
                        className="btn btn-primary"
                        disabled={loading || !teacherId}
                        onClick={() => onSave(teacherId)}
                    >
                        Assign teacher
                    </button>
                </>
            }
        >
            <div className="field">
                <label>Teacher</label>
                <SingleSelect
                    options={availableTeachers}
                    value={teacherId}
                    onChange={(v) => setTeacherId(String(v))}
                    label="Select a teacher"
                />
            </div>
        </Modal>
    );
}

// ─── CurriculumDetail ──────────────────────────────────────────────────────

interface CurriculumDetailProps {
    curriculumId: string;
    onBack: () => void;
    addToast: (message: string, type?: ToastType) => void;
}

export function CurriculumDetail({
    curriculumId,
    onBack,
    addToast,
}: CurriculumDetailProps) {
    const [curriculum, setCurriculum] = useState<Curriculum | null>(null);
    const [curriculumSubjects, setCurriculumSubjects] = useState<
        CurriculumSubject[]
    >([]);
    const [allSubjects, setAllSubjects] = useState<Subject[]>([]);
    const [allTeachers, setAllTeachers] = useState<Teacher[]>([]);
    const [loading, setLoading] = useState(false);
    const [fetching, setFetching] = useState(true);

    // modal state
    const [showAssignSubject, setShowAssignSubject] = useState(false);
    const [assignTeacherFor, setAssignTeacherFor] =
        useState<CurriculumSubject | null>(null);
    const [confirmRemoveSubject, setConfirmRemoveSubject] =
        useState<CurriculumSubject | null>(null);
    const [confirmRemoveTeacher, setConfirmRemoveTeacher] = useState<{
        curriculumSubject: CurriculumSubject;
        teacherCurriculumSubject: TeacherCurriculumSubject;
    } | null>(null);

    // ── fetch ──────────────────────────────────────────────────────────────
    const fetchAll = async () => {
        setFetching(true);

        try {
            const [currRes, subjRes, teacherRes] = await Promise.all([
                axios.get(`/api/curricula/${curriculumId}`),
                axios.get('/api/subjects?limit=1000'),
                axios.get('/api/teachers?limit=1000'),
            ]);
            setCurriculum(currRes.data);
            setCurriculumSubjects(currRes.data.curriculum_subjects ?? []);
            setAllSubjects(subjRes.data.subjects ?? []);
            setAllTeachers(teacherRes.data.data ?? []);
        } catch {
            addToast('Failed to load curriculum', 'error');
        } finally {
            setFetching(false);
        }
    };

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        fetchAll();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [curriculumId, loading]);

    // ── derived options ────────────────────────────────────────────────────

    // subjects not yet assigned to this curriculum
    const assignedSubjectIds = new Set(
        curriculumSubjects.map((cs) => cs.subject.id),
    );
    const availableSubjectOptions: SelectOption[] = allSubjects
        .filter((s) => !assignedSubjectIds.has(s.id))
        .map((s) => ({
            label: s.code ? `${s.name} (${s.code})` : s.name,
            value: s.id,
        }));

    // teachers not yet assigned to a given curriculum subject
    const availableTeachersFor = (cs: CurriculumSubject): SelectOption[] => {
        const assignedTeacherIds = new Set(
            cs.teachers.map((t) => t.teacher.id),
        );

        return allTeachers
            .filter((t) => !assignedTeacherIds.has(t.id))
            .map((t) => ({
                label: `${t.first_name} ${t.last_name}`,
                value: t.id,
            }));
    };

    // ── handlers ──────────────────────────────────────────────────────────

    const handleAssignSubject = async (
        subjectId: string,
        isCompulsory: boolean,
        displayOrder: number,
    ) => {
        setLoading(true);

        try {
            await axios.post(`/api/curricula/${curriculumId}/subjects`, {
                subject_id: subjectId,
                is_compulsory: isCompulsory,
                display_order: displayOrder,
            });
            addToast('Subject assigned successfully', 'success');
            setShowAssignSubject(false);
        } catch {
            addToast('Failed to assign subject', 'error');
        } finally {
            setLoading(false);
        }
    };

    const handleRemoveSubject = async (cs: CurriculumSubject) => {
        setLoading(true);

        try {
            const response = await axios.delete(
                `/api/curriculum-subjects/${cs.id}`,
            );

            if (response.status === 200) {
                addToast('Subject removed', 'success');
            } else {
                addToast('Failed to remove subject', 'error');
            }
        } catch {
            addToast('Failed to remove subject', 'error');
        } finally {
            setLoading(false);
        }
    };

    const handleToggleCompulsory = async (cs: CurriculumSubject) => {
        try {
            setLoading(true);
            const response = await axios.patch(
                `/api/curriculum-subjects/${cs.id}`,
                {
                    is_compulsory: !cs.is_compulsory,
                },
            );

            if (response.status === 200) {
                addToast('Subject updated successfully', 'success');
            } else {
                addToast('Failed to update subject', 'error');
            }
        } catch {
            addToast('Failed to update subject', 'error');
        } finally {
            setLoading(false);
        }
    };

    const handleReorder = async (fromIdx: number, toIdx: number) => {
        setLoading(true);
        const sorted = [...curriculumSubjects].sort(
            (a, b) => a.display_order - b.display_order,
        );
        const reordered = sorted.slice();
        const [moved] = reordered.splice(fromIdx, 1);
        reordered.splice(toIdx, 0, moved);
        const updated = reordered.map((cs, i) => ({
            ...cs,
            display_order: i + 1,
        }));

        try {
            await axios.patch(
                `/api/curricula/${curriculumId}/subjects/reorder`,
                {
                    order: updated.map(({ id, display_order }) => ({
                        id,
                        display_order,
                    })),
                },
            );
        } catch {
            addToast('Failed to save order', 'error');
        } finally {
            setLoading(false);
        }
    };

    const handleAssignTeacher = async (teacherId: string) => {
        if (!assignTeacherFor) {
            return;
        }

        setLoading(true);

        try {
            await axios.post(
                `/api/curriculum-subjects/${assignTeacherFor.id}/teachers`,
                {
                    teacher_id: teacherId,
                },
            );
            addToast('Teacher assigned successfully', 'success');
            setAssignTeacherFor(null);
        } catch {
            addToast('Failed to assign teacher', 'error');
        } finally {
            setLoading(false);
        }
    };

    const handleRemoveTeacher = async (
        cs: CurriculumSubject,
        teacher: Teacher,
    ) => {
        setLoading(true);

        try {
            await axios.delete(
                `/api/curriculum-subjects/${cs.id}/teachers/${teacher.id}`,
            );
            addToast('Teacher removed', 'success');
        } catch {
            addToast('Failed to remove teacher', 'error');
        } finally {
            setLoading(false);
        }
    };

    // ── render ─────────────────────────────────────────────────────────────

    if (fetching) {
        return (
            <div
                style={{
                    padding: 40,
                    textAlign: 'center',
                    color: 'var(--text3)',
                }}
            >
                Loading…
            </div>
        );
    }

    if (!curriculum) {
        return (
            <EmptyState
                icon={<FileWarningIcon />}
                title="Curriculum not found"
                description="The requested curriculum could not be found."
                actionLabel="Back to Setup"
                onAction={onBack}
            ></EmptyState>
        );
    }

    return (
        <>
            {/* ── Header ──────────────────────────────────────────────── */}
            <div className="page-hdr">
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                    <button
                        className="btn btn-ghost btn-sm btn-icon"
                        onClick={onBack}
                        title="Back to curricula"
                        style={{ fontSize: 18 }}
                    >
                        ←
                    </button>
                    <div>
                        <h1
                            style={{
                                display: 'flex',
                                alignItems: 'center',
                                gap: 8,
                            }}
                        >
                            {curriculum.term?.full_name ?? '—'}
                            <span
                                style={{
                                    color: 'var(--text3)',
                                    fontWeight: 400,
                                }}
                            >
                                ·
                            </span>
                            <span className="code-tag">
                                {curriculum.class_level_arm?.name ?? '—'}
                            </span>
                        </h1>
                        <p>{curriculum.exam_type?.name ?? '—'}</p>
                    </div>
                </div>
                <div className="page-hdr-actions">
                    <StatusPill status={curriculum.status} />
                </div>
            </div>

            {/* ── Curriculum meta card ─────────────────────────────────── */}
            <div
                className="card"
                style={{
                    display: 'grid',
                    gridTemplateColumns:
                        'repeat(auto-fill, minmax(160px, 1fr))',
                    gap: '16px 24px',
                    padding: '16px 20px',
                    marginBottom: 16,
                }}
            >
                <InfoRow label="Session">
                    {curriculum.term?.full_name ?? '—'}
                </InfoRow>
                <InfoRow label="Class level">
                    <span className="code-tag">
                        {curriculum.class_level_arm?.name ?? '—'}
                    </span>
                </InfoRow>
                <InfoRow label="Exam type">
                    {curriculum.exam_type?.name ?? '—'}
                </InfoRow>
                <InfoRow label="Min. subjects">
                    <span className="mono">{curriculum.min_subjects}</span>
                </InfoRow>
                <InfoRow label="Start Date">
                    {fmtDate(curriculum.term?.start_date ?? '')}
                </InfoRow>
                <InfoRow label="End Date">
                    {fmtDate(curriculum.term?.end_date ?? '')}
                </InfoRow>
                <InfoRow label="Registration Deadline">
                    {fmtDate(curriculum.term?.registration_deadline ?? '')}
                </InfoRow>
                <InfoRow label="Results visible">
                    {fmtDate(curriculum.term?.result_visible_at ?? '')}
                </InfoRow>
                <InfoRow label="Status">
                    <StatusPill status={curriculum.status} />
                </InfoRow>
            </div>

            {/* ── Subjects card ────────────────────────────────────────── */}
            <div className="card">
                <div
                    style={{
                        display: 'flex',
                        justifyContent: 'space-between',
                        alignItems: 'center',
                        padding: '12px 16px 0',
                        marginBottom: 12,
                    }}
                >
                    <div>
                        <h2
                            style={{ margin: 0, fontSize: 15, fontWeight: 600 }}
                        >
                            Subjects
                        </h2>
                        <p
                            style={{
                                margin: 0,
                                fontSize: 12.5,
                                color: 'var(--text3)',
                            }}
                        >
                            {curriculumSubjects.length} subject
                            {curriculumSubjects.length !== 1 ? 's' : ''}{' '}
                            assigned
                        </p>
                    </div>
                    {availableSubjectOptions.length > 0 && (
                        <div className="flex gap-2">
                            <button
                                className="btn btn-primary btn-sm"
                                onClick={() => setShowAssignSubject(true)}
                            >
                                + Assign subject
                            </button>
                        </div>
                    )}
                </div>

                <div className="tbl-wrap">
                    {/* <table>
                        <thead>
                            <tr>
                                <th style={{ width: 32 }}>#</th>
                                <th>Subject</th>
                                <th>Code</th>
                                <th style={{ textAlign: 'center' }}>
                                    Compulsory
                                </th>
                                <th>Teachers</th>
                                <th style={{ textAlign: 'right' }}>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {curriculumSubjects.length === 0 && (
                                <tr>
                                    <td colSpan={6}>
                                        <div
                                            style={{
                                                textAlign: 'center',
                                                padding: '32px 0',
                                                color: 'var(--text3)',
                                            }}
                                        >
                                            <div
                                                style={{
                                                    fontSize: 28,
                                                    marginBottom: 6,
                                                }}
                                            >
                                                📚
                                            </div>
                                            <div
                                                style={{
                                                    fontWeight: 600,
                                                    marginBottom: 2,
                                                }}
                                            >
                                                No subjects yet
                                            </div>
                                            <div style={{ fontSize: 13 }}>
                                                Assign subjects to get started
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            )}
                            {curriculumSubjects
                                .slice()
                                .sort(
                                    (a, b) => a.display_order - b.display_order,
                                )
                                .map((cs, idx) => (
                                    <tr key={cs.id}>
                                        <td
                                            className="muted mono"
                                            style={{ fontSize: 12 }}
                                        >
                                            {cs.display_order || idx + 1}
                                        </td>
                                        <td style={{ fontWeight: 500 }}>
                                            {cs.subject.name}
                                        </td>
                                        <td>
                                            {cs.subject.code ? (
                                                <span className="code-tag">
                                                    {cs.subject.code}
                                                </span>
                                            ) : (
                                                <span className="muted">—</span>
                                            )}
                                        </td>
                                        <td style={{ textAlign: 'center' }}>
                                            <button
                                                className={`pill ${cs.is_compulsory ? 'pill-green' : 'pill-slate'}`}
                                                style={{
                                                    cursor: 'pointer',
                                                    border: 'none',
                                                    background: 'none',
                                                }}
                                                title="Toggle compulsory"
                                                onClick={() =>
                                                    handleToggleCompulsory(cs)
                                                }
                                            >
                                                {cs.is_compulsory
                                                    ? 'Yes'
                                                    : 'No'}
                                            </button>
                                        </td>
                                        <td>
                                            <div
                                                style={{
                                                    display: 'flex',
                                                    flexWrap: 'wrap',
                                                    gap: 4,
                                                    alignItems: 'center',
                                                }}
                                            >
                                                {cs.teachers.map((t) => (
                                                    <TeacherChip
                                                        key={t.id}
                                                        teacherCurriculumSubject={
                                                            t
                                                        }
                                                        onRemove={() =>
                                                            setConfirmRemoveTeacher(
                                                                {
                                                                    curriculumSubject:
                                                                        cs,
                                                                    teacherCurriculumSubject:
                                                                        t,
                                                                },
                                                            )
                                                        }
                                                    />
                                                ))}
                                                <button
                                                    className="btn btn-ghost btn-sm"
                                                    style={{
                                                        fontSize: 11,
                                                        padding: '2px 8px',
                                                    }}
                                                    onClick={() =>
                                                        setAssignTeacherFor(cs)
                                                    }
                                                    title="Assign teacher"
                                                >
                                                    + Teacher
                                                </button>
                                            </div>
                                        </td>
                                        <td>
                                            <div
                                                className="row-actions"
                                                style={{
                                                    justifyContent: 'flex-end',
                                                }}
                                            >
                                                <button
                                                    className="btn btn-danger btn-sm btn-icon"
                                                    onClick={() =>
                                                        setConfirmRemoveSubject(
                                                            cs,
                                                        )
                                                    }
                                                    title="Remove subject"
                                                >
                                                    🗑
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                        </tbody>
                    </table> */}
                    <SubjectsTable
                        curriculumSubjects={curriculumSubjects}
                        onToggleCompulsory={handleToggleCompulsory}
                        onRemoveSubject={setConfirmRemoveSubject}
                        onAssignTeacher={setAssignTeacherFor}
                        onRemoveTeacher={(cs, t) =>
                            setConfirmRemoveTeacher({
                                curriculumSubject: cs,
                                teacherCurriculumSubject: t,
                            })
                        }
                        onReorder={handleReorder}
                    />
                </div>
            </div>

            {/* ── Modals ───────────────────────────────────────────────── */}

            {showAssignSubject && (
                <AssignSubjectModal
                    availableSubjects={availableSubjectOptions}
                    onSave={handleAssignSubject}
                    onClose={() => setShowAssignSubject(false)}
                    loading={loading}
                />
            )}

            {assignTeacherFor && (
                <AssignTeacherModal
                    subjectName={assignTeacherFor.subject.name}
                    availableTeachers={availableTeachersFor(assignTeacherFor)}
                    onSave={handleAssignTeacher}
                    onClose={() => setAssignTeacherFor(null)}
                    loading={loading}
                />
            )}

            {confirmRemoveSubject && (
                <Confirm
                    msg={`Remove "${confirmRemoveSubject.subject.name}" from this curriculum? Any linked scores will be affected.`}
                    onConfirm={() => {
                        handleRemoveSubject(confirmRemoveSubject);
                        setConfirmRemoveSubject(null);
                    }}
                    onClose={() => setConfirmRemoveSubject(null)}
                />
            )}

            {confirmRemoveTeacher && (
                <Confirm
                    msg={`Remove ${confirmRemoveTeacher.teacherCurriculumSubject.teacher.first_name} ${confirmRemoveTeacher.teacherCurriculumSubject.teacher.last_name} as teacher for "${confirmRemoveTeacher.curriculumSubject.subject.name}"?`}
                    onConfirm={() => {
                        handleRemoveTeacher(
                            confirmRemoveTeacher.curriculumSubject,
                            confirmRemoveTeacher.teacherCurriculumSubject
                                .teacher,
                        );
                        setConfirmRemoveTeacher(null);
                    }}
                    onClose={() => setConfirmRemoveTeacher(null)}
                />
            )}
        </>
    );
}
