import PendingSubjectResults from '@/components/pending-subject-results';

export default function Pending() {
    return (
        <div className="mx-auto max-w-7xl space-y-6 overflow-scroll p-6">
            <div>
                <h1 className="text-xl font-semibold text-gray-900">
                    Pending Results
                </h1>
                <p className="mt-1 text-sm text-gray-600">
                    Results yet to be submitted.
                </p>
            </div>
            <PendingSubjectResults />
        </div>
    );
}
