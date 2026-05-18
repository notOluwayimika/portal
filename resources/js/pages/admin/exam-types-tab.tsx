import axios from 'axios';
import { useEffect, useState } from 'react';
import type { ToastType } from '@/components/toast-item';
import type { ExamType } from '@/types/models';
import { Check, Pencil, Trash2, X } from 'lucide-react';
import { Confirm, Empty, Modal } from './school-setup';

interface ExamTypeForm {
    name: string;
}

export function ExamTypesTab({
    addToast,
}: {
    addToast: (message: string, type?: ToastType) => void;
}) {
    const [examTypes, setExamTypes] = useState<ExamType[]>([]);
    const [modal, setModal] = useState<string | null>(null);
    const [form, setForm] = useState<ExamTypeForm>({ name: '' });
    const [confirm, setConfirm] = useState<ExamType | null>(null);
    const [inlineId, setInlineId] = useState<string | null>(null);
    const [inlineVal, setInlineVal] = useState<string>('');
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        const fetchExamTypes = async () => {
            const response = await axios.get('/api/exam-types');

            if (response.status === 200) {
                setExamTypes(response.data.data);
            }
        };

        fetchExamTypes();
    }, [loading]);

    const handleDelete = async (id: string) => {
        setLoading(true);

        try {
            const response = await axios.delete(`/api/exam-types/${id}`);

            if (response.status === 200) {
                addToast('Exam type deleted successfully.', 'success');
                setExamTypes((p) => p.filter((e) => e.id !== id));
            } else {
                addToast('Failed to delete exam type.', 'error');
            }
        } catch (error) {
            console.log(error);
            addToast(
                'An error occurred while deleting the exam type.',
                'error',
            );
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
                const response = await axios.post('/api/exam-types', form);

                if (response.status === 201) {
                    addToast('Exam type created successfully.', 'success');
                    setModal(null);
                } else {
                    addToast('Failed to create exam type.', 'error');
                }
            } else {
                const response = await axios.put(
                    `/api/exam-types/${inlineId}`,
                    { name: inlineVal.trim() },
                );

                if (response.status === 200) {
                    addToast('Exam type updated successfully.', 'info');
                    setInlineId(null);
                } else {
                    addToast('Failed to update exam type.', 'error');
                }
            }
        } catch (error) {
            console.log(error);
            addToast('An error occurred while saving the exam type.', 'error');
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
                    <h1>Exam Types</h1>
                    <p>First Term, WAEC Mock, NECO, etc.</p>
                </div>
                <div className="page-hdr-actions">
                    <button
                        className="btn btn-primary"
                        onClick={() => {
                            setForm({ name: '' });
                            setModal('new');
                        }}
                    >
                        + New Exam Type
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
                            {examTypes.length === 0 && (
                                <tr>
                                    <td colSpan={2}>
                                        <Empty
                                            icon="📝"
                                            title="No exam types"
                                            sub="Add your first exam type"
                                        />
                                    </td>
                                </tr>
                            )}
                            {examTypes.map((e) => (
                                <tr key={e.id}>
                                    <td>
                                        {inlineId === e.id ? (
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
                                                    setInlineId(e.id);
                                                    setInlineVal(e.name);
                                                }}
                                            >
                                                {e.name}
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
                                                    setInlineId(e.id);
                                                    setInlineVal(e.name);
                                                }}
                                            >
                                                <Pencil className="h-3 w-3" />
                                            </button>
                                            <button
                                                className="btn btn-danger btn-sm btn-icon"
                                                onClick={() => setConfirm(e)}
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
                    title="New Exam Type"
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
                            placeholder="e.g. Mid-Term Assessment"
                            value={form.name}
                            onChange={(e) => setForm({ name: e.target.value })}
                            autoFocus
                        />
                    </div>
                </Modal>
            )}
            {confirm && (
                <Confirm
                    msg={`Delete exam type "${confirm.name}"?`}
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
