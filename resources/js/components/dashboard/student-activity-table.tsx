type StatusTone = 'green' | 'amber' | 'red' | 'blue';

const statusBadge: Record<StatusTone, string> = {
    green: 'bg-green-50 text-green-700',
    amber: 'bg-amber-50 text-amber-700',
    red: 'bg-red-50 text-red-700',
    blue: 'bg-blue-50 text-[#185FA5]',
};

const rows = [
    { name: 'Adeyemi, John', section: 'Secondary', klass: 'Year 10A', action: 'Result downloaded', status: 'Done', tone: 'green' as StatusTone },
    { name: 'Okafor, Amara', section: 'Primary', klass: 'Primary 5B', action: 'Fee payment', status: 'Paid', tone: 'green' as StatusTone },
    { name: 'Bello, Zara', section: 'IFY Abuja', klass: 'IFY Hybrid', action: 'Pre-Mock entry', status: 'Pending', tone: 'amber' as StatusTone },
    { name: 'Nwosu, David', section: 'Secondary', klass: 'Year 12A', action: 'Attendance flagged', status: 'Review', tone: 'red' as StatusTone },
    { name: 'Ibrahim, Fatima', section: 'Primary', klass: 'Nursery 2', action: 'Medical note added', status: 'Info', tone: 'blue' as StatusTone },
];

export function StudentActivityTable() {
    return (
        <div className="bg-white border border-slate-200 rounded-lg p-4 col-span-2">
            <div className="flex items-center justify-between mb-3">
                <p className="text-sm font-medium text-slate-900">Recent student activity</p>
                <a href="#" className="text-xs text-[#185FA5] hover:underline">See all</a>
            </div>
            <table className="w-full">
                <thead>
                    <tr className="border-b border-slate-100">
                        <th className="text-left text-[11px] font-medium text-slate-400 pb-2 pr-3">Student</th>
                        <th className="text-left text-[11px] font-medium text-slate-400 pb-2 pr-3">Section</th>
                        <th className="text-left text-[11px] font-medium text-slate-400 pb-2 pr-3">Class</th>
                        <th className="text-left text-[11px] font-medium text-slate-400 pb-2 pr-3">Action</th>
                        <th className="text-left text-[11px] font-medium text-slate-400 pb-2">Status</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, i) => (
                        <tr key={i} className="border-b border-slate-50 last:border-0">
                            <td className="py-2.5 text-xs text-slate-900 pr-3">{row.name}</td>
                            <td className="py-2.5 text-xs text-slate-600 pr-3">{row.section}</td>
                            <td className="py-2.5 text-xs text-slate-600 pr-3">{row.klass}</td>
                            <td className="py-2.5 text-xs text-slate-600 pr-3">{row.action}</td>
                            <td className="py-2.5">
                                <span className={`inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-medium ${statusBadge[row.tone]}`}>
                                    {row.status}
                                </span>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
