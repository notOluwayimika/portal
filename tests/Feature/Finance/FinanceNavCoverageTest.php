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

    // U7's invoice detail. Takes an INVOICE uuid — the same reason as the three above. It is
    // reached from the statement's invoices table, which links EVERY row including a voided one:
    // voidness is a REPORTING default and not an existence one, the detail page renders the void
    // trail rather than 404-ing, and a reader who has opened the audit view (?include_void=1) has
    // come precisely to read it.
    //
    // The arm below checks that link, the way the statement's own exemption and the receipt's are
    // checked, so this cannot quietly become "unreachable by a different route".
    'finance/invoices/{invoice}' => 'per-invoice; linked from every row of the statement’s invoices table',

    // Bulk manual invoicing's per-run report. Takes a RUN uuid, so there is no single URL a menu
    // could point at — the same reason as the five above, and the identical shape to U6's bulk-run
    // report two entries up.
    //
    // WHAT LINKS IT IS NOT A LIST, AND THAT IS A GAP RATHER THAN A DESIGN. There is no index of past
    // manual runs — no endpoint returns one — so the report is reached from the selection screen at
    // /finance/manual-invoice-runs by exactly two routes: submit lands on it, and the one-active-run
    // refusal names the run in flight and offers a link to it. The arm below asserts both, because
    // an exemption whose reason is unchecked is how a page becomes unreachable while looking
    // accounted for. The missing list is recorded as a residual, not disguised here.
    'finance/manual-invoice-runs/{run}' => 'per-run; reached from /finance/manual-invoice-runs — submit lands on it, and the in-flight refusal links to it',

    // U7's printable invoice. Takes an INVOICE uuid, one level in from the detail — the receipt's
    // shape and the receipt's reasoning. It is reached from the detail's toolbar, UNCONDITIONALLY:
    // the link is offered on a voided invoice too, because the person who needs the document on
    // paper after a void is the one reconciling why the charge is gone, and the printed page states
    // the void rather than refusing to print. The arm below checks that link.
    'finance/invoices/{invoice}/print' => 'per-invoice; linked from the invoice detail’s toolbar',
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

it('the manual-invoice-run exemption really is linked from the selection screen, by BOTH routes', function () {
    /*
     * The same check as the four above, for bulk manual invoicing — and it asserts TWO links rather
     * than one, because unlike every other exemption in this file the thing linking here is not a
     * list. There is no index of manual runs, so if either of these two goes, a bursar who navigates
     * away from a run has no way back to its report at all.
     *
     * LINK ONE — SUBMIT LANDS ON THE REPORT. `router.visit(manualInvoiceRuns.pageUrl(data.uuid))`
     * immediately after a 201. That is the feature's design and not a convenience: Brookstone ruled
     * on 30 August 2026 that this issues DIRECTLY, so the report is the only account this act ever
     * gets, and a toast in its place would be the whole oversight replaced by a sentence.
     *
     * LINK TWO — THE IN-FLIGHT REFUSAL. A School's second non-terminal run is refused, and the
     * refusal NAMES the run already under way. The screen turns that name into a link, which is
     * currently the ONLY way back to a run whose uuid the operator did not keep.
     *
     * Both are built through the service module's `pageUrl`, which reads the wayfinder route helper,
     * so the path lives in routes/web.php alone and a rename breaks the build rather than the
     * screen.
     *
     * WHAT THIS CANNOT SEE, stated rather than implied — it is a TEXT check on a file, and there is
     * no JavaScript test runner in this repository (package.json carries vite, eslint, prettier and
     * tsc; no vitest, no jest): whether either link is reached at runtime, and whether the uuid
     * handed to it is the right one.
     */
    $screen = fncRead('resources/js/pages/admin/finance/manual-invoice-runs/index.tsx');

    // NOT matched as one multi-line string: prettier owns the wrapping of a nested call, so an
    // assertion carrying its indentation would red on a reformat that changed nothing. The two
    // facts are asserted separately, and the COUNT is what says there are two links rather than one
    // written twice.
    expect($screen)->toContain('manualInvoiceRuns.pageUrl(data.uuid)')
        ->and($screen)->toContain('inFlightUuid');

    expect(substr_count($screen, 'manualInvoiceRuns.pageUrl('))->toBeGreaterThanOrEqual(2,
        'The manual-invoicing screen builds fewer than two links to a run report. There is no index '
        .'of manual runs, so submit-lands-on-it and the in-flight refusal are the ONLY two ways a '
        .'bursar reaches one; losing either strands a run whose uuid they did not keep.');
});

it('the manual-invoicing screen holds NONE of the guardians select-all-matching vocabulary', function () {
    /*
     * THE ONE RULE THIS SCREEN EXISTS UNDER, made executable rather than left in a docblock.
     *
     * `guardians/bulk-action-bar.tsx` renders "Select all N matching" and sets a `selectAllMatching`
     * flag while the browser holds only the CURRENT PAGE's ids, so every action behind that bar runs
     * on those — the operator is told 240 and gets 25, and nothing errors
     * (docs/handoff/tickets/guardians-select-all-matching-claims-a-scope-it-does-not-have.md). In an
     * export that is a short spreadsheet. On THIS screen it would bill 25 families and report 240,
     * on a path where each wrong invoice is undone by its own void request and a second person's
     * approval.
     *
     * The brief states the rule as "do not import anything from guardians/bulk-action-bar.tsx",
     * because copying it is how the defect spreads. A prose rule with nothing behind it is
     * wallpaper, so this is the lint: neither the import nor the vocabulary may appear.
     *
     * ── HOW THE IMPORT HALF IS MEASURED, AND WHY IT IS A COUNT ──
     *
     * The first version of this arm was `not->toContain('guardians/bulk-action-bar')` and it FAILED
     * ON ITS OWN SUBJECT: the screen's docblock names that file, in the paragraph explaining why it
     * must never be imported. That is the false positive this file's `receiptable` arm already
     * records one screen over, met again — a text rule cannot tell a warning from the thing it warns
     * about.
     *
     * So the path is counted rather than forbidden. EXACTLY ONE occurrence is legitimate — the
     * docblock reference — and an import is a second one, which reds. If you are here because you
     * added a second MENTION in a comment, reword the comment: this check cannot tell the two apart,
     * and that is stated rather than left for you to discover.
     *
     * The two flag names carry no such tension and stay absolute: nothing on this screen has any
     * business naming them at all.
     */
    $screen = fncRead('resources/js/pages/admin/finance/manual-invoice-runs/index.tsx');

    expect(substr_count($screen, 'guardians/bulk-action-bar'))->toBe(1,
        'The manual-invoicing screen names guardians/bulk-action-bar.tsx a number of times other '
        .'than the one that is legitimate — the docblock explaining why it must not be imported. A '
        .'second occurrence is an import, and importing that bar is how "told 240, billed 25" '
        .'spreads onto a path where it bills families.');

    expect($screen)->not->toContain('selectAllMatching')
        ->and($screen)->not->toContain('totalMatching');

    // And the page-scoped warning is present. Clearing the selection on navigation without SAYING
    // so is the half of this compromise that costs the operator forty ticks with no notice.
    expect($screen)->toContain('data-testid="page-scoped-warning"');
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

it('the printable-invoice exemption really is linked from the invoice detail, and the link is unconditional', function () {
    /*
     * The same check the receipt's exemption carries, one document over — and it asserts the
     * SECOND half for the same reason the receipt's does. The receipt's entry point is
     * unconditional because the opening-balance spec forbids silently hiding the row; this one is
     * unconditional because a VOIDED invoice is still a document somebody reconciles from, and a
     * page that offered "Print" only while an invoice was live would hide it at the one moment the
     * paper is actually wanted.
     *
     * MEASURED AS: the detail page builds the print href, and the file names `isVoid` a bounded
     * number of times — never inside a condition wrapping the toolbar. A count is used rather than
     * a window between two offsets because a window is what a cold review broke twice on the
     * receipt's arm: wrapping the whole toolbar, or filtering upstream, both sit OUTSIDE any window
     * and both passed. Every shape that would hide this link names `isVoid` an extra time.
     *
     * WHAT IT CANNOT SEE, stated rather than implied — a text check on a file, with no JavaScript
     * test runner in this repository: whether the link renders, whether it is clickable, and
     * whether the page it reaches renders anything. The browser drive covers those.
     */
    $detail = fncRead('resources/js/pages/admin/finance/invoice.tsx');

    expect($detail)->toContain('/print')
        ->and($detail)->toContain('/finance/invoices/${invoice.id}/print');

    // `isVoid` appears exactly FOUR times, counted from the file rather than assumed: the
    // derivation (:129), the status badge's colour (:212), the void banner (:268) and the actions
    // block a voided invoice does not get (:344). The toolbar carrying the print link is NOT one of
    // them, and a fifth occurrence is the shape that would make it one.
    expect(substr_count($detail, 'isVoid'))->toBe(4,
        'resources/js/pages/admin/finance/invoice.tsx names `isVoid` a different number of times '
        .'than when this arm was written. If a void now gates the PRINT link or the toolbar, that '
        .'is the defect this arm exists for: the document is what someone reconciling a reversed '
        .'charge needs on paper. If it is an unrelated addition, re-derive the count and say in '
        .'this comment what the new occurrence is.');
});

it('the invoice-detail exemption really is linked from the statement, and the link is unconditional', function () {
    /*
     * U7's detail, checked exactly as the receipt's exemption is checked one document over — and
     * the second half is again a RULE rather than styling.
     *
     * The link exists: the statement's invoices table builds `/finance/invoices/{id}` from the
     * invoice's own uuid.
     *
     * And it is UNCONDITIONAL — every row, including a VOIDED one. Voidness is a REPORTING default
     * here (the audit view opts in with `?include_void=1`) and never an existence one, and the
     * detail page renders the void trail rather than 404-ing. A link rendered only while an invoice
     * is live would hide the page at the one moment somebody is asking why a charge disappeared.
     *
     * MEASURED AS: the href is present, and the file names the literal `'void'` a bounded number of
     * times — never around the link. A whole-source occurrence count rather than a window between
     * two offsets, for the reason the receipt's arm records: a cold review broke the windowed
     * version twice, by wrapping the enclosing element and by filtering the rows upstream, and both
     * mutations sat outside the window and passed. Every shape that would gate this link on
     * voidness names that literal an extra time.
     *
     * WHAT IT CANNOT SEE, stated rather than implied — a text check on a file, and there is no
     * JavaScript test runner in this repository: a row hidden by something that does not spell
     * `'void'` (a filter server-side in the feed, or on `cancelled_at`), and whether the link, once
     * rendered, is clickable or reaches anything. The browser drive covers the second.
     */
    $statement = fncRead('resources/js/pages/admin/finance/statement.tsx');

    expect($statement)->toContain('/finance/invoices/${invoice.id}');

    // `'void'` appears exactly TWICE in this file, counted from it rather than assumed (`grep -n
    // "'void'"`): the row's document-badge colour, and the actions cell a voided invoice does not
    // get. NEITHER is the link, and a third occurrence is the shape that would make one.
    expect(substr_count($statement, "'void'"))->toBe(2,
        'resources/js/pages/admin/finance/statement.tsx names the literal `\'void\'` a different '
        .'number of times than when this arm was written. If a void now gates the link to the '
        .'invoice detail, that is the defect this arm exists for: voidness is a reporting default '
        .'and the detail page renders the void trail rather than 404-ing, so the audit view must '
        .'be able to reach it. If it is an unrelated addition, re-derive the count and say in this '
        .'comment what the new occurrence is.');
});

/**
 * THE MIRROR OF THIS FILE'S RULE — a link must not point where its viewer is refused.
 *
 * Everything above asks whether a page is reachable from somewhere. This asks the inverse, and the
 * BROWSER DRIVE is what found it: `/finance/decisions` is gated on the group's `finance.access`
 * while `/finance/approvals` needs a finance CHECKER ability, and the decisions page's toolbar
 * offered a "Pending approvals" button to every viewer. `maker@drive.test` — an accounts_officer
 * holding `finance.access` and no checker ability — saw the control, and the route answered 403.
 *
 * That is a failure this repository already has a name for: a control a user can see, press and
 * fail on is worse than an absent one, because absent is honestly broken and present-and-dead is
 * dishonestly broken (`resources/js/lib/finance/approval-feeds.ts`, on `decidedElsewhere`).
 *
 * MEASURED AS: the href exists, and it is NOT unconditional. `isFinanceChecker` is named twice —
 * once where the predicate is derived from the viewer's own effective set, once where the button is
 * conditioned on it — so a whole-source count reds on the deletion of either, including the exact
 * edit that restores the defect (dropping the `{isFinanceChecker && (…)}` wrapper and leaving the
 * derivation in place, which looks guarded and is not).
 *
 * AND THE PREDICATE MUST STAY DERIVED. A hardcoded pair of abilities would hide the link from a
 * future `finance.refund.approve` checker whom the route would admit — the defect approval-feeds.ts
 * exists to kill, rebuilt one component along — so the arm asserts the convention's own shape
 * (`.approve` / `.reject` over `finance.`) rather than any particular ability name.
 *
 * WHAT IT CANNOT SEE, stated rather than implied: this is a text check on a file, and there is no
 * JavaScript test runner in this repository. It does not prove what React renders for a given
 * permission set. The drive covers that, and the drive is what is being pinned here.
 */
it('the decisions page offers the queue link only to a checker, on a DERIVED predicate', function () {
    $decisions = fncRead('resources/js/pages/admin/finance/decisions.tsx');

    $names = substr_count($decisions, 'isFinanceChecker');

    expect(str_contains($decisions, '/finance/approvals'))->toBeTrue(
        'resources/js/pages/admin/finance/decisions.tsx no longer links the pending queue at all. '
        .'The two surfaces are halves of one story and a checker arriving on one should be able to '
        .'reach the other; if the link was removed deliberately, remove this arm and say why.')
        ->and($names)->toBe(2,
            'resources/js/pages/admin/finance/decisions.tsx names `isFinanceChecker` '.$names.' times, '
            .'not 2 (the derivation, and the condition on the button). One occurrence is the defect '
            .'the browser drive found: the derivation left in place while the button renders '
            .'unconditionally — which looks guarded and 403s for every non-checker who presses it.')
        ->and(str_contains($decisions, "ability.endsWith('.approve')"))->toBeTrue(
            'resources/js/pages/admin/finance/decisions.tsx no longer derives the checker predicate '
            .'from the ApprovalAbility convention. A hardcoded ability list hides the link from the '
            .'next checker type the route already admits.');
});
