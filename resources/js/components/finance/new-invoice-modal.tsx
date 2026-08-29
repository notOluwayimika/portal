import axios from 'axios';
import { Trash2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'react-toastify';
import { index as bankAccountsIndex } from '@/actions/App/Finance/Http/Controllers/BankAccountController';
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
    SelectableBankAccount,
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
    // Unselected, and NOT defaulted to the school's only account even when it has exactly one.
    // A default here would be a destination nobody chose — the fabrication
    // 2026_08_10_120000 refused this column for, arriving through the front door instead.
    bankAccountId: '',
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
 * Which accounts this form will offer as a DESTINATION.
 *
 * CONVENIENCE, NOT ENFORCEMENT — the same distinction selectablePolicies draws above, and it has to
 * be drawn again here because the thing this filters on is enforced NOWHERE ELSE. `deactivated_at`
 * withdraws an account from CHOICE (BankAccount::isActive()'s own comment — "a deactivated account
 * stays readable; it is only withdrawn from choice"); nothing in the schema
 * forbids billing to a retired account, GenerateInvoiceRequest's rule deliberately does not filter
 * on it, and `finance_fee_items.bank_account_id` carries no such predicate either — so a fee item
 * may already point at a deactivated account and FeeScheduleLineMapper will snapshot it. That is a
 * ruling recorded in the request's rule, not an oversight: refusing here and snapshotting there
 * would make the two writers disagree about the same account.
 *
 * What this list is therefore for is not offering a bursar an account the school has stopped using.
 * A reader who takes it for a guard will look for a server-side one and not find it.
 *
 * The endpoint does NOT filter — BankAccountController::index returns every account in display
 * order, active first (scopeInDisplayOrder), with no query parameters — so unlike the `?status=active`
 * on the policy fetch, this filter is the ONLY thing narrowing the list and is not redundant with
 * anything.
 */
export function selectableBankAccounts(
    accounts: SelectableBankAccount[],
): SelectableBankAccount[] {
    return accounts.filter((account) => account.is_active);
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
 *
 * THE DESTINATION IS THE SAME RULE MIRRORED (S11 commit 1). A reduction sends money nowhere, so
 * flipping a line TO a reduction discards the account the operator picked while it was a charge.
 * The two fields are now exclusive by construction and this function is the one place that is true:
 * `kind === 'charge'` clears the policy, anything else clears the destination, and no line can ever
 * hold both.
 *
 * WHAT THIS PREVENTS TODAY IS SMALLER THAN THE POLICY HALF, AND THE ASYMMETRY IS WORTH STATING
 * rather than leaving for someone to discover as an inconsistency. wireLine() already omits the
 * destination on a reduction, so a retained value cannot reach the server whatever this does, and
 * nothing yet refuses a destination on a reduction line — whether a waiver may cite the account of
 * the charge it offsets is unmodelled and unanswered, which is exactly why the S11 commit-2 trigger
 * permits null on non-charge lines instead of deciding. So this half is defence in depth for a
 * question that is still open, and it is written that way on purpose: if the answer ever turns out
 * to be "a reduction may not name one", nothing here has to change.
 */
export function patchForKind(kind: DraftLine['kind']): Partial<DraftLine> {
    return kind === 'charge'
        ? { kind, discountPolicyId: '' }
        : { kind, bankAccountId: '' };
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
 *
 * `bank_account_id` GOES ONLY ON CHARGE LINES (S11 commit 1) — the exact mirror of the rule above,
 * because a reduction has no destination: it reduces a bill rather than sending money anywhere.
 * patchForKind() has already cleared the field in state by the time a line stops being a charge;
 * this is the second statement of the same rule, kept for the same reason the policy half is —
 * DraftLine is a plain object and a future edit path could set the field without passing through
 * the kind select.
 *
 * AND `''` IS SENT AS IS HERE TOO, for the same reason and with a DIFFERENT consequence that must
 * not be glossed. The empty string becomes null before any rule sees it, and the request is then
 * REFUSED: GenerateInvoiceRequest::assertDestinationsChosen() (S11 commit 2) answers 422 with a
 * FIELD error keyed to `lines.N.bank_account_id` on the ORIGINAL wire index, and
 * finance_invoice_lines_destination_guard (2026_08_29_120000) is the authority behind it. Sending
 * `''` rather than refusing client-side is exactly what makes that refusal reach the operator as a
 * ROW NUMBER — the server cannot name a line it was never sent.
 *
 * MEASURED, not read (drive 2026-08-29, docs/handoff/drives/2026-08-29-new-invoice-destination):
 * one charge line with the select untouched came back "Line 1 — Select the account this charge is
 * destined for. A charge line has to record where its money is going."; the form kept every value
 * the bursar had typed; and nothing was written — the refusal lands before the Action's
 * transaction, so the append-only table is never reached.
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
    } else {
        wire.bank_account_id = line.bankAccountId;
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
 * A CHARGE NAMES ITS DESTINATION (S11 commit 1). Every charge line carries the bank account its
 * money is destined for, chosen by the operator and SNAPSHOTTED onto finance_invoice_lines at issue
 * — not looked up later through the mutable fee catalog, which could only ever answer "where would
 * this go today". The catalog is fetched on open and narrowed by selectableBankAccounts; the select
 * appears only on a charge line and the id is CLEARED when a line flips to a reduction. NOTHING IN
 * THIS FILE REFUSES A MISSING ONE, AND NOTHING HERE NEEDS TO: since S11 commit 2 the request is
 * refused server-side — assertDestinationsChosen() names EVERY offending line in one response and
 * finance_invoice_lines_destination_guard is the authority behind it. What this file still owes the
 * operator is the ROW NUMBER, and errorLinesFrom() is where that is supplied. The empty-catalog
 * branch below is a separate statement about a school that can offer no destination at all.
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
 * FIVE FUNCTIONS AND ONE TYPE ARE EXPORTED WITH NO IMPORTER — selectablePolicies,
 * selectableBankAccounts, termBillLabel, patchForKind and wireLine, plus the InvoiceKindChoice type.
 * (`termBillLabel` and `InvoiceKindChoice` arrived with U7's invoice-kind select and
 * `selectableBankAccounts` with S11; the count read THREE, then FOUR, and is re-derived here rather
 * than incremented — `grep -c '^export ' ` on this file, minus NewInvoiceModal, which
 * statement.tsx:20 does import.)
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
    // The destination catalog, already narrowed by selectableBankAccounts. The same three states
    // told apart for the same reason as the policies above: loading, loaded-and-empty (the school
    // has configured no active account — said in words, never as an empty select), and
    // loaded-but-the-request-failed.
    const [accounts, setAccounts] = useState<SelectableBankAccount[]>([]);
    const [accountsLoading, setAccountsLoading] = useState(true);
    const [accountsFailed, setAccountsFailed] = useState(false);

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

    /**
     * The destination catalog the account select is built from (S11 commit 1).
     *
     * NO QUERY PARAMETERS, unlike the policy fetch above, and that is a property of the endpoint
     * rather than a choice made here: BankAccountController::index reads none — it returns every
     * account in `inDisplayOrder()` (active first, then by label) and the shape is
     * `{bank_accounts: [...]}`. bank-accounts.tsx:81-86 records the same fact about the same endpoint,
     * and pins the consequence it has there (client-side filtering is sound only while the endpoint
     * does not paginate) — the day it paginates, this fetch needs the same look.
     * So selectableBankAccounts() is the ONLY narrowing, which is why it is not redundant the way
     * selectablePolicies' status filter is.
     *
     * THE ROUTE COMES FROM WAYFINDER, not a hand-written string. The policy fetch above predates
     * that habit on this file and still carries a literal; a new call site does not need to inherit
     * it, and the generated action fails at build time if the route moves.
     *
     * THE PERMISSION HOLDS, checked rather than assumed: `GET /api/v1/finance/bank-accounts` is
     * gated on `finance.bank-account.manage` (routes/endpoints/finance.php), and both roles that
     * hold `finance.invoice.generate` — admin and accounts_officer — also hold it (RbacSeeder:248
     * and :252, :407 and :411). A principal who can raise an invoice on this screen can therefore
     * read the list it offers. If that ever stops being true this fetch 403s and the failure branch
     * below is what the bursar sees.
     *
     * A FAILED FETCH IS NOT AN EMPTY CATALOG, same as the policies: an empty list after a network
     * error would tell the bursar their school has no accounts, which is a different and possibly
     * false statement, and here it is the more dangerous one — it points at the Bank accounts screen
     * for a problem that is not there.
     */
    const loadBankAccounts = useCallback(async () => {
        setAccountsLoading(true);
        setAccountsFailed(false);

        try {
            const { data } = await axios.get<{
                bank_accounts: SelectableBankAccount[];
            }>(bankAccountsIndex.url());
            setAccounts(selectableBankAccounts(data?.bank_accounts ?? []));
        } catch {
            setAccounts([]);
            setAccountsFailed(true);
        } finally {
            setAccountsLoading(false);
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
            void loadBankAccounts();
        }
    }, [isOpen, loadEnrollment, loadPolicies, loadBankAccounts]);

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
                                     * WHERE THIS CHARGE'S MONEY IS DESTINED (S11 commit 1) — shown
                                     * only for a CHARGE, because a reduction sends money nowhere and
                                     * carries null. patchForKind() has already cleared the stored
                                     * uuid by the time this stops rendering, and wireLine() omits
                                     * the field on a reduction regardless, so nothing hidden here
                                     * rides along on the next submit.
                                     *
                                     * PER LINE, NOT PER INVOICE, and that follows the READ path
                                     * rather than this form's convenience: AllocationProposal
                                     * already treats an invoice as possibly multi-destination — its
                                     * `accounts` has always been a list, and the mismatch banner it
                                     * drives exists to show money landing in one account settling
                                     * lines destined for another. A per-invoice selector would
                                     * narrow what the reader already supports, and tuition and
                                     * transport routing to different accounts is the case the
                                     * column was added for.
                                     *
                                     * A NATIVE <select> for the same reason the policy one below is:
                                     * Radix's SelectItem cannot take `value=""`, and unselected is a
                                     * reachable state here. Since S11 commit 2 it is a REFUSED one,
                                     * which is why it must stay REACHABLE: the empty value is what
                                     * the server reads as "no destination on this line", and it is
                                     * what lets the 422 name the row.
                                     */}
                                    {line.kind === 'charge' && (
                                        <div className="pl-1">
                                            <Label
                                                htmlFor={`ni-destination-${index}`}
                                                className="text-xs"
                                            >
                                                Destination account
                                            </Label>
                                            {accountsLoading ? (
                                                <p className="text-xs text-muted-foreground">
                                                    Loading the bank accounts…
                                                </p>
                                            ) : accountsFailed ? (
                                                <p className="text-xs text-destructive">
                                                    The bank accounts could not
                                                    be loaded, so this charge
                                                    cannot record where its
                                                    money is destined. Close the
                                                    dialog and reopen it to try
                                                    again.
                                                </p>
                                            ) : accounts.length === 0 ? (
                                                /*
                                                 * NEVER AN EMPTY SELECT — same rule as the policy
                                                 * block below, and here the sentence still has to
                                                 * carry the consequence. THE CONSEQUENCE CHANGED
                                                 * WITH S11 COMMIT 2 and the new one is not the old
                                                 * one weakened. It is no longer "nothing refuses
                                                 * this, so the invoice is raised and the line is
                                                 * permanently silent about where its money went":
                                                 * assertDestinationsChosen() refuses it and
                                                 * finance_invoice_lines_destination_guard is the
                                                 * authority. It is that the invoice cannot be
                                                 * raised AT ALL until this school has an active
                                                 * account — every charge line must name a
                                                 * destination, this select is the only place to
                                                 * choose one, and a school with none can offer
                                                 * nothing to choose. Without this sentence the
                                                 * bursar meets a 422 they cannot act on from this
                                                 * screen. The warning stays; only its reason
                                                 * moved.
                                                 */
                                                <p className="text-xs text-amber-700 dark:text-amber-400">
                                                    This school has no active
                                                    bank account, so this charge
                                                    cannot record a destination.
                                                    Add one on the Bank accounts
                                                    screen first — an invoice
                                                    raised without one can never
                                                    say where its money was
                                                    meant to go.
                                                </p>
                                            ) : (
                                                <select
                                                    id={`ni-destination-${index}`}
                                                    className={SELECT_CLASS}
                                                    value={line.bankAccountId}
                                                    onChange={(e) =>
                                                        setLine(index, {
                                                            bankAccountId:
                                                                e.target.value,
                                                        })
                                                    }
                                                >
                                                    {/*
                                                     * NO DEFAULT SELECTION, not even when the school
                                                     * has exactly one account. Preselecting would
                                                     * record a destination the operator never chose
                                                     * — the fabrication 2026_08_10_120000 refused
                                                     * this whole column for, reintroduced as a
                                                     * convenience. One extra click, and the value on
                                                     * the row is a choice.
                                                     */}
                                                    <option value="">
                                                        Choose an account…
                                                    </option>
                                                    {accounts.map((account) => (
                                                        <option
                                                            key={account.id}
                                                            value={account.id}
                                                        >
                                                            {account.label} —{' '}
                                                            {account.bank_name}
                                                        </option>
                                                    ))}
                                                </select>
                                            )}
                                        </div>
                                    )}

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
