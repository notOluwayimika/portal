// ═══════════════════════════════════════════════════════════════════════════
// SUBJECTS TAB
// ═══════════════════════════════════════════════════════════════════════════

import { useState } from 'react';
import type { Subject } from '@/types/models';
import { Confirm, Empty, Modal } from './school-setup';

interface SubjectForm {
    name: string;
    code: string;
}

export function SubjectsTab({
    addToast,
}: {
    addToast: (message: string, type?: ToastType) => void;
}) {
    const [subjects, setSubjects] = useState<Subject[]>([]);
    const [search, setSearch] = useState<string>('');
    const [modal, setModal] = useState<string | null>(null);
    const [form, setForm] = useState<SubjectForm>({ name: '', code: '' });
    const [confirm, setConfirm] = useState<Subject | null>(null);

    const filtered = subjects.filter(
        (s) =>
            s.name.toLowerCase().includes(search.toLowerCase()) ||
            (s.code || '').toLowerCase().includes(search.toLowerCase()),
    );

    const save = async (): Promise<void> => {
        if (!form.name.trim()) {
            return;
        }

        if (modal === 'new') {
            //
        } else {
            //
        }

        setModal(null);
    };

    return (
        <>
            <div className="page-hdr">
                <div>
                    <h1>Subjects</h1>
                    <p>{subjects.length} subjects in the catalogue</p>
                </div>
                <div className="page-hdr-actions">
                    <div className="search-wrap">
                        <span className="search-icon">🔍</span>
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
                            {filtered.length === 0 && (
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
                            {filtered.map((s, i) => (
                                <tr key={s.id}>
                                    <td
                                        className="muted"
                                        style={{
                                            fontFamily: 'var(--mono)',
                                            fontSize: 12,
                                        }}
                                    >
                                        {i + 1}
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
                                                ✏️
                                            </button>
                                            <button
                                                className="btn btn-danger btn-sm btn-icon"
                                                onClick={() => setConfirm(s)}
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
                            <button className="btn btn-primary" onClick={save}>
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
                        setSubjects((p) =>
                            p.filter((s) => s.id !== confirm.id),
                        );
                        setConfirm(null);
                    }}
                    onClose={() => setConfirm(null)}
                />
            )}
        </>
    );
}
