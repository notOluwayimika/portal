import { describe, expect, it } from 'vitest';
import { internalAuditNavGroup } from './internal-audit-nav';

/** A `can` that answers only for the abilities this seat actually holds. */
const seat =
    (...held: string[]) =>
    (ability: string) =>
        held.includes(ability);

describe('the Internal audit sidebar group', () => {
    it('renders for a seat holding finance.invoice.approve', () => {
        const group = internalAuditNavGroup(seat('finance.invoice.approve'));

        expect(group).not.toBeNull();
        expect(group?.label).toBe('Internal audit');
        expect(group?.items).toHaveLength(1);
        // The href must be the route's, or the entry is a 404 dressed as navigation.
        expect(group?.items[0].href).toBe('/internal-audit/review-queue');
    });

    it('does NOT render for a seat without it', () => {
        expect(internalAuditNavGroup(seat())).toBeNull();
    });

    it('does not render for a seat that holds only finance.access', () => {
        // THE MISPLACEMENT, IN ABILITY TERMS. `internal_auditor` holds the approve ability and NOT
        // finance.access; a bursar holds finance.access and NOT the approve ability. If this gate
        // were keyed on the wrong one — which is what nesting it inside the `can('finance.access')`
        // block would effectively do — the entry would appear for exactly the seats that cannot
        // reach the page, and vanish for the one that can. Both directions are asserted, because
        // either alone passes on a gate that is simply always-true or always-false.
        expect(internalAuditNavGroup(seat('finance.access'))).toBeNull();
    });

    it('renders for a dual-role seat holding both', () => {
        expect(
            internalAuditNavGroup(
                seat('finance.access', 'finance.invoice.approve'),
            ),
        ).not.toBeNull();
    });
});
