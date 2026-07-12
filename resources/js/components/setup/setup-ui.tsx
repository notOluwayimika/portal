import { useEffect } from 'react';

interface ModalProps {
    title: string;
    onClose: () => void;
    footer?: React.ReactNode;
    children: React.ReactNode;
    large?: boolean;
    small?: boolean;
}

export function Modal({
    title,
    onClose,
    footer,
    children,
    large,
    small,
}: ModalProps) {
    useEffect(() => {
        const handleKeyDown = (event: KeyboardEvent): void => {
            if (event.key === 'Escape') {
                onClose();
            }
        };
        window.addEventListener('keydown', handleKeyDown);

        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [onClose]);

    return (
        <div
            className="modal-backdrop"
            onClick={(event) => {
                if (event.target === event.currentTarget) {
                    onClose();
                }
            }}
        >
            <div
                className={`modal ${large ? 'modal-lg' : ''}${small ? 'modal-sm' : ''}`}
            >
                <div className="modal-hdr">
                    <span className="modal-title">{title}</span>
                    <button
                        className="btn btn-ghost btn-sm btn-icon"
                        onClick={onClose}
                    >
                        ✕
                    </button>
                </div>
                <div className="modal-body">{children}</div>
                {footer && <div className="modal-footer">{footer}</div>}
            </div>
        </div>
    );
}

interface ConfirmProps {
    msg: string;
    onConfirm: () => void;
    onClose: () => void;
}

export function Confirm({ msg, onConfirm, onClose }: ConfirmProps) {
    return (
        <Modal
            small
            title="Confirm delete"
            onClose={onClose}
            footer={
                <>
                    <button className="btn btn-outline" onClick={onClose}>
                        Cancel
                    </button>
                    <button className="btn btn-danger" onClick={onConfirm}>
                        Delete
                    </button>
                </>
            }
        >
            <p
                style={{
                    fontSize: 13.5,
                    color: 'var(--text2)',
                    lineHeight: 1.65,
                }}
            >
                {msg}
            </p>
        </Modal>
    );
}

interface EmptyProps {
    icon: string;
    title: string;
    sub?: string;
}

export function Empty({ icon, title, sub }: EmptyProps) {
    return (
        <div className="empty-state">
            <div className="empty-icon">{icon}</div>
            <div className="empty-title">{title}</div>
            {sub && <div className="empty-sub">{sub}</div>}
        </div>
    );
}
