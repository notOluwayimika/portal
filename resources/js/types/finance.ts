// Wire shapes for /api/v1/finance/* — mirror the backend Resources EXACTLY so binding
// to the contract is type-checked. Money is ALWAYS {amount_minor, currency} (integer
// minor units + ISO-4217); it is rendered only via formatNaira() and never arithmetic'd
// in the UI (bin/ci-money-lint.php enforces both).

export type Money = { amount_minor: number; currency: string };

export type InvoiceStatus = 'issued' | 'void';

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
    lines?: InvoiceLine[];
    cancelled_at: string | null;
    cancel_reason: string | null;
};

export type CreditNoteKind = 'credit_note' | 'write_off';

// CreditNoteResource
export type CreditNote = {
    id: string; // uuid
    number: number;
    display_number: string;
    invoice_id: number;
    kind: CreditNoteKind;
    amount: Money;
    note: string | null;
    created_at: string;
};

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
};
