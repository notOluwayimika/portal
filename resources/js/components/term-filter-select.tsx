import axios from 'axios';
import { useEffect, useState } from 'react';
import type { Term } from '@/types/models';

/**
 * Term picker for data-entry pages that support past-term (backfilled)
 * entry. Empty value = "current term" (no term_id param is sent, so the
 * server falls back to the active term — today's behavior).
 */
export function TermFilterSelect({
    value,
    onChange,
    className,
}: {
    value: string;
    onChange: (termId: string) => void;
    className?: string;
}) {
    const [terms, setTerms] = useState<Term[]>([]);

    useEffect(() => {
        let cancelled = false;

        axios
            .get('/api/class-structure')
            .then((res) => {
                if (cancelled) {
                    return;
                }
                const all: Term[] = res.data.terms ?? [];
                setTerms(all.filter((t) => t.status !== 'upcoming'));
            })
            .catch(() => {
                // Non-fatal: the page still works against the current term.
            });

        return () => {
            cancelled = true;
        };
    }, []);

    return (
        <select
            value={value}
            onChange={(e) => onChange(e.target.value)}
            className={
                className ??
                'rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700 focus:border-indigo-400 focus:outline-none'
            }
        >
            <option value="">Current term</option>
            {terms
                .filter((t) => t.status === 'completed')
                .map((t) => (
                    <option key={t.id} value={t.id}>
                        {t.full_name}
                    </option>
                ))}
        </select>
    );
}
