import axios from 'axios';
import { Trash2 } from 'lucide-react';
import { useState, useMemo, useEffect } from 'react';
import { toast } from 'react-toastify';
import { Empty } from '@/pages/admin/school-setup';
import type { Arm, ClassLevel, ClassLevelArm, Stream } from '@/types/models';

// ─── Component ────────────────────────────────────────────────────────────────

export default function ClassStreamTab() {
    const [loading, setLoading] = useState(false);

    const [classLevels, setClassLevels] = useState<ClassLevel[]>([]);
    const [arms, setArms] = useState<Arm[]>([]);
    const [streams, setStreams] = useState<Stream[]>([]);
    const [entries, setEntries] = useState<ClassLevelArm[]>([]);
    const [selLevel, setSelLevel] = useState('');
    const [selArm, setSelArm] = useState('');
    const [selStream, setSelStream] = useState('');

    useEffect(() => {
        const fetchClassStructure = async () => {
            const response = await axios.get('/api/class-structure');
            setClassLevels(response.data.class_levels);
            setArms(response.data.arms);
            setStreams(response.data.streams);
            setEntries(response.data.class_level_arms);
        };
        fetchClassStructure();
    }, [loading]);

    const [filterLevel, setFilterLevel] = useState('');
    const [filterStream, setFilterStream] = useState('');

    const levelMap = useMemo(
        () => Object.fromEntries(classLevels.map((c) => [c.id, c])),
        [classLevels],
    );
    const armMap = useMemo(
        () => Object.fromEntries(arms.map((a) => [a.id, a])),
        [arms],
    );

    const filtered = useMemo(() => {
        return entries.filter((entry) => {
            const level = levelMap[entry.class_level.id];
            const stream = streams.find((s) => s.id === entry?.stream?.id);

            return (
                (!filterLevel || level?.id === filterLevel) &&
                (!filterStream ||
                    stream?.id === filterStream ||
                    (filterStream === '__none__' && !stream))
            );
        });
    }, [entries, levelMap, streams, filterLevel, filterStream]);

    const handleAdd = async () => {
        try {
            setLoading(true);

            if (!selLevel || !selArm) {
                toast.error('Select a class level and arm first.');

                return;
            }

            const payload = {
                class_level_id: selLevel,
                arm_id: selArm,
                stream_id: selStream,
            };
            const response = await axios.post('/api/class-structure', payload);

            if (response.status != 201) {
                toast.error(response.data.error);
            } else {
                toast.success('Class arm saved.');
            }
        } catch (error) {
            console.log(error);
            toast.error('Failed to save class arm.');
        } finally {
            setLoading(false);
            setSelLevel('');
            setSelArm('');
            setSelStream('');
        }
    };

    const handleStreamChange = async (entryId: string, streamId: string) => {
        try {
            setLoading(true);
            const response = await axios.patch(
                `/api/class-structure/${entryId}`,
                { stream_id: streamId },
            );

            if (response.status != 200) {
                toast.error(response.data.error);
            } else {
                toast.success('Stream updated.');
            }
        } catch (error) {
            console.log(error);
            toast.error('Failed to save class arm.');
        } finally {
            setLoading(false);
        }
    };

    const handleRemove = async (id: string) => {
        try {
            setLoading(true);
            const response = await axios.delete(`/api/class-structure/${id}`);

            if (response.status === 200) {
                toast.success('Class arm removed.');
            } else {
                toast.error(
                    response.data.error ?? 'Failed to remove class arm.'
                );
            }
        } catch (error) {
            console.log(error);
            toast.error('Failed to remove class arm.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <div>
            <div className="page-hdr">
                <div>
                    <h1>Class Level Arms</h1>
                    <p>
                        {entries.length}{' '}
                        {entries.length === 1 ? 'entry' : 'entries'} configured
                    </p>
                </div>
            </div>

            {/* Add toolbar */}
            <div className="card" style={{ marginBottom: 16 }}>
                <div className="card-hdr">
                    <span className="card-hdr-title">Add entry</span>
                    <span className="card-hdr-sub">
                        Select level, arm and optional stream
                    </span>
                </div>
                <div
                    style={{
                        padding: '12px 16px',
                        display: 'flex',
                        gap: 8,
                        flexWrap: 'wrap',
                        alignItems: 'center',
                    }}
                >
                    <select
                        value={selLevel}
                        onChange={(e) => setSelLevel(e.target.value)}
                        style={{ minWidth: 140 }}
                    >
                        <option value="">Class level…</option>
                        {classLevels.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name}
                            </option>
                        ))}
                    </select>
                    <select
                        value={selArm}
                        onChange={(e) => setSelArm(e.target.value)}
                        style={{ minWidth: 80 }}
                    >
                        <option value="">Arm…</option>
                        {arms.map((a) => (
                            <option key={a.id} value={a.id}>
                                {a.label}
                            </option>
                        ))}
                    </select>
                    <select
                        value={selStream}
                        onChange={(e) => setSelStream(e.target.value)}
                        style={{ minWidth: 160 }}
                    >
                        <option value="">Stream (optional)…</option>
                        {streams.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </select>
                    <button
                        className="btn btn-primary"
                        onClick={handleAdd}
                        disabled={loading}
                    >
                        {loading ? 'Saving…' : '+ Add arm'}
                    </button>
                </div>
            </div>

            {/* Filters */}
            <div className="filter-row">
                <button
                    className={
                        filterLevel === '' ? 'filter-btn on' : 'filter-btn'
                    }
                    onClick={() => setFilterLevel('')}
                >
                    All levels
                </button>
                {classLevels.map((c) => (
                    <button
                        key={c.id}
                        className={
                            filterLevel === c.id
                                ? 'filter-btn on'
                                : 'filter-btn'
                        }
                        onClick={() => setFilterLevel(c.id)}
                    >
                        {c.name}
                    </button>
                ))}
                <span
                    style={{
                        color: 'var(--border2)',
                        padding: '0 4px',
                        alignSelf: 'center',
                    }}
                >
                    |
                </span>
                <button
                    className={
                        filterStream === '' ? 'filter-btn on' : 'filter-btn'
                    }
                    onClick={() => setFilterStream('')}
                >
                    All streams
                </button>
                <button
                    className={
                        filterStream === '__none__'
                            ? 'filter-btn on'
                            : 'filter-btn'
                    }
                    onClick={() => setFilterStream('__none__')}
                >
                    No stream
                </button>
                {streams.map((s) => (
                    <button
                        key={s.id}
                        className={
                            filterStream === s.id
                                ? 'filter-btn on'
                                : 'filter-btn'
                        }
                        onClick={() => setFilterStream(s.id)}
                    >
                        {s.name}
                    </button>
                ))}
            </div>

            {/* Table */}
            <div className="card">
                <div className="tbl-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Class level</th>
                                <th>Arm</th>
                                <th>Stream</th>
                                <th style={{ textAlign: 'right' }}></th>
                            </tr>
                        </thead>
                        <tbody>
                            {filtered.length === 0 ? (
                                <tr>
                                    <td colSpan={4}>
                                        <Empty
                                            icon="📊"
                                            title="No entries match"
                                            sub="Adjust filters or add a new entry above"
                                        />
                                    </td>
                                </tr>
                            ) : (
                                filtered.map((entry) => {
                                    const lvl =
                                        levelMap[`${entry.class_level.id}`];
                                    const arm = armMap[`${entry.arm.id}`];

                                    return (
                                        <tr
                                            key={entry.id}
                                            style={{
                                                opacity: loading ? 0.5 : 1,
                                            }}
                                        >
                                            <td>
                                                <span
                                                    style={{
                                                        fontFamily: 'var(--mono)',
                                                        fontWeight: 700,
                                                    }}
                                                >
                                                    {lvl?.name ?? '—'}
                                                </span>
                                            </td>
                                            <td>
                                                <span className="pill pill-blue">
                                                    {arm?.label ?? '—'}
                                                </span>
                                            </td>
                                            <td>
                                                <select
                                                    value={
                                                        entry?.stream?.id ?? ''
                                                    }
                                                    onChange={(e) =>
                                                        handleStreamChange(
                                                            entry.id,
                                                            e.target.value,
                                                        )
                                                    }
                                                    disabled={loading}
                                                    style={{
                                                        height: 28,
                                                        fontSize: 12,
                                                        padding: '0 6px',
                                                    }}
                                                >
                                                    <option value="">
                                                        — none —
                                                    </option>
                                                    {streams.map((s) => (
                                                        <option
                                                            key={s.id}
                                                            value={s.id}
                                                        >
                                                            {s.name}
                                                            {s.code
                                                                ? ` (${s.code})`
                                                                : ''}
                                                        </option>
                                                    ))}
                                                </select>
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
                                                        className="btn btn-danger btn-sm btn-icon"
                                                        onClick={() =>
                                                            handleRemove(
                                                                entry.id,
                                                            )
                                                        }
                                                        disabled={loading}
                                                    >
                                                        <Trash2 className="h-3 w-3" />
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}
