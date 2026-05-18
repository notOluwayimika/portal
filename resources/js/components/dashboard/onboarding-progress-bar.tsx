interface OnboardingProgressBarProps {
    completed: number;
    total: number;
}

export function OnboardingProgressBar({ completed, total }: OnboardingProgressBarProps) {
    const pct = total > 0 ? Math.round((completed / total) * 100) : 0;

    return (
        <div className="mt-6">
            <div className="flex items-center justify-between mb-2">
                <span className="text-xs text-slate-500">
                    {completed} of {total} steps completed
                </span>
                <span className="text-xs font-medium text-slate-700">{pct}%</span>
            </div>
            <div className="h-1.5 bg-slate-100 rounded-full overflow-hidden">
                <div
                    className="h-full rounded-full bg-[#185FA5] transition-all duration-500"
                    style={{ width: `${pct}%` }}
                />
            </div>
        </div>
    );
}
