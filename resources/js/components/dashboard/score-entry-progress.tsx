import { type ScoreEntryItem } from '@/types/dashboard';

const COLORS = ['#378ADD', '#639922', '#1D9E75', '#BA7517', '#7C3AED'];

interface ScoreEntryProgressProps {
    data: ScoreEntryItem[];
    termLabel?: string;
}

export function ScoreEntryProgress({ data, termLabel }: ScoreEntryProgressProps) {
    return (
        <div className="bg-white border border-slate-200 rounded-lg p-4 dark:bg-slate-900 dark:border-slate-700">
            <div className="flex items-center justify-between mb-3">
                <p className="text-sm font-medium text-slate-900 dark:text-slate-100">Score entry progress</p>
            </div>
            {termLabel && (
                <span className="inline-flex items-center px-2 py-0.5 rounded-md bg-slate-100 text-[11px] text-slate-600 mb-4 dark:bg-slate-800 dark:text-slate-400">
                    {termLabel}
                </span>
            )}
            {data.length === 0 ? (
                <p className="text-xs text-slate-400">No assessment data for the current term</p>
            ) : (
                <div className="space-y-3">
                    {data.map((row, i) => (
                        <div key={row.label} className="flex items-center gap-3">
                            <span className="w-24 text-xs text-slate-600 shrink-0 truncate">{row.label}</span>
                            <div className="flex-1 h-1.5 bg-slate-100 rounded-full overflow-hidden dark:bg-slate-700">
                                <div
                                    className="h-full rounded-full"
                                    style={{ width: `${row.pct}%`, backgroundColor: COLORS[i % COLORS.length] }}
                                />
                            </div>
                            <span className="text-xs text-slate-500 w-8 text-right">{row.pct}%</span>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
