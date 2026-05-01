import axios from 'axios';
import { useEffect, useState } from 'react';
import { Confirm, Empty, Modal } from '@/pages/admin/school-setup';
import type { ClassLevel } from '@/types/models';
import type { ToastType } from '../toast-item';
// ═══════════════════════════════════════════════════════════════════════════
// SESSIONS TAB
// ═══════════════════════════════════════════════════════════════════════════

// ═══════════════════════════════════════════════════════════════════════════
// CLASS STRUCTURE TAB
// ═══════════════════════════════════════════════════════════════════════════

interface ClassLevelForm {
    name: string;
    order: string | number;
}
interface Arm {
    id: string;
    label: string;
}
interface ArmForm {
    label: string;
}

interface ConfirmTarget<T> {
    type: string;
    item: T;
}
export function ClassStructureTab({
    addToast,
}: {
    addToast: (message: string, type?: ToastType) => void;
}) {
    const [levels, setLevels] = useState<ClassLevel[]>([]);
    const [arms, setArms] = useState<Arm[]>([]);
    const [loading, setLoading] = useState(false);
    const [lvlModal, setLvlModal] = useState<string | null>(null);
    const [armModal, setArmModal] = useState<string | null>(null);
    const [lvlForm, setLvlForm] = useState<ClassLevelForm>({
        name: '',
        order: '',
    });
    const [armForm, setArmForm] = useState<ArmForm>({ label: '' });
    const [confirm, setConfirm] = useState<ConfirmTarget<
        ClassLevel | Arm
    > | null>(null);

    useEffect(() => {
        const fetchClassStructure = async () => {
            const response = await axios.get('/api/class-structure');
            setLevels(response.data.class_levels);
            setArms(response.data.arms);
        };
        fetchClassStructure();
    }, [loading]);

    const saveLvl = async (): Promise<void> => {
        if (!lvlForm.name.trim()) {
            return;
        }

        setLoading(true);

        try {
            if (lvlModal === 'new') {
                const response = await axios.post(
                    '/api/class-structure/levels',
                    {
                        name: lvlForm.name.trim(),
                        order: +lvlForm.order || 0,
                    },
                );

                if (response.status === 201) {
                    addToast('Level saved successfully');
                    setLvlModal(null);
                } else {
                    addToast('Failed to save level', 'error');
                }
            } else {
                const response = await axios.put(
                    `/api/class-structure/levels/${lvlModal}`,
                    {
                        name: lvlForm.name.trim(),
                        order: +lvlForm.order || 0,
                    },
                );

                if (response.status === 200) {
                    addToast('Level updated successfully', 'info');
                    setLvlModal(null);
                } else {
                    addToast('Failed to update level', 'error');
                }
            }
        } catch (error) {
            console.log(error);
            addToast('Failed to save level', 'error');
        } finally {
            setLoading(false);
        }
    };

    const saveArm = async (): Promise<void> => {
        if (!armForm.label.trim()) {
            return;
        }

        setLoading(true);

        try {
            if (armModal === 'new') {
                const response = await axios.post('/api/class-structure/arms', {
                    label: armForm.label.trim(),
                });

                if (response.status === 201) {
                    addToast('Arm saved successfully');
                } else {
                    addToast('Failed to save arm', 'error');
                }
            } else {
                const response = await axios.put(
                    `/api/class-structure/arms/${armModal}`,
                    {
                        label: armForm.label.trim(),
                    },
                );

                if (response.status === 200) {
                    addToast('Arm updated successfully', 'info');
                } else {
                    addToast('Failed to update arm', 'error');
                }
            }
        } catch (error) {
            console.log(error);
            addToast('Failed to save arm', 'error');
        } finally {
            setLoading(false);
            setArmModal(null);
        }

        setArmModal(null);
    };

    const handleDelete = async (
        id: string,
        type: 'level' | 'arm',
    ): Promise<void> => {
        setLoading(true);

        try {
            const response = await axios.delete(
                `/api/class-structure/${type === 'level' ? 'levels' : 'arms'}/${id}`,
            );

            if (response.status === 200) {
                addToast(
                    `${type.charAt(0).toUpperCase() + type.slice(1)} deleted successfully`,
                );
            } else {
                addToast(`Failed to delete ${type}`, 'error');
            }
        } catch (error) {
            console.log(error);
            addToast(`Failed to delete ${type}`, 'error');
        } finally {
            setLoading(false);
        }
    };

    const toggle = async (lid: string, aid: string): Promise<void> => {
        setLoading(true);

        try {
            const response = await axios.post('/api/class-structure/toggle', {
                class_level_id: lid,
                arm_id: aid,
            });

            if (response.status == 200) {
                addToast('Relationship updated successfully');
            } else {
                addToast('Failed to update relationship', 'error');
            }
        } catch (error) {
            console.log(error);
            addToast('Failed to update relationship', 'error');
        } finally {
            setLoading(false);
        }
    };

    const sorted = [...levels].sort((a, b) => a.order - b.order);

    return (
        <>
            <div className="page-hdr">
                <div>
                    <h1>Class Structure</h1>
                    <p>Class levels and their assigned arms</p>
                </div>
                <div className="page-hdr-actions">
                    <button
                        className="btn btn-outline"
                        onClick={() => {
                            setArmForm({ label: '' });
                            setArmModal('new');
                        }}
                    >
                        + New Arm
                    </button>
                    <button
                        className="btn btn-primary"
                        onClick={() => {
                            setLvlForm({ name: '', order: '' });
                            setLvlModal('new');
                        }}
                    >
                        + New Level
                    </button>
                </div>
            </div>

            <div
                style={{
                    display: 'grid',
                    gridTemplateColumns: '1fr 220px',
                    gap: 16,
                    alignItems: 'start',
                }}
            >
                <div className="card">
                    <div className="card-hdr">
                        <span className="card-hdr-title">Class Levels</span>
                        <span className="card-hdr-sub">
                            Tick to assign arms
                        </span>
                    </div>
                    <div className="tbl-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Level</th>
                                    <th style={{ textAlign: 'center' }}>
                                        Order
                                    </th>
                                    {arms.map((a) => (
                                        <th
                                            key={a.id}
                                            style={{ textAlign: 'center' }}
                                        >
                                            Arm {a.label}
                                        </th>
                                    ))}
                                    <th style={{ textAlign: 'right' }}>
                                        Actions
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                {sorted.length === 0 && (
                                    <tr>
                                        <td colSpan={3 + arms.length}>
                                            <Empty
                                                icon="🏫"
                                                title="No class levels"
                                            />
                                        </td>
                                    </tr>
                                )}
                                {sorted.map((l) => (
                                    <tr key={l.id}>
                                        <td>
                                            <span
                                                style={{
                                                    fontFamily: 'var(--mono)',
                                                    fontWeight: 700,
                                                }}
                                            >
                                                {l.name}
                                            </span>
                                        </td>
                                        <td
                                            style={{ textAlign: 'center' }}
                                            className="muted"
                                        >
                                            {l.order}
                                        </td>

                                        {arms.map((a) => (
                                            <td
                                                key={a.id}
                                                style={{ textAlign: 'center' }}
                                            >
                                                <input
                                                    disabled={loading}
                                                    type="checkbox"
                                                    className="checked:bg-primary"
                                                    checked={
                                                        l.arms?.some(
                                                            (arm) =>
                                                                arm.id === a.id,
                                                        ) ?? false
                                                    }
                                                    onChange={() => {
                                                        toggle(l.id, a.id);
                                                    }}
                                                />
                                            </td>
                                        ))}
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
                                                        setLvlForm({
                                                            name: l.name,
                                                            order: l.order,
                                                        });
                                                        setLvlModal(l.id);
                                                    }}
                                                >
                                                    ✏️
                                                </button>
                                                <button
                                                    className="btn btn-danger btn-sm btn-icon"
                                                    onClick={() =>
                                                        setConfirm({
                                                            type: 'level',
                                                            item: l,
                                                        })
                                                    }
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
                </div>

                <div className="card">
                    <div className="card-hdr">
                        <span className="card-hdr-title">Arms</span>
                    </div>
                    <div>
                        {arms.length === 0 && (
                            <Empty icon="🔤" title="No arms yet" />
                        )}
                        {arms.map((a) => (
                            <div
                                key={a.id}
                                style={{
                                    display: 'flex',
                                    alignItems: 'center',
                                    padding: '10px 16px',
                                    borderBottom: '1px solid var(--border)',
                                }}
                            >
                                <span
                                    style={{
                                        fontFamily: 'var(--mono)',
                                        fontWeight: 700,
                                        fontSize: 16,
                                        color: 'var(--blue-dk)',
                                        flex: 1,
                                    }}
                                >
                                    {a.label}
                                </span>
                                <div className="row-actions">
                                    <button
                                        className="btn btn-ghost btn-sm btn-icon"
                                        onClick={() => {
                                            setArmForm({ label: a.label });
                                            setArmModal(a.id);
                                        }}
                                    >
                                        ✏️
                                    </button>
                                    <button
                                        className="btn btn-danger btn-sm btn-icon"
                                        onClick={() =>
                                            setConfirm({ type: 'arm', item: a })
                                        }
                                    >
                                        🗑
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            {lvlModal && (
                <Modal
                    title={
                        lvlModal === 'new'
                            ? 'New Class Level'
                            : 'Edit Class Level'
                    }
                    onClose={() => setLvlModal(null)}
                    footer={
                        <>
                            <button
                                className="btn btn-outline"
                                onClick={() => setLvlModal(null)}
                            >
                                Cancel
                            </button>
                            <button
                                className="btn btn-primary"
                                onClick={saveLvl}
                            >
                                Save
                            </button>
                        </>
                    }
                >
                    <div className="form-grid form-grid-2">
                        <div className="field">
                            <label>Level name</label>
                            <input
                                placeholder="e.g. JS1"
                                value={lvlForm.name}
                                onChange={(e) =>
                                    setLvlForm((p) => ({
                                        ...p,
                                        name: e.target.value,
                                    }))
                                }
                                autoFocus
                            />
                        </div>
                        <div className="field">
                            <label>Display order</label>
                            <input
                                type="number"
                                min="1"
                                value={lvlForm.order}
                                onChange={(e) =>
                                    setLvlForm((p) => ({
                                        ...p,
                                        order: e.target.value,
                                    }))
                                }
                            />
                        </div>
                    </div>
                </Modal>
            )}

            {armModal && (
                <Modal
                    title={armModal === 'new' ? 'New Arm' : 'Edit Arm'}
                    onClose={() => setArmModal(null)}
                    footer={
                        <>
                            <button
                                className="btn btn-outline"
                                onClick={() => setArmModal(null)}
                            >
                                Cancel
                            </button>
                            <button
                                className="btn btn-primary"
                                onClick={saveArm}
                            >
                                Save
                            </button>
                        </>
                    }
                >
                    <div className="field">
                        <label>Arm label</label>
                        <input
                            placeholder="e.g. D"
                            value={armForm.label}
                            onChange={(e) =>
                                setArmForm({ label: e.target.value })
                            }
                            autoFocus
                        />
                    </div>
                </Modal>
            )}

            {confirm && (
                <Confirm
                    msg={`Delete ${
                        confirm.type === 'level'
                            ? `class level "${(confirm.item as ClassLevel).name}"`
                            : `arm "${(confirm.item as Arm).label}"`
                    }?`}
                    onConfirm={() => {
                        if (confirm.type === 'level') {
                            handleDelete(
                                (confirm.item as ClassLevel).id,
                                'level',
                            );
                        } else {
                            handleDelete((confirm.item as Arm).id, 'arm');
                        }

                        setConfirm(null);
                    }}
                    onClose={() => setConfirm(null)}
                />
            )}
        </>
    );
}
