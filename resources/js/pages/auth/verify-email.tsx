// Components
import { Form, Head } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';

/**
 * Fortify's `verification.send` endpoint, written out rather than imported from
 * `@/routes/verification`.
 *
 * Email verification is intentionally OFF (config/fortify.php — registration is
 * disabled and users are administrator-created), so Fortify never registers the
 * route, wayfinder therefore never generates the module, and importing it crashed
 * this page at module load. This view is still registered
 * (FortifyServiceProvider::verifyEmailView) but is unreachable while the feature is
 * off, so the affordance below is inert by design rather than broken.
 *
 * When `Features::emailVerification()` is switched on, wayfinder will emit
 * `@/routes/verification` again — swap this back for the generated `send.form()`.
 */
const verificationSend = {
    action: '/email/verification-notification',
    method: 'post',
} as const;

export default function VerifyEmail({ status }: { status?: string }) {
    return (
        <>
            <Head title="Email verification" />

            {status === 'verification-link-sent' && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    A new verification link has been sent to the email address
                    you provided during registration.
                </div>
            )}

            <Form {...verificationSend} className="space-y-6 text-center">
                {({ processing }) => (
                    <>
                        <Button disabled={processing} variant="secondary">
                            {processing && <Spinner />}
                            Resend verification email
                        </Button>

                        <TextLink
                            href={logout()}
                            className="mx-auto block text-sm"
                        >
                            Log out
                        </TextLink>
                    </>
                )}
            </Form>
        </>
    );
}

VerifyEmail.layout = {
    title: 'Verify email',
    description:
        'Please verify your email address by clicking on the link we just emailed to you.',
};
