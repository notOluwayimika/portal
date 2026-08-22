import axios from 'axios';
import { Trash2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'react-toastify';
import {
    billableEnrollment,
    generateForStudent,
} from '@/actions/App/Finance/Http/Controllers/InvoiceController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import Modal from '@/components/ui/Modal';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { INVOICE_KIND_LABEL } from '@/lib/finance/invoice-kind';
import { formatNaira, nairaToMinor, sumMinor } from '@/lib/format';
import type {
    BillableEnrollmentInfo,
    DraftLine,
    InvoiceKind,
    SelectablePolicy,
} from '@/types/finance';

type Props = {
    isOpen: boolean;
    onClose: () => void;
    student: { uuid: string; name: string };
    onCreated: () => void;
};

const EMPTY_LINE: DraftLine = {
    description: '',
    amount: '',
    kind: 'charge',
    discountPolicyId: '',
};

// The one shape a native <select> needs here. fee-schedules.tsx:133-134 and discount-policies.tsx
// each carry their own identical copy; this is the third and it is deliberately not extracted,
// because moving it would edit two files this commit has no other business in.
const SELECT_CLASS =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs';

/**
 * Which policies this form will offer.
 *
 * BOTH FILTERS ARE CONVENIENCE, NOT ENFORCEMENT, and the distinction is the whole reason this
 * comment is longer than the function. Nothing here refuses anything. What refuses a reduction
 * citing an unusable policy is, in order:
 *
 *   1. `GenerateInvoiceRequest::assertDiscountPoliciesUsable()` — the server pre-check, which turns
 *      each refusal into a FIELD error keyed to the offending line (U8 commit 3);
 *   2. `finance_invoice_lines_reduction_guard` — the BEFORE INSERT trigger, which is the authority
 *      and the backstop, and which a job, a raw insert or tinker meets exactly as an HTTP request
 *      does (2026_07_26_140002_add_discount_policy_to_finance_lines.php:62-101).
 *
 * A reader who takes this predicate for a guard stops checking that those two still exist, which is
 * how a control gets deleted with everything staying green. It is here so the bursar is not offered
 * a policy that will be bounced back at them, and for no other reason.
 *
 * `status === 'active'` IS REDUNDANT WITH THE REQUEST and kept anyway: the fetch asks for
 * `?status=active`, so the server has already filtered. Repeating it makes this function total over
 * whatever it is actually handed, rather than correct only while the query string holds.
 *
 * `requires_approval` IS NOT REDUNDANT WITH ANYTHING. `?status=active` does not exclude it, and the
 * trigger's third arm refuses an ACTIVE policy that requires per-application approval
 * (…:85-88 — "apply it as a credit note, not an invoice line"). Status alone is necessary and not
 * sufficient, so a list filtered on status alone would offer policies that cannot be applied here.
 */
export function selectablePolicies(
    policies: SelectablePolicy[],
): SelectablePolicy[] {
    return policies.filter(
        (policy) =>
            policy.status === 'active' && policy.requires_approval !== true,
    );
}

/**
 * WHAT THE INVOICE IS — the wire's `kind`, which is NOT the per-line `kind` a few functions down.
 * The two words are unrelated and both are on this screen: a LINE is a charge/waiver/discount, an
 * INVOICE is a term bill or a supplementary charge. Kept as its own named type so a future edit
 * cannot pass one where the other belongs.
 */
export type InvoiceKindChoice = InvoiceKind;

/**
 * The label on the "Term bill" option.
 *
 * THE TRAP IS MADE VISIBLE BEFORE SUBMIT, NOT AFTER. An episode that already carries an active term
 * invoice will have a second one REFUSED — GenerateInvoice answers 422 "This enrollment already has
 * an active TERM invoice. Void it before billing the term again." Until this branch that refusal was
 * the only thing the modal could produce for a bursar trying to bill damages, because the term bill
 * was the only invoice it could ask for. Now that there is a second option, the option that will be
 * rejected says so in its own label rather than leaving the amber banner to be read.
 *
 * IT IS A LABEL, NOT A DISABLE, and that is deliberate. Disabling the option would hide the refusal
 * instead of explaining it, and voiding-then-rebilling the term is a legitimate thing a bursar does
 * — the server, not this select, is the authority on whether it is allowed right now.
 *
 * THE DEFAULT NEVER MOVES. `already_invoiced` changes this LABEL and nothing else: the selected
 * value is 'scheduled' on every open regardless of the episode's state. A default that follows the
 * data would mean the same clicks create a different document depending on what was billed earlier,
 * which is how someone raises a supplementary charge believing they raised the term bill.
 */
export function termBillLabel(alreadyInvoiced: boolean): string {
    // The WORD comes from the shared vocabulary (U7) so this select and every surface that reads an
    // invoice back afterwards name one document identically; the parenthetical warning is this
    // screen's own and belongs nowhere else.
    return alreadyInvoiced
        ? `${INVOICE_KIND_LABEL.scheduled} (will be rejected — void first)`
        : INVOICE_KIND_LABEL.scheduled;
}

/**
 * The patch to apply when a line's KIND changes.
 *
 * THE CLEAR IS THE POINT. Flipping a line back to `charge` must DISCARD the policy the operator
 * picked while it was a reduction, not merely stop showing it: the reduction guard's fifth arm
 * refuses a charge line that references a policy at all (…:96-99), and the server pre-check refuses
 * it one layer up with "A charge line cannot carry a discount policy." A hidden-but-retained id is
 * therefore a submission the server rejects for a field the operator can no longer see — the exact
 * shape of the bug this function exists to not have.
 *
 * Re-selecting on a flip BACK to a reduction is intended, not an oversight. Remembering the previous
 * choice would leave a policy silently attached to a line whose meaning changed twice, and an
 * unnoticed stale selection on a reduction is worse than one extra click.
 */
export function patchForKind(kind: DraftLine['kind']): Partial<DraftLine> {
    return kind === 'charge' ? { kind, discountPolicyId: '' } : { kind };
}

/**
 * One line, as the wire carries it.
 *
 * `discount_policy_id` GOES ONLY ON REDUCTION LINES — the second half of the same rule patchForKind
 * enforces in state, kept here as well because DraftLine is a plain object and any future edit path
 * that sets `discountPolicyId` without going through the kind select would otherwise put it on the
 * wire. Neither half refuses anything — the refusals are the server pre-check and the DB trigger,
 * both named in selectablePolicies' docblock above.
 *
 * On a reduction the value is sent AS IS, including `''` when nothing was picked. That is the
 * designed path and not a gap: `''` is rewritten to null by ConvertEmptyStringsToNull before any
 * rule sees it, and the pre-check answers with a field error naming that line. Blocking the request
 * client-side instead would hide whether the server still names it.
 */
export function wireLine(
    line: DraftLine,
    amountMinor: number,
): Record<string, unknown> {
    const wire: Record<string, unknown> = {
        description: line.description.trim(),
        amount_minor: amountMinor,
        kind: line.kind,
    };

    if (line.kind !== 'charge') {
        wire.discount_policy_id = line.discountPolicyId;
    }

    return wire;
}

/**
 * Turn a 422 body into the lines to show above the form.
 *
 * WHY THIS EXISTS, measured on both sides of U8 commit 3. Before it, a reduction whose policy was
 * missing, retired or approval-requiring came back as
 * `{"message": "A reduction line must reference an active discount policy; …"}` — the DB trigger's own
 * sentence, in `message`, which this modal displayed. After it, the same request comes back as
 * `{"message": "There are validation errors", "errors": {"lines.1.discount_policy_id": ["Select the
 * discount policy that authorises this reduction. …"]}}`, and reading `message` alone showed the
 * operator "There are validation errors" and nothing else. The server got MORE specific and the screen
 * got LESS: a regression, and this closes it.
 *
 * EVERY message, not the first. `Object.values(errors)[0]?.[0]` is the established shape elsewhere in
 * this codebase (edit-pivot-modal, student-guardians-panel) and it is wrong here: the pre-check
 * deliberately names every offending line in one response, so taking the first would hide the rest and
 * make the bursar fix the form one round trip per bad line.
 *
 * THE KEY IS NEVER REQUIRED TO BE A SMALL INTEGER. Laravel keys these by the caller's own array key —
 * this modal always sends a JS array, so its own keys are 0..n-1, but a keyed-object payload produces
 * `lines.7.discount_policy_id` for a line at key 7. The row prefix is therefore best-effort: the index
 * segment is used only when it parses as a non-negative integer, and any other shape (or a non-`lines`
 * key entirely) falls through to the bare message rather than rendering "Line NaN".
 */
function errorLinesFrom(data: unknown): string[] {
    const body = data as
        | { message?: unknown; errors?: Record<string, unknown> }
        | undefined;
    const errors = body?.errors;

    if (errors && typeof errors === 'object') {
        const out: string[] = [];

        for (const [field, messages] of Object.entries(errors)) {
            const segments = field.split('.');
            const index =
                segments.length === 3 && segments[0] === 'lines'
                    ? Number(segments[1])
                    : NaN;
            const prefix =
                Number.isInteger(index) && index >= 0
                    ? `Line ${index + 1} — `
                    : '';

            for (const message of Array.isArray(messages)
                ? messages
                : [messages]) {
                if (typeof message === 'string' && message !== '') {
                    out.push(`${prefix}${message}`);
                }
            }
        }

        if (out.length > 0) {
            return out;
        }
    }

    // FALLBACK, and it is load-bearing rather than defensive. GenerateInvoice still answers a plain
    // `{"message": …}` with no `errors` key for every BusinessRuleException it raises — the F7 duplicate
    // invoice, a negative total, a percentage with no discountable base, the no-context refusal. Those
    // are not going away and must keep rendering.
    return [
        typeof body?.message === 'string' && body.message !== ''
            ? body.message
            : 'The invoice could not be created.',
    ];
}

/**
 * Create an invoice for the STUDENT. Enrollment resolution is server-side: on open the
 * modal reads the current billable episode (academic context + F7 preview) — the frontend
 * never handles an enrollment id. Line entry is manual (no fee catalog yet); the live total
 * goes through sumMinor (the sanctioned integer sum), reductions carry a negative amount, and
 * the preview MIRRORS the server's F6 total. Submit posts to the student-scoped generate
 * endpoint; no-enrollment / already-invoiced (F7) / negative-total come back as 422 inline.
 *
 * A REDUCTION CITES A POLICY (U8 commit 4). Until this commit the modal offered `waiver` and
 * `discount` in its Kind select and sent no `discount_policy_id` at all, so every reduction the
 * running UI could submit was refused — by the pre-check since U8 commit 3, and by
 * finance_invoice_lines_reduction_guard before that. The catalog is fetched on open
 * (`?status=active`, then narrowed again by selectablePolicies), the select appears only on a
 * reduction line, and the id is CLEARED when a line flips back to `charge`. NOTHING IN THIS FILE
 * REFUSES ANYTHING — the refusals are GenerateInvoiceRequest::assertDiscountPoliciesUsable() and
 * finance_invoice_lines_reduction_guard, both named in selectablePolicies' docblock above.
 *
 * FOUR FUNCTIONS AND ONE TYPE ARE EXPORTED WITH NO IMPORTER — selectablePolicies, termBillLabel,
 * patchForKind and wireLine, plus the InvoiceKindChoice type. (`termBillLabel` and
 * `InvoiceKindChoice` arrived with U7's invoice-kind select; the count read THREE until then, and
 * is re-derived here rather than incremented — `grep -n '^export ' ` on this file, minus
 * NewInvoiceModal, which statement.tsx:20 does import.)
 * That is deliberate and it is a cost paid on purpose. This module's only importer is statement.tsx,
 * which takes `NewInvoiceModal` alone, so nothing in the bundle reaches them and neither tsc nor
 * eslint will tell you if one becomes dead. They are exported because they are the only pure,
 * mechanically-testable seam in a file whose logic is otherwise entangled with React state, and
 * because un-exporting them would make the file untestable BY CONSTRUCTION on the day a JavaScript
 * runner lands (docs/handoff/tickets/no-javascript-test-runner.md). Keeping the seam open costs an
 * unused export today; closing it costs the first test tomorrow.
 */
export function NewInvoiceModal({
    isOpen,
    onClose,
    student,
    onCreated,
}: Props) {
    const [enrollment, setEnrollment] = useState<BillableEnrollmentInfo | null>(
        null,
    );
    const [blocked, setBlocked] = useState<string | null>(null); // no active enrollment
    // WHAT is being raised. 'scheduled' on every open — see termBillLabel for why this does not
    // follow `already_invoiced`. Reset alongside the lines in loadEnrollment, so reopening the
    // dialog for a different student cannot inherit a supplementary choice made for the last one.
    const [invoiceKind, setInvoiceKind] =
        useState<InvoiceKindChoice>('scheduled');
    const [lines, setLines] = useState<DraftLine[]>([{ ...EMPTY_LINE }]);
    const [formErrors, setFormErrors] = useState<string[]>([]);
    const [submitting, setSubmitting] = useState(false);
    // The discount catalog, already narrowed by selectablePolicies. Three states worth telling apart:
    // still loading, loaded-and-empty (the School has nothing that can back a reduction — said in
    // words below, never as an empty select), and loaded-but-the-request-failed.
    const [policies, setPolicies] = useState<SelectablePolicy[]>([]);
    const [policiesLoading, setPoliciesLoading] = useState(true);
    const [policiesFailed, setPoliciesFailed] = useState(false);

    const loadEnrollment = useCallback(async () => {
        setEnrollment(null);
        setBlocked(null);
        setFormErrors([]);
        setInvoiceKind('scheduled');
        setLines([{ ...EMPTY_LINE }]);

        try {
            const { data } = await axios.get<BillableEnrollmentInfo>(
                billableEnrollment.url(student.uuid),
            );
            setEnrollment(data);
        } catch (err: unknown) {
            if (axios.isAxiosError(err) && err.response?.status === 422) {
                setBlocked(
                    err.response.data?.message ??
                        'This student cannot be billed.',
                );
            } else {
                setBlocked('Could not resolve the student’s enrollment.');
            }
        }
    }, [student.uuid]);

    /**
     * The discount catalog the policy select is built from.
     *
     * `?status=active` FOLLOWS discount-policies.tsx:248-252 (the `load()` callback's comment —
     * re-derived 2026-08-16, having pointed at :167-172 until that file was rewritten), which is the
     * in-repo precedent for reading this endpoint and which says in its own comment that a caller
     * wanting only the choosable ones asks for exactly this. DiscountPolicyController::index treats an absent `status`
     * as UNFILTERED, so omitting it would hand this select every superseded and retired policy the
     * School has ever had.
     *
     * A FAILED FETCH IS NOT AN EMPTY CATALOG, and the two are kept apart on purpose: an empty list
     * rendered after a network error would tell the bursar their School has no policies, which is a
     * different and possibly false statement. The failure gets its own sentence below.
     */
    const loadPolicies = useCallback(async () => {
        setPoliciesLoading(true);
        setPoliciesFailed(false);

        try {
            const { data } = await axios.get<SelectablePolicy[]>(
                '/api/v1/finance/discount-policies',
                { params: { status: 'active' } },
            );
            setPolicies(selectablePolicies(data ?? []));
        } catch {
            setPolicies([]);
            setPoliciesFailed(true);
        } finally {
            setPoliciesLoading(false);
        }
    }, []);

    useEffect(() => {
        if (isOpen) {
            // eslint-disable-next-line react-hooks/set-state-in-effect
            void loadEnrollment();
            // NO SECOND DISABLE. The rule fires once per effect, on the first offending call, so a
            // directive here is unused and eslint reports it as a warning. Measured: adding it
            // produced "Unused eslint-disable directive"; removing it, zero warnings. That
            // measurement is the whole justification — this comment used to cite
            // discount-policies.tsx:183-185 as making the same point, and that citation went stale
            // twice over on 2026-08-16: the lines moved, and the passage now at :268-276 records the
            // OPPOSITE outcome, because once that screen grew an error state the rule started firing
            // there and it carries the disable. Re-derive a cross-file citation or do not make one.
            void loadPolicies();
        }
    }, [isOpen, loadEnrollment, loadPolicies]);

    const setLine = (index: number, patch: Partial<DraftLine>) =>
        setLines((prev) =>
            prev.map((l, i) => (i === index ? { ...l, ...patch } : l)),
        );
    const addLine = () => setLines((prev) => [...prev, { ...EMPTY_LINE }]);
    const removeLine = (index: number) =>
        setLines((prev) =>
            prev.length === 1 ? prev : prev.filter((_, i) => i !== index),
        );

    // Signed minor units per line: a charge adds, a waiver/discount subtracts (the wire
    // carries a NEGATIVE amount for reductions, matching the server's per-kind sign rule).
    // null when a line's amount is not yet a valid number.
    const signedMinors = lines.map((l) => {
        const m = nairaToMinor(l.amount);

        if (m === null || m === 0) {
            return null;
        }

        return l.kind === 'charge' ? m : -m;
    });
    const allValid =
        lines.every((l) => l.description.trim() !== '') &&
        signedMinors.every((m) => m !== null);
    const previewTotal = allValid ? sumMinor(signedMinors as number[]) : null;

    const submit = async () => {
        setFormErrors([]);

        if (!allValid) {
            setFormErrors([
                'Every line needs a description and a non-zero amount.',
            ]);

            return;
        }

        if (previewTotal !== null && previewTotal < 0) {
            setFormErrors([
                'Reductions may not exceed the charges — the total would be negative.',
            ]);

            return;
        }

        setSubmitting(true);

        try {
            await axios.post(generateForStudent.url(student.uuid), {
                // SENT ON EVERY SUBMIT, including the default. The server treats an absent `kind`
                // as scheduled, so omitting it when the bursar left the select alone would work —
                // and would make the payload's meaning depend on a default declared in a different
                // file. What this screen asked for is stated in what it posts.
                kind: invoiceKind,
                // `allValid` above has already established that every signedMinors entry is a number,
                // which is what makes the cast honest rather than hopeful. Same cast, same reason and
                // same guard as `sumMinor(signedMinors as number[])` on the preview line.
                lines: lines.map((l, i) =>
                    wireLine(l, signedMinors[i] as number),
                ),
            });
            toast.success('Invoice created.');
            onCreated();
            onClose();
        } catch (err: unknown) {
            if (axios.isAxiosError(err) && err.response?.status === 422) {
                // EVERY message the server named, keyed by line where it said so.
                // Reading `message` alone here is what made the screen show
                // "There are validation errors" for a refusal the server had
                // described precisely — see errorLinesFrom.
                setFormErrors(errorLinesFrom(err.response.data));
            } else {
                setFormErrors(['Something went wrong creating the invoice.']);
            }
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Modal
            isOpen={isOpen}
            onClose={onClose}
            title={`New invoice — ${student.name}`}
            size="lg"
        >
            <div className="space-y-4">
                {blocked && (
                    <p className="rounded-md bg-destructive/10 p-2 text-sm text-destructive">
                        {blocked}
                    </p>
                )}

                {enrollment && (
                    <>
                        <div className="rounded-md bg-muted p-2 text-sm">
                            <span className="text-muted-foreground">
                                Billing episode:{' '}
                            </span>
                            {enrollment.academic_context}
                        </div>
                        {/*
                         * WHAT IS BEING RAISED — the term bill, or a charge outside the schedule.
                         *
                         * ABOVE THE LINES ON PURPOSE. It changes what the whole document means, not
                         * one row of it, so it is read before any amount is typed rather than found
                         * beside the submit button afterwards.
                         *
                         * NOT DISABLED AND NOT DEFAULTED FROM DATA. Both options are always
                         * selectable and 'scheduled' is always the selection on open —
                         * `already_invoiced` reaches the LABEL only. termBillLabel carries the
                         * reasoning.
                         */}
                        <div>
                            <Label htmlFor="ni-invoice-kind">Invoice</Label>
                            <Select
                                value={invoiceKind}
                                onValueChange={(v) =>
                                    setInvoiceKind(v as InvoiceKindChoice)
                                }
                            >
                                <SelectTrigger id="ni-invoice-kind">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="scheduled">
                                        {termBillLabel(
                                            enrollment.already_invoiced,
                                        )}
                                    </SelectItem>
                                    <SelectItem value="supplementary">
                                        {INVOICE_KIND_LABEL.supplementary}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        {/* `already_invoiced` is SCHEDULED-ONLY — the API computes it from
                            InvoiceReadModel::hasActiveScheduledInvoiceForEnrollment, the same
                            predicate GenerateInvoice's 422 uses. So this warns about the TERM
                            invoice and nothing else: an episode carrying only supplementary
                            charges is not "already invoiced" and must not be told to void
                            anything. The noun matters — voiding the wrong invoice discards its
                            payment allocations. Keep this sentence in step with the 422 in
                            app/Finance/Actions/GenerateInvoice.php.

                            THE SECOND SENTENCE EXISTS BECAUSE THE FIRST ONE USED TO BE THE ONLY
                            ROAD OUT. Before U7's wire this banner's only actionable advice was
                            "void it first", which is the WRONG action for a bursar billing damages
                            — it discards the term invoice's payment allocations to add a charge
                            that never needed it. Now that Supplementary is reachable from the
                            select above, the banner names it. Both sentences stay in step with the
                            422: voiding is still what the TERM bill needs, and is still the only
                            thing that refusal is about. */}
                        {enrollment.already_invoiced && (
                            <p className="rounded-md bg-amber-100 p-2 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">
                                This episode already has an active term invoice.
                                Void it first — creating another term invoice
                                will be rejected. To bill something outside the
                                term’s fees — damages, a trip, a lost book —
                                choose Supplementary charge above instead, which
                                needs nothing voided.
                            </p>
                        )}

                        {formErrors.length > 0 && (
                            <div className="space-y-1 rounded-md bg-destructive/10 p-2">
                                {formErrors.map((error) => (
                                    <p
                                        key={error}
                                        className="text-sm text-destructive"
                                    >
                                        {error}
                                    </p>
                                ))}
                            </div>
                        )}

                        <div className="space-y-2">
                            {lines.map((line, index) => (
                                <div key={index} className="space-y-1">
                                    <div className="flex items-end gap-2">
                                        <div className="flex-1">
                                            {index === 0 && (
                                                <Label>Description</Label>
                                            )}
                                            <Input
                                                placeholder="Tuition"
                                                value={line.description}
                                                onChange={(e) =>
                                                    setLine(index, {
                                                        description:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                        </div>
                                        <div className="w-32">
                                            {index === 0 && <Label>Kind</Label>}
                                            <Select
                                                value={line.kind}
                                                onValueChange={(v) =>
                                                    setLine(
                                                        index,
                                                        patchForKind(
                                                            v as DraftLine['kind'],
                                                        ),
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="charge">
                                                        Charge
                                                    </SelectItem>
                                                    <SelectItem value="waiver">
                                                        Waiver
                                                    </SelectItem>
                                                    <SelectItem value="discount">
                                                        Discount
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="w-32">
                                            {index === 0 && (
                                                <Label>Amount (₦)</Label>
                                            )}
                                            <Input
                                                inputMode="decimal"
                                                placeholder="0.00"
                                                value={line.amount}
                                                onChange={(e) =>
                                                    setLine(index, {
                                                        amount: e.target.value,
                                                    })
                                                }
                                            />
                                        </div>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() => removeLine(index)}
                                            disabled={lines.length === 1}
                                            aria-label="Remove line"
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </Button>
                                    </div>

                                    {/*
                                     * THE POLICY THIS REDUCTION CITES — shown only for a reduction,
                                     * because a charge line may not carry one at all (the reduction
                                     * guard's fifth arm). patchForKind() has already cleared the
                                     * stored id by the time this stops rendering, so nothing hidden
                                     * here rides along on the next submit.
                                     *
                                     * A NATIVE <select>, not the Radix one the Kind column uses, and
                                     * the reason is the empty option: Radix's SelectItem cannot take
                                     * `value=""`, and the unselected state is a designed path here —
                                     * it posts `''`, the server reads no provenance, and the
                                     * pre-check answers with a field error naming this line. The
                                     * shape follows fee-schedules.tsx:903-925, the in-repo precedent
                                     * for a "choose one, or leave empty" select in the Finance UI.
                                     */}
                                    {line.kind !== 'charge' && (
                                        <div className="pl-1">
                                            <Label
                                                htmlFor={`ni-policy-${index}`}
                                                className="text-xs"
                                            >
                                                Discount policy
                                            </Label>
                                            {policiesLoading ? (
                                                <p className="text-xs text-muted-foreground">
                                                    Loading the discount
                                                    policies…
                                                </p>
                                            ) : policiesFailed ? (
                                                <p className="text-xs text-destructive">
                                                    The discount policies could
                                                    not be loaded, so this
                                                    reduction cannot cite one.
                                                    Close the dialog and reopen
                                                    it to try again.
                                                </p>
                                            ) : policies.length === 0 ? (
                                                /*
                                                 * NEVER AN EMPTY SELECT. A select with only the
                                                 * placeholder in it looks like a control the
                                                 * operator has not used yet, so they hunt for the
                                                 * option that is not there; this says what is
                                                 * actually true and where to go. The same defect
                                                 * class — a screen rendering an empty control over
                                                 * a fixture that seeds nothing behind it — is what
                                                 * two earlier drives on this project were spent on.
                                                 */
                                                <p className="text-xs text-amber-700 dark:text-amber-400">
                                                    This school has no active
                                                    discount policy that can
                                                    back a reduction. Have one
                                                    authorised on the Discount
                                                    policies screen, or raise
                                                    this as a credit note
                                                    instead — submitting without
                                                    one will be refused.
                                                </p>
                                            ) : (
                                                <select
                                                    id={`ni-policy-${index}`}
                                                    className={SELECT_CLASS}
                                                    value={
                                                        line.discountPolicyId
                                                    }
                                                    onChange={(e) =>
                                                        setLine(index, {
                                                            discountPolicyId:
                                                                e.target.value,
                                                        })
                                                    }
                                                >
                                                    <option value="">
                                                        Choose a policy…
                                                    </option>
                                                    {policies.map((policy) => (
                                                        <option
                                                            key={policy.id}
                                                            value={policy.id}
                                                        >
                                                            {policy.name}
                                                        </option>
                                                    ))}
                                                </select>
                                            )}
                                        </div>
                                    )}
                                </div>
                            ))}
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={addLine}
                            >
                                Add line
                            </Button>
                        </div>

                        <div className="flex items-center justify-between border-t pt-3">
                            <span className="text-sm text-muted-foreground">
                                Total
                            </span>
                            <span className="text-lg font-semibold">
                                {previewTotal === null
                                    ? '—'
                                    : formatNaira({
                                          amount_minor: previewTotal,
                                          currency: 'NGN',
                                      })}
                            </span>
                        </div>
                    </>
                )}

                <div className="flex justify-end gap-2 border-t pt-3">
                    <Button
                        variant="outline"
                        onClick={onClose}
                        disabled={submitting}
                    >
                        Cancel
                    </Button>
                    <Button
                        onClick={submit}
                        disabled={submitting || blocked !== null}
                    >
                        {submitting ? 'Creating…' : 'Create invoice'}
                    </Button>
                </div>
            </div>
        </Modal>
    );
}
