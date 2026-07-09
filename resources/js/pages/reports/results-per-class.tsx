import { Link, usePage } from '@inertiajs/react';
import type { ClassLevel } from '@/types/models';

interface ClassLevelPageProps {
    classLevels: { data: ClassLevel[] };
    [key: string]: unknown;
}

export default function ResultsPerClass() {
    const { classLevels } = usePage<ClassLevelPageProps>().props;
    const classLevelsData = classLevels.data;
    const sorted = [...classLevelsData].sort((a, b) => a.order - b.order);

    return (
        <div className="flex flex-col gap-2.5 p-4">
            {/* Heading */}
            <h2 className="text-lg font-semibold text-gray-900">
                Results per Class
            </h2>
            {sorted.map((level) => (
                <div
                    key={level.id}
                    className="flex items-center justify-between gap-4 rounded-xl border border-gray-200 bg-white px-5 py-4 transition-colors hover:border-gray-300"
                >
                    <div className="flex items-center gap-4">
                        <span className="w-16 text-sm font-medium text-gray-900">
                            {level.name}
                        </span>
                        <div className="flex flex-wrap gap-1.5">
                            {level?.class_level_arms?.map((cla) => (
                                <a
                                    key={cla.id}
                                    target="_blank"
                                    href={`/class-level-arm/${cla.id}/results`}
                                    title={`${level.name} ${cla.arm.label}${cla.stream ? ` ${cla.stream.name}` : ''} results`}
                                    className="inline-flex h-7 min-w-7 items-center justify-center rounded-md border border-gray-200 bg-gray-50 px-1 text-xs font-medium text-gray-500 transition-colors hover:border-blue-300 hover:bg-blue-50 hover:text-blue-600"
                                >
                                    {cla.arm.label}
                                    {cla.stream ? ` ${cla.stream.name}` : ''}
                                </a>
                            ))}
                        </div>
                    </div>

                    <a
                        target="_blank"
                        href={`/class-level/${level.id}/results`}
                        className="inline-flex items-center gap-1.5 rounded-md border border-blue-200 px-3 py-1.5 text-xs whitespace-nowrap text-blue-600 transition-colors hover:bg-blue-50"
                    >
                        Results
                    </a>
                </div>
            ))}
        </div>
    );
}
