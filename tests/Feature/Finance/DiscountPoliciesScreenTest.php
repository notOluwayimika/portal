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
    ], 'A second MAKER-side discount ability now exists. '
        .DPS_PAGE.' gates the whole page on finance.discount-policy.change.submit and asks nothing '
        .'else, because until now every control on it posted that one endpoint. If the new ability '
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
    ], 'The discount ability set no longer matches on the word "discount". If these were renamed, the '
        .'maker arm above is now matching nothing and proving nothing — re-derive its filter from '
        .'whatever the catalog calls them now.');
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
