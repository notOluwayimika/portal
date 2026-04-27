import { Head } from '@inertiajs/react';
import AcademicSessions from '@/components/academic-sessions';

export default function Sessions() {
    return (
        <>
            <Head title="Sessions" />

            <h1 className="sr-only">Sessions</h1>

            <AcademicSessions />
        </>
    );
}

Sessions.layout = {
    breadcrumbs: [
        {
            title: 'Sessions',
            href: '/settings',
            // href: edit(),
        },
    ],
};
