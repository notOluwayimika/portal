// ═══════════════════════════════════════════════════════════════════════════
// CURRICULA TAB
// ═══════════════════════════════════════════════════════════════════════════

import { Link } from '@inertiajs/react';
import axios from 'axios';
import { Settings } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Pagination } from '@/components/pagination';
import type { SelectOption } from '@/components/single-select';
import SingleSelect from '@/components/single-select';
import type { ToastType } from '@/components/toast-item';
import { convertToSelectOptions, fmtDate } from '@/helpers';
import { show } from '@/routes/setup/curricula';
import type { Curriculum } from '@/types/models';
import { Confirm, Empty, Modal } from './school-setup';

// ─── StatusPill ────────────────────────────────────────────────────────────

interface StatusPillProps {
    status: Curriculum['status'];
}

function StatusPill({ status }: StatusPillProps) {
    const map: Record<Curriculum['status'], [string, string]> = {
        active: ['pill-green', 'Active'],
        draft: ['pill-amber', 'Draft'],
        closed: ['pill-slate', 'Closed'],
    };
    const [cls, lbl] = map[status] ?? ['pill-slate', status];

    return <span className={`pill ${cls}`}>{lbl}</span>;
}

interface CurriculumForm {
    term_id: string;
    class_level_id: string;
    exam_type_id: string;
    min_subjects: string;
    status: Curriculum['status'];
}

// ─── Filters ───────────────────────────────────────────────────────────────

interface Filters {
    term_id: string;
    class_level_id: string;
    term: string;
    status: string;
}

export function CurriculaTab({
    addToast,
}: {
    addToast: (message: string, type?: ToastType) => void;
}) {
    const [curricula, setCurricula] = useState<Curriculum[]>([]);
    const [classLevels, setClassLevels] = useState<SelectOption[]>([]);
    const [examTypes, setExamTypes] = useState<SelectOption[]>([]);
    const [terms, setTerms] = useState<SelectOption[]>([]); // To store term options
    const [modal, setModal] = useState<string | null>(null);
    const [confirm, setConfirm] = useState<Curriculum | null>(null);
    const [limit, setLimit] = useState(10);
    const [page, setPage] = useState(1);
    const [paginationMeta, setPaginationMeta] = useState({
        current_page: 1,
        last_page: 1,
        per_page: 10,
        total: 0,
    });
    const [loading, setLoading] = useState(false);

    // ─── Filter state ─────────────────────────────────────────────────────
    const blankFilters: Filters = {
        term_id: '',
        class_level_id: '',
        term: '',
        status: '',
    };
    const [filters, setFilters] = useState<Filters>(blankFilters);

    const flt = <K extends keyof Filters>(k: K, v: Filters[K]): void => {
        setFilters((p) => ({ ...p, [k]: v }));
        setPage(1); // reset to first page on filter change
    };

    const clearFilters = () => {
        setFilters(blankFilters);
        setPage(1);
    };

    const hasActiveFilters = Object.values(filters).some(Boolean);
    // ─────────────────────────────────────────────────────────────────────

    const statusOptions: SelectOption[] = [
        { label: 'Active', value: 'active' },
        { label: 'Draft', value: 'draft' },
        { label: 'Closed', value: 'closed' },
    ];

    useEffect(() => {
        const fetchClassStructure = async () => {
            const response = await axios.get('/api/class-structure');
            setClassLevels(
                convertToSelectOptions(response.data.class_level_arms),
            );
            setExamTypes(convertToSelectOptions(response.data.exam_types));
            setTerms(
                convertToSelectOptions(response.data.terms || [], 'full_name'),
            );
        };
        fetchClassStructure();
    }, []);
    useEffect(() => {
        const fetchCurricula = async () => {
            const response = await axios.get('/api/curricula', {
                params: {
                    limit,
                    page,
                    // Only send non-empty filter values
                    ...(filters.term_id &&
                        filters.term_id !== 'all' && {
                            term_id: filters.term_id,
                        }),
                    ...(filters.class_level_id &&
                        filters.class_level_id !== 'all' && {
                            class_level_id: filters.class_level_id,
                        }),
                    ...(filters.status &&
                        filters.status !== 'all' && { status: filters.status }),
                },
            });
            setCurricula(response.data.curricula);
            setPaginationMeta(response.data.pagination ?? paginationMeta);
        };
        fetchCurricula();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [loading, limit, page, filters]);

    const blank: CurriculumForm = {
        term_id: '',
        class_level_id: '',
        exam_type_id: '',
        min_subjects: '8',
        status: '',
    };
    const [form, setForm] = useState<CurriculumForm>(blank);

    const f = <K extends keyof CurriculumForm>(
        k: K,
        v: CurriculumForm[K],
    ): void => setForm((p) => ({ ...p, [k]: v }));

    const open = (c: Curriculum | null = null): void => {
        if (c) {
            setForm({
                term_id: c.term?.id ?? '',
                class_level_id: c.class_level_arm?.id ?? '',
                exam_type_id: c.exam_type?.id ?? '',
                min_subjects: String(c.min_subjects),
                status: c.status,
            });
        } else {
            setForm({ ...blank });
        }

        setModal(c ? c.id : 'new');
    };

    const handleDelete = async (id: string): Promise<void> => {
        setLoading(true);

        try {
            const response = await axios.delete(`/api/curricula/${id}`);

            if (response.status === 204) {
                addToast('Successfully deleted curriculum');
            }
        } catch (error) {
            console.log(error);
            addToast('Unable to delete curriculum', 'error');
        } finally {
            setLoading(false);
        }
    };

    const save = async (): Promise<void> => {
        if (
            !form.term_id ||
            !form.class_level_id ||
            !form.exam_type_id ||
            !form.min_subjects ||
            !form.status
        ) {
            addToast('Please fill in all required fields.', 'error');

            return;
        }

        setLoading(true);

        try {
            const payload = {
                ...form,
                min_subjects: +form.min_subjects,
            };

            if (modal === 'new') {
                const response = await axios.post('/api/curricula', payload);

                if (response.status === 201) {
                    addToast('Curriculum saved successfully!', 'success');
                    setModal(null);
                } else {
                    addToast('Failed to save curriculum.', 'error');
                }
            } else {
                const response = await axios.put(
                    `/api/curricula/${modal}`,
                    payload,
                );

                if (response.status === 200) {
                    addToast('Curriculum updated successfully!', 'success');
                    setModal(null);
                } else {
                    addToast('Failed to update curriculum.', 'error');
                }
            }
        } catch (error) {
            console.log(error);
            addToast('Failed to save curriculum.', 'error');
        } finally {
            setLoading(false);
        }
    };

    return (
        <>
            <div className="page-hdr">
                <div>
                    <h1>Curricula</h1>
                    <p>Session × class level × term configurations</p>
                </div>
                <div className="page-hdr-actions">
                    <button className="btn btn-primary" onClick={() => open()}>
                        + New Curriculum
                    </button>
                </div>
            </div>

            {/* ─── Filters ──────────────────────────────────────────────── */}
            <div className="card p-4" style={{ marginBottom: 12 }}>
                <div
                    style={{
                        display: 'flex',
                        flexWrap: 'wrap',
                        gap: 12,
                        alignItems: 'flex-end',
                        padding: '4px 0',
                    }}
                >
                    <div
                        className="field"
                        style={{ flex: '1 1 130px', marginBottom: 0 }}
                    >
                        <label>Term</label>
                        <SingleSelect
                            options={[
                                { label: 'All terms', value: 'all' },
                                ...terms,
                            ]}
                            value={filters.term_id}
                            onChange={(v) => flt('term_id', String(v))}
                            label="All terms"
                        />
                    </div>
                    <div
                        className="field"
                        style={{ flex: '1 1 160px', marginBottom: 0 }}
                    >
                        <label>Class level</label>
                        <SingleSelect
                            options={[
                                { label: 'All levels', value: 'all' },
                                ...classLevels,
                            ]}
                            value={filters.class_level_id}
                            onChange={(v) => flt('class_level_id', String(v))}
                            label="All levels"
                        />
                    </div>

                    <div
                        className="field"
                        style={{ flex: '1 1 130px', marginBottom: 0 }}
                    >
                        <label>Status</label>
                        <SingleSelect
                            options={[
                                { label: 'All statuses', value: 'all' },
                                ...statusOptions,
                            ]}
                            value={filters.status}
                            onChange={(v) => flt('status', String(v))}
                            label="All statuses"
                        />
                    </div>
                    {hasActiveFilters && (
                        <button
                            className="btn btn-ghost btn-sm"
                            onClick={clearFilters}
                            style={{ marginBottom: 0, whiteSpace: 'nowrap' }}
                        >
                            ✕ Clear filters
                        </button>
                    )}
                </div>
            </div>
            {/* ──────────────────────────────────────────────────────────── */}

            <div className="card">
                <div className="tbl-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Session</th>
                                <th>Level</th>
                                <th>Term</th>
                                <th>Exam type</th>
                                <th style={{ textAlign: 'center' }}>
                                    Min. subj.
                                </th>
                                <th>Reg. deadline</th>
                                <th>Results visible</th>
                                <th>Status</th>
                                <th style={{ textAlign: 'right' }}>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {curricula.length === 0 && (
                                <tr>
                                    <td colSpan={9}>
                                        <Empty
                                            icon="📋"
                                            title="No curricula yet"
                                            sub={
                                                hasActiveFilters
                                                    ? 'No results match the current filters'
                                                    : 'Create your first curriculum'
                                            }
                                        />
                                    </td>
                                </tr>
                            )}
                            {curricula.map((c) => (
                                <tr key={c.id}>
                                    <td
                                        style={{
                                            fontFamily: 'var(--mono)',
                                            fontWeight: 600,
                                            fontSize: 12.5,
                                        }}
                                    >
                                        {c.academic_session?.name ?? '—'}
                                    </td>
                                    <td>
                                        <span className="code-tag">
                                            {c.class_level_arm?.name ?? '—'}
                                        </span>
                                    </td>
                                    <td className="muted">
                                        {c.term?.name ?? `Term ${c.term_id}`}
                                    </td>
                                    <td
                                        style={{
                                            fontSize: 12.5,
                                            color: 'var(--text2)',
                                        }}
                                    >
                                        {c.exam_type?.name ?? '—'}
                                    </td>
                                    <td
                                        style={{ textAlign: 'center' }}
                                        className="mono"
                                    >
                                        {c.min_subjects}
                                    </td>
                                    <td
                                        className="muted"
                                        style={{ fontSize: 12.5 }}
                                    >
                                        {fmtDate(c.term?.start_date ?? '—')}
                                    </td>
                                    <td
                                        className="muted"
                                        style={{ fontSize: 12.5 }}
                                    >
                                        {fmtDate(c.term?.end_date ?? '—')}
                                    </td>
                                    <td>
                                        <StatusPill status={c.status} />
                                    </td>
                                    <td>
                                        <div
                                            className="row-actions"
                                            style={{
                                                justifyContent: 'flex-end',
                                            }}
                                        >
                                            <Link
                                                href={show(c.id)}
                                                className="btn btn-ghost btn-sm btn-icon"
                                            >
                                                <Settings />
                                            </Link>
                                            <button
                                                className="btn btn-ghost btn-sm btn-icon"
                                                onClick={() => open(c)}
                                            >
                                                ✏️
                                            </button>
                                            <button
                                                className="btn btn-danger btn-sm btn-icon"
                                                onClick={() => setConfirm(c)}
                                            >
                                                🗑
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination
                    meta={paginationMeta}
                    setPage={setPage}
                    setLimit={setLimit}
                />
            </div>

            {modal && (
                <Modal
                    title={
                        modal === 'new' ? 'New Curriculum' : 'Edit Curriculum'
                    }
                    large
                    onClose={() => setModal(null)}
                    footer={
                        <>
                            <button
                                className="btn btn-outline"
                                onClick={() => setModal(null)}
                            >
                                Cancel
                            </button>
                            <button
                                disabled={loading}
                                className="btn btn-primary"
                                onClick={save}
                            >
                                Save curriculum
                            </button>
                        </>
                    }
                >
                    <div className="form-grid form-grid-3">
                        <div className="field">
                            <label>Term</label>
                            <SingleSelect
                                options={terms}
                                value={form.term_id}
                                onChange={(value) =>
                                    f('term_id', String(value))
                                }
                                label="Term"
                            />
                        </div>
                        <div className="field">
                            <label>Class level</label>
                            <SingleSelect
                                options={classLevels}
                                value={form.class_level_id}
                                onChange={(value) =>
                                    f('class_level_id', String(value))
                                }
                                label="Class level"
                            />
                        </div>

                        <div className="field">
                            <label>Exam type</label>
                            <SingleSelect
                                options={examTypes}
                                value={form.exam_type_id}
                                onChange={(value) =>
                                    f('exam_type_id', String(value))
                                }
                                label="Exam type"
                            />
                        </div>
                        <div className="field">
                            <label>Status</label>
                            <SingleSelect
                                options={statusOptions}
                                value={form.status}
                                onChange={(value) => f('status', String(value))}
                                label="Status"
                            />
                        </div>
                        <div className="field">
                            <label>Min. subjects</label>
                            <input
                                type="number"
                                min="1"
                                value={form.min_subjects}
                                onChange={(e) =>
                                    f('min_subjects', e.target.value)
                                }
                            />
                        </div>
                    </div>
                </Modal>
            )}
            {confirm && (
                <Confirm
                    msg="Delete this curriculum? Any linked scores and results will be affected."
                    onConfirm={() => {
                        handleDelete(confirm.id);
                        setConfirm(null);
                    }}
                    onClose={() => setConfirm(null)}
                />
            )}
        </>
    );
}
