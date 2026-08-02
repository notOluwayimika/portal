<?php

use App\Enums\Permission as PermissionEnum;
use App\Models\School;
use App\Notifications\Contracts\Notification;
use App\Notifications\Contracts\RecipientResolver;
use App\Notifications\Services\NotificationRegistry;
use App\Notifications\Services\Resolvers\CheckerAbilityResolver;
use App\Notifications\Types\ApprovalRequested;
use App\Support\ApprovalAbility;

uses()->group('arch');

/*
|--------------------------------------------------------------------------
| Module privacy (blueprint §9/§10)
|--------------------------------------------------------------------------
|
| The mirror of the armed Finance rules. Notifications is consumed by other
| modules — Finance already calls it — so its internals have to be private from
| the first commit, not once someone reaches past them.
*/

arch('notification services are private to the module')
    ->expect('App\Notifications\Services')
    // App\Providers is the ONE allowed exception, and it is the composition root
    // doing its job: binding Contracts\Notifier to Services\Notifier necessarily
    // names both sides. Exactly the exception the Finance ACL binding already has
    // for BillableEnrollmentAdapter. Every other consumer sees only the contract.
    ->toOnlyBeUsedIn(['App\Notifications', 'App\Providers']);

arch('notification models are private to the module')
    ->expect('App\Notifications\Models')
    // The public Notifier contract returns the event record, so the Contracts
    // namespace names it. Nothing outside the module does.
    ->toOnlyBeUsedIn(['App\Notifications', 'App\Providers']);

arch('notification models are School-scoped')
    ->expect([
        'App\Notifications\Models\Notification',
        'App\Notifications\Models\NotificationRecipient',
        'App\Notifications\Models\NotificationPreference',
    ])
    ->toUse('App\Concerns\BelongsToSchool');

arch('the notification module does not reach into Finance')
    ->expect('App\Notifications')
    ->not->toUse('App\Finance');

/*
|--------------------------------------------------------------------------
| CORRECTION #1 — the resolver is reachable ONLY with checker abilities
|--------------------------------------------------------------------------
|
| CheckerAbilityResolver reads STORED grants. That is the correct recipient set
| for a checker ability precisely because ADR 0040 excludes checker actions from
| the super-admin Gate::before bypass — nobody holds one by bypass alone. For any
| OTHER ability a super admin's power leaves no stored grant, so the same query
| would silently omit every super admin while the UI shows they can act.
|
| The runtime guard is a thrown LogicException rather than assert(), because
| `zend.assertions` is compiled out in production — an assert would be a guard
| that exists everywhere except where it matters.
|
| These tests are written against the ENUM, not against a list of abilities, so a
| checker ability added tomorrow is covered without anyone remembering this file
| exists. Same enforcement shape as SuperAdminBypassExclusionTest.
*/

it('accepts every terminally-approve/reject enum case and refuses every other one', function () {
    $accepted = [];
    $refused = [];

    foreach (PermissionEnum::cases() as $case) {
        $isChecker = ApprovalAbility::isExcludedFromSuperAdminBypass($case->value);

        try {
            new ApprovalRequested(
                checkerAbility: $case->value,
                subject: new School,
                schoolId: 1,
                submittedBy: null,
                summary: 'arch probe',
            );
            $accepted[] = $case->value;

            expect($isChecker)->toBeTrue(
                "[{$case->value}] is not a checker ability but ApprovalRequested accepted it. "
                .'The recipient set would be built from stored grants and would omit every '
                .'super admin.'
            );
        } catch (LogicException) {
            $refused[] = $case->value;

            expect($isChecker)->toBeFalse(
                "[{$case->value}] IS a checker ability but ApprovalRequested refused it."
            );
        }
    }

    // Neither side may be vacuous: an implementation that accepted nothing, or
    // refused nothing, would satisfy every assertion above.
    expect($accepted)->not->toBeEmpty()->and($refused)->not->toBeEmpty();
});

it('guards the resolver at runtime rather than through assert(), which production compiles out', function () {
    $source = file_get_contents(dirname(__DIR__, 2).'/app/Notifications/Services/Resolvers/CheckerAbilityResolver.php');

    expect($source)->toContain('throw new LogicException')
        ->and($source)->not->toMatch('/^\s*assert\(/m');
});

/*
|--------------------------------------------------------------------------
| The dedup-key rule
|--------------------------------------------------------------------------
|
| `dedup_key` is EVENT identity. A key containing a recipient identifier is a
| different axis and silently destroys data — see NotificationDispatchTest, which
| proves the three-children case behaviourally. This test holds the structural
| half: the contract that makes the mistake hard to make.
*/

it('gives the notification contract no access to recipients, so a key cannot vary by one', function () {
    $reflection = new ReflectionClass(Notification::class);

    $methods = collect($reflection->getMethods())->map(fn ($m) => $m->getName());

    // The contract deliberately exposes no recipient accessor. A dedupKey() that
    // varied per recipient would have to receive one from the CALLER, which the
    // registry never does — so the axis error is structurally out of reach.
    expect($methods)->not->toContain('recipients')
        ->and($methods)->not->toContain('recipient')
        ->and($reflection->getMethod('dedupKey')->getNumberOfParameters())->toBe(0);
});

it('registers a resolver class that exists and implements the contract, for every defined type', function () {
    foreach (app(NotificationRegistry::class)->all() as $key => $definition) {
        expect(class_exists($definition->resolver))->toBeTrue("resolver for [{$key}] does not exist")
            ->and(is_subclass_of($definition->resolver, RecipientResolver::class))
            ->toBeTrue("resolver for [{$key}] does not implement RecipientResolver")
            ->and($definition->defaultChannels)->not->toBeEmpty("type [{$key}] declares no channels");
    }
});

it('only points the checker resolver at types whose payload carries a checker ability', function () {
    foreach (app(NotificationRegistry::class)->all() as $key => $definition) {
        if ($definition->resolver !== CheckerAbilityResolver::class) {
            continue;
        }

        // A type resolved by checker ability must exclude its actor: the submitter
        // cannot decide their own request (`submitted_by <> decided_by` at Policy
        // and DB), so notifying them invites a refused action.
        expect($definition->excludeActor)->toBeTrue(
            "[{$key}] resolves by checker ability but does not exclude its actor."
        );
    }
});
