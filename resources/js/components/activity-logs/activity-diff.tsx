import type { DiffRow } from './types';

function render(v: unknown): string {
    if (v === null || v === undefined || v === '') return '—';
    if (typeof v === 'object') return JSON.stringify(v);
    return String(v);
}

/** Before/after diff. Server already filters to changed fields and masks
 *  sensitive ones; this only renders. */
export function ActivityDiff({ diff }: { diff: DiffRow[] }) {
    if (!diff.length) {
        return (
            <p className="px-1 py-2 text-xs text-muted-foreground">
                No field-level changes recorded.
            </p>
        );
    }

    return (
        <table className="w-full border-collapse text-xs">
            <thead>
                <tr className="border-b text-muted-foreground dark:border-slate-800">
                    <th className="py-1.5 pr-3 text-left font-normal">Field</th>
                    <th className="py-1.5 pr-3 text-left font-normal">Before</th>
                    <th className="py-1.5 text-left font-normal">After</th>
                </tr>
            </thead>
            <tbody>
                {diff.map((row) => (
                    <tr
                        key={row.field}
                        className="border-b last:border-0 dark:border-slate-800"
                    >
                        <td className="py-1.5 pr-3 font-medium">{row.field}</td>
                        <td className="py-1.5 pr-3 font-mono text-red-600 line-through dark:text-red-400">
                            {render(row.old)}
                        </td>
                        <td className="py-1.5 font-mono text-green-700 dark:text-green-400">
                            {render(row.new)}
                        </td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}
