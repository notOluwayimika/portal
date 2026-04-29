import axios from 'axios';
import { useState, useEffect } from 'react';
import { OverviewTab } from '@/components/setup/overview-tab';
import { SessionsTab } from '@/components/setup/sessions-tab';
import { ToastItem } from '@/components/toast-item';
import type { ToastType } from '@/components/toast-item';
import type { Toast } from '@/components/toast-item';
import type { School, SetupData } from '@/types/models';

interface Session {
    id: string;
    name: string;
    is_current: boolean;
}

interface ClassLevel {
    id: string;
    name: string;
    order: number;
}

interface Arm {
    id: string;
    label: string;
}

interface ClassLevelArm {
    id: string;
    class_level_id: string;
    arm_id: string;
}

interface ExamType {
    id: string;
    name: string;
}

interface Subject {
    id: string;
    name: string;
    code: string;
}

interface GradeBoundary {
    id: string;
    exam_type_id: string | null;
    min_score: number;
    max_score: number;
    grade: string;
    label: string;
}

interface Student {
    id: string;
    first_name: string;
    last_name: string;
    admission_number: string;
}

interface Curriculum {
    id: string;
    session_id: string;
    class_level_id: string;
    exam_type_id: string;
    term: number;
    min_subjects: number;
    registration_deadline: string;
    result_visible_at: string;
    status: 'draft' | 'active' | 'closed';
}

interface SeedData {
    school: School;
    sessions: Session[];
    classLevels: ClassLevel[];
    arms: Arm[];
    classLevelArms: ClassLevelArm[];
    examTypes: ExamType[];
    subjects: Subject[];
    gradeBoundaries: GradeBoundary[];
    students: Student[];
    curricula: Curriculum[];
}

interface TabConfig {
    id: string;
    label: string;
    icon: string;
    count: (() => number) | null;
}

// ─── Seed data ─────────────────────────────────────────────────────────────
let _id = 200;
const uid = (): string => `id-${++_id}`;
const delay = (ms = 300): Promise<void> =>
    new Promise((r) => setTimeout(r, ms));

const seed: SeedData = {
    school: {
        id: 'sch-001',
        name: 'Greenfield Academy',
        slug: 'greenfield-academy',
    },
    sessions: [
        { id: 'ses-001', name: '2023/2024', is_current: false },
        { id: 'ses-002', name: '2024/2025', is_current: false },
        { id: 'ses-003', name: '2025/2026', is_current: true },
    ],
    classLevels: [
        { id: 'cl-1', name: 'JS1', order: 1 },
        { id: 'cl-2', name: 'JS2', order: 2 },
        { id: 'cl-3', name: 'JS3', order: 3 },
        { id: 'cl-4', name: 'SS1', order: 4 },
        { id: 'cl-5', name: 'SS2', order: 5 },
        { id: 'cl-6', name: 'SS3', order: 6 },
    ],
    arms: [
        { id: 'arm-1', label: 'A' },
        { id: 'arm-2', label: 'B' },
        { id: 'arm-3', label: 'C' },
    ],
    classLevelArms: [
        { id: 'cla-1', class_level_id: 'cl-1', arm_id: 'arm-1' },
        { id: 'cla-2', class_level_id: 'cl-1', arm_id: 'arm-2' },
        { id: 'cla-3', class_level_id: 'cl-2', arm_id: 'arm-1' },
        { id: 'cla-4', class_level_id: 'cl-2', arm_id: 'arm-2' },
        { id: 'cla-5', class_level_id: 'cl-5', arm_id: 'arm-1' },
        { id: 'cla-6', class_level_id: 'cl-5', arm_id: 'arm-2' },
        { id: 'cla-7', class_level_id: 'cl-5', arm_id: 'arm-3' },
    ],
    examTypes: [
        { id: 'et-1', name: 'First Term Exam' },
        { id: 'et-2', name: 'Second Term Exam' },
        { id: 'et-3', name: 'Third Term Exam' },
        { id: 'et-4', name: 'WAEC Mock' },
    ],
    subjects: [
        { id: 'sub-1', name: 'English Language', code: 'ENG' },
        { id: 'sub-2', name: 'Mathematics', code: 'MTH' },
        { id: 'sub-3', name: 'Biology', code: 'BIO' },
        { id: 'sub-4', name: 'Chemistry', code: 'CHM' },
        { id: 'sub-5', name: 'Physics', code: 'PHY' },
        { id: 'sub-6', name: 'Economics', code: 'ECO' },
        { id: 'sub-7', name: 'Government', code: 'GOV' },
        { id: 'sub-8', name: 'Computer Science', code: 'CSC' },
    ],
    gradeBoundaries: [
        {
            id: 'gb-1',
            exam_type_id: null,
            min_score: 70,
            max_score: 101,
            grade: 'A',
            label: 'Distinction',
        },
        {
            id: 'gb-2',
            exam_type_id: null,
            min_score: 60,
            max_score: 70,
            grade: 'B',
            label: 'Credit',
        },
        {
            id: 'gb-3',
            exam_type_id: null,
            min_score: 50,
            max_score: 60,
            grade: 'C',
            label: 'Merit',
        },
        {
            id: 'gb-4',
            exam_type_id: null,
            min_score: 45,
            max_score: 50,
            grade: 'D',
            label: 'Pass',
        },
        {
            id: 'gb-5',
            exam_type_id: null,
            min_score: 40,
            max_score: 45,
            grade: 'E',
            label: 'Below Average',
        },
        {
            id: 'gb-6',
            exam_type_id: null,
            min_score: 0,
            max_score: 40,
            grade: 'F',
            label: 'Fail',
        },
    ],
    students: [
        {
            id: 'st-1',
            first_name: 'Chukwuemeka',
            last_name: 'Obi',
            admission_number: 'GFA/2025/001',
        },
        {
            id: 'st-2',
            first_name: 'Amina',
            last_name: 'Suleiman',
            admission_number: 'GFA/2025/002',
        },
        {
            id: 'st-3',
            first_name: 'Tunde',
            last_name: 'Bakare',
            admission_number: 'GFA/2025/003',
        },
        {
            id: 'st-4',
            first_name: 'Ifunanya',
            last_name: 'Nwachukwu',
            admission_number: 'GFA/2025/004',
        },
        {
            id: 'st-5',
            first_name: 'Seun',
            last_name: 'Afolabi',
            admission_number: 'GFA/2025/005',
        },
        {
            id: 'st-6',
            first_name: 'Blessing',
            last_name: 'Eze',
            admission_number: 'GFA/2025/006',
        },
        {
            id: 'st-7',
            first_name: 'Yusuf',
            last_name: 'Abdullahi',
            admission_number: 'GFA/2025/007',
        },
        {
            id: 'st-8',
            first_name: 'Chisom',
            last_name: 'Okafor',
            admission_number: 'GFA/2025/008',
        },
        {
            id: 'st-9',
            first_name: 'Adaeze',
            last_name: 'Igwe',
            admission_number: 'GFA/2025/009',
        },
        {
            id: 'st-10',
            first_name: 'Oluwaseun',
            last_name: 'Adewale',
            admission_number: 'GFA/2025/010',
        },
    ],
    curricula: [
        {
            id: 'cur-1',
            session_id: 'ses-003',
            class_level_id: 'cl-5',
            exam_type_id: 'et-1',
            term: 1,
            min_subjects: 8,
            registration_deadline: '2025-09-30T23:59',
            result_visible_at: '2025-12-15T00:00',
            status: 'active',
        },
        {
            id: 'cur-2',
            session_id: 'ses-003',
            class_level_id: 'cl-1',
            exam_type_id: 'et-1',
            term: 1,
            min_subjects: 6,
            registration_deadline: '2025-09-30T23:59',
            result_visible_at: '2025-12-15T00:00',
            status: 'active',
        },
    ],
};

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

  .tab-row { display: flex; gap: 0; overflow-x: auto; scrollbar-width: none; }
  .tab-row::-webkit-scrollbar { display: none; }

  .tab-btn {
    display: flex; align-items: center; gap: 7px;
    height: 46px; padding: 0 18px;
    font-size: 13.5px; font-weight: 500; color: var(--text2);
    cursor: pointer; border: none; background: transparent;
    font-family: var(--font); white-space: nowrap;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    transition: color 0.13s;
    position: relative;
  }
  .tab-btn:hover { color: var(--text); }
  .tab-btn.active {
    color: var(--blue);
    font-weight: 600;
    border-bottom-color: var(--blue);
  }
  .tab-icon { font-size: 14px; }
  .tab-count {
    font-size: 10.5px; font-weight: 600;
    background: var(--bg); border: 1px solid var(--border);
    color: var(--text3); border-radius: 20px;
    padding: 1px 6px; font-family: var(--mono);
  }
  .tab-btn.active .tab-count {
    background: var(--blue-lt); border-color: var(--blue-mid); color: var(--blue-dk);
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

// ─── StatusPill ────────────────────────────────────────────────────────────

interface StatusPillProps {
    status: Curriculum['status'];
}

function StatusPill({ status }: StatusPillProps) {
    const map: Record<Curriculum['status'], [string, string]> = {
        active: ['pill-green', 'Active'],
        draft: ['pill-amber', 'Draft'],
        closed: ['pill-slate', 'Closed'],
    };
    const [cls, lbl] = map[status] ?? ['pill-slate', status];

    return <span className={`pill ${cls}`}>{lbl}</span>;
}

// ═══════════════════════════════════════════════════════════════════════════
// SESSIONS TAB
// ═══════════════════════════════════════════════════════════════════════════

// ═══════════════════════════════════════════════════════════════════════════
// CLASS STRUCTURE TAB
// ═══════════════════════════════════════════════════════════════════════════

interface ClassLevelForm {
    name: string;
    order: string | number;
}

interface ArmForm {
    label: string;
}

interface ConfirmTarget<T> {
    type: string;
    item: T;
}

function ClassStructureTab() {
    const [levels, setLevels] = useState<ClassLevel[]>([...seed.classLevels]);
    const [arms, setArms] = useState<Arm[]>([...seed.arms]);
    const [links, setLinks] = useState<ClassLevelArm[]>([
        ...seed.classLevelArms,
    ]);
    const [lvlModal, setLvlModal] = useState<string | null>(null);
    const [armModal, setArmModal] = useState<string | null>(null);
    const [lvlForm, setLvlForm] = useState<ClassLevelForm>({
        name: '',
        order: '',
    });
    const [armForm, setArmForm] = useState<ArmForm>({ label: '' });
    const [confirm, setConfirm] = useState<ConfirmTarget<
        ClassLevel | Arm
    > | null>(null);

    const saveLvl = async (): Promise<void> => {
        if (!lvlForm.name.trim()) {
            return;
        }

        await delay();

        if (lvlModal === 'new') {
            setLevels((p) => [
                ...p,
                {
                    id: uid(),
                    name: lvlForm.name.trim(),
                    order: +lvlForm.order || p.length + 1,
                },
            ]);
        } else {
            setLevels((p) =>
                p.map((l) =>
                    l.id === lvlModal
                        ? {
                              ...l,
                              name: lvlForm.name.trim(),
                              order: +lvlForm.order || l.order,
                          }
                        : l,
                ),
            );
        }

        setLvlModal(null);
    };

    const saveArm = async (): Promise<void> => {
        if (!armForm.label.trim()) {
            return;
        }

        await delay();

        if (armModal === 'new') {
            setArms((p) => [...p, { id: uid(), label: armForm.label.trim() }]);
        } else {
            setArms((p) =>
                p.map((a) =>
                    a.id === armModal
                        ? { ...a, label: armForm.label.trim() }
                        : a,
                ),
            );
        }

        setArmModal(null);
    };

    const toggle = (lid: string, aid: string): void => {
        const has = links.some(
            (l) => l.class_level_id === lid && l.arm_id === aid,
        );
        setLinks((p) =>
            has
                ? p.filter(
                      (l) => !(l.class_level_id === lid && l.arm_id === aid),
                  )
                : [...p, { id: uid(), class_level_id: lid, arm_id: aid }],
        );
    };

    const sorted = [...levels].sort((a, b) => a.order - b.order);

    return (
        <>
            <div className="page-hdr">
                <div>
                    <h1>Class Structure</h1>
                    <p>Class levels and their assigned arms</p>
                </div>
                <div className="page-hdr-actions">
                    <button
                        className="btn btn-outline"
                        onClick={() => {
                            setArmForm({ label: '' });
                            setArmModal('new');
                        }}
                    >
                        + New Arm
                    </button>
                    <button
                        className="btn btn-primary"
                        onClick={() => {
                            setLvlForm({ name: '', order: '' });
                            setLvlModal('new');
                        }}
                    >
                        + New Level
                    </button>
                </div>
            </div>

            <div
                style={{
                    display: 'grid',
                    gridTemplateColumns: '1fr 220px',
                    gap: 16,
                    alignItems: 'start',
                }}
            >
                <div className="card">
                    <div className="card-hdr">
                        <span className="card-hdr-title">Class Levels</span>
                        <span className="card-hdr-sub">
                            Tick to assign arms
                        </span>
                    </div>
                    <div className="tbl-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Level</th>
                                    <th style={{ textAlign: 'center' }}>
                                        Order
                                    </th>
                                    {arms.map((a) => (
                                        <th
                                            key={a.id}
                                            style={{ textAlign: 'center' }}
                                        >
                                            Arm {a.label}
                                        </th>
                                    ))}
                                    <th style={{ textAlign: 'right' }}>
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {sorted.length === 0 && (
                                    <tr>
                                        <td colSpan={3 + arms.length}>
                                            <Empty
                                                icon="🏫"
                                                title="No class levels"
                                            />
                                        </td>
                                    </tr>
                                )}
                                {sorted.map((l) => (
                                    <tr key={l.id}>
                                        <td>
                                            <span
                                                style={{
                                                    fontFamily: 'var(--mono)',
                                                    fontWeight: 700,
                                                }}
                                            >
                                                {l.name}
                                            </span>
                                        </td>
                                        <td
                                            style={{ textAlign: 'center' }}
                                            className="muted"
                                        >
                                            {l.order}
                                        </td>
                                        {arms.map((a) => (
                                            <td
                                                key={a.id}
                                                style={{ textAlign: 'center' }}
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={links.some(
                                                        (lk) =>
                                                            lk.class_level_id ===
                                                                l.id &&
                                                            lk.arm_id === a.id,
                                                    )}
                                                    onChange={() =>
                                                        toggle(l.id, a.id)
                                                    }
                                                />
                                            </td>
                                        ))}
                                        <td>
                                            <div
                                                className="row-actions"
                                                style={{
                                                    justifyContent: 'flex-end',
                                                }}
                                            >
                                                <button
                                                    className="btn btn-ghost btn-sm btn-icon"
                                                    onClick={() => {
                                                        setLvlForm({
                                                            name: l.name,
                                                            order: l.order,
                                                        });
                                                        setLvlModal(l.id);
                                                    }}
                                                >
                                                    ✏️
                                                </button>
                                                <button
                                                    className="btn btn-danger btn-sm btn-icon"
                                                    onClick={() =>
                                                        setConfirm({
                                                            type: 'level',
                                                            item: l,
                                                        })
                                                    }
                                                >
                                                    🗑
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                <div className="card">
                    <div className="card-hdr">
                        <span className="card-hdr-title">Arms</span>
                    </div>
                    <div>
                        {arms.length === 0 && (
                            <Empty icon="🔤" title="No arms yet" />
                        )}
                        {arms.map((a) => (
                            <div
                                key={a.id}
                                style={{
                                    display: 'flex',
                                    alignItems: 'center',
                                    padding: '10px 16px',
                                    borderBottom: '1px solid var(--border)',
                                }}
                            >
                                <span
                                    style={{
                                        fontFamily: 'var(--mono)',
                                        fontWeight: 700,
                                        fontSize: 16,
                                        color: 'var(--blue-dk)',
                                        flex: 1,
                                    }}
                                >
                                    {a.label}
                                </span>
                                <div className="row-actions">
                                    <button
                                        className="btn btn-ghost btn-sm btn-icon"
                                        onClick={() => {
                                            setArmForm({ label: a.label });
                                            setArmModal(a.id);
                                        }}
                                    >
                                        ✏️
                                    </button>
                                    <button
                                        className="btn btn-danger btn-sm btn-icon"
                                        onClick={() =>
                                            setConfirm({ type: 'arm', item: a })
                                        }
                                    >
                                        🗑
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            {lvlModal && (
                <Modal
                    title={
                        lvlModal === 'new'
                            ? 'New Class Level'
                            : 'Edit Class Level'
                    }
                    onClose={() => setLvlModal(null)}
                    footer={
                        <>
                            <button
                                className="btn btn-outline"
                                onClick={() => setLvlModal(null)}
                            >
                                Cancel
                            </button>
                            <button
                                className="btn btn-primary"
                                onClick={saveLvl}
                            >
                                Save
                            </button>
                        </>
                    }
                >
                    <div className="form-grid form-grid-2">
                        <div className="field">
                            <label>Level name</label>
                            <input
                                placeholder="e.g. JS1"
                                value={lvlForm.name}
                                onChange={(e) =>
                                    setLvlForm((p) => ({
                                        ...p,
                                        name: e.target.value,
                                    }))
                                }
                                autoFocus
                            />
                        </div>
                        <div className="field">
                            <label>Display order</label>
                            <input
                                type="number"
                                min="1"
                                value={lvlForm.order}
                                onChange={(e) =>
                                    setLvlForm((p) => ({
                                        ...p,
                                        order: e.target.value,
                                    }))
                                }
                            />
                        </div>
                    </div>
                </Modal>
            )}

            {armModal && (
                <Modal
                    title={armModal === 'new' ? 'New Arm' : 'Edit Arm'}
                    onClose={() => setArmModal(null)}
                    footer={
                        <>
                            <button
                                className="btn btn-outline"
                                onClick={() => setArmModal(null)}
                            >
                                Cancel
                            </button>
                            <button
                                className="btn btn-primary"
                                onClick={saveArm}
                            >
                                Save
                            </button>
                        </>
                    }
                >
                    <div className="field">
                        <label>Arm label</label>
                        <input
                            placeholder="e.g. D"
                            value={armForm.label}
                            onChange={(e) =>
                                setArmForm({ label: e.target.value })
                            }
                            autoFocus
                        />
                    </div>
                </Modal>
            )}

            {confirm && (
                <Confirm
                    msg={`Delete ${
                        confirm.type === 'level'
                            ? `class level "${(confirm.item as ClassLevel).name}"`
                            : `arm "${(confirm.item as Arm).label}"`
                    }?`}
                    onConfirm={() => {
                        if (confirm.type === 'level') {
                            const id = (confirm.item as ClassLevel).id;
                            setLevels((p) => p.filter((l) => l.id !== id));
                            setLinks((p) =>
                                p.filter((l) => l.class_level_id !== id),
                            );
                        } else {
                            const id = (confirm.item as Arm).id;
                            setArms((p) => p.filter((a) => a.id !== id));
                            setLinks((p) => p.filter((l) => l.arm_id !== id));
                        }

                        setConfirm(null);
                    }}
                    onClose={() => setConfirm(null)}
                />
            )}
        </>
    );
}

// ═══════════════════════════════════════════════════════════════════════════
// EXAM TYPES TAB
// ═══════════════════════════════════════════════════════════════════════════

interface ExamTypeForm {
    name: string;
}

function ExamTypesTab() {
    const [examTypes, setExamTypes] = useState<ExamType[]>([...seed.examTypes]);
    const [modal, setModal] = useState<string | null>(null);
    const [form, setForm] = useState<ExamTypeForm>({ name: '' });
    const [confirm, setConfirm] = useState<ExamType | null>(null);
    const [inlineId, setInlineId] = useState<string | null>(null);
    const [inlineVal, setInlineVal] = useState<string>('');

    const save = async (): Promise<void> => {
        if (!form.name.trim()) {
            return;
        }

        await delay();

        if (modal === 'new') {
            setExamTypes((p) => [...p, { id: uid(), name: form.name.trim() }]);
        } else {
            setExamTypes((p) =>
                p.map((e) =>
                    e.id === modal ? { ...e, name: form.name.trim() } : e,
                ),
            );
        }

        setModal(null);
    };

    const commitInline = (id: string): void => {
        if (inlineVal.trim()) {
            setExamTypes((p) =>
                p.map((e) =>
                    e.id === id ? { ...e, name: inlineVal.trim() } : e,
                ),
            );
        }

        setInlineId(null);
    };

    return (
        <>
            <div className="page-hdr">
                <div>
                    <h1>Exam Types</h1>
                    <p>First Term, WAEC Mock, NECO, etc.</p>
                </div>
                <div className="page-hdr-actions">
                    <button
                        className="btn btn-primary"
                        onClick={() => {
                            setForm({ name: '' });
                            setModal('new');
                        }}
                    >
                        + New Exam Type
                    </button>
                </div>
            </div>
            <div className="card">
                <div className="tbl-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th style={{ textAlign: 'right' }}>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {examTypes.length === 0 && (
                                <tr>
                                    <td colSpan={2}>
                                        <Empty
                                            icon="📝"
                                            title="No exam types"
                                            sub="Add your first exam type"
                                        />
                                    </td>
                                </tr>
                            )}
                            {examTypes.map((e) => (
                                <tr key={e.id}>
                                    <td>
                                        {inlineId === e.id ? (
                                            <div className="inline-edit">
                                                <input
                                                    value={inlineVal}
                                                    autoFocus
                                                    onChange={(ev) =>
                                                        setInlineVal(
                                                            ev.target.value,
                                                        )
                                                    }
                                                    onKeyDown={(ev) => {
                                                        if (
                                                            ev.key === 'Enter'
                                                        ) {
                                                            commitInline(e.id);
                                                        }
                                                    }}
                                                />
                                                <button
                                                    className="btn btn-primary btn-sm"
                                                    onClick={() =>
                                                        commitInline(e.id)
                                                    }
                                                >
                                                    ✓
                                                </button>
                                                <button
                                                    className="btn btn-ghost btn-sm"
                                                    onClick={() =>
                                                        setInlineId(null)
                                                    }
                                                >
                                                    ✕
                                                </button>
                                            </div>
                                        ) : (
                                            <span
                                                style={{ fontWeight: 500 }}
                                                onDoubleClick={() => {
                                                    setInlineId(e.id);
                                                    setInlineVal(e.name);
                                                }}
                                            >
                                                {e.name}
                                            </span>
                                        )}
                                    </td>
                                    <td>
                                        <div
                                            className="row-actions"
                                            style={{
                                                justifyContent: 'flex-end',
                                            }}
                                        >
                                            <button
                                                className="btn btn-ghost btn-sm btn-icon"
                                                onClick={() => {
                                                    setInlineId(e.id);
                                                    setInlineVal(e.name);
                                                }}
                                            >
                                                ✏️
                                            </button>
                                            <button
                                                className="btn btn-danger btn-sm btn-icon"
                                                onClick={() => setConfirm(e)}
                                            >
                                                🗑
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            {modal && (
                <Modal
                    title="New Exam Type"
                    onClose={() => setModal(null)}
                    footer={
                        <>
                            <button
                                className="btn btn-outline"
                                onClick={() => setModal(null)}
                            >
                                Cancel
                            </button>
                            <button className="btn btn-primary" onClick={save}>
                                Save
                            </button>
                        </>
                    }
                >
                    <div className="field">
                        <label>Name</label>
                        <input
                            placeholder="e.g. Mid-Term Assessment"
                            value={form.name}
                            onChange={(e) => setForm({ name: e.target.value })}
                            autoFocus
                        />
                    </div>
                </Modal>
            )}
            {confirm && (
                <Confirm
                    msg={`Delete exam type "${confirm.name}"?`}
                    onConfirm={() => {
                        setExamTypes((p) =>
                            p.filter((e) => e.id !== confirm.id),
                        );
                        setConfirm(null);
                    }}
                    onClose={() => setConfirm(null)}
                />
            )}
        </>
    );
}

// ═══════════════════════════════════════════════════════════════════════════
// SUBJECTS TAB
// ═══════════════════════════════════════════════════════════════════════════

interface SubjectForm {
    name: string;
    code: string;
}

function SubjectsTab() {
    const [subjects, setSubjects] = useState<Subject[]>([...seed.subjects]);
    const [search, setSearch] = useState<string>('');
    const [modal, setModal] = useState<string | null>(null);
    const [form, setForm] = useState<SubjectForm>({ name: '', code: '' });
    const [confirm, setConfirm] = useState<Subject | null>(null);

    const filtered = subjects.filter(
        (s) =>
            s.name.toLowerCase().includes(search.toLowerCase()) ||
            (s.code || '').toLowerCase().includes(search.toLowerCase()),
    );

    const save = async (): Promise<void> => {
        if (!form.name.trim()) {
            return;
        }

        await delay();

        if (modal === 'new') {
            setSubjects((p) => [
                ...p,
                {
                    id: uid(),
                    name: form.name.trim(),
                    code: form.code.trim().toUpperCase(),
                },
            ]);
        } else {
            setSubjects((p) =>
                p.map((s) =>
                    s.id === modal
                        ? {
                              ...s,
                              name: form.name.trim(),
                              code: form.code.trim().toUpperCase(),
                          }
                        : s,
                ),
            );
        }

        setModal(null);
    };

    return (
        <>
            <div className="page-hdr">
                <div>
                    <h1>Subjects</h1>
                    <p>{subjects.length} subjects in the catalogue</p>
                </div>
                <div className="page-hdr-actions">
                    <div className="search-wrap">
                        <span className="search-icon">🔍</span>
                        <input
                            placeholder="Search…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            style={{ paddingLeft: 34, width: 220 }}
                        />
                    </div>
                    <button
                        className="btn btn-primary"
                        onClick={() => {
                            setForm({ name: '', code: '' });
                            setModal('new');
                        }}
                    >
                        + New Subject
                    </button>
                </div>
            </div>
            <div className="card">
                <div className="tbl-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th style={{ width: 44 }}>#</th>
                                <th>Subject name</th>
                                <th>Code</th>
                                <th style={{ textAlign: 'right' }}>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {filtered.length === 0 && (
                                <tr>
                                    <td colSpan={4}>
                                        <Empty
                                            icon="📚"
                                            title={
                                                search
                                                    ? 'No subjects match'
                                                    : 'No subjects yet'
                                            }
                                        />
                                    </td>
                                </tr>
                            )}
                            {filtered.map((s, i) => (
                                <tr key={s.id}>
                                    <td
                                        className="muted"
                                        style={{
                                            fontFamily: 'var(--mono)',
                                            fontSize: 12,
                                        }}
                                    >
                                        {i + 1}
                                    </td>
                                    <td style={{ fontWeight: 500 }}>
                                        {s.name}
                                    </td>
                                    <td>
                                        <span className="code-tag">
                                            {s.code || '—'}
                                        </span>
                                    </td>
                                    <td>
                                        <div
                                            className="row-actions"
                                            style={{
                                                justifyContent: 'flex-end',
                                            }}
                                        >
                                            <button
                                                className="btn btn-ghost btn-sm btn-icon"
                                                onClick={() => {
                                                    setForm({
                                                        name: s.name,
                                                        code: s.code || '',
                                                    });
                                                    setModal(s.id);
                                                }}
                                            >
                                                ✏️
                                            </button>
                                            <button
                                                className="btn btn-danger btn-sm btn-icon"
                                                onClick={() => setConfirm(s)}
                                            >
                                                🗑
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            {modal && (
                <Modal
                    title={modal === 'new' ? 'New Subject' : 'Edit Subject'}
                    onClose={() => setModal(null)}
                    footer={
                        <>
                            <button
                                className="btn btn-outline"
                                onClick={() => setModal(null)}
                            >
                                Cancel
                            </button>
                            <button className="btn btn-primary" onClick={save}>
                                Save
                            </button>
                        </>
                    }
                >
                    <div className="form-grid form-grid-2">
                        <div className="field span-2">
                            <label>Subject name</label>
                            <input
                                placeholder="e.g. Further Mathematics"
                                value={form.name}
                                onChange={(e) =>
                                    setForm((p) => ({
                                        ...p,
                                        name: e.target.value,
                                    }))
                                }
                                autoFocus
                            />
                        </div>
                        <div className="field">
                            <label>Subject code</label>
                            <input
                                placeholder="FMT"
                                value={form.code}
                                onChange={(e) =>
                                    setForm((p) => ({
                                        ...p,
                                        code: e.target.value,
                                    }))
                                }
                            />
                        </div>
                    </div>
                </Modal>
            )}
            {confirm && (
                <Confirm
                    msg={`Delete subject "${confirm.name}"?`}
                    onConfirm={() => {
                        setSubjects((p) =>
                            p.filter((s) => s.id !== confirm.id),
                        );
                        setConfirm(null);
                    }}
                    onClose={() => setConfirm(null)}
                />
            )}
        </>
    );
}

// ═══════════════════════════════════════════════════════════════════════════
// GRADE BOUNDARIES TAB
// ═══════════════════════════════════════════════════════════════════════════

type EditingMap = Record<string, GradeBoundary>;

function GradeBoundariesTab() {
    const [examTypes] = useState<ExamType[]>([...seed.examTypes]);
    const [boundaries, setBoundaries] = useState<GradeBoundary[]>([
        ...seed.gradeBoundaries,
    ]);
    const [filter, setFilter] = useState<string>('__default__');
    const [editing, setEditing] = useState<EditingMap>({});

    const displayed = boundaries.filter((b) =>
        filter === '__default__'
            ? b.exam_type_id === null
            : b.exam_type_id === filter,
    );

    const gradeColor = (g: string): string => {
        if (['A', 'A*', 'A1'].includes(g)) {
            return '#15803d';
        }

        if (['B', 'B2', 'B3'].includes(g)) {
            return '#1d4ed8';
        }

        if (['C', 'C4', 'C5', 'C6'].includes(g)) {
            return '#b45309';
        }

        return '#b91c1c';
    };

    const addRow = (): void => {
        const nb: GradeBoundary = {
            id: uid(),
            exam_type_id: filter === '__default__' ? null : filter,
            min_score: 0,
            max_score: 0,
            grade: '',
            label: '',
        };
        setBoundaries((p) => [...p, nb]);
        setEditing((p) => ({ ...p, [nb.id]: { ...nb } }));
    };

    const startEdit = (b: GradeBoundary): void =>
        setEditing((p) => ({ ...p, [b.id]: { ...b } }));
    const cancelEdit = (id: string): void =>
        setEditing((p) => {
            const n = { ...p };
            delete n[id];

            return n;
        });
    const updateEdit = (
        id: string,
        k: keyof GradeBoundary,
        v: string | number | null,
    ): void => setEditing((p) => ({ ...p, [id]: { ...p[id], [k]: v } }));
    const saveRow = (id: string): void => {
        setBoundaries((p) =>
            p.map((b) => (b.id === id ? { ...b, ...editing[id] } : b)),
        );
        cancelEdit(id);
    };
    const delRow = (id: string): void => {
        setBoundaries((p) => p.filter((b) => b.id !== id));
        cancelEdit(id);
    };

    return (
        <>
            <div className="page-hdr">
                <div>
                    <h1>Grade Boundaries</h1>
                    <p>Map score ranges to grade labels</p>
                </div>
                <div className="page-hdr-actions">
                    <button className="btn btn-primary" onClick={addRow}>
                        + Add Boundary
                    </button>
                </div>
            </div>

            <div className="filter-row">
                <button
                    className={`filter-btn${filter === '__default__' ? 'on' : ''}`}
                    onClick={() => setFilter('__default__')}
                >
                    Default
                </button>
                {examTypes.map((et) => (
                    <button
                        key={et.id}
                        className={`filter-btn${filter === et.id ? 'on' : ''}`}
                        onClick={() => setFilter(et.id)}
                    >
                        {et.name}
                    </button>
                ))}
            </div>

            <div className="card">
                <div
                    style={{
                        padding: '12px 16px',
                        borderBottom: '1px solid var(--border)',
                        background: 'var(--surface2)',
                    }}
                >
                    <div className="grade-cols">
                        <span className="grade-col-hdr">Min score</span>
                        <span className="grade-col-hdr">Max score</span>
                        <span className="grade-col-hdr">Grade</span>
                        <span className="grade-col-hdr">Label</span>
                        <span></span>
                    </div>
                </div>
                <div
                    style={{
                        padding: '10px 12px',
                        display: 'flex',
                        flexDirection: 'column',
                        gap: 6,
                    }}
                >
                    {displayed.length === 0 && (
                        <Empty
                            icon="📊"
                            title="No boundaries"
                            sub="Click '+ Add Boundary' to get started"
                        />
                    )}
                    {displayed.map((b) => {
                        const isEd = !!editing[b.id];
                        const e = editing[b.id] ?? b;

                        return (
                            <div
                                key={b.id}
                                style={{
                                    background: isEd
                                        ? 'var(--blue-lt)'
                                        : 'var(--surface2)',
                                    border: `1px solid ${isEd ? 'var(--blue-mid)' : 'var(--border)'}`,
                                    borderRadius: 8,
                                    padding: '9px 12px',
                                    transition: 'all 0.15s',
                                }}
                            >
                                <div className="grade-cols">
                                    {isEd ? (
                                        <>
                                            <input
                                                type="number"
                                                min="0"
                                                max="100"
                                                value={e.min_score}
                                                onChange={(ev) =>
                                                    updateEdit(
                                                        b.id,
                                                        'min_score',
                                                        +ev.target.value,
                                                    )
                                                }
                                            />
                                            <input
                                                type="number"
                                                min="0"
                                                max="101"
                                                value={e.max_score}
                                                onChange={(ev) =>
                                                    updateEdit(
                                                        b.id,
                                                        'max_score',
                                                        +ev.target.value,
                                                    )
                                                }
                                            />
                                            <input
                                                placeholder="A"
                                                value={e.grade}
                                                onChange={(ev) =>
                                                    updateEdit(
                                                        b.id,
                                                        'grade',
                                                        ev.target.value,
                                                    )
                                                }
                                            />
                                            <input
                                                placeholder="Distinction"
                                                value={e.label}
                                                onChange={(ev) =>
                                                    updateEdit(
                                                        b.id,
                                                        'label',
                                                        ev.target.value,
                                                    )
                                                }
                                            />
                                            <div
                                                style={{
                                                    display: 'flex',
                                                    gap: 4,
                                                }}
                                            >
                                                <button
                                                    className="btn btn-primary btn-sm btn-icon"
                                                    onClick={() =>
                                                        saveRow(b.id)
                                                    }
                                                >
                                                    ✓
                                                </button>
                                                <button
                                                    className="btn btn-ghost btn-sm btn-icon"
                                                    onClick={() =>
                                                        cancelEdit(b.id)
                                                    }
                                                >
                                                    ✕
                                                </button>
                                            </div>
                                        </>
                                    ) : (
                                        <>
                                            <span
                                                style={{
                                                    fontFamily: 'var(--mono)',
                                                    fontSize: 13,
                                                    color: 'var(--text2)',
                                                }}
                                            >
                                                {b.min_score}
                                            </span>
                                            <span
                                                style={{
                                                    fontFamily: 'var(--mono)',
                                                    fontSize: 13,
                                                    color: 'var(--text2)',
                                                }}
                                            >
                                                {b.max_score}
                                            </span>
                                            <span
                                                style={{
                                                    fontFamily: 'var(--mono)',
                                                    fontWeight: 700,
                                                    color: gradeColor(b.grade),
                                                    fontSize: 14,
                                                }}
                                            >
                                                {b.grade}
                                            </span>
                                            <span style={{ fontSize: 13.5 }}>
                                                {b.label}
                                            </span>
                                            <div
                                                style={{
                                                    display: 'flex',
                                                    gap: 4,
                                                }}
                                            >
                                                <button
                                                    className="btn btn-ghost btn-sm btn-icon"
                                                    onClick={() => startEdit(b)}
                                                >
                                                    ✏️
                                                </button>
                                                <button
                                                    className="btn btn-danger btn-sm btn-icon"
                                                    onClick={() => delRow(b.id)}
                                                >
                                                    🗑
                                                </button>
                                            </div>
                                        </>
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>
        </>
    );
}

// ═══════════════════════════════════════════════════════════════════════════
// STUDENTS TAB
// ═══════════════════════════════════════════════════════════════════════════

interface StudentForm {
    first_name: string;
    last_name: string;
    admission_number: string;
}

function StudentsTab() {
    const [students, setStudents] = useState<Student[]>([...seed.students]);
    const [search, setSearch] = useState<string>('');
    const [modal, setModal] = useState<string | null>(null);
    const [form, setForm] = useState<StudentForm>({
        first_name: '',
        last_name: '',
        admission_number: '',
    });
    const [confirm, setConfirm] = useState<Student | null>(null);

    const filtered = students.filter((s) => {
        const q = search.toLowerCase();

        return (
            `${s.first_name} ${s.last_name}`.toLowerCase().includes(q) ||
            (s.admission_number || '').toLowerCase().includes(q)
        );
    });

    const open = (s: Student | null = null): void => {
        setForm(
            s
                ? {
                      first_name: s.first_name,
                      last_name: s.last_name,
                      admission_number: s.admission_number || '',
                  }
                : { first_name: '', last_name: '', admission_number: '' },
        );
        setModal(s ? s.id : 'new');
    };

    const save = async (): Promise<void> => {
        if (!form.first_name.trim() || !form.last_name.trim()) {
            return;
        }

        await delay();

        if (modal === 'new') {
            setStudents((p) => [...p, { id: uid(), ...form }]);
        } else {
            setStudents((p) =>
                p.map((s) => (s.id === modal ? { ...s, ...form } : s)),
            );
        }

        setModal(null);
    };

    return (
        <>
            <div className="page-hdr">
                <div>
                    <h1>Students</h1>
                    <p>{students.length} students enrolled</p>
                </div>
                <div className="page-hdr-actions">
                    <div className="search-wrap">
                        <span className="search-icon">🔍</span>
                        <input
                            placeholder="Search by name or admission no…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            style={{ paddingLeft: 34, width: 260 }}
                        />
                    </div>
                    <button className="btn btn-primary" onClick={() => open()}>
                        + Add Student
                    </button>
                </div>
            </div>
            <div className="card">
                <div className="tbl-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th style={{ width: 44 }}>#</th>
                                <th>Full name</th>
                                <th>Admission number</th>
                                <th style={{ textAlign: 'right' }}>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {filtered.length === 0 && (
                                <tr>
                                    <td colSpan={4}>
                                        <Empty
                                            icon="👤"
                                            title={
                                                search
                                                    ? 'No students match'
                                                    : 'No students yet'
                                            }
                                        />
                                    </td>
                                </tr>
                            )}
                            {filtered.map((s, i) => (
                                <tr key={s.id}>
                                    <td
                                        className="muted"
                                        style={{
                                            fontFamily: 'var(--mono)',
                                            fontSize: 12,
                                        }}
                                    >
                                        {i + 1}
                                    </td>
                                    <td style={{ fontWeight: 500 }}>
                                        {s.first_name} {s.last_name}
                                    </td>
                                    <td className="mono">
                                        {s.admission_number || (
                                            <span
                                                style={{
                                                    color: 'var(--text3)',
                                                }}
                                            >
                                                —
                                            </span>
                                        )}
                                    </td>
                                    <td>
                                        <div
                                            className="row-actions"
                                            style={{
                                                justifyContent: 'flex-end',
                                            }}
                                        >
                                            <button
                                                className="btn btn-ghost btn-sm btn-icon"
                                                onClick={() => open(s)}
                                            >
                                                ✏️
                                            </button>
                                            <button
                                                className="btn btn-danger btn-sm btn-icon"
                                                onClick={() => setConfirm(s)}
                                            >
                                                🗑
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            {modal && (
                <Modal
                    title={modal === 'new' ? 'Add Student' : 'Edit Student'}
                    onClose={() => setModal(null)}
                    footer={
                        <>
                            <button
                                className="btn btn-outline"
                                onClick={() => setModal(null)}
                            >
                                Cancel
                            </button>
                            <button className="btn btn-primary" onClick={save}>
                                Save
                            </button>
                        </>
                    }
                >
                    <div className="form-grid form-grid-2">
                        <div className="field">
                            <label>First name</label>
                            <input
                                value={form.first_name}
                                onChange={(e) =>
                                    setForm((p) => ({
                                        ...p,
                                        first_name: e.target.value,
                                    }))
                                }
                                autoFocus
                            />
                        </div>
                        <div className="field">
                            <label>Last name</label>
                            <input
                                value={form.last_name}
                                onChange={(e) =>
                                    setForm((p) => ({
                                        ...p,
                                        last_name: e.target.value,
                                    }))
                                }
                            />
                        </div>
                        <div className="field span-2">
                            <label>Admission number</label>
                            <input
                                placeholder="GFA/2025/011"
                                value={form.admission_number}
                                onChange={(e) =>
                                    setForm((p) => ({
                                        ...p,
                                        admission_number: e.target.value,
                                    }))
                                }
                            />
                        </div>
                    </div>
                </Modal>
            )}
            {confirm && (
                <Confirm
                    msg={`Remove student "${confirm.first_name} ${confirm.last_name}"?`}
                    onConfirm={() => {
                        setStudents((p) =>
                            p.filter((s) => s.id !== confirm.id),
                        );
                        setConfirm(null);
                    }}
                    onClose={() => setConfirm(null)}
                />
            )}
        </>
    );
}

// ═══════════════════════════════════════════════════════════════════════════
// CURRICULA TAB
// ═══════════════════════════════════════════════════════════════════════════

interface CurriculumForm {
    session_id: string;
    class_level_id: string;
    exam_type_id: string;
    term: string;
    min_subjects: string;
    registration_deadline: string;
    result_visible_at: string;
    status: Curriculum['status'];
}

function CurriculaTab() {
    const [curricula, setCurricula] = useState<Curriculum[]>([
        ...seed.curricula,
    ]);
    const [sessions] = useState<Session[]>([...seed.sessions]);
    const [classLevels] = useState<ClassLevel[]>([...seed.classLevels]);
    const [examTypes] = useState<ExamType[]>([...seed.examTypes]);
    const [modal, setModal] = useState<string | null>(null);
    const [confirm, setConfirm] = useState<Curriculum | null>(null);

    const blank: CurriculumForm = {
        session_id: '',
        class_level_id: '',
        exam_type_id: '',
        term: '1',
        min_subjects: '8',
        registration_deadline: '',
        result_visible_at: '',
        status: 'draft',
    };
    const [form, setForm] = useState<CurriculumForm>(blank);

    const name = <T extends { id: string; name: string }>(
        arr: T[],
        id: string,
    ): string => arr.find((x) => x.id === id)?.name ?? '—';

    const fmt = (d: string): string =>
        d
            ? new Date(d).toLocaleDateString('en-GB', {
                  day: '2-digit',
                  month: 'short',
                  year: 'numeric',
              })
            : '—';

    const f = <K extends keyof CurriculumForm>(
        k: K,
        v: CurriculumForm[K],
    ): void => setForm((p) => ({ ...p, [k]: v }));

    const open = (c: Curriculum | null = null): void => {
        if (c) {
            setForm({
                session_id: c.session_id,
                class_level_id: c.class_level_id,
                exam_type_id: c.exam_type_id,
                term: String(c.term),
                min_subjects: String(c.min_subjects),
                registration_deadline: (c.registration_deadline || '').slice(
                    0,
                    16,
                ),
                result_visible_at: (c.result_visible_at || '').slice(0, 16),
                status: c.status,
            });
        } else {
            setForm({
                ...blank,
                session_id: sessions.find((s) => s.is_current)?.id ?? '',
            });
        }

        setModal(c ? c.id : 'new');
    };

    const save = async (): Promise<void> => {
        if (!form.session_id || !form.class_level_id || !form.exam_type_id) {
            return;
        }

        await delay();
        const payload: Omit<Curriculum, 'id'> = {
            ...form,
            term: +form.term,
            min_subjects: +form.min_subjects,
        };

        if (modal === 'new') {
            setCurricula((p) => [...p, { id: uid(), ...payload }]);
        } else {
            setCurricula((p) =>
                p.map((c) => (c.id === modal ? { ...c, ...payload } : c)),
            );
        }

        setModal(null);
    };

    return (
        <>
            <div className="page-hdr">
                <div>
                    <h1>Curricula</h1>
                    <p>Session × class level × term configurations</p>
                </div>
                <div className="page-hdr-actions">
                    <button className="btn btn-primary" onClick={() => open()}>
                        + New Curriculum
                    </button>
                </div>
            </div>
            <div className="card">
                <div className="tbl-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Session</th>
                                <th>Level</th>
                                <th>Term</th>
                                <th>Exam type</th>
                                <th style={{ textAlign: 'center' }}>
                                    Min. subj.
                                </th>
                                <th>Reg. deadline</th>
                                <th>Results visible</th>
                                <th>Status</th>
                                <th style={{ textAlign: 'right' }}>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {curricula.length === 0 && (
                                <tr>
                                    <td colSpan={9}>
                                        <Empty
                                            icon="📋"
                                            title="No curricula yet"
                                            sub="Create your first curriculum"
                                        />
                                    </td>
                                </tr>
                            )}
                            {curricula.map((c) => (
                                <tr key={c.id}>
                                    <td
                                        style={{
                                            fontFamily: 'var(--mono)',
                                            fontWeight: 600,
                                            fontSize: 12.5,
                                        }}
                                    >
                                        {name(sessions, c.session_id)}
                                    </td>
                                    <td>
                                        <span className="code-tag">
                                            {name(
                                                classLevels,
                                                c.class_level_id,
                                            )}
                                        </span>
                                    </td>
                                    <td className="muted">Term {c.term}</td>
                                    <td
                                        style={{
                                            fontSize: 12.5,
                                            color: 'var(--text2)',
                                        }}
                                    >
                                        {name(examTypes, c.exam_type_id)}
                                    </td>
                                    <td
                                        style={{ textAlign: 'center' }}
                                        className="mono"
                                    >
                                        {c.min_subjects}
                                    </td>
                                    <td
                                        className="muted"
                                        style={{ fontSize: 12.5 }}
                                    >
                                        {fmt(c.registration_deadline)}
                                    </td>
                                    <td
                                        className="muted"
                                        style={{ fontSize: 12.5 }}
                                    >
                                        {fmt(c.result_visible_at)}
                                    </td>
                                    <td>
                                        <StatusPill status={c.status} />
                                    </td>
                                    <td>
                                        <div
                                            className="row-actions"
                                            style={{
                                                justifyContent: 'flex-end',
                                            }}
                                        >
                                            <button
                                                className="btn btn-ghost btn-sm btn-icon"
                                                onClick={() => open(c)}
                                            >
                                                ✏️
                                            </button>
                                            <button
                                                className="btn btn-danger btn-sm btn-icon"
                                                onClick={() => setConfirm(c)}
                                            >
                                                🗑
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            {modal && (
                <Modal
                    title={
                        modal === 'new' ? 'New Curriculum' : 'Edit Curriculum'
                    }
                    large
                    onClose={() => setModal(null)}
                    footer={
                        <>
                            <button
                                className="btn btn-outline"
                                onClick={() => setModal(null)}
                            >
                                Cancel
                            </button>
                            <button className="btn btn-primary" onClick={save}>
                                Save curriculum
                            </button>
                        </>
                    }
                >
                    <div className="form-grid form-grid-3">
                        <div className="field">
                            <label>Session</label>
                            <select
                                value={form.session_id}
                                onChange={(e) =>
                                    f('session_id', e.target.value)
                                }
                            >
                                <option value="">Select…</option>
                                {sessions.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="field">
                            <label>Class level</label>
                            <select
                                value={form.class_level_id}
                                onChange={(e) =>
                                    f('class_level_id', e.target.value)
                                }
                            >
                                <option value="">Select…</option>
                                {[...classLevels]
                                    .sort((a, b) => a.order - b.order)
                                    .map((l) => (
                                        <option key={l.id} value={l.id}>
                                            {l.name}
                                        </option>
                                    ))}
                            </select>
                        </div>
                        <div className="field">
                            <label>Term</label>
                            <select
                                value={form.term}
                                onChange={(e) => f('term', e.target.value)}
                            >
                                <option value="1">Term 1</option>
                                <option value="2">Term 2</option>
                                <option value="3">Term 3</option>
                            </select>
                        </div>
                        <div className="field span-2">
                            <label>Exam type</label>
                            <select
                                value={form.exam_type_id}
                                onChange={(e) =>
                                    f('exam_type_id', e.target.value)
                                }
                            >
                                <option value="">Select…</option>
                                {examTypes.map((e) => (
                                    <option key={e.id} value={e.id}>
                                        {e.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="field">
                            <label>Status</label>
                            <select
                                value={form.status}
                                onChange={(e) =>
                                    f(
                                        'status',
                                        e.target.value as Curriculum['status'],
                                    )
                                }
                            >
                                <option value="draft">Draft</option>
                                <option value="active">Active</option>
                                <option value="closed">Closed</option>
                            </select>
                        </div>
                        <div className="field">
                            <label>Min. subjects</label>
                            <input
                                type="number"
                                min="1"
                                value={form.min_subjects}
                                onChange={(e) =>
                                    f('min_subjects', e.target.value)
                                }
                            />
                        </div>
                        <div className="field">
                            <label>Registration deadline</label>
                            <input
                                type="datetime-local"
                                value={form.registration_deadline}
                                onChange={(e) =>
                                    f('registration_deadline', e.target.value)
                                }
                            />
                        </div>
                        <div className="field">
                            <label>Results visible at</label>
                            <input
                                type="datetime-local"
                                value={form.result_visible_at}
                                onChange={(e) =>
                                    f('result_visible_at', e.target.value)
                                }
                            />
                        </div>
                    </div>
                </Modal>
            )}
            {confirm && (
                <Confirm
                    msg="Delete this curriculum? Any linked scores and results will be affected."
                    onConfirm={() => {
                        setCurricula((p) =>
                            p.filter((c) => c.id !== confirm.id),
                        );
                        setConfirm(null);
                    }}
                    onClose={() => setConfirm(null)}
                />
            )}
        </>
    );
}

// ═══════════════════════════════════════════════════════════════════════════
// ROOT
// ═══════════════════════════════════════════════════════════════════════════

type TabId =
    | 'overview'
    | 'sessions'
    | 'structure'
    | 'exam-types'
    | 'subjects'
    | 'grades'
    | 'students'
    | 'curricula';

const TABS: TabConfig[] = [
    { id: 'overview', label: 'Overview', icon: '⊞', count: null },
    {
        id: 'sessions',
        label: 'Sessions',
        icon: '📅',
        count: () => seed.sessions.length,
    },
    { id: 'structure', label: 'Class Structure', icon: '🏫', count: null },
    {
        id: 'exam-types',
        label: 'Exam Types',
        icon: '📝',
        count: () => seed.examTypes.length,
    },
    {
        id: 'subjects',
        label: 'Subjects',
        icon: '📚',
        count: () => seed.subjects.length,
    },
    {
        id: 'grades',
        label: 'Grade Boundaries',
        icon: '📊',
        count: () => seed.gradeBoundaries.length,
    },
    {
        id: 'students',
        label: 'Students',
        icon: '👤',
        count: () => seed.students.length,
    },
    {
        id: 'curricula',
        label: 'Curricula',
        icon: '📋',
        count: () => seed.curricula.length,
    },
];

export default function SchoolSetup() {
    const [active, setActive] = useState<TabId>('overview');
    const [toasts, setToasts] = useState<Toast[]>([]);
    const [data, setData] = useState<SetupData | null>(null);

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
            console.log(response.data);
            setData(response.data);
        }
        getSetupData();
    }, []);

    const render = () => {
        switch (active) {
            case 'overview':
                return <OverviewTab data={data} />;
            case 'sessions':
                return <SessionsTab addToast={addToast} />;
            case 'structure':
                return <ClassStructureTab />;
            case 'exam-types':
                return <ExamTypesTab />;
            case 'subjects':
                return <SubjectsTab />;
            case 'grades':
                return <GradeBoundariesTab />;
            case 'students':
                return <StudentsTab />;
            case 'curricula':
                return <CurriculaTab />;
            default:
                return null;
        }
    };

    const initials = seed.school.name
        .split(' ')
        .map((w) => w[0])
        .slice(0, 2)
        .join('');

    const cur = seed.sessions.find((s) => s.is_current);

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
                                        {seed.school.name}
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
                                        ● {cur?.name ?? 'No active session'}
                                    </span>
                                </div>
                            </div>

                            <div className="tab-row">
                                {TABS.map((t) => {
                                    const count = t.count?.();

                                    return (
                                        <button
                                            key={t.id}
                                            className={`tab-btn${active === t.id ? 'active' : ''}`}
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
