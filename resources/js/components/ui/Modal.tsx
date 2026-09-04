import { useEffect, type ReactNode } from 'react'
import { XIcon } from 'lucide-react'

type ModalSize = 'sm' | 'md' | 'lg' | 'xl' | '3xl' | '4xl' | '5xl' | 'full' 

interface ModalProps {
    isOpen: boolean
    onClose: () => void
    title: string
    children: ReactNode
    footer?: ReactNode
    size?: ModalSize
}

const sizeClasses: Record<ModalSize, string> = {
    sm: 'max-w-sm',
    md: 'max-w-md',
    lg: 'max-w-2xl',
    xl: 'max-w-xl',
    '3xl': 'max-w-3xl',
    '4xl': 'max-w-4xl',
    '5xl': 'max-w-5xl',
    full: 'max-w-[95vw]',
}

export default function Modal({
    isOpen,
    onClose,
    title,
    children,
    footer,
    size = 'md',
}: ModalProps) {
    useEffect(() => {
        if (!isOpen) {
            return
        }

        const handleKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                onClose()
            }
        }

        window.addEventListener('keydown', handleKeyDown)

        return () => {
            window.removeEventListener('keydown', handleKeyDown)
        }
    }, [isOpen, onClose])

    if (!isOpen) {
        return null
    }

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4 py-6"
            role="dialog"
            aria-modal="true"
            onClick={(event) => {
                if (event.target === event.currentTarget) {
                    onClose()
                }
            }}
        >
            <div
                className={`w-full ${sizeClasses[size]} rounded-2xl bg-white dark:bg-card border border-slate-100 dark:border-slate-800 shadow-xl overflow-hidden`}
            >
                <div className="flex items-start justify-between border-b border-slate-100 dark:border-slate-800 px-6 py-4">
                    <div>
                        <h2 className="text-lg font-semibold text-slate-900 dark:text-white">{title}</h2>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg p-2 text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-700 dark:hover:text-slate-200"
                        aria-label="Close modal"
                    >
                        <XIcon className="h-5 w-5" />
                    </button>
                </div>
                <div className="max-h-[70vh] overflow-y-auto px-6 py-4">
                    {children}
                </div>
                {footer ? (
                    <div className="border-t border-slate-100 dark:border-slate-800 px-6 py-4">{footer}</div>
                ) : null}
            </div>
        </div>
    )
}
