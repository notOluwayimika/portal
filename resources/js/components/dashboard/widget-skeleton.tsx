interface WidgetSkeletonProps {
    variant?: 'kpi' | 'chart' | 'list';
}

export function WidgetSkeleton({ variant = 'kpi' }: WidgetSkeletonProps) {
    if (variant === 'chart') {
        return (
            <div className="bg-white border border-slate-200 rounded-lg p-4 animate-pulse">
                <div className="h-3 bg-slate-100 rounded w-1/3 mb-4" />
                <div className="h-32 bg-slate-100 rounded" />
            </div>
        );
    }

    if (variant === 'list') {
        return (
            <div className="bg-white border border-slate-200 rounded-lg p-4 animate-pulse space-y-3">
                <div className="h-3 bg-slate-100 rounded w-1/3 mb-4" />
                {[1, 2, 3, 4].map((i) => (
                    <div key={i} className="h-3 bg-slate-100 rounded" />
                ))}
            </div>
        );
    }

    return (
        <div className="bg-white border border-slate-200 rounded-lg p-4 animate-pulse">
            <div className="h-2.5 bg-slate-100 rounded w-1/2 mb-3" />
            <div className="h-7 bg-slate-100 rounded w-2/3 mb-2" />
            <div className="h-2 bg-slate-100 rounded w-1/3" />
        </div>
    );
}
