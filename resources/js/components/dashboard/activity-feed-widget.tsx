import { type RecentActivity } from '@/types/dashboard';

const LOG_DOT_COLORS: Record<string, string> = {
    academics: 'bg-blue-500',
    finance: 'bg-green-500',
    admin: 'bg-red-500',
    guardians: 'bg-amber-500',
    default: 'bg-slate-400',
};

function timeAgo(dateStr: string): string {
    const diff = Date.now() - new Date(dateStr).getTime();
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return 'Just now';
    if (mins < 60) return `${mins} min ago`;
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return `${hrs} hr ago`;
    const days = Math.floor(hrs / 24);
    if (days === 1) return 'Yesterday';
    return `${days} days ago`;
}

interface ActivityFeedWidgetProps {
    activities: RecentActivity[];
    viewAllHref?: string;
}

export function ActivityFeedWidget({ activities, viewAllHref = '/activity-logs' }: ActivityFeedWidgetProps) {
    return (
        <div className="bg-white border border-slate-200 rounded-lg p-4">
            <div className="flex items-center justify-between mb-3">
                <p className="text-sm font-medium text-slate-900">Activity log</p>
                <a href={viewAllHref} className="text-xs text-[#185FA5] hover:underline">
                    View all
                </a>
            </div>
            {activities.length === 0 ? (
                <p className="text-xs text-slate-400">No recent activity</p>
            ) : (
                <div className="space-y-3">
                    {activities.map((item, i) => {
                        const dotColor = LOG_DOT_COLORS[item.log_name] ?? LOG_DOT_COLORS.default;
                        return (
                            <div key={i} className="flex gap-2.5">
                                <div className={`w-2 h-2 rounded-full mt-1 shrink-0 ${dotColor}`} />
                                <div>
                                    <p className="text-xs text-slate-700 leading-snug">{item.description}</p>
                                    <p className="text-[11px] text-slate-400 mt-0.5">{timeAgo(item.created_at)}</p>
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
