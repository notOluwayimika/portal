// import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { useState, useEffect } from 'react';
import { toast } from 'react-toastify';
import { generateSlug } from '@/helpers';
import type { AcademicSession } from '@/types/models';
import { Pagination } from './pagination';

// ─── Types ────────────────────────────────────────────────────────────────────

type FormMode = 'create' | 'edit';

interface FormState {
    name: string;
    slug: string;
    is_current: boolean;
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

// ─── Sub-components ───────────────────────────────────────────────────────────

function Badge({ isCurrent }: { isCurrent: boolean }) {
    return (
        <span
            style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: 4,
                fontSize: 11,
                fontWeight: 600,
                letterSpacing: '0.04em',
                padding: '3px 9px',
                borderRadius: 20,
                background: isCurrent ? '#EAF3DE' : '#F1EFE8',
                color: isCurrent ? '#3B6D11' : '#5F5E5A',
                border: `1px solid ${isCurrent ? '#C0DD97' : '#D3D1C7'}`,
                textTransform: 'uppercase',
            }}
        >
            {isCurrent && (
                <span
                    style={{
                        width: 6,
                        height: 6,
                        borderRadius: '50%',
                        background: '#639922',
                        display: 'inline-block',
                    }}
                />
            )}
            {isCurrent ? 'Current' : 'Inactive'}
        </span>
    );
}

function IconEdit() {
    return (
        <svg
            width="15"
            height="15"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
        </svg>
    );
}

function IconTrash() {
    return (
        <svg
            width="15"
            height="15"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <polyline points="3 6 5 6 21 6" />
            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
            <path d="M10 11v6M14 11v6" />
            <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" />
        </svg>
    );
}

function IconPlus() {
    return (
        <svg
            width="15"
            height="15"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2.5"
            strokeLinecap="round"
        >
            <line x1="12" y1="5" x2="12" y2="19" />
            <line x1="5" y1="12" x2="19" y2="12" />
        </svg>
    );
}

function IconClose() {
    return (
        <svg
            width="14"
            height="14"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2.5"
            strokeLinecap="round"
        >
            <line x1="18" y1="6" x2="6" y2="18" />
            <line x1="6" y1="6" x2="18" y2="18" />
        </svg>
    );
}

// ─── Form Modal ───────────────────────────────────────────────────────────────

interface SessionFormProps {
    mode: FormMode;
    initial?: Partial<AcademicSession>;
    onSave: (data: FormState) => void;
    onCancel: () => void;
    slugExists: (slug: string, excludeId?: string) => boolean;
}

function SessionForm({
    mode,
    initial,
    onSave,
    onCancel,
    slugExists,
}: SessionFormProps) {
    const [form, setForm] = useState<FormState>({
        name: initial?.name ?? '',
        slug: initial?.slug ?? '',
        is_current: initial?.is_current ?? false,
    });
    const [errors, setErrors] = useState<
        Partial<Record<keyof FormState, string>>
    >({});
    const [slugManual, setSlugManual] = useState(false);

    function handleNameChange(val: string) {
        const updated: FormState = { ...form, name: val };

        if (!slugManual) {
            updated.slug = generateSlug(val);
        }

        setForm(updated);

        if (errors.name) {
            setErrors((e) => ({ ...e, name: undefined }));
        }
    }

    function handleSlugChange(val: string) {
        setSlugManual(true);
        setForm((f) => ({ ...f, slug: val }));

        if (errors.slug) {
            setErrors((e) => ({ ...e, slug: undefined }));
        }
    }

    function validate(): boolean {
        const errs: Partial<Record<keyof FormState, string>> = {};

        if (!form.name.trim()) {
            errs.name = 'Name is required.';
        }

        if (!form.slug.trim()) {
            errs.slug = 'Slug is required.';
        } else if (!/^[a-z0-9-]+$/.test(form.slug)) {
            errs.slug =
                'Slug must be lowercase letters, numbers, and hyphens only.';
        } else if (slugExists(form.slug, initial?.id)) {
            errs.slug = 'This slug is already taken.';
        }

        setErrors(errs);

        return Object.keys(errs).length === 0;
    }

    function handleSubmit() {
        if (validate()) {
            onSave(form);
        }
    }

    const inputStyle: React.CSSProperties = {
        width: '100%',
        padding: '9px 12px',
        fontSize: 14,
        border: '1.5px solid #E5E7EB',
        borderRadius: 8,
        outline: 'none',
        background: '#FFFFFF',
        color: '#111827',
        boxSizing: 'border-box',
        transition: 'border-color 0.15s',
        fontFamily: 'inherit',
    };

    const errorStyle: React.CSSProperties = {
        fontSize: 12,
        color: '#DC2626',
        marginTop: 4,
    };

    const labelStyle: React.CSSProperties = {
        display: 'block',
        fontSize: 13,
        fontWeight: 600,
        color: '#374151',
        marginBottom: 5,
        letterSpacing: '0.01em',
    };

    return (
        <div
            style={{
                position: 'fixed',
                inset: 0,
                background: 'rgba(17, 24, 39, 0.45)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                zIndex: 50,
                padding: 16,
            }}
            onClick={(e) => e.target === e.currentTarget && onCancel()}
        >
            <div
                style={{
                    background: '#FFFFFF',
                    borderRadius: 14,
                    width: '100%',
                    maxWidth: 480,
                    boxShadow: '0 20px 60px rgba(43,25,122,0.15)',
                    overflow: 'hidden',
                }}
            >
                {/* Header */}
                <div
                    style={{
                        background: '#2B197A',
                        padding: '18px 24px',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                    }}
                >
                    <div>
                        <p
                            style={{
                                margin: 0,
                                fontSize: 11,
                                color: '#9B8FD4',
                                textTransform: 'uppercase',
                                letterSpacing: '0.08em',
                                fontWeight: 600,
                            }}
                        >
                            Academic Sessions
                        </p>
                        <h2
                            style={{
                                margin: '2px 0 0',
                                color: '#FFFFFF',
                                fontSize: 18,
                                fontWeight: 600,
                            }}
                        >
                            {mode === 'create' ? 'New Session' : 'Edit Session'}
                        </h2>
                    </div>
                    <button
                        onClick={onCancel}
                        style={{
                            background: 'rgba(255,255,255,0.12)',
                            border: 'none',
                            borderRadius: 8,
                            color: '#FFFFFF',
                            cursor: 'pointer',
                            width: 32,
                            height: 32,
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                        }}
                    >
                        <IconClose />
                    </button>
                </div>

                {/* Body */}
                <div style={{ padding: '24px 24px 20px' }}>
                    {/* Name */}
                    <div style={{ marginBottom: 18 }}>
                        <label style={labelStyle}>Session Name</label>
                        <input
                            type="text"
                            value={form.name}
                            onChange={(e) => handleNameChange(e.target.value)}
                            placeholder="e.g. 1st Term 2025/2026"
                            style={{
                                ...inputStyle,
                                borderColor: errors.name
                                    ? '#DC2626'
                                    : '#E5E7EB',
                            }}
                        />
                        {errors.name && <p style={errorStyle}>{errors.name}</p>}
                    </div>

                    {/* Slug */}
                    <div style={{ marginBottom: 18 }}>
                        <label style={labelStyle}>
                            Slug
                            <span
                                style={{
                                    fontWeight: 400,
                                    color: '#9CA3AF',
                                    marginLeft: 6,
                                    fontSize: 12,
                                }}
                            >
                                auto-generated
                            </span>
                        </label>
                        <input
                            type="text"
                            value={form.slug}
                            onChange={(e) => handleSlugChange(e.target.value)}
                            placeholder="1st-term-2025-2026"
                            style={{
                                ...inputStyle,
                                fontFamily: 'monospace',
                                fontSize: 13,
                                background: '#F8F9FC',
                                borderColor: errors.slug
                                    ? '#DC2626'
                                    : '#E5E7EB',
                            }}
                        />
                        {errors.slug && <p style={errorStyle}>{errors.slug}</p>}
                    </div>

                    {/* Is Current */}
                    <label
                        style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: 10,
                            cursor: 'pointer',
                            padding: '12px 14px',
                            border: `1.5px solid ${form.is_current ? '#4C3AA3' : '#E5E7EB'}`,
                            borderRadius: 8,
                            background: form.is_current ? '#EEEDFE' : '#F8F9FC',
                            transition: 'all 0.15s',
                            marginBottom: 4,
                        }}
                    >
                        <input
                            type="checkbox"
                            checked={form.is_current}
                            onChange={(e) =>
                                setForm((f) => ({
                                    ...f,
                                    is_current: e.target.checked,
                                }))
                            }
                            style={{
                                accentColor: '#2B197A',
                                width: 16,
                                height: 16,
                            }}
                        />
                        <div>
                            <p
                                style={{
                                    margin: 0,
                                    fontSize: 14,
                                    fontWeight: 600,
                                    color: form.is_current
                                        ? '#2B197A'
                                        : '#374151',
                                }}
                            >
                                Mark as current session
                            </p>
                            <p
                                style={{
                                    margin: '1px 0 0',
                                    fontSize: 12,
                                    color: '#6B7280',
                                }}
                            >
                                Setting this will update the active session for
                                the school
                            </p>
                        </div>
                    </label>
                </div>

                {/* Footer */}
                <div
                    style={{
                        padding: '16px 24px',
                        borderTop: '1px solid #E5E7EB',
                        display: 'flex',
                        gap: 10,
                        justifyContent: 'flex-end',
                    }}
                >
                    <button
                        onClick={onCancel}
                        style={{
                            padding: '9px 18px',
                            fontSize: 14,
                            fontWeight: 500,
                            border: '1.5px solid #E5E7EB',
                            borderRadius: 8,
                            background: '#FFFFFF',
                            color: '#374151',
                            cursor: 'pointer',
                            fontFamily: 'inherit',
                        }}
                    >
                        Cancel
                    </button>
                    <button
                        onClick={handleSubmit}
                        style={{
                            padding: '9px 22px',
                            fontSize: 14,
                            fontWeight: 600,
                            border: 'none',
                            borderRadius: 8,
                            background: '#2B197A',
                            color: '#FFFFFF',
                            cursor: 'pointer',
                            fontFamily: 'inherit',
                        }}
                    >
                        {mode === 'create' ? 'Create Session' : 'Save Changes'}
                    </button>
                </div>
            </div>
        </div>
    );
}

// ─── Delete Confirm ───────────────────────────────────────────────────────────

function DeleteConfirm({
    session,
    onConfirm,
    onCancel,
}: {
    session: Partial<AcademicSession>;
    onConfirm: () => void;
    onCancel: () => void;
}) {
    return (
        <div
            style={{
                position: 'fixed',
                inset: 0,
                background: 'rgba(17, 24, 39, 0.45)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                zIndex: 50,
                padding: 16,
            }}
        >
            <div
                style={{
                    background: '#FFFFFF',
                    borderRadius: 14,
                    width: '100%',
                    maxWidth: 400,
                    padding: '28px 28px 24px',
                    boxShadow: '0 20px 60px rgba(220,38,38,0.12)',
                }}
            >
                <div
                    style={{
                        width: 48,
                        height: 48,
                        borderRadius: '50%',
                        background: '#FEF2F2',
                        border: '1px solid #FECACA',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        marginBottom: 16,
                    }}
                >
                    <svg
                        width="22"
                        height="22"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="#DC2626"
                        strokeWidth="2"
                        strokeLinecap="round"
                    >
                        <polyline points="3 6 5 6 21 6" />
                        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
                        <path d="M10 11v6M14 11v6" />
                        <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" />
                    </svg>
                </div>
                <h3
                    style={{
                        margin: '0 0 6px',
                        fontSize: 17,
                        fontWeight: 600,
                        color: '#111827',
                    }}
                >
                    Delete Session?
                </h3>
                <p
                    style={{
                        margin: '0 0 20px',
                        fontSize: 14,
                        color: '#6B7280',
                        lineHeight: 1.6,
                    }}
                >
                    <strong style={{ color: '#111827' }}>{session.name}</strong>{' '}
                    will be permanently deleted. This action cannot be undone.
                </p>
                <div
                    style={{
                        display: 'flex',
                        gap: 10,
                        justifyContent: 'flex-end',
                    }}
                >
                    <button
                        onClick={onCancel}
                        style={{
                            padding: '9px 18px',
                            fontSize: 14,
                            fontWeight: 500,
                            border: '1.5px solid #E5E7EB',
                            borderRadius: 8,
                            background: '#FFFFFF',
                            color: '#374151',
                            cursor: 'pointer',
                            fontFamily: 'inherit',
                        }}
                    >
                        Cancel
                    </button>
                    <button
                        onClick={onConfirm}
                        style={{
                            padding: '9px 18px',
                            fontSize: 14,
                            fontWeight: 600,
                            border: 'none',
                            borderRadius: 8,
                            background: '#DC2626',
                            color: '#FFFFFF',
                            cursor: 'pointer',
                            fontFamily: 'inherit',
                        }}
                    >
                        Delete
                    </button>
                </div>
            </div>
        </div>
    );
}

// ─── Main Component ───────────────────────────────────────────────────────────

export default function AcademicSessions() {
    const [sessions, setSessions] = useState<Partial<AcademicSession>[]>([]);
    const [modalMode, setModalMode] = useState<FormMode | null>(null);
    const [editTarget, setEditTarget] =
        useState<Partial<AcademicSession> | null>(null);
    const [deleteTarget, setDeleteTarget] =
        useState<Partial<AcademicSession> | null>(null);
    const [search, setSearch] = useState('');
    const [total, setTotal] = useState(0);
    const [filter, setFilter] = useState('total');
    // const [totalActive, setTotalActive] = useState(0);
    // const [totalInactive, setTotalInactive] = useState(0);
    const [loading, setLoading] = useState(false);
    const [limit, setLimit] = useState(5);
    const [page, setPage] = useState(1);
    const [paginationMeta, setPaginationMeta] = useState({
        current_page: 1,
        last_page: 1,
        per_page: 10,
        total: 0,
    });
    // const { auth } = usePage().props;

    useEffect(() => {
        const fetchSessions = async () => {
            const response = await axios.get('/api/sessions', {
                params: { limit, page, filter, search },
            });

            if (response.status === 200) {
                setSessions(response.data.data ?? []);
                setTotal(response.data.total ?? 0);
                setPaginationMeta(response.data ?? paginationMeta);
            } else {
                toast.error('Failed to fetch academic sessions.');
            }
        };

        fetchSessions();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [limit, page, filter, search, loading]);




    function slugExists(slug: string, excludeId?: string) {
        return sessions.some((s) => s.slug === slug && s.id !== excludeId);
    }

    async function handleCreate(data: FormState) {
        const newSession: Partial<AcademicSession> = {
            name: data.name,
            slug: data.slug,
            is_current: data.is_current,
        };

        try {
            setLoading(true);
            const response = await axios.post('/api/sessions', newSession);

            if (response.status === 200) {
                setModalMode(null);
                toast.success(`"${data.name}" created successfully.`);
            }
        } catch (error) {
            console.log(error);
            toast.error('Failed to create academic session.');
        } finally {
            setLoading(false);
        }
    }

    async function handleUpdate(data: FormState) {
        if (!editTarget) {
            return;
        }

        try {
            setLoading(true);
            const response = await axios.put(
                `/api/sessions/${editTarget.id}`,
                data,
            );

            if (response.status === 200) {
                setModalMode(null);
                setEditTarget(null);
                toast.info(`"${data.name}" updated.`);
            } else {
                toast.error('Failed to update academic session.');
            }
        } catch (error) {
            console.log(error);
            toast.error('Failed to update academic session.');
        } finally {
            setLoading(false);
        }
    }

    async function handleDelete() {
        if (!deleteTarget) {
            return;
        }

        try {
            setLoading(true);
            const response = await axios.delete(
                `/api/sessions/${deleteTarget.id}`,
            );

            if (response.status === 200) {
                toast.error(`"${deleteTarget.name}" deleted.`);
                setDeleteTarget(null);
            } else {
                toast.error('Failed to delete academic session.');
            }
        } catch (error) {
            console.log(error);
            toast.error('Failed to delete academic session.');
        } finally {
            setLoading(false);
        }
    }

    function openEdit(session: Partial<AcademicSession>) {
        setEditTarget(session);
        setModalMode('edit');
    }

    const filtered = sessions.filter(
        (s) =>
            (s?.name ?? '').toLowerCase().includes(search.toLowerCase()) ||
            (s?.slug ?? '').toLowerCase().includes(search.toLowerCase()),
    );

    return (
        <div
            style={{
                fontFamily:
                    "'Inter', -apple-system, BlinkMacSystemFont, sans-serif",
                minHeight: '100vh',
                background: '#F8F9FC',
                padding: '32px 24px',
            }}
        >
            <style>{`
        @keyframes slideIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
        button:hover { opacity: 0.88; }
        input:focus { border-color: #4C3AA3 !important; box-shadow: 0 0 0 3px rgba(76,58,163,0.12); }
      `}</style>

            <div style={{ maxWidth: 760, margin: '0 auto' }}>
                {/* Header */}
                <div style={{ marginBottom: 28 }}>
                    <div
                        style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: 10,
                            marginBottom: 4,
                        }}
                    >
                        <h1
                            style={{
                                margin: 0,
                                fontSize: 22,
                                fontWeight: 700,
                                color: '#111827',
                            }}
                        >
                            Academic Sessions
                        </h1>
                    </div>
                    <p style={{ margin: 0, fontSize: 14, color: '#6B7280' }}>
                        Manage academic terms and sessions for your school.
                    </p>
                </div>

                {/* Stats bar */}
                <div
                    style={{
                        display: 'grid',
                        gridTemplateColumns: 'repeat(3, 1fr)',
                        gap: 12,
                        marginBottom: 24,
                    }}
                >
                    {[
                        {
                            label: 'Total',
                            value: total,
                            color: '#2B197A',
                        },
                        {
                            label: 'Inactive',
                            value: sessions.filter((s) => !s.is_current).length,
                            color: '#6B7280',
                        },
                        {
                            label: 'Active',
                            value: sessions.filter((s) => s.is_current).length,
                            color: '#10B981',
                        },
                    ].map((stat) => (
                        <div
                            key={stat.label}
                            onClick={() => setFilter(stat.label.toLowerCase())}
                            className={`cursor-pointer transition-transform hover:scale-105 ${filter === stat.label.toLowerCase() ? 'scale-105 ring-2 ring-primary' : ''}`}
                            style={{
                                background: '#FFFFFF',
                                border: '1px solid #E5E7EB',
                                borderRadius: 10,
                                padding: '14px 16px',
                            }}
                        >
                            <p
                                style={{
                                    margin: '0 0 4px',
                                    fontSize: 11,
                                    color: '#9CA3AF',
                                    textTransform: 'uppercase',
                                    fontWeight: 600,
                                    letterSpacing: '0.06em',
                                }}
                            >
                                {stat.label}
                            </p>
                            <p
                                style={{
                                    margin: 0,
                                    fontSize: 20,
                                    fontWeight: 700,
                                    color: stat.color,
                                }}
                            >
                                {stat.value}
                            </p>
                        </div>
                    ))}
                </div>

                {/* Toolbar */}
                <div
                    style={{
                        display: 'flex',
                        gap: 10,
                        marginBottom: 16,
                        alignItems: 'center',
                    }}
                >
                    <div style={{ flex: 1, position: 'relative' }}>
                        <svg
                            width="16"
                            height="16"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="#9CA3AF"
                            strokeWidth="2"
                            strokeLinecap="round"
                            style={{
                                position: 'absolute',
                                left: 11,
                                top: '50%',
                                transform: 'translateY(-50%)',
                                pointerEvents: 'none',
                            }}
                        >
                            <circle cx="11" cy="11" r="8" />
                            <line x1="21" y1="21" x2="16.65" y2="16.65" />
                        </svg>
                        <input
                            type="text"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search sessions..."
                            style={{
                                width: '100%',
                                boxSizing: 'border-box',
                                padding: '9px 12px 9px 34px',
                                fontSize: 14,
                                border: '1.5px solid #E5E7EB',
                                borderRadius: 8,
                                background: '#FFFFFF',
                                color: '#111827',
                                fontFamily: 'inherit',
                                outline: 'none',
                            }}
                        />
                    </div>
                    <button
                        onClick={() => setModalMode('create')}
                        style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: 7,
                            padding: '9px 18px',
                            fontSize: 14,
                            fontWeight: 600,
                            background: '#2B197A',
                            color: '#FFFFFF',
                            border: 'none',
                            borderRadius: 8,
                            cursor: 'pointer',
                            whiteSpace: 'nowrap',
                            fontFamily: 'inherit',
                        }}
                    >
                        <IconPlus />
                        New Session
                    </button>
                </div>

                {/* Session List */}
                <div
                    style={{
                        background: '#FFFFFF',
                        border: '1px solid #E5E7EB',
                        borderRadius: 12,
                        overflow: 'hidden',
                    }}
                >
                    {filtered.length === 0 ? (
                        <div
                            style={{
                                padding: '48px 24px',
                                textAlign: 'center',
                            }}
                        >
                            <p
                                style={{
                                    fontSize: 15,
                                    color: '#9CA3AF',
                                    margin: 0,
                                }}
                            >
                                {search
                                    ? `No sessions match "${search}"`
                                    : 'No academic sessions yet. Create one to get started.'}
                            </p>
                        </div>
                    ) : (
                        filtered.map((session, idx) => (
                            <div
                                key={session.id}
                                style={{
                                    display: 'flex',
                                    alignItems: 'center',
                                    padding: '16px 20px',
                                    borderBottom:
                                        idx < filtered.length - 1
                                            ? '1px solid #F3F4F6'
                                            : 'none',
                                    borderLeft: session.is_current
                                        ? '3px solid #2B197A'
                                        : '3px solid transparent',
                                    background: session.is_current
                                        ? '#FAFAFE'
                                        : '#FFFFFF',
                                    transition: 'background 0.15s',
                                }}
                            >
                                <div style={{ flex: 1, minWidth: 0 }}>
                                    <div
                                        style={{
                                            display: 'flex',
                                            alignItems: 'center',
                                            gap: 10,
                                            marginBottom: 4,
                                            flexWrap: 'wrap',
                                        }}
                                    >
                                        <span
                                            style={{
                                                fontSize: 15,
                                                fontWeight: 600,
                                                color: '#111827',
                                            }}
                                        >
                                            {session.name}
                                        </span>
                                        <Badge
                                            isCurrent={
                                                session.is_current ?? false
                                            }
                                        />
                                    </div>
                                    <span
                                        style={{
                                            fontFamily: 'monospace',
                                            fontSize: 12,
                                            background: '#F3F4F6',
                                            color: '#6B7280',
                                            padding: '2px 7px',
                                            borderRadius: 5,
                                            border: '1px solid #E5E7EB',
                                        }}
                                    >
                                        {session.slug}
                                    </span>
                                </div>

                                <div
                                    style={{
                                        display: 'flex',
                                        gap: 6,
                                        marginLeft: 12,
                                    }}
                                >
                                    <button
                                        onClick={() => openEdit(session)}
                                        title="Edit"
                                        style={{
                                            display: 'flex',
                                            alignItems: 'center',
                                            justifyContent: 'center',
                                            width: 34,
                                            height: 34,
                                            border: '1.5px solid #E5E7EB',
                                            borderRadius: 8,
                                            background: '#FFFFFF',
                                            color: '#374151',
                                            cursor: 'pointer',
                                        }}
                                    >
                                        <IconEdit />
                                    </button>
                                    <button
                                        onClick={() => setDeleteTarget(session)}
                                        title="Delete"
                                        style={{
                                            display: 'flex',
                                            alignItems: 'center',
                                            justifyContent: 'center',
                                            width: 34,
                                            height: 34,
                                            border: '1.5px solid #FCA5A5',
                                            borderRadius: 8,
                                            background: '#FFF5F5',
                                            color: '#DC2626',
                                            cursor: 'pointer',
                                        }}
                                    >
                                        <IconTrash />
                                    </button>
                                </div>
                            </div>
                        ))
                    )}
                </div>

                {/* Count */}
                {filtered.length > 0 && (
                    <p
                        style={{
                            margin: '10px 0 0',
                            fontSize: 12,
                            color: '#9CA3AF',
                            textAlign: 'right',
                        }}
                    >
                        {filtered.length} session
                        {filtered.length !== 1 ? 's' : ''}
                    </p>
                )}
                <Pagination
                    meta={paginationMeta}
                    setPage={setPage}
                    setLimit={setLimit}
                />
            </div>

            {/* Modals */}
            {modalMode === 'create' && (
                <SessionForm
                    mode="create"
                    onSave={handleCreate}
                    onCancel={() => setModalMode(null)}
                    slugExists={slugExists}
                />
            )}
            {modalMode === 'edit' && editTarget && (
                <SessionForm
                    mode="edit"
                    initial={editTarget}
                    onSave={handleUpdate}
                    onCancel={() => {
                        setModalMode(null);
                        setEditTarget(null);
                    }}
                    slugExists={slugExists}
                />
            )}
            {deleteTarget && (
                <DeleteConfirm
                    session={deleteTarget}
                    onConfirm={handleDelete}
                    onCancel={() => setDeleteTarget(null)}
                />
            )}

        </div>
    );
}
