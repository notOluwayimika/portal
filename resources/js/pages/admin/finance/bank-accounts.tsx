import { Head } from '@inertiajs/react';
import axios from 'axios';
import { Ban, Pencil, Plus, RotateCcw } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import Modal from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/spinner';

/**
 * The school's bank accounts — where money lands, and the key a bursar reconciles a statement
 * against. S6/U3 commit 1: this screen exists so that commit 2, which makes `bank_account_id`
 * required on payments and fee items, has something to point at on the day it ships.
 *
 * THERE IS NO DELETE, and its absence is deliberate rather than unfinished. An account that has
 * received money must stay nameable forever — a March payment reconciled against it is
 * unexplainable in September if the account has been erased, and finance_payments is append-only so
 * the reference cannot be rewritten. Deactivating withdraws an account from choice and leaves it
 * readable, which is why a deactivated row stays on this list rather than disappearing from it.
 */

type BankAccount = {
    id: string;
    label: string;
    bank_name: string;
    account_number: string;
    account_name: string | null;
    is_active: boolean;
    deactivated_at: string | null;
};

type FormState = {
    label: string;
    bank_name: string;
    account_number: string;
    account_name: string;
};

const EMPTY: FormState = {
    label: '',
    bank_name: '',
    account_number: '',
    account_name: '',
};

export default function FinanceBankAccounts() {
    const [accounts, setAccounts] = useState<BankAccount[]>([]);
    const [loading, setLoading] = useState(true);
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState<BankAccount | null>(null);
    const [form, setForm] = useState<FormState>(EMPTY);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [submitting, setSubmitting] = useState(false);

    const load = useCallback(async () => {
        setLoading(true);

        try {
            const { data } = await axios.get('/api/v1/finance/bank-accounts');
            setAccounts(data.bank_accounts ?? []);
        } catch {
            toast.error('Could not load bank accounts.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        // Same disable the sibling accounts page carries (finance/index.tsx:95): the initial fetch
        // is the effect's whole purpose, and its loading flag is set synchronously inside it.
        // eslint-disable-next-line react-hooks/set-state-in-effect
        void load();
    }, [load]);

    const openCreate = () => {
        setEditing(null);
        setForm(EMPTY);
        setErrors({});
        setModalOpen(true);
    };

    const openEdit = (account: BankAccount) => {
        setEditing(account);
        setForm({
            label: account.label,
            bank_name: account.bank_name,
            account_number: account.account_number,
            account_name: account.account_name ?? '',
        });
        setErrors({});
        setModalOpen(true);
    };

    const submit = async () => {
        setSubmitting(true);
        setErrors({});

        try {
            if (editing) {
                await axios.patch(
                    `/api/v1/finance/bank-accounts/${editing.id}`,
                    form,
                );
                toast.success('Bank account updated.');
            } else {
                await axios.post('/api/v1/finance/bank-accounts', form);
                toast.success('Bank account added.');
            }

            setModalOpen(false);
            await load();
        } catch (err: unknown) {
            if (axios.isAxiosError(err) && err.response?.status === 422) {
                const bag = err.response.data?.errors ?? {};
                setErrors(
                    Object.fromEntries(
                        Object.entries(bag).map(([k, v]) => [
                            k,
                            Array.isArray(v) ? v[0] : String(v),
                        ]),
                    ),
                );
            } else {
                toast.error('Could not save the bank account.');
            }
        } finally {
            setSubmitting(false);
        }
    };

    // Deactivate and reactivate are the only retirement controls. Neither removes a row.
    const setActive = async (account: BankAccount, active: boolean) => {
        try {
            await axios.post(
                `/api/v1/finance/bank-accounts/${account.id}/${active ? 'reactivate' : 'deactivate'}`,
            );
            toast.success(
                active
                    ? 'Bank account reactivated.'
                    : 'Bank account deactivated.',
            );
            await load();
        } catch {
            toast.error('Could not change the account’s status.');
        }
    };

    return (
        <>
            <Head title="Bank accounts" />

            <div className="space-y-4 p-4">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold">Bank accounts</h1>
                        <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
                            The accounts this school receives money into. The
                            bank name and account number are what a bursar
                            matches against a bank statement, so they are
                            required; the label is what appears everywhere else
                            in the portal.
                        </p>
                    </div>
                    <Button onClick={openCreate}>
                        <Plus className="mr-1 h-4 w-4" />
                        Add account
                    </Button>
                </div>

                {loading ? (
                    <div className="flex justify-center p-8">
                        <Spinner />
                    </div>
                ) : accounts.length === 0 ? (
                    <p className="rounded-md border border-dashed p-8 text-center text-sm text-muted-foreground">
                        No bank accounts yet. Add the account fees are paid
                        into.
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-2">Label</th>
                                    <th className="p-2">Bank</th>
                                    <th className="p-2">Account number</th>
                                    <th className="p-2">Account name</th>
                                    <th className="p-2">Status</th>
                                    <th className="p-2 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {accounts.map((a) => (
                                    <tr
                                        key={a.id}
                                        className="border-t"
                                        data-testid="bank-account-row"
                                    >
                                        <td className="p-2 font-medium">
                                            {a.label}
                                        </td>
                                        <td className="p-2">{a.bank_name}</td>
                                        <td className="p-2 font-mono">
                                            {a.account_number}
                                        </td>
                                        <td className="p-2">
                                            {a.account_name ?? '—'}
                                        </td>
                                        <td className="p-2">
                                            {/*
                                             * A DEACTIVATED ACCOUNT STAYS ON THIS LIST. It is
                                             * withdrawn from choice, not erased — the row is still
                                             * the only thing that can explain a payment reconciled
                                             * against it months ago.
                                             */}
                                            <span
                                                className={
                                                    a.is_active
                                                        ? 'rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800'
                                                        : 'rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground'
                                                }
                                            >
                                                {a.is_active
                                                    ? 'Active'
                                                    : 'Deactivated'}
                                            </span>
                                        </td>
                                        <td className="p-2 text-right">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => openEdit(a)}
                                            >
                                                <Pencil className="mr-1 h-3.5 w-3.5" />
                                                Edit
                                            </Button>{' '}
                                            {a.is_active ? (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        setActive(a, false)
                                                    }
                                                >
                                                    <Ban className="mr-1 h-3.5 w-3.5" />
                                                    Deactivate
                                                </Button>
                                            ) : (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        setActive(a, true)
                                                    }
                                                >
                                                    <RotateCcw className="mr-1 h-3.5 w-3.5" />
                                                    Reactivate
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            <Modal
                isOpen={modalOpen}
                onClose={() => setModalOpen(false)}
                title={editing ? `Edit ${editing.label}` : 'Add a bank account'}
                size="md"
            >
                <div className="space-y-4">
                    {/*
                     * THE IDENTITY FIELDS ARE NOT RENDERED WHEN EDITING — not rendered disabled,
                     * not rendered readonly. A disabled input that still posts its value is not a
                     * guard, and a readonly one still tells the operator the field is theirs to
                     * argue with. On edit they are shown below as plain text, which is what they
                     * are: a fact about the account, not a field.
                     */}
                    {(editing
                        ? ([
                              ['label', 'Label', 'Zenith — Fees'],
                              [
                                  'account_name',
                                  'Account name (optional)',
                                  'Brookstone Schools Ltd',
                              ],
                          ] as const)
                        : ([
                              ['label', 'Label', 'Zenith — Fees'],
                              ['bank_name', 'Bank', 'Zenith Bank'],
                              [
                                  'account_number',
                                  'Account number',
                                  '1234567890',
                              ],
                              [
                                  'account_name',
                                  'Account name (optional)',
                                  'Brookstone Schools Ltd',
                              ],
                          ] as const)
                    ).map(([field, labelText, placeholder]) => (
                        <div key={field}>
                            <Label htmlFor={`ba-${field}`}>{labelText}</Label>
                            <Input
                                id={`ba-${field}`}
                                value={form[field]}
                                placeholder={placeholder}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        [field]: e.target.value,
                                    })
                                }
                            />
                            {errors[field] && (
                                <p className="mt-0.5 text-xs text-destructive">
                                    {errors[field]}
                                </p>
                            )}
                        </div>
                    ))}

                    {editing && (
                        <div className="rounded-md bg-muted/50 p-3 text-sm">
                            <p className="font-medium">
                                {editing.bank_name} · {editing.account_number}
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                The bank and account number cannot be changed —
                                they are what a bank statement is matched
                                against, so changing them would rewrite where
                                past payments went. If this school&rsquo;s
                                banking details have changed, deactivate this
                                account and add a new one.
                            </p>
                        </div>
                    )}

                    <div className="flex justify-end gap-2 border-t pt-3">
                        <Button
                            variant="outline"
                            onClick={() => setModalOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button onClick={submit} disabled={submitting}>
                            {submitting
                                ? 'Saving…'
                                : editing
                                  ? 'Save changes'
                                  : 'Add account'}
                        </Button>
                    </div>
                </div>
            </Modal>
        </>
    );
}

FinanceBankAccounts.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'Bank accounts', href: '/finance/bank-accounts' },
    ],
};
