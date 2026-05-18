import { type DataGap, type GapSeverity } from '@/types/dashboard';
import { AlertCircle, AlertTriangle, Info } from 'lucide-react';

const severityConfig: Record<GapSeverity, { icon: typeof Info; iconColor: string; badgeClass: string; label: string }> = {
    info: { icon: Info, iconColor: '#94a3b8', badgeClass: 'bg-slate-100 text-slate-600', label: 'Info' },
    warning: { icon: AlertTriangle, iconColor: '#d97706', badgeClass: 'bg-amber-50 text-amber-700', label: 'Warning' },
    critical: { icon: AlertCircle, iconColor: '#dc2626', badgeClass: 'bg-red-50 text-red-700', label: 'Action needed' },
};

const gapLabels: Record<string, string> = {
    students_without_guardian: 'Students with no guardian linked',
    students_without_enrollment: 'Students with no active enrollment',
    enrollments_without_subjects: 'Enrollments with no subjects assigned',
    teachers_without_assignment: 'Teachers with no subject assignment',
};

interface DataGapsPanelProps {
    gaps: DataGap[];
}

export function DataGapsPanel({ gaps }: DataGapsPanelProps) {
    if (gaps.length === 0) return null;

    return (
        <div className="bg-white border border-slate-200 rounded-lg p-4">
            <p className="text-sm font-medium text-slate-900 mb-3">Data health</p>
            <div className="space-y-2">
                {gaps.map((gap) => {
                    const config = severityConfig[gap.severity];
                    const Icon = config.icon;
                    const label = gapLabels[gap.type] ?? gap.type.replace(/_/g, ' ');
                    return (
                        <div
                            key={gap.type}
                            className="flex items-start gap-2.5 rounded-md border border-slate-100 p-2.5"
                        >
                            <Icon size={14} style={{ color: config.iconColor }} className="mt-0.5 shrink-0" />
                            <div className="flex-1 min-w-0">
                                <div className="flex items-center gap-2 flex-wrap">
                                    <span className="text-xs text-slate-700">{label}</span>
                                    <span
                                        className={`inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-medium ${config.badgeClass}`}
                                    >
                                        {gap.count.toLocaleString()}
                                    </span>
                                </div>
                            </div>
                            <a
                                href={gap.resolution_path}
                                className="text-[11px] text-[#185FA5] hover:underline shrink-0 mt-0.5"
                            >
                                Resolve
                            </a>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
