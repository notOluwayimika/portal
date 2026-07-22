<?php

use App\Enums\Permission as PermissionEnum;
use App\Models\SubjectResultStatus;
use App\Models\User;
use App\Support\ApprovalAbility;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * ADR 0040 mechanism 1 — the super-admin bypass excludes checker actions.
 *
 * The point of this file is DRIFT RESISTANCE. ADR 0040 words the exclusion as
 * `finance.*.approve`; ADR 0044 then created `result.approve`/`result.reject`,
 * which that wording does not match. A denylist would already be wrong. So the
 * rule is a convention (terminal segment), and the tests below are written
 * against the ENUM rather than against named abilities — a future
 * `finance.invoice.approve` is asserted the moment the case is added, with no
 * one having to remember this file exists.
 */
function sbe_superAdmin(): User
{
    $user = User::factory()->create();
    setPermissionsTeamId(null);
    $user->assignRole('super_admin');
    $user->flushSchoolAccessCache();

    return $user;
}

it('excludes every terminally-approve/reject enum case from the bypass, and excludes nothing else', function () {
    $this->seed(DatabaseSeeder::class);
    config(['auth.gate_before_superadmin' => true]);

    $superAdmin = sbe_superAdmin();

    // The seeded super_admin holds none of the checker permissions (C1), so any
    // `true` below could only have come from the bypass.
    $granted = $superAdmin->getAllPermissions()->pluck('name');

    foreach (PermissionEnum::cases() as $case) {
        $ability = $case->value;
        $isChecker = in_array(
            ApprovalAbility::terminalSegment($ability),
            ApprovalAbility::CHECKER_SEGMENTS,
            true,
        );

        if ($isChecker) {
            expect($granted)->not->toContain($ability, "precondition: super_admin must not HOLD {$ability}");
            expect($superAdmin->can($ability))->toBeFalse(
                "{$ability} is a checker action (ADR 0040) and must NOT be bypassed — "
                    .'if this is a new permission, that is the point: the convention covered it automatically.',
            );
        } else {
            expect($superAdmin->can($ability))->toBeTrue(
                "{$ability} is not a checker action and must still be bypassed while the flag is on.",
            );
        }
    }
});

it('covers the checker abilities that exist today, by name (the regression this was written for)', function () {
    $this->seed(DatabaseSeeder::class);
    config(['auth.gate_before_superadmin' => true]);

    $superAdmin = sbe_superAdmin();

    // result.* does NOT match ADR 0040's literal `finance.*.approve` wording.
    // A prefix-matching implementation passes every other test in this file and
    // fails these two.
    expect($superAdmin->can('result.approve'))->toBeFalse()
        ->and($superAdmin->can('result.reject'))->toBeFalse()
        ->and($superAdmin->can('result.submit'))->toBeTrue()      // maker side is not excluded
        ->and($superAdmin->can('result.view_scores'))->toBeTrue(); // reads are not excluded
});

it('excludes bare Policy ability names too (Gate::authorize passes "approve", not "result.approve")', function () {
    $this->seed(DatabaseSeeder::class);
    config(['auth.gate_before_superadmin' => true]);

    $superAdmin = sbe_superAdmin();

    // Policy methods reach Gate::before under their bare method name. If the
    // convention only understood dotted names, every Policy approve() would be
    // bypassed and the structural maker≠checker rule would be unreachable for
    // super_admin — the exact hole ADR 0040 forbids.
    expect(ApprovalAbility::isExcludedFromSuperAdminBypass('approve'))->toBeTrue()
        ->and(ApprovalAbility::isExcludedFromSuperAdminBypass('reject'))->toBeTrue()
        ->and($superAdmin->can('approve', new SubjectResultStatus))->toBeFalse();
});

it('classifies abilities by terminal segment, not by prefix or substring', function () {
    expect(ApprovalAbility::isExcludedFromSuperAdminBypass('finance.invoice.approve'))->toBeTrue()
        ->and(ApprovalAbility::isExcludedFromSuperAdminBypass('result.approve'))->toBeTrue()
        ->and(ApprovalAbility::isExcludedFromSuperAdminBypass('approve'))->toBeTrue()
        // Not checker actions, despite containing the words:
        ->and(ApprovalAbility::isExcludedFromSuperAdminBypass('result.approve_history.view'))->toBeFalse()
        ->and(ApprovalAbility::isExcludedFromSuperAdminBypass('invoice.rejection_reason.read'))->toBeFalse()
        ->and(ApprovalAbility::isExcludedFromSuperAdminBypass('principal_approval.manage'))->toBeFalse();
});

it('leaves the exclusion in force even when the bypass flag is off (it is not a flag-shaped control)', function () {
    $this->seed(DatabaseSeeder::class);
    config(['auth.gate_before_superadmin' => false]);

    expect(sbe_superAdmin()->can('result.approve'))->toBeFalse();
});

it('keeps the bypass-flag DEFAULT true — load-bearing for the C2/C3 deploy until ADR 0045 retires it', function () {
    // Ask 1 resolved as a code fact: absent env var + true default = bypass on
    // at deploy. That default is now what stands between super_admin and a
    // silent 27-group lockout, so it is a GUARD, not a spot-check: this fails
    // loudly if anyone flips the default before 0045 removes the flag.
    // (Prod also sets AUTH_GATE_BEFORE_SUPERADMIN=true explicitly — I5 runbook
    // line — belt and suspenders; this pins the belt.)
    expect(config('auth.gate_before_superadmin'))->toBeTrue();
});
