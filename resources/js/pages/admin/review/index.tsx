import { usePage } from '@inertiajs/react';
import PendingReviewsPage from '@/components/pending-reviews';
import type { SubjectResultStatus } from '@/types/models';

export default function Index() {
    const { subjectResults }: { subjectResults: SubjectResultStatus[] } =
        usePage().props;

    return <PendingReviewsPage subjectResults={subjectResults.data} />;
}
