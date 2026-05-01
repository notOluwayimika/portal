import type { SetupData } from '@/types/models';

interface StatItem {
    v?: number;
    l: string;
}

interface RelRow {
    left: string;
    arrow: string;
    right: string;
}

export function OverviewTab({ data }: { data: SetupData | null }) {
    const cur = data?.current_session;

    const stats: StatItem[] = [
        { v: data?.sessions, l: 'Sessions' },
        { v: data?.class_levels, l: 'Class Levels' },
        { v: data?.arms, l: 'Arms' },
        { v: data?.exam_types, l: 'Exam Types' },
        { v: data?.subjects, l: 'Subjects' },
        { v: data?.grade_boundaries, l: 'Grade Boundaries' },
        { v: data?.students, l: 'Students' },
        { v: data?.curricula, l: 'Curricula' },
    ];

    const rels: RelRow[] = [
        {
            left: 'schools',
            arrow: '→',
            right: 'sessions, class_levels, arms, subjects, exam_types, grade_boundaries, students',
        },
        {
            left: 'class_levels + arms',
            arrow: '→',
            right: 'class_level_arms (join table)',
        },
        {
            left: 'sessions + class_levels + exam_types + term',
            arrow: '→',
            right: 'curricula',
        },
        {
            left: 'curricula + subjects',
            arrow: '→',
            right: 'curriculum_subjects (is_compulsory, display_order)',
        },
        {
            left: 'curriculum_subjects',
            arrow: '→',
            right: 'marking_components (CA weight + Exam weight)',
        },
        {
            left: 'students + curricula',
            arrow: '→',
            right: 'student_curricula → student_subjects',
        },
        {
            left: 'students + marking_components',
            arrow: '→',
            right: 'scores → student_results',
        },
    ];

    return (
        <>
            <div className="page-hdr">
                <div>
                    <h1>{data?.school?.name}</h1>
                    <p>
                        Current session:{' '}
                        <strong style={{ color: 'var(--blue)' }}>
                            {cur?.name ?? '—'}
                        </strong>
                    </p>
                </div>
            </div>
            <div className="stats-grid">
                {stats.map((s) => (
                    <div className="stat-card" key={s.l}>
                        <div className="stat-val">{s.v}</div>
                        <div className="stat-lbl">{s.l}</div>
                    </div>
                ))}
            </div>
            <div className="card">
                <div className="card-hdr">
                    <span className="card-hdr-title">Table relationships</span>
                </div>
                <div style={{ padding: '4px 18px 12px' }}>
                    {rels.map((r) => (
                        <div className="rel-row" key={r.left}>
                            <span className="rel-left">{r.left}</span>
                            <span className="rel-arrow">{r.arrow}</span>
                            <span className="rel-right">{r.right}</span>
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}
