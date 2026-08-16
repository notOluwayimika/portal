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
 * that DOES link it is asserted, not asserted-about. There are two: the per-student statement, and
 * (U11) the per-payment receipt.
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

it('the receipt exemption really is linked from the statement, on EVERY payment row', function () {
    /*
     * The same check, one level in, for U11 — and it asserts two separate things because the second
     * is a RULE and not a styling choice.
     *
     * The link exists: the statement builds it through the wayfinder action for the receipt
     * controller, so the path lives in routes/web.php alone and a rename cannot leave a dead link.
     *
     * And it is UNCONDITIONAL. The opening-balance spec's wording is "never silently hide the row",
     * so a migrated payment — the one the route will refuse — must still carry the link; the row
     * states the refusal beside it instead. Asserting the link is not wrapped in `receiptable` is
     * what stops the next reasonable-looking edit ("don't offer a receipt we won't issue") from
     * quietly turning the refusal back into a hide.
     */
    $statement = fncRead('resources/js/pages/admin/finance/statement.tsx');

    expect($statement)->toContain('PaymentReceiptController')
        ->and($statement)->toContain('receiptUrl.url(')
        // The chip is what `receiptable` gates. The LINK is not.
        ->and($statement)->toContain('!payment.receiptable');

    // The link must not sit inside a `receiptable` conditional. Measured on the source between the
    // flag's only use (the chip) and the anchor: the anchor comes AFTER the chip's closing brace, at
    // the same level, so no `payment.receiptable &&` may appear between the chip block and the link.
    $chipAt = strpos($statement, '!payment.receiptable');
    $linkAt = strpos($statement, 'receiptUrl.url(');
    expect($linkAt)->toBeGreaterThan($chipAt);
    expect(substr_count(substr($statement, $chipAt, $linkAt - $chipAt), 'payment.receiptable'))->toBe(1);
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
