// ─── Toast ────────────────────────────────────────────────────────────────────

import { useEffect } from 'react';

export type ToastType = 'success' | 'error' | 'info';

export interface Toast {
    id: number;
    message: string;
    type: ToastType;
}

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

    const colors = {
        success: { bg: '#EAF3DE', border: '#C0DD97', text: '#3B6D11' },
        error: { bg: '#FCEBEB', border: '#F7C1C1', text: '#A32D2D' },
        info: { bg: '#EEEDFE', border: '#CECBF6', text: '#3C3489' },
    }[toast.type];

    return (
        <div
            style={{
                background: colors.bg,
                border: `1px solid ${colors.border}`,
                color: colors.text,
                borderRadius: 10,
                padding: '11px 16px',
                fontSize: 13,
                fontWeight: 500,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                gap: 12,
                boxShadow: '0 4px 16px rgba(0,0,0,0.08)',
                animation: 'slideIn 0.2s ease',
            }}
        >
            {toast.message}
            <button
                onClick={onDismiss}
                style={{
                    background: 'none',
                    border: 'none',
                    cursor: 'pointer',
                    color: 'inherit',
                    lineHeight: 1,
                    padding: 0,
                }}
            >
                ✕
            </button>
        </div>
    );
}
