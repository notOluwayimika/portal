import { CheckCircle, Circle } from 'lucide-react';

interface OnboardingStepProps {
    stepNumber: number;
    totalSteps: number;
    title: string;
    description: string;
    isComplete: boolean;
    actionLabel: string;
    actionHref: string;
}

export function OnboardingStep({
    stepNumber,
    totalSteps,
    title,
    description,
    isComplete,
    actionLabel,
    actionHref,
}: OnboardingStepProps) {
    return (
        <div
            className={`border rounded-lg p-4 transition-colors ${
                isComplete ? 'border-green-100 bg-green-50/40' : 'border-slate-200 bg-white'
            }`}
            aria-label={isComplete ? `${title} — completed` : `${title} — pending`}
        >
            <div className="flex items-start gap-3">
                <div className="shrink-0 mt-0.5">
                    {isComplete ? (
                        <CheckCircle size={18} className="text-green-600" aria-hidden="true" />
                    ) : (
                        <Circle size={18} className="text-slate-300" aria-hidden="true" />
                    )}
                </div>
                <div className="flex-1">
                    <div className="flex items-center justify-between gap-3 flex-wrap">
                        <div>
                            <p className="text-[11px] text-slate-400 mb-0.5">
                                Step {stepNumber} of {totalSteps}
                            </p>
                            <p className={`text-sm font-medium ${isComplete ? 'text-slate-500 line-through decoration-slate-300' : 'text-slate-900'}`}>
                                {title}
                            </p>
                            <p className="text-xs text-slate-500 mt-0.5">{description}</p>
                        </div>
                        {!isComplete && (
                            <a
                                href={actionHref}
                                className="inline-flex items-center gap-1 text-xs font-medium text-[#185FA5] hover:underline shrink-0"
                            >
                                {actionLabel} →
                            </a>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
