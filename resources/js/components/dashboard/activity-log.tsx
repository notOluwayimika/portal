const dotColors = ['bg-blue-500', 'bg-green-500', 'bg-red-500', 'bg-amber-500', 'bg-blue-500'];

const items = [
    { text: 'Mr. Obi entered scores for Yr 10A Maths', time: '2 min ago' },
    { text: 'HoS approved Secondary EoT results (Yr 9)', time: '18 min ago' },
    { text: 'Finance: 3 new debtors flagged, results locked', time: '1 hr ago' },
    { text: 'Admin impersonated Parent — Nneka Okafor', time: '2 hr ago' },
    { text: 'New student enrolled: Chukwu, Emeka (Yr 7)', time: 'Yesterday' },
];

export function ActivityLog() {
    return (
        <div className="bg-white border border-slate-200 rounded-lg p-4 dark:bg-slate-900 dark:border-slate-700">
            <div className="flex items-center justify-between mb-3">
                <p className="text-sm font-medium text-slate-900 dark:text-slate-100">Activity log</p>
                <a href="#" className="text-xs text-primary hover:underline">View all</a>
            </div>
            <div className="space-y-3">
                {items.map((item, i) => (
                    <div key={i} className="flex gap-2.5">
                        <div className={`w-2 h-2 rounded-full mt-1 shrink-0 ${dotColors[i]}`} />
                        <div>
                            <p className="text-xs text-slate-700 dark:text-slate-300 leading-snug">{item.text}</p>
                            <p className="text-[11px] text-slate-400 mt-0.5">{item.time}</p>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}
