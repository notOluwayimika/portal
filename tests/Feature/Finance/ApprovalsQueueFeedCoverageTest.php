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
 * Just the APPROVAL_FEEDS array literal. Counting `type:` or `subject:` over the whole file also
 * counts the ApprovalFeed TYPE DECLARATION's members, which are not entries — the first version of
 * the subject test did exactly that and read 6 subjects for 5 entries. Narrow to the body, so the
 * counts are of things that are actually feeds.
 */
function aqfFeedsArrayBody(string $source): string
{
    $start = strpos($source, 'export const APPROVAL_FEEDS');
    $end = strpos($source, "\n];", (int) $start);

    return substr($source, (int) $start, (int) $end - (int) $start);
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

/**
 * The local aliases the feed list IMPORTS its pending routes under — `pending as pendingVoid` →
 * `pendingVoid`. A multiset, not a set: a duplicate import is itself worth seeing.
 *
 * @return list<string>
 */
function aqfImportedPendingAliases(string $source): array
{
    preg_match_all('/^\s+pending as ([A-Za-z]+),?$/m', $source, $matches);

    // The single-line import form (`import { pending as x } from '…';`) does not match the
    // multi-line shape above, so it is picked up separately.
    preg_match_all('/^import \{ pending as ([A-Za-z]+) \} from /m', $source, $single);

    $aliases = [...$matches[1], ...$single[1]];
    sort($aliases);

    return $aliases;
}

/**
 * The aliases each ENTRY actually calls in its `pendingUrl` — `pendingUrl: () => pendingVoid.url()`
 * → `pendingVoid`. This is the half that says what the page fetches, as opposed to what it imports.
 *
 * @return list<string>
 */
function aqfWiredPendingAliases(string $source): array
{
    preg_match_all('/pendingUrl: \(\) => ([A-Za-z]+)\.url\(\)/', $source, $matches);

    $aliases = $matches[1];
    sort($aliases);

    return $aliases;
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
    $entries = preg_match_all("/^\s+type: '/m", aqfFeedsArrayBody(aqfRead(AQF_FEEDS_MODULE)));
    expect($entries)->toBe(count($registered),
        "APPROVAL_FEEDS has {$entries} entries for ".count($registered).' registered pending routes.');
});

/**
 * IMPORT COVERAGE IS NOT WIRING COVERAGE, and the test above only had the first half. Every import
 * can be present, all five entries can exist, both directions of the route comparison can agree —
 * and one entry can still be pointed at another feed's url:
 *
 *     { type: 'void', …, pendingUrl: () => pendingCredit.url() }
 *
 * That is green under every assertion above, and it fetches credit notes twice while fetching void
 * requests never. Pending voids then render on NO screen: the exact defect this file is named for,
 * reintroduced inside the one file whose docblock tells you to be careful.
 *
 * So the aliases are pinned 1:1. Each `pending as X` import must be called by exactly one entry's
 * `pendingUrl`, and each entry must call an imported one. A multiset comparison, not a set — set
 * equality would pass the aliasing above the moment a sixth entry happened to mention the orphaned
 * alias somewhere.
 */
it('every imported pending alias is wired to exactly one entry, and every entry to an imported one', function () {
    $source = aqfRead(AQF_FEEDS_MODULE);

    $imported = aqfImportedPendingAliases($source);
    $wired = aqfWiredPendingAliases(aqfFeedsArrayBody($source));

    expect($wired)->toBe($imported, 'The declared feed list imports ['.implode(', ', $imported)
        .'] but its entries fetch ['.implode(', ', $wired).']. A duplicate on the right means one '
        .'type is fetched twice and another never — its rows render on no screen.');
});

/**
 * One entry's source text, keyed by its `type`. Sliced from the array body between successive
 * `type: '…'` lines, so a per-entry assertion can name WHICH entry is wrong instead of reporting
 * that the file as a whole contains or lacks a string.
 *
 * @return array<string, string>
 */
function aqfEntriesByType(string $body): array
{
    preg_match_all("/^\s+type: '([a-z_]+)',$/m", $body, $matches, PREG_OFFSET_CAPTURE);

    $entries = [];
    $count = count($matches[0]);

    for ($i = 0; $i < $count; $i++) {
        $start = (int) $matches[0][$i][1];
        $end = $i + 1 < $count ? (int) $matches[0][$i + 1][1] : strlen($body);
        $entries[(string) $matches[1][$i][0]] = substr($body, $start, $end - $start);
    }

    return $entries;
}

/**
 * THE PENDING-ALIAS PIN, EXTENDED TO THE DECISION URLS — and it is the same defect one door along.
 * An entry can import every alias correctly and still wire the wrong one:
 *
 *     { type: 'opening_balance', …, decide: { approve: (id) => approveCredit.url(id), … } }
 *
 * That posts a BATCH uuid at the credit-note approve endpoint. It is green under every assertion in
 * this file, and the failure it produces is a 404 in front of a checker who is trying to approve a
 * cutover — discovered at the worst possible moment, by the one person who cannot work around it.
 *
 * All five entries already name their aliases `<verb><Type>` — pendingCredit/approveCredit/
 * rejectCredit, pendingVoid/approveVoid/rejectVoid, and so on — so the wiring is checkable without
 * inventing anything: strip the verb, and an entry's three aliases must agree on what is left. A
 * rename that keeps the convention passes; one that breaks it fails loudly with the entry named,
 * which is the right trade against a mis-wired decision discovered in production.
 */
it('every decidable entry wires its approve and reject at the SAME controller its pending feed points to', function () {
    $entries = aqfEntriesByType(aqfFeedsArrayBody(aqfRead(AQF_FEEDS_MODULE)));

    $decidable = 0;

    foreach ($entries as $type => $source) {
        if (preg_match('/^\s+decide: \{/m', $source) !== 1) {
            continue;
        }

        $decidable++;

        preg_match('/pendingUrl: \(\) => ([A-Za-z]+)\.url\(\)/', $source, $pending);
        preg_match('/approve: \(id\) => ([A-Za-z]+)\.url\(id\)/', $source, $approve);
        preg_match('/reject: \(id\) => ([A-Za-z]+)\.url\(id\)/', $source, $reject);

        $suffix = fn (string $alias) => preg_replace('/^(pending|approve|reject)/', '', $alias);

        expect([$approve[1] ?? '', $reject[1] ?? ''])->not->toContain('',
            "The [{$type}] entry declares `decide` but does not wire both an approve and a reject url.");

        expect($suffix((string) $approve[1]))->toBe($suffix((string) $pending[1]),
            "The [{$type}] entry fetches with [{$pending[1]}] but approves with [{$approve[1]}] — "
            .'a decision posted at another type\'s endpoint.')
            ->and($suffix((string) $reject[1]))->toBe($suffix((string) $pending[1]),
                "The [{$type}] entry fetches with [{$pending[1]}] but rejects with [{$reject[1]}] — "
                .'a decision posted at another type\'s endpoint.');
    }

    // Not vacuous: a parse that matched no entry would satisfy the loop by never running.
    expect($decidable)->toBeGreaterThan(0, 'No entry was parsed as decidable — the loop above asserted nothing.');
});

/**
 * WHICH APPROVALS CANNOT BE UNDONE, WRITTEN DOWN.
 *
 * One approval on this queue is irreversible: an opening-balance batch's, which posts the school's
 * cutover into an append-only subledger under two triggers that deny every exit from `posted`
 * (G1/G1b). Its feed entry carries a `confirm`; the other four deliberately do not, because a dialog
 * on every approval is a dialog nobody reads — and the one that gets clicked through is then the one
 * that mattered.
 *
 * BOTH DIRECTIONS ARE PINNED, and the silent direction is why this test exists. Deleting `confirm`
 * from the opening-balance entry breaks nothing visible: the button simply fires on the click, and a
 * checker posts a cutover with one press. Nothing renders differently, no request fails, no type
 * complains. Adding one to a correctable type is the loud direction, and it is pinned too, because
 * that is how the confirmation stops meaning anything.
 *
 * The expected list is written out by name rather than derived. There is nothing in the TypeScript
 * that says which approvals are irreversible, so a derivation would be inventing an oracle; a sixth
 * type that cannot be undone must make this test red and force somebody to answer the question.
 */
it('exactly one approval type confirms before it fires, and it is the irreversible one', function () {
    $entries = aqfEntriesByType(aqfFeedsArrayBody(aqfRead(AQF_FEEDS_MODULE)));

    $confirming = array_keys(array_filter(
        $entries,
        fn (string $source) => preg_match('/^\s+confirm: /m', $source) === 1,
    ));

    expect($confirming)->toBe(['opening_balance'],
        'The approvals queue confirms ['.implode(', ', $confirming).'] before approving. Exactly one '
        .'type may: opening_balance, whose approval posts a cutover that cannot be un-posted, deleted '
        .'or moved. A missing confirmation there is a one-click irreversible post; an extra one '
        .'anywhere else is what teaches a checker to dismiss the dialog without reading it.');

    // And the sentence a checker actually reads has to contain the claim. A confirmation that does
    // not say the act cannot be undone is a speed bump, not a warning.
    expect(strtolower($entries['opening_balance']))->toContain('irreversible');
});

/**
 * A ROW THAT CANNOT SAY WHICH THING IT IS ABOUT VOIDS THE CONTROL IT IMPLEMENTS. Maker-checker's
 * premise is that a second person looks at THE THING; two pending fee-schedule publishes that both
 * render "Fee schedule · publish" make the signature ceremonial, and that state was reachable
 * (`open_key` forbids two open requests against the same schedule, not against different ones).
 *
 * The fix is not "label these two types" — it is that the LIST carries the rule, so the sixth type
 * cannot be added without answering the question. `subject` is required on `ApprovalFeed`, which
 * TypeScript enforces at the point of writing; this asserts it at the point of running, so an entry
 * that reaches `main` without one is red rather than silently rendering a type name.
 */
it('every declared feed says which thing its rows are about', function () {
    $source = aqfRead(AQF_FEEDS_MODULE);

    $body = aqfFeedsArrayBody($source);

    $entries = preg_match_all("/^\s+type: '/m", $body);
    $subjects = preg_match_all('/^\s+subject: /m', $body);

    expect($subjects)->toBe($entries,
        "APPROVAL_FEEDS has {$entries} entries and {$subjects} subject renderers. A feed with no "
        .'subject renders rows that name their TYPE and not the thing being approved, which is a '
        .'second signature given to something the checker cannot identify.');
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
 * A DECLARED CONFIRMATION THE PAGE NEVER CONSULTS IS WORSE THAN NO CONFIRMATION, because the
 * declaration is then read — by the next person, and by the test above — as evidence that the guard
 * is there. The arm above pins the feed entry; this pins the one line that makes the entry matter.
 *
 * Same limit and same precedent as the error-rule arm below: there is no JavaScript test runner in
 * this repository, so this reads the source. It asserts the Approve button routes through the
 * confirm-aware handler and NOT straight into the request — the exact edit (`onClick={() =>
 * void approve(row)}`) that silently restores one-click posting.
 */
it('the Approve button fires through the confirmation gate, not straight at the request', function () {
    $page = aqfRead(AQF_PAGE);

    $viaGate = str_contains($page, 'onClick={() =>') && str_contains($page, 'requestApprove(');
    $consultsFeed = str_contains($page, 'feedFor(row)?.confirm === undefined');
    $directCall = preg_match('/onClick=\{\(\) =>\s*void approve\(/', $page) === 1;

    expect($viaGate)->toBeTrue(AQF_PAGE.' no longer routes Approve through requestApprove(). An '
        .'irreversible approval declared in the feed list would fire on the click with no dialog.')
        ->and($consultsFeed)->toBeTrue(AQF_PAGE.' no longer branches on the row\'s FEED carrying a '
            .'`confirm`. Either the confirmation is dead, or the page has grown per-type knowledge.')
        ->and($directCall)->toBeFalse(AQF_PAGE.' calls approve() directly from an onClick. That is '
            .'the edit that bypasses the confirmation while leaving it declared and apparently guarded.');
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
