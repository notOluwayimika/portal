<?php

use Illuminate\Support\Facades\Route;

/**
 * THE GUARD AGAINST THE DEFECT RECURRING (§9 step 5a).
 *
 * The defect was not "two feeds were missing from the approvals page". It was that the page could
 * not tell you which feeds it had: it imported two by hand while four were live and ability-gated at
 * the API, so `fee-schedule-changes/pending` and `discount-policy-changes/pending` shipped, were
 * reachable, and were rendered nowhere — an approver holding finance.fee-schedule.change.approve had
 * no screen at all, and no test, lint or type anywhere said so. Adding the missing two fixes an
 * INSTANCE. This file is the fix for the CAUSE: the page's feeds are declared in one module and this
 * test pins that declaration to the routes that actually exist, in BOTH directions.
 *
 * IT READS THE TYPESCRIPT AS TEXT, which is ugly, and the precedent and the reason are the same as
 * NotificationDeepLinkRouteTest's (which reads use-notifications.ts to keep a deep link from dying
 * silently): there is no JavaScript test runner in this repository — package.json has vite, eslint,
 * prettier and tsc, and no vitest or jest — so the choice is not "a nice test or an ugly one", it is
 * this or nothing, and nothing is what let two feeds go missing.
 *
 * WHAT IT WOULD CATCH. Delete an entry from APPROVAL_FEEDS: red, naming the route that no screen
 * renders. Add a sixth `…/pending` route and forget the entry: red, the same way, on the day the
 * route lands rather than whenever someone next looks. Delete a route but leave the entry: red, so
 * the page never ships a fetch at a URL that 404s.
 */
const AQF_FEEDS_MODULE = 'resources/js/lib/finance/approval-feeds.ts';

const AQF_PAGE = 'resources/js/pages/admin/finance/approvals.tsx';

function aqfRead(string $relative): string
{
    return (string) file_get_contents(dirname(__DIR__, 3).'/'.$relative);
}

/**
 * Every registered pending feed under the Finance API prefix, as `controller short name => uri`.
 * Derived from the ROUTER, never from a list in this file — a second hardcoded list is the thing
 * being guarded against, and writing one here would make this test agree with itself.
 *
 * @return array<string, string>
 */
function aqfPendingRoutes(): array
{
    $found = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        if (! str_starts_with($uri, 'api/v1/finance/') || ! str_ends_with($uri, '/pending')) {
            continue;
        }

        // GET and HEAD are the same registration; count it once.
        $action = (string) $route->getAction('controller');
        $controller = class_basename(explode('@', $action)[0]);
        $found[$controller] = $uri;
    }

    ksort($found);

    return $found;
}

/**
 * The controllers the declared feed list imports. The per-controller import line is the load-bearing
 * thing this reads, which is why the module's docblock says so.
 *
 * @return list<string>
 */
function aqfDeclaredControllers(string $source): array
{
    // Anchored to a real import STATEMENT — trailing `';` at end of line. An unanchored match also
    // hits the same path written inside a docblock, which is a comment, not a feed.
    preg_match_all(
        "#from '@/actions/App/Finance/Http/Controllers/([A-Za-z]+)';$#m",
        $source,
        $matches
    );

    $controllers = array_values(array_unique($matches[1]));
    sort($controllers);

    return $controllers;
}

it('every pending feed the API registers is declared on the approvals queue, and nothing else is', function () {
    $routes = aqfPendingRoutes();
    $declared = aqfDeclaredControllers(aqfRead(AQF_FEEDS_MODULE));

    $registered = array_keys($routes);

    $unrendered = array_values(array_diff($registered, $declared));
    $phantom = array_values(array_diff($declared, $registered));

    expect($unrendered)->toBe([], 'A pending feed is live at the API and rendered on NO screen: '
        .implode(', ', array_map(fn (string $c) => "{$c} (GET /{$routes[$c]})", $unrendered))
        .' — add it to '.AQF_FEEDS_MODULE.'. This is the exact defect §9 step 5a fixed.')
        ->and($phantom)->toBe([], 'The approvals queue declares a feed with no registered route: '
            .implode(', ', $phantom).' — the page would fetch a URL that does not exist.');

    // The count is asserted separately so a DUPLICATE entry (same controller twice — a merge
    // artefact that the set comparison above cannot see) is caught: it would render every row of
    // that type twice.
    $entries = preg_match_all("/^\s+type: '/m", aqfRead(AQF_FEEDS_MODULE));
    expect($entries)->toBe(count($registered),
        "APPROVAL_FEEDS has {$entries} entries for ".count($registered).' registered pending routes.');
});

/**
 * THE PAGE HOLDS NO FEED KNOWLEDGE. Two hardcoded imports are how the queue lost two types; this
 * asserts there are now zero. A future edit that reaches for one more import instead of one more
 * declaration is the defect being reintroduced, and it fails here.
 */
it('the approvals page imports no Finance controller action directly — every feed comes off the declared list', function () {
    expect(aqfDeclaredControllers(aqfRead(AQF_PAGE)))->toBe([]);
});

/**
 * THE ERROR RULE IS "ALL REJECTED", GENERALISED. It read "both rejected" when there were two feeds,
 * which was correct at N=2 and silently wrong at N>2: a checker holding ONE of the five approve
 * abilities gets four 403s on every single load, and under any rule of the shape "some rejected" or
 * "not all fulfilled" they would be shown a broken queue for holding exactly the authority they were
 * granted. The rule must therefore be expressed over the WHOLE array, so it stays true at six feeds.
 *
 * This is a SOURCE assertion rather than a behavioural one, and that limit is real: it pins the shape
 * of the condition, not what React renders. There is no JS test runner to do better with. The
 * behavioural half — that a one-ability checker genuinely receives one 200 and four 403s, and a
 * checker with no approve ability receives five — is proven over HTTP in
 * ApprovalsQueueRendersEveryTypeTest.
 */
it('the queue errors only when EVERY feed fails, expressed over the whole array', function () {
    $page = aqfRead(AQF_PAGE);

    // Asserted as booleans rather than with toContain, so a failure prints the claim instead of the
    // entire 400-line page source.
    $arrayWide = str_contains($page, "settled.every((result) => result.status === 'rejected')");

    // A fixed-arity conjunction — `x.status === 'rejected' && y.status === 'rejected'` — is the
    // shape that was right for two and wrong for five. It must not come back.
    $fixedArity = preg_match("/status === 'rejected' &&/", $page) === 1;

    expect($arrayWide)->toBeTrue(AQF_PAGE.' no longer errors on `settled.every(... rejected)`. '
        .'The error rule must be expressed over the whole feed array, not a fixed number of feeds.')
        ->and($fixedArity)->toBeFalse(AQF_PAGE.' errors on a FIXED-ARITY conjunction of rejections. '
            .'That rule was correct at two feeds and shows a one-ability checker a broken queue at five.');
});
