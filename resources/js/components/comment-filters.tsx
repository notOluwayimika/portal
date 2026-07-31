import { useMemo } from 'react';

/**
 * The shared filter bar for the comment and assessment screens.
 *
 * One component for all four (form teacher, head of school, key stage
 * coordinator, boarding parent) so they cannot drift into four slightly different
 * filter bars — the same reason the row payloads come from one trait helper.
 *
 * FILTERING IS CLIENT-SIDE, deliberately. Each of those pages already loads its
 * whole per-term list in one request (a teacher's arms, not the school's), so the
 * rows to filter are already in hand. Pushing this to the server would mean query
 * params on four endpoints and a second round trip for no gain.
 *
 * The class level and arm options are DERIVED FROM THE ROWS rather than fetched
 * from /api/class-structure: a form teacher holds one arm and a coordinator a
 * handful, so offering every class in the school would list filters that match
 * nothing.
 */
export interface FilterableRow {
    student: {
        first_name: string;
        last_name: string;
        admission_number?: string | null;
    };
    class_level?: { id: string; name: string } | null;
    class_level_arm?: { id: string; name: string } | null;
}

export interface CommentFilterState {
    search: string;
    classLevelId: string;
    classLevelArmId: string;
    status: 'all' | 'done' | 'pending';
}

export const emptyCommentFilters: CommentFilterState = {
    search: '',
    classLevelId: '',
    classLevelArmId: '',
    status: 'all',
};

/**
 * Apply the filters to a set of rows.
 *
 * `isDone` is supplied by the caller because "done" is not the same question on
 * every page: three of them mean a comment exists, the boarding-parent screen
 * means an ASSESSMENT exists. Keeping the predicate out here is what lets the
 * labels differ per page without the filtering logic forking.
 */
export function applyCommentFilters<T extends FilterableRow>(
    rows: T[],
    filters: CommentFilterState,
    isDone: (row: T) => boolean,
): T[] {
    const needle = filters.search.trim().toLowerCase();

    return rows.filter((row) => {
        if (needle) {
            const haystack =
                `${row.student.first_name ?? ''} ${row.student.last_name ?? ''} ${row.student.admission_number ?? ''}`.toLowerCase();

            if (!haystack.includes(needle)) {
                return false;
            }
        }

        if (
            filters.classLevelId &&
            row.class_level?.id !== filters.classLevelId
        ) {
            return false;
        }

        if (
            filters.classLevelArmId &&
            row.class_level_arm?.id !== filters.classLevelArmId
        ) {
            return false;
        }

        if (filters.status === 'done' && !isDone(row)) {
            return false;
        }

        if (filters.status === 'pending' && isDone(row)) {
            return false;
        }

        return true;
    });
}

export function CommentFilters<T extends FilterableRow>({
    rows,
    value,
    onChange,
    doneLabel,
    pendingLabel,
}: {
    rows: T[];
    value: CommentFilterState;
    onChange: (next: CommentFilterState) => void;
    /** e.g. "Commented" — or "Assessed" on the boarding-parent screen. */
    doneLabel: string;
    /** e.g. "Not commented" / "Not assessed". */
    pendingLabel: string;
}) {
    const classLevels = useMemo(() => {
        const map = new Map<string, string>();

        for (const row of rows) {
            if (row.class_level) {
                map.set(row.class_level.id, row.class_level.name);
            }
        }

        return Array.from(map, ([id, name]) => ({ id, name })).sort((a, b) =>
            a.name.localeCompare(b.name),
        );
    }, [rows]);

    // Arms are narrowed by the chosen level, so picking a level then an arm reads
    // as one drill-down rather than two independent lists.
    const arms = useMemo(() => {
        const map = new Map<string, string>();

        for (const row of rows) {
            if (!row.class_level_arm) {
                continue;
            }

            if (
                value.classLevelId &&
                row.class_level?.id !== value.classLevelId
            ) {
                continue;
            }

            map.set(row.class_level_arm.id, row.class_level_arm.name);
        }

        return Array.from(map, ([id, name]) => ({ id, name })).sort((a, b) =>
            a.name.localeCompare(b.name),
        );
    }, [rows, value.classLevelId]);

    const select =
        'rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700 focus:border-indigo-400 focus:outline-none';

    return (
        <div className="flex flex-wrap items-center gap-2">
            <input
                type="search"
                value={value.search}
                onChange={(e) => onChange({ ...value, search: e.target.value })}
                placeholder="Search name or admission no…"
                className="w-60 rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-indigo-400 focus:outline-none"
            />

            {classLevels.length > 1 && (
                <select
                    value={value.classLevelId}
                    onChange={(e) =>
                        // Clearing the arm is the point of the dependency: an arm
                        // from the previous level would filter everything away.
                        onChange({
                            ...value,
                            classLevelId: e.target.value,
                            classLevelArmId: '',
                        })
                    }
                    className={select}
                >
                    <option value="">All class levels</option>
                    {classLevels.map((level) => (
                        <option key={level.id} value={level.id}>
                            {level.name}
                        </option>
                    ))}
                </select>
            )}

            {arms.length > 1 && (
                <select
                    value={value.classLevelArmId}
                    onChange={(e) =>
                        onChange({ ...value, classLevelArmId: e.target.value })
                    }
                    className={select}
                >
                    <option value="">All classes</option>
                    {arms.map((arm) => (
                        <option key={arm.id} value={arm.id}>
                            {arm.name}
                        </option>
                    ))}
                </select>
            )}

            <select
                value={value.status}
                onChange={(e) =>
                    onChange({
                        ...value,
                        status: e.target.value as CommentFilterState['status'],
                    })
                }
                className={select}
            >
                <option value="all">All</option>
                <option value="done">{doneLabel}</option>
                <option value="pending">{pendingLabel}</option>
            </select>
        </div>
    );
}
