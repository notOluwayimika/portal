<?php

/*
 * THE DISCOUNT-POLICIES SCREEN (U2) — the route, the catalog it reads, and the coupling that lets it
 * carry no props at all.
 *
 * The shape is FeeSchedulesScreenTest's, one screen over, and the difference between them is the
 * subject of the first arm: fee schedules has TWO abilities (open the screen / send a proposal) and a
 * seat holding one without the other gets buttons that 403. Discount policies has no `manage` ability
 * at all — every control on the page posts the one endpoint the page gate names — so that split cannot
 * occur here, and this file says so with the permission catalog rather than with a comment.
 */

use App\Enums\Permission as PermissionEnum;
use App\Finance\Models\DiscountPolicy;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\ApprovalAbility;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

const DPS_PAGE = 'resources/js/pages/admin/finance/discount-policies.tsx';
const DPS_SIDEBAR = 'resources/js/components/app-sidebar.tsx';

/** A user in $school holding exactly $permissions, through a role minted for that set. */
function dpsUser(School $school, array $permissions): User
{
    $roleName = 'dps_'.substr(md5(implode(',', $permissions)), 0, 10);
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

/**
 * A policy in whatever state the arm needs. Written directly rather than through the approval path
 * because these arms are about what the SCREEN reads, not about how a policy comes to exist — that is
 * DiscountPolicyTest's subject, proven there against the real Actions.
 */
function dpsPolicy(School $school, string $name, string $status): DiscountPolicy
{
    return ActiveSchool::runFor($school->id, fn () => DiscountPolicy::create([
        'school_id' => $school->id, 'name' => $name, 'basis' => 'percent', 'percent' => 10,
        'requires_approval' => false, 'status' => $status,
    ]));
}

function dpsRead(string $relative): string
{
    return (string) file_get_contents(dirname(__DIR__, 3).'/'.$relative);
}

// ── The route ────────────────────────────────────────────────────────────────────────────────────

it('serves the screen to the seat that may propose a discount-policy change', function () {
    $school = School::factory()->create();

    test()->actingAs(dpsUser($school, ['finance.access', 'finance.discount-policy.change.submit']))
        ->withSession(['school_id' => $school->id])
        ->get('/finance/discount-policies')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/finance/discount-policies'));
});

it('refuses the screen to a seat that can only VIEW finance', function () {
    // finance.access alone must not reach a screen that defines what may come off a bill. Same
    // reasoning as bank accounts and fee schedules: this is configuration, and the nav entry keys on
    // the same ability so a visible item cannot 403 on click.
    $school = School::factory()->create();

    test()->actingAs(dpsUser($school, ['finance.access']))
        ->withSession(['school_id' => $school->id])
        ->get('/finance/discount-policies')
        ->assertForbidden();
});

// ── The catalog the page reads ───────────────────────────────────────────────────────────────────

it('lists superseded and retired policies, not only the active ones', function () {
    /*
     * THE BEHAVIOUR CHANGE THIS COMMIT MAKES, PINNED. DiscountPolicyController::index hard-filtered to
     * `active` and had no way to ask for anything else, so a caller got a partial catalog it could not
     * tell was partial. A policy that priced an invoice must stay nameable forever — the table has a
     * no-DELETE trigger and a name unique scoped to the ACTIVE state for exactly that reason — and the
     * screen that authors the catalog is where it has to stay readable.
     *
     * WATCHED RED by restoring the `where('status', 'active')` filter: the first expectation fails
     * with only the active policy's name present.
     */
    $school = School::factory()->create();
    dpsPolicy($school, 'Sibling discount', 'active');
    dpsPolicy($school, 'Old sibling discount', 'superseded');
    dpsPolicy($school, 'Covid relief', 'retired');

    $as = fn () => test()->actingAs(dpsUser($school, ['finance.access', 'finance.discount-policy.change.submit']))
        ->withSession(['school_id' => $school->id]);

    $all = $as()->getJson('/api/v1/finance/discount-policies')->assertOk();

    expect(collect($all->json())->pluck('name')->sort()->values()->all())
        ->toBe(['Covid relief', 'Old sibling discount', 'Sibling discount']);

    // ABSENT MEANS UNFILTERED; a caller that wants the choosable set says so. This is the shape
    // FeeScheduleController::index already uses, and the one U8 will ask for at invoice time.
    $active = $as()->getJson('/api/v1/finance/discount-policies?status=active')->assertOk();

    expect(collect($active->json())->pluck('name')->all())->toBe(['Sibling discount']);

    // And an unknown state is a 422, not a silent empty list.
    $as()->getJson('/api/v1/finance/discount-policies?status=lapsed')->assertStatus(422);
});

it('carries `status` and `requires_approval`, in the shapes the invoice modal reads', function () {
    /*
     * EXTENDED 2026-08-16 by feat/ui-discount-policies-redesign. The second half of this arm pins the
     * TERMS keys as well — see the block below the original expectations. It is the same arm rather
     * than a second one because it is the same contract: one response, one key set, one place to
     * update when the Resource changes.
     */
    /*
     * THE CATALOG CONTRACT, PINNED FROM THE SERVER'S SIDE ONLY (U8 commit 6).
     *
     * new-invoice-modal.tsx's selectablePolicies() decides which policies a bursar may cite on a
     * reduction with `policy.status === 'active' && policy.requires_approval !== true`. Both keys
     * come from DiscountPolicyResource, and until this arm NOTHING asserted either of them in a
     * response body: the three existing expectations in this file pluck `name` (twice) and `id`
     * (once), and a repo-wide search for an assertion on those two keys in a discount-policies
     * payload returns nothing.
     *
     * WHAT THAT COST. `axios.get<SelectablePolicy[]>` is a COMPILE-TIME assertion — TypeScript
     * erases it, so at runtime the client trusts whatever arrives. Rename or drop `status` in the
     * Resource and every row's `policy.status` is `undefined`, `undefined === 'active'` is false for
     * all of them, and the modal tells every bursar in words "This school has no active discount
     * policy that can back a reduction." No error, no console entry, no non-2xx status — a false
     * sentence, silently, with nobody having touched JavaScript. This arm is what fails instead.
     *
     * SHAPES, NOT JUST PRESENCE. `requires_approval` must be a BOOLEAN: `!== true` is false for the
     * integer 1, so a Resource that stopped casting would silently make every approval-requiring
     * policy selectable — the opposite failure, and the one the reduction guard's third arm then
     * refuses at the database with a message the operator did not expect. `status` must be one of
     * the three enum values the client's union declares.
     *
     * THIS PINS THE SERVER'S HALF AND ONLY THE SERVER'S HALF. If someone inverts the client's own
     * predicate — `!== true` to `=== true` — every catalog goes empty, the same false sentence is
     * shown, and this arm still passes, as do all fifteen gate steps (measured). There is no
     * JavaScript test runner in this repository, so the client's half stays unguarded until there
     * is one: docs/handoff/tickets/no-javascript-test-runner.md.
     *
     * WATCHED REDS: delete `'status'` from DiscountPolicyResource::toArray(), then
     * `'requires_approval'`, then change the model's boolean cast. Each reds this arm and nothing
     * else in the file.
     */
    $school = School::factory()->create();
    dpsPolicy($school, 'Sibling discount', 'active');
    dpsPolicy($school, 'Old bursary', 'retired');

    $body = test()->actingAs(dpsUser($school, ['finance.access', 'finance.discount-policy.change.submit']))
        ->withSession(['school_id' => $school->id])
        ->getJson('/api/v1/finance/discount-policies')
        ->assertOk()
        ->json();

    expect($body)->toHaveCount(2);

    foreach ($body as $row) {
        expect($row)->toHaveKeys(['id', 'name', 'status', 'requires_approval']);

        // A BOOLEAN, not 0/1 — `requires_approval !== true` in the client is false for the integer.
        expect($row['requires_approval'])->toBeBool();

        // One of the three the client's `SelectablePolicy['status']` union declares. A fourth value
        // would be filtered out by the client with no way to tell that from "no policies".
        expect($row['status'])->toBeIn(['active', 'superseded', 'retired']);
    }

    // And the one value the client's predicate turns on actually arrives, rather than every row
    // happening to be inactive — without this the arm would pass over a Resource that hardcoded a
    // wrong-but-well-shaped status.
    expect(collect($body)->pluck('status')->sort()->values()->all())->toBe(['active', 'retired']);

    /*
     * THE TERMS KEYS, PINNED FOR THE SCREEN THE SAME WAY THE TWO ABOVE ARE PINNED FOR THE MODAL.
     *
     * The four expectations above cover `id`, `name`, `status` and `requires_approval` — the keys
     * new-invoice-modal.tsx reads. discount-policies.tsx reads FIVE MORE: `basis`, `value_minor`,
     * `value_currency`, `percent` and `description`. Nothing asserted any of them before this block,
     * and every one of them fails the same silent way § 26 describes, because the screen's own
     * no-value glyph absorbs the loss:
     *
     *   - `basis` renamed → `policy.basis === 'percent'` is false for every row, valueLabel() takes
     *     the AMOUNT branch, `value_minor` is undefined, and every policy's Value cell renders an em
     *     dash while the Basis column reads "Fixed amount" for a percentage. No error, no console
     *     entry: a page that quietly stops saying what any policy does.
     *   - `percent` / `value_minor` / `value_currency` renamed → the same em dash from the other
     *     side, and — the expensive half — openAmend() prefills the form from those keys, so an
     *     amendment authored from a blanked prefill re-states the policy's terms as empty and the
     *     bursar retypes (or fails to retype) a rate the ED then approves.
     *
     * SHAPES, NOT JUST PRESENCE, for the same reason `requires_approval` is asserted `toBeBool()`
     * above. `value_minor` and `percent` must be INTEGERS OR NULL: the screen hands `value_minor`
     * straight to formatNaira/minorToNairaInput, which are integer-minor-unit helpers, and a string
     * "2500000" arriving there is the float-money bug re-entering through the wire rather than
     * through JavaScript. `basis` must be one of the two the client's union declares.
     *
     * AND THE BASIS-EXCLUSIVE RULE IS ASSERTED ON THE PAYLOAD, not just in the schema: the row that
     * carries money carries no percent and vice versa. That is what lets valueLabel() branch on
     * `basis` alone.
     *
     * WATCHED REDS: delete `'basis'` from DiscountPolicyResource::toArray(), then `'value_minor'`,
     * then `'percent'`, then `'description'`. Each reds this block and nothing else in the file.
     */
    $withAmount = ActiveSchool::runFor($school->id, fn () => DiscountPolicy::create([
        'school_id' => $school->id, 'name' => 'Staff rate', 'basis' => 'amount',
        'value_minor' => 2500000, 'value_currency' => 'NGN', 'description' => 'Children of staff.',
        'requires_approval' => true, 'status' => 'active',
    ]));

    $full = test()->actingAs(dpsUser($school, ['finance.access', 'finance.discount-policy.change.submit']))
        ->withSession(['school_id' => $school->id])
        ->getJson('/api/v1/finance/discount-policies')
        ->assertOk()
        ->json();

    expect($full)->toHaveCount(3);

    foreach ($full as $row) {
        expect($row)->toHaveKeys([
            'id', 'name', 'description', 'basis', 'value_minor', 'value_currency', 'percent',
            // `base` gained a reader on the screen at the same time as this line: valueLabel() used
            // to hardcode "of discountable charges" over every percent policy, `total` ones
            // included. Drop the key from the Resource and the client says "of discountable
            // charges" about half the whole bill — a wrong figure, silently, with the em-dash
            // fallback nowhere in sight because `percent` still arrives.
            'base', 'requires_approval', 'status',
        ]);

        expect($row['basis'])->toBeIn(['amount', 'percent']);

        // Integer-or-null on both halves. formatNaira/minorToNairaInput take integer minor units.
        expect($row['value_minor'] === null || is_int($row['value_minor']))->toBeTrue(
            'value_minor arrived as '.gettype($row['value_minor']).'; the screen hands it to the '
            .'integer minor-unit helpers unchanged.');
        expect($row['percent'] === null || is_int($row['percent']))->toBeTrue(
            'percent arrived as '.gettype($row['percent']).'; the screen renders it as a whole number.');

        // ONE SIDE OF THE BASIS IS POPULATED, NEVER BOTH — valueLabel() branches on `basis` alone.
        if ($row['basis'] === 'amount') {
            expect($row['value_minor'])->not->toBeNull();
            expect($row['value_currency'])->toBeString();
            expect($row['percent'])->toBeNull();
        } else {
            expect($row['percent'])->not->toBeNull();
            expect($row['value_minor'])->toBeNull();
            expect($row['value_currency'])->toBeNull();
        }
    }

    // NOT VACUOUS: both bases actually occur in this payload, so the branch above ran twice. Without
    // this the amount side could be pinned by a body containing only percent rows.
    expect(collect($full)->pluck('basis')->unique()->sort()->values()->all())->toBe(['amount', 'percent']);

    // And `description` carries its value rather than merely existing — a Resource that emitted a
    // constant null would satisfy toHaveKeys() and blank every secondary line on the list.
    expect(collect($full)->firstWhere('id', $withAmount->uuid)['description'])->toBe('Children of staff.');
});

it('shows School B its OWN policies, and none of School A’s', function () {
    // The isolation the browser drive checks by eye, asserted. DiscountPolicy carries SchoolScope; this
    // is the arm that fails if the catalog query ever loses its bound.
    $a = School::factory()->create();
    $b = School::factory()->create();
    dpsPolicy($a, 'School A bursary', 'active');
    dpsPolicy($a, 'School A retired', 'retired');
    $mine = dpsPolicy($b, 'School B sibling', 'active');

    $response = test()->actingAs(dpsUser($b, ['finance.access', 'finance.discount-policy.change.submit']))
        ->withSession(['school_id' => $b->id])
        ->getJson('/api/v1/finance/discount-policies')
        ->assertOk();

    expect(collect($response->json())->pluck('id')->all())->toBe([$mine->uuid]);
});

// ── The coupling, and the split that cannot happen here ──────────────────────────────────────────

it('every role that may open this screen may also read the catalog it fetches', function () {
    /*
     * THE PAGE CARRIES NO PROPS, AND THAT IS ONLY SAFE BECAUSE OF THIS. The list is a fetch of
     * GET /api/v1/finance/discount-policies, which carries only the route group's `finance.access`;
     * the page is gated on `finance.discount-policy.change.submit`. A role holding the second without
     * the first cannot even reach the route group — the screen would 403 before it rendered — so the
     * coupling is what makes "no props" a decision rather than a hole.
     *
     * Read off RbacSeeder::grantsMap() rather than a list here: a second copy of the map is the drift
     * being guarded against.
     *
     * WATCHED RED by removing FINANCE_ACCESS from the `finance_lead` row of grantsMap(): this fails
     * naming that role.
     */
    $propose = PermissionEnum::FINANCE_DISCOUNT_POLICY_CHANGE_SUBMIT->value;
    $read = PermissionEnum::FINANCE_ACCESS->value;

    $broken = [];
    foreach (RbacSeeder::grantsMap() as $role => $grants) {
        if (in_array($propose, $grants, true) && ! in_array($read, $grants, true)) {
            $broken[] = $role;
        }
    }

    expect($broken)->toBe([],
        'Role(s) ['.implode(', ', $broken)."] hold {$propose} but not {$read}. /finance/discount-policies "
        .'sits inside the finance.access route group and its list is a fetch of the catalog endpoint, '
        .'so that seat is offered a nav item onto a screen that 403s before it renders.');

    // NOT VACUOUS: the loop has to have inspected something. A grantsMap that stopped naming the
    // proposing ability at all would otherwise pass this by looking at nothing.
    $holders = collect(RbacSeeder::grantsMap())->filter(fn (array $g) => in_array($propose, $g, true))->keys();

    expect($holders->count())->toBeGreaterThan(0,
        'No role in grantsMap() holds '.$propose.' — the loop above iterated and matched nothing, so it '
        .'proved nothing. Either the ability was renamed or the map lost every proposing seat.');
});

it('has ONE MAKER ability, so the page gate and the button gate cannot disagree', function () {
    /*
     * WHY THIS SCREEN NEEDS NO SECOND `can(...)` GATE, stated as a fact about the permission catalog.
     * Fee schedules has `finance.fee-schedule.manage` (open the screen) AND
     * `finance.fee-schedule.change.submit` (send the proposal), and the seeded `admin` role holds the
     * first without the second — hence the in-page gate that file asserts. The discount catalog coins
     * no `manage` ability: propose, amend and retire all POST the one endpoint that the page gate
     * names, so holding the page implies holding every control on it.
     *
     * THE FILTER MATCHES THE PROPERTY, NOT A PREFIX, and that is the whole difference between this arm
     * working and this arm looking like it works. Its first version filtered
     * `str_starts_with($ability, 'finance.discount-policy.')` — so a future maker ability spelled
     * anything else (`finance.discount.manage`, `finance.discount-policy-catalog.manage`) was invisible
     * to it, and it would have stayed green through exactly the divergence it exists to catch. A prefix
     * is a guess about how the next person will name the thing.
     *
     * The obvious widening — `str_contains($ability, 'discount')` — walks into the opposite trap: it
     * matches the two CHECKER abilities as well, and this arm asserts there is exactly ONE maker one.
     * So checker actions are excluded by the TERMINAL-SEGMENT CONVENTION rather than by naming them:
     * `ApprovalAbility::isExcludedFromSuperAdminBypass()` is the same predicate the sidebar's checker
     * item and ADR 0040's bypass exclusion already use. A third hand-written copy of "ends with approve
     * or reject" here would be the drift this repo removes everywhere else.
     */
    $makerAbilities = collect(PermissionEnum::cases())
        ->map(fn (PermissionEnum $case) => $case->value)
        ->filter(fn (string $ability) => str_contains(strtolower($ability), 'discount')
            && ! ApprovalAbility::isExcludedFromSuperAdminBypass($ability))
        ->values();

    expect($makerAbilities->all())->toBe([
        'finance.discount-policy.change.submit',
        // ADDED BY THE BSS AWARD IMPORT, AND ADMITTED ONLY BECAUSE IT GATES NOTHING ON THIS SCREEN.
        // `finance.discount-award.manage` gates four routes under /v1/finance/discount-award-imports
        // — template, upload, status, report — and this page posts none of them. So the page gate and
        // the button gate still cannot disagree here: holding the page still implies holding every
        // control on it.
        //
        // THAT SENTENCE IS NOT LEFT AS PROSE. The arm below asserts this page references neither the
        // import endpoint nor the new ability, so the day a control for it IS added to this screen,
        // this file goes red and the U1 question becomes live again — which is what this tripwire was
        // widened on the promise of.
        'finance.discount-award.manage',
    ], 'A further MAKER-side discount ability now exists. '
        .DPS_PAGE.' gates the whole page on finance.discount-policy.change.submit and asks nothing '
        .'else, because every control on it posts that one endpoint. If the new ability '
        .'gates an action on this screen, the page gate and the button gate can now disagree — the U1 '
        .'defect — and the page needs an in-page can() on whatever those controls actually post.');

    // NOT VACUOUS in the other direction: the checker sides must still be visible to the filter before
    // the exclusion removes them. If the catalog stopped carrying discount abilities altogether, the
    // expectation above would be asserting emptiness against a single-element list and would fail — but
    // if the WORD changed (a rename to `concession`), it would pass by matching nothing at all.
    $everyDiscountAbility = collect(PermissionEnum::cases())
        ->map(fn (PermissionEnum $case) => $case->value)
        ->filter(fn (string $ability) => str_contains(strtolower($ability), 'discount'))
        ->values();

    expect($everyDiscountAbility->all())->toBe([
        'finance.discount-policy.change.submit',
        'finance.discount-policy.change.approve',
        'finance.discount-policy.change.reject',
        'finance.discount-award.manage',
    ], 'The discount ability set no longer matches on the word "discount". If these were renamed, the '
        .'maker arm above is now matching nothing and proving nothing — re-derive its filter from '
        .'whatever the catalog calls them now.');
});

it('does not put an award-import control on this page — the reason the maker arm was widened', function () {
    /*
     * THE ENFORCEMENT BEHIND THE ARM ABOVE. `finance.discount-award.manage` was admitted to the maker
     * list on ONE claim: it gates nothing on this screen, so the page gate and the button gate cannot
     * disagree. A claim wider than its artifact is the same defect as a control with no enforcement,
     * one level up — so the claim is checked rather than believed.
     *
     * If a later commit puts the award import's template button, upload or report link on this page,
     * this arm reds and the U1 question is live again: the page would then need an in-page can() on
     * `finance.discount-award.manage`, because a seat holding change.submit does NOT hold it (the
     * grants map puts it on accounts_officer alone).
     */
    $page = dpsRead(DPS_PAGE);

    // `str_contains(...)` + `toBeFalse($message)`, NOT `->not->toContain($needle, $message)`.
    // `toContain` is VARIADIC, so a message passed to it becomes a SECOND NEEDLE and the negation
    // then passes whenever EITHER string is absent — which the message always is. Written that way
    // first, this arm stayed green with the import endpoint planted in the page: a guard that could
    // not fail, reading as covered. Caught by bite-proving it rather than by reading it.
    expect(str_contains($page, 'discount-award-imports'))->toBeFalse(
        DPS_PAGE.' now posts or links the discount-award import endpoint, which is gated on '
        .'finance.discount-award.manage while the page gate asks only for '
        .'finance.discount-policy.change.submit. Those are different seats — add an in-page can() on '
        .'the control, or move it to its own screen.');

    expect(str_contains($page, 'discount-award.manage'))->toBeFalse(
        DPS_PAGE.' now names finance.discount-award.manage. If this page has grown an award control, '
        .'gate that control in-page rather than leaving the whole screen on one ability.');
});

// ── The nav entry ────────────────────────────────────────────────────────────────────────────────

it('the sidebar item keys on the SAME ability as the route', function () {
    // FinanceNavCoverageTest already fails if the href is missing entirely. This asserts the other
    // half — that the item and the route ask the same question, so a visible entry cannot 403.
    // Read as text for the reason FinanceNavCoverageTest gives: there is no JavaScript test runner in
    // this repository, so it is this or nothing.
    // `str_contains(...)` asserted as a BOOLEAN rather than `expect($sidebar)->toContain($needle)`:
    // watched red on this arm printed the whole 18kB sidebar instead of a sentence, which is the trap
    // FeeSchedulesScreenTest:216-220 records (Pest's toContain is variadic, so a message argument is
    // read as a second needle). The failure has to say what to do about it.
    $sidebar = dpsRead(DPS_SIDEBAR);

    expect(str_contains($sidebar, "'/finance/discount-policies'"))->toBeTrue(
        DPS_SIDEBAR.' has no item pointing at /finance/discount-policies.');

    expect(str_contains($sidebar, "can('finance.discount-policy.change.submit')"))->toBeTrue(
        DPS_SIDEBAR.' shows the Discount policies item on a different question from the one its route '
        .'asks. The route is gated on finance.discount-policy.change.submit; an item keyed on anything '
        .'else renders for a seat the route then refuses.');
});

it('the page fetches the catalog UNFILTERED, so a retired policy stays readable on it', function () {
    // The arm above proves the ENDPOINT returns every state. This proves the page asks for every state:
    // adding `?status=active` to that fetch would leave the endpoint arm green and quietly erase the
    // provenance section from the screen.
    // The needle is the URL and the ABSENCE of a status query, not the whole `axios.get(…)` call:
    // prettier wraps that call across three lines once the argument makes it long enough, and a needle
    // that a formatter can break is a test that fails for the wrong reason. (Observed here — the first
    // version of this arm went red on `prettier --write` alone.)
    $page = dpsRead(DPS_PAGE);

    expect(str_contains($page, "'/api/v1/finance/discount-policies'"))->toBeTrue(
        DPS_PAGE.' no longer fetches the discount catalog at all.');

    expect(str_contains($page, '/api/v1/finance/discount-policies?status='))->toBeFalse(
        DPS_PAGE.' now filters the catalog fetch by status. A superseded or retired policy is the only '
        .'thing that can explain a discount on an invoice issued months ago, and this screen is where it '
        .'stays readable.');
});
