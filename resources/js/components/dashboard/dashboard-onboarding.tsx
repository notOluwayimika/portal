import { type OnboardingState } from '@/types/dashboard';
import { OnboardingProgressBar } from '@/components/dashboard/onboarding-progress-bar';
import { OnboardingStep } from '@/components/dashboard/onboarding-step';

interface DashboardOnboardingProps {
    onboarding: OnboardingState;
    schoolName: string | null;
}

export function DashboardOnboarding({ onboarding, schoolName }: DashboardOnboardingProps) {
    return (
        <div className="max-w-2xl mx-auto py-10 px-4">
            <div className="mb-8">
                <h1 className="text-xl font-semibold text-slate-900">
                    Welcome to {schoolName ? `${schoolName}'s` : 'your'} portal
                </h1>
                <p className="text-sm text-slate-500 mt-1">
                    Let's get your school set up. Complete these steps to activate your dashboard.
                </p>
            </div>

            <div className="space-y-3">
                {onboarding.steps.map((step, i) => (
                    <OnboardingStep
                        key={step.key}
                        stepNumber={i + 1}
                        totalSteps={onboarding.total_count}
                        title={step.title}
                        description={step.description}
                        isComplete={step.is_complete}
                        actionLabel={step.action_label}
                        actionHref={step.action_href}
                    />
                ))}
            </div>

            <OnboardingProgressBar
                completed={onboarding.completed_count}
                total={onboarding.total_count}
            />
        </div>
    );
}
