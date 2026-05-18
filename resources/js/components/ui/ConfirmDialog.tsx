import { useEffect, useState } from 'react'
import Modal from './Modal'

interface ConfirmDialogProps {
    isOpen: boolean
    onClose: () => void
    onConfirm: () => void
    title: string
    message: string
    confirmLabel?: string
    dangerous?: boolean
    requiresTyping?: boolean
    expectedText?: string
}

export default function ConfirmDialog({
    isOpen,
    onClose,
    onConfirm,
    title,
    message,
    confirmLabel = 'Confirm',
    dangerous = false,
    requiresTyping = false,
    expectedText = 'CONFIRM',
}: ConfirmDialogProps) {
    const [inputValue, setInputValue] = useState('')

    useEffect(() => {
        if (!isOpen) setInputValue('')
    }, [isOpen])

    const canConfirm = requiresTyping ? inputValue === expectedText : true

    return (
        <Modal
            isOpen={isOpen}
            onClose={onClose}
            title={title}
            size="sm"
            footer={
                <div className="flex items-center justify-end gap-3">
                    <button
                        type="button"
                        className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm text-gray-600 hover:bg-gray-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                        onClick={onClose}
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        className={`rounded-lg px-4 py-2 text-sm ${dangerous ? 'bg-red-50 text-red-700 border border-red-200 hover:bg-red-100 dark:bg-red-950/40 dark:text-red-400 dark:border-red-800 dark:hover:bg-red-950/60' : 'bg-primary text-primary-foreground hover:bg-primary/90'} ${!canConfirm ? 'opacity-50 cursor-not-allowed' : ''}`}
                        onClick={onConfirm}
                        disabled={!canConfirm}
                    >
                        {confirmLabel}
                    </button>
                </div>
            }
        >
            <div className="space-y-4">
                <p className="text-sm text-gray-600 dark:text-slate-400">{message}</p>
                {requiresTyping ? (
                    <div className="space-y-2">
                        <label className="block text-sm font-medium text-gray-700 dark:text-slate-300">
                            Type <span className="font-semibold">{expectedText}</span> to confirm
                        </label>
                        <input
                            type="text"
                            value={inputValue}
                            onChange={(event) => setInputValue(event.target.value)}
                            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent dark:border-slate-600 dark:bg-slate-800 dark:text-white dark:placeholder-slate-500"
                            placeholder={expectedText}
                        />
                    </div>
                ) : null}
            </div>
        </Modal>
    )
}
