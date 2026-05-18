import { type DailyCount } from '@/types/dashboard';
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

interface TrendChartProps {
    data: DailyCount[];
    label: string;
    color?: string;
    fullSize?: boolean;
}

export function TrendChart({ data, label, color = '#2c197a', fullSize = false }: TrendChartProps) {
    if (data.length === 0) {
        return (
            <div className="bg-white border border-slate-200 rounded-lg p-4 dark:bg-slate-900 dark:border-slate-700">
                <p className="text-sm font-medium text-slate-900 dark:text-slate-100 mb-2">{label}</p>
                <p className="text-xs text-slate-400">No activity data in the last 30 days</p>
            </div>
        );
    }

    const formatted = data.map((d) => ({
        ...d,
        dateLabel: new Date(d.date).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' }),
    }));

    return (
        <div className="bg-white border border-slate-200 rounded-lg p-4 dark:bg-slate-900 dark:border-slate-700">
            <p className="text-sm font-medium text-slate-900 dark:text-slate-100 mb-3">{label}</p>
            <div className={fullSize ? 'h-52' : 'h-32'}>
                <ResponsiveContainer width="100%" height="100%">
                    <AreaChart data={formatted}>
                        <defs>
                            <linearGradient id="trend-gradient" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="5%" stopColor={color} stopOpacity={0.12} />
                                <stop offset="95%" stopColor={color} stopOpacity={0} />
                            </linearGradient>
                        </defs>
                        {fullSize && (
                            <>
                                <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" strokeOpacity={0.4} />
                                <XAxis
                                    dataKey="dateLabel"
                                    tick={{ fontSize: 10, fill: '#94a3b8' }}
                                    axisLine={false}
                                    tickLine={false}
                                    interval="preserveStartEnd"
                                />
                                <YAxis
                                    tick={{ fontSize: 10, fill: '#94a3b8' }}
                                    axisLine={false}
                                    tickLine={false}
                                    width={30}
                                />
                                <Tooltip
                                    contentStyle={{
                                        border: '1px solid #e2e8f0',
                                        borderRadius: '6px',
                                        fontSize: '11px',
                                        padding: '4px 8px',
                                    }}
                                    labelStyle={{ color: '#475569' }}
                                    itemStyle={{ color: '#1e293b' }}
                                />
                            </>
                        )}
                        <Area
                            type="monotone"
                            dataKey="count"
                            stroke={color}
                            strokeWidth={1.5}
                            fill="url(#trend-gradient)"
                            dot={false}
                            isAnimationActive={false}
                        />
                    </AreaChart>
                </ResponsiveContainer>
            </div>
        </div>
    );
}
