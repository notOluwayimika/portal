import { describe, expect, it } from 'vitest';

import { baseLabel, rowSubject } from '@/lib/finance/approval-feeds';
import type { PendingApproval } from '@/types/finance';

import { amendBase, changeTerms, valueLabel } from './discount-policies';

/**
 * The discount-policy form's two pure decisions, and the phrase it shares with the approvals queue.
 *
 * WHAT THIS FILE PROVES AND WHAT IT DOES NOT, stated up front because the screen's job is split
 * across a boundary this runner cannot cross. There is no DOM environment in vitest.config.ts and no
 * component renderer in the project, so nothing here clicks the radio group or watches an axios
 * request leave. What IS covered is every rule that decides the wire shape: which base an amendment
 * opens on, and which keys the request carries for each basis. What is NOT covered is the single
 * line in send() that spreads changeTerms' result into axios.post, and the JSX that binds the radio
 * group to form.base — an unguarded seam, named rather than papered over with an endpoint test
 * wearing a form test's name. The endpoint's own arms live in BssPerStudentDiscountTest.
 *
 * The same split money-input.test.ts makes, for the same reason.
 */

/** A catalog row, defaults chosen so each arm below overrides only the axis it is about. */
function policy(
    over: Partial<Parameters<typeof amendBase>[0]>,
): Parameters<typeof amendBase>[0] {
    return {
        id: 'p1',
        name: 'Sibling discount',
        description: null,
        basis: 'percent',
        value_minor: null,
        value_currency: null,
        percent: 50,
        base: 'discountable',
        requires_approval: false,
        status: 'active',
        ...over,
    };
}

/** The form as the modal holds it. `base` is never absent — EMPTY seeds it and openAmend reseeds it. */
function form(
    over: Partial<Parameters<typeof changeTerms>[1]>,
): Parameters<typeof changeTerms>[1] {
    return {
        name: 'Sibling discount',
        description: '',
        basis: 'percent',
        amountMinor: null,
        percent: '50',
        base: 'discountable',
        requiresApproval: false,
        reason: 'because',
        ...over,
    };
}

describe('changeTerms — which keys reach the server', () => {
    it('posts the base with the percentage on a percent create', () => {
        const terms = changeTerms('create', form({ base: 'total' }));

        expect(terms.percent).toBe(50);
        expect(terms.base).toBe('total');
    });

    /**
     * NOT `toBeUndefined()` — `prohibited_if:basis,amount` refuses the KEY, so a present key holding
     * undefined would be a 422 the moment axios serialised it away or did not. The assertion is
     * about the key's existence, which is what the FormRequest tests.
     */
    it('omits the base entirely on an amount basis, even when the form holds one', () => {
        const terms = changeTerms(
            'create',
            form({ basis: 'amount', amountMinor: 25_000_00, base: 'total' }),
        );

        expect('base' in terms).toBe(false);
        expect('percent' in terms).toBe(false);
        expect(terms.value_minor).toBe(25_000_00);
        expect(terms.value_currency).toBe('NGN');
    });

    it('omits the value half on a percent basis', () => {
        const terms = changeTerms('create', form({}));

        expect('value_minor' in terms).toBe(false);
        expect('value_currency' in terms).toBe(false);
    });

    it('proposes no terms at all on a retire', () => {
        expect(changeTerms('retire', form({ base: 'total' }))).toEqual({});
    });
});

describe('amendBase — what the control opens on', () => {
    /**
     * The percent arm. The stored base is `total` and the default is `discountable`, so a seeding
     * rule that ignored the row would land on the other value and this arm would red.
     */
    it('seeds a percent policy from its own base, and the amendment posts it', () => {
        const target = policy({ basis: 'percent', base: 'total' });

        expect(amendBase(target)).toBe('total');
        expect(
            changeTerms('amend', form({ base: amendBase(target) })).base,
        ).toBe('total');
    });

    /**
     * The amount arm, and the fixture is what makes it discriminate: the amount policy's stored base
     * is `total`, which is INERT there and is not what the server would use. Seeding from the stored
     * value would return 'total' and this arm reds; seeding from the basis returns the default that
     * effectiveBase() gives a cross-basis amend (DiscountPolicyChange.php:118). Had the fixture
     * stored `discountable` the two rules would agree by coincidence and the arm would prove
     * nothing.
     */
    it('seeds an amount policy to the default, NOT to its inert stored base', () => {
        const target = policy({
            basis: 'amount',
            percent: null,
            value_minor: 25_000_00,
            value_currency: 'NGN',
            base: 'total',
        });

        expect(amendBase(target)).toBe('discountable');
        expect(
            changeTerms('amend', form({ base: amendBase(target) })).base,
        ).toBe('discountable');
    });
});

describe('baseLabel is the one copy of the two phrases', () => {
    /** A queue row for the same terms the catalog row below states. */
    function pending(base: 'discountable' | 'total'): PendingApproval {
        return {
            type: 'discount_policy_change',
            id: 'c1',
            kind: 'amend',
            name: 'Sibling discount',
            basis: 'percent',
            percent: 50,
            base: null,
            effective_base: base,
            reason: 'because',
            status: 'submitted',
            can_approve: true,
            can_reject: true,
            created_at: '2026-01-01T00:00:00Z',
        };
    }

    /**
     * THE DRIFT GUARD. The phrase is derived from each screen's own renderer and the two are
     * compared — not compared against a literal written here, which would only assert that this file
     * agrees with itself. Both bases are exercised and asserted DIFFERENT, so a renderer returning
     * one constant phrase cannot pass by collapsing the axis.
     */
    it.each(['discountable', 'total'] as const)(
        'the catalog and the approvals queue state the same phrase for %s',
        (base) => {
            const catalog = valueLabel(policy({ base }));
            const queue = rowSubject(pending(base));

            expect(catalog).toMatch(/^50% /);
            expect(queue).toContain(catalog);
        },
    );

    it('the two bases are not the same phrase', () => {
        expect(valueLabel(policy({ base: 'discountable' }))).not.toBe(
            valueLabel(policy({ base: 'total' })),
        );
    });

    /**
     * The wording itself, pinned once. The ED approves a phrase and reads it back on the policy
     * list, so a reword is a governance-visible change and should have to be made deliberately in
     * both directions — the arms above only prove the two screens AGREE, which a simultaneous edit
     * of one shared function keeps true through any wording at all.
     */
    it('states the two phrases the ED approves', () => {
        expect(valueLabel(policy({ base: 'discountable' }))).toBe(
            '50% of discountable charges',
        );
        expect(valueLabel(policy({ base: 'total' }))).toBe(
            '50% of the whole bill',
        );
    });

    /**
     * THE HALF OF A DOUBLE PIN THAT LIVES ON THIS SIDE OF THE LANGUAGE BOUNDARY.
     *
     * These two phrases are no longer only a screen's wording. The BSS discount-award import's
     * template offers them as the values of its `discount_applies_to` column — the same axis, in the
     * template's case — so `DiscountAwardImporter::APPLIES_TO_CANONICAL` is
     * `'DISCOUNTABLE CHARGES'` and `'THE WHOLE BILL'`, and the refusal messages read them into
     * `'%d%% of %s'`, which is `baseLabel`'s own sentence.
     *
     * IT IS TWO PINS AND NOT ONE LINK, DELIBERATELY. A vitest arm could scrape that PHP constant and
     * assert the two are derived from one another — real linkage, one source of truth. A regex over
     * another language's source fails in the direction that costs most close to a cutover: a false red
     * in `bin/quality` on a formatting change nobody made on purpose. So this arm pins the strings
     * here, tests/Feature/Finance/DiscountAwardImportScreenTest.php pins them there, and a reword reds
     * exactly one of the two and points at the other.
     *
     * WHAT THAT LEAVES OPEN, stated because a weaker form claimed as a strong one is worse than no
     * form at all: nothing FORCES the two files to change together, so a reword that edited both pins
     * without editing either renderer would pass. The pins catch the realistic mistake — one surface
     * reworded, the other forgotten — and not a deliberate coordinated one.
     *
     * `baseLabel` DIRECTLY, not through `valueLabel`. The arm above goes through the screen's
     * renderer, which is right for the ED's reading; this one is about the shared function that four
     * surfaces now read, so it asks that function.
     */
    it('is the same wording the discount-award import template offers', () => {
        expect(baseLabel('discountable')).toBe('of discountable charges');
        expect(baseLabel('total')).toBe('of the whole bill');
    });
});
