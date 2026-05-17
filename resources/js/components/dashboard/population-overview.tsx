const sections = [
    { name: 'Secondary', count: 524, pct: 42, color: '#185FA5' },
    { name: 'Primary', count: 480, pct: 39, color: '#639922' },
    { name: 'IFY Abuja', count: 142, pct: 11, color: '#1D9E75' },
    { name: 'IFY PH', count: 94, pct: 8, color: '#BA7517' },
];

export function PopulationOverview() {
    return (
        <div className="bg-white border border-slate-200 rounded-lg p-4">
            <p className="text-sm font-medium text-slate-900 mb-4">Population overview</p>
            <div className="space-y-3">
                {sections.map((s) => (
                    <div key={s.name}>
                        <div className="flex items-center justify-between mb-1">
                            <span className="text-xs text-slate-700">{s.name}</span>
                            <span className="text-xs text-slate-500">{s.count.toLocaleString()}</span>
                        </div>
                        <div className="h-1.5 bg-slate-100 rounded-full overflow-hidden">
                            <div
                                className="h-full rounded-full"
                                style={{ width: `${s.pct}%`, backgroundColor: s.color }}
                            />
                        </div>
                    </div>
                ))}
            </div>
            <div className="border-t border-slate-100 mt-4 pt-3 flex items-center justify-between">
                <span className="text-xs text-slate-500">Total enrolment</span>
                <span className="text-xs font-medium text-slate-900">1,240 students</span>
            </div>
        </div>
    );
}
