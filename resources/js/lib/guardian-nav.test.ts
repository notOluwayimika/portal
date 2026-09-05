import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/**
 * THE GUARDIAN'S MENU MUST REACH THE FEES PAGE.
 *
 * ── WHY THIS ARM EXISTS ──
 *
 * `parent/finance` shipped registered, gated on `parent_portal.access`, backed by a controller that
 * withholds unreleased bills on both keys, with a pay screen, a fee preview and a return page — and
 * **no entry in `guardianNavGroups`**. A guardian's whole menu was one item. The page was reachable
 * only by typing the URL, every test was green, and nothing failed, because nothing links a route to
 * a menu.
 *
 * That is the purest form of the failure this codebase keeps finding: a correctness requirement with
 * no local signal. Restoring the entry without an arm would leave the gap able to reopen on the next
 * edit of that array, looking exactly as green as it did before.
 *
 * ── WHY IT READS THE SOURCE RATHER THAN RENDERING ──
 *
 * `vitest.config.ts` runs in `node` and argues against installing a DOM for anything that does not
 * genuinely need to render; asserting a menu entry is not that moment. So this reads the module as
 * text — the same instrument the release-stamp and return-path arms use, and the same limit applies:
 * it proves the ENTRY IS DECLARED, not that the sidebar renders it for a given user.
 *
 * The rendering half is covered by the route's own middleware, which is what actually decides
 * whether a guardian may open the page.
 *
 * ── THE STRONGER GATE, NAMED AND NOT BUILT ──
 *
 * The general form is: every parent-facing route is either linked from the nav or on a stated
 * allowlist. That would catch the next page as well as this one. It is not built here because it
 * needs a route-to-nav map this codebase does not have, and inventing one on the evening before
 * resumption is the wrong trade. Named so it is a deferral rather than an omission.
 */
const sidebar = readFileSync(
    fileURLToPath(new URL('../components/app-sidebar.tsx', import.meta.url)),
    'utf8',
);

/** The guardian's nav array, isolated so an entry elsewhere in the file cannot satisfy these arms. */
function guardianNavBlock(): string {
    const start = sidebar.indexOf('const guardianNavGroups');

    // THE EXTRACTION IS ASSERTED, not assumed. If the array is renamed this returns -1, `slice`
    // would hand back the whole file, and every arm below would pass against the ADMIN nav — a
    // matcher that silently widens to something that satisfies it is the broken-open shape.
    expect(start).toBeGreaterThan(-1);

    const end = sidebar.indexOf('const superAdminNavGroups');
    expect(end).toBeGreaterThan(start);

    return sidebar.slice(start, end);
}

describe('the guardian navigation', () => {
    it('links to the fees page', () => {
        expect(guardianNavBlock()).toContain("href: '/parent/finance'");
    });

    it('still links to the wards page, so this arm cannot pass by replacing one entry with another', () => {
        // THE KNOWN NEGATIVE'S COUSIN. Without it, swapping `My Wards` for `Fees` satisfies the arm
        // above while removing a page a guardian also needs — the gap moves rather than closes.
        expect(guardianNavBlock()).toContain("href: '/parent/wards'");
    });

    it('does not reach the fees entry by matching some other nav group', () => {
        // The admin sidebar also links to a finance surface (`/finance`). This asserts the guardian
        // block itself carries the parent path, so the extraction above is doing real work.
        const block = guardianNavBlock();

        expect(block).not.toContain("href: '/finance'");
        expect(block.length).toBeLessThan(sidebar.length);
    });
});
