// ═══════════════════════════════════════════════════════════════════════════
// MY SUBJECTS — Teacher view
// Each row = a curriculum_subject the teacher is assigned to.
// Inline expand → manage marking_components for that subject.
// ═══════════════════════════════════════════════════════════════════════════

import axios from 'axios';
import { useEffect, useRef, useState } from 'react';
import type { ToastType } from '@/components/toast-item';
import type {
    MarkingComponent,
    TeacherCurriculumSubject,
} from '@/types/models';
// import { fmtDate } from '@/helpers';

// ─── Types ─────────────────────────────────────────────────────────────────

// ─── helpers ───────────────────────────────────────────────────────────────

const termLabel = (t: { name: string } | number) => {
    if (typeof t === 'object') {
        return t.name;
    }

    return ['', '1st', '2nd', '3rd'][t] ?? String(t);
};

const pct = (w: number) => `${Math.round(w * 100)}%`;

function totalWeight(components: MarkingComponent[]) {
    return components.reduce((s, c) => s + Number(c.weight), 0);
}

function WeightBar({ components }: { components: MarkingComponent[] }) {
    const total = totalWeight(components);
    const over = total > 1.001;
    const ok = Math.abs(total - 1) < 0.001;

    return (
        <div style={{ marginTop: 10 }}>
            <div
                style={{
                    display: 'flex',
                    height: 6,
                    borderRadius: 999,
                    overflow: 'hidden',
                    background: 'var(--surface2, #f1f5f9)',
                    gap: 1,
                }}
            >
                {components.map((c, i) => (
                    <div
                        key={c.id}
                        style={{
                            flex: `0 0 ${Math.min(c.weight / 1, 1) * 100}%`,
                            background: COLORS[i % COLORS.length],
                            transition: 'flex 0.3s',
                        }}
                        title={`${c.name}: ${pct(c.weight)}`}
                    />
                ))}
            </div>
            <div
                style={{
                    fontSize: 11,
                    marginTop: 4,
                    color: over
                        ? 'var(--red, #ef4444)'
                        : ok
                          ? 'var(--green, #22c55e)'
                          : 'var(--text3)',
                    fontWeight: 500,
                }}
            >
                {pct(total)} allocated
                {over && ' — exceeds 100%'}
                {ok && ' ✓'}
            </div>
        </div>
    );
}

const COLORS = [
    'var(--brand, #3b82f6)',
    '#f59e0b',
    '#10b981',
    '#8b5cf6',
    '#ec4899',
    '#06b6d4',
];

// ─── StatusPill ─────────────────────────────────────────────────────────────

function StatusPill({ status }: { status: 'draft' | 'active' | 'closed' }) {
    const map = {
        active: ['pill-green', 'Active'],
        draft: ['pill-amber', 'Draft'],
        closed: ['pill-slate', 'Closed'],
    } as const;
    const [cls, lbl] = map[status] ?? ['pill-slate', status];

    return <span className={`pill ${cls}`}>{lbl}</span>;
}

// ─── ComponentRow ────────────────────────────────────────────────────────────
// Inline editable row for a single marking component.

interface ComponentRowProps {
    component: MarkingComponent;
    colorIndex: number;
    onSave: (id: string, name: string, weight: number) => Promise<void>;
    onDelete: (id: string) => Promise<void>;
}

function ComponentRow({
    component,
    colorIndex,
    onSave,
    onDelete,
}: ComponentRowProps) {
    const [editing, setEditing] = useState(false);
    const [name, setName] = useState(component.name);
    const [weight, setWeight] = useState(
        String(Math.round(component.weight * 100)),
    );
    const [saving, setSaving] = useState(false);
    const nameRef = useRef<HTMLInputElement>(null);

    const startEdit = () => {
        setName(component.name);
        setWeight(String(Math.round(component.weight * 100)));
        setEditing(true);
        setTimeout(() => nameRef.current?.focus(), 50);
    };

    const cancel = () => setEditing(false);

    const save = async () => {
        const w = Number(weight) / 100;

        if (!name.trim() || isNaN(w) || w <= 0) {
            return;
        }

        setSaving(true);
        await onSave(component.id, name.trim(), w);
        setSaving(false);
        setEditing(false);
    };

    const handleKeyDown = (e: React.KeyboardEvent) => {
        if (e.key === 'Enter') {
            save();
        }

        if (e.key === 'Escape') {
            cancel();
        }
    };

    return (
        <div
            style={{
                display: 'flex',
                alignItems: 'center',
                gap: 8,
                padding: '7px 10px',
                borderRadius: 8,
                background: 'var(--surface1, #fff)',
                border: '1px solid var(--border, #e5e7eb)',
            }}
        >
            {/* colour dot */}
            <span
                style={{
                    width: 8,
                    height: 8,
                    borderRadius: '50%',
                    background: COLORS[colorIndex % COLORS.length],
                    flexShrink: 0,
                }}
            />

            {editing ? (
                <>
                    <input
                        ref={nameRef}
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        onKeyDown={handleKeyDown}
                        style={{
                            flex: 1,
                            fontSize: 13,
                            border: '1px solid var(--border)',
                            borderRadius: 4,
                            padding: '2px 6px',
                            minWidth: 0,
                        }}
                        placeholder="Component name"
                    />
                    <input
                        value={weight}
                        onChange={(e) => setWeight(e.target.value)}
                        onKeyDown={handleKeyDown}
                        type="number"
                        min="1"
                        max="100"
                        style={{
                            width: 60,
                            fontSize: 13,
                            border: '1px solid var(--border)',
                            borderRadius: 4,
                            padding: '2px 6px',
                            textAlign: 'right',
                        }}
                    />
                    <span
                        style={{
                            fontSize: 12,
                            color: 'var(--text3)',
                            flexShrink: 0,
                        }}
                    >
                        %
                    </span>
                    <button
                        className="btn btn-primary btn-sm"
                        disabled={saving}
                        onClick={save}
                        style={{ padding: '2px 10px', fontSize: 12 }}
                    >
                        {saving ? '…' : 'Save'}
                    </button>
                    <button
                        className="btn btn-ghost btn-sm"
                        onClick={cancel}
                        style={{ padding: '2px 8px', fontSize: 12 }}
                    >
                        ✕
                    </button>
                </>
            ) : (
                <>
                    <span style={{ flex: 1, fontSize: 13, fontWeight: 500 }}>
                        {component.name}
                    </span>
                    <span
                        style={{
                            fontSize: 12,
                            fontFamily: 'var(--mono)',
                            color: 'var(--text2)',
                            background: 'var(--surface2, #f3f4f6)',
                            padding: '1px 7px',
                            borderRadius: 4,
                            flexShrink: 0,
                        }}
                    >
                        {pct(component.weight)}
                    </span>
                    <button
                        className="btn btn-ghost btn-sm btn-icon"
                        onClick={startEdit}
                        title="Edit"
                        style={{ fontSize: 13 }}
                    >
                        ✏️
                    </button>
                    <button
                        className="btn btn-danger btn-sm btn-icon"
                        onClick={() => onDelete(component.id)}
                        title="Delete"
                        style={{ fontSize: 13 }}
                    >
                        🗑
                    </button>
                </>
            )}
        </div>
    );
}

// ─── AddComponentForm ─────────────────────────────────────────────────────────

function AddComponentForm({
    onAdd,
}: {
    onAdd: (name: string, weight: number) => Promise<void>;
}) {
    const [open, setOpen] = useState(false);
    const [name, setName] = useState('');
    const [weight, setWeight] = useState('');
    const [saving, setSaving] = useState(false);
    const nameRef = useRef<HTMLInputElement>(null);

    const submit = async () => {
        const w = Number(weight) / 100;

        if (!name.trim() || isNaN(w) || w <= 0) {
            return;
        }

        setSaving(true);
        await onAdd(name.trim(), w);
        setSaving(false);
        setName('');
        setWeight('');
        setOpen(false);
    };

    if (!open) {
        return (
            <button
                className="btn btn-ghost btn-sm"
                style={{ fontSize: 12, marginTop: 8, width: '100%' }}
                onClick={() => {
                    setOpen(true);
                    setTimeout(() => nameRef.current?.focus(), 50);
                }}
            >
                + Add component
            </button>
        );
    }

    return (
        <div
            style={{
                display: 'flex',
                alignItems: 'center',
                gap: 8,
                marginTop: 8,
                padding: '7px 10px',
                borderRadius: 8,
                border: '1px dashed var(--brand, #3b82f6)',
                background: 'var(--brand-subtle, #eff6ff)',
            }}
        >
            <input
                ref={nameRef}
                value={name}
                onChange={(e) => setName(e.target.value)}
                onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                        submit();
                    }

                    if (e.key === 'Escape') {
                        setOpen(false);
                    }
                }}
                placeholder="e.g. CA Test"
                style={{
                    flex: 1,
                    fontSize: 13,
                    border: '1px solid var(--border)',
                    borderRadius: 4,
                    padding: '2px 6px',
                    minWidth: 0,
                }}
            />
            <input
                value={weight}
                onChange={(e) => setWeight(e.target.value)}
                onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                        submit();
                    }

                    if (e.key === 'Escape') {
                        setOpen(false);
                    }
                }}
                type="number"
                min="1"
                max="100"
                placeholder="30"
                style={{
                    width: 60,
                    fontSize: 13,
                    border: '1px solid var(--border)',
                    borderRadius: 4,
                    padding: '2px 6px',
                    textAlign: 'right',
                }}
            />
            <span
                style={{ fontSize: 12, color: 'var(--text3)', flexShrink: 0 }}
            >
                %
            </span>
            <button
                className="btn btn-primary btn-sm"
                disabled={saving || !name.trim() || !weight}
                onClick={submit}
                style={{ padding: '2px 10px', fontSize: 12 }}
            >
                {saving ? '…' : 'Add'}
            </button>
            <button
                className="btn btn-ghost btn-sm"
                onClick={() => setOpen(false)}
                style={{ padding: '2px 8px', fontSize: 12 }}
            >
                ✕
            </button>
        </div>
    );
}

// ─── SubjectCard ──────────────────────────────────────────────────────────────

interface SubjectCardProps {
    tcs: TeacherCurriculumSubject;
    addToast: (msg: string, type?: ToastType) => void;
    onComponentsChange: (
        curriculumSubjectId: string,
        components: MarkingComponent[],
    ) => void;
}

function SubjectCard({ tcs, addToast, onComponentsChange }: SubjectCardProps) {
    const [expanded, setExpanded] = useState(false);
    const components = tcs.curriculum_subject.marking_components;

    const handleAdd = async (name: string, weight: number) => {
        try {
            const res = await axios.post(
                `/api/curriculum-subjects/${tcs.curriculum_subject.id}/marking-components`,
                { name, weight },
            );
            const created: MarkingComponent = res.data.marking_component;
            onComponentsChange(tcs.curriculum_subject.id, [
                ...components,
                created,
            ]);
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
            const updated: MarkingComponent = res.data.marking_component;
            onComponentsChange(
                tcs.curriculum_subject.id,
                components.map((c) => (c.id === id ? updated : c)),
            );
            addToast('Component updated', 'success');
        } catch {
            addToast('Failed to update component', 'error');
        }
    };

    const handleDelete = async (id: string) => {
        try {
            await axios.delete(`/api/marking-components/${id}`);
            onComponentsChange(
                tcs.curriculum_subject.id,
                components.filter((c) => c.id !== id),
            );
            addToast('Component removed', 'success');
        } catch {
            addToast('Failed to delete component', 'error');
        }
    };

    const c = tcs.curriculum_subject;
    const total = totalWeight(components);
    const weightOk = Math.abs(total - 1) < 0.001;

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
                        {tcs.curriculum_subject.subject.name}
                        {tcs.curriculum_subject.subject.code && (
                            <span className="code-tag">
                                {tcs.curriculum_subject.subject.code}
                            </span>
                        )}
                        {tcs.curriculum_subject.students && (
                            <span className="code-tag">
                                {tcs.curriculum_subject.students.length}{' '}
                                students
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
                        {c.curriculum?.academic_session?.name ?? '—'} ·{' '}
                        <span className="code-tag" style={{ fontSize: 11 }}>
                            {c.curriculum?.class_level_arm?.name ?? '—'}
                        </span>{' '}
                        · {termLabel(c.curriculum?.term ?? 1)} Term ·{' '}
                        {c.curriculum?.exam_type?.name ?? '—'}
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
                            (c.curriculum?.status ?? 'draft') as
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

// ─── MySubjectsPage ───────────────────────────────────────────────────────────

export function TeacherSubjects({
    addToast,
    teacherId,
}: {
    addToast: (msg: string, type?: ToastType) => void;
    teacherId: string;
}) {
    const [subjects, setSubjects] = useState<TeacherCurriculumSubject[]>([]);
    const [fetching, setFetching] = useState(true);
    const [loading] = useState(false);

    // filters
    const [filterSession, setFilterSession] = useState('');
    const [filterStatus, setFilterStatus] = useState('');
    const [filterSearch, setFilterSearch] = useState('');

    useEffect(() => {
        const fetch = async () => {
            setFetching(true);

            try {
                const res = await axios.get(
                    `/api/teachers/${teacherId}/subjects`,
                );
                console.log(res.data);
                setSubjects(res.data ?? []);
            } catch {
                addToast('Failed to load subjects', 'error');
            } finally {
                setFetching(false);
            }
        };
        fetch();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [loading]);

    // derived unique session list for filter dropdown
    const sessions = Array.from(
        new Map(
            subjects
                .map((s) => s.curriculum_subject?.curriculum?.academic_session)
                .filter(Boolean)
                .map((s) => [s!.id, s!]),
        ).values(),
    );

    const handleComponentsChange = (
        curriculumSubjectId: string,
        components: MarkingComponent[],
    ) => {
        setSubjects((prev) =>
            prev.map((s) =>
                s.curriculum_subject.id === curriculumSubjectId
                    ? { ...s, marking_components: components }
                    : s,
            ),
        );
    };

    const filtered = subjects.filter((s) => {
        if (
            filterSession &&
            s.curriculum_subject?.curriculum?.academic_session?.id !==
                filterSession
        ) {
            return false;
        }

        if (
            filterStatus &&
            s.curriculum_subject?.curriculum?.status !== filterStatus
        ) {
            return false;
        }

        if (
            filterSearch &&
            !s.curriculum_subject?.subject?.name
                .toLowerCase()
                .includes(filterSearch.toLowerCase()) &&
            !(s.curriculum_subject?.subject?.code ?? '')
                .toLowerCase()
                .includes(filterSearch.toLowerCase())
        ) {
            return false;
        }

        return true;
    });

    const hasFilters = !!(filterSession || filterStatus || filterSearch);

    return (
        <>
            {/* ── Header ──────────────────────────────────────────── */}
            <div className="page-hdr">
                <div>
                    <h1>Teacher Subjects</h1>
                    <p>
                        {subjects.length} subject
                        {subjects.length !== 1 ? 's' : ''} assigned to{' '}
                        {subjects[0]?.teacher?.first_name}{' '}
                        {subjects[0]?.teacher?.last_name}
                    </p>
                </div>
            </div>

            {/* ── Filters ─────────────────────────────────────────── */}
            <div
                className="card"
                style={{
                    marginBottom: 16,
                    display: 'flex',
                    flexWrap: 'wrap',
                    gap: 10,
                    alignItems: 'flex-end',
                    padding: '12px 16px',
                }}
            >
                {/* search */}
                <div
                    className="field"
                    style={{ flex: '1 1 200px', marginBottom: 0 }}
                >
                    <label>Search</label>
                    <input
                        type="search"
                        placeholder="Subject name or code…"
                        value={filterSearch}
                        onChange={(e) => setFilterSearch(e.target.value)}
                    />
                </div>

                {/* session */}
                <div
                    className="field"
                    style={{ flex: '1 1 180px', marginBottom: 0 }}
                >
                    <label>Session</label>
                    <select
                        value={filterSession}
                        onChange={(e) => setFilterSession(e.target.value)}
                    >
                        <option value="">All sessions</option>
                        {sessions.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </select>
                </div>

                {/* status */}
                <div
                    className="field"
                    style={{ flex: '1 1 140px', marginBottom: 0 }}
                >
                    <label>Status</label>
                    <select
                        value={filterStatus}
                        onChange={(e) => setFilterStatus(e.target.value)}
                    >
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="draft">Draft</option>
                        <option value="closed">Closed</option>
                    </select>
                </div>

                {hasFilters && (
                    <button
                        className="btn btn-ghost btn-sm"
                        onClick={() => {
                            setFilterSearch('');
                            setFilterSession('');
                            setFilterStatus('');
                        }}
                        style={{ marginBottom: 0, whiteSpace: 'nowrap' }}
                    >
                        ✕ Clear
                    </button>
                )}
            </div>

            {/* ── Subject cards ────────────────────────────────────── */}
            {fetching ? (
                <div
                    style={{
                        padding: 40,
                        textAlign: 'center',
                        color: 'var(--text3)',
                    }}
                >
                    Loading…
                </div>
            ) : filtered.length === 0 ? (
                <div className="card">
                    <div
                        style={{
                            textAlign: 'center',
                            padding: '40px 0',
                            color: 'var(--text3)',
                        }}
                    >
                        <div style={{ fontSize: 32, marginBottom: 8 }}>📚</div>
                        <div style={{ fontWeight: 600, marginBottom: 4 }}>
                            {hasFilters
                                ? 'No subjects match your filters'
                                : 'No subjects assigned yet'}
                        </div>
                        <div style={{ fontSize: 13 }}>
                            {hasFilters
                                ? 'Try adjusting your search or filters'
                                : 'Contact your admin to get assigned to a curriculum'}
                        </div>
                    </div>
                </div>
            ) : (
                <div
                    style={{
                        display: 'flex',
                        flexDirection: 'column',
                        gap: 10,
                    }}
                >
                    {filtered.map((tcs) => (
                        <SubjectCard
                            key={tcs.id}
                            tcs={tcs}
                            addToast={addToast}
                            onComponentsChange={handleComponentsChange}
                        />
                    ))}
                </div>
            )}
        </>
    );
}
