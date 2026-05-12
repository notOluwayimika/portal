import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import ScoreEntryPage from '@/components/score-entry-page';
import { ToastItem } from '@/components/toast-item';
import type { Toast, ToastType } from '@/components/toast-item';
import { handleBack } from '@/helpers';

export default function Show() {
    const { curriculumSubject } = usePage().props as unknown as {
        curriculumSubject: any;
    };
    const [toasts, setToasts] = useState<Toast[]>([]);
    const toastCounter = useState(0)[0];
    let toastId = toastCounter;
    function addToast(message: string, type: ToastType = 'success') {
        const id = ++toastId;
        setToasts((t) => [...t, { id, message, type }]);
    }

    function dismissToast(id: number) {
        setToasts((t) => t.filter((x) => x.id !== id));
    }

    return (
        <div className="space-y-6 p-10">
            <div></div>
            <button
                className="btn btn-ghost btn-sm btn-icon"
                onClick={handleBack}
                title="Back to curricula"
                style={{ fontSize: 14 }}
            >
                ← Go back
            </button>
            <ScoreEntryPage cs={curriculumSubject.data} addToast={addToast} />
            {/* Toasts */}
            <div
                style={{
                    position: 'fixed',
                    bottom: 24,
                    right: 24,
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 8,
                    zIndex: 100,
                    minWidth: 280,
                    maxWidth: 360,
                }}
            >
                {toasts.map((toast) => (
                    <ToastItem
                        key={toast.id}
                        toast={toast}
                        onDismiss={() => dismissToast(toast.id)}
                    />
                ))}
            </div>
        </div>
    );
}
