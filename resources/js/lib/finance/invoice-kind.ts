// HOW AN INVOICE KIND IS NAMED, in one place.
//
// `kind` reached the wire with U7 (InvoiceResource) and it is now rendered on six surfaces: the
// statement's invoices table, the invoice detail, the printable invoice, and the three modals that
// precede an act on a chosen invoice. Six literals would be six chances for two screens to name one
// document differently — the same failure the Term::displayLabel() method exists to prevent on the
// term selects (routes/web.php names it three times over), one document type across.
//
// THE WORDS ARE NOT NEW HERE. They are the ones the "New invoice" modal's own select already used
// ("Term bill" / "Supplementary charge"), so the label a bursar picks at creation is the label they
// read back everywhere afterwards; new-invoice-modal.tsx now reads them from here rather than
// carrying its own copy.
//
// NO REFUSAL, NO RULE, NO DERIVATION lives in this file — it is vocabulary. Whether an invoice may
// be voided, paid or credited is the server's (`can_*` on InvoiceResource), and nothing here is
// consulted for any of it.

import type { InvoiceKind } from '@/types/finance';

/** The full label — used wherever there is room for a sentence's worth of words. */
export const INVOICE_KIND_LABEL: Record<InvoiceKind, string> = {
    scheduled: 'Term bill',
    supplementary: 'Supplementary charge',
};

/**
 * The badge treatment. A THIRD visual axis, deliberately distinct from the two the statement
 * already renders: the document badge (issued/void) is blue/slate and the settlement badge is
 * rose/amber/emerald, so kind takes indigo/violet and cannot be misread as either of them.
 */
export const INVOICE_KIND_BADGE: Record<InvoiceKind, string> = {
    scheduled:
        'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/20 dark:text-indigo-300',
    supplementary:
        'bg-violet-50 text-violet-700 dark:bg-violet-900/20 dark:text-violet-300',
};

/**
 * How a modal or a page names ONE invoice: the kind and the number together.
 *
 * THIS IS THE FUNCTION THE TICKET'S §5 IS ABOUT. Voiding the wrong invoice discards its payment
 * allocations, and until U7 the confirmation a bursar read before doing it named a number alone —
 * which stopped implying which document it was the moment an episode could carry a term bill and a
 * supplementary charge at the same time. Every surface that precedes an irreversible act names the
 * invoice through here.
 */
export function invoiceLabel(invoice: {
    kind: InvoiceKind;
    display_number: string;
}): string {
    return `${INVOICE_KIND_LABEL[invoice.kind]} ${invoice.display_number}`;
}
