import axios from 'axios';
import { useEffect, useState } from 'react';
import { Confirm, Empty, Modal } from '@/pages/admin/school-setup';
import type { Session } from '@/types/models';
import { Pagination } from '../pagination';
import type { ToastType } from '../toast-item';

interface SessionForm {
    name: string;
    is_current: boolean;
}

export function SessionsTab({
    addToast,
}: {
    addToast: (message: string, type?: ToastType) => void;
}) {
    const [sessions, setSessions] = useState<Session[]>([]);
    const [modal, setModal] = useState<string | null>(null);
    const [form, setForm] = useState<SessionForm>({
        name: '',
        is_current: false,
    });
    const [confirm, setConfirm] = useState<Session | null>(null);
    const [search, setSearch] = useState('');
    const [total, setTotal] = useState(0);
    const [filter, setFilter] = useState('total');
    const [totalActive, setTotalActive] = useState(0);
    const [totalInactive, setTotalInactive] = useState(0);
    const [loading, setLoading] = useState(false);
    const [limit, setLimit] = useState(5);
    const [page, setPage] = useState(1);
    const [paginationMeta, setPaginationMeta] = useState({
        current_page: 1,
        last_page: 1,
        per_page: 10,
        total: 0,
    });

    useEffect(() => {
        const fetchSessions = async () => {
            const response = await axios.get('/api/sessions', {
                params: { limit, page, filter, search },
            });

            if (response.status === 200) {
                setSessions(response.data.sessions.data ?? []);
                setTotal(response.data.stats.total ?? 0);
                setTotalActive(response.data.stats.active ?? 0);
                setTotalInactive(response.data.stats.inactive ?? 0);
                setPaginationMeta(response.data.sessions ?? paginationMeta);
            } else {
                addToast('Failed to fetch academic sessions.', 'error');
            }
        };

        fetchSessions();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [limit, page, filter, search, loading]);

    const open = (s: Session | null = null): void => {
        setForm(
            s
                ? { name: s.name, is_current: s.is_current }
                : { name: '', is_current: false },
        );
        setModal(s ? s.id : 'new');
    };

    async function handleCreate(data: SessionForm) {
        const newSession: Partial<Session> = {
            name: data.name,
            is_current: data.is_current,
        };

        try {
            setLoading(true);
            const response = await axios.post('/api/sessions', newSession);
            console.log(response);

            if (response.status === 201) {
                setModal(null);
                addToast(`"${data.name}" created successfully.`);
            }
        } catch (error) {
            console.log(error);
            addToast('Failed to create academic session.', 'error');
        } finally {
            setLoading(false);
        }
    }
    async function handleUpdate(data: SessionForm) {
        if (!modal || modal === 'new') {
            return;
        }

        try {
            setLoading(true);
            const response = await axios.put(`/api/sessions/${modal}`, data);

            if (response.status === 200) {
                setModal(null);
                addToast(`"${data.name}" updated.`, 'info');
            } else {
                addToast('Failed to update academic session.', 'error');
            }
        } catch (error) {
            console.log(error);
            addToast('Failed to update academic session.', 'error');
        } finally {
            setLoading(false);
        }
    }
    async function handleDelete(deleteTarget: string | null) {
        if (!deleteTarget) {
            return;
        }

        try {
            setLoading(true);
            const response = await axios.delete(
                `/api/sessions/${deleteTarget}`,
            );

            if (response.status === 200) {
                addToast(`session deleted.`, 'error');
                setConfirm(null);
            } else {
                addToast('Failed to delete academic session.', 'error');
            }
        } catch (error) {
            console.log(error);
            addToast('Failed to delete academic session.', 'error');
        } finally {
            setLoading(false);
        }
    }

    const save = async (): Promise<void> => {
        if (!form.name.trim()) {
            return;
        }

        if (modal === 'new') {
            handleCreate(form);
        } else {
            handleUpdate(form);
        }
    };

    const setCurrent = async (id: string): Promise<void> => {
        setLoading(true);
        const response = await axios.post(`/api/sessions/${id}/current`);

        if (response.status === 200) {
            addToast(`Session set as current.`, 'info');
        } else {
            addToast('Failed to set session as current.', 'error');
        }

        setLoading(false);
    };

    const filtered =
        sessions?.filter((s) =>
            (s?.name ?? '').toLowerCase().includes(search.toLowerCase()),
        ) ?? [];

    return (
        <>
            <div className="page-hdr">
                <div>
                    <h1>Academic Sessions</h1>
                    <p>Manage academic years</p>
                </div>
                <div className="page-hdr-actions">
                    <button className="btn btn-primary" onClick={() => open()}>
                        + New Session
                    </button>
                </div>
            </div>
            {/* search bar */}
            <div className="my-4" style={{ flex: 1, position: 'relative' }}>
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
                        value: totalInactive,
                        color: '#6B7280',
                    },
                    {
                        label: 'Active',
                        value: totalActive,
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
            <div className="card">
                <div className="tbl-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Session</th>
                                <th>Status</th>
                                <th style={{ textAlign: 'right' }}>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {sessions.length === 0 && (
                                <tr>
                                    <td colSpan={3}>
                                        <Empty
                                            icon="📅"
                                            title="No sessions yet"
                                            sub="Create your first academic session"
                                        />
                                    </td>
                                </tr>
                            )}
                            {sessions.map((s) => (
                                <tr key={s.id}>
                                    <td
                                        style={{
                                            fontFamily: 'var(--mono)',
                                            fontWeight: 600,
                                        }}
                                    >
                                        {s.name}
                                    </td>
                                    <td>
                                        {s.is_current ? (
                                            <span className="pill pill-green">
                                                ● Current
                                            </span>
                                        ) : (
                                            <span className="pill pill-slate">
                                                Inactive
                                            </span>
                                        )}
                                    </td>
                                    <td>
                                        <div
                                            className="row-actions"
                                            style={{
                                                justifyContent: 'flex-end',
                                            }}
                                        >
                                            {!s.is_current && (
                                                <button
                                                    className="btn btn-outline btn-sm"
                                                    onClick={() =>
                                                        setCurrent(s.id)
                                                    }
                                                >
                                                    Set current
                                                </button>
                                            )}
                                            <button
                                                className="btn btn-ghost btn-sm btn-icon"
                                                onClick={() => open(s)}
                                            >
                                                ✏️
                                            </button>
                                            <button
                                                className="btn btn-danger btn-sm btn-icon"
                                                onClick={() => setConfirm(s)}
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
            {modal && (
                <Modal
                    title={modal === 'new' ? 'New Session' : 'Edit Session'}
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
                                Save
                            </button>
                        </>
                    }
                >
                    <div className="field">
                        <label>Session name</label>
                        <input
                            placeholder="e.g. 2026/2027"
                            value={form.name}
                            onChange={(e) =>
                                setForm((p) => ({ ...p, name: e.target.value }))
                            }
                            autoFocus
                        />
                    </div>
                    <label
                        style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: 8,
                            cursor: 'pointer',
                            fontSize: 13.5,
                            color: 'var(--text)',
                            fontWeight: 500,
                        }}
                    >
                        <input
                            type="checkbox"
                            checked={form.is_current}
                            onChange={(e) =>
                                setForm((p) => ({
                                    ...p,
                                    is_current: e.target.checked,
                                }))
                            }
                        />
                        Set as current session
                    </label>
                </Modal>
            )}
            {confirm && (
                <Confirm
                    msg={`Delete session "${confirm.name}"? This cannot be undone.`}
                    onConfirm={() => {
                        handleDelete(confirm.id);
                    }}
                    onClose={() => setConfirm(null)}
                />
            )}
        </>
    );
}
