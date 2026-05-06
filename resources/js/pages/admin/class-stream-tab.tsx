import axios from 'axios';
import { useState, useMemo, useEffect } from 'react';
import type { ToastType } from '@/components/toast-item';
import type { Arm, ClassLevel, ClassLevelArm, Stream } from '@/types/models';

// ─── Component ────────────────────────────────────────────────────────────────

export default function ClassStreamTab({
    addToast,
}: {
    addToast: (message: string, type?: ToastType) => void;
}) {
    const [loading, setLoading] = useState(false);

    const [classLevels, setClassLevels] = useState<ClassLevel[]>([]);
    const [arms, setArms] = useState<Arm[]>([]);
    const [streams, setStreams] = useState<Stream[]>([]);
    const [entries, setEntries] = useState<ClassLevelArm[]>([]);
    // Add form state
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

    // Filters
    const [filterLevel, setFilterLevel] = useState('');
    const [filterStream, setFilterStream] = useState('');

    // ── Lookup helpers ──────────────────────────────────────────────────────────

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

    // ── Add entry ───────────────────────────────────────────────────────────────

    const handleAdd = async () => {
        try {
            setLoading(true);

            if (!selLevel || !selArm) {
                addToast('Select a class level and arm first.', 'error');

                return;
            }

            const payload = {
                class_level_id: selLevel,
                arm_id: selArm,
                stream_id: selStream,
            };
            const response = await axios.post('/api/class-structure', payload);

            if (response.status != 201) {
                addToast(response.data.error, 'error');
            } else {
                addToast('Class arm saved.', 'success');
            }
        } catch (error) {
            console.log(error);
            addToast('Failed to save class arm.', 'error');
        } finally {
            setLoading(false);
            setSelLevel('');
            setSelArm('');
            setSelStream('');
        }
    };

    // ── Update stream ───────────────────────────────────────────────────────────

    const handleStreamChange = async (entryId: string, streamId: string) => {
        try {
            setLoading(true);

            // handle update logic
            const payload = {
                stream_id: streamId,
            };
            const response = await axios.patch(
                `/api/class-structure/${entryId}`,
                payload,
            );

            if (response.status != 200) {
                addToast(response.data.error, 'error');
            } else {
                addToast('Class arm saved.', 'success');
            }
        } catch (error) {
            console.log(error);
            addToast('Failed to save class arm.', 'error');
        } finally {
            setLoading(false);
        }
    };

    // ── Remove entry ────────────────────────────────────────────────────────────

    const handleRemove = async (id: string) => {
        try {
            setLoading(true);
            const response = await axios.delete(`/api/class-structure/${id}`);

            if (response.status === 200) {
                addToast('Class arm removed.', 'success');
            } else {
                addToast(
                    response.data.error ?? 'Failed to remove class arm.',
                    'error',
                );
            }
        } catch (error) {
            console.log(error);
            addToast('Failed to remove class arm.', 'error');
        } finally {
            setLoading(false);
        }
    };

    // ── Render ──────────────────────────────────────────────────────────────────

    return (
        <div style={{ fontFamily: 'inherit', fontSize: 14 }}>
            {/* Header */}
            <div
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 8,
                    marginBottom: 16,
                }}
            >
                <h3
                    style={{
                        fontSize: 15,
                        fontWeight: 500,
                        margin: 0,
                        color: 'var(--color-text-primary, #111)',
                    }}
                >
                    Class level arms
                </h3>
                <span
                    style={{
                        fontSize: 11,
                        padding: '2px 8px',
                        borderRadius: 99,
                        background:
                            'var(--color-background-secondary, #f4f4f4)',
                        color: 'var(--color-text-secondary, #666)',
                    }}
                >
                    {entries.length}{' '}
                    {entries.length === 1 ? 'entry' : 'entries'}
                </span>
            </div>

            {/* Add toolbar */}
            <div
                style={{
                    display: 'flex',
                    gap: 8,
                    marginBottom: 12,
                    flexWrap: 'wrap',
                    alignItems: 'center',
                }}
            >
                <Select
                    value={selLevel}
                    onChange={setSelLevel}
                    placeholder="Class level…"
                >
                    {classLevels.map((c) => (
                        <option key={c.id} value={c.id}>
                            {c.name}
                        </option>
                    ))}
                </Select>

                <Select value={selArm} onChange={setSelArm} placeholder="Arm…">
                    {arms.map((a) => (
                        <option key={a.id} value={a.id}>
                            {a.label}
                        </option>
                    ))}
                </Select>

                <Select
                    value={selStream}
                    onChange={setSelStream}
                    placeholder="Stream (optional)…"
                >
                    {streams.map((s) => (
                        <option key={s.id} value={s.id}>
                            {s.name}
                        </option>
                    ))}
                </Select>

                <button
                    onClick={handleAdd}
                    disabled={loading}
                    style={primaryBtnStyle}
                >
                    {loading ? 'Saving…' : '+ Add arm'}
                </button>
            </div>

            {/* Filters */}
            <div
                style={{
                    display: 'flex',
                    gap: 8,
                    marginBottom: 16,
                    flexWrap: 'wrap',
                }}
            >
                <Select
                    value={filterLevel}
                    onChange={setFilterLevel}
                    placeholder="All levels"
                    small
                >
                    {classLevels.map((c) => (
                        <option key={c.id} value={c.id}>
                            {c.name}
                        </option>
                    ))}
                </Select>

                <Select
                    value={filterStream}
                    onChange={setFilterStream}
                    placeholder="All streams"
                    small
                >
                    <option value="__none__">No stream</option>
                    {streams.map((s) => (
                        <option key={s.id} value={s.id}>
                            {s.name}
                        </option>
                    ))}
                </Select>
            </div>

            {/* Table */}
            <div
                style={{
                    border: '0.5px solid var(--color-border-tertiary, #e5e5e5)',
                    borderRadius: 10,
                    overflow: 'hidden',
                }}
            >
                <table
                    style={{
                        width: '100%',
                        borderCollapse: 'collapse',
                        fontSize: 13,
                    }}
                >
                    <thead>
                        <tr
                            style={{
                                background:
                                    'var(--color-background-secondary, #f9f9f9)',
                            }}
                        >
                            {['Class level', 'Arm', 'Stream', ''].map(
                                (h, i) => (
                                    <th
                                        key={i}
                                        style={{
                                            textAlign: 'left',
                                            padding: '8px 12px',
                                            fontSize: 11,
                                            fontWeight: 500,
                                            color: 'var(--color-text-secondary, #888)',
                                            textTransform: 'uppercase',
                                            letterSpacing: '0.04em',
                                            borderBottom:
                                                '0.5px solid var(--color-border-tertiary, #e5e5e5)',
                                        }}
                                    >
                                        {h}
                                    </th>
                                ),
                            )}
                        </tr>
                    </thead>
                    <tbody>
                        {filtered.length === 0 ? (
                            <tr>
                                <td
                                    colSpan={5}
                                    style={{
                                        textAlign: 'center',
                                        padding: '2.5rem 1rem',
                                        color: 'var(--color-text-tertiary, #bbb)',
                                        fontSize: 14,
                                    }}
                                >
                                    No entries match the current filters.
                                </td>
                            </tr>
                        ) : (
                            filtered.map((entry, idx) => {
                                const lvl = levelMap[`${entry.class_level.id}`];
                                const arm = armMap[`${entry.arm.id}`];
                                const isLoading = loading;

                                return (
                                    <tr
                                        key={entry.id}
                                        style={{
                                            borderBottom:
                                                idx < filtered.length - 1
                                                    ? '0.5px solid var(--color-border-tertiary, #e5e5e5)'
                                                    : 'none',
                                            opacity: isLoading ? 0.5 : 1,
                                            transition: 'background 0.1s',
                                        }}
                                        onMouseEnter={(e) =>
                                            ((
                                                e.currentTarget as HTMLElement
                                            ).style.background =
                                                'var(--color-background-secondary, #f9f9f9)')
                                        }
                                        onMouseLeave={(e) =>
                                            ((
                                                e.currentTarget as HTMLElement
                                            ).style.background = 'transparent')
                                        }
                                    >
                                        {/* Class level */}
                                        <td style={tdStyle}>
                                            <span style={{ fontWeight: 500 }}>
                                                {lvl?.name ?? '—'}
                                            </span>
                                        </td>

                                        {/* Arm */}
                                        <td style={tdStyle}>
                                            {arm?.label ?? '—'}
                                        </td>

                                        {/* Stream inline select */}
                                        <td style={tdStyle}>
                                            <select
                                                value={entry?.stream?.id ?? ''}
                                                onChange={(e) =>
                                                    handleStreamChange(
                                                        entry.id,
                                                        e.target.value,
                                                    )
                                                }
                                                disabled={isLoading}
                                                style={streamSelectStyle}
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

                                        {/* Actions */}
                                        <td
                                            style={{
                                                ...tdStyle,
                                                textAlign: 'right',
                                            }}
                                        >
                                            <button
                                                onClick={() =>
                                                    handleRemove(entry.id)
                                                }
                                                disabled={isLoading}
                                                style={removeBtnStyle}
                                                onMouseEnter={(e) => {
                                                    (
                                                        e.currentTarget as HTMLElement
                                                    ).style.background =
                                                        '#FCEBEB';
                                                    (
                                                        e.currentTarget as HTMLElement
                                                    ).style.color = '#A32D2D';
                                                    (
                                                        e.currentTarget as HTMLElement
                                                    ).style.borderColor =
                                                        '#F09595';
                                                }}
                                                onMouseLeave={(e) => {
                                                    (
                                                        e.currentTarget as HTMLElement
                                                    ).style.background =
                                                        'transparent';
                                                    (
                                                        e.currentTarget as HTMLElement
                                                    ).style.color =
                                                        'var(--color-text-secondary, #888)';
                                                    (
                                                        e.currentTarget as HTMLElement
                                                    ).style.borderColor =
                                                        'var(--color-border-secondary, #ddd)';
                                                }}
                                            >
                                                Remove
                                            </button>
                                        </td>
                                    </tr>
                                );
                            })
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

// ─── Sub-component: Select ────────────────────────────────────────────────────

function Select({
    value,
    onChange,
    placeholder,
    children,
    small = false,
}: {
    value: string;
    onChange: (v: string) => void;
    placeholder?: string;
    children: React.ReactNode;
    small?: boolean;
}) {
    return (
        <select
            value={value}
            onChange={(e) => onChange(e.target.value)}
            style={{
                height: small ? 30 : 36,
                fontSize: small ? 12 : 14,
                borderRadius: 8,
                border: '0.5px solid var(--color-border-secondary, #ddd)',
                padding: '0 10px',
                background: 'var(--color-background-primary, #fff)',
                color: value
                    ? 'var(--color-text-primary, #111)'
                    : 'var(--color-text-secondary, #888)',
                minWidth: small ? 110 : 140,
                cursor: 'pointer',
            }}
        >
            {placeholder && <option value="">{placeholder}</option>}
            {children}
        </select>
    );
}

// ─── Styles ───────────────────────────────────────────────────────────────────

const tdStyle: React.CSSProperties = {
    padding: '9px 12px',
    color: 'var(--color-text-primary, #111)',
    verticalAlign: 'middle',
};

const streamSelectStyle: React.CSSProperties = {
    height: 28,
    fontSize: 12,
    borderRadius: 8,
    border: '0.5px solid var(--color-border-secondary, #ddd)',
    padding: '0 6px',
    background: 'var(--color-background-primary, #fff)',
    color: 'var(--color-text-primary, #111)',
    cursor: 'pointer',
};

const primaryBtnStyle: React.CSSProperties = {
    height: 36,
    padding: '0 16px',
    fontSize: 14,
    borderRadius: 8,
    border: 'none',
    background: '#185FA5',
    color: '#fff',
    cursor: 'pointer',
    fontWeight: 500,
    whiteSpace: 'nowrap',
};

const removeBtnStyle: React.CSSProperties = {
    height: 28,
    padding: '0 10px',
    fontSize: 12,
    borderRadius: 8,
    border: '0.5px solid var(--color-border-secondary, #ddd)',
    background: 'transparent',
    color: 'var(--color-text-secondary, #888)',
    cursor: 'pointer',
    transition: 'background 0.1s, color 0.1s, border-color 0.1s',
};
