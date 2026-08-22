// Wire shapes for /api/v1/finance/* — mirror the backend Resources EXACTLY so binding
// to the contract is type-checked. Money is ALWAYS {amount_minor, currency} (integer
// minor units + ISO-4217); it is rendered only via formatNaira() and never arithmetic'd
// in the UI (bin/ci-money-lint.php enforces both).

export type Money = { amount_minor: number; currency: string };

export type InvoiceStatus = 'issued' | 'void';

// WHAT THE DOCUMENT IS — the third axis, and orthogonal to both of the two below. `scheduled` is
// the term bill (the episode's fees, from the fee schedule); `supplementary` is a charge raised
// outside the schedule against an episode that is already billed. An episode carries at most one
// active term bill and any number of live supplementary charges, so this is what tells two rows on
// one episode apart. Immutable at the database (finance_invoices_kind_immutable); never a status.
export type InvoiceKind = 'scheduled' | 'supplementary';

// Two ORTHOGONAL axes (never one badge): `status` above is the DOCUMENT state; this is the
// derived SETTLEMENT state. `null` on a voided invoice — a void has no meaningful settlement.
export type SettlementState = 'unpaid' | 'part_paid' | 'settled' | null;

export type InvoiceLine = {
    id: string;
    description: string;
    kind: string;
    note: string | null;
    amount: Money;
};

// InvoiceResource
export type Invoice = {
    id: string; // uuid
    number: number;
    display_number: string;
    status: InvoiceStatus;
    // Term bill or supplementary charge. Rendered through @/lib/finance/invoice-kind so every
    // surface names one document the same way.
    kind: InvoiceKind;
    billed_to_name: string;
    academic_context: string;
    total: Money;
    // Settlement axis (server-derived; the UI renders, never re-derives). `outstanding` is
    // floored at zero for display — the account balance carries a paid-then-credited invoice's
    // true credit position.
    outstanding: Money;
    settlement_state: SettlementState;
    // Per-invoice eligibility — the UI gates buttons on these flags, never on JS arithmetic.
    can_record_payment: boolean;
    can_submit_credit_note: boolean;
    can_request_void: boolean;
    void_blocked_reason: string | null;
    lines?: InvoiceLine[];
    cancelled_at: string | null;
    cancel_reason: string | null;
};

export type CreditNoteKind = 'credit_note' | 'write_off';

// Ph3 maker-checker lifecycle: a proposal is `submitted` (no money moved) until a checker
// ≠ maker `approved`s it (money moves) or `rejected`s it (never any money).
export type CreditNoteStatus = 'submitted' | 'approved' | 'rejected';

// CreditNoteResource
export type CreditNote = {
    id: string; // uuid
    number: number;
    display_number: string;
    invoice_id: number;
    invoice_display_number?: string; // present in the pending queue (invoice eager-loaded)
    // WHICH document the note acts on, not just which number — U7 put `kind` on the wire because an
    // episode can carry a term bill and several supplementary charges at once. Present wherever the
    // invoice is eager-loaded (the pending queue and the decided feed).
    invoice_kind?: InvoiceKind | null;
    kind: CreditNoteKind;
    amount: Money;
    note: string | null;
    status: CreditNoteStatus;
    submitted_by_name?: string | null;
    // THE CHECKER (U13). Emitted through whenLoaded('decidedBy'), which only the DECIDED feed loads,
    // so the key is ABSENT on a pending row rather than null — "this document has no checker" and
    // "the checker is unknown" are different statements and the wire keeps them apart.
    decided_by_name?: string | null;
    decided_at?: string | null;
    rejection_reason?: string | null;
    // Policy-computed, viewer-relative: disabled on one's own submission (maker ≠ checker).
    can_approve: boolean;
    can_reject: boolean;
    created_at: string;
};

// Ph3b maker-checker: a void request is `submitted` (invoice untouched, no money moved)
// until a checker ≠ maker `approved`s it (invoice voided, reversal posted) or `rejected`s
// it (charge stands). Terminal states mirror the credit-note lifecycle.
export type VoidRequestStatus = 'submitted' | 'approved' | 'rejected';

// VoidRequestResource — carries `type: 'void'` so the unified approvals queue can render it
// beside credit notes. `amount` is the invoice total (the reversal at stake).
export type VoidRequest = {
    type: 'void';
    id: string; // uuid
    invoice_id: number;
    invoice_display_number?: string | null;
    // The kind beside the number, for the reason CreditNote carries it.
    invoice_kind?: InvoiceKind | null;
    amount?: Money | null;
    reason: string;
    note?: string | null; // = reason, so the unified queue reads one field
    status: VoidRequestStatus;
    submitted_by_name?: string | null;
    // THE CHECKER (U14) — CreditNote's twin field, absent on a pending row for the same reason.
    decided_by_name?: string | null;
    decided_at?: string | null;
    rejection_reason?: string | null;
    can_approve: boolean;
    can_reject: boolean;
    created_at: string;
};

// FeeScheduleChangeResource on the approvals queue (§9 step 5a). `amount` is null — approving a
// publish/retire moves no money — and `note` mirrors `reason` so the queue reads one field across
// every type, exactly as VoidRequest does.
export type FeeScheduleChangeApproval = {
    type: 'fee_schedule_change';
    id: string; // uuid
    kind: string;
    target_schedule_id?: string | null;
    // The SUBJECT of the decision — a schedule IS its (class level × term) pair, and `label` alone
    // is author-supplied free text that two schedules may share. Present only when the feed eager-
    // loads the target (the pending queue does); whenLoaded() omits them otherwise.
    target_label?: string | null;
    target_class_level?: string | null;
    target_term?: string | null;
    reason: string;
    note?: string | null;
    amount?: Money | null;
    status: string;
    submitted_by_name?: string | null;
    rejection_reason?: string | null;
    can_approve: boolean;
    can_reject: boolean;
    created_at: string;
};

// DiscountPolicyChangeResource on the approvals queue (§9 step 5a). `amount` is null on purpose:
// `value_minor` / `percent` are the policy's parameters, not a sum that moves on approval.
export type DiscountPolicyChangeApproval = {
    type: 'discount_policy_change';
    id: string; // uuid
    kind: string;
    // A create and an amend state their own terms; a RETIRE states none of them — the DB CHECK
    // forces name/basis/requires_approval NULL there — so `target_policy_name` is the only thing
    // that identifies a retire. `basis` + `percent` / `value_minor` are the rate or amount at
    // stake; rendered in the subject, never in the money column (a discount rate is not money).
    name?: string | null;
    target_policy_name?: string | null;
    basis?: 'amount' | 'percent' | null;
    percent?: number | null;
    value_minor?: number | null;
    value_currency?: string | null;
    reason: string;
    note?: string | null;
    amount?: Money | null;
    status: string;
    submitted_by_name?: string | null;
    rejection_reason?: string | null;
    can_approve: boolean;
    can_reject: boolean;
    created_at: string;
};

// OpeningBalanceBatchResource on the approvals queue (§9 step 5a). `amount` is the batch's
// control total — the position approval posts into the subledger in one transaction.
//
// `can_approve` / `can_reject` ARE NOW LIVE for this type. They were false for every viewer until
// §9 step 5b-ii, which added OpeningBalanceBatchPolicy and the approve/reject endpoints; the
// Resource never changed, because it always computed them through the Gate. Approving a row of this
// type POSTS the cutover irreversibly, which is why its feed entry is the only one carrying a
// confirmation.
export type OpeningBalanceApproval = {
    type: 'opening_balance';
    id: string; // uuid
    batch_reference: string;
    invoice_id: null;
    invoice_display_number: null;
    amount?: Money | null;
    note: null;
    status: string;
    submitted_by_name?: string | null;
    decided_at?: string | null;
    rejection_reason?: string | null;
    can_approve: boolean;
    can_reject: boolean;
    created_at: string;
};

// Every document that flows through the maker-checker approvals queue. Each resource carries a
// `type` discriminator and the queue renders all of them from ONE declared list
// (lib/finance/approval-feeds.ts) — the union and that list are the two halves of the same
// statement, and ApprovalsQueueFeedCoverageTest is what stops them drifting apart.
export type PendingApproval =
    | (CreditNote & { type: 'credit_note' })
    | VoidRequest
    | FeeScheduleChangeApproval
    | DiscountPolicyChangeApproval
    | OpeningBalanceApproval;

// The account-level position (where credit-note credit is visible — it carries on the
// balance, not as a per-invoice line). balance is signed (positive = owed).
export type AccountPosition = {
    balance: Money;
    available_credit: Money;
};

// GET /api/v1/finance/students/{student}/invoices — the statement.
// NOTE: this endpoint does NOT expose a payments list; the account position reflects
// payments' net effect. A per-payment history would need a new read endpoint (reported,
// not added — no backend change in this vertical).
export type Statement = {
    billed_total: Money;
    invoices: Invoice[];
    credit_notes: CreditNote[];
    // Ph3b: void requests ride beside the invoices — a pending one is "void requested,
    // awaiting approval" (invoice still active); a decided one is the audit trail.
    void_requests: VoidRequest[];
    account: AccountPosition;
    payments: Payment[];
};

// PaymentResource
export type Payment = {
    id: string;
    reference: number;
    payer_name: string;
    method: string;
    amount: Money;
    created_at: string;
    // Receipt eligibility, derived server-side from `origin` (U11). The row is NEVER hidden on
    // these — the statement links every payment to its receipt page, and the flag only decides
    // whether the row also states, in place, why no receipt will be issued. `origin` itself stays
    // off the wire; see PaymentResource's docblock for why this is the narrower disclosure.
    receiptable: boolean;
    receipt_refusal_reason: string | null;
    // What this payment has NOT yet settled — amount − Σ(allocations), UNFLOORED. A negative value
    // means the allocation table holds more than the payment carries, which is a violating row; the
    // ticket behind the payment-axis trigger names flooring it at zero as explicitly not the fix.
    unallocated: Money;
    // Server-derived (Payment::unallocatedAmount). The statement row gates the "Allocate" link on
    // this and never compares amounts itself. It says only that there is something left to direct —
    // whether the student has an open invoice to direct it AT is the allocation screen's answer.
    can_allocate: boolean;
    allocations?: { id: string; invoice_id: number; amount: Money }[];
};

// ── U10, the allocation screen ────────────────────────────────────────────────────────────────
// GET /api/v1/finance/payments/{payment}/allocation-proposal

// WHERE THIS INVOICE'S CHARGES WERE DESTINED, against where the money landed. THREE-VALUED, and the
// middle value is the point: `unrecorded` is NOT `matches`. The destination is derived through the
// invoice line's nullable `fee_item_id` — finance_invoice_lines has no bank_account_id of its own,
// deliberately (2026_08_10_120000) — so an invoice whose lines are free text has no readable
// destination at all, and rendering that as agreement is the "silently allocate across it" the MVP
// cut brief forbids, one level more subtle.
export type AllocationDestinationState = 'matches' | 'differs' | 'unrecorded';

export type AllocationDestination = {
    state: AllocationDestinationState;
    // The distinct accounts this invoice's charge lines resolve to. Named, because "there is a
    // mismatch" without saying which account is a warning an operator cannot act on.
    accounts: { label: string; bank_name: string }[];
    // The SUBSET of `accounts` that is not the account the money landed in — empty unless `state` is
    // `differs`. It exists because an invoice can resolve to more than one destination: rendering the
    // whole of `accounts` under "Not the account this money landed in" named the MATCHING account
    // under a sentence saying it did not match.
    differing_accounts: { label: string; bank_name: string }[];
    // How much of the invoice the answer does not cover. `matches` with a non-zero count here means
    // "as far as we can read", and the screen says so rather than showing a bare tick.
    charge_lines_without_destination: number;
};

export type AllocationCandidate = {
    id: string; // invoice uuid
    display_number: string;
    // scheduled (the term bill) or supplementary (a trip, a damaged appliance) — #269's wire. An
    // operator directing money needs to know which of several open bills is the term bill.
    kind: string;
    academic_context: string;
    total: Money;
    outstanding: Money;
    // What the engine would allocate here if it ran now: oldest invoice first, capped at outstanding.
    proposed: Money;
    allocatable: boolean;
    blocked_reason: string | null;
    destination: AllocationDestination;
};

export type AllocationProposal = {
    payment: {
        id: string;
        reference: number;
        payer_name: string;
        method: string;
        amount: Money;
        allocated: Money;
        unallocated: Money;
        // Formatted server-side; the money-lint bans date formatting in finance pages too.
        received_at: string;
        received_at_reason: string | null;
        // Null for a migrated payment — the origin pairing trigger enforces exactly that — so the
        // screen renders an absence rather than assuming a label.
        bank_account: { label: string; bank_name: string } | null;
    };
    invoices: AllocationCandidate[];
    proposed_total: Money;
    // What the proposal could not place: no open invoice left to absorb it. It stays on the account
    // and the next invoice generation draws it forward.
    unproposed_remainder: Money;
    // The position this proposal was computed from, hashed. Posted back on submit so the server can
    // tell an operator's edit from the world moving underneath them — without it, a concurrent
    // invoice generation would get the operator's rows stamped as overridden, permanently, on a table
    // that has no UPDATE. See AllocationProposal::fingerprint in PHP.
    fingerprint: string;
};

// GET .../students/{student}/billable-enrollment — the "New invoice" modal's episode
// confirm + F7 preview (422 when the student has no active enrollment).
export type BillableEnrollmentInfo = {
    academic_context: string;
    already_invoiced: boolean;
};

// GET /api/v1/finance/accounts — the bursar accounts index (front door).
// A row's student display is LIVE (resolved via the ACL port at read time), and uuid is
// null only for a soft-deleted student (whose balance still counts in the KPIs but has no
// linkable statement). Every money field is the wire shape, displayed via formatNaira.
export type AccountStatus = 'outstanding' | 'in_credit' | 'settled';

export type AccountRow = {
    student: {
        uuid: string | null;
        name: string;
        admission_number: string | null;
    };
    balance: Money; // signed: positive = the student owes
    available_credit: Money; // max(0, -balance)
    last_activity: string | null;
};

export type AccountsPage = {
    data: AccountRow[];
    pagination: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
    // School-wide totals over ALL accounts — independent of search/filter/page.
    kpis: { total_receivables: Money; total_credit: Money };
};

// One draft invoice line in the New-invoice form (charge or reduction).
export type DraftLine = {
    description: string;
    amount: string; // naira, converted via nairaToMinor on submit
    kind: 'charge' | 'waiver' | 'discount';
    // The discount policy uuid a REDUCTION line cites (U8 commit 4). NOT optional and NOT nullable:
    // `''` is the unselected state, which is what a native <select> with an empty placeholder option
    // posts, and what the server reads as "no provenance" (ConvertEmptyStringsToNull rewrites it to
    // null before any rule sees it — GenerateInvoiceRequest documents this at length). A required
    // string with one meaningful empty value keeps every DraftLine the same shape, so nothing
    // downstream has to ask whether the field is present.
    //
    // A CHARGE LINE MUST CARRY `''` HERE, never a stale uuid: the reduction guard's fifth arm refuses
    // a charge line that references a policy. new-invoice-modal.tsx's patchForKind() is what clears
    // it on the flip back.
    discountPolicyId: string;
};

// The subset of DiscountPolicyResource (app/Finance/Http/Resources/DiscountPolicyResource.php) that
// choosing a policy at invoice time needs. A PROJECTION, deliberately named differently from the
// fuller `DiscountPolicy` type in pages/admin/finance/discount-policies.tsx, which is the authoring
// screen's own and carries basis/value/percent/description because it renders them. Naming the
// narrow one `SelectablePolicy` keeps it from reading as a second, drifting copy of the wide one.
//
// `id` IS THE UUID. DiscountPolicyResource:15 serialises `'id' => $this->uuid`; the integer primary
// key never reaches the wire (U8 commit 1).
export type SelectablePolicy = {
    id: string;
    name: string;
    requires_approval: boolean;
    status: 'active' | 'superseded' | 'retired';
};
