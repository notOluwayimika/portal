// Wire shapes for /api/v1/finance/* — mirror the backend Resources EXACTLY so binding
// to the contract is type-checked. Money is ALWAYS {amount_minor, currency} (integer
// minor units + ISO-4217); it is rendered only via formatNaira() and never arithmetic'd
// in the UI (bin/ci-money-lint.php enforces both).

export type Money = { amount_minor: number; currency: string };

export type InvoiceStatus = 'issued' | 'void';

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
    kind: CreditNoteKind;
    amount: Money;
    note: string | null;
    status: CreditNoteStatus;
    submitted_by_name?: string | null;
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
    amount?: Money | null;
    reason: string;
    note?: string | null; // = reason, so the unified queue reads one field
    status: VoidRequestStatus;
    submitted_by_name?: string | null;
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
    allocations?: { id: string; invoice_id: number; amount: Money }[];
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
