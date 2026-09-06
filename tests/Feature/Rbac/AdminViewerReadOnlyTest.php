<?php

use App\Enums\Permission as PermissionEnum;
use App\Models\User;
use App\Support\ApprovalAbility;
use App\Support\DutySeparation;
use App\Support\ReadOnlyAbility;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * `admin_viewer` — the read-only admin seat.
 *
 * TWO KINDS OF ARM LIVE HERE AND THEY ARE NOT INTERCHANGEABLE. The first kind asserts the
 * DERIVATION (the set is `admin` filtered by {@see ReadOnlyAbility}, plus the one door that cannot
 * be derived). The second asserts the CONSEQUENCE at the Gate — this seat answers yes to reads and
 * no to writes. A derivation arm alone would prove the seeder equals itself; a Gate arm alone would
 * not notice the set silently ceasing to track `admin`.
 *
 * The third kind — "does any ability this role holds unlock a non-GET ROUTE" — is the load-bearing
 * one and it is deliberately NOT here: it belongs against the live route table, and it lives in
 * {@see \Tests\Feature\Rbac\AdminViewerHoldsNoWriteGateTest}. That is the arm that caught
 * `admin_area.access` guarding 18 writes in the first place.
 */
function av_role(): array
{
    return RbacSeeder::grantsMap()['admin_viewer'];
}

it('is exactly admin\'s read-only grants plus the one door that cannot be derived', function () {
    $admin = RbacSeeder::grantsMap()['admin'];
    $viewer = av_role();

    // Everything the seat holds either comes from admin, or is the coined read-only door.
    expect(array_values(array_diff($viewer, $admin)))
        ->toEqual([PermissionEnum::ADMIN_AREA_VIEW->value],
            'admin_viewer may hold nothing admin does not, except the read-only door admin has no need of');

    // And it drops nothing admin holds that is a read — the set TRACKS admin rather than
    // snapshotting it. Without this arm the derivation could quietly narrow and every other arm
    // here would stay green.
    expect(array_values(array_diff(ReadOnlyAbility::filter($admin), $viewer)))
        ->toBeEmpty('every read-only grant on admin must reach admin_viewer');
});

it('holds no write, named as a property rather than as a list of the writes we thought of', function () {
    foreach (av_role() as $ability) {
        expect(ReadOnlyAbility::isReadOnly($ability))
            ->toBeTrue("[{$ability}] is not a read under the ReadOnlyAbility convention");
    }
});

/*
 * SEGREGATION OF DUTIES. Stated against DutySeparation::pairs() — the DERIVED pair set — rather
 * than against a list of finance permissions typed out here, so a maker/checker pair coined
 * tomorrow is covered with no edit to this file. `pairs()` is used rather than `enforcedPairs()`
 * deliberately: enforcement is scoped to finance, but a read-only seat must hold no side of ANY
 * pair, including the result workstream's.
 */
it('holds neither side of any maker-checker pair', function () {
    $held = av_role();
    $pairs = DutySeparation::pairs();

    expect($pairs)->not->toBeEmpty('a vacuous pair list would make this arm pass by construction');

    foreach ($pairs as $pair) {
        expect($held)->not->toContain($pair['checker'])
            ->and($held)->not->toContain($pair['maker']);
    }
});

it('holds no checker ability at all, by the terminal-segment convention', function () {
    foreach (av_role() as $ability) {
        expect(ApprovalAbility::isExcludedFromSuperAdminBypass($ability))
            ->toBeFalse("[{$ability}] is a checker action and cannot sit on a read-only seat");
    }
});

it('requires two-factor enrolment', function () {
    expect(RbacSeeder::TWO_FACTOR_REQUIRED)->toContain('admin_viewer');
})->group('arch');

/*
 * ─── THE GATE ARMS ───────────────────────────────────────────────────────────────────────────────
 *
 * Each is a real `$user->can()` in a real school team. The write list is not a sample: it names the
 * two academic writes the brief called out, plus EVERY finance maker and checker in the catalogue,
 * derived from DutySeparation::pairs() so it cannot fall behind the enum.
 */
function av_user(): User
{
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    $user->grantSchoolAccess($school, 'admin_viewer');
    $user->flushSchoolAccessCache();
    setPermissionsTeamId($school->id);
    $user->unsetRelation('roles')->unsetRelation('permissions');

    return $user;
}

it('passes the read gates', function () {
    $this->seed(DatabaseSeeder::class);
    $user = av_user();

    foreach ([
        PermissionEnum::ADMIN_AREA_VIEW->value,
        PermissionEnum::ACADEMIC_DATA_VIEW->value,
        PermissionEnum::STUDENT_VIEW->value,
        PermissionEnum::ACTIVITY_LOG_VIEW->value,
        PermissionEnum::GUARDIAN_VIEW->value,
    ] as $ability) {
        expect($user->can($ability))->toBeTrue("admin_viewer must be able to [{$ability}]");
    }
});

it('fails every write gate, including the area gate it is deliberately NOT given', function () {
    $this->seed(DatabaseSeeder::class);
    $user = av_user();

    $writes = [
        // Named in the brief.
        PermissionEnum::ACADEMIC_SETUP_MANAGE->value,
        PermissionEnum::ACADEMICS_ROLLOVER->value,
        // The area gate. This is the arm that would have caught the original design: holding
        // `admin_area.access` is what would have handed this seat POST /students and the guardian
        // password reset. It must answer NO even though the seat can see the admin area.
        PermissionEnum::ADMIN_AREA_ACCESS->value,
        // Representative writes from each of admin's other namespaces.
        PermissionEnum::GUARDIAN_UPDATE->value,
        PermissionEnum::GUARDIAN_ENABLE_LOGIN->value,
        PermissionEnum::SCORE_MANAGE->value,
        PermissionEnum::RBAC_MANAGE_USERS->value,
        PermissionEnum::ACTIVITY_LOG_EXPORT->value,
        PermissionEnum::FINANCE_ACCESS->value,
    ];

    // EVERY finance maker and checker, derived — not a list somebody has to remember to extend.
    foreach (DutySeparation::pairs() as $pair) {
        $writes[] = $pair['maker'];
        $writes[] = $pair['checker'];
    }

    foreach (array_unique($writes) as $ability) {
        expect($user->can($ability))->toBeFalse("admin_viewer must NOT be able to [{$ability}]");
    }
});
