import { type DistributionItem } from '@/types/dashboard';

const COLORS = ['#2c197a', '#639922', '#1D9E75', '#BA7517', '#7C3AED', '#DB2777'];

interface DistributionChartProps {
    data: DistributionItem[];
    title?: string;
}

export function DistributionChart({ data, title = 'Population overview' }: DistributionChartProps) {
    if (data.length === 0) {
        return (
            <div className="bg-white border border-slate-200 rounded-lg p-4 dark:bg-slate-900 dark:border-slate-700">
                <p className="text-sm font-medium text-slate-900 dark:text-slate-100 mb-2">{title}</p>
                <p className="text-xs text-slate-400">No enrollment data yet</p>
            </div>
        );
    }

    const total = data.reduce((sum, d) => sum + d.count, 0);

    return (
        <div className="bg-white border border-slate-200 rounded-lg p-4 dark:bg-slate-900 dark:border-slate-700">
            <p className="text-sm font-medium text-slate-900 dark:text-slate-100 mb-4">{title}</p>
            <div className="space-y-3">
                {data.map((item, i) => {
                    const pct = total > 0 ? Math.round((item.count / total) * 100) : 0;
                    const color = COLORS[i % COLORS.length];
                    return (
                        <div key={item.name}>
                            <div className="flex items-center justify-between mb-1">
                                <span className="text-xs text-slate-700">{item.name}</span>
                                <span className="text-xs text-slate-500">{item.count.toLocaleString()}</span>
                            </div>
                            <div className="h-1.5 bg-slate-100 rounded-full overflow-hidden dark:bg-slate-700">
                                <div
                                    className="h-full rounded-full"
                                    style={{ width: `${pct}%`, backgroundColor: color }}
                                />
                            </div>
                        </div>
                    );
                })}
            </div>
            <div className="border-t border-slate-100 dark:border-slate-700 mt-4 pt-3 flex items-center justify-between">
                <span className="text-xs text-slate-500">Total enrolment</span>
                <span className="text-xs font-medium text-slate-900 dark:text-slate-100">{total.toLocaleString()} students</span>
            </div>
        </div>
    );
}
