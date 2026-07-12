import axios from 'axios';
import { Pencil, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'react-toastify';
import { Confirm, Empty, Modal } from '@/components/setup/setup-ui';
import type { Arm, ClassLevel, GradingScheme, Stream } from '@/types/models';
// ═══════════════════════════════════════════════════════════════════════════
// SESSIONS TAB
// ═══════════════════════════════════════════════════════════════════════════

// ═══════════════════════════════════════════════════════════════════════════
// CLASS STRUCTURE TAB
// ═══════════════════════════════════════════════════════════════════════════

interface ClassLevelForm {
    name: string;
    order: string | number;
    grading_scheme_id: string;
}
interface ArmForm {
    label: string;
}

interface StreamForm {
    name: string;
    code: string;
    sort_order: number;
}

interface ConfirmTarget<T> {
    type: string;
    item: T;
}
export function ClassStructureTab() {
    const [levels, setLevels] = useState<ClassLevel[]>([]);
    const [arms, setArms] = useState<Arm[]>([]);
    const [streams, setStreams] = useState<Stream[]>([]);
    const [gradingSchemes, setGradingSchemes] = useState<GradingScheme[]>([]);
    const [loading, setLoading] = useState(false);
    const [lvlModal, setLvlModal] = useState<string | null>(null);
    const [armModal, setArmModal] = useState<string | null>(null);
    const [streamModal, setStreamModal] = useState<string | null>(null);
    const [lvlForm, setLvlForm] = useState<ClassLevelForm>({
        name: '',
        order: '',
        grading_scheme_id: '',
    });
    const [armForm, setArmForm] = useState<ArmForm>({ label: '' });
    const [streamForm, setStreamForm] = useState<StreamForm>({
        name: '',
        code: '',
        sort_order: 0,
    });
    const [confirm, setConfirm] = useState<ConfirmTarget<
        ClassLevel | Arm | Stream
    > | null>(null);

    useEffect(() => {
        const fetchClassStructure = async () => {
            const response = await axios.get('/api/class-structure');
            setLevels(response.data.class_levels);
            setArms(response.data.arms);
            setStreams(response.data.streams);
            const schemesResponse = await axios.get('/api/grading-schemes');
            setGradingSchemes(schemesResponse.data.data ?? []);
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
                        grading_scheme_id: lvlForm.grading_scheme_id || null,
                    },
                );

                if (response.status === 201) {
                    toast.success('Level saved successfully');
                    setLvlModal(null);
                } else {
                    toast.error('Failed to save level');
                }
            } else {
                const response = await axios.put(
                    `/api/class-structure/levels/${lvlModal}`,
                    {
                        name: lvlForm.name.trim(),
                        order: +lvlForm.order || 0,
                        grading_scheme_id: lvlForm.grading_scheme_id || null,
                    },
                );

                if (response.status === 200) {
                    toast.success('Level updated successfully');
                    setLvlModal(null);
                } else {
                    toast.error('Failed to update level');
                }
            }
        } catch (error) {
            console.log(error);
            toast.error('Failed to save level');
        } finally {
            setLoading(false);
        }
    };

    const saveStream = async (): Promise<void> => {
        if (!streamForm.name.trim()) {
            return;
        }

        setLoading(true);

        try {
            if (streamModal === 'new') {
                const response = await axios.post(
                    '/api/class-structure/streams',
                    {
                        name: streamForm.name.trim(),
                        code: streamForm.code.trim(),
                        sort_order: streamForm.sort_order,
                    },
                );

                if (response.status === 201) {
                    toast.success('Stream saved successfully');
                    setStreamModal(null);
                } else {
                    toast.error('Failed to save stream');
                }
            } else {
                const response = await axios.put(
                    `/api/class-structure/streams/${streamModal}`,
                    {
                        name: streamForm.name.trim(),
                        code: streamForm.code.trim(),
                        sort_order: streamForm.sort_order,
                    },
                );

                if (response.status === 200) {
                    toast.success('Stream updated successfully');
                    setStreamModal(null);
                } else {
                    toast.error('Failed to update stream');
                }
            }
        } catch (error) {
            console.log(error);
            toast.error('Failed to save stream');
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
                    toast.success('Arm saved successfully');
                } else {
                    toast.error('Failed to save arm');
                }
            } else {
                const response = await axios.put(
                    `/api/class-structure/arms/${armModal}`,
                    {
                        label: armForm.label.trim(),
                    },
                );

                if (response.status === 200) {
                    toast.success('Arm updated successfully');
                } else {
                    toast.error('Failed to update arm');
                }
            }
        } catch (error) {
            console.log(error);
            toast.error('Failed to save arm');
        } finally {
            setLoading(false);
            setArmModal(null);
        }

        setArmModal(null);
    };

    const handleDelete = async (
        id: string,
        type: 'level' | 'arm' | 'stream',
    ): Promise<void> => {
        setLoading(true);

        try {
            const response = await axios.delete(
                `/api/class-structure/${type}s/${id}`,
            );

            if (response.status === 200) {
                toast.success(
                    `${type.charAt(0).toUpperCase() + type.slice(1)} deleted successfully`,
                );
            } else {
                toast.error(`Failed to delete ${type}`);
            }
        } catch (error) {
            console.log(error);
            toast.error(`Failed to delete ${type}`);
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
                toast.success('Relationship updated successfully');
            } else {
                toast.error('Failed to update relationship');
            }
        } catch (error) {
            console.log(error);
            toast.error('Failed to update relationship');
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
                            setStreamForm({
                                name: '',
                                code: '',
                                sort_order: 0,
                            });
                            setStreamModal('new');
                        }}
                    >
                        + New Stream
                    </button>
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
                            setLvlForm({
                                name: '',
                                order: '',
                                grading_scheme_id: '',
                            });
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
                                                            grading_scheme_id:
                                                                l.grading_scheme
                                                                    ?.id ?? '',
                                                        });
                                                        setLvlModal(l.id);
                                                    }}
                                                >
                                                    <Pencil className="h-3 w-3" />
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
                <div className="grid grid-cols-1">
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
                                            <Pencil className="h-3 w-3" />
                                        </button>
                                        <button
                                            className="btn btn-danger btn-sm btn-icon"
                                            onClick={() =>
                                                setConfirm({
                                                    type: 'arm',
                                                    item: a,
                                                })
                                            }
                                        >
                                            <Trash2 className="h-3 w-3" />
                                        </button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                    <div className="card">
                        <div className="card-hdr">
                            <span className="card-hdr-title">Streams</span>
                        </div>
                        <div>
                            {streams.length === 0 && (
                                <Empty icon="🔤" title="No streams yet" />
                            )}
                            {streams.map((s) => (
                                <div
                                    key={s.id}
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
                                        {s.name}
                                    </span>
                                    <div className="row-actions">
                                        <button
                                            className="btn btn-ghost btn-sm btn-icon"
                                            onClick={() => {
                                                setStreamForm({
                                                    name: s.name,
                                                    code: s.code,
                                                    sort_order: s.sort_order,
                                                });
                                                setStreamModal(s.id);
                                            }}
                                        >
                                            <Pencil className="h-3 w-3" />
                                        </button>
                                        <button
                                            className="btn btn-danger btn-sm btn-icon"
                                            onClick={() =>
                                                setConfirm({
                                                    type: 'stream',
                                                    item: s,
                                                })
                                            }
                                        >
                                            <Trash2 className="h-3 w-3" />
                                        </button>
                                    </div>
                                </div>
                            ))}
                        </div>
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
                        <div className="field span-2">
                            <label>Grading method</label>
                            <select
                                value={lvlForm.grading_scheme_id}
                                onChange={(event) =>
                                    setLvlForm((current) => ({
                                        ...current,
                                        grading_scheme_id: event.target.value,
                                    }))
                                }
                            >
                                <option value="">
                                    Numerical grading (default)
                                </option>
                                {gradingSchemes.map((scheme) => (
                                    <option key={scheme.id} value={scheme.id}>
                                        Categorical — {scheme.name}
                                    </option>
                                ))}
                            </select>
                            <span className="text-xs text-slate-500">
                                New curricula for this level will use this
                                grading method.
                            </span>
                        </div>
                    </div>
                </Modal>
            )}
            {streamModal && (
                <Modal
                    title={streamModal === 'new' ? 'New Stream' : 'Edit Stream'}
                    onClose={() => setStreamModal(null)}
                    footer={
                        <>
                            <button
                                className="btn btn-outline"
                                onClick={() => setStreamModal(null)}
                            >
                                Cancel
                            </button>
                            <button
                                className="btn btn-primary"
                                onClick={saveStream}
                            >
                                Save
                            </button>
                        </>
                    }
                >
                    <div className="form-grid form-grid-2">
                        <div className="field">
                            <label>Stream name</label>
                            <input
                                placeholder="e.g. Science"
                                value={streamForm.name}
                                onChange={(e) =>
                                    setStreamForm((p) => ({
                                        ...p,
                                        name: e.target.value,
                                    }))
                                }
                                autoFocus
                            />
                        </div>
                        <div className="field">
                            <label>Stream code</label>
                            <input
                                placeholder="e.g. SCI"
                                value={streamForm.code}
                                onChange={(e) =>
                                    setStreamForm((p) => ({
                                        ...p,
                                        code: e.target.value,
                                    }))
                                }
                            />
                        </div>
                        <div className="field">
                            <label>Display order</label>
                            <input
                                type="number"
                                min="1"
                                value={streamForm.sort_order}
                                onChange={(e) =>
                                    setStreamForm((p) => ({
                                        ...p,
                                        sort_order: Number(e.target.value),
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
                        } else if (confirm.type === 'arm') {
                            handleDelete((confirm.item as Arm).id, 'arm');
                        } else if (confirm.type === 'stream') {
                            handleDelete((confirm.item as Stream).id, 'stream');
                        }

                        setConfirm(null);
                    }}
                    onClose={() => setConfirm(null)}
                />
            )}
        </>
    );
}
