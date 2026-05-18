import { useEffect } from 'react';

export type ToastType = 'success' | 'error' | 'info';

export interface Toast {
    id: number;
    message: string;
    type: ToastType;
}

const typeClasses: Record<ToastType, string> = {
    success: 'bg-[#EAF3DE] border border-[#C0DD97] text-[#3B6D11] dark:bg-green-950/60 dark:border-green-800 dark:text-green-300',
    error:   'bg-[#FCEBEB] border border-[#F7C1C1] text-[#A32D2D] dark:bg-red-950/60 dark:border-red-800 dark:text-red-300',
    info:    'bg-[#EEEDFE] border border-[#CECBF6] text-[#3C3489] dark:bg-primary/10 dark:border-primary/30 dark:text-primary',
};

export function ToastItem({
    toast,
    onDismiss,
}: {
    toast: Toast;
    onDismiss: () => void;
}) {
    useEffect(() => {
        const t = setTimeout(onDismiss, 3200);
        return () => clearTimeout(t);
    }, []);

    return (
        <div
            className={`flex items-center justify-between gap-3 rounded-[10px] px-4 py-[11px] text-[13px] font-medium shadow-[0_4px_16px_rgba(0,0,0,0.08)] ${typeClasses[toast.type]}`}
            style={{ animation: 'slideIn 0.2s ease' }}
        >
            {toast.message}
            <button
                onClick={onDismiss}
                className="shrink-0 leading-none opacity-70 hover:opacity-100"
                aria-label="Dismiss"
            >
                ✕
            </button>
        </div>
    );
}
