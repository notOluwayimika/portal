import { Head, useForm } from '@inertiajs/react';
import { ChevronDown, Trash2, UserPlus } from 'lucide-react';
import { useState } from 'react';
import { useApiSweetAlertConfirmation } from '@/hooks/use-sweetalert-confirmation';

interface Principal {
    id: string;
    first_name: string;
    last_name: string;
    email: string;
}

export default function PrincipalsIndex({
    principals,
}: {
    principals: Principal[];
}) {
    const [formOpen, setFormOpen] = useState(false);
    const { confirmAndExecute } = useApiSweetAlertConfirmation();
    const form = useForm({
        first_name: '',
        last_name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post('/setup/principals', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setFormOpen(false);
            },
        });
    };

    const remove = (principal: Principal) => {
        void confirmAndExecute({
            sweetAlertTitle: 'Remove principal?',
            sweetAlertText: `This will remove the principal role from ${principal.first_name} ${principal.last_name}.`,
            sweetAlertIcon: 'warning',
            confirmButtonText: 'Remove',
            showSuccessAlert: false,
            onConfirm: () =>
                new Promise<void>((resolve) => {
                    form.delete(`/setup/principals/${principal.id}`, {
                        preserveScroll: true,
                        onFinish: () => resolve(),
                    });
                }),
        });
    };

    return (
        <div className="space-y-6 p-4">
            <Head title="Principals" />
            <div>
                <h1 className="text-xl font-semibold text-gray-900">
                    Principals
                </h1>
                <p className="text-sm text-gray-500">
                    Create school principals who can review and release
                    active-term results.
                </p>
            </div>

            <div className="rounded-xl border border-gray-200 bg-white shadow-sm">
                <button
                    type="button"
                    onClick={() => setFormOpen((open) => !open)}
                    aria-expanded={formOpen}
                    aria-controls="add-principal-form"
                    className="flex w-full items-center justify-between p-5 text-left font-medium text-gray-900"
                >
                    <span className="flex items-center gap-2">
                        <UserPlus className="h-4 w-4" /> Add principal
                    </span>
                    <ChevronDown
                        className={`h-4 w-4 transition-transform ${formOpen ? 'rotate-180' : ''}`}
                    />
                </button>

                {formOpen && (
                    <form
                        id="add-principal-form"
                        onSubmit={submit}
                        className="border-t border-gray-100 p-5"
                    >
                        <div className="grid gap-4 md:grid-cols-2">
                            {(
                                [
                                    'first_name',
                                    'last_name',
                                    'email',
                                    'password',
                                    'password_confirmation',
                                ] as const
                            ).map((field) => (
                                <label
                                    key={field}
                                    className="space-y-1 text-sm text-gray-700"
                                >
                                    <span>
                                        {field
                                            .split('_')
                                            .map(
                                                (word) =>
                                                    word[0].toUpperCase() +
                                                    word.slice(1),
                                            )
                                            .join(' ')}
                                    </span>
                                    <input
                                        type={
                                            field.includes('password')
                                                ? 'password'
                                                : field === 'email'
                                                  ? 'email'
                                                  : 'text'
                                        }
                                        value={form.data[field]}
                                        onChange={(event) =>
                                            form.setData(
                                                field,
                                                event.target.value,
                                            )
                                        }
                                        className="w-full rounded-md border border-gray-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                                        required
                                    />
                                    {form.errors[field] && (
                                        <span className="text-xs text-red-600">
                                            {form.errors[field]}
                                        </span>
                                    )}
                                </label>
                            ))}
                        </div>
                        <button
                            disabled={form.processing}
                            className="mt-4 rounded-md bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800 disabled:opacity-50"
                        >
                            {form.processing ? 'Saving…' : 'Create principal'}
                        </button>
                    </form>
                )}
            </div>

            <div className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                {principals.length === 0 ? (
                    <p className="p-8 text-center text-sm text-gray-500">
                        No principals have been created.
                    </p>
                ) : (
                    principals.map((principal) => (
                        <div
                            key={principal.id}
                            className="flex items-center justify-between border-b border-gray-100 px-5 py-4 last:border-0"
                        >
                            <div>
                                <p className="font-medium text-gray-900">
                                    {principal.first_name} {principal.last_name}
                                </p>
                                <p className="text-sm text-gray-500">
                                    {principal.email}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => remove(principal)}
                                className="rounded-md p-2 text-red-600 hover:bg-red-50"
                                title="Remove principal role"
                            >
                                <Trash2 className="h-4 w-4" />
                            </button>
                        </div>
                    ))
                )}
            </div>
        </div>
    );
}
