<?php

use App\Finance\Approval\NotifiesApprovalCheckers;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Every approval family must tell its checkers — by construction, not by inspection.
 *
 * ⚠️ THE GAP THIS CLOSES IS "ONE WIRED, THREE FORGOTTEN". `ApprovalRequested` was built
 * to serve all four families through the ApprovalAbility convention, and exactly one
 * (void requests) dispatched. Flipping the notification flag would have notified for
 * voids and silently not for credit notes — which a checker reads as "notifications are
 * broken", not "not built yet".
 *
 * ⚠️ AND THE DISPATCH MOVED FROM THE CONTROLLER TO THE ACTION. It lived in
 * VoidRequestController, so any submit not routed through that controller notified
 * nobody — DriveFinanceStates calls the actions directly, and so would any job. A
 * request moved to pending-approval in silence. Four controller dispatches would have
 * been four copies of that same hole.
 *
 * A CARDINALITY PIN, not a checklist. Enumerating the actions from the filesystem means
 * a FIFTH family added later fails CI the moment it lands without the trait — the
 * enumeration does not decay the way a hand-listed set does.
 */
it('makes every Submit action notify its approval checkers', function () {
    $actionDir = dirname(__DIR__, 3).'/app/Finance/Actions';

    $submitActions = collect(scandir($actionDir))
        ->filter(fn (string $f) => str_starts_with($f, 'Submit') && str_ends_with($f, '.php'))
        ->values();

    // Not vacuous: an empty glob would satisfy every assertion below.
    expect($submitActions)->not->toBeEmpty();

    $missing = $submitActions->reject(function (string $file) use ($actionDir) {
        $source = (string) file_get_contents($actionDir.'/'.$file);

        return str_contains($source, 'NotifiesApprovalCheckers')
            && str_contains($source, 'notifyApprovalCheckers(');
    });

    expect($missing->all())->toBe([]);
});

/**
 * The dispatch must NOT be at a controller — that is the shape that let the console
 * path submit silently, and re-adding it anywhere would reintroduce the bypass.
 */
it('keeps approval dispatch out of the Finance controllers', function () {
    $controllerDir = dirname(__DIR__, 3).'/app/Finance/Http/Controllers';

    $offenders = collect(scandir($controllerDir))
        ->filter(fn (string $f) => str_ends_with($f, '.php'))
        ->filter(function (string $file) use ($controllerDir) {
            $source = (string) file_get_contents($controllerDir.'/'.$file);

            return str_contains($source, 'ApprovalRequested');
        });

    expect($offenders->all())->toBe([]);
});

/**
 * The trait is the ONE definition. Four inlined dispatches would agree today and drift
 * the first time one of them is edited — the same inlined-copy failure the synthetic
 * check taught, applied before it happens rather than after.
 */
it('has exactly one place that constructs an ApprovalRequested notification', function () {
    $appDir = dirname(__DIR__, 3).'/app';
    $constructors = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        foreach (file($file->getPathname(), FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^\s*(\/\/|\*|\/\*)/', $line)) {
                continue;
            }

            if (str_contains($line, 'new ApprovalRequested(')) {
                $constructors[] = basename($file->getPathname());
            }
        }
    }

    expect($constructors)->toBe(['NotifiesApprovalCheckers.php']);
});

it('exposes the notify helper to the actions that use it', function () {
    // The trait's method is `protected` — usable by the four actions, not callable from
    // a controller, which is the placement the first two tests police.
    $method = new ReflectionMethod(
        new class
        {
            use NotifiesApprovalCheckers;
        },
        'notifyApprovalCheckers',
    );

    expect($method->isProtected())->toBeTrue();
});
