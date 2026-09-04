import { describe, expect, it } from 'vitest';
import { PAYMENT_RETURN_PATH, paymentReturnUrl } from './payment-return-url';

/**
 * The client half of the return wiring.
 *
 * WHAT THIS CANNOT PROVE, stated so nobody reads a green here as the whole guarantee: it asserts the
 * VALUE, not that `PayInvoice` sends it. Rendering the component needs a DOM environment
 * `vitest.config.ts` deliberately does not install, and the config says to add one when a test
 * genuinely needs to render — asserting one string is not that moment.
 *
 * The other half is `GatewayReturnRouteTest`, which pins this path against the registered route. The
 * gap that remains is a component that stops sending the value at all; that is visible in the diff
 * of one file and is the smaller risk than a path silently drifting from a route.
 */
describe('paymentReturnUrl', () => {
    it('points at the route the return controller is registered at', () => {
        // THE PATH IS ASSERTED AS A LITERAL, not by reusing the constant, or the arm would say only
        // that the constant equals itself. The PHP side pins the same literal to the route.
        expect(PAYMENT_RETURN_PATH).toBe('/parent/payments/return');
    });

    it('builds an absolute URL from the caller origin', () => {
        expect(paymentReturnUrl('https://portal.example.test')).toBe(
            'https://portal.example.test/parent/payments/return',
        );
    });

    it('does not double the slash when the origin carries a trailing one', () => {
        // A real hazard rather than a hypothetical: `window.location.origin` has no trailing slash,
        // but a configured base URL pasted from a browser bar does, and `//parent/payments/return`
        // is a protocol-relative URL to a host named `parent` — it would leave the site entirely.
        expect(paymentReturnUrl('https://portal.example.test/')).toBe(
            'https://portal.example.test/parent/payments/return',
        );
    });

    it('carries no reference or query string of its own', () => {
        // Paystack appends `?reference=` and `?trxref=`. Anything this URL asserted about the
        // payment would be a number in a URL the payer's browser sends, and the controller settles
        // from `verify()` precisely because such numbers are not evidence.
        expect(paymentReturnUrl('https://portal.example.test')).not.toContain(
            '?',
        );
    });
});
