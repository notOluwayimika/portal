<?php

use App\Enums\Permission;
use App\Finance\Approval\ApprovalRequirement;
use App\Support\ApprovalAbility;
use App\Support\Money;

/**
 * The maker-checker seam (ADR 0051). A pure decision object with no DB — proven standalone before any
 * finance_approval_rules table exists. The whole point of the class today is a GUARANTEE: it fails closed.
 * These tests pin that guarantee so a future edit that wires the table cannot accidentally flip the default.
 *
 * F-4 (fail-closed) and F-5 (the straight-through arm is dead for every live caller) live here; the lint
 * (approval-seam-missing / approval-seam-count) proves the Submit actions actually CALL the seam.
 */

// ── F-4: FAIL CLOSED — every path returns required=true, ruleId=null ──────────────────────────────

it('requires a checker for an ability that matches no pair at all', function () {
    // The strongest form of the guarantee: even a garbage ability string — one no maker-checker pair
    // derives — still requires approval. An absent rule means "required", never the reverse.
    $r = ApprovalRequirement::for('nonsense.ability.that.matches.nothing');

    expect($r->required)->toBeTrue()
        ->and($r->ruleId)->toBeNull();
});

it('requires a checker regardless of amount — null, zero, or very large', function () {
    foreach ([null, Money::fromKobo(0), Money::fromKobo(1), Money::fromKobo(5_000_000_00)] as $amount) {
        $r = ApprovalRequirement::for('finance.credit-note.submit', $amount);

        expect($r->required)->toBeTrue()
            ->and($r->ruleId)->toBeNull();
    }
});

// ── F-5: the straight-through (throw) arm is DEAD for every real call site ─────────────────────────

it('requires a checker for every live maker ability (the throw arm is unreachable today)', function () {
    // Each Submit action branches `if (! ApprovalRequirement::for(<maker>, ...)->required) throw`. Because
    // the seam returns required=true for each real maker ability, that throw is provably dead for every
    // live caller — the marker arm, not a live code path — until finance_approval_rules lands.
    //
    // The list is DERIVED from the enum, not typed out: it used to be four names, 4c made it five, and a
    // hand-list is a thing that silently stops covering the newest instance — the exact drift
    // approval-seam-count exists to catch one level up. The derivation is the same predicate that lint
    // uses (a finance ability whose terminal segment is `submit`), so the two cannot disagree.
    $makers = array_values(array_filter(
        Permission::values(),
        fn (string $a) => str_starts_with($a, 'finance.') && ApprovalAbility::terminalSegment($a) === 'submit',
    ));

    // Not vacuous: an empty list would satisfy the loop below.
    expect(count($makers))->toBe(count(glob(base_path('app/Finance/Actions/Submit*.php')) ?: []))
        ->and($makers)->not->toBeEmpty();

    foreach ($makers as $maker) {
        expect(ApprovalRequirement::for($maker)->required)->toBeTrue();
    }
});

it('is immutable — a readonly value object', function () {
    $r = ApprovalRequirement::for('finance.credit-note.submit');

    expect((new ReflectionClass($r))->isReadOnly())->toBeTrue();
});
