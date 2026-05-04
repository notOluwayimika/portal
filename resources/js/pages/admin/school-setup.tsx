import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { useState, useEffect } from 'react';
import { ClassStructureTab } from '@/components/setup/class-structure-tab';
import { OverviewTab } from '@/components/setup/overview-tab';
import { SessionsTab } from '@/components/setup/sessions-tab';
import { ToastItem } from '@/components/toast-item';
import type { ToastType } from '@/components/toast-item';
import type { Toast } from '@/components/toast-item';
import type { SetupData } from '@/types/models';
import ClassStreamTab from './class-stream-tab';
import { CurriculaTab } from './curricula-tab';
import { ExamTypesTab } from './exam-types-tab';
import { GradeBoundariesTab } from './grade-boundaries-tab';
import { SubjectsTab } from './subjects-tab';
interface TabConfig {
    id: string;
    label: string;
    icon: string;
    count: (() => number) | null;
}

// ─── CSS — strict light mode ────────────────────────────────────────────────
const css = `
  :root {
    --white:      #ffffff;
    --bg:         #f4f6f9;
    --surface:    #ffffff;
    --surface2:   #f9fafb;
    --border:     #e2e6ec;
    --border2:    #c8cdd8;
    --blue:       #2563eb;
    --blue-lt:    #eff4ff;
    --blue-mid:   #bfcffd;
    --blue-dk:    #1d4ed8;
    --blue-xdk:   #1e3a8a;
    --green:      #15803d;
    --green-lt:   #f0fdf4;
    --green-mid:  #86efac;
    --amber:      #b45309;
    --amber-lt:   #fffbeb;
    --red:        #b91c1c;
    --red-lt:     #fef2f2;
    --slate:      #475569;
    --slate-lt:   #f1f5f9;
    --text:       #0f172a;
    --text2:      #475569;
    --text3:      #94a3b8;
    --radius:     8px;
    --radius-lg:  12px;
    --shadow-xs:  0 1px 2px rgba(15,23,42,0.06);
    --shadow-sm:  0 1px 4px rgba(15,23,42,0.08);
    --shadow:     0 4px 16px rgba(15,23,42,0.10);
    --shadow-lg:  0 8px 32px rgba(15,23,42,0.14);
    --font:       'Figtree', sans-serif;
    --mono:       'JetBrains Mono', monospace;
  }

  html, body { height: 100%; background: var(--bg); color: var(--text); }
  body { font-family: var(--font); -webkit-font-smoothing: antialiased; }

  .shell { min-height: 100vh; display: flex; flex-direction: column; }

  .chrome {
    background: var(--white);
    border-bottom: 1px solid var(--border);
    box-shadow: var(--shadow-xs);
    position: sticky; top: 0; z-index: 50;
  }
  .chrome-inner {
    max-width: 1320px;
    margin: 0 auto;
    padding: 0 32px;
  }

  .identity-bar {
    display: flex; align-items: center; gap: 14px;
    padding: 13px 0 11px;
    border-bottom: 1px solid var(--border);
  }
  .school-avatar {
    width: 38px; height: 38px; border-radius: 10px;
    background: var(--blue); color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; font-weight: 700; letter-spacing: -0.5px;
    flex-shrink: 0;
  }
  .school-name  { font-size: 15px; font-weight: 700; color: var(--text); line-height: 1.2; }
  .school-sub   { font-size: 11.5px; color: var(--text3); margin-top: 1px; }
  .identity-bar-right { margin-left: auto; display: flex; align-items: center; gap: 10px; }

  .tab-row {
  display: flex;
  gap: 4px;
  padding: 8px 0;
  overflow-x: auto;
  scrollbar-width: none;
}
.tab-row::-webkit-scrollbar { display: none; }

.tab-btn {
  display: flex; align-items: center; gap: 6px;
  height: 32px; padding: 0 13px;
  border: none; border-radius: 7px;
  font-size: 13px; font-weight: 500;
  color: var(--text2); cursor: pointer;
  background: transparent;
  font-family: var(--font); white-space: nowrap;
  transition: all 0.14s;
}
.tab-btn:hover { color: var(--text); }
.tab-btn.active {
  background: var(--white);
  color: var(--text);
  box-shadow: 0 1px 3px rgba(0,0,0,.08), 0 0 0 0.5px var(--border2);
}
.tab-icon { font-size: 14px; }
.tab-count {
  font-size: 10.5px; font-weight: 600;
  background: var(--bg); border: none;
  color: var(--text3); border-radius: 99px;
  padding: 1px 6px; font-family: var(--mono);
}
.tab-btn.active .tab-count {
  background: var(--blue); color: #fff;
}

  .content-area {
    flex: 1;
    max-width: 1320px;
    margin: 0 auto; width: 100%;
    padding: 30px 32px 56px;
  }

  .page-hdr { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 22px; gap: 16px; }
  .page-hdr h1 { font-size: 20px; font-weight: 700; color: var(--text); }
  .page-hdr p  { font-size: 13px; color: var(--text3); margin-top: 3px; }
  .page-hdr-actions { display: flex; gap: 8px; align-items: center; flex-shrink: 0; }

  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
  }
  .card-hdr {
    display: flex; align-items: center; gap: 10px;
    padding: 13px 18px; border-bottom: 1px solid var(--border);
    background: var(--surface2);
  }
  .card-hdr-title { font-size: 13px; font-weight: 600; color: var(--text); }
  .card-hdr-sub   { font-size: 12px; color: var(--text3); margin-left: auto; }

  .btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 15px; border-radius: var(--radius);
    font-size: 13px; font-weight: 500;
    cursor: pointer; border: none; font-family: var(--font);
    transition: all 0.12s; white-space: nowrap; line-height: 1;
  }
  .btn-primary { background: var(--blue); color: #fff; box-shadow: 0 1px 3px rgba(37,99,235,0.25); }
  .btn-primary:hover { background: var(--blue-dk); }
  .btn-outline { background: var(--white); color: var(--text2); border: 1px solid var(--border2); box-shadow: var(--shadow-xs); }
  .btn-outline:hover { border-color: var(--blue); color: var(--blue); background: var(--blue-lt); }
  .btn-ghost   { background: transparent; color: var(--text2); }
  .btn-ghost:hover { background: var(--surface2); color: var(--text); }
  .btn-danger  { background: var(--red-lt); color: var(--red); border: 1px solid #fecaca; }
  .btn-danger:hover { background: #fee2e2; }
  .btn-sm   { padding: 5px 10px; font-size: 12px; border-radius: 6px; }
  .btn-icon { padding: 6px; border-radius: 6px; }
  .btn:disabled { opacity: 0.4; cursor: not-allowed; }

  .form-grid   { display: grid; gap: 14px; }
  .form-grid-2 { grid-template-columns: 1fr 1fr; }
  .form-grid-3 { grid-template-columns: 1fr 1fr 1fr; }
  .field       { display: flex; flex-direction: column; gap: 5px; }
  .field.span-2 { grid-column: span 2; }
  .field.span-3 { grid-column: span 3; }

  label { font-size: 12px; font-weight: 600; color: var(--text2); }

  input, select, textarea {
    background: var(--white); border: 1px solid var(--border2);
    border-radius: var(--radius); color: var(--text);
    font-family: var(--font); font-size: 13.5px; padding: 8px 11px;
    outline: none; transition: border-color 0.13s, box-shadow 0.13s;
    appearance: none; -webkit-appearance: none;
  }
  input::placeholder, textarea::placeholder { color: var(--text3); }
  input:focus, select:focus, textarea:focus {
    border-color: var(--blue);
    box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
  }
  select {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%2394a3b8'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 11px center;
    padding-right: 30px; cursor: pointer;
  }
  textarea { resize: vertical; min-height: 72px; }
  input[type="checkbox"] {
    width: 15px; height: 15px; accent-color: var(--blue);
    cursor: pointer; padding: 0; border-radius: 3px; box-shadow: none;
    appearance: auto;
  -webkit-appearance: checkbox;
  }

  .tbl-wrap { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; }
  thead { background: var(--surface2); }
  thead th {
    font-size: 11.5px; font-weight: 600; color: var(--text3);
    text-transform: uppercase; letter-spacing: 0.06em;
    padding: 10px 16px; text-align: left;
    border-bottom: 1px solid var(--border); white-space: nowrap;
  }
  tbody tr { border-bottom: 1px solid var(--border); transition: background 0.1s; }
  tbody tr:last-child { border-bottom: none; }
  tbody tr:hover { background: #f8faff; }
  td { padding: 11px 16px; font-size: 13.5px; color: var(--text); vertical-align: middle; }
  td.muted { color: var(--text2); font-size: 13px; }
  td.mono  { font-family: var(--mono); font-size: 12.5px; color: var(--text2); }
  .row-actions { display: flex; gap: 4px; align-items: center; }

  .pill {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 11.5px; font-weight: 600;
    padding: 3px 9px; border-radius: 20px; line-height: 1;
  }
  .pill-green { background: var(--green-lt); color: var(--green); border: 1px solid var(--green-mid); }
  .pill-amber { background: var(--amber-lt); color: var(--amber); border: 1px solid #fde68a; }
  .pill-blue  { background: var(--blue-lt);  color: var(--blue);  border: 1px solid var(--blue-mid); }
  .pill-slate { background: var(--slate-lt); color: var(--slate); border: 1px solid #cbd5e1; }
  .pill-red   { background: var(--red-lt);   color: var(--red);   border: 1px solid #fecaca; }

  .code-tag {
    font-family: var(--mono); font-size: 11.5px; font-weight: 500;
    background: var(--blue-lt); border: 1px solid var(--blue-mid);
    color: var(--blue-dk); padding: 2px 7px; border-radius: 5px;
  }

  .search-wrap { position: relative; }
  .search-wrap input { padding-left: 34px; }
  .search-icon {
    position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
    color: var(--text3); font-size: 14px; pointer-events: none;
  }

  .modal-backdrop {
    position: fixed; inset: 0; background: rgba(15,23,42,0.4);
    display: flex; align-items: center; justify-content: center;
    z-index: 100; padding: 20px; backdrop-filter: blur(3px);
    animation: fade-in 0.15s ease;
  }
  .modal {
    background: var(--white); border: 1px solid var(--border);
    border-radius: var(--radius-lg); width: 100%; max-width: 500px;
    box-shadow: var(--shadow-lg); animation: slide-up 0.18s ease;
  }
  .modal-lg  { max-width: 680px; }
  .modal-sm  { max-width: 400px; }
  .modal-hdr { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--border); }
  .modal-title { font-size: 14.5px; font-weight: 700; color: var(--text); }
  .modal-body { padding: 20px; display: flex; flex-direction: column; gap: 16px; }
  .modal-footer {
    display: flex; justify-content: flex-end; gap: 8px;
    padding: 14px 20px; border-top: 1px solid var(--border);
    background: var(--surface2); border-radius: 0 0 var(--radius-lg) var(--radius-lg);
  }

  .empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 52px 20px; gap: 8px; text-align: center; }
  .empty-icon  { font-size: 28px; opacity: 0.25; }
  .empty-title { font-size: 14px; font-weight: 600; color: var(--text2); }
  .empty-sub   { font-size: 13px; color: var(--text3); }

  .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 24px; }
  .stat-card  { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 16px; box-shadow: var(--shadow-xs); }
  .stat-val   { font-size: 28px; font-weight: 700; font-family: var(--mono); color: var(--text); line-height: 1; }
  .stat-lbl   { font-size: 12px; color: var(--text3); margin-top: 5px; font-weight: 500; }

  .grade-cols { display: grid; grid-template-columns: 100px 100px 80px 1fr 72px; gap: 8px; align-items: center; }
  .grade-col-hdr { font-size: 11px; font-weight: 600; color: var(--text3); text-transform: uppercase; letter-spacing: 0.06em; }

  .filter-row { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 16px; }
  .filter-btn {
    padding: 5px 13px; border-radius: 20px; font-size: 12.5px; font-weight: 500;
    cursor: pointer; border: 1px solid var(--border2); background: var(--white);
    color: var(--text2); font-family: var(--font); transition: all 0.12s;
  }
  .filter-btn:hover { border-color: var(--blue); color: var(--blue); }
  .filter-btn.on    { background: var(--blue); border-color: var(--blue); color: #fff; }

  .inline-edit { display: flex; align-items: center; gap: 6px; }
  .inline-edit input { padding: 5px 9px; font-size: 13px; border-radius: 6px; min-width: 160px; }

  .rel-row { display: flex; align-items: center; gap: 12px; padding: 9px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
  .rel-row:last-child { border-bottom: none; }
  .rel-left  { font-family: var(--mono); font-size: 12px; color: var(--blue-dk); min-width: 220px; }
  .rel-arrow { color: var(--text3); }
  .rel-right { color: var(--text2); font-size: 12.5px; }

  @keyframes fade-in  { from { opacity: 0; }                              to { opacity: 1; } }
  @keyframes slide-up { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
`;

// ─── Modal ─────────────────────────────────────────────────────────────────

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
        const h = (e: KeyboardEvent): void => {
            if (e.key === 'Escape') {
                onClose();
            }
        };
        window.addEventListener('keydown', h);

        return () => window.removeEventListener('keydown', h);
    }, [onClose]);

    return (
        <div
            className="modal-backdrop"
            onClick={(e) => {
                if (e.target === e.currentTarget) {
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

// ─── Confirm ───────────────────────────────────────────────────────────────

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

// ─── Empty ─────────────────────────────────────────────────────────────────

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

// ═══════════════════════════════════════════════════════════════════════════
// ROOT
// ═══════════════════════════════════════════════════════════════════════════

type TabId =
    | 'overview'
    | 'sessions'
    | 'structure'
    | 'stream'
    | 'exam-types'
    | 'subjects'
    | 'grades'
    | 'curricula';

const TABS: TabConfig[] = [
    { id: 'overview', label: 'Overview', icon: '⊞', count: null },
    {
        id: 'sessions',
        label: 'Sessions',
        icon: '📅',
        count: null,
    },
    { id: 'structure', label: 'Class Structure', icon: '🏫', count: null },
    { id: 'stream', label: 'Class Stream', icon: '📊', count: null },
    {
        id: 'exam-types',
        label: 'Exam Types',
        icon: '📝',
        count: null,
    },
    {
        id: 'subjects',
        label: 'Subjects',
        icon: '📚',
        count: null,
    },
    {
        id: 'grades',
        label: 'Grade Boundaries',
        icon: '📊',
        count: null,
    },
    {
        id: 'curricula',
        label: 'Curricula',
        icon: '📋',
        count: null,
    },
];

export default function SchoolSetup() {
    const [active, setActive] = useState<TabId>('curricula');
    const [toasts, setToasts] = useState<Toast[]>([]);
    const [data, setData] = useState<SetupData | null>(null);
    const { auth } = usePage().props;
    const toastCounter = useState(0)[0];
    let toastId = toastCounter;
    function addToast(message: string, type: ToastType = 'success') {
        const id = ++toastId;
        setToasts((t) => [...t, { id, message, type }]);
    }

    function dismissToast(id: number) {
        setToasts((t) => t.filter((x) => x.id !== id));
    }

    useEffect(() => {
        async function getSetupData() {
            const response = await axios.get('/api/setup-data');
            setData(response.data);
        }
        getSetupData();
    }, []);
    const [sessionName, setSessionName] = useState(
        auth.school.current_session?.name ?? '',
    );

    const render = () => {
        switch (active) {
            case 'overview':
                return <OverviewTab data={data} />;
            case 'sessions':
                return (
                    <SessionsTab
                        addToast={addToast}
                        setSessionName={setSessionName}
                    />
                );
            case 'structure':
                return <ClassStructureTab addToast={addToast} />;
            case 'stream':
                return <ClassStreamTab addToast={addToast} />;
            case 'exam-types':
                return <ExamTypesTab addToast={addToast} />;
            case 'subjects':
                return <SubjectsTab addToast={addToast} />;
            case 'grades':
                return <GradeBoundariesTab addToast={addToast} />;
            case 'curricula':
                return <CurriculaTab addToast={addToast} />;
            default:
                return null;
        }
    };

    const initials = auth.school.name
        .split(' ')
        .map((w) => w[0])
        .slice(0, 2)
        .join('');

    return (
        <>
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <style>{css}</style>
                <div className="shell">
                    <header className="chrome">
                        <div className="chrome-inner">
                            <div className="identity-bar">
                                <div className="school-avatar">{initials}</div>
                                <div>
                                    <div className="school-name">
                                        {auth.school.name}
                                    </div>
                                    <div className="school-sub">
                                        School Setup &amp; Configuration
                                    </div>
                                </div>
                                <div className="identity-bar-right">
                                    <span
                                        className="pill pill-green"
                                        style={{ fontSize: 12 }}
                                    >
                                        ● {sessionName ?? 'No active session'}
                                    </span>
                                </div>
                            </div>

                            <div className="tab-row">
                                {TABS.map((t) => {
                                    const count = t.count?.();

                                    return (
                                        <button
                                            key={t.id}
                                            className={`tab-btn ${active === t.id ? 'active' : ''}`}
                                            onClick={() =>
                                                setActive(t.id as TabId)
                                            }
                                        >
                                            <span className="tab-icon">
                                                {t.icon}
                                            </span>
                                            {t.label}
                                            {count != null && (
                                                <span className="tab-count">
                                                    {count}
                                                </span>
                                            )}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    </header>

                    <main className="content-area">{render()}</main>
                </div>

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
        </>
    );
}
