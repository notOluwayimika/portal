/* eslint-disable react-hooks/refs */

import { useRef, useState } from 'react';
import type {
    CurriculumSubject,
    TeacherCurriculumSubject,
} from '@/types/models';

// ─────────────────────────────────────────────────────────────────────────

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
                                    {cs.teachers.map((t) => (
                                        <span
                                            key={t.id}
                                            style={{
                                                display: 'inline-flex',
                                                alignItems: 'center',
                                                gap: 4,
                                                padding: '2px 8px 2px 10px',
                                                borderRadius: 999,
                                                background:
                                                    'var(--surface2, #f3f4f6)',
                                                fontSize: 12,
                                                color: 'var(--text2)',
                                                fontWeight: 500,
                                            }}
                                        >
                                            {t.teacher.first_name}{' '}
                                            {t.teacher.last_name}
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
                                        className="btn btn-danger btn-sm btn-icon"
                                        onClick={() => onRemoveSubject(cs)}
                                        title="Remove subject"
                                    >
                                        🗑
                                    </button>
                                </div>
                            </td>
                        </tr>
                    );
                })}
            </tbody>
        </table>
    );
}
