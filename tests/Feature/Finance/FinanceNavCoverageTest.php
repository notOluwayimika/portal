<?php

/*
 * THE GUARD AGAINST A SHIPPED-AND-UNREACHABLE SCREEN (Finance in the sidebar).
 *
 * THE DEFECT THIS EXISTS FOR SURVIVED FOUR PULL REQUESTS. `/finance`, `/finance/approvals` and
 * `/finance/opening-balances/import` were registered, permission-gated and rendered — and reachable
 * from no menu anywhere. Every one of them was built, reviewed and merged without anybody noticing
 * that the only way to open it was to type the URL. app-sidebar.tsx did not contain the word
 * "finance" except in a comment describing "the compose-by-permission pattern Finance's nav
 * additions follow" — a pattern named after additions that did not exist.
 *
 * SAME SHAPE AND SAME REASONING AS ApprovalsQueueFeedCoverageTest, which exists because two live
 * approval feeds rendered on no screen. The lesson there generalises and is worth stating once:
 * a route is not a feature until something links to it, and nothing in a route file, a controller
 * test or a page test can observe that. Only a rule comparing the two can.
 *
 * IT READS THE TYPESCRIPT AS TEXT, which is ugly, and the precedent and the reason are the same as
 * ApprovalsQueueFeedCoverageTest's and NotificationDeepLinkRouteTest's: there is no JavaScript test
 * runner in this repository — package.json carries vite, eslint, prettier and tsc, and no vitest or
 * jest — so the choice is not "a nice test or an ugly one", it is this or nothing, and nothing is
 * what let three screens go unlinked.
 *
 * THE EXEMPTIONS ARE NAMED, NOT DEFAULTED. A finance GET route that is not a nav destination has to
 * say why in this file, where a reader looking for the missing item will find it — and the thing
 * that DOES link it is asserted, not asserted-about. There are four: the per-student statement,
 * (U11) the per-payment receipt, (U6) the per-run bulk-invoice-run report, and (U10) the per-payment
 * allocation screen.
 */

use App\Models\User;
use App\Support\EffectivePermissions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

const FNC_SIDEBAR = 'resources/js/components/app-sidebar.tsx';

/**
 * Finance GET routes that are deliberately NOT menu items, with the reason each one is exempt.
 *
 * A route in here is a decision. A route missing from BOTH this list and the sidebar is the defect.
 *
 * @var array<string, string>
 */
const FNC_NOT_NAV = [
    // Takes a student uuid, so there is no single URL a menu could point at. It is reached from the
    // accounts list, which links it twice — the row itself and a "View statement" action
    // (resources/js/pages/admin/finance/index.tsx). That is a real link, checked rather than assumed
    // by the arm below, so this exemption cannot quietly become "unreachable by a different route".
    'finance/students/{student}/statement' => 'per-student; linked from the accounts list at /finance',

    // U11's receipt. Takes a PAYMENT uuid, so there is no single URL a menu could point at — the
    // same reason as the statement above, one level further in. It is reached from the statement's
    // payments tab, which links EVERY row (including the migrated ones the route will refuse, which
    // is a rule of that screen, not an oversight — see PaymentReceiptController). The arm below
    // checks that link the way the statement's own exemption is checked, so this cannot quietly
    // become "unreachable by a different route".
    'finance/payments/{payment}/receipt' => 'per-payment; linked from every row of the statement’s payments tab',

    // U6 commit 4's per-run report. Takes a RUN uuid, so there is no single URL a menu could point
    // at — the same reason as the two above. It is reached from the runs list at
    // /finance/bulk-invoice-runs, which is itself a nav item and which links EVERY row, including a
    // failed one: a failed run's report is the only place its reason is readable, so hiding the link
    // there would hide the diagnosis. The arm below checks that link rather than trusting it.
    'finance/bulk-invoice-runs/{run}' => 'per-run; linked from every row of the runs list at /finance/bulk-invoice-runs',

    // U10's allocation screen. Takes a PAYMENT uuid — the same reason as the receipt one level up —
    // and it is reached from the statement's payments tab. Unlike the receipt, this link is
    // CONDITIONAL and that is a rule rather than styling: a payment with nothing unallocated has
    // nothing to direct, and the row shows the figure that says so beside the missing link. The arm
    // below asserts both halves.
    'finance/payments/{payment}/allocate' => 'per-payment; linked from the statement’s payments tab for every payment with an unallocated remainder',
];

function fncRead(string $relative): string
{
    return (string) file_get_contents(dirname(__DIR__, 3).'/'.$relative);
}

/**
 * Every registered GET route under /finance. Derived from the ROUTER, never from a list in this
 * file — a second hardcoded list is the thing being guarded against, and writing one here would
 * make this test agree with itself.
 *
 * @return list<string>
 */
function fncFinanceRoutes(): array
{
    $found = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        // WEB routes only. `api/v1/finance/**` is the data surface the pages fetch; it is not
        // something a human navigates to, and requiring nav entries for it would be nonsense.
        if (! str_starts_with($uri, 'finance') || ! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $found[$uri] = true;
    }

    $uris = array_keys($found);
    sort($uris);

    return $uris;
}

it('every finance page is reachable from the sidebar, or is named here as not a nav destination', function () {
    $sidebar = fncRead(FNC_SIDEBAR);
    $missing = [];

    foreach (fncFinanceRoutes() as $uri) {
        if (array_key_exists($uri, FNC_NOT_NAV)) {
            continue;
        }

        // The href a menu item would carry. Compared as a quoted string so a substring of a longer
        // path cannot satisfy a shorter one: '/finance' must not be matched by
        // '/finance/approvals'.
        if (! str_contains($sidebar, "'/{$uri}'")) {
            $missing[] = $uri;
        }
    }

    expect($missing)->toBe([],
        'A finance page is registered, permission-gated and reachable from NO menu: '
        .implode(', ', array_map(fn (string $u) => "/{$u}", $missing))
        .'. Add it to the Finance group in '.FNC_SIDEBAR.', or — if it genuinely cannot be a menu '
        .'item — add it to FNC_NOT_NAV in this file WITH THE REASON and with whatever does link to '
        .'it. This is the exact defect that shipped three unreachable finance screens across four '
        .'pull requests.');
});

it('keeps the not-a-nav-destination list honest — every exemption is a live route', function () {
    // The other direction. An exemption for a route that no longer exists is a comment pretending
    // to be a decision, and it would silently absorb a future route of the same name.
    $live = fncFinanceRoutes();
    $stale = array_values(array_diff(array_keys(FNC_NOT_NAV), $live));

    expect($stale)->toBe([], 'FNC_NOT_NAV exempts a route that is not registered: '.implode(', ', $stale));
});

it('the statement exemption really is linked from the accounts list', function () {
    // The exemption's REASON, asserted rather than trusted. "It is linked from somewhere else" is
    // the only thing that makes a non-nav route acceptable, and an unchecked claim of it is how a
    // page becomes unreachable while looking accounted for.
    $accounts = fncRead('resources/js/pages/admin/finance/index.tsx');

    expect($accounts)->toContain('/finance/students/${row.student.uuid}/statement');
});

it('the receipt exemption really is linked from the statement, and the flag is used exactly once', function () {
    /*
     * The same check, one level in, for U11 — and it asserts two separate things because the second
     * is a RULE and not a styling choice.
     *
     * The link exists: the statement builds it through the wayfinder action for the receipt
     * controller, so the path lives in routes/web.php alone and a rename cannot leave a dead link.
     *
     * And the entry point is UNCONDITIONAL. The opening-balance spec's wording is "never silently
     * hide the row", so a migrated payment — the one the route will refuse — must still carry the
     * link; the row states the refusal beside it instead.
     *
     * ── HOW THIS IS MEASURED, AND EXACTLY WHAT IT DOES NOT COVER ──
     *
     * `receiptable` appears in this file EXACTLY ONCE, on the chip that states the refusal in place.
     * That is the whole rule, so the assertion is a whole-source occurrence count rather than a
     * window between two offsets. The first version of this arm counted only between the chip and
     * the anchor, and a cold review broke it with two mutations in ten minutes:
     *
     *   • wrapping the whole `<td>` in `{payment.receiptable && …}` — chip AND link vanish — PASSED,
     *     because the added occurrence sits BEFORE the window;
     *   • filtering the rows upstream (`.filter((p) => p.receiptable)`) so the row never renders at
     *     all — PASSED, for the same reason. That second one is literally the "silently hide the
     *     row" the spec forbids, sailing through a guard whose comment claimed to stop it.
     *
     * The count below reds on all three shapes, including the two that used to pass, because every
     * one of them names the flag a second time.
     *
     * WHAT IT STILL CANNOT SEE, stated rather than implied — this is a TEXT check on a file, not a
     * behavioural one, and there is no JavaScript test runner in this repository (package.json
     * carries vite, eslint, prettier and tsc; no vitest, no jest):
     *
     *   • a row filtered out by something OTHER than this flag — `receipt_refusal_reason !== null`,
     *     a `method === 'migrated'` test, or a filter applied server-side in the feed. Nothing here
     *     would notice, and nothing in this repository could;
     *   • whether the link, once rendered, is clickable, enabled, or points anywhere useful.
     *
     * A false positive is possible and is by design: mentioning `receiptable` a second time even in
     * a COMMENT reds this arm. The message says so, because a guard whose failure is unexplained is
     * one people delete.
     */
    $statement = fncRead('resources/js/pages/admin/finance/statement.tsx');

    expect($statement)->toContain('PaymentReceiptController')
        ->and($statement)->toContain('receiptUrl.url(')
        // The chip is what `receiptable` gates. The LINK is not.
        ->and($statement)->toContain('!payment.receiptable');

    expect(substr_count($statement, 'receiptable'))->toBe(1,
        'The statement mentions `receiptable` more than once. There is exactly one legitimate use — '
        .'the chip that states the refusal in place — and every second use found so far has been a '
        .'HIDE: the receipt cell wrapped in it, or the payment rows filtered by it. "Never silently '
        .'hide the row" is the opening-balance spec\'s wording and the refusal is the server\'s '
        .'(PaymentReceiptController), not this screen\'s. If you have only NAMED the flag in a new '
        .'comment, reword the comment — this check cannot tell the two apart.');
});

it('the bulk-invoice-run exemption really is linked from the runs list, unconditionally', function () {
    /*
     * The same check as the two above, for U6 commit 4 — and it asserts the link is UNCONDITIONAL,
     * which is a rule and not a styling choice.
     *
     * The link exists: the list builds it through the service module's `pageUrl`, which reads the
     * wayfinder route helper, so the path lives in routes/web.php alone and a rename breaks the build
     * rather than the screen.
     *
     * And EVERY row carries it, including a FAILED one. A failed run's report is the only place its
     * `failure_reason` and its rows are readable — hiding the link on the rows that failed would hide
     * the one thing an operator can act on, which is the silent-omission shape this whole feature was
     * built to end. The measurement is the same whole-source occurrence count the receipt arm settled
     * on, after a windowed version there passed two mutations that hid the row entirely: `run.status`
     * appears in the list ONLY where the status is displayed, so wrapping the link in a status test,
     * or filtering the rows by status upstream, both name it again and both red this arm.
     *
     * WHAT IT STILL CANNOT SEE, stated rather than implied — this is a TEXT check on a file, and
     * there is no JavaScript test runner in this repository: a row filtered out by something OTHER
     * than the status, or whether the link, once rendered, points anywhere useful.
     */
    $list = fncRead('resources/js/pages/admin/finance/bulk-invoice-runs/index.tsx');

    expect($list)->toContain('bulkInvoiceRuns.pageUrl(')
        ->and($list)->toContain('data-testid="bulk-invoice-run-row"');

    expect(substr_count($list, 'run.status'))->toBe(4,
        'The runs list reads `run.status` a different number of times than the four that are legitimate '
        .'(the in-flight poll predicate, the tone map, the label map and the icon). Every additional use found so far in this '
        .'codebase has been a HIDE — a row wrapped in a status test, or rows filtered by status — and '
        .'a failed run whose report cannot be opened hides the only place its reason is readable. If '
        .'you have only NAMED the status in a new comment, reword the comment: this check cannot tell '
        .'the two apart.');
});

it('gates the Finance group on the permission its routes require', function () {
    // The group must key on `finance.access`, because the whole /finance route group requires it —
    // an item shown to someone without it renders a menu entry that 403s on click, which is worse
    // than no entry at all.
    $sidebar = fncRead(FNC_SIDEBAR);

    expect($sidebar)->toContain("can('finance.access')")
        ->and($sidebar)->toContain("label: 'Finance'");
});

it('DERIVES the approvals item from the checker convention rather than listing abilities', function () {
    // /finance/approvals is gated server-side on a set built from Permission::cases() — every
    // finance ability whose terminal segment is a checker one — so a future finance.refund.approve
    // joins the ROUTE the day the case exists. A hard-coded array in the sidebar would not join with
    // it, and the item would be hidden from a checker the route would happily admit: a live
    // permission with no way in, which is this whole file's subject.
    //
    // Asserted as the SHAPE of the predicate, not as a list of abilities — a list here would be the
    // very thing it forbids.
    $sidebar = fncRead(FNC_SIDEBAR);

    expect($sidebar)->toContain(".endsWith('.approve')")
        ->and($sidebar)->toContain(".endsWith('.reject')")
        ->and($sidebar)->toContain("startsWith('finance.')");

    // And the ability names must NOT appear one by one. `finance.credit-note.approve` written into
    // the sidebar is the hard-coded list this rule exists to refuse.
    expect($sidebar)->not->toContain('finance.credit-note.approve');
});

it('a super_admin sees the Finance group but NOT Approvals, because ADR 0040 denies them the route', function () {
    // THE ONE SEAT THE BROWSER DRIVE COULD NOT COVER — the local copy has no super_admin holder,
    // and minting a platform authority into a production copy to take a screenshot is a bigger act
    // than the screenshot is worth. This asserts the thing the screenshot would have shown, and it
    // asserts it closer to the cause.
    //
    // The sidebar's Approvals item keys on the user's EFFECTIVE set, and EffectivePermissions
    // resolves each ability through the full Gate — so ADR 0040's checker exclusion folds in:
    // `approve`/`reject` are excluded from the super-admin Gate::before bypass, so a super_admin
    // holds NO finance checker ability and the item is hidden. That is CORRECT rather than
    // unfortunate: /finance/approvals would refuse them too, and a menu entry onto a screen its
    // holder can never act on is exactly what this file exists to prevent — in the other direction.
    $this->seed(DatabaseSeeder::class);
    config(['auth.gate_before_superadmin' => true]);

    $superAdmin = User::factory()->create();
    setPermissionsTeamId(null);
    $superAdmin->assignRole('super_admin');
    $superAdmin->flushSchoolAccessCache();

    $effective = collect(EffectivePermissions::for($superAdmin));
    $checkerAbilities = $effective->filter(
        fn (string $ability) => str_starts_with($ability, 'finance.')
            && (str_ends_with($ability, '.approve') || str_ends_with($ability, '.reject')),
    )->values();

    expect($checkerAbilities->all())->toBe([],
        'A super_admin now holds a finance CHECKER ability, so the sidebar would offer them an '
        .'Approvals item onto a screen ADR 0040 makes the backend refuse. Either the bypass '
        .'exclusion regressed or a checker ability stopped ending in .approve/.reject.');

    // Not vacuous in either direction: the group itself IS shown to them, and the maker item with
    // it, because neither is a checker action and the bypass reaches both.
    expect($effective)->toContain('finance.access')
        ->and($effective)->toContain('finance.opening-balance.submit');
});

it('the allocation-screen exemption really is linked from the statement, and the link is gated on the server’s own flag', function () {
    /*
     * The same check as the two above, for U10 — and like the receipt's it asserts more than "a link
     * exists", because the interesting part is what the link is gated ON.
     *
     * THE GATE IS THE SERVER'S FLAG, NOT JS ARITHMETIC. `can_allocate` is derived in PaymentResource
     * from Payment::unallocatedAmount(); the statement renders it. A `payment.unallocated.amount_minor
     * > 0` in its place would be the UI re-deriving an eligibility rule the server owns — the
     * can_approve lesson, and the reason InvoiceSettlement exists at all. It would also be a
     * money-lint finding away from becoming money arithmetic in a page.
     *
     * AND THE FIGURE IS UNCONDITIONAL EVEN THOUGH THE LINK IS NOT. A payments tab that showed the
     * unallocated column only for rows that have one would leave an operator unable to tell "settled
     * in full" from "this column does not apply here".
     *
     * WHAT THIS CANNOT SEE, stated rather than implied — it is a text check on a file, and there is no
     * JavaScript test runner in this repository: whether the link renders, whether the permission gate
     * around it resolves, or whether the page it opens works. Only that the statement names the route,
     * the flag and the figure.
     */
    $statement = fncRead('resources/js/pages/admin/finance/statement.tsx');

    expect($statement)->toContain('/finance/payments/${payment.id}/allocate')
        // Gated on the ability as well as on the flag — offering a control the server will refuse
        // teaches an operator that the screen lies.
        ->and($statement)->toContain('permission="finance.payment.allocate"')
        ->and($statement)->toContain('payment.can_allocate')
        // The figure, which is NOT behind the same condition.
        ->and($statement)->toContain('payment.unallocated');

    // The eligibility rule is the server's exactly once. A second mention has, on this very file's
    // sibling arm, always turned out to be a HIDE — the cell wrapped in it, or the rows filtered by
    // it — so the count is the assertion rather than a window between two offsets.
    expect(substr_count($statement, 'can_allocate'))->toBe(1,
        'The statement mentions `can_allocate` more than once. There is exactly one legitimate use — '
        .'the condition on the Allocate link — and a second one has historically been a filter that '
        .'removes the row entirely.');

    // And the UI must not re-derive the rule from the amount. This is the shape the server flag exists
    // to replace, and it is one edit away from being money arithmetic in a finance page.
    expect($statement)->not->toContain('unallocated.amount_minor >');
});
