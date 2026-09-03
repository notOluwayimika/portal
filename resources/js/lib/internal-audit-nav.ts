import { ShieldCheck } from 'lucide-react';
import type { NavGroup } from '@/types/navigation';

/**
 * INTERNAL AUDIT'S SIDEBAR GROUP.
 *
 * EXTRACTED SO THE GATE IS ASSERTABLE. `app-sidebar.tsx` assembles its groups in one large
 * `useMemo` that reads Inertia page props, so the only way to test an entry inside it is to render
 * the component — and vitest runs in `node` (vitest.config.ts) precisely to keep the assertable
 * parts pure. This is the decision; the sidebar spreads the result.
 *
 * THE ABILITY IS `finance.invoice.approve`, THE SAME ONE THE ROUTE CARRIES. An item shown to
 * someone the route refuses is a 403 on click, and an item hidden from someone the route admits is
 * a page with no way in — which is what this whole change is fixing.
 *
 * IT MUST BE CALLED OUTSIDE `can('finance.access')`. `internal_auditor` holds the approve ability
 * and NOT `finance.access`, so nesting this inside that block would render it for everyone who can
 * view finance and hide it from the one seat that needs it: the enclosing gate wins over the
 * item's own condition. The same trap put the ROUTE in its own top-level group rather than in the
 * finance group. This function cannot enforce where it is called from — `app-sidebar.tsx` carries
 * that comment at the call site, and this paragraph is why it is worth repeating there.
 */
export function internalAuditNavGroup(
    can: (ability: string) => boolean,
): NavGroup | null {
    if (!can('finance.invoice.approve')) {
        return null;
    }

    return {
        label: 'Internal audit',
        items: [
            {
                title: 'Review queue',
                href: '/internal-audit/review-queue',
                icon: ShieldCheck,
            },
        ],
    };
}
