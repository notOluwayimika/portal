import { type ReactNode } from 'react'

interface EmptyStateProps {
    icon: ReactNode
    title: string
    description: string
    actionLabel?: string
    onAction?: () => void
}

export default function EmptyState({
    icon,
    title,
    description,
    actionLabel,
    onAction,
}: EmptyStateProps) {
    return (
        <div className="rounded-xl border border-gray-200 bg-white p-8 text-center shadow-sm dark:border-slate-700 dark:bg-slate-900">
            <div className="mx-auto flex h-20 w-20 items-center justify-center rounded-3xl bg-gray-100 text-gray-400 dark:bg-slate-800 dark:text-slate-500">
                {icon}
            </div>
            <div className="mt-6 space-y-3">
                <h3 className="text-lg font-semibold text-gray-900 dark:text-white">{title}</h3>
                <p className="text-sm text-gray-600 dark:text-slate-400">{description}</p>
                {actionLabel && onAction ? (
                    <button
                        type="button"
                        onClick={onAction}
                        className="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                    >
                        {actionLabel}
                    </button>
                ) : null}
            </div>
        </div>
    )
}
