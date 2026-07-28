<?php

use App\Enums\Permission;
use App\Enums\PermissionGroup;

/**
 * The permission taxonomy the RBAC console is built on.
 *
 * The load-bearing assertion is the PARTITION: every permission belongs to exactly one group, and
 * every group member is a real permission. {@see PermissionGroup} lists membership explicitly
 * rather than deriving it, precisely so that this test can be the thing that notices when someone
 * adds a case and does not file it — without it, a new permission would simply not appear in the
 * console and nothing anywhere would go red.
 *
 * No database: this is pure code-shape, so it runs without RefreshDatabase and costs nothing.
 */
it('partitions every permission into exactly one group', function () {
    $filed = collect(PermissionGroup::cases())
        ->flatMap(fn (PermissionGroup $group) => $group->permissions())
        ->map(fn (Permission $permission) => $permission->value);

    $all = collect(Permission::values());

    // Named diffs on both sides, so a failure says WHICH permission drifted rather than just
    // reporting two numbers that disagree.
    expect($all->diff($filed)->values()->all())->toBe([], 'permissions missing from every group')
        ->and($filed->diff($all)->values()->all())->toBe([], 'group members that are not permissions')
        ->and($filed->duplicates()->values()->all())->toBe([], 'permissions filed under two groups')
        ->and($filed)->toHaveCount($all->count());
});

it('resolves a group for every permission without a fallback', function () {
    foreach (Permission::cases() as $permission) {
        expect($permission->group())->toBeInstanceOf(PermissionGroup::class);
    }
});

it('files the awkward cases where the taxonomy disagrees with the name', function () {
    // These are exactly the permissions naive prefix-parsing gets wrong, which is why the
    // taxonomy is explicit. If someone "simplifies" PermissionGroup back to prefix parsing,
    // these four are what break.
    expect(Permission::RESULT_VIEW->group())->toBe(PermissionGroup::ROUTE_ACCESS)
        ->and(Permission::RESULT_SUBMIT->group())->toBe(PermissionGroup::RESULT_LIFECYCLE)
        ->and(Permission::STUDENT_CURRICULUM_UNENROLL->group())->toBe(PermissionGroup::STUDENT_RECORDS)
        ->and(Permission::STUDENT_CURRICULUM_REGISTER->group())->toBe(PermissionGroup::ENROLLMENT_LIFECYCLE)
        // Three segments, unlike almost everything else — prefix parsing files this under
        // "finance" but loses that it is a maker action.
        ->and(Permission::FINANCE_CREDIT_NOTE_SUBMIT->group())->toBe(PermissionGroup::FINANCE)
        // No dot at all: prefix parsing dumps all nine of these in a junk bucket.
        ->and(Permission::MANAGE_TEACHER_ASSIGNMENTS->group())->toBe(PermissionGroup::TEACHER_ASSESSMENTS);
});

it('gives every group a label, description and icon', function () {
    foreach (PermissionGroup::cases() as $group) {
        expect($group->label())->not->toBe('')
            ->and($group->description())->not->toBe('')
            ->and($group->icon())->toMatch('/^[A-Z][A-Za-z]+$/') // a lucide component name
            ->and($group->permissions())->not->toBeEmpty();
    }
});

it('derives a readable label from a permission name', function () {
    expect(Permission::GUARDIAN_UPDATE_CREDENTIALS->label())->toBe('Guardian update credentials')
        ->and(Permission::FINANCE_CREDIT_NOTE_SUBMIT->label())->toBe('Finance credit note submit')
        ->and(Permission::MANAGE_TEACHER_ASSIGNMENTS->label())->toBe('Manage teacher assignments');
});
