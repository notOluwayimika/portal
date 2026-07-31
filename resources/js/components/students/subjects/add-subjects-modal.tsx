import axios from 'axios';
import { Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import Modal from '@/components/ui/Modal';
import type { StudentSubjectsGrouped } from '@/types/models';

interface AddSubjectsModalProps {
    isOpen: boolean;
    grouped: StudentSubjectsGrouped | null;
    enrollmentId: string;
    studentId: string;
    onClose: () => void;
    onAdded: () => void;
}

export function AddSubjectsModal({
    isOpen,
    grouped,
    enrollmentId,
    studentId,
    onClose,
    onAdded,
}: AddSubjectsModalProps) {
    const [search, setSearch] = useState('');
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const allItems = useMemo(() => {
        const available = (grouped?.optional_available ?? []).map((cs) => ({
            id: cs.id,
            name: cs.subject_name,
            code: cs.subject_code,
            isPreviouslyDropped: false,
        }));

        // Archived subjects must NOT be offered. `optional_available` is already
        // filtered server-side (StudentCurriculum::availableOptionalSubjects uses
        // ->active()), but `optional_dropped` is not — it is the student's own
        // history, and rightly still lists a subject the curriculum archived after
        // they dropped it. Merging the two lists unfiltered therefore put archived
        // subjects back in the picker, where selecting one failed on submit with
        // "This subject has been archived in the curriculum and cannot be added to
        // new students." Filtered here rather than server-side so the dropped row
        // keeps showing in the student's subject list, which is what it is for.
        const dropped = (grouped?.optional_dropped ?? [])
            .filter((s) => s.curriculum_subject.active !== false)
            .map((s) => ({
                id: s.curriculum_subject.id,
                name: s.curriculum_subject.subject?.name ?? '',
                code: s.curriculum_subject.subject?.code ?? null,
                isPreviouslyDropped: true,
            }));

        return [...available, ...dropped];
    }, [grouped]);

    const filtered = useMemo(() => {
        if (!search.trim()) {
            return allItems;
        }

        const q = search.toLowerCase();

        return allItems.filter(
            (i) =>
                i.name.toLowerCase().includes(q) ||
                (i.code ?? '').toLowerCase().includes(q),
        );
    }, [allItems, search]);

    function toggleItem(id: string) {
        setSelected((prev) => {
            const next = new Set(prev);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });
    }

    async function handleAdd() {
        if (selected.size === 0) {
            return;
        }

        setBusy(true);
        setError(null);

        try {
            await axios.post(
                `/api/students/${studentId}/enrollments/${enrollmentId}/subjects`,
                {
                    curriculum_subject_ids: Array.from(selected),
                },
            );
            setSelected(new Set());
            setSearch('');
            onAdded();
            onClose();
        } catch (err: any) {
            setError(
                err?.response?.data?.message ??
                    'Failed to add subjects. Please try again.',
            );
        } finally {
            setBusy(false);
        }
    }

    function handleClose() {
        setSelected(new Set());
        setSearch('');
        setError(null);
        onClose();
    }

    return (
        <Modal
            isOpen={isOpen}
            onClose={handleClose}
            title="Add Optional Subjects"
            size="md"
            footer={
                <div className="flex items-center justify-between">
                    <span className="text-sm text-slate-500">
                        {selected.size > 0 ? `${selected.size} selected` : ''}
                    </span>
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            onClick={handleClose}
                            disabled={busy}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={handleAdd}
                            disabled={busy || selected.size === 0}
                        >
                            {busy ? 'Adding…' : 'Add Selected'}
                        </Button>
                    </div>
                </div>
            }
        >
            <div className="space-y-3">
                <div className="relative">
                    <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input
                        type="text"
                        className="w-full rounded-md border border-slate-200 bg-white py-2 pr-3 pl-9 text-sm placeholder-slate-400 focus:border-indigo-400 focus:outline-none dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                        placeholder="Search subjects…"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                </div>

                {filtered.length === 0 ? (
                    <p className="py-6 text-center text-sm text-slate-400">
                        No optional subjects available.
                    </p>
                ) : (
                    <ul className="max-h-72 divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
                        {filtered.map((item) => (
                            <li key={item.id}>
                                <label className="flex cursor-pointer items-center gap-3 px-1 py-2.5 hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                    <input
                                        type="checkbox"
                                        className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                        checked={selected.has(item.id)}
                                        onChange={() => toggleItem(item.id)}
                                    />
                                    <span className="flex-1 text-sm text-slate-700 dark:text-slate-200">
                                        {item.name}
                                        {item.code && (
                                            <span className="ml-1.5 text-xs text-slate-400">
                                                {item.code}
                                            </span>
                                        )}
                                    </span>
                                    {item.isPreviouslyDropped && (
                                        <span className="text-[10px] text-amber-500 dark:text-amber-400">
                                            previously dropped — will be
                                            restored
                                        </span>
                                    )}
                                </label>
                            </li>
                        ))}
                    </ul>
                )}

                {error && (
                    <p className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-600 dark:bg-red-900/30 dark:text-red-400">
                        {error}
                    </p>
                )}
            </div>
        </Modal>
    );
}
