import axios from 'axios';
import { Check, Pencil, Trash2, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'react-toastify';
import { Confirm, Empty, Modal } from '@/components/setup/setup-ui';
import type { Scholarship, ScholarshipKind } from '@/types/models';

interface ScholarshipForm {
    name: string;
    /** '' is "the operator has not chosen yet" in this form only — the server refuses it on create. */
    kind: ScholarshipKind | '';
}

/*
 * WHAT THE SCHEME DOES, NOT WHAT THE ENUM IS CALLED.
 *
 * 'discount' and 'sponsored' are the wire values and they mean nothing to a bursar reading this
 * screen. What they need to decide between is whether the family still gets a bill. So the labels
 * below say that, and the enum value is never rendered on its own.
 *
 * A scholarship with no kind is NOT blank here. It is refused by the bulk invoice run and by a
 * discount award, so it is a state the operator has to be able to see and act on — rendering it as
 * an empty cell would hide the one thing on this screen that currently blocks billing.
 */
const KIND_LABEL: Record<ScholarshipKind, string> = {
    discount: 'Discount — the school reduces the bill',
    sponsored: 'Sponsored — someone outside pays',
};

const KIND_DETAIL: Record<ScholarshipKind, string> = {
    discount:
        'The family still gets a bill, for less. These students are invoiced by the termly run.',
    sponsored:
        'An outside organisation pays, off platform. The family is not billed at all, and these students are left out of the termly run.',
};

const UNCONFIGURED_LABEL = 'Not configured';
const UNCONFIGURED_DETAIL =
    'Nobody has said which scheme this is. Students on it cannot be billed and cannot be given a discount until you choose.';

/** The message the server sent, if it sent one, so a 422 says which field rather than "an error". */
function apiMessage(error: unknown, fallback: string): string {
    if (axios.isAxiosError(error)) {
        const data = error.response?.data as
            | { message?: string; error?: string }
            | undefined;

        return data?.message ?? data?.error ?? fallback;
    }

    return fallback;
}

export function ScholarshipsTab() {
    const [scholarships, setScholarships] = useState<Scholarship[]>([]);
    const [modal, setModal] = useState<string | null>(null);
    const [form, setForm] = useState<ScholarshipForm>({ name: '', kind: '' });
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

    /*
     * The kind change sends the NAME too, because PUT /api/scholarships/{uuid} requires it. It is
     * the row's current name, unchanged — this control classifies, it does not rename.
     */
    const changeKind = async (
        scholarship: Scholarship,
        kind: ScholarshipKind,
    ): Promise<void> => {
        if (scholarship.kind === kind) {
            return;
        }

        setLoading(true);

        try {
            const response = await axios.put(
                `/api/scholarships/${scholarship.uuid}`,
                { name: scholarship.name, kind },
            );

            if (response.status === 200) {
                toast.success(
                    `"${scholarship.name}" is now ${KIND_LABEL[kind]}.`,
                );
            } else {
                toast.error('Failed to update scholarship.');
            }
        } catch (error) {
            console.log(error);
            toast.error(
                apiMessage(
                    error,
                    'An error occurred while saving the scholarship.',
                ),
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
            toast.error(
                apiMessage(
                    error,
                    'An error occurred while saving the scholarship.',
                ),
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
                    <h1>Scholarships</h1>
                    <p>C2C, BSS, and other scholarship categories.</p>
                </div>
                <div className="page-hdr-actions">
                    <button
                        className="btn btn-primary"
                        onClick={() => {
                            setForm({ name: '', kind: '' });
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
                                <th>Who pays</th>
                                <th style={{ textAlign: 'right' }}>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {scholarships.length === 0 && (
                                <tr>
                                    <td colSpan={3}>
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
                                        <select
                                            value={s.kind ?? ''}
                                            disabled={loading}
                                            style={{ minWidth: 240 }}
                                            onChange={(ev) =>
                                                changeKind(
                                                    s,
                                                    ev.target
                                                        .value as ScholarshipKind,
                                                )
                                            }
                                        >
                                            {/*
                                             * Selectable only while it is the row's current state,
                                             * and never a destination: NULL means "nobody has said
                                             * yet", and un-saying it is not something the screen
                                             * should offer. The server refuses it too.
                                             */}
                                            <option value="" disabled>
                                                {UNCONFIGURED_LABEL} — choose
                                                one
                                            </option>
                                            <option value="discount">
                                                {KIND_LABEL.discount}
                                            </option>
                                            <option value="sponsored">
                                                {KIND_LABEL.sponsored}
                                            </option>
                                        </select>
                                        <div
                                            className="muted"
                                            style={{
                                                fontSize: 12,
                                                marginTop: 4,
                                                maxWidth: 360,
                                            }}
                                        >
                                            {s.kind === null
                                                ? UNCONFIGURED_DETAIL
                                                : KIND_DETAIL[s.kind]}
                                        </div>
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
                            <button
                                className="btn btn-primary"
                                disabled={!form.name.trim() || form.kind === ''}
                                onClick={save}
                            >
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
                            onChange={(e) =>
                                setForm({ ...form, name: e.target.value })
                            }
                            autoFocus
                        />
                    </div>
                    <div className="field">
                        <label>Who pays</label>
                        <select
                            value={form.kind}
                            onChange={(e) =>
                                setForm({
                                    ...form,
                                    kind: e.target.value as ScholarshipKind,
                                })
                            }
                        >
                            <option value="" disabled>
                                Choose one…
                            </option>
                            <option value="discount">
                                {KIND_LABEL.discount}
                            </option>
                            <option value="sponsored">
                                {KIND_LABEL.sponsored}
                            </option>
                        </select>
                        {/*
                         * The disabled Save button above is a convenience, NOT the control: the
                         * server refuses a create with no kind with a 422, and that refusal is what
                         * stops another unconfigured row being minted. A new scholarship can always
                         * say which scheme it is, so there is no reason to offer the blank.
                         */}
                        <div
                            className="muted"
                            style={{ fontSize: 12, marginTop: 4 }}
                        >
                            {form.kind === ''
                                ? 'Required. A scholarship with no answer here cannot be billed and cannot carry a discount.'
                                : KIND_DETAIL[form.kind]}
                        </div>
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
