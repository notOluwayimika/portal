<?php

use App\Enums\Permission as PermissionEnum;
use App\Models\Role;
use App\Models\User;
use App\Notifications\Services\Resolvers\CheckerAbilityResolver;
use App\Notifications\Types\ApprovalRequested;
use App\Support\ApprovalAbility;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/**
 * The permission-derived resolver — the harder of the two shapes, and the one
 * where a plausible-looking implementation is wrong in three separate ways.
 */
function car_notification(string $ability, int $schoolId, ?int $submittedBy = null): ApprovalRequested
{
    return new ApprovalRequested(
        checkerAbility: $ability,
        subject: User::query()->firstOrFail(),   // any morphable record will do here
        schoolId: $schoolId,
        submittedBy: $submittedBy,
        summary: 'test',
    );
}

function car_grantViaRole(User $user, int $schoolId, string $ability): void
{
    setPermissionsTeamId(null);
    $role = Role::firstOrCreate(['name' => 'checker_'.$ability, 'guard_name' => 'web']);
    $role->givePermissionTo(Permission::findOrCreate($ability, 'web'));

    setPermissionsTeamId($schoolId);
    $user->assignRole($role);
}

it('finds checkers granted through a role, and only in their own school', function () {
    $school = al_makeSchool();
    $other = al_makeSchool();
    $ability = PermissionEnum::FINANCE_INVOICE_VOID_REQUEST_APPROVE->value;

    $checker = al_makeUser($school->id);
    $elsewhere = al_makeUser($other->id);
    $bystander = al_makeUser($school->id);

    car_grantViaRole($checker, $school->id, $ability);
    // Same grant, DIFFERENT school team. `school_user` is a pivot, so this is the
    // ordinary case of one human holding a seat at one school and not another —
    // and notifying them here would be a cross-tenant leak.
    car_grantViaRole($elsewhere, $other->id, $ability);

    $resolved = collect((new CheckerAbilityResolver)->resolve(car_notification($ability, $school->id)))
        ->map(fn ($r) => $r->notifiableId);

    expect($resolved)->toContain($checker->id)
        ->and($resolved)->not->toContain($elsewhere->id)
        ->and($resolved)->not->toContain($bystander->id);
});

it('finds checkers granted the permission directly, not only through a role', function () {
    $school = al_makeSchool();
    $ability = PermissionEnum::FINANCE_CREDIT_NOTE_APPROVE->value;

    $direct = al_makeUser($school->id);
    setPermissionsTeamId($school->id);
    $direct->givePermissionTo(Permission::findOrCreate($ability, 'web'));

    $resolved = collect((new CheckerAbilityResolver)->resolve(car_notification($ability, $school->id)))
        ->map(fn ($r) => $r->notifiableId);

    // A resolver that only joined model_has_roles would tell someone who CAN act
    // that there was nothing to act on.
    expect($resolved)->toContain($direct->id);
});

/**
 * CORRECTION #2, PROVED. `can()` is the correct oracle for testing ONE known
 * user; it is the wrong tool for enumerating the set. This test uses it in
 * exactly that role — as the check on the set the query built, never as the way
 * the set is built.
 */
it('agrees with can() for every user, without ever sweeping users through can()', function () {
    $school = al_makeSchool();
    $ability = PermissionEnum::RESULT_APPROVE->value;

    $checker = al_makeUser($school->id);
    car_grantViaRole($checker, $school->id, $ability);
    $bystander = al_makeUser($school->id);

    $resolved = collect((new CheckerAbilityResolver)->resolve(car_notification($ability, $school->id)))
        ->map(fn ($r) => $r->notifiableId)
        ->all();

    setPermissionsTeamId($school->id);

    foreach ([$checker, $bystander] as $user) {
        $user->unsetRelation('roles')->unsetRelation('permissions');
        expect(in_array($user->id, $resolved, true))->toBe($user->can($ability));
    }
});

/**
 * A super admin's power over a checker ability does NOT come from a stored grant
 * — ADR 0040 excludes checker actions from the Gate::before bypass. So the
 * stored-grant query gives the right answer here for free, which is exactly why
 * the resolver is restricted to checker abilities (correction #1).
 */
it('does not notify a super admin who holds no stored grant', function () {
    config(['auth.gate_before_superadmin' => true]);
    $school = al_makeSchool();
    $ability = PermissionEnum::FINANCE_INVOICE_VOID_REQUEST_APPROVE->value;

    $superAdmin = al_makeUser($school->id);
    setPermissionsTeamId(null);
    $superAdmin->assignRole('super_admin');
    $superAdmin->flushSchoolAccessCache();

    $resolved = collect((new CheckerAbilityResolver)->resolve(car_notification($ability, $school->id)))
        ->map(fn ($r) => $r->notifiableId);

    expect($resolved)->not->toContain($superAdmin->id);
});

it('skips withdrawn and disabled accounts, which keep their grants until revoked', function () {
    $school = al_makeSchool();
    $ability = PermissionEnum::RESULT_REJECT->value;

    $disabled = al_makeUser($school->id);
    $withdrawn = al_makeUser($school->id);
    car_grantViaRole($disabled, $school->id, $ability);
    car_grantViaRole($withdrawn, $school->id, $ability);

    $disabled->forceFill(['disabled_at' => now()])->save();
    $withdrawn->delete();

    $resolved = collect((new CheckerAbilityResolver)->resolve(car_notification($ability, $school->id)))
        ->map(fn ($r) => $r->notifiableId);

    expect($resolved)->not->toContain($disabled->id)
        ->and($resolved)->not->toContain($withdrawn->id);
});

it('yields each checker once even when the grant arrives through two roles', function () {
    $school = al_makeSchool();
    $ability = PermissionEnum::FINANCE_CREDIT_NOTE_REJECT->value;
    $user = al_makeUser($school->id);

    setPermissionsTeamId(null);
    $permission = Permission::findOrCreate($ability, 'web');
    foreach (['checker_a', 'checker_b'] as $name) {
        $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        setPermissionsTeamId($school->id);
        $user->assignRole($role);
        setPermissionsTeamId(null);
    }

    $resolved = collect((new CheckerAbilityResolver)->resolve(car_notification($ability, $school->id)))
        ->map(fn ($r) => $r->notifiableId);

    expect($resolved->filter(fn ($id) => $id === $user->id))->toHaveCount(1);
});

/**
 * CORRECTION #1, PROVED — as a THROW, not an assert().
 *
 * `zend.assertions` is compiled out in production, so an assert() here would be a
 * guard that exists everywhere except where it matters. The test asserts the
 * exception is thrown with assertions in whatever state this run has them.
 */
it('refuses a non-checker ability rather than silently omitting super admins', function () {
    $school = al_makeSchool();

    // A real, non-checker ability from the enum: super admins DO hold this by
    // bypass with no stored grant, so a stored-grant query would silently omit
    // every one of them.
    $ability = PermissionEnum::RESULT_VIEW_SCORES->value;
    expect(ApprovalAbility::isExcludedFromSuperAdminBypass($ability))->toBeFalse();

    // Constructed around ApprovalRequested, which validates too — so the resolver
    // is driven directly to prove ITS guard, not the type's.
    $notification = new class($ability, $school->id) implements \App\Notifications\Contracts\Notification
    {
        public function __construct(private string $ability, private int $schoolId) {}

        public function type(): \App\Notifications\Enums\NotificationType
        {
            return \App\Notifications\Enums\NotificationType::APPROVAL_REQUESTED;
        }

        public function schoolId(): int
        {
            return $this->schoolId;
        }

        public function subject(): ?\Illuminate\Database\Eloquent\Model
        {
            return null;
        }

        public function actorId(): ?int
        {
            return null;
        }

        public function payload(): array
        {
            return ['checker_ability' => $this->ability];
        }

        public function dedupKey(): ?string
        {
            return null;
        }

        public function renderedFallback(): ?string
        {
            return null;
        }
    };

    expect(fn () => iterator_to_array((new CheckerAbilityResolver)->resolve($notification)))
        ->toThrow(LogicException::class, 'not one of');
});
