// ═══════════════════════════════════════════════════════════════════════════
// SUBJECTS TAB
// ═══════════════════════════════════════════════════════════════════════════

import axios from 'axios';
import { Pencil, Search, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'react-toastify';
import { Pagination } from '@/components/pagination';
import type { Subject } from '@/types/models';
import { Confirm, Empty, Modal } from '@/components/setup/setup-ui';

interface SubjectForm {
    name: string;
    code: string;
}

export function SubjectsTab() {
    const [subjects, setSubjects] = useState<Subject[]>([]);
    const [search, setSearch] = useState<string>('');
    const [modal, setModal] = useState<string | null>(null);
    const [form, setForm] = useState<SubjectForm>({ name: '', code: '' });
    const [confirm, setConfirm] = useState<Subject | null>(null);
    const [total, setTotal] = useState(0);
    const [loading, setLoading] = useState(false);
    const [limit, setLimit] = useState(10);
    const [page, setPage] = useState(1);
    const [paginationMeta, setPaginationMeta] = useState({
        current_page: 1,
        last_page: 1,
        per_page: 10,
        total: 0,
    });

    useEffect(() => {
        const fetchSubjects = async () => {
            // Replace this with your actual API call
            const response = await axios.get('/api/subjects', {
                params: { limit, page, search },
            });

            if (response.status === 200) {
                setSubjects(response.data.subjects ?? []);
                setTotal(response.data.pagination.total ?? 0);
                setPaginationMeta(response.data.pagination ?? paginationMeta);
            }
        };

        fetchSubjects();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [loading, search, limit, page]);

    const save = async (): Promise<void> => {
        if (!form.name.trim() || !form.code.trim()) {
            return;
        }

        try {
            setLoading(true);

            if (modal === 'new') {
                const response = await axios.post('/api/subjects', form);

                if (response.status === 201) {
                    toast.success('Subject created successfully');
                    setModal(null);
                } else {
                    toast.error('Failed to create subject');
                }
            } else {
                const response = await axios.put(
                    `/api/subjects/${modal}`,
                    form,
                );

                if (response.status === 200) {
                    toast.success('Subject updated successfully');
                    setModal(null);
                } else {
                    toast.error('Failed to update subject');
                }
            }
        } catch (error) {
            console.log(error);
            toast.error('An error occurred while creating the subject');
        } finally {
            setLoading(false);
        }

        setModal(null);
    };
    const handleDelete = async (id: string) => {
        try {
            setLoading(true);
            const response = await axios.delete(`/api/subjects/${id}`);

            if (response.status === 204) {
                toast.success('Subject deleted successfully');
                setConfirm(null);
            } else {
                toast.error('Failed to delete subject');
            }
        } catch (error) {
            console.log(error);
            toast.error('An error occurred while deleting the subject');
        } finally {
            setLoading(false);
        }
    };

    return (
        <>
            <div className="page-hdr">
                <div>
                    <h1>Subjects</h1>
                    <p>{total} subjects in the catalogue</p>
                </div>
                <div className="page-hdr-actions">
                    <div className="search-wrap">
                        <Search
                            className="search-icon"
                            style={{
                                position: 'absolute',
                                left: 10,
                                top: '50%',
                                transform: 'translateY(-50%)',
                                color: 'var(--text3)',
                                width: 14,
                                height: 14,
                                pointerEvents: 'none',
                            }}
                        />
                        <input
                            placeholder="Search…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            style={{ paddingLeft: 34, width: 220 }}
                        />
                    </div>
                    <button
                        className="btn btn-primary"
                        onClick={() => {
                            setForm({ name: '', code: '' });
                            setModal('new');
                        }}
                    >
                        + New Subject
                    </button>
                </div>
            </div>
            <div className="card">
                <div className="tbl-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th style={{ width: 44 }}>#</th>
                                <th>Subject name</th>
                                <th>Code</th>
                                <th style={{ textAlign: 'right' }}>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {subjects.length === 0 && (
                                <tr>
                                    <td colSpan={4}>
                                        <Empty
                                            icon="📚"
                                            title={
                                                search
                                                    ? 'No subjects match'
                                                    : 'No subjects yet'
                                            }
                                        />
                                    </td>
                                </tr>
                            )}
                            {subjects.map((s, i) => (
                                <tr key={s.id}>
                                    <td
                                        className="muted"
                                        style={{
                                            fontFamily: 'var(--mono)',
                                            fontSize: 12,
                                        }}
                                    >
                                        {(page - 1) * limit + (i + 1)}
                                    </td>
                                    <td style={{ fontWeight: 500 }}>
                                        {s.name}
                                    </td>
                                    <td>
                                        <span className="code-tag">
                                            {s.code || '—'}
                                        </span>
                                    </td>
                                    <td>
                                        <div
                                            className="row-actions"
                                            style={{
                                                justifyContent: 'flex-end',
                                            }}
                                        >
                                            <button
                                                className="btn btn-ghost btn-sm btn-icon"
                                                onClick={() => {
                                                    setForm({
                                                        name: s.name,
                                                        code: s.code || '',
                                                    });
                                                    setModal(s.id);
                                                }}
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
                    <Pagination
                        meta={paginationMeta}
                        setPage={setPage}
                        setLimit={setLimit}
                    />
                </div>
            </div>
            {modal && (
                <Modal
                    title={modal === 'new' ? 'New Subject' : 'Edit Subject'}
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
                    <div className="form-grid form-grid-2">
                        <div className="field span-2">
                            <label>Subject name</label>
                            <input
                                placeholder="e.g. Further Mathematics"
                                value={form.name}
                                onChange={(e) =>
                                    setForm((p) => ({
                                        ...p,
                                        name: e.target.value,
                                    }))
                                }
                                autoFocus
                            />
                        </div>
                        <div className="field">
                            <label>Subject code</label>
                            <input
                                placeholder="FMT"
                                value={form.code}
                                onChange={(e) =>
                                    setForm((p) => ({
                                        ...p,
                                        code: e.target.value,
                                    }))
                                }
                            />
                        </div>
                    </div>
                </Modal>
            )}
            {confirm && (
                <Confirm
                    msg={`Delete subject "${confirm.name}"?`}
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
