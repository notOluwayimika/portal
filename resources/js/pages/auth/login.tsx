import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
};

// Underline-only input — overrides the boxed default
const underlineCls =
    'border-0 border-b border-gray-300 rounded-none px-0 h-12 text-base bg-transparent shadow-none ' +
    'focus-visible:ring-0 focus-visible:ring-offset-0 focus-visible:border-b-2 focus-visible:border-gray-900 ' +
    'placeholder:text-gray-400';

// function GoogleIcon() {
//     return (
//         <svg className="h-4 w-4 shrink-0" viewBox="0 0 24 24" aria-hidden="true">
//             <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4" />
//             <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853" />
//             <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z" fill="#FBBC05" />
//             <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335" />
//         </svg>
//     );
// }

// function AppleIcon() {
//     return (
//         <svg className="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
//             <path d="M12.152 6.896c-.948 0-2.415-1.078-3.96-1.04-2.04.027-3.91 1.183-4.961 3.014-2.117 3.675-.546 9.103 1.519 12.09 1.013 1.454 2.208 3.09 3.792 3.039 1.52-.065 2.09-.987 3.935-.987 1.831 0 2.35.987 3.96.948 1.637-.026 2.676-1.48 3.676-2.948 1.156-1.688 1.636-3.325 1.662-3.415-.039-.013-3.182-1.221-3.22-4.857-.026-3.04 2.48-4.494 2.597-4.559-1.429-2.09-3.623-2.324-4.39-2.376-2-.156-3.675 1.09-4.61 1.09z" />
//             <path d="M15.53 3.83c.843-1.012 1.4-2.427 1.245-3.83-1.207.052-2.662.805-3.532 1.818-.78.896-1.454 2.338-1.273 3.714 1.338.104 2.715-.688 3.559-1.701z" />
//         </svg>
//     );
// }

// function FacebookIcon() {
//     return (
//         <svg className="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="#1877F2" aria-hidden="true">
//             <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z" />
//         </svg>
//     );
// }

// const socialProviders = [
//     { label: 'Google',   icon: <GoogleIcon /> },
//     { label: 'Apple ID', icon: <AppleIcon /> },
//     { label: 'Facebook', icon: <FacebookIcon /> },
// ];

export default function Login({ status, canResetPassword, canRegister }: Props) {
    const [showPassword, setShowPassword] = useState(false);

    return (
        <>
            <Head title="Log in" />

            {status && (
                <p className="mb-4 text-center text-sm font-medium text-green-600">{status}</p>
            )}

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-5 sm:gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        {/* Email */}
                        <div className="space-y-1">
                            <Label htmlFor="email" className="sr-only">
                                Email address
                            </Label>
                            <div className="relative">
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="email"
                                    placeholder="Enter Email / Phone No"
                                    className={underlineCls + ' pr-8'}
                                />
                            </div>
                            <InputError message={errors.email} />
                        </div>

                        {/* Password */}
                        <div className="space-y-1">
                            <Label htmlFor="password" className="sr-only">
                                Password
                            </Label>
                            <div className="relative">
                                <Input
                                    id="password"
                                    type={showPassword ? 'text' : 'password'}
                                    name="password"
                                    required
                                    tabIndex={2}
                                    autoComplete="current-password"
                                    placeholder="Passcode"
                                    className={underlineCls + ' pr-14 text-base [font-size:16px]'}
                                />
                                <button
                                    type="button"
                                    onClick={() => setShowPassword((v) => !v)}
                                    tabIndex={-1}
                                    className="absolute right-0 inset-y-0 text-sm font-medium text-gray-600 hover:text-gray-900 focus-visible:outline-none focus-visible:underline"
                                    aria-label={showPassword ? 'Hide password' : 'Show password'}
                                >
                                    {showPassword ? 'Hide' : 'Show'}
                                </button>
                            </div>
                            <InputError message={errors.password} />
                        </div>

                        {/* Trouble link */}
                        {canResetPassword && (
                            <div>
                                <TextLink
                                    href={request()}
                                    className="text-sm font-semibold text-gray-800"
                                    tabIndex={3}
                                >
                                    Having trouble signing in?
                                </TextLink>
                            </div>
                        )}

                        {/* Submit */}
                        <button
                            type="submit"
                            tabIndex={4}
                            disabled={processing}
                            className="flex h-12 w-full items-center justify-center rounded-lg bg-primary text-base font-semibold text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-70 sm:h-[52px]"
                        >
                            {processing ? <Spinner className="h-5 w-5" /> : 'Sign in'}
                        </button>

                        {/* Social divider */}
                        {/* <div className="flex items-center gap-3">
                            <div className="h-px flex-1 bg-gray-200" />
                            <span className="text-xs text-gray-400">Or Sign in with</span>
                            <div className="h-px flex-1 bg-gray-200" />
                        </div> */}

                        {/* Social buttons */}
                        {/* <div className="flex flex-col gap-2.5 min-[480px]:flex-row">
                            {socialProviders.map(({ label, icon }, i) => (
                                <button
                                    key={label}
                                    type="button"
                                    tabIndex={5 + i}
                                    className="flex h-11 flex-1 items-center justify-center gap-2 rounded-lg border border-gray-900 bg-white text-sm font-medium text-gray-900 transition-colors hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
                                >
                                    {icon}
                                    {label}
                                </button>
                            ))}
                        </div> */}

                        {/* Footer */}
                        {/* {canRegister && (
                            <p className="text-center text-sm text-gray-500">
                                Don&apos;t have an account?{' '}
                                <TextLink
                                    href={register()}
                                    className="font-semibold text-gray-900"
                                    tabIndex={8}
                                >
                                    Request Now
                                </TextLink>
                            </p>
                        )} */}
                    </>
                )}
            </Form>
        </>
    );
}

Login.layout = {
    title: 'Brookstone School',
    description: 'Nurturing excellence, developing leaders, and inspiring a lifelong love of learning',
};
