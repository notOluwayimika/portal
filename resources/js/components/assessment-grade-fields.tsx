import { snakeToTitleCase } from '@/hooks/use-helper';
import { GRADE_MAPPING, GRADES } from '@/lib/assessment';
import type { BehavioralGrade } from '@/types/models';

/**
 * Grid of A–E grade selects, one per assessment field. Shared by the
 * behavioral pillars and the psychomotor categories.
 */
export function AssessmentGradeFields<F extends string>({
    fields,
    labels,
    values,
    onChange,
}: {
    fields: readonly F[];
    labels?: Partial<Record<F, string>>;
    values: Record<F, BehavioralGrade | ''>;
    onChange: (field: F, value: BehavioralGrade | '') => void;
}) {
    return (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            {fields.map((field) => (
                <label key={field} className="block">
                    <span className="mb-1 block text-xs font-medium text-gray-600">
                        {labels?.[field] ?? snakeToTitleCase(field)}
                    </span>
                    <select
                        value={values[field]}
                        onChange={(e) =>
                            onChange(
                                field,
                                e.target.value as BehavioralGrade | '',
                            )
                        }
                        className="w-full rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 focus:outline-none"
                    >
                        <option value="">Select an option</option>
                        {GRADES.map((grade) => (
                            <option key={grade} value={grade}>
                                {grade} - {GRADE_MAPPING[grade]}
                            </option>
                        ))}
                    </select>
                </label>
            ))}
        </div>
    );
}
