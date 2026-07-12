import axios from 'axios';
import { Check, Pencil, Trash2, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'react-toastify';
import type { Scholarship } from '@/types/models';
import { Confirm, Empty, Modal } from '@/components/setup/setup-ui';

interface ScholarshipForm {
    name: string;
}

export function ScholarshipsTab() {
    const [scholarships, setScholarships] = useState<Scholarship[]>([]);
    const [modal, setModal] = useState<string | null>(null);
    const [form, setForm] = useState<ScholarshipForm>({ name: '' });
    const [confirm, setConfirm] = useState<Scholarship | null>(null);
    const [inlineId, setInlineId] = useState<string | null>(null);
    const [inlineVal, setInlineVal] = useState<string>('');
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        const fetchScholarships = async () => {
            const response = await axios.get('/api/scholarships');

            if (response.status === 200) {
                setScholarships(response.data.data);
            }
        };

        fetchScholarships();
    }, [loading]);

    const handleDelete = async (uuid: string) => {
        setLoading(true);

        try {
            const response = await axios.delete(`/api/scholarships/${uuid}`);

            if (response.status === 200) {
                toast.success('Scholarship deleted successfully.');
                setScholarships((p) => p.filter((s) => s.uuid !== uuid));
            } else {
                toast.error('Failed to delete scholarship.');
            }
        } catch (error) {
            console.log(error);
            toast.error('An error occurred while deleting the scholarship.');
        } finally {
            setLoading(false);
        }
    };

    const save = async (): Promise<void> => {
        if (!form.name.trim() && !inlineVal.trim()) {
            return;
        }

        setLoading(true);

        try {
            if (modal === 'new') {
                const response = await axios.post('/api/scholarships', form);

                if (response.status === 201) {
                    toast.success('Scholarship created successfully.');
                    setModal(null);
                } else {
                    toast.error('Failed to create scholarship.');
                }
            } else {
                const response = await axios.put(
                    `/api/scholarships/${inlineId}`,
                    { name: inlineVal.trim() },
                );

                if (response.status === 200) {
                    toast.success('Scholarship updated successfully.');
                    setInlineId(null);
                } else {
                    toast.error('Failed to update scholarship.');
                }
            }
        } catch (error) {
            console.log(error);
            toast.error('An error occurred while saving the scholarship.');
        } finally {
            setLoading(false);
        }
    };

    const commitInline = (): void => {
        if (inlineVal.trim()) {
            save();
        }
    };

    return (
        <>
            <div className="page-hdr">
                <div>
                    <h1>Scholarships</h1>
                    <p>C2C, BSS, and other scholarship categories.</p>
                </div>
                <div className="page-hdr-actions">
                    <button
                        className="btn btn-primary"
                        onClick={() => {
                            setForm({ name: '' });
                            setModal('new');
                        }}
                    >
                        + New Scholarship
                    </button>
                </div>
            </div>
            <div className="card">
                <div className="tbl-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th style={{ textAlign: 'right' }}>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {scholarships.length === 0 && (
                                <tr>
                                    <td colSpan={2}>
                                        <Empty
                                            icon="🎓"
                                            title="No scholarships"
                                            sub="Add your first scholarship"
                                        />
                                    </td>
                                </tr>
                            )}
                            {scholarships.map((s) => (
                                <tr key={s.uuid}>
                                    <td>
                                        {inlineId === s.uuid ? (
                                            <div className="inline-edit">
                                                <input
                                                    value={inlineVal}
                                                    autoFocus
                                                    onChange={(ev) =>
                                                        setInlineVal(
                                                            ev.target.value,
                                                        )
                                                    }
                                                    onKeyDown={(ev) => {
                                                        if (
                                                            ev.key === 'Enter'
                                                        ) {
                                                            commitInline();
                                                        }
                                                    }}
                                                />
                                                <button
                                                    className="btn btn-primary btn-sm btn-icon"
                                                    onClick={() =>
                                                        commitInline()
                                                    }
                                                >
                                                    <Check className="h-3 w-3" />
                                                </button>
                                                <button
                                                    className="btn btn-ghost btn-sm btn-icon"
                                                    onClick={() =>
                                                        setInlineId(null)
                                                    }
                                                >
                                                    <X className="h-3 w-3" />
                                                </button>
                                            </div>
                                        ) : (
                                            <span
                                                style={{ fontWeight: 500 }}
                                                onDoubleClick={() => {
                                                    setInlineId(s.uuid);
                                                    setInlineVal(s.name);
                                                }}
                                            >
                                                {s.name}
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
                                                className="btn btn-ghost btn-sm btn-icon"
                                                onClick={() => {
                                                    setInlineId(s.uuid);
                                                    setInlineVal(s.name);
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
                </div>
            </div>
            {modal && (
                <Modal
                    title="New Scholarship"
                    onClose={() => setModal(null)}
                    footer={
                        <>
                            <button
                                className="btn btn-outline"
                                onClick={() => setModal(null)}
                            >
                                Cancel
                            </button>
                            <button className="btn btn-primary" onClick={save}>
                                Save
                            </button>
                        </>
                    }
                >
                    <div className="field">
                        <label>Name</label>
                        <input
                            placeholder="e.g. C2C"
                            value={form.name}
                            onChange={(e) => setForm({ name: e.target.value })}
                            autoFocus
                        />
                    </div>
                </Modal>
            )}
            {confirm && (
                <Confirm
                    msg={`Delete scholarship "${confirm.name}"?`}
                    onConfirm={() => {
                        handleDelete(confirm.uuid);
                        setConfirm(null);
                    }}
                    onClose={() => setConfirm(null)}
                />
            )}
        </>
    );
}
