import { PSYCHOMOTOR_CATEGORIES, PSYCHOMOTOR_LABELS } from '@/lib/assessment';
import type { PsychomotorSkill } from '@/types/models';

const GRADES = ['A', 'B', 'C', 'D', 'E'];

/**
 * Checkmark grid of psychomotor skill grades for the student result
 * template. Renders an empty grid when no skill record exists — same
 * behavior as BehavioralAssessmentTable.
 */
export function PsychomotorSkillsTable({
    skill,
}: {
    skill?: PsychomotorSkill | null;
}) {
    return (
        <div className="overflow-x-auto text-xs">
            <table className="w-full border-collapse border border-gray-300 text-xs">
                <thead className="bg-slate-700 text-white">
                    <tr>
                        <th className="border border-gray-300 text-left whitespace-nowrap">
                            Psychomotor Skills
                        </th>

                        {GRADES.map((grade) => (
                            <th
                                key={grade}
                                className="w-12 border border-gray-300 text-center"
                            >
                                {grade}
                            </th>
                        ))}
                    </tr>
                </thead>

                <tbody>
                    {PSYCHOMOTOR_CATEGORIES.map((category) => (
                        <tr key={category}>
                            <td className="border border-gray-300 whitespace-nowrap">
                                {PSYCHOMOTOR_LABELS[category]}
                            </td>

                            {GRADES.map((grade) => (
                                <td
                                    key={grade}
                                    className="border border-gray-300 text-center"
                                >
                                    {skill?.[category] === grade ? '✓' : ''}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
