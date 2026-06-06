// ═══════════════════════════════════════════════════════════════════════════
// GRADE BOUNDARIES TAB
// ═══════════════════════════════════════════════════════════════════════════

import axios from 'axios';
import { Check, Pencil, Trash2, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ToastType } from '@/components/toast-item';
import type { ExamType, GradeBoundary } from '@/types/models';
import { Empty } from './school-setup';

type EditingMap = Record<string, GradeBoundary>;

export function GradeBoundariesTab({
    addToast,
}: {
    addToast: (message: string, type?: ToastType) => void;
}) {
    const [examTypes, setExamTypes] = useState<ExamType[]>([]);
    const [boundaries, setBoundaries] = useState<GradeBoundary[]>([]);
    const [filter, setFilter] = useState<string | null>(null);
    const [editing, setEditing] = useState<EditingMap>({});
    const [loading, setLoading] = useState<boolean>(false);
    const [mode, setMode] = useState<'add' | 'edit' | null>(null);
    useEffect(() => {
        const fetchExamTypes = async () => {
            const response = await axios.get('/api/exam-types');
            setExamTypes(response.data.data ?? []);
        };

        fetchExamTypes();
    }, []);

    useEffect(() => {
        const fetchBoundaries = async () => {
            if (filter) {
                const response = await axios.get(
                    `/api/grade-boundaries/${filter}`,
                );
                setBoundaries(response.data.data ?? []);
            }
        };

        fetchBoundaries();
    }, [filter, loading]);

    const gradeColor = (g: string): string => {
        if (['A', 'A*', 'A1'].includes(g)) {
            return '#15803d';
        }

        if (['B', 'B2', 'B3'].includes(g)) {
            return '#1d4ed8';
        }

        if (['C', 'C4', 'C5', 'C6'].includes(g)) {
            return '#b45309';
        }

        return '#b91c1c';
    };

    const addRow = (): void => {
        if (!filter) {
            addToast('Please select an exam type', 'error');

            return;
        }

        const nb: GradeBoundary = {
            id: new Date().toLocaleTimeString(),
            exam_type_id: filter ?? undefined,
            min_score: 0,
            max_score: 0,
            grade: '',
            label: '',
            grade_point: '',
        };
        setMode('add');
        setBoundaries((p) => [...p, nb]);
        setEditing((p) => ({ ...p, [nb.id]: { ...nb } }));
    };

    const startEdit = (b: GradeBoundary): void => {
        setMode('edit');
        setEditing((p) => ({ ...p, [b.id]: { ...b } }));
    };
    const cancelEdit = (id: string): void => {
        setLoading(true);
        setMode(null);

        try {
            setEditing((p) => {
                const n = { ...p };
                delete n[id];

                return n;
            });
        } catch (error) {
            console.log(error);
            addToast('Failed to cancel edit', 'error');
        } finally {
            setTimeout(() => {
                setLoading(false);
            }, 500);
        }
    };
    const updateEdit = (
        id: string,
        k: keyof GradeBoundary,
        v: string | number | null,
    ): void => setEditing((p) => ({ ...p, [id]: { ...p[id], [k]: v } }));
    const saveRow = async (id: string): Promise<void> => {
        setLoading(true);
        const boundary = editing[id];

        if (!boundary) {
            setLoading(false);

            return;
        }

        try {
            if (mode === 'edit') {
                const response = await axios.put(
                    `/api/grade-boundaries/${id}`,
                    boundary,
                );

                if (response.status === 200) {
                    cancelEdit(id);
                    addToast('Boundary saved successfully', 'success');
                    setMode(null);
                } else {
                    addToast('Failed to save boundary', 'error');
                }
            } else if (mode === 'add') {
                const response = await axios.post(
                    `/api/grade-boundaries`,
                    boundary,
                );

                if (response.status === 201) {
                    cancelEdit(id);
                    addToast('Boundary added successfully', 'success');
                    setMode(null);
                } else {
                    addToast('Failed to add boundary', 'error');
                }
            }
        } catch (error) {
            console.log(error);
            addToast('Failed to save boundary', 'error');
        } finally {
            setLoading(false);
        }
    };
    const delRow = async (id: string): Promise<void> => {
        try {
            setLoading(true);
            const response = await axios.delete(`/api/grade-boundaries/${id}`);

            if (response.status === 204) {
                addToast('Boundary deleted successfully', 'success');
            }
        } catch (error) {
            console.log(error);
            addToast('Failed to delete boundary', 'error');
        } finally {
            setLoading(false);
            cancelEdit(id);
        }
    };

    return (
        <>
            <div className="page-hdr">
                <div>
                    <h1>Grade Boundaries</h1>
                    <p>Map score ranges to grade labels</p>
                </div>
                <div className="page-hdr-actions">
                    <button className="btn btn-primary" onClick={addRow}>
                        + Add Boundary
                    </button>
                </div>
            </div>

            <div className="filter-row">
                {/* <button
                    className={`filter-btn${filter === '__default__' ? 'on' : ''}`}
                    onClick={() => setFilter('__default__')}
                >
                    Default
                </button> */}
                {examTypes.map((et) => (
                    <button
                        key={et.id}
                        className={
                            filter === et.id ? 'filter-btn on' : 'filter-btn'
                        }
                        onClick={() => setFilter(et.id)}
                    >
                        {et.name}
                    </button>
                ))}
            </div>

            <div className="card">
                <div
                    style={{
                        padding: '12px 16px',
                        borderBottom: '1px solid var(--border)',
                        background: 'var(--surface2)',
                    }}
                >
                    <div className="grade-cols">
                        <span className="grade-col-hdr">Min score</span>
                        <span className="grade-col-hdr">Max score</span>
                        <span className="grade-col-hdr">Grade</span>
                        <span className="grade-col-hdr">Label</span>
                        <span className="grade-col-hdr">Grade Point</span>
                        <span></span>
                    </div>
                </div>
                <div
                    className="overflow-x-scroll"
                    style={{
                        padding: '10px 12px',
                        display: 'flex',
                        flexDirection: 'column',
                        gap: 6,
                    }}
                >
                    {boundaries.length === 0 && (
                        <Empty
                            icon="📊"
                            title="No boundaries"
                            sub="Click '+ Add Boundary' to get started"
                        />
                    )}
                    {boundaries.map((b) => {
                        const isEd = !!editing[b.id];
                        const e = editing[b.id] ?? b;

                        return (
                            <div
                                key={b.id}
                                style={{
                                    background: isEd
                                        ? 'var(--blue-lt)'
                                        : 'var(--surface2)',
                                    border: `1px solid ${isEd ? 'var(--blue-mid)' : 'var(--border)'}`,
                                    borderRadius: 8,
                                    padding: '9px 12px',
                                    transition: 'all 0.15s',
                                }}
                            >
                                <div className="grade-cols">
                                    {isEd ? (
                                        <>
                                            <input
                                                type="number"
                                                min="0"
                                                max="100"
                                                step={1}
                                                value={e.min_score}
                                                onChange={(ev) =>
                                                    updateEdit(
                                                        b.id,
                                                        'min_score',
                                                        +ev.target.value,
                                                    )
                                                }
                                            />
                                            <input
                                                type="number"
                                                min="0"
                                                max="101"
                                                value={e.max_score}
                                                onChange={(ev) =>
                                                    updateEdit(
                                                        b.id,
                                                        'max_score',
                                                        +ev.target.value,
                                                    )
                                                }
                                            />
                                            <input
                                                placeholder="A"
                                                value={e.grade}
                                                onChange={(ev) =>
                                                    updateEdit(
                                                        b.id,
                                                        'grade',
                                                        ev.target.value,
                                                    )
                                                }
                                            />
                                            <input
                                                placeholder="Distinction"
                                                value={e.label}
                                                onChange={(ev) =>
                                                    updateEdit(
                                                        b.id,
                                                        'label',
                                                        ev.target.value,
                                                    )
                                                }
                                            />
                                            <input
                                                placeholder="5.0"
                                                value={e.grade_point}
                                                onChange={(ev) =>
                                                    updateEdit(
                                                        b.id,
                                                        'grade_point',
                                                        ev.target.value,
                                                    )
                                                }
                                            />
                                            <div
                                                style={{
                                                    display: 'flex',
                                                    gap: 4,
                                                }}
                                            >
                                                <button
                                                    className="btn btn-primary btn-sm btn-icon"
                                                    onClick={() =>
                                                        saveRow(b.id)
                                                    }
                                                >
                                                    <Check className="h-3 w-3" />
                                                </button>
                                                <button
                                                    className="btn btn-ghost btn-sm btn-icon"
                                                    onClick={() =>
                                                        cancelEdit(b.id)
                                                    }
                                                >
                                                    <X className="h-3 w-3" />
                                                </button>
                                            </div>
                                        </>
                                    ) : (
                                        <>
                                            <span
                                                style={{
                                                    fontFamily: 'var(--mono)',
                                                    fontSize: 13,
                                                    color: 'var(--text2)',
                                                }}
                                            >
                                                {b.min_score}
                                            </span>
                                            <span
                                                style={{
                                                    fontFamily: 'var(--mono)',
                                                    fontSize: 13,
                                                    color: 'var(--text2)',
                                                }}
                                            >
                                                {b.max_score}
                                            </span>
                                            <span
                                                style={{
                                                    fontFamily: 'var(--mono)',
                                                    fontWeight: 700,
                                                    color: gradeColor(b.grade),
                                                    fontSize: 14,
                                                }}
                                            >
                                                {b.grade}
                                            </span>
                                            <span style={{ fontSize: 13.5 }}>
                                                {b.label}
                                            </span>
                                            <span style={{ fontSize: 13.5 }}>
                                                {b.grade_point}
                                            </span>
                                            <div
                                                style={{
                                                    display: 'flex',
                                                    gap: 4,
                                                }}
                                            >
                                                <button
                                                    className="btn btn-ghost btn-sm btn-icon"
                                                    onClick={() => startEdit(b)}
                                                >
                                                    <Pencil className="h-3 w-3" />
                                                </button>
                                                <button
                                                    className="btn btn-danger btn-sm btn-icon"
                                                    onClick={() => delRow(b.id)}
                                                >
                                                    <Trash2 className="h-3 w-3" />
                                                </button>
                                            </div>
                                        </>
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>
        </>
    );
}
