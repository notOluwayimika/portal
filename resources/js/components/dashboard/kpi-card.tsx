import { type DailyCount } from '@/types/dashboard';
import { Area, AreaChart, ResponsiveContainer } from 'recharts';

type Tone = 'up' | 'down' | 'warning' | 'muted' | 'neutral';

const toneStyles: Record<Tone, { text: string; bg: string; arrow: string }> = {
    up: { text: 'text-green-600', bg: 'bg-green-50', arrow: '↑' },
    down: { text: 'text-red-600', bg: 'bg-red-50', arrow: '↓' },
    warning: { text: 'text-amber-600', bg: 'bg-amber-50', arrow: '!' },
    muted: { text: 'text-slate-400', bg: 'bg-slate-50 dark:bg-slate-800', arrow: '' },
    neutral: { text: 'text-slate-600', bg: 'bg-slate-50 dark:bg-slate-800', arrow: '→' },
};

const toneColor: Record<Tone, string> = {
    up: '#16a34a',
    down: '#dc2626',
    warning: '#d97706',
    muted: '#94a3b8',
    neutral: '#475569',
};

interface KpiCardProps {
    label: string;
    value: string | number;
    subText?: string;
    tone?: Tone;
    sparklineData?: DailyCount[];
    href?: string;
}

export function KpiCard({ label, value, subText, tone = 'neutral', sparklineData, href }: KpiCardProps) {
    const styles = toneStyles[tone];
    const Wrapper = href ? 'a' : 'div';

    return (
        <Wrapper
            {...(href ? { href } : {})}
            className="bg-white border border-slate-200 rounded-lg p-4 block hover:border-slate-300 transition-colors dark:bg-slate-900 dark:border-slate-700 dark:hover:border-slate-600"
        >
            <p className="text-xs text-slate-500 dark:text-slate-400">{label}</p>
            <p className="text-2xl font-medium text-slate-900 mt-1 tabular-nums dark:text-white">
                {typeof value === 'number' ? value.toLocaleString() : value}
            </p>
            {subText && (
                <p className={`text-xs mt-1 ${styles.text}`}>
                    {styles.arrow && <span className="mr-0.5">{styles.arrow}</span>}
                    {subText}
                </p>
            )}
            {sparklineData && sparklineData.length > 0 && (
                <div className="mt-3 h-10">
                    <ResponsiveContainer width="100%" height="100%">
                        <AreaChart data={sparklineData}>
                            <defs>
                                <linearGradient id={`grad-${tone}`} x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="5%" stopColor={toneColor[tone]} stopOpacity={0.15} />
                                    <stop offset="95%" stopColor={toneColor[tone]} stopOpacity={0} />
                                </linearGradient>
                            </defs>
                            <Area
                                type="monotone"
                                dataKey="count"
                                stroke={toneColor[tone]}
                                strokeWidth={1.5}
                                fill={`url(#grad-${tone})`}
                                dot={false}
                                isAnimationActive={false}
                            />
                        </AreaChart>
                    </ResponsiveContainer>
                </div>
            )}
        </Wrapper>
    );
}
