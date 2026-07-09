import axios from 'axios';
import { Check, Pencil, Trash2, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'react-toastify';
import type { SportHouse } from '@/types/models';
import { Confirm, Empty, Modal } from './school-setup';

interface SportHouseForm {
    name: string;
}

export function SportHousesTab() {
    const [sportHouses, setSportHouses] = useState<SportHouse[]>([]);
    const [modal, setModal] = useState<string | null>(null);
    const [form, setForm] = useState<SportHouseForm>({ name: '' });
    const [confirm, setConfirm] = useState<SportHouse | null>(null);
    const [inlineId, setInlineId] = useState<string | null>(null);
    const [inlineVal, setInlineVal] = useState<string>('');
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        const fetchSportHouses = async () => {
            const response = await axios.get('/api/sport-houses');

            if (response.status === 200) {
                setSportHouses(response.data.data);
            }
        };

        fetchSportHouses();
    }, [loading]);

    const handleDelete = async (uuid: string) => {
        setLoading(true);

        try {
            const response = await axios.delete(`/api/sport-houses/${uuid}`);

            if (response.status === 200) {
                toast.success('Sport house deleted successfully.');
                setSportHouses((p) => p.filter((s) => s.uuid !== uuid));
            } else {
                toast.error('Failed to delete sport house.');
            }
        } catch (error) {
            console.log(error);
            toast.error('An error occurred while deleting the sport house.');
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
                const response = await axios.post('/api/sport-houses', form);

                if (response.status === 201) {
                    toast.success('Sport house created successfully.');
                    setModal(null);
                } else {
                    toast.error('Failed to create sport house.');
                }
            } else {
                const response = await axios.put(
                    `/api/sport-houses/${inlineId}`,
                    { name: inlineVal.trim() },
                );

                if (response.status === 200) {
                    toast.success('Sport house updated successfully.');
                    setInlineId(null);
                } else {
                    toast.error('Failed to update sport house.');
                }
            }
        } catch (error) {
            console.log(error);
            toast.error(
                'An error occurred while saving the sport house.'
            );
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
                    <h1>Sport Houses</h1>
                    <p>Red House, Blue House, Green House, etc.</p>
                </div>
                <div className="page-hdr-actions">
                    <button
                        className="btn btn-primary"
                        onClick={() => {
                            setForm({ name: '' });
                            setModal('new');
                        }}
                    >
                        + New Sport House
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
                            {sportHouses.length === 0 && (
                                <tr>
                                    <td colSpan={2}>
                                        <Empty
                                            icon="🏆"
                                            title="No sport houses"
                                            sub="Add your first sport house"
                                        />
                                    </td>
                                </tr>
                            )}
                            {sportHouses.map((s) => (
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
                    title="New Sport House"
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
                            placeholder="e.g. Red House"
                            value={form.name}
                            onChange={(e) => setForm({ name: e.target.value })}
                            autoFocus
                        />
                    </div>
                </Modal>
            )}
            {confirm && (
                <Confirm
                    msg={`Delete sport house "${confirm.name}"?`}
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
