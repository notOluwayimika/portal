import { useEffect } from 'react'

type ToastType = 'success' | 'error' | 'info'

interface ToastProps {
    message: string
    type?: ToastType
    onDismiss: () => void
}

const borderStyles: Record<ToastType, string> = {
    success: 'border-green-500',
    error: 'border-red-500',
    info: 'border-blue-500',
}

const bgStyles: Record<ToastType, string> = {
    success: 'bg-white',
    error: 'bg-white',
    info: 'bg-white',
}

const textStyles: Record<ToastType, string> = {
    success: 'text-green-700',
    error: 'text-red-700',
    info: 'text-blue-700',
}

export default function Toast({
    message,
    type = 'info',
    onDismiss,
}: ToastProps) {
    useEffect(() => {
        const timeout = window.setTimeout(() => {
            onDismiss()
        }, 3000)

        return () => window.clearTimeout(timeout)
    }, [onDismiss])

    return (
        <div className="fixed bottom-4 right-4 z-50">
            <div
                className={`w-80 rounded-xl border-l-4 shadow-lg ${borderStyles[type]} ${bgStyles[type]} text-sm`}
            >
                <div className="p-4">
                    <p className={`font-medium ${textStyles[type]}`}>{message}</p>
                </div>
            </div>
        </div>
    )
}
