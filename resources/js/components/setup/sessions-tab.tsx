import axios from 'axios';
import { BookOpen, Pencil, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'react-toastify';
import { Confirm, Empty, Modal } from '@/pages/admin/school-setup';
import type { Session, Term } from '@/types/models';
import { Pagination } from '../pagination';

interface SessionForm {
    name: string;
    is_current: boolean;
}

type TermStatus = 'active' | 'upcoming' | 'completed';

interface TermForm {
    name: string;
    order: number;
    start_date: string;
    end_date: string;
    result_visible_at: string;
    registration_deadline: string;
    status: string;
}

const emptyTermForm: TermForm = {
    name: '',
    order: 1,
    start_date: '',
    end_date: '',
    result_visible_at: '',
    registration_deadline: '',
    status: 'upcoming',
};

export function SessionsTab({
    setSessionName,
}: {
    setSessionName: (name: string) => void;
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
    const [limit, setLimit] = useState(10);
    const [page, setPage] = useState(1);
    const [paginationMeta, setPaginationMeta] = useState({
        current_page: 1,
        last_page: 1,
        per_page: 10,
        total: 0,
    });

    // Terms state
    const [termsSession, setTermsSession] = useState<Session | null>(null);
    const [terms, setTerms] = useState<Term[]>([]);
    const [termsLoading, setTermsLoading] = useState(false);
    const [termModal, setTermModal] = useState<string | null>(null); // 'new' or term id
    const [termForm, setTermForm] = useState<TermForm>(emptyTermForm);
    const [termConfirm, setTermConfirm] = useState<Term | null>(null);
    const [termSaving, setTermSaving] = useState(false);

    useEffect(() => {
        const fetchSessions = async () => {
            const response = await axios.get('/api/sessions', {
                params: { limit, page, filter, search },
            });

            if (response.status === 200) {
                setSessions(response.data.sessions ?? []);
                setTotal(response.data.stats.total ?? 0);
                setTotalActive(response.data.stats.active ?? 0);
                setTotalInactive(response.data.stats.inactive ?? 0);
                setPaginationMeta(response.data.pagination ?? paginationMeta);
            } else {
                toast.error('Failed to fetch academic sessions.');
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

            if (response.status === 201) {
                setModal(null);
                toast.success(`"${data.name}" created successfully.`);
            }
        } catch (error) {
            console.log(error);
            toast.error('Failed to create academic session.');
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
                toast.error(`Session deleted.`);
                setConfirm(null);
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
            toast.info(`Session set as current.`);
            setSessionName(response.data.name);
        } else {
            toast.error('Failed to set session as current.');
        }

        setLoading(false);
    };

    // -------------------- Terms --------------------
    const fetchTerms = async (sessionId: string) => {
        try {
            setTermsLoading(true);
            const response = await axios.get(
                `/api/sessions/${sessionId}/terms`,
            );

            if (response.status === 200) {
                setTerms(response.data.terms ?? response.data.data ?? []);
            } else {
                toast.error('Failed to fetch terms.');
            }
        } catch (error) {
            console.log(error);
            toast.error('Failed to fetch terms.');
        } finally {
            setTermsLoading(false);
        }
    };

    const openTerms = (s: Session): void => {
        setTermsSession(s);
        setTerms([]);
        fetchTerms(s.id);
    };

    const closeTerms = (): void => {
        setTermsSession(null);
        setTerms([]);
        setTermModal(null);
        setTermConfirm(null);
    };

    const openTermForm = (t: Term | null = null): void => {
        if (t) {
            setTermForm({
                name: t.name,
                order: t.order,
                start_date: (t.start_date ?? '').slice(0, 10),
                end_date: (t.end_date ?? '').slice(0, 10),
                result_visible_at: (t.result_visible_at ?? '').slice(0, 10),
                registration_deadline: (t.registration_deadline ?? '').slice(
                    0,
                    10,
                ),
                status: t.status,
            });
            setTermModal(t.id);
        } else {
            const nextOrder =
                terms.length > 0
                    ? Math.max(...terms.map((x) => x.order)) + 1
                    : 1;
            setTermForm({ ...emptyTermForm, order: nextOrder });
            setTermModal('new');
        }
    };

    const saveTerm = async (): Promise<void> => {
        if (!termsSession) {
            return;
        }

        if (!termForm.name.trim()) {
            toast.error('Term name is required.');

            return;
        }

        if (!termForm.start_date || !termForm.end_date) {
            toast.error('Start and end dates are required.');

            return;
        }

        if (termForm.end_date < termForm.start_date) {
            toast.error('End date must be after start date.');

            return;
        }

        try {
            setTermSaving(true);

            if (termModal === 'new') {
                const response = await axios.post(
                    `/api/sessions/${termsSession.id}/terms`,
                    termForm,
                );

                if (response.status === 201 || response.status === 200) {
                    toast.success(`"${termForm.name}" term created.`);
                    setTermModal(null);
                    fetchTerms(termsSession.id);
                }
            } else if (termModal) {
                const response = await axios.put(
                    `/api/sessions/${termsSession.id}/terms/${termModal}`,
                    termForm,
                );

                if (response.status === 200) {
                    toast.info(`"${termForm.name}" term updated.`);
                    setTermModal(null);
                    fetchTerms(termsSession.id);
                }
            }
        } catch (error) {
            console.log(error);
            toast.error('Failed to save term.');
        } finally {
            setTermSaving(false);
        }
    };

    const deleteTerm = async (term: Term): Promise<void> => {
        if (!termsSession) {
            return;
        }

        try {
            setTermSaving(true);
            const response = await axios.delete(
                `/api/sessions/${termsSession.id}/terms/${term.id}`,
            );

            if (response.status === 200 || response.status === 204) {
                toast.error(`Term deleted.`);
                setTermConfirm(null);
                fetchTerms(termsSession.id);
            }
        } catch (error) {
            console.log(error);
            toast.error('Failed to delete term.');
        } finally {
            setTermSaving(false);
        }
    };

    const statusPillClass = (status: string): string => {
        switch (status) {
            case 'active':
                return 'pill pill-green';
            case 'upcoming':
                return 'pill pill-amber';
            case 'completed':
                return 'pill pill-slate';
            default:
                return 'pill pill-slate';
        }
    };

    const formatDate = (d: string | null | undefined): string => {
        if (!d) {
            return '—';
        }

        const date = new Date(d);

        if (isNaN(date.getTime())) {
            return d;
        }

        return date.toLocaleDateString(undefined, {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };
    // -----------------------------------------------

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
                                            <button
                                                className="btn btn-outline btn-sm"
                                                onClick={() => openTerms(s)}
                                                title="Manage terms"
                                            >
                                                <BookOpen className="h-3.5 w-3.5" />
                                                Terms
                                            </button>
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
                                                <Pencil className="h-3 w-3" />
                                            </button>
                                            <button
                                                className="btn btn-danger btn-sm btn-icon"
                                                onClick={() => setConfirm(s)}
                                            >
                                                <Trash2 className="h-3 w-3" />
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

            {/* Session create/edit modal */}
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

            {/* Session delete confirm */}
            {confirm && (
                <Confirm
                    msg={`Delete session "${confirm.name}"? This cannot be undone.`}
                    onConfirm={() => {
                        handleDelete(confirm.id);
                    }}
                    onClose={() => setConfirm(null)}
                />
            )}

            {/* Terms list modal */}
            {termsSession && (
                <Modal
                    title={`Terms — ${termsSession.name}`}
                    onClose={closeTerms}
                    footer={
                        <>
                            <button
                                className="btn btn-outline"
                                onClick={closeTerms}
                            >
                                Close
                            </button>
                            <button
                                className="btn btn-primary"
                                onClick={() => openTermForm()}
                            >
                                + New Term
                            </button>
                        </>
                    }
                >
                    {termsLoading ? (
                        <p
                            style={{
                                textAlign: 'center',
                                padding: '24px 0',
                                color: '#6B7280',
                                fontSize: 13,
                            }}
                        >
                            Loading terms…
                        </p>
                    ) : terms.length === 0 ? (
                        <Empty
                            icon="📚"
                            title="No terms yet"
                            sub="Add the first term for this session"
                        />
                    ) : (
                        <div className="tbl-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th style={{ width: 50 }}>#</th>
                                        <th>Name</th>
                                        <th>Dates</th>
                                        <th>Status</th>
                                        <th style={{ textAlign: 'right' }}>
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {[...terms]
                                        .sort((a, b) => a.order - b.order)
                                        .map((t) => (
                                            <tr key={t.id}>
                                                <td
                                                    style={{
                                                        fontFamily:
                                                            'var(--mono)',
                                                        color: '#6B7280',
                                                    }}
                                                >
                                                    {t.order}
                                                </td>
                                                <td style={{ fontWeight: 600 }}>
                                                    {t.name}
                                                </td>
                                                <td
                                                    style={{
                                                        fontSize: 12.5,
                                                        color: '#374151',
                                                    }}
                                                >
                                                    {formatDate(t.start_date)} →{' '}
                                                    {formatDate(t.end_date)}
                                                </td>
                                                <td>
                                                    <span
                                                        className={statusPillClass(
                                                            t.status,
                                                        )}
                                                        style={{
                                                            textTransform:
                                                                'capitalize',
                                                        }}
                                                    >
                                                        {t.status}
                                                    </span>
                                                </td>
                                                <td>
                                                    <div
                                                        className="row-actions"
                                                        style={{
                                                            justifyContent:
                                                                'flex-end',
                                                        }}
                                                    >
                                                        <button
                                                            className="btn btn-ghost btn-sm btn-icon"
                                                            onClick={() =>
                                                                openTermForm(t)
                                                            }
                                                        >
                                                            <Pencil className="h-3 w-3" />
                                                        </button>
                                                        <button
                                                            className="btn btn-danger btn-sm btn-icon"
                                                            onClick={() =>
                                                                setTermConfirm(
                                                                    t,
                                                                )
                                                            }
                                                        >
                                                            <Trash2 className="h-3 w-3" />
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Modal>
            )}

            {/* Term create/edit modal */}
            {termsSession && termModal && (
                <Modal
                    title={
                        termModal === 'new'
                            ? `New Term — ${termsSession.name}`
                            : `Edit Term — ${termsSession.name}`
                    }
                    onClose={() => setTermModal(null)}
                    footer={
                        <>
                            <button
                                className="btn btn-outline"
                                onClick={() => setTermModal(null)}
                            >
                                Cancel
                            </button>
                            <button
                                disabled={termSaving}
                                className="btn btn-primary"
                                onClick={saveTerm}
                            >
                                Save
                            </button>
                        </>
                    }
                >
                    <div className="field">
                        <label>Term name</label>
                        <input
                            placeholder="e.g. First Term"
                            value={termForm.name}
                            onChange={(e) =>
                                setTermForm((p) => ({
                                    ...p,
                                    name: e.target.value,
                                }))
                            }
                            autoFocus
                        />
                    </div>

                    <div
                        style={{
                            display: 'grid',
                            gridTemplateColumns: '1fr 1fr',
                            gap: 12,
                        }}
                    >
                        <div className="field">
                            <label>Order</label>
                            <input
                                type="number"
                                min={1}
                                max={255}
                                value={termForm.order}
                                onChange={(e) =>
                                    setTermForm((p) => ({
                                        ...p,
                                        order:
                                            parseInt(e.target.value, 10) || 1,
                                    }))
                                }
                            />
                        </div>
                        <div className="field">
                            <label>Status</label>
                            <select
                                value={termForm.status}
                                onChange={(e) =>
                                    setTermForm((p) => ({
                                        ...p,
                                        status: e.target.value as TermStatus,
                                    }))
                                }
                            >
                                <option value="upcoming">Upcoming</option>
                                <option value="active">Active</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>
                    </div>

                    <div
                        style={{
                            display: 'grid',
                            gridTemplateColumns: '1fr 1fr',
                            gap: 12,
                        }}
                    >
                        <div className="field">
                            <label>Start date</label>
                            <input
                                type="date"
                                value={termForm.start_date}
                                onChange={(e) =>
                                    setTermForm((p) => ({
                                        ...p,
                                        start_date: e.target.value,
                                    }))
                                }
                            />
                        </div>
                        <div className="field">
                            <label>End date</label>
                            <input
                                type="date"
                                value={termForm.end_date}
                                onChange={(e) =>
                                    setTermForm((p) => ({
                                        ...p,
                                        end_date: e.target.value,
                                    }))
                                }
                            />
                        </div>
                    </div>
                    <div
                        style={{
                            display: 'grid',
                            gridTemplateColumns: '1fr 1fr',
                            gap: 12,
                        }}
                    >
                        <div className="field">
                            <label>Registration Deadline</label>
                            <input
                                type="date"
                                value={termForm.registration_deadline}
                                onChange={(e) =>
                                    setTermForm((p) => ({
                                        ...p,
                                        registration_deadline: e.target.value,
                                    }))
                                }
                            />
                        </div>
                        <div className="field">
                            <label>Result Visible At</label>
                            <input
                                type="date"
                                value={termForm.result_visible_at}
                                onChange={(e) =>
                                    setTermForm((p) => ({
                                        ...p,
                                        result_visible_at: e.target.value,
                                    }))
                                }
                            />
                        </div>
                    </div>
                </Modal>
            )}

            {/* Term delete confirm */}
            {termConfirm && (
                <Confirm
                    msg={`Delete term "${termConfirm.name}"? This cannot be undone.`}
                    onConfirm={() => deleteTerm(termConfirm)}
                    onClose={() => setTermConfirm(null)}
                />
            )}
        </>
    );
}
