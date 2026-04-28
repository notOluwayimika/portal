import { useEffect, useState, type ReactNode } from 'react'
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
        if (!isOpen) {
            setInputValue('')
        }
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
                        className="border border-gray-300 bg-white text-gray-600 rounded-lg px-4 py-2 text-sm hover:bg-gray-50"
                        onClick={onClose}
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        className={`rounded-lg px-4 py-2 text-sm ${dangerous ? 'bg-red-50 text-red-700 border border-red-200 hover:bg-red-100' : 'bg-[#185FA5] text-white hover:bg-[#0f4a82]'} ${!canConfirm ? 'opacity-50 cursor-not-allowed' : ''}`}
                        onClick={onConfirm}
                        disabled={!canConfirm}
                    >
                        {confirmLabel}
                    </button>
                </div>
            }
        >
            <div className="space-y-4">
                <p className="text-sm text-gray-600">{message}</p>
                {requiresTyping ? (
                    <div className="space-y-2">
                        <label className="block text-sm font-medium text-gray-700">Type <span className="font-semibold">{expectedText}</span> to confirm</label>
                        <input
                            type="text"
                            value={inputValue}
                            onChange={(event) => setInputValue(event.target.value)}
                            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#185FA5] focus:border-transparent"
                            placeholder={expectedText}
                        />
                    </div>
                ) : null}
            </div>
        </Modal>
    )
}
