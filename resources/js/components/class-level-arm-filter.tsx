import { useMemo, useState } from 'react';
import type { ClassLevel, ClassLevelArm } from '@/types/models';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface Props {
    classLevelArms: ClassLevelArm[];
    selected: string[];
    setFilters: (selected: string[]) => void;
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export default function ClassLevelArmFilter({
    classLevelArms,
    selected,
    setFilters,
}: Props) {
    const [open, setOpen] = useState(true);

    const groups = useMemo(() => {
        const map = new Map<
            string,
            { classLevel: ClassLevel; arms: ClassLevelArm[] }
        >();

        for (const cla of classLevelArms) {
            if (!map.has(cla.class_level.id)) {
                map.set(cla.class_level.id, {
                    classLevel: cla.class_level,
                    arms: [],
                });
            }

            map.get(cla.class_level.id)!.arms.push(cla);
        }

        return Array.from(map.values());
    }, [classLevelArms]);

    const selectedSet = useMemo(() => new Set(selected), [selected]);

    function toggle(id: string) {
        const next = new Set(selectedSet);
        next.has(id) ? next.delete(id) : next.add(id);
        setFilters(Array.from(next));
    }

    function toggleGroup(arms: ClassLevelArm[]) {
        const ids = arms.map((a) => a.id);
        const allChecked = ids.every((id) => selectedSet.has(id));
        const next = new Set(selectedSet);
        allChecked
            ? ids.forEach((id) => next.delete(id))
            : ids.forEach((id) => next.add(id));
        setFilters(Array.from(next));
    }

    function clearAll() {
        setFilters([]);
    }

    const hasSelection = selected.length > 0;

    return (
        <div className="w-full rounded-xl border border-gray-200 bg-white">
            {/* Header */}
            <button
                onClick={() => setOpen((v) => !v)}
                className="flex w-full items-center justify-between px-4 py-3 text-left"
            >
                <div className="flex items-center gap-2">
                    <span className="text-sm font-semibold text-gray-800">
                        Class Arms
                    </span>
                    {hasSelection && (
                        <span className="flex h-5 min-w-5 items-center justify-center rounded-full bg-indigo-600 px-1.5 text-[10px] font-bold text-white">
                            {selected.length}
                        </span>
                    )}
                </div>
                <div className="flex items-center gap-3">
                    {hasSelection && open && (
                        <span
                            role="button"
                            onClick={(e) => {
                                e.stopPropagation();
                                clearAll();
                            }}
                            className="text-xs font-medium text-indigo-600 hover:text-indigo-800"
                        >
                            Clear all
                        </span>
                    )}
                    <svg
                        className={`h-4 w-4 text-gray-400 transition-transform duration-200 ${open ? 'rotate-180' : ''}`}
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            strokeWidth={2}
                            d="M19 9l-7 7-7-7"
                        />
                    </svg>
                </div>
            </button>

            {/* Scrollable horizontal body */}
            {open && (
                <div className="border-t border-gray-100">
                    {groups.length === 0 ? (
                        <p className="px-4 py-6 text-center text-sm text-gray-400">
                            No class arms available
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <div className="flex min-w-max divide-x divide-gray-100 px-1 py-2">
                                {groups.map(({ classLevel, arms }) => {
                                    const groupIds = arms.map((a) => a.id);
                                    const checkedCount = groupIds.filter((id) =>
                                        selectedSet.has(id),
                                    ).length;
                                    const allChecked =
                                        checkedCount === arms.length;
                                    const someChecked =
                                        checkedCount > 0 && !allChecked;

                                    return (
                                        <div
                                            key={classLevel.id}
                                            className="flex min-w-[7rem] flex-col gap-1 px-3"
                                        >
                                            {/* Class level header — acts as group checkbox */}
                                            <label className="flex cursor-pointer items-center gap-2 pb-1">
                                                <input
                                                    type="checkbox"
                                                    checked={allChecked}
                                                    ref={(el) => {
                                                        if (el) {
                                                            el.indeterminate =
                                                                someChecked;
                                                        }
                                                    }}
                                                    onChange={() =>
                                                        toggleGroup(arms)
                                                    }
                                                    className="h-3.5 w-3.5 cursor-pointer rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                />
                                                <span className="text-xs font-semibold text-gray-700">
                                                    {classLevel.name}
                                                </span>
                                                {checkedCount > 0 && (
                                                    <span className="ml-auto text-[10px] text-gray-400">
                                                        {checkedCount}/
                                                        {arms.length}
                                                    </span>
                                                )}
                                            </label>

                                            {/* Arm checkboxes */}
                                            {arms.map((cla) => (
                                                <label
                                                    key={cla.id}
                                                    className="flex cursor-pointer items-center gap-2 rounded-md px-1 py-1 hover:bg-gray-50"
                                                >
                                                    <input
                                                        type="checkbox"
                                                        checked={selectedSet.has(
                                                            cla.id,
                                                        )}
                                                        onChange={() =>
                                                            toggle(cla.id)
                                                        }
                                                        className="h-3.5 w-3.5 cursor-pointer rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                    />
                                                    <span className="text-xs text-gray-600">
                                                        {cla.arm.label}
                                                    </span>
                                                </label>
                                            ))}
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
