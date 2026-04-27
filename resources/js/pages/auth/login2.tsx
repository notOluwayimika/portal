import { Head } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { ToastItem } from '@/components/toast-item';
import type { Toast, ToastType } from '@/components/toast-item';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';
import { request } from '@/routes/password';
import { checkToken, getUserFromToken } from '@/helpers';

type Props = {
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
};
interface FormState {
    email: string;
    password: string;
}
interface Error {
    message?: string;
    errors?: {
        email?: string[];
        password?: string[];
    };
}
export default function Login({
    status,
    canResetPassword,
    canRegister,
}: Props) {
    console.log(checkToken());
    useEffect(() => {
        const checkUser = async () => {
            const user = await getUserFromToken();
            console.log(user);
        };
        checkUser();
    }, []);
    const [form, setForm] = useState<FormState>({
        email: '',
        password: '',
    });
    const [errors, setErrors] = useState<Error['errors']>({});
    const [processing, setProcessing] = useState(false);
    const [toasts, setToasts] = useState<Toast[]>([]);

    const toastCounter = useState(0)[0];
    let toastId = toastCounter;

    function addToast(message: string, type: ToastType = 'success') {
        const id = ++toastId;
        setToasts((t) => [...t, { id, message, type }]);
    }
    function dismissToast(id: number) {
        setToasts((t) => t.filter((x) => x.id !== id));
    }
    const handleSubmit = async () => {
        addToast('Logging in...');
        setProcessing(true);

        try {
            const response = await axios.post('/api/login', {
                email: form.email,
                password: form.password,
            });

            if (response.data.token) {
                localStorage.setItem('token', response.data.token);
                window.location.href = '/dashboard';
            } else {
                addToast(response.data.message, 'error');
                setErrors(response.data.errors);
            }
        } catch (error) {
            addToast('Failed to log in.', 'error');
            console.log(error);
        } finally {
            setProcessing(false);
        }
    };

    return (
        <>
            <Head title="Log in" />

            <form className="flex flex-col gap-6">
                <>
                    <div className="grid gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email address</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                required
                                autoFocus
                                tabIndex={1}
                                onChange={(e) =>
                                    setForm({ ...form, email: e.target.value })
                                }
                                autoComplete="email"
                                placeholder="email@example.com"
                            />
                            <InputError message={errors?.email?.[0]} />
                        </div>

                        <div className="grid gap-2">
                            <div className="flex items-center">
                                <Label htmlFor="password">Password</Label>
                                {canResetPassword && (
                                    <TextLink
                                        href={request()}
                                        className="ml-auto text-sm"
                                        tabIndex={5}
                                    >
                                        Forgot password?
                                    </TextLink>
                                )}
                            </div>
                            <PasswordInput
                                id="password"
                                name="password"
                                required
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        password: e.target.value,
                                    })
                                }
                                tabIndex={2}
                                autoComplete="current-password"
                                placeholder="Password"
                            />
                            <InputError message={errors?.password?.[0]} />
                        </div>

                        <div className="flex items-center space-x-3">
                            <Checkbox
                                id="remember"
                                name="remember"
                                tabIndex={3}
                            />
                            <Label htmlFor="remember">Remember me</Label>
                        </div>

                        <Button
                            type="submit"
                            className="mt-4 w-full"
                            tabIndex={4}
                            disabled={processing}
                            data-test="login-button"
                            onClick={handleSubmit}
                        >
                            {processing && <Spinner />}
                            Log in
                        </Button>
                    </div>

                    {canRegister && (
                        <div className="text-center text-sm text-muted-foreground">
                            Don't have an account?{' '}
                            <TextLink href={register()} tabIndex={5}>
                                Sign up
                            </TextLink>
                        </div>
                    )}
                </>
            </form>

            {status && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    {status}
                </div>
            )}

            <div
                style={{
                    position: 'fixed',
                    bottom: 24,
                    right: 24,
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 8,
                    zIndex: 100,
                    minWidth: 280,
                    maxWidth: 360,
                }}
            >
                {toasts.map((toast) => (
                    <ToastItem
                        key={toast.id}
                        toast={toast}
                        onDismiss={() => dismissToast(toast.id)}
                    />
                ))}
            </div>
        </>
    );
}

Login.layout = {
    title: 'Log in to your account',
    description: 'Enter your email and password below to log in',
};
