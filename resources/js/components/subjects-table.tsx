/* eslint-disable react-hooks/refs */

import { Link } from '@inertiajs/react';
import axios from 'axios';
import { PackagePlus, PencilIcon, Trash2Icon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Modal } from '@/pages/admin/school-setup';
import type {
    CurriculumSubject,
    MarkingComponent,
    TeacherCurriculumSubject,
} from '@/types/models';
import {
    AddComponentForm,
    ComponentRow,
    pct,
    StatusPill,
    termLabel,
    totalWeight,
    WeightBar,
} from './teacher-subjects';
import type { ToastType } from './toast-item';

// ─────────────────────────────────────────────────────────────────────────
interface SubjectCardProps {
    cs: CurriculumSubject | null;
    addToast: (msg: string, type?: ToastType) => void;
}

function SubjectCard({ cs, addToast }: SubjectCardProps) {
    const [expanded, setExpanded] = useState(false);
    const [components, setComponents] = useState<MarkingComponent[]>(
        cs?.marking_components ?? [],
    );
    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        setComponents(cs?.marking_components ?? []);
    }, [cs?.marking_components]);
    const handleComponentChange = (newComponents: MarkingComponent[]) => {
        setComponents(newComponents);
    };

    const handleAdd = async (name: string, weight: number) => {
        try {
            const res = await axios.post(
                `/api/curriculum-subjects/${cs?.id}/marking-components`,
                { name, weight },
            );
            const created: MarkingComponent = res.data.data;
            handleComponentChange([...components, created]);
            addToast('Component added', 'success');
        } catch {
            addToast('Failed to add component', 'error');
        }
    };

    const handleSave = async (id: string, name: string, weight: number) => {
        try {
            const res = await axios.put(`/api/marking-components/${id}`, {
                name,
                weight,
            });
            const updated: MarkingComponent = res.data.data;
            handleComponentChange(
                components.map((c) => (c.id === id ? updated : c)),
            );
            addToast('Component updated', 'success');
        } catch {
            addToast('Failed to update component', 'error');
        }
    };

    const handleDelete = async (id: string) => {
        try {
            const response = await axios.delete(
                `/api/marking-components/${id}`,
            );

            if (response.status === 200) {
                handleComponentChange(components.filter((c) => c.id !== id));
                addToast('Component removed', 'success');
            } else {
                addToast('Failed to delete component', 'error');
            }
        } catch {
            addToast('Failed to delete component', 'error');
        }
    };

    const total = totalWeight(components);
    const weightOk = Math.abs(total - 1) < 0.001;

    if (!cs) {
        return null;
    }

    return (
        <div
            className="card"
            style={{
                padding: 0,
                overflow: 'hidden',
                border: expanded
                    ? '1.5px solid var(--brand, #3b82f6)'
                    : '1px solid var(--border, #e5e7eb)',
                transition: 'border-color 0.15s',
            }}
        >
            {/* ── card header ─────────────────────────────────────── */}
            <button
                onClick={() => setExpanded((v) => !v)}
                style={{
                    width: '100%',
                    display: 'flex',
                    alignItems: 'center',
                    gap: 12,
                    padding: '14px 16px',
                    background: 'none',
                    border: 'none',
                    cursor: 'pointer',
                    textAlign: 'left',
                }}
            >
                {/* chevron */}
                <span
                    style={{
                        fontSize: 12,
                        color: 'var(--text3)',
                        transition: 'transform 0.2s',
                        transform: expanded ? 'rotate(90deg)' : 'rotate(0deg)',
                        flexShrink: 0,
                    }}
                >
                    ▶
                </span>

                {/* subject name + code */}
                <div style={{ flex: 1, minWidth: 0 }}>
                    <div
                        style={{
                            fontWeight: 600,
                            fontSize: 14,
                            display: 'flex',
                            alignItems: 'center',
                            gap: 6,
                            flexWrap: 'wrap',
                        }}
                    >
                        {cs.subject.name}
                        {cs.subject.code && (
                            <span className="code-tag">{cs.subject.code}</span>
                        )}
                        {cs.students && cs.students.length > 0 && (
                            <span className="code-tag">
                                {cs.students.length} students
                            </span>
                        )}
                    </div>
                    <div
                        style={{
                            fontSize: 12,
                            color: 'var(--text3)',
                            marginTop: 2,
                        }}
                    >
                        {cs.curriculum?.academic_session?.name ?? '—'} ·{' '}
                        <span className="code-tag" style={{ fontSize: 11 }}>
                            {cs.curriculum?.class_level_arm?.name ?? '—'}
                        </span>{' '}
                        · {termLabel(cs.curriculum?.term ?? 1)} Term ·{' '}
                        {cs.curriculum?.exam_type?.name ?? '—'}
                    </div>
                </div>

                {/* right-side badges */}
                <div
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: 8,
                        flexShrink: 0,
                    }}
                >
                    {components.length > 0 && (
                        <span
                            style={{
                                fontSize: 11,
                                fontFamily: 'var(--mono)',
                                padding: '2px 7px',
                                borderRadius: 4,
                                background: weightOk
                                    ? 'var(--green-subtle, #f0fdf4)'
                                    : 'var(--amber-subtle, #fffbeb)',
                                color: weightOk
                                    ? 'var(--green, #16a34a)'
                                    : 'var(--amber, #d97706)',
                                fontWeight: 600,
                            }}
                        >
                            {pct(total)}
                        </span>
                    )}
                    {components.length === 0 && (
                        <span
                            style={{
                                fontSize: 11,
                                color: 'var(--text3)',
                                fontStyle: 'italic',
                            }}
                        >
                            No components
                        </span>
                    )}
                    <StatusPill
                        status={
                            (cs.curriculum?.status ?? 'draft') as
                                | 'draft'
                                | 'active'
                                | 'closed'
                        }
                    />
                </div>
            </button>

            {/* ── expanded body ──────────────────────────────────── */}
            {expanded && (
                <div
                    style={{
                        padding: '0 16px 16px',
                        borderTop: '1px solid var(--border, #e5e7eb)',
                    }}
                >
                    {components.length > 0 && (
                        <WeightBar components={components} />
                    )}
                    {/* view details for a single tcs */}
                    {(cs?.students?.length ?? 0) > 0 &&
                        (cs?.marking_components?.length ?? 0) > 0 &&
                        Math.ceil(
                            (components ?? []).reduce(
                                (sum, component) =>
                                    sum + Number(component?.weight ?? 0),
                                0,
                            ) * 100,
                        ) === 100 && (
                            <div className="my-2 flex justify-end">
                                <Link
                                    className="rounded-md bg-blue-900 p-2 text-sm text-white transition duration-100 hover:bg-blue-800"
                                    href={`/setup/curriculum-subject/${cs?.id}`}
                                >
                                    Assign Scores
                                </Link>
                            </div>
                        )}

                    <div
                        style={{
                            display: 'flex',
                            flexDirection: 'column',
                            gap: 6,
                            marginTop: 12,
                        }}
                    >
                        {components.map((mc, i) => (
                            <ComponentRow
                                key={mc.id}
                                component={mc}
                                colorIndex={i}
                                onSave={handleSave}
                                onDelete={handleDelete}
                            />
                        ))}
                    </div>

                    <AddComponentForm onAdd={handleAdd} />
                </div>
            )}
        </div>
    );
}

function ManageSubjectModal({
    cs,
    onClose,
}: {
    cs: CurriculumSubject | null;
    onClose: () => void;
}) {
    const [curriculumSubject, setCurriculumSubject] =
        useState<CurriculumSubject | null>(null);
    useEffect(() => {
        async function fetchCs() {
            if (cs) {
                const response = await axios.get(
                    `/api/curriculum-subjects/${cs.id}`,
                );
                setCurriculumSubject(response.data);
            }
        }
        fetchCs();
    }, [cs]);

    return (
        <Modal
            title="Manage Subject"
            onClose={onClose}
            footer={
                <>
                    <button className="btn btn-outline" onClick={onClose}>
                        Cancel
                    </button>
                </>
            }
        >
            <SubjectCard cs={curriculumSubject} addToast={() => {}} />
        </Modal>
    );
}

// Drop this into the parent component and wire props in
export function SubjectsTable({
    curriculumSubjects,
    onToggleCompulsory,
    onRemoveSubject,
    onAssignTeacher,
    onRemoveTeacher,
    onReorder,
}: {
    curriculumSubjects: CurriculumSubject[];
    onToggleCompulsory: (cs: CurriculumSubject) => void;
    onRemoveSubject: (cs: CurriculumSubject) => void;
    onAssignTeacher: (cs: CurriculumSubject) => void;
    onRemoveTeacher: (
        cs: CurriculumSubject,
        teacher: TeacherCurriculumSubject,
    ) => void;
    onReorder: (fromIdx: number, toIdx: number) => void;
}) {
    const sorted = [...curriculumSubjects].sort(
        (a, b) => a.display_order - b.display_order,
    );

    const dragIndex = useRef<number | null>(null);
    const [dragOver, setDragOver] = useState<number | null>(null);
    const [isDragging, setIsDragging] = useState(false);
    const [showManageSubject, setShowManageSubject] = useState(false);
    const [cs, setCs] = useState<CurriculumSubject | null>(null);

    const handleDragStart = (idx: number) => {
        dragIndex.current = idx;
        setIsDragging(true);
    };

    const handleDragOver = (e: React.DragEvent, idx: number) => {
        e.preventDefault();

        if (dragIndex.current !== idx) {
            setDragOver(idx);
        }
    };

    const handleDrop = (toIdx: number) => {
        if (dragIndex.current !== null && dragIndex.current !== toIdx) {
            onReorder(dragIndex.current, toIdx);
        }

        setDragOver(null);
        dragIndex.current = null;
        setIsDragging(false);
    };

    const handleDragEnd = () => {
        setDragOver(null);
        dragIndex.current = null;
        setIsDragging(false);
    };

    return (
        <table>
            <thead>
                <tr>
                    {/* drag handle col */}
                    <th style={{ width: 28 }} />
                    <th style={{ width: 32 }}>#</th>
                    <th>Subject</th>
                    <th>Code</th>
                    <th style={{ textAlign: 'center' }}>Compulsory</th>
                    <th>Teachers</th>
                    <th style={{ textAlign: 'right' }}>Actions</th>
                </tr>
            </thead>
            <tbody>
                {sorted.length === 0 && (
                    <tr>
                        <td colSpan={7}>
                            <div
                                style={{
                                    textAlign: 'center',
                                    padding: '32px 0',
                                    color: 'var(--text3)',
                                }}
                            >
                                <div style={{ fontSize: 28, marginBottom: 6 }}>
                                    📚
                                </div>
                                <div
                                    style={{ fontWeight: 600, marginBottom: 2 }}
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
                {sorted.map((cs, idx) => {
                    const isOver = dragOver === idx;
                    const isBeingDragged =
                        isDragging && dragIndex.current === idx;

                    return (
                        <tr
                            key={cs.id}
                            draggable
                            onDragStart={() => handleDragStart(idx)}
                            onDragOver={(e) => handleDragOver(e, idx)}
                            onDrop={() => handleDrop(idx)}
                            onDragEnd={handleDragEnd}
                            style={{
                                opacity: isBeingDragged ? 0.4 : 1,
                                background: isOver
                                    ? 'var(--surface2, #f0f9ff)'
                                    : undefined,
                                boxShadow: isOver
                                    ? 'inset 0 2px 0 var(--brand, #3b82f6)'
                                    : undefined,
                                transition: 'background 0.1s, box-shadow 0.1s',
                                cursor: isDragging ? 'grabbing' : undefined,
                            }}
                        >
                            {/* drag handle */}
                            <td
                                style={{
                                    cursor: 'grab',
                                    color: 'var(--text3)',
                                    userSelect: 'none',
                                    paddingRight: 0,
                                    fontSize: 14,
                                    textAlign: 'center',
                                    lineHeight: 1,
                                }}
                                title="Drag to reorder"
                            >
                                ⠿
                            </td>

                            <td className="muted mono" style={{ fontSize: 12 }}>
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
                                    onClick={() => onToggleCompulsory(cs)}
                                >
                                    {cs.is_compulsory ? 'Yes' : 'No'}
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
                                    {cs.teachers?.map((t) => (
                                        <span
                                            key={t.id}
                                            style={{
                                                display: 'inline-flex',
                                                alignItems: 'center',
                                                gap: 8,
                                                padding: '2px 8px 2px 10px',
                                                borderRadius: 999,
                                                background:
                                                    'var(--surface2, #f3f4f6)',
                                                fontSize: 12,
                                                color: 'var(--text2)',
                                                fontWeight: 500,
                                            }}
                                        >
                                            <Link
                                                href={`/setup/teacher/${t.teacher?.id}`}
                                                className="transition duration-100 hover:text-blue-500"
                                            >
                                                {t.teacher?.first_name}{' '}
                                                {t.teacher?.last_name}
                                            </Link>
                                            <button
                                                onClick={() =>
                                                    onRemoveTeacher(cs, t)
                                                }
                                                style={{
                                                    background: 'none',
                                                    border: 'none',
                                                    cursor: 'pointer',
                                                    padding: '0 2px',
                                                    color: 'var(--text3)',
                                                    fontSize: 13,
                                                    lineHeight: 1,
                                                }}
                                                title="Remove teacher"
                                            >
                                                ×
                                            </button>
                                        </span>
                                    ))}
                                    <button
                                        className="btn btn-ghost btn-sm"
                                        style={{
                                            fontSize: 11,
                                            padding: '2px 8px',
                                        }}
                                        onClick={() => onAssignTeacher(cs)}
                                        title="Assign teacher"
                                    >
                                        + Teacher
                                    </button>
                                </div>
                            </td>

                            <td>
                                <div
                                    className="row-actions"
                                    style={{ justifyContent: 'flex-end' }}
                                >
                                    <button
                                        className="btn btn-primary btn-sm btn-icon"
                                        onClick={() => {
                                            setCs(cs);
                                            setShowManageSubject(true);
                                        }}
                                        title="Manage subject"
                                    >
                                        <PackagePlus />
                                    </button>
                                    <Link
                                        href={`/setup/curriculum-subject/${cs.id}`}
                                        className="btn btn-secondary btn-sm btn-icon"
                                    >
                                        <PencilIcon />
                                    </Link>
                                    <button
                                        className="btn btn-danger btn-sm btn-icon"
                                        onClick={() => onRemoveSubject(cs)}
                                        title="Remove subject"
                                    >
                                        <Trash2Icon />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    );
                })}
            </tbody>
            {showManageSubject && (
                <ManageSubjectModal
                    cs={cs}
                    onClose={() => {
                        setShowManageSubject(false);
                        setCs(null);
                    }}
                />
            )}
        </table>
    );
}
