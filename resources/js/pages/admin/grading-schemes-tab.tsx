import axios from 'axios';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'react-toastify';
import { Empty, Modal } from '@/components/setup/setup-ui';
import type { GradingScheme } from '@/types/models';

interface CategoryRow {
    code: string;
    label: string;
}

export function GradingSchemesTab() {
    const [schemes, setSchemes] = useState<GradingScheme[]>([]);
    const [editing, setEditing] = useState<GradingScheme | 'new' | null>(null);
    const [name, setName] = useState('');
    const [items, setItems] = useState<CategoryRow[]>([]);
    const [saving, setSaving] = useState(false);

    const load = async () => {
        const response = await axios.get('/api/grading-schemes');
        setSchemes(response.data.data ?? []);
    };

    useEffect(() => {
        void load();
    }, []);

    const openNew = () => {
        setName('Nursery Progress');
        setItems([
            { code: 'GP', label: 'Good Progress' },
            { code: 'WS', label: 'Working on Skills' },
            { code: 'CL', label: 'Can Improve' },
            { code: 'NA', label: 'Not Applicable' },
        ]);
        setEditing('new');
    };

    const openEdit = (scheme: GradingScheme) => {
        setName(scheme.name);
        setItems(scheme.items.map(({ code, label }) => ({ code, label })));
        setEditing(scheme);
    };

    const save = async () => {
        if (
            !name.trim() ||
            items.length < 2 ||
            items.some((item) => !item.code.trim() || !item.label.trim())
        ) {
            toast.error('Enter a name and at least two complete categories.');

            return;
        }
        setSaving(true);
        try {
            const payload = {
                name: name.trim(),
                items: items.map((item) => ({
                    code: item.code.trim().toUpperCase(),
                    label: item.label.trim(),
                })),
            };
            if (editing === 'new') {
                await axios.post('/api/grading-schemes', payload);
            } else if (editing) {
                await axios.put(`/api/grading-schemes/${editing.id}`, payload);
            }
            toast.success('Categorical grading scheme saved.');
            setEditing(null);
            await load();
        } catch {
            toast.error('Failed to save grading scheme.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <>
            <div className="page-hdr">
                <div>
                    <h1>Categorical Grading</h1>
                    <p>
                        Create progress-rating categories for class levels that
                        do not use scores. Numerical grading remains the
                        default.
                    </p>
                </div>
                <button className="btn btn-primary" onClick={openNew}>
                    <Plus className="h-4 w-4" /> New Scheme
                </button>
            </div>
            <div className="card">
                {schemes.length === 0 ? (
                    <Empty
                        icon="🏷️"
                        title="No categorical schemes"
                        sub="Create one, then assign it to a class level."
                    />
                ) : (
                    schemes.map((scheme) => (
                        <div
                            key={scheme.id}
                            className="flex items-center gap-4 border-b border-slate-200 px-5 py-4 last:border-0"
                        >
                            <div className="flex-1">
                                <div className="font-semibold text-slate-900">
                                    {scheme.name}
                                </div>
                                <div className="mt-2 flex flex-wrap gap-2">
                                    {scheme.items.map((item) => (
                                        <span
                                            key={item.id}
                                            className="rounded-full bg-blue-50 px-2.5 py-1 text-xs text-blue-700"
                                        >
                                            <strong>{item.code}</strong> ·{' '}
                                            {item.label}
                                        </span>
                                    ))}
                                </div>
                            </div>
                            <button
                                className="btn btn-ghost btn-sm btn-icon"
                                onClick={() => openEdit(scheme)}
                            >
                                <Pencil className="h-4 w-4" />
                            </button>
                        </div>
                    ))
                )}
            </div>
            {editing && (
                <Modal
                    title={
                        editing === 'new'
                            ? 'New Categorical Scheme'
                            : 'Edit Categorical Scheme'
                    }
                    onClose={() => setEditing(null)}
                    large
                    footer={
                        <>
                            <button
                                className="btn btn-outline"
                                onClick={() => setEditing(null)}
                            >
                                Cancel
                            </button>
                            <button
                                className="btn btn-primary"
                                disabled={saving}
                                onClick={save}
                            >
                                {saving ? 'Saving…' : 'Save Scheme'}
                            </button>
                        </>
                    }
                >
                    <div className="field">
                        <label>Scheme name</label>
                        <input
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                            placeholder="e.g. Nursery Progress"
                        />
                    </div>
                    <div className="space-y-2">
                        <label>Categories</label>
                        {items.map((item, index) => (
                            <div key={index} className="flex gap-2">
                                <input
                                    className="w-24"
                                    value={item.code}
                                    maxLength={20}
                                    placeholder="GP"
                                    onChange={(event) =>
                                        setItems((current) =>
                                            current.map((row, rowIndex) =>
                                                rowIndex === index
                                                    ? {
                                                          ...row,
                                                          code: event.target
                                                              .value,
                                                      }
                                                    : row,
                                            ),
                                        )
                                    }
                                />
                                <input
                                    className="flex-1"
                                    value={item.label}
                                    placeholder="Good Progress"
                                    onChange={(event) =>
                                        setItems((current) =>
                                            current.map((row, rowIndex) =>
                                                rowIndex === index
                                                    ? {
                                                          ...row,
                                                          label: event.target
                                                              .value,
                                                      }
                                                    : row,
                                            ),
                                        )
                                    }
                                />
                                <button
                                    className="btn btn-danger btn-icon"
                                    disabled={items.length <= 2}
                                    onClick={() =>
                                        setItems((current) =>
                                            current.filter(
                                                (_, rowIndex) =>
                                                    rowIndex !== index,
                                            ),
                                        )
                                    }
                                >
                                    <Trash2 className="h-4 w-4" />
                                </button>
                            </div>
                        ))}
                        <button
                            className="btn btn-outline btn-sm"
                            onClick={() =>
                                setItems((current) => [
                                    ...current,
                                    { code: '', label: '' },
                                ])
                            }
                        >
                            + Add Category
                        </button>
                    </div>
                </Modal>
            )}
        </>
    );
}
