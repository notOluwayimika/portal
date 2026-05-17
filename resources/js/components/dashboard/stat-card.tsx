type Tone = 'up' | 'down' | 'warning' | 'muted';

const toneClass: Record<Tone, string> = {
    up: 'text-green-600',
    down: 'text-red-600',
    warning: 'text-amber-600',
    muted: 'text-slate-400',
};

interface StatCardProps {
    label: string;
    value: string | number;
    subText: string;
    tone: Tone;
}

export function StatCard({ label, value, subText, tone }: StatCardProps) {
    return (
        <div className="bg-white border border-slate-200 rounded-lg p-4">
            <p className="text-xs text-slate-500">{label}</p>
            <p className="text-2xl font-medium text-slate-900 mt-1">{value}</p>
            <p className={`text-xs mt-1 ${toneClass[tone]}`}>{subText}</p>
        </div>
    );
}
