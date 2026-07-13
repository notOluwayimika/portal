import { Head, router } from '@inertiajs/react';
import { Building2, Check } from 'lucide-react';
import { useState } from 'react';
import { Spinner } from '@/components/ui/spinner';
import type { SchoolOption } from '@/types';

type Props = {
    schools: SchoolOption[];
};

export default function SelectSchool({ schools }: Props) {
    const [submitting, setSubmitting] = useState<string | null>(null);

    const choose = (uuid: string) => {
        if (submitting) return;
        setSubmitting(uuid);
        router.post(
            '/select-school',
            { school: uuid },
            { onFinish: () => setSubmitting(null) },
        );
    };

    return (
        <>
            <Head title="Select School" />

            <div className="flex flex-col gap-5">
                <div className="text-center">
                    <h1 className="text-lg font-semibold text-gray-900">
                        Select a school
                    </h1>
                    <p className="mt-1 text-sm text-gray-500">
                        Choose which school you want to log into.
                    </p>
                </div>

                <div className="flex flex-col gap-2">
                    {schools.map((school) => (
                        <button
                            key={school.uuid}
                            type="button"
                            disabled={!!submitting}
                            onClick={() => choose(school.uuid)}
                            className="flex items-center gap-3 rounded-lg border border-gray-200 px-4 py-3 text-left transition hover:border-gray-900 hover:bg-gray-50 disabled:opacity-60"
                        >
                            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-gray-100">
                                <Building2 className="h-4 w-4 text-gray-600" />
                            </span>
                            <span className="flex-1 text-sm font-medium text-gray-900">
                                {school.name}
                            </span>
                            {submitting === school.uuid ? (
                                <Spinner className="h-4 w-4" />
                            ) : school.current ? (
                                <span className="flex items-center gap-1 text-xs font-medium text-green-600">
                                    <Check className="h-3.5 w-3.5" />
                                    Current
                                </span>
                            ) : null}
                        </button>
                    ))}
                </div>
            </div>
        </>
    );
}
