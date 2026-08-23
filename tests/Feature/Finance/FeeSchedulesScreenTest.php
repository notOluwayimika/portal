<?php

/*
 * THE FEE-SCHEDULES SCREEN (U1 commit 2) — the route, its props, and the two couplings that make the
 * page work for reasons no test previously stated.
 *
 * The page shell tests here are the OpeningBalanceOperatorScreenTest shape and exist for the defect
 * that file records: a route that returns 200 with an EMPTY select is indistinguishable, to a test
 * that asserts the page renders, from one that works. `/finance/opening-balances/import` shipped
 * exactly that — `ActiveSchool::getOrFail()` (a School MODEL) bound into `where('school_id', …)`,
 * matching nothing — and a browser drive is what found it. So the props are asserted, by count and
 * by value, not the 200.
 */

use App\Enums\Permission as PermissionEnum;
use App\Enums\TermStatusEnum;
use App\Models\AcademicSession;
use App\Models\ClassLevel;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

const FSS_PAGE = 'resources/js/pages/admin/finance/fee-schedules.tsx';
const FSS_SIDEBAR = 'resources/js/components/app-sidebar.tsx';

/** A School with one session, TWO terms and TWO class levels — enough that a count can be wrong. */
function fssSchool(): array
{
    $school = School::factory()->create();
    $session = AcademicSession::create([
        'school_id' => $school->id, 'name' => '2026/2027', 'slug' => 'sess-'.Str::random(8), 'is_current' => true,
    ]);

    $terms = collect(['First Term', 'Second Term'])->map(fn (string $name, int $i) => Term::create([
        'academic_session_id' => $session->id, 'school_id' => $school->id, 'name' => $name,
        'slug' => 'term-'.Str::random(8), 'order' => $i + 1, 'start_date' => now()->addMonths($i * 3),
        'end_date' => now()->addMonths($i * 3 + 2), 'status' => TermStatusEnum::UPCOMING->value,
    ]));

    // Created out of `order` on purpose: the route orders by `order`, and rows inserted in that order
    // would make the assertion pass whether the orderBy is there or not.
    $levels = collect([['JSS 2', 2], ['JSS 1', 1]])->map(fn (array $spec) => ClassLevel::create([
        'school_id' => $school->id, 'name' => $spec[0], 'order' => $spec[1],
    ]));

    return ['school' => $school, 'session' => $session, 'terms' => $terms, 'levels' => $levels];
}

/** A user in $school holding exactly $permissions, through a role minted for that set. */
function fssUser(School $school, array $permissions): User
{
    $roleName = 'fss_'.substr(md5(implode(',', $permissions)), 0, 10);
    $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
    $role->syncPermissions($permissions);

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->grantSchoolAccess($school, $roleName);
    $user->flushSchoolAccessCache();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function fssRead(string $relative): string
{
    return (string) file_get_contents(dirname(__DIR__, 3).'/'.$relative);
}

// ── The route and its props ──────────────────────────────────────────────────────────────────────

it('serves the screen to a fee-schedule author, with the terms and class levels it needs', function () {
    /*
     * THE PROPS ARE ASSERTED, NOT THE 200. Both selects are unfetchable by this seat — the only API
     * listing terms is gated on `academic_data.view`, which a finance author does not hold — so if
     * these two props come back empty the screen renders, looks fine, and cannot author anything.
     *
     * WATCHED RED by binding `ActiveSchool::getOrFail()` (the MODEL) instead of `->id` into the two
     * `where('school_id', …)` clauses, which is the exact mistake routes/web.php:207-213 carries a scar
     * for: both `has(...)` assertions fail with 0 items.
     */
    $ctx = fssSchool();

    test()->actingAs(fssUser($ctx['school'], ['finance.access', 'finance.fee-schedule.manage']))
        ->withSession(['school_id' => $ctx['school']->id])
        ->get('/finance/fee-schedules')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/finance/fee-schedules')
            ->has('terms', 2)
            // id DESC — the term a school is about to price is the one most recently created.
            ->where('terms.0.id', $ctx['terms'][1]->id)
            // Term::displayLabel(), the same string the opening-balance select and the approvals queue
            // print. Built literally here rather than by calling the method: an arm that calls the thing
            // it tests would pass against a displayLabel() that returned ''.
            ->where('terms.0.label', '2026/2027 — Second Term')
            ->has('class_levels', 2)
            // ORDERED BY `order`, not by id — the rows were inserted JSS 2 first.
            ->where('class_levels.0.name', 'JSS 1'));
});

it('refuses the screen to a seat that can only VIEW finance', function () {
    // finance.access alone must not reach a screen that SETS PRICES. Same reasoning as bank-accounts:
    // this is configuration, and the nav entry keys on the same ability so a visible item cannot 403.
    $ctx = fssSchool();

    test()->actingAs(fssUser($ctx['school'], ['finance.access']))
        ->withSession(['school_id' => $ctx['school']->id])
        ->get('/finance/fee-schedules')
        ->assertForbidden();
});

it('shows School B its OWN terms and class levels, and none of School A’s', function () {
    // The isolation the browser drive checks by eye, asserted. Both props are built from explicit
    // School-scoped queries; this is the arm that fails if either loses its bound.
    $a = fssSchool();
    $b = fssSchool();

    test()->actingAs(fssUser($b['school'], ['finance.access', 'finance.fee-schedule.manage']))
        ->withSession(['school_id' => $b['school']->id])
        ->get('/finance/fee-schedules')
        ->assertOk()
        ->assertInertia(function ($page) use ($a, $b) {
            $termIds = collect($page->toArray()['props']['terms'])->pluck('id')->all();
            $levelIds = collect($page->toArray()['props']['class_levels'])->pluck('id')->all();

            expect($termIds)->toBe($b['terms']->pluck('id')->sortDesc()->values()->all())
                ->and(array_intersect($termIds, $a['terms']->pluck('id')->all()))->toBe([])
                ->and(array_intersect($levelIds, $a['levels']->pluck('id')->all()))->toBe([]);
        });
});

// ── The two couplings ────────────────────────────────────────────────────────────────────────────

it('every role that may author a fee schedule may also fetch the bank accounts the picker needs', function () {
    /*
     * THE PICKER IS A FETCH, NOT A PROP, AND THAT IS ONLY SAFE BECAUSE OF THIS.
     * GET /api/v1/finance/bank-accounts is gated on `finance.bank-account.manage`; the screen is gated
     * on `finance.fee-schedule.manage`. Two different abilities. A role holding the second and not the
     * first opens the screen, fetches the accounts, gets a 403, and every fee line's "Paid into" select
     * is empty — a form that cannot be submitted, with nothing on it saying why.
     *
     * The coupling holds today by accident of the grant map. This turns it into a checked one. It reads
     * RbacSeeder::grantsMap() rather than a list here: a second copy of the map is the drift being
     * guarded against.
     *
     * WATCHED RED by removing FINANCE_BANK_ACCOUNT_MANAGE from the `admin` row of grantsMap(): this
     * fails naming that role.
     */
    $author = PermissionEnum::FINANCE_FEE_SCHEDULE_MANAGE->value;
    $accounts = PermissionEnum::FINANCE_BANK_ACCOUNT_MANAGE->value;

    $broken = [];
    foreach (RbacSeeder::grantsMap() as $role => $grants) {
        if (in_array($author, $grants, true) && ! in_array($accounts, $grants, true)) {
            $broken[] = $role;
        }
    }

    expect($broken)->toBe([],
        'Role(s) ['.implode(', ', $broken)."] hold {$author} but not {$accounts}. The fee-schedules "
        ."screen's account picker FETCHES /api/v1/finance/bank-accounts, which is gated on the latter, "
        .'so that seat would open the authoring screen onto an empty-and-broken destination select on '
        .'every fee line. Either grant the second ability, or make the accounts a prop on the route.');

    // NOT VACUOUS: the loop has to have inspected something. A grantsMap that stopped naming the
    // authoring ability at all would otherwise pass this by looking at nothing.
    $holders = collect(RbacSeeder::grantsMap())->filter(fn (array $g) => in_array($author, $g, true))->keys();

    expect($holders->count())->toBeGreaterThan(0,
        'No role in grantsMap() holds '.$author.' — the loop above iterated and matched nothing, so it '
        .'proved nothing. Either the ability was renamed or the map lost every authoring seat.');
});

it('gates the submit/retire controls on the CHANGE ability, which this screen’s ability does not imply', function () {
    /*
     * THE OTHER COUPLING, AND THIS ONE IS ALREADY BROKEN — which is why it is a gate on the button and
     * not an assertion about the map. Proposing a publish or a retire is
     * `finance.fee-schedule.change.submit`; the page is `finance.fee-schedule.manage`. The seeded
     * `admin` role holds the second and NOT the first, so an admin authoring a draft would be shown a
     * "Submit for approval" button that 403s at POST /api/v1/finance/fee-schedule-changes.
     *
     * The map is not wrong — `admin` is deliberately not a finance maker seat — so the fix is the page
     * asking, which is what this asserts. Read as text for the reason FinanceNavCoverageTest gives:
     * there is no JavaScript test runner in this repository, so it is this or nothing.
     */
    $map = RbacSeeder::grantsMap();
    $author = PermissionEnum::FINANCE_FEE_SCHEDULE_MANAGE->value;
    $propose = PermissionEnum::FINANCE_FEE_SCHEDULE_CHANGE_SUBMIT->value;

    $authorOnly = collect($map)
        ->filter(fn (array $g) => in_array($author, $g, true) && ! in_array($propose, $g, true))
        ->keys();

    // If this ever becomes empty the gate below is belt-and-braces rather than load-bearing — which is
    // fine, but the reader should be told which of the two it is.
    expect($authorOnly->count())->toBeGreaterThan(0,
        'No role holds '.$author.' without '.$propose.' any more. The page gate below is now redundant '
        .'rather than load-bearing; leave it, but this comment is stale.');

    $page = fssRead(FSS_PAGE);

    // `str_contains(...)` asserted as a BOOLEAN rather than `expect($page)->toContain($needle, $msg)`:
    // Pest's toContain is VARIADIC — a second argument is a second NEEDLE, not a message — so the
    // sentence below would have been asserted as a substring of the page and failed for the wrong
    // reason, printing 47kB of TSX instead of what to do about it. (Found by watching it do exactly
    // that.) RouteAccessParityTest carries a note on the sibling trap in `->not->toEqual`.
    expect(str_contains($page, "can('finance.fee-schedule.change.submit')"))->toBeTrue(
        FSS_PAGE.' renders Submit-for-approval and Retire without asking for '
        .$propose.'. Role(s) ['.$authorOnly->implode(', ').'] reach this screen and hold no such '
        .'ability, so those buttons 403 on click for them.');
});

// ── The slot collision, as the PAGE sees it ──────────────────────────────────────────────────────

it('answers a second draft for an occupied slot with a 422 the page can render — a message and NO bag', function () {
    /*
     * `finance_fee_schedules_pending_unique` permits at most one draft-or-pending schedule per
     * (school, term, class level). This is the arm that says what the operator actually gets, because
     * the page's error handling forks on it: a validation 422 carries `errors` and is rendered beside
     * the offending field, and THIS 422 carries `message` and no `errors` key at all — it comes from
     * CreateFeeSchedule translating the 1062 into a BusinessRuleException, which
     * FeeScheduleController::store returns as `['message' => …]`.
     *
     * A page that only read `response.data.errors` would show a failed save and say nothing. Provoked
     * rather than assumed: the shape below is what the request returns, not what the source reads like.
     */
    $ctx = fssSchool();
    $author = fssUser($ctx['school'], [
        'finance.access', 'finance.fee-schedule.manage', 'finance.bank-account.manage',
    ]);

    $as = fn () => test()->actingAs($author)->withSession(['school_id' => $ctx['school']->id]);

    $account = $as()->postJson('/api/v1/finance/bank-accounts', [
        'label' => 'Fees', 'bank_name' => 'Zenith', 'account_number' => '1234567890',
    ])->assertCreated()->json('id');

    $body = fn (string $label) => [
        'term_id' => $ctx['terms'][0]->id,
        'class_level_id' => $ctx['levels'][0]->id,
        'label' => $label,
        'items' => [['description' => 'Tuition', 'amount_minor' => 250000, 'currency' => 'NGN', 'bank_account_id' => $account]],
    ];

    $as()->postJson('/api/v1/finance/fee-schedules', $body('v1'))->assertCreated();

    $collision = $as()->postJson('/api/v1/finance/fee-schedules', $body('v2'))->assertStatus(422);

    expect(array_keys((array) $collision->json()))->toBe(['message'],
        'The slot collision no longer answers with a bare `message`. The fee-schedules page renders '
        .'that key as a form-level error precisely because there is no `errors` bag to hang it on; if '
        .'this payload changed shape, the operator now gets a silent failed save.');

    // AND THE SENTENCE NAMES AN ACTION. "Duplicate entry for key …" would be a true statement about
    // the database and no use at all to the person who just clicked Save.
    expect((string) $collision->json('message'))
        ->toContain('already exists for this term and class level')
        ->toContain('Edit that draft');
});

// ── The edit round-trip: an edit must not re-denominate money ────────────────────────────────────

it('the Edit body the page sends preserves each item’s CURRENCY, not just its amount', function () {
    /*
     * THE DEFECT THIS PINS. An edit replaces the item set WHOLESALE — EditFeeScheduleDraft deletes
     * and re-inserts — so a field the form does not send back is not left alone, it is REWRITTEN from
     * whatever default the write path supplies. CreateFeeSchedule reads
     * `$item['currency'] ?? Money::DEFAULT_CURRENCY`, so a currency the page drops does not stay as
     * it was: the amounts survive and the denominations do not.
     *
     * THE BODY IS BUILT THE WAY THE PAGE BUILDS IT — from the fields the catalog response carries,
     * which is exactly what `openFrom()` reads into the form and `submit()` posts back. A body
     * hand-written with the currency already in it would assert that the ENDPOINT preserves what it
     * is told, which was never in doubt; what was in doubt is whether the page tells it.
     *
     * WATCHED RED, and this recipe is the CURRENT one — drop `currency` from the item map below,
     * which IS the pre-fix page, and the edit is refused:
     *
     *     Expected response status code [200] but received 422.
     *       "items.0.currency": ["The items.0.currency field is required."]
     *       "items.1.currency": ["The items.1.currency field is required."]
     *
     * THE RECIPE THIS REPLACES DID NOT REPRODUCE, and the reason is worth keeping because it is how
     * a watched-red goes stale without anyone noticing. It read: "the assertion fails with the second
     * item's currency read back as 'NGN' instead of 'USD'", and it rested on a sentence above it
     * claiming `items.*.currency` was `sometimes`. That was true when the arm was written and false
     * by the time anyone would have followed it — U1 commit 2 made the rule `required`, so an omitted
     * currency stopped being a silent default and became a 422. Measured at the base commit of the
     * branch that corrected this: the promised currency mismatch never appears.
     *
     * The consequence for the FIXTURE is why both items are NGN. The old arm used a USD second line
     * as the detector — the one value a silent default-to-NGN would visibly overwrite. `required`
     * took that job over, and the pin on `items.*.currency`
     * (Rule::in([Money::DEFAULT_CURRENCY])) means USD can no longer be posted at all. The arm's
     * subject is untouched by either change: it asks whether the page sends the field, and dropping
     * the field still reds.
     */
    $ctx = fssSchool();
    $author = fssUser($ctx['school'], [
        'finance.access', 'finance.fee-schedule.manage', 'finance.bank-account.manage',
    ]);

    $as = fn () => test()->actingAs($author)->withSession(['school_id' => $ctx['school']->id]);

    $account = $as()->postJson('/api/v1/finance/bank-accounts', [
        'label' => 'Fees', 'bank_name' => 'Zenith', 'account_number' => '1234567890',
    ])->assertCreated()->json('id');

    $created = $as()->postJson('/api/v1/finance/fee-schedules', [
        'term_id' => $ctx['terms'][0]->id,
        'class_level_id' => $ctx['levels'][0]->id,
        'label' => 'JSS1 T1',
        'items' => [
            ['description' => 'Tuition', 'amount_minor' => 250000, 'bank_account_id' => $account, 'currency' => 'NGN'],
            ['description' => 'Exchange trip', 'amount_minor' => 90000, 'bank_account_id' => $account, 'currency' => 'NGN'],
        ],
    ])->assertCreated();

    $uuid = (string) $created->json('id');

    // openFrom() + submit(), in PHP: every item field the form holds, posted back. The description of
    // the first line is the one thing an operator changed.
    $items = collect((array) $created->json('items'))
        ->map(fn (array $item, int $index) => [
            'description' => $index === 0 ? 'Tuition (revised)' : $item['description'],
            'amount_minor' => $item['amount']['amount_minor'],
            'bank_account_id' => $item['bank_account_id'],
            'currency' => $item['amount']['currency'],
            'is_mandatory' => $item['is_mandatory'],
            'is_discountable' => $item['is_discountable'],
            'sort_order' => $index,
        ])
        ->all();

    $edited = $as()->putJson("/api/v1/finance/fee-schedules/{$uuid}/draft", [
        'label' => 'JSS1 T1',
        'items' => $items,
    ])->assertOk();

    $after = collect((array) $edited->json('items'));

    expect($after->pluck('amount.currency')->all())->toBe(['NGN', 'NGN'],
        'An edit re-denominated the schedule. The page posts the item set wholesale, so a currency it '
        .'does not send back is rewritten to Money::DEFAULT_CURRENCY — the amounts survive and the '
        .'denominations do not, which is a price change nobody made.');

    // The amounts are untouched, so this is a re-denomination and not a re-pricing — stated because
    // the two would need different remedies.
    expect($after->pluck('amount.amount_minor')->all())->toBe([250000, 90000])
        ->and($after->pluck('description')->all())->toBe(['Tuition (revised)', 'Exchange trip']);

    // And the schedule totals, because both lines are now NGN. This line used to assert `total` was
    // NULL — the mixed-currency condition reported instead of a number — and that subject left with
    // the USD fixture. It is replaced rather than deleted: the null-total behaviour still has a
    // dedicated arm in FeeScheduleTest (which builds the mixed schedule in-process, the only surface
    // that can still produce one), so nothing is lost by asserting the true value here, and an
    // assertion that the sum survived a wholesale delete-and-re-insert is worth having on its own.
    expect($edited->json('total'))->toBe(['amount_minor' => 340000, 'currency' => 'NGN']);
});

it('the page posts the currency back — the round-trip, asserted in the file that performs it', function () {
    /*
     * The arm above proves the BODY works. This proves the page sends that body. Both are needed:
     * deleting `currency: row.currency` from the page leaves the arm above green, because that arm
     * builds its own body.
     *
     * Read as text for the reason FinanceNavCoverageTest gives — there is no JavaScript test runner
     * in this repository, so it is this or nothing.
     */
    $page = fssRead(FSS_PAGE);

    expect(str_contains($page, 'currency: item.amount.currency'))->toBeTrue(
        FSS_PAGE.' no longer reads each item\'s currency into the edit form. Every line of an edited '
        .'schedule will be rewritten to NGN.');

    expect(str_contains($page, 'currency: row.currency'))->toBeTrue(
        FSS_PAGE.' no longer posts each item\'s currency back. `items.*.currency` is `sometimes`, so '
        .'the omission is not a 422 — it is a silent re-denomination.');
});

// ── The nav entry ────────────────────────────────────────────────────────────────────────────────

it('the sidebar item keys on the SAME ability as the route', function () {
    // FinanceNavCoverageTest already fails if the href is missing entirely. This asserts the other
    // half — that the item and the route ask the same question, so a visible entry cannot 403.
    $sidebar = fssRead(FSS_SIDEBAR);

    expect($sidebar)->toContain("'/finance/fee-schedules'")
        ->and($sidebar)->toContain("can('finance.fee-schedule.manage')");
});
