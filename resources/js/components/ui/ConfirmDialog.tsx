import { useState } from 'react'
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

    /*
     * THE RESET LIVES IN THE EVENT HANDLERS, NOT IN AN EFFECT — CAUSE 1 of the two that
     * `resources/js/pages/admin/internal-audit/review-queue.tsx` records for
     * `react-hooks/set-state-in-effect`, not a third pattern.
     *
     * This file carried `useEffect(() => { if (!isOpen) setInputValue('') }, [isOpen])`, which is
     * that docblock's first cause exactly: a setState that "runs SYNCHRONOUSLY in the effect body".
     * The same page states the remedy in as many words at its `refresh` handler — "An EVENT
     * HANDLER, not an effect — here the transition ... is real, and a synchronous setState is
     * correct."
     *
     * Closing and confirming ARE those events, and every path out of this dialog goes through one:
     * `Modal` calls `onClose` for the backdrop, the close icon and Escape, and Cancel calls it
     * directly. The reset now happens where the cause is, and no effect observes a prop to infer
     * what already happened.
     *
     * NO BEHAVIOUR CHANGES TODAY, stated rather than implied: `requiresTyping` and `expectedText`
     * are passed by NOBODY — measured across resources/js — so `canConfirm` is always true and
     * `inputValue` is never rendered.
     */
    const close = () => {
        setInputValue('')
        onClose()
    }

    const confirm = () => {
        setInputValue('')
        onConfirm()
    }

    const canConfirm = requiresTyping ? inputValue === expectedText : true

    return (
        <Modal
            isOpen={isOpen}
            onClose={close}
            title={title}
            size="sm"
            footer={
                <div className="flex items-center justify-end gap-3">
                    <button
                        type="button"
                        className="border border-gray-300 bg-white text-gray-600 rounded-lg px-4 py-2 text-sm hover:bg-gray-50"
                        onClick={close}
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        className={`rounded-lg px-4 py-2 text-sm ${dangerous ? 'bg-red-50 text-red-700 border border-red-200 hover:bg-red-100' : 'bg-[#185FA5] text-white hover:bg-[#0f4a82]'} ${!canConfirm ? 'opacity-50 cursor-not-allowed' : ''}`}
                        onClick={confirm}
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
