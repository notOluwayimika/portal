import { Head } from '@inertiajs/react';
import axios from 'axios';
import { Archive, Pencil, Plus } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import Modal from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/spinner';
import { formatNaira, minorToNairaInput, nairaToMinor } from '@/lib/format';

/**
 * The school's discount policies — the catalog every reduction on an invoice has to cite (U2).
 *
 * WHY THE SCREEN EXISTS. Every endpoint behind it already shipped, and the reduction trigger
 * (`finance_invoice_lines_reduction_guard`) refuses a reduction line that names no policy. With no way
 * to author one there were zero policies, so a scholarship could only be handled by credit-noting a
 * full-price invoice — three approvals a term per student, and a parent shown the full fee with a
 * correction underneath instead of their actual bill.
 *
 * FOUR ACTS AND NO FIFTH: list, propose a new policy, propose an amendment, propose a retirement.
 * THERE IS NO APPROVE AND NO REJECT HERE — that is /finance/approvals, and a second home for the ED's
 * decision is a second place for it to disagree with itself. Nothing on this page writes a policy:
 * all three proposals POST /api/v1/finance/discount-policy-changes and wait, and
 * ApproveDiscountPolicyChange is the only writer of `finance_discount_policies` (an arch test says so).
 *
 * RETIRED AND SUPERSEDED ROWS STAY ON THE LIST. A policy that priced an invoice must remain nameable
 * forever — the same argument bank accounts uses for having no delete, and the same one the table
 * itself makes with a no-DELETE trigger and a name unique scoped to the ACTIVE state. They are shown
 * below the active ones, without controls.
 *
 * THE FRONTEND COMPUTES NO MONEY. An amount policy's value goes in through nairaToMinor and is read
 * back through formatNaira/minorToNairaInput; a percent policy is NOT money and touches neither — it
 * is an integer 1..100 (`unsignedTinyInteger`, CHECK-bounded in the migration). There is no `* 100`,
 * no parseFloat, no toFixed and no Intl in this file; bin/ci-money-lint.php is a gate step.
 */

type PolicyStatus = 'active' | 'superseded' | 'retired';

type Basis = 'amount' | 'percent';

// DiscountPolicyResource's shape. `value_minor`/`value_currency` are the amount basis's two halves and
// arrive UNWRAPPED (that resource does not cast through Money) — they are paired back into the wire
// shape at the one place they are rendered, in valueLabel() below.
type DiscountPolicy = {
    id: string;
    name: string;
    description: string | null;
    basis: Basis;
    value_minor: number | null;
    value_currency: string | null;
    percent: number | null;
    requires_approval: boolean;
    status: PolicyStatus;
};

type Proposal =
    | { kind: 'create'; policy: null }
    | { kind: 'amend'; policy: DiscountPolicy }
    | { kind: 'retire'; policy: DiscountPolicy };

type FormState = {
    name: string;
    description: string;
    basis: Basis;
    // As typed, in naira — converted at submit by nairaToMinor. Only read when basis = 'amount'.
    amount: string;
    // As typed. An integer 1..100, and NOT money: it never goes near nairaToMinor.
    percent: string;
    requiresApproval: boolean;
    reason: string;
};

const EMPTY: FormState = {
    name: '',
    description: '',
    basis: 'percent',
    amount: '',
    percent: '',
    requiresApproval: false,
    reason: '',
};

/**
 * What an amount policy is denominated in. This form authors nothing else, and the currency is posted
 * explicitly rather than left to a server default — `value_currency` is NOT cast through Money on this
 * table, so a missing or malformed one persists into an append-only row and fails much later, at
 * whatever tries to apply the policy (SubmitDiscountPolicyChangeRequest:36-40 says the same thing from
 * the other side).
 */
const DEFAULT_CURRENCY = 'NGN';

type ErrorBag = { fields: Record<string, string>; message: string | null };

const NO_ERRORS: ErrorBag = { fields: {}, message: null };

const STATUS_LABEL: Record<PolicyStatus, string> = {
    active: 'Active',
    superseded: 'Superseded',
    retired: 'Retired',
};

const STATUS_CLASS: Record<PolicyStatus, string> = {
    active: 'bg-emerald-100 text-emerald-800',
    superseded: 'bg-muted text-muted-foreground',
    retired: 'bg-muted text-muted-foreground',
};

const SELECT_CLASS =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs';

function parseErrorBag(err: unknown): ErrorBag | null {
    if (!axios.isAxiosError(err) || err.response?.status !== 422) {
        return null;
    }

    const bag = (err.response.data?.errors ?? {}) as Record<string, unknown>;
    const fields: Record<string, string> = {};

    for (const [key, value] of Object.entries(bag)) {
        fields[key] = Array.isArray(value) ? String(value[0]) : String(value);
    }

    return {
        fields,
        // The Action's friendly refusals — an already-open request for this policy, an empty reason —
        // are a 422 with a `message` and NO bag. Without this the modal would report nothing at all.
        message:
            typeof err.response.data?.message === 'string' &&
            Object.keys(bag).length === 0
                ? err.response.data.message
                : null,
    };
}

/** How a policy reduces a bill, in the operator's words. Amount through formatNaira; percent is not money. */
function valueLabel(policy: DiscountPolicy): string {
    if (policy.basis === 'percent') {
        return policy.percent === null
            ? '—'
            : `${policy.percent}% of discountable charges`;
    }

    return policy.value_minor === null || policy.value_currency === null
        ? '—'
        : formatNaira({
              amount_minor: policy.value_minor,
              currency: policy.value_currency,
          });
}

export default function DiscountPolicies() {
    const [policies, setPolicies] = useState<DiscountPolicy[]>([]);
    const [loading, setLoading] = useState(true);
    const [proposal, setProposal] = useState<Proposal | null>(null);
    const [form, setForm] = useState<FormState>(EMPTY);
    const [errors, setErrors] = useState<ErrorBag>(NO_ERRORS);
    const [submitting, setSubmitting] = useState(false);

    const load = useCallback(async () => {
        setLoading(true);

        try {
            // NO `status` PARAMETER, deliberately: absent means unfiltered, and a superseded or retired
            // policy is provenance this screen has to keep showing. A caller that wants only the
            // choosable ones asks for `?status=active`.
            const { data } = await axios.get(
                '/api/v1/finance/discount-policies',
            );
            setPolicies(data ?? []);
        } catch {
            toast.error('Could not load the discount policies.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        // The initial fetch is this effect's whole purpose. NO eslint-disable here, unlike the sibling
        // pages (bank-accounts.tsx:72): `react-hooks/set-state-in-effect` does not fire on this one, and
        // eslint reports an unused directive as a warning — a disable comment copied across for
        // symmetry is a claim about a rule that is not being broken.
        void load();
    }, [load]);

    const openCreate = () => {
        setForm(EMPTY);
        setErrors(NO_ERRORS);
        setProposal({ kind: 'create', policy: null });
    };

    const openAmend = (policy: DiscountPolicy) => {
        // Prefilled from the policy being superseded, because an amendment is authored FROM the current
        // terms — an empty form would make "change the rate" into "retype the whole policy", which is
        // how a description or a requires_approval flag silently changes with it.
        setForm({
            name: policy.name,
            description: policy.description ?? '',
            basis: policy.basis,
            amount:
                policy.value_minor !== null && policy.value_currency !== null
                    ? minorToNairaInput({
                          amount_minor: policy.value_minor,
                          currency: policy.value_currency,
                      })
                    : '',
            percent: policy.percent === null ? '' : String(policy.percent),
            requiresApproval: policy.requires_approval,
            reason: '',
        });
        setErrors(NO_ERRORS);
        setProposal({ kind: 'amend', policy });
    };

    const openRetire = (policy: DiscountPolicy) => {
        setForm({ ...EMPTY, reason: '' });
        setErrors(NO_ERRORS);
        setProposal({ kind: 'retire', policy });
    };

    /**
     * The client half of the amount-XOR-percent rule. The schema is the authority — the policies table
     * carries a basis-exclusive CHECK and the change table repeats it in `…_terms_shape` — and the
     * FormRequest refuses a cross combo with a 422 before either is reached. This exists so an operator
     * cannot SUBMIT both or neither in the first place: the server refusing it anyway is the guarantee,
     * this is the form not wasting their time.
     */
    const localErrors = (): Record<string, string> => {
        if (proposal?.kind === 'retire') {
            return {};
        }

        const found: Record<string, string> = {};

        if (form.name.trim() === '') {
            found.name =
                'A policy needs a name — this is what a bursar picks it by.';
        }

        if (form.basis === 'amount') {
            const minor = nairaToMinor(form.amount);

            if (minor === null || minor < 1) {
                found.value_minor =
                    'Enter the amount taken off, in naira — for example 25000 or 2500.50.';
            }
        } else if (!/^\d{1,3}$/.test(form.percent.trim())) {
            found.percent = 'Enter a whole percentage between 1 and 100.';
        } else {
            const percent = Number(form.percent.trim());

            if (percent < 1 || percent > 100) {
                found.percent = 'Enter a whole percentage between 1 and 100.';
            }
        }

        return found;
    };

    const send = async () => {
        if (!proposal) {
            return;
        }

        const local = localErrors();

        if (form.reason.trim() === '') {
            local.reason =
                'The executive director reads this, and it is the only context they get.';
        }

        if (Object.keys(local).length > 0) {
            setErrors({ fields: local, message: null });

            return;
        }

        setSubmitting(true);
        setErrors(NO_ERRORS);

        // ONE SIDE OF THE BASIS IS POSTED, NEVER BOTH. `value_minor`/`value_currency` are
        // `prohibited_if:basis,percent` and `percent` is `prohibited_if:basis,amount`, so sending the
        // unused half — even empty — is a 422 rather than a tidy no-op.
        const terms =
            proposal.kind === 'retire'
                ? {}
                : {
                      name: form.name.trim(),
                      description:
                          form.description.trim() === ''
                              ? null
                              : form.description.trim(),
                      basis: form.basis,
                      requires_approval: form.requiresApproval,
                      ...(form.basis === 'amount'
                          ? {
                                value_minor: nairaToMinor(form.amount),
                                value_currency: DEFAULT_CURRENCY,
                            }
                          : { percent: Number(form.percent.trim()) }),
                  };

        try {
            await axios.post('/api/v1/finance/discount-policy-changes', {
                kind: proposal.kind,
                ...(proposal.policy ? { target: proposal.policy.id } : {}),
                reason: form.reason.trim(),
                ...terms,
            });

            toast.success(
                proposal.kind === 'retire'
                    ? 'Retirement sent to the executive director.'
                    : proposal.kind === 'amend'
                      ? 'Amendment sent to the executive director.'
                      : 'New policy sent to the executive director.',
            );
            setProposal(null);
            await load();
        } catch (err: unknown) {
            const bag = parseErrorBag(err);

            if (bag) {
                setErrors(bag);

                if (bag.message) {
                    toast.error(bag.message);
                }
            } else {
                toast.error('Could not send this for approval.');
            }
        } finally {
            setSubmitting(false);
        }
    };

    const active = policies.filter((p) => p.status === 'active');
    const closed = policies.filter((p) => p.status !== 'active');

    const row = (policy: DiscountPolicy) => (
        <tr
            key={policy.id}
            className="border-t"
            data-testid="discount-policy-row"
        >
            <td className="p-2">
                <span className="font-medium">{policy.name}</span>
                {policy.description && (
                    <span className="block text-xs text-muted-foreground">
                        {policy.description}
                    </span>
                )}
            </td>
            <td className="p-2">
                {policy.basis === 'amount' ? 'Fixed amount' : 'Percentage'}
            </td>
            <td className="p-2">{valueLabel(policy)}</td>
            <td className="p-2">
                {/*
                 * THE FIELD THIS SCREEN LIVES OR DIES ON, said in the list as well as in the form —
                 * "Requires approval: yes" tells a reader nothing about what it DOES.
                 */}
                {policy.requires_approval ? (
                    <span className="text-amber-800">
                        Each award needs the ED&rsquo;s sign-off — raised as a
                        credit note, never as an invoice line
                    </span>
                ) : (
                    <span className="text-muted-foreground">
                        A bursar applies it directly on the invoice
                    </span>
                )}
            </td>
            <td className="p-2">
                <span
                    className={`rounded-full px-2 py-0.5 text-xs ${STATUS_CLASS[policy.status]}`}
                >
                    {STATUS_LABEL[policy.status]}
                </span>
            </td>
            <td className="p-2 text-right">
                {policy.status === 'active' ? (
                    <>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => openAmend(policy)}
                        >
                            <Pencil className="mr-1 h-3.5 w-3.5" />
                            Amend
                        </Button>{' '}
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => openRetire(policy)}
                        >
                            <Archive className="mr-1 h-3.5 w-3.5" />
                            Retire
                        </Button>
                    </>
                ) : (
                    <span className="text-xs text-muted-foreground">
                        Kept for the invoices it priced
                    </span>
                )}
            </td>
        </tr>
    );

    const table = (rows: DiscountPolicy[]) => (
        <div className="overflow-x-auto rounded-md border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-left">
                    <tr>
                        <th className="p-2">Policy</th>
                        <th className="p-2">Basis</th>
                        <th className="p-2">Value</th>
                        <th className="p-2">How it is applied</th>
                        <th className="p-2">Status</th>
                        <th className="p-2 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>{rows.map(row)}</tbody>
            </table>
        </div>
    );

    return (
        <>
            <Head title="Discount policies" />

            <div className="space-y-4 p-4">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold">
                            Discount policies
                        </h1>
                        <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
                            Every reduction on an invoice has to name one of
                            these. Nothing here changes until the executive
                            director approves it — a new policy, a change to an
                            existing one and a retirement are all proposals, and
                            a policy&rsquo;s terms never change once approved:
                            an amendment writes a new policy and supersedes the
                            old one, so the invoices the old one priced stay
                            explainable.
                        </p>
                    </div>
                    <Button onClick={openCreate}>
                        <Plus className="mr-1 h-4 w-4" />
                        Propose a policy
                    </Button>
                </div>

                {loading ? (
                    <div className="flex justify-center p-8">
                        <Spinner />
                    </div>
                ) : policies.length === 0 ? (
                    <p className="rounded-md border border-dashed p-8 text-center text-sm text-muted-foreground">
                        No discount policies yet. Until one is approved, no
                        invoice can carry a discount at all — a reduction has to
                        cite a policy.
                    </p>
                ) : (
                    <div className="space-y-6">
                        {active.length > 0 && table(active)}

                        {closed.length > 0 && (
                            <div className="space-y-2">
                                <h2 className="text-sm font-semibold">
                                    No longer in use
                                </h2>
                                <p className="max-w-2xl text-xs text-muted-foreground">
                                    Superseded and retired policies stay here
                                    for good. Each one may still be the only
                                    thing that explains a discount on an invoice
                                    issued months ago, so none of them can be
                                    deleted.
                                </p>
                                {table(closed)}
                            </div>
                        )}
                    </div>
                )}
            </div>

            <Modal
                isOpen={proposal !== null}
                onClose={() => setProposal(null)}
                title={
                    proposal?.kind === 'retire'
                        ? `Retire ${proposal.policy.name}`
                        : proposal?.kind === 'amend'
                          ? `Amend ${proposal.policy.name}`
                          : 'Propose a discount policy'
                }
                size="lg"
            >
                <div className="space-y-4">
                    {errors.message && (
                        <p className="rounded-md border border-destructive/40 bg-destructive/5 p-2 text-sm text-destructive">
                            {errors.message}
                        </p>
                    )}

                    {proposal?.kind === 'retire' ? (
                        <p className="text-sm text-muted-foreground">
                            The executive director decides. Until they approve,
                            this policy keeps working — retiring it withdraws it
                            from choice on future invoices and changes nothing
                            about the ones it already priced.
                        </p>
                    ) : (
                        <>
                            {proposal?.kind === 'amend' && (
                                <p className="rounded-md bg-muted/50 p-3 text-sm text-muted-foreground">
                                    A policy&rsquo;s terms are immutable, so
                                    this does not edit{' '}
                                    <span className="font-medium">
                                        {proposal.policy.name}
                                    </span>
                                    . On approval the executive director
                                    supersedes it and a new policy takes its
                                    place; the old one stays on the list,
                                    unchanged, explaining every invoice it
                                    priced.
                                </p>
                            )}

                            <div>
                                <Label htmlFor="dp-name">Name</Label>
                                <Input
                                    id="dp-name"
                                    value={form.name}
                                    maxLength={255}
                                    placeholder="Sibling discount"
                                    onChange={(e) =>
                                        setForm({
                                            ...form,
                                            name: e.target.value,
                                        })
                                    }
                                />
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    What a bursar will pick it by, and what a
                                    parent may see beside the reduction.
                                </p>
                                {errors.fields.name && (
                                    <p className="mt-0.5 text-xs text-destructive">
                                        {errors.fields.name}
                                    </p>
                                )}
                            </div>

                            <div>
                                <Label htmlFor="dp-description">
                                    Description (optional)
                                </Label>
                                <Input
                                    id="dp-description"
                                    value={form.description}
                                    maxLength={500}
                                    placeholder="Who qualifies, and on what evidence."
                                    onChange={(e) =>
                                        setForm({
                                            ...form,
                                            description: e.target.value,
                                        })
                                    }
                                />
                                {errors.fields.description && (
                                    <p className="mt-0.5 text-xs text-destructive">
                                        {errors.fields.description}
                                    </p>
                                )}
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="dp-basis">Basis</Label>
                                    <select
                                        id="dp-basis"
                                        className={SELECT_CLASS}
                                        value={form.basis}
                                        onChange={(e) =>
                                            setForm({
                                                ...form,
                                                basis: e.target.value as Basis,
                                            })
                                        }
                                    >
                                        <option value="percent">
                                            A percentage of the bill
                                        </option>
                                        <option value="amount">
                                            A fixed amount off
                                        </option>
                                    </select>
                                    {errors.fields.basis && (
                                        <p className="mt-0.5 text-xs text-destructive">
                                            {errors.fields.basis}
                                        </p>
                                    )}
                                </div>

                                {/*
                                 * ONE FIELD OR THE OTHER, NEVER BOTH — the schema says so (the
                                 * basis-exclusive CHECK), so the form shows only the one the chosen
                                 * basis uses rather than greying the other out. A percentage is not
                                 * money and never goes near the naira helpers.
                                 */}
                                {form.basis === 'amount' ? (
                                    <div>
                                        <Label htmlFor="dp-amount">
                                            Amount off (₦)
                                        </Label>
                                        <Input
                                            id="dp-amount"
                                            value={form.amount}
                                            inputMode="decimal"
                                            placeholder="25000"
                                            onChange={(e) =>
                                                setForm({
                                                    ...form,
                                                    amount: e.target.value,
                                                })
                                            }
                                        />
                                        {(errors.fields.value_minor ??
                                            errors.fields.value_currency) && (
                                            <p className="mt-0.5 text-xs text-destructive">
                                                {errors.fields.value_minor ??
                                                    errors.fields
                                                        .value_currency}
                                            </p>
                                        )}
                                    </div>
                                ) : (
                                    <div>
                                        <Label htmlFor="dp-percent">
                                            Percentage off (%)
                                        </Label>
                                        <Input
                                            id="dp-percent"
                                            value={form.percent}
                                            inputMode="numeric"
                                            placeholder="10"
                                            onChange={(e) =>
                                                setForm({
                                                    ...form,
                                                    percent: e.target.value,
                                                })
                                            }
                                        />
                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                            A whole number from 1 to 100,
                                            applied to the discountable charges
                                            on the bill.
                                        </p>
                                        {errors.fields.percent && (
                                            <p className="mt-0.5 text-xs text-destructive">
                                                {errors.fields.percent}
                                            </p>
                                        )}
                                    </div>
                                )}
                            </div>

                            {/*
                             * requires_approval, SPELLED OUT BESIDE THE CONTROL AND NOT IN A TOOLTIP.
                             * Its name says nothing about what it does, and both wrong answers are
                             * expensive: false on a scholarship hands out an unbounded reduction on one
                             * person's judgement, true on a routine discount buries the ED in
                             * signatures until they stop reading them. The wording is the behaviour,
                             * not a paraphrase of it: with `true` the reduction trigger REFUSES the
                             * discount as an invoice line outright ("apply it as a credit note, not an
                             * invoice line"), so every award goes through the credit-note
                             * maker-checker.
                             */}
                            <fieldset className="space-y-2">
                                <legend className="text-sm font-medium">
                                    How is it applied?
                                </legend>

                                {(
                                    [
                                        [
                                            false,
                                            'A bursar applies it directly',
                                            'The discount goes straight onto the invoice as a line. Right for a standing arrangement — a sibling discount, a staff rate — where the rule decides and nobody needs to look at the individual case.',
                                        ],
                                        [
                                            true,
                                            'Every award needs the executive director',
                                            'The discount cannot go on an invoice at all. Each award is raised as a credit note and the ED approves it one student at a time. Right for a scholarship or a hardship award, where each decision is about the student rather than about the rule.',
                                        ],
                                    ] as const
                                ).map(([value, title, blurb]) => (
                                    <label
                                        key={String(value)}
                                        className={`flex cursor-pointer gap-3 rounded-md border p-3 ${
                                            form.requiresApproval === value
                                                ? 'border-primary bg-muted/40'
                                                : 'border-input'
                                        }`}
                                    >
                                        <input
                                            type="radio"
                                            className="mt-1"
                                            name="dp-requires-approval"
                                            checked={
                                                form.requiresApproval === value
                                            }
                                            onChange={() =>
                                                setForm({
                                                    ...form,
                                                    requiresApproval: value,
                                                })
                                            }
                                        />
                                        <span>
                                            <span className="block text-sm font-medium">
                                                {title}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {blurb}
                                            </span>
                                        </span>
                                    </label>
                                ))}

                                {errors.fields.requires_approval && (
                                    <p className="text-xs text-destructive">
                                        {errors.fields.requires_approval}
                                    </p>
                                )}
                            </fieldset>
                        </>
                    )}

                    <div>
                        <Label htmlFor="dp-reason">Reason</Label>
                        <Input
                            id="dp-reason"
                            value={form.reason}
                            maxLength={255}
                            placeholder="Why this, and why now — the ED reads this."
                            onChange={(e) =>
                                setForm({ ...form, reason: e.target.value })
                            }
                        />
                        {errors.fields.reason && (
                            <p className="mt-0.5 text-xs text-destructive">
                                {errors.fields.reason}
                            </p>
                        )}
                    </div>

                    <div className="flex justify-end gap-2 border-t pt-3">
                        <Button
                            variant="outline"
                            onClick={() => setProposal(null)}
                        >
                            Cancel
                        </Button>
                        <Button onClick={send} disabled={submitting}>
                            {submitting ? 'Sending…' : 'Send to the ED'}
                        </Button>
                    </div>
                </div>
            </Modal>
        </>
    );
}

DiscountPolicies.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'Discount policies', href: '/finance/discount-policies' },
    ],
};
