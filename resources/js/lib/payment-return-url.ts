/**
 * WHERE PAYSTACK RETURNS THE PAYER — the one place this path is written on the client.
 *
 * ── WHY A MODULE AND NOT AN INLINE TEMPLATE STRING ──
 *
 * The pay screen sends this as `callback_url` when it starts a payment; `GatewayReturnController`
 * answers at the other end. **Those two live on different branches and neither fails if the wiring
 * is missing** — Paystack simply falls back to the dashboard's default return URL, the parent lands
 * somewhere generic, and nothing reds. That is the shape this project spends its time eliminating:
 * a correctness requirement with no local failure signal.
 *
 * Pulling the path out gives it two: a vitest arm over this value, and a PHP arm asserting the
 * literal below matches the registered route (`GatewayReturnRouteTest`). Move either side and one of
 * them goes red.
 *
 * ── PURE, SO IT IS TESTABLE WITHOUT A DOM ──
 *
 * `vitest.config.ts` runs in `node` and argues against installing jsdom for anything that does not
 * genuinely need to render. The origin is a parameter rather than a read of `window`, so the
 * function is a value transformation and the component supplies the browser part.
 */

/** The path `GatewayReturnController` is registered at. Pinned against the route in PHP. */
export const PAYMENT_RETURN_PATH = '/parent/payments/return';

/**
 * The absolute URL Paystack should return the payer to.
 *
 * ABSOLUTE, because it leaves this origin and comes back. Built from the caller's origin rather than
 * a configured base so it is correct in every environment without a second place to keep in step.
 *
 * IT CARRIES NO REFERENCE. Paystack appends its own `?reference=` and `?trxref=`, and the return
 * controller settles from `verify()` rather than from either — so there is nothing for this URL to
 * assert about the payment, and anything it did assert would be a number in a URL a browser sends.
 */
export function paymentReturnUrl(origin: string): string {
    return `${origin.replace(/\/+$/, '')}${PAYMENT_RETURN_PATH}`;
}
