import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({ children, title, description }: AuthLayoutProps) {
    return (
        <div
            className="flex min-h-screen flex-col items-center justify-start pt-8 p-4 sm:justify-center sm:p-6"
            style={{ backgroundColor: '#F5F1E8' }}
        >
            <div className="w-full bg-white rounded-2xl px-6 py-8 sm:max-w-[440px] sm:px-9 sm:py-10 lg:max-w-[480px] lg:px-12 lg:py-12">

                {/* School branding */}
                <div className="mb-6 flex flex-col items-center gap-3 text-center">
                    <img
                        src="/assets/images/brookstoneLogo.svg"
                        alt="Brookstone School"
                        className="h-16 w-auto sm:h-20"
                        draggable={false}
                    />
                    <div className="space-y-1">
                        <p className="text-base font-semibold tracking-wide text-gray-900">
                            { title }
                        </p>
                        <p className="text-[11px] italic leading-relaxed text-gray-400" style={{ maxWidth: '32ch' }}>
                           { description }
                        </p>
                    </div>
                </div>
                {children}
            </div>
        </div>
    );
}
