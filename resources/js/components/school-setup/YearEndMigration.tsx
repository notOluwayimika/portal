import { useEffect, useMemo, useState } from 'react'
import { CheckCircle2, Download, ShieldCheck, Slash, ArrowRightCircle } from 'lucide-react'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import Modal from '@/components/ui/Modal'
import Toast from '@/components/ui/Toast'

interface MigrationStep {
    id: number
    title: string
    description: string
    completed: boolean
}

const initialSteps: MigrationStep[] = [
    {
        id: 1,
        title: 'Confirm session end',
        description: 'Verify that the 2025/2026 session closes on July 18, 2026',
        completed: false,
    },
    {
        id: 2,
        title: 'Review graduation list',
        description: 'Check Year 12 leavers and IFY completion records',
        completed: false,
    },
    {
        id: 3,
        title: 'Mark repeating students',
        description: 'Flag any students staying in their current year group',
        completed: false,
    },
    {
        id: 4,
        title: 'Export database backup',
        description: 'Download a full backup before migration runs',
        completed: false,
    },
    {
        id: 5,
        title: 'Run migration',
        description: 'Promote all eligible students to the next year group',
        completed: false,
    },
]

export default function YearEndMigration() {
    const [steps, setSteps] = useState<MigrationStep[]>(initialSteps)
    const [isConfirmOpen, setIsConfirmOpen] = useState(false)
    const [confirmationText, setConfirmationText] = useState('')
    const [isMigrating, setIsMigrating] = useState(false)
    const [toast, setToast] = useState<{ message: string; type: 'success' | 'error' | 'info' } | null>(null)

    const completedCount = useMemo(() => steps.filter((step) => step.completed).length, [steps])

    const canCheck = (index: number) => {
        if (index === 0) return true
        return steps[index - 1].completed
    }

    const handleToggleStep = (index: number) => {
        if (!canCheck(index)) return
        if (index === 3 && !steps[3].completed) {
            setToast({ message: 'Backup will be downloaded before continuing.', type: 'info' })
            window.setTimeout(() => {
                setToast({ message: 'Backup downloaded.', type: 'success' })
            }, 600)
        }
        setSteps((current) =>
            current.map((step, stepIndex) =>
                stepIndex === index ? { ...step, completed: !step.completed } : step
            )
        )
    }

    const canRunMigration = completedCount === steps.length

    const handleRunMigration = () => {
        setIsConfirmOpen(true)
    }

    const handleConfirmMigration = () => {
        setIsMigrating(true)
        setTimeout(() => {
            setIsMigrating(false)
            setIsConfirmOpen(false)
            setToast({ message: 'Migration complete. All students have been promoted.', type: 'success' })
            setSteps(initialSteps)
            setConfirmationText('')
        }, 1500)
    }

    useEffect(() => {
        if (!toast) return
        const timeout = window.setTimeout(() => setToast(null), 3000)
        return () => window.clearTimeout(timeout)
    }, [toast])

    return (
        <div className="space-y-8">
            <div className="rounded-2xl border border-gray-200 bg-white p-8 shadow-sm transition-shadow hover:shadow-md">
                <div className="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-xl font-semibold text-gray-900">Year-end migration</h1>
                        <p className="mt-2 text-sm text-gray-600">Complete the migration checklist before promoting students to the next year group.</p>
                    </div>
                    <div className="rounded-full border border-gray-200 bg-gray-50 px-5 py-3 text-sm text-gray-700">
                        {completedCount} of {steps.length} steps completed
                    </div>
                </div>
            </div>

            <div className="space-y-6">
                {steps.map((step, index) => {
                    const unlocked = canCheck(index)
                    return (
                        <div key={step.id} className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-shadow hover:shadow-md">
                            <div className="flex items-start gap-6">
                                <div className="flex h-12 w-12 items-center justify-center rounded-full bg-[#185FA5]/10 text-[#185FA5]">
                                    <span className="text-sm font-semibold">{step.id}</span>
                                </div>
                                <div className="flex-1">
                                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <h2 className="text-lg font-medium text-gray-900">{step.title}</h2>
                                            <p className="mt-1 text-sm text-gray-600">{step.description}</p>
                                        </div>
                                        <div className="flex items-center gap-4">
                                            {step.completed ? (
                                                <span className="inline-flex items-center gap-2 rounded-full bg-emerald-100 px-4 py-2 text-xs font-semibold text-emerald-700">
                                                    <CheckCircle2 className="h-4 w-4" />
                                                    Completed
                                                </span>
                                            ) : (
                                                <span className="rounded-full bg-gray-100 px-4 py-2 text-xs font-semibold text-gray-700">Pending</span>
                                            )}
                                            <button
                                                type="button"
                                                onClick={() => handleToggleStep(index)}
                                                disabled={!unlocked}
                                                className={`rounded-lg px-5 py-2.5 text-sm font-medium transition-all ${unlocked ? 'bg-[#185FA5] text-white shadow-sm hover:bg-[#0f4a82] hover:shadow-md' : 'border border-gray-200 bg-gray-100 text-gray-400 cursor-not-allowed'}`}
                                            >
                                                {step.completed ? 'Undo' : 'Mark done'}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )
                })}
            </div>

            <div className="flex justify-end">
                <button
                    type="button"
                    disabled={!canRunMigration || isMigrating}
                    onClick={handleRunMigration}
                    className={`inline-flex items-center gap-2 rounded-lg px-6 py-3 text-sm font-medium transition-all ${canRunMigration ? 'bg-red-600 text-white shadow-sm hover:bg-red-700 hover:shadow-md' : 'bg-red-100 text-red-300 cursor-not-allowed'} disabled:opacity-50 disabled:cursor-not-allowed`}
                >
                    <ArrowRightCircle className="h-5 w-5" />
                    {isMigrating ? 'Running Migration...' : 'Run Year-End Migration'}
                </button>
            </div>

            <ConfirmDialog
                isOpen={isConfirmOpen}
                onClose={() => setIsConfirmOpen(false)}
                onConfirm={handleConfirmMigration}
                title="Run Year-End Migration?"
                message="This will promote all students to the next year group. This cannot be undone. Type CONFIRM to proceed."
                confirmLabel={isMigrating ? 'Running...' : 'Confirm'}
                dangerous
                requiresTyping
                expectedText="CONFIRM"
            />

            {toast ? <Toast message={toast.message} type={toast.type} onDismiss={() => setToast(null)} /> : null}
        </div>
    )
}
