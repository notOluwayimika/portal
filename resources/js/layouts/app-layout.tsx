import { Slide, ToastContainer } from 'react-toastify';
import ImpersonationBanner from '@/components/impersonation-banner';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import type { BreadcrumbItem } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs}>
            {/* Above the page content, on every page: while impersonating, the
                entire UI is the target's and this is the only thing that says
                so — and the only way back out. */}
            <ImpersonationBanner />
            {children}
            <ToastContainer
                position="bottom-right"
                autoClose={5000}
                hideProgressBar={false}
                newestOnTop={false}
                closeOnClick={false}
                rtl={false}
                pauseOnFocusLoss
                draggable
                pauseOnHover
                theme="light"
                transition={Slide}
                icon={false}
            />
        </AppLayoutTemplate>
    );
}
