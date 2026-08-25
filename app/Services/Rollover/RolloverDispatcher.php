<?php

namespace App\Services\Rollover;

use App\Jobs\MoveFromTermJob;
use App\Jobs\MoveToNextYearJob;
use App\Models\AcademicSession;
use App\Models\Curriculum;
use App\Models\User;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

/**
 * Turns a plan into a queued batch. The only place `Bus::batch` is written for a rollover.
 *
 * ── WHY THIS IS SEPARATE FROM THE PLANNER, AND SEPARATE FROM THE CALLERS ─────────────────────────
 * Slice 1 kept `Bus::batch` in the callers so that "the planner cannot dispatch" was true by
 * construction — an arch test asserts it, and slice 2's `assertNothingBatched` on the preview
 * endpoint rests on it. That was right, and it stops being enough the moment there are two callers:
 * the command and the controller would each hand-roll the same batch, and the second copy is where
 * the batch name, the `allowFailures()`, or the job arguments quietly diverge.
 *
 * So the split is three ways, not two: PLAN (RolloverPlanner) · DISPATCH (here) · PRESENT (the
 * command's terminal output, the controller's JSON). The planner still cannot dispatch, and now
 * nobody writes a batch twice.
 *
 * ── IT TAKES A PLAN. IT DOES NOT MAKE ONE ────────────────────────────────────────────────────────
 * Deliberate, and the reason is the TOCTOU the UI introduces: preview -> the operator reads ->
 * the operator confirms -> dispatch. The caller is responsible for handing over a plan computed at
 * DISPATCH time, not the one the operator was shown — see RolloverController, which re-plans and
 * re-checks `isRunnable()` before calling here. Taking a plan rather than the inputs keeps that
 * decision at the call site where the freshness question is visible, instead of hiding a re-plan
 * inside a method named "dispatch".
 *
 * ── IT REFUSES A PLAN THAT IS NOT RUNNABLE ───────────────────────────────────────────────────────
 * Belt and braces over the callers' own checks. A dispatcher that queued a blocked plan because a
 * caller forgot to ask would be the single most expensive bug available in this milestone — a
 * whole-school migration past a gate that exists to stop it — so the refusal lives here too, where
 * it cannot be skipped by adding a third caller.
 */
class RolloverDispatcher
{
    public function dispatchEndOfTerm(RolloverPlan $plan, User $operator): Batch
    {
        $this->assertDispatchable($plan, RolloverBatchName::KIND_END_OF_TERM);

        return Bus::batch(
            $plan->curricula->map(fn (Curriculum $curriculum) => new MoveFromTermJob(
                $curriculum, (int) $operator->id, $plan->schoolId
            ))->all()
        )->name($plan->batchName)
            ->allowFailures()
            ->dispatch();
    }

    public function dispatchEndOfYear(RolloverPlan $plan, AcademicSession $target, User $operator): Batch
    {
        $this->assertDispatchable($plan, RolloverBatchName::KIND_END_OF_YEAR);

        // The TARGET is not on the plan, and that is not an oversight: the plan answers "what would
        // move and may it", which is a property of the SOURCE. Where they land is the caller's
        // decision and is validated separately (same school, not the source session).
        return Bus::batch(
            $plan->curricula->map(fn (Curriculum $curriculum) => new MoveToNextYearJob(
                $curriculum, $target, (int) $operator->id, $plan->schoolId
            ))->all()
        )->name($plan->batchName)
            ->allowFailures()
            ->dispatch();
    }

    private function assertDispatchable(RolloverPlan $plan, string $expectedKind): void
    {
        if ($plan->kind !== $expectedKind) {
            throw new \LogicException(
                "Refusing to dispatch a {$plan->kind} plan through the {$expectedKind} path."
            );
        }

        if (! $plan->isRunnable()) {
            throw new \LogicException(
                'Refusing to dispatch a blocked plan: '.implode(', ', $plan->blockedBy).'. '
                .'A caller reached the dispatcher without checking isRunnable().'
            );
        }

        if ($plan->isEmpty()) {
            throw new \LogicException(
                'Refusing to dispatch an empty plan — there is nothing to migrate, and an empty '
                .'batch would report a rollover that did not happen.'
            );
        }
    }
}
