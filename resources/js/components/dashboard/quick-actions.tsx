import { AlertCircle, BookOpen, FileText, Settings, Users } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

interface Action {
    label: string;
    icon: LucideIcon;
    tileBg: string;
    iconColor: string;
}

const actions: Action[] = [
    { label: 'Enter / approve results', icon: FileText, tileBg: 'bg-blue-50', iconColor: '#185FA5' },
    { label: 'Manage parents', icon: Users, tileBg: 'bg-green-50', iconColor: '#3B6D11' },
    { label: 'Teacher dashboard', icon: BookOpen, tileBg: 'bg-amber-50', iconColor: '#854F0B' },
    { label: 'View fee debtors', icon: AlertCircle, tileBg: 'bg-red-50', iconColor: '#A32D2D' },
    { label: 'School setup', icon: Settings, tileBg: 'bg-slate-100', iconColor: '#5F5E5A' },
];

export function QuickActions() {
    return (
        <div className="bg-white border border-slate-200 rounded-lg p-4">
            <p className="text-sm font-medium text-slate-900 mb-3">Quick actions</p>
            <div className="space-y-2">
                {actions.map((action) => (
                    <button
                        key={action.label}
                        className="w-full flex items-center gap-2.5 border border-slate-200 rounded-md p-2.5 hover:bg-slate-50 transition-colors text-left cursor-pointer"
                    >
                        <div className={`w-6 h-6 rounded-md flex items-center justify-center shrink-0 ${action.tileBg}`}>
                            <action.icon size={13} style={{ color: action.iconColor }} />
                        </div>
                        <span className="text-xs text-slate-700 flex-1">{action.label}</span>
                        <span className="text-slate-400 text-xs">↗</span>
                    </button>
                ))}
            </div>
        </div>
    );
}
