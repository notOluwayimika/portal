<?php

/*
 * THE BSS DISCOUNT-AWARD OPERATOR SCREEN — the page, its props, its seat, and its vocabulary.
 *
 * DiscountAwardImportTest covers the four endpoints and the importer. This file covers the SCREEN
 * commit: that the page is served to the seat its API routes admit and refused to every other, that
 * the props it renders from carry exactly the keys the client reads, that the item linking it asks the
 * same question its route asks — and that the template's base column says what the rest of this
 * project says about the same axis.
 */

use App\Finance\Enums\DiscountBase;
use App\Finance\Enums\DiscountPolicyStatus;
use App\Finance\Exports\DiscountAwardImportTemplateExport;
use App\Finance\Models\DiscountPolicy;
use App\Finance\Services\DiscountAwardImporter;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\ActiveSchool;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

const DAIS_ACCESS = 'finance.access';

const DAIS_MANAGE = 'finance.discount-award.manage';

const DAIS_POLICY_SUBMIT = 'finance.discount-policy.change.submit';

const DAIS_PAGE = 'resources/js/pages/admin/finance/discount-award-imports.tsx';

const DAIS_SIDEBAR = 'resources/js/components/app-sidebar.tsx';

const DAIS_URL = '/finance/discount-award-imports';

/**
 * `dais` PREFIX, and the world helpers are written here rather than imported from
 * DiscountAwardImportTest — Pest defines a file's functions when it loads that file, so calling
 * another file's helper works only if that file happened to load first. That is a load-order
 * dependency and it fails as a redeclaration the day both files load in one process.
 *
 * A web-session user holding EXACTLY $permissions. Permission-keyed and not role-keyed, so a grants
 * commit cannot move the actor and the thing under test together.
 *
 * @param  list<string>  $permissions
 */
function daisUser(School $school, array $permissions): User
{
    $roleName = 'dais_'.substr(md5(implode(',', $permissions)), 0, 10);
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

function daisSchool(): School
{
    return School::factory()->create();
}

/** A percentage discount policy in $school. Written through the model for the reason daPolicy gives. */
function daisPolicy(School $school, int $percent, DiscountBase $base, array $overrides = []): DiscountPolicy
{
    return ActiveSchool::runFor($school->id, fn () => DiscountPolicy::create(array_merge([
        'school_id' => $school->id,
        'name' => 'BSS '.$percent.'% '.$base->value.' '.Str::random(4),
        'basis' => 'percent',
        'percent' => $percent,
        'base' => $base,
        'requires_approval' => false,
        'status' => DiscountPolicyStatus::Active,
    ], $overrides)));
}

function daisGet(User $actor, School $school)
{
    return test()->actingAs($actor)->withSession(['school_id' => $school->id])->get(DAIS_URL);
}

function daisRead(string $relative): string
{
    return (string) file_get_contents(dirname(__DIR__, 3).'/'.$relative);
}

/**
 * The same source with ALL whitespace removed, for needles that would otherwise be broken by a
 * formatter rather than by a change of meaning.
 *
 * THIS IS A RECORDED TRAP AND NOT A PRECAUTION. Prettier wraps a call across several lines once its
 * arguments make it long enough, so `can('finance.…')` written on one line here matches a page where
 * the same call is correct but wrapped — and DiscountPoliciesScreenTest records an arm of this shape
 * going red on `prettier --write` alone, with nothing about the code having changed. Squashing removes
 * the axis the formatter owns and leaves the one the author owns.
 */
function daisSquashed(string $relative): string
{
    return (string) preg_replace('/\s+/', '', daisRead($relative));
}

/*
|--------------------------------------------------------------------------
| (iii) The seat
|--------------------------------------------------------------------------
*/

it('serves the screen to the seat its own endpoints admit, and to nobody else', function () {
    $school = daisSchool();

    daisGet(daisUser($school, [DAIS_ACCESS, DAIS_MANAGE]), $school)->assertOk();

    // `finance.access` ALONE IS NOT ENOUGH, and this is the arm that says so. The group middleware on
    // this route block is finance.access; if the page-level permission were ever dropped, every seat
    // that can read a statement could open a screen that re-prices a cohort.
    daisGet(daisUser($school, [DAIS_ACCESS]), $school)->assertForbidden();

    // NOR IS THE ADJACENT DISCOUNT ABILITY. Authoring the figures and putting a named child on one are
    // different authorities — that is the whole reason finance.discount-award.manage was coined rather
    // than borrowed — so the seat that submits policy changes does not thereby reach this.
    daisGet(daisUser($school, [DAIS_ACCESS, DAIS_POLICY_SUBMIT]), $school)->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| (i) The prop contract
|--------------------------------------------------------------------------
*/

it('renders the format and the policy pairs, with exactly the keys the client reads', function () {
    /*
     * THE PROP CONTRACT, PINNED FROM THE SERVER'S SIDE, in the shape of DiscountPoliciesScreenTest's
     * catalog-contract arm and for the same reason. `Inertia.render` props are read by TypeScript
     * interfaces that are ERASED at runtime: rename `applies_to` and the client reads `undefined`,
     * renders "50% of undefined" beside a percentage, and there is no error, no console entry and no
     * non-2xx anywhere — a screen that quietly teaches a phrase the importer would refuse.
     *
     * EXACT KEYS, not `toHaveKeys`. Every key inside each scope is interacted with and `etc()` is NOT
     * called there, so AssertableInertia fails on a key the client does not know about as well as on a
     * missing one. `etc()` at the top level only, because Inertia's shared props (auth, ziggy, flash)
     * are not this page's contract.
     *
     * THE FIXTURE HAS TWO PAIRS ON DIFFERENT BASES AT DIFFERENT PERCENTAGES. One pair would collapse
     * the axis: a route that read `base` from the wrong policy, or ignored it entirely, would land on
     * the single row and pass. Two, differing on both fields, means only the real grouping passes.
     */
    $school = daisSchool();

    // CREATED IN THE OPPOSITE ORDER TO THE ONE ASSERTED, deliberately. `groupBy` preserves first-seen
    // order and the query orders by nothing, so seeding these the way they come out would let the
    // ordering assertion below pass with the `sortBy` deleted — the arm would restate the fixture
    // rather than test the route. Seeded reversed, only the sort can produce the asserted order.
    daisPolicy($school, 100, DiscountBase::Total);
    daisPolicy($school, 50, DiscountBase::Discountable);

    // NOT A PERCENTAGE POLICY, so it must not appear. A fixed-amount policy has no percentage to take
    // of anything and no row of this file could ever name it; if it leaked into `pairs` the screen
    // would offer a figure the importer cannot match.
    daisPolicy($school, 25, DiscountBase::Discountable, [
        'basis' => 'amount',
        'percent' => null,
        'value_minor' => 25_000_00,
        'value_currency' => 'NGN',
    ]);

    // NOT ACTIVE, so it must not appear either — the importer matches on ACTIVE alone, and a retired
    // policy offered here is a row refused after the upload for something the screen said was fine.
    daisPolicy($school, 75, DiscountBase::Total, ['status' => DiscountPolicyStatus::Retired]);

    daisGet(daisUser($school, [DAIS_ACCESS, DAIS_MANAGE]), $school)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/finance/discount-award-imports')

            // Ordered: base ascending then percent ascending, so `discountable` leads. Asserted rather
            // than assumed — the screen prints these in the order they arrive.
            ->has('pairs', 2, fn (AssertableInertia $pair) => $pair
                ->where('percent', 50)
                ->where('base', 'discountable')
                // THE PHRASE, FROM THE IMPORTER'S OWN LABELLER. Derived rather than written out, so
                // this arm asserts "the screen speaks the file's vocabulary" and not "the phrase is
                // this string" — the string itself is pinned once, by the template arm below.
                ->where('applies_to', DiscountAwardImporter::appliesToLabel(DiscountBase::Discountable))
                ->where('policy_count', 1))
            ->where('pairs.1.percent', 100)
            ->where('pairs.1.base', 'total')
            ->where('pairs.1.applies_to', DiscountAwardImporter::appliesToLabel(DiscountBase::Total))
            ->where('pairs.1.policy_count', 1)

            ->has('columns', count(DiscountAwardImporter::COLUMNS), fn (AssertableInertia $column) => $column
                ->where('column', array_key_first(DiscountAwardImporter::COLUMNS))
                ->where('required', true)
                ->where('format', DiscountAwardImporter::COLUMNS[array_key_first(DiscountAwardImporter::COLUMNS)]['format'])
                ->where('example', DiscountAwardImporter::COLUMNS[array_key_first(DiscountAwardImporter::COLUMNS)]['example'])
                ->where('notes', DiscountAwardImporter::COLUMNS[array_key_first(DiscountAwardImporter::COLUMNS)]['notes']))

            ->has('notes', count(DiscountAwardImporter::NOTES), fn (AssertableInertia $note) => $note
                ->where('rule', DiscountAwardImporter::NOTES[0][0])
                ->where('meaning', DiscountAwardImporter::NOTES[0][1]))

            ->etc());
});

it('reports a pair carrying two active policies, because that row will be refused too', function () {
    // AMBIGUITY IS A REFUSAL AND IT IS KNOWABLE NOW. Two active policies on one pair make the row
    // unanswerable — the importer will not choose on the operator's behalf — so the count travels with
    // the pair rather than the bursar learning it from a report.
    $school = daisSchool();

    daisPolicy($school, 50, DiscountBase::Discountable);
    daisPolicy($school, 50, DiscountBase::Discountable);

    daisGet(daisUser($school, [DAIS_ACCESS, DAIS_MANAGE]), $school)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('pairs', 1)
            ->where('pairs.0.policy_count', 2)
            ->etc());
});

it('sends an EMPTY pair list when the school has approved none, which is what withholds the upload', function () {
    /*
     * THE EMPTY STATE. Every row resolves to an active percentage policy, so a school holding none
     * refuses every row — and a bursar who uploads ninety-one rows to be told ninety-one times that
     * nothing matched has been failed by the screen, not by their file.
     *
     * THE FIXTURE HOLDS POLICIES THAT DO NOT COUNT rather than no policies at all. An empty table would
     * pass against a route that ignored `status` and `basis` entirely; this one only passes if both
     * filters are applied, and it is the same distinction the contract arm above makes from the other
     * side.
     */
    $school = daisSchool();

    daisPolicy($school, 50, DiscountBase::Discountable, ['status' => DiscountPolicyStatus::Retired]);
    daisPolicy($school, 30, DiscountBase::Total, [
        'basis' => 'amount',
        'percent' => null,
        'value_minor' => 30_000_00,
        'value_currency' => 'NGN',
    ]);

    daisGet(daisUser($school, [DAIS_ACCESS, DAIS_MANAGE]), $school)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('pairs', 0)->etc());

    // And the screen's own half of it: the upload is WITHHELD on an empty list rather than offered and
    // then explained. Read as text — `noPolicies` is the client's name for the condition and the upload
    // card is rendered under `!noPolicies`.
    $page = daisSquashed(DAIS_PAGE);

    expect(str_contains($page, 'constnoPolicies=pairs.length===0;'))->toBeTrue(
        DAIS_PAGE.' no longer derives the empty state from the pair list. An operator who can press '
        .'Upload will press it, and the report they get back says nothing they could not have been '
        .'told first.');

    expect(str_contains($page, '{!noPolicies&&('))->toBeTrue(
        DAIS_PAGE.' now renders the upload control unconditionally. With no approved percentage policy '
        .'every row of the file will be refused, so the upload must be withheld and not merely '
        .'annotated.');
});

it('does not offer the discount-policies link to a seat that cannot open it', function () {
    // An offered link that 403s is worse than prose. The seeded accounts_officer holds both abilities,
    // but a runtime matrix edit can separate them, so the empty state's remedy link is gated in-page on
    // the ability its DESTINATION requires rather than on the one that opened this screen.
    $page = daisSquashed(DAIS_PAGE);

    // The needle stops at the ability and does NOT include the closing parenthesis: prettier renders
    // this call on one line or across three depending on the length of the surrounding JSX, so a
    // trailing `,)` and a trailing `)` are both correct and neither is this arm's business.
    expect(str_contains($page, "can('finance.discount-policy.change.submit'"))->toBeTrue(
        DAIS_PAGE.' no longer gates the Discount policies link on '
        .'finance.discount-policy.change.submit. That route requires it and this one does not, so an '
        .'ungated link offers a seat holding only finance.discount-award.manage a 403.');

    // AND THE LINK IS REALLY THERE — otherwise the arm above would stay green over a page that had
    // dropped the remedy entirely, which is the empty state saying "you cannot do this" and not saying
    // where it is done.
    expect(str_contains($page, 'href="/finance/discount-policies"'))->toBeTrue(
        DAIS_PAGE.' no longer links /finance/discount-policies from the empty state. Telling a bursar '
        .'the policies must exist first, without saying where they are created, is half a sentence.');
});

/*
|--------------------------------------------------------------------------
| (ii) The nav entry
|--------------------------------------------------------------------------
*/

it('the sidebar item keys on the SAME ability as the route', function () {
    // FinanceNavCoverageTest already fails if the href is missing entirely — every registered web GET
    // route under /finance must be in the sidebar or named as an exemption. This asserts the other
    // half: that the item and the route ask the same question, so a visible entry cannot 403.
    //
    // `str_contains(...)` asserted as a BOOLEAN rather than `expect($sidebar)->toContain($needle)`.
    // `toContain` is VARIADIC, so a message passed to it becomes a second needle — the vacuous-arm
    // defect PestNegatedExpectationMessagesTest names and that this very feature shipped once already.
    $sidebar = daisRead(DAIS_SIDEBAR);

    expect(str_contains($sidebar, "'".DAIS_URL."'"))->toBeTrue(
        DAIS_SIDEBAR.' has no item pointing at '.DAIS_URL.'.');

    expect(str_contains($sidebar, "can('finance.discount-award.manage')"))->toBeTrue(
        DAIS_SIDEBAR.' shows the Discount awards item on a different question from the one its route '
        .'asks. The route is gated on finance.discount-award.manage; an item keyed on anything else — '
        .'finance.access, or either discount-policy ability — renders for a seat the route then '
        .'refuses.');
});

/*
|--------------------------------------------------------------------------
| (iv) The base column's vocabulary
|--------------------------------------------------------------------------
*/

it('issues a template whose base column carries the two phrases this project uses for that axis', function () {
    /*
     * THE DRIFT GUARD FOR THE BASE AXIS — AND IT IS DELIBERATELY TWO PINS RATHER THAN ONE LINK.
     *
     * The phrases below are `baseLabel`'s (resources/js/lib/finance/approval-feeds.ts), in the
     * template's case: "of discountable charges" and "of the whole bill", which the catalog screen and
     * the approvals queue already render through that one function. This constant is the same axis on a
     * third and fourth surface, so it says the same words.
     *
     * THE LINKED FORM WAS CONSIDERED AND REJECTED ON TIMING, not on principle. A vitest arm could
     * import `baseLabel`, call it, scrape `APPLIES_TO_CANONICAL` out of this PHP file, and assert the
     * two ARE derived from one another — genuine linkage, one source. A regex over another language's
     * source fails in the direction that costs most nine days from cutover: a false red in
     * `bin/quality` on a formatting change nobody made on purpose. So instead:
     *
     *   - THIS arm pins the two literals on the PHP side;
     *   - resources/js/pages/admin/finance/discount-policies.test.ts pins the SAME two on the TS side.
     *
     * Both name the same strings, so a reword reds exactly one of them and its message points at the
     * other. That is weaker than a link — nothing forces the two files to be edited together, and a
     * reword that updated BOTH pins while updating neither renderer would pass — and it is written down
     * as the weaker form so that the next person reading it knows it was chosen and not overlooked.
     *
     * THE GENERATED BYTES, not the export's arrays. A template is only a template if the thing that
     * comes out of it is the thing the reader accepts.
     */
    $csv = Excel::raw(new DiscountAwardImportTemplateExport, ExcelFormat::CSV);

    $lines = array_values(array_filter(explode("\n", str_replace("\r", '', $csv)), fn ($l) => $l !== ''));
    $samples = array_map(fn ($l) => str_getcsv($l), array_slice($lines, 1));

    // PIN ONE — the constant. Ordered pairs with their DiscountBase case, never a set: a set would let
    // the two phrases swap bases and stay green, which is the mutation that puts a child on 100% of
    // everything when the sheet said tuition.
    expect(DiscountAwardImporter::APPLIES_TO_CANONICAL)->toBe([
        'DISCOUNTABLE CHARGES' => DiscountBase::Discountable,
        'THE WHOLE BILL' => DiscountBase::Total,
    ]);

    // PIN TWO — what the download actually carries in the third column. The constant could be right
    // while the export emitted an alias, which is precisely the claim ("TUITION ONLY") this commit
    // removed from the format.
    expect(array_column($samples, 2))->toBe(['DISCOUNTABLE CHARGES', 'THE WHOLE BILL']);

    // AND THE READER ACCEPTS BOTH, mapped the way the constant says. A template offering a phrase its
    // own reader refuses is the defect the opening-balance template shipped in another form.
    expect(DiscountAwardImporter::parseAppliesTo('DISCOUNTABLE CHARGES'))->toBe(DiscountBase::Discountable)
        ->and(DiscountAwardImporter::parseAppliesTo('THE WHOLE BILL'))->toBe(DiscountBase::Total);
});

it('still accepts Brookstone\'s own wording, which is no longer what it emits', function () {
    // ACCEPTING MORE THAN WE EMIT COSTS NOTHING, and a bursar copying from the BSS list will write
    // TUITION ONLY. What changed is the EMITTED phrase: "tuition" is a fact about one school's current
    // fee schedule, and `discountable` means whatever that schedule marks — so the heading states the
    // rule and the column note states what it currently amounts to.
    expect(DiscountAwardImporter::parseAppliesTo('TUITION ONLY'))->toBe(DiscountBase::Discountable)
        ->and(DiscountAwardImporter::parseAppliesTo('  tuition   only '))->toBe(DiscountBase::Discountable);

    expect(array_keys(DiscountAwardImporter::APPLIES_TO_CANONICAL))->not->toContain('TUITION ONLY');

    // The claim that can go stale is where a claim that can go stale belongs.
    expect(DiscountAwardImporter::COLUMNS['discount_applies_to']['notes'])
        ->toContain('which in your fee schedule today means tuition');

    expect(str_contains(DiscountAwardImporter::COLUMNS['discount_applies_to']['format'], 'TUITION ONLY'))
        ->toBeFalse('The base column\'s FORMAT still offers TUITION ONLY. That phrase is a claim about '
        .'what this school marks discountable today; the day transport is marked discountable the '
        .'template is simply wrong on the one file that decides what families pay.');
});
