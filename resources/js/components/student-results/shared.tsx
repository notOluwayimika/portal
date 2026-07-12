import type { GradeBoundary, Student, StudentCurriculum } from '@/types/models';

// ---------------------------------------------------------------------------
// Shared result-card types, helpers and components.
//
// These live outside the `pages/` directory on purpose: page modules are
// dynamically imported by Inertia AND referenced individually in the Vite
// manifest (see app.blade.php). If a component statically imports from a
// page module, Rollup merges the page into that component's chunk and the
// page loses its manifest entry — full page reloads then fail with
// "Unable to locate file in Vite manifest".
// ---------------------------------------------------------------------------

export interface ResultRow {
    key: string;
    name: string;
    code: string;
    compulsory: boolean;
    score: number | null;
    grade: string;
    classAvg: number | null;
    classAvgGrade: string;
    comment?: string | null;
    commented_by?: string | null;
}

export interface CurriculumCardProps {
    sc: StudentCurriculum;
    defaultBoundaries: GradeBoundary[];
    studentId: number | string;
    student: Student;
    boundaries?: GradeBoundary[];
}

export const toNum = (v: string | number): number =>
    typeof v === 'number' ? v : parseFloat(v);

export function gradeForScore(
    score: number | null,
    boundaries: GradeBoundary[],
): string {
    if (score == null || Number.isNaN(score)) {
        return '—';
    }

    const flooredScore = Math.floor(score);

    for (const b of boundaries) {
        const min = toNum(b.min_score);
        const max = toNum(b.max_score);

        if (flooredScore >= min && flooredScore <= max) {
            return b.grade;
        }
    }

    // include the very top edge in the highest band
    const top = boundaries[0];

    if (top && flooredScore >= toNum(top.max_score)) {
        return top.grade;
    }

    return '—';
}

export function nextGradeForScore(
    score: number | null,
    boundaries: GradeBoundary[],
): string {
    if (score == null || Number.isNaN(score)) {
        return '—';
    }

    const flooredScore = Math.floor(score);

    for (let i = 0; i < boundaries.length; i++) {
        const b = boundaries[i];

        const min = toNum(b.min_score);
        const max = toNum(b.max_score);

        if (flooredScore >= min && flooredScore <= max) {
            // return the grade ABOVE the current one
            return boundaries[i - 1]?.grade ?? b.grade;
        }
    }

    // handle top edge
    const top = boundaries[0];

    if (top && flooredScore >= toNum(top.max_score)) {
        return top.grade; // already highest
    }

    return '—';
}

export function totalGradePoint(row: ResultRow[], boundaries: GradeBoundary[]) {
    let GP = 0;
    let count = 0;
    row.forEach((r) => {
        if (r.score) {
            const flooredScore = Math.floor(r.score);
            GP += toNum(gradePointForScore(flooredScore, boundaries));
            count++;
        }
    });
    GP = Math.round(GP);

    return count > 0 ? (GP / count).toFixed(1) : '—';
}

export function gradePointForScore(
    score: number | null,
    boundaries: GradeBoundary[],
): string {
    if (score == null || Number.isNaN(score)) {
        return '—';
    }

    const flooredScore = Math.floor(score);

    for (const b of boundaries) {
        const min = toNum(b.min_score);
        const max = toNum(b.max_score);

        if (flooredScore >= min && flooredScore <= max) {
            return b.grade_point;
        }
    }

    // include the very top edge in the highest band
    const top = boundaries[0];

    if (top && flooredScore >= toNum(top.max_score)) {
        return top.grade_point;
    }

    return '—';
}

interface GradeKeyTableProps {
    boundaries: GradeBoundary[];
}

export function GradeKeyTable({ boundaries }: GradeKeyTableProps) {
    return (
        <div className="overflow-hidden border border-slate-300 shadow-sm">
            <div className="bg-slate-400 px-4">
                <h3 className="text-xs font-bold text-white">
                    Grade Key Table
                </h3>
            </div>
            <div className="overflow-x-auto">
                <table className="w-full border-collapse text-xs">
                    <thead>
                        <tr className="bg-slate-100 text-left text-slate-700">
                            <th className="border border-slate-300 px-1 font-semibold">
                                Grade
                            </th>
                            <th className="border border-slate-300 px-1 font-semibold">
                                Score Range
                            </th>
                            <th className="border border-slate-300 px-1 font-semibold">
                                Label
                            </th>
                            <th className="border border-slate-300 px-1 font-semibold">
                                Grade Point
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {boundaries.map((b, i) => (
                            <tr
                                key={b.id ?? b.grade}
                                className={i % 2 ? 'bg-slate-50' : 'bg-white'}
                            >
                                <td
                                    className={`border border-slate-300 px-1 font-bold text-black`}
                                >
                                    {b.grade}
                                </td>
                                <td className="border border-slate-300 px-1 text-slate-600 tabular-nums">
                                    {toNum(b.min_score)} – {toNum(b.max_score)}
                                </td>
                                <td className="border border-slate-300 px-1 text-slate-700">
                                    {b.label}
                                </td>
                                <td className="border border-slate-300 px-1 text-slate-700">
                                    {b.grade_point}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
