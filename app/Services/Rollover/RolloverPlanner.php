<?php

namespace App\Services\Rollover;

use App\Enums\StudentStatusEnum;
use App\Models\AcademicSession;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\ClassLevelTermParticipation;
use App\Models\Curriculum;
use App\Models\Scopes\SchoolScope;
use App\Models\StudentCurriculum;
use App\Models\Term;
use App\Services\ProgressionGraph;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Given a term or a pair of sessions: what the rollover would migrate, and whether it may run.
 *
 * ── ONE PLAN, TWO CALLERS ────────────────────────────────────────────────────────────────────────
 * `academics:run-end-of-term` / `run-end-of-year` and (slice 2) `RolloverController` both plan
 * through here. Before this class the selection and the gates lived inside the commands, so a UI
 * could only reach them by shelling out to artisan — which B4 forbids — or by re-deriving them,
 * which is how a screen ends up offering a rollover the CLI would refuse.
 *
 * ── IT PLANS; IT DOES NOT DISPATCH ───────────────────────────────────────────────────────────────
 * `Bus::batch` stays in the caller. That is what makes "a preview cannot dispatch" true by
 * CONSTRUCTION rather than by remembering — there is no code path from this class to a queue, so
 * slice 2's `assertNothingBatched` on the preview endpoint is asserting a structural fact rather
 * than an implementation detail that a later edit could quietly reverse.
 *
 * ── THE CYCLE GATE CALLS THE WALK DIRECTLY, AND THAT IS THE WHOLE POINT ──────────────────────────
 * `RunEndOfYear` used to run this gate as `$this->call('academics:validate-progression')` — a
 * command invoking a sibling command, whose result is an EXIT CODE with the ring printed to a
 * console buffer. B4 requires the UI to render the cycle "naming the ring"; an exit code cannot name
 * a ring, and scraping `Artisan::output()` is the same defect as shelling out.
 *
 * `ProgressionGraph::findCycle` already returns the ring as data — `list<string>|null`, level names
 * in walk order with the entry repeated. So nothing needed building: the ring existed and the
 * command was throwing it away. This class calls the walk directly and puts the array in the plan.
 *
 * ── WHAT THIS CLASS IS NOT ───────────────────────────────────────────────────────────────────────
 * `academics:validate-progression` does NOT and must not call this. It validates every school's
 * graph as a standalone config check — no session, no term, no selection — and routing it through a
 * rollover planner would widen the planner to serve a caller that supplies none of its inputs. It
 * stays a thin presenter over ProgressionGraph, which is already one walk with several callers.
 * There are two seams here (the walk, and the plan); collapsing them creates the coupling this
 * separation exists to prevent.
 */
class RolloverPlanner
{
    /**
     * Every non-CCM curriculum active in the closing term.
     *
     * The CCM gate is evaluated over the SAME selection rather than a second query: MoveFromTermJob
     * refuses a CCM source, so dispatching past an active CCM curriculum reports success while
     * silently skipping that cohort — leaving pupils in a term nobody moved them out of.
     */
    public function planEndOfTerm(Term $term): RolloverPlan
    {
        $schoolId = (int) $term->school_id;

        $active = $this->activeCurriculaInTerm($term)->get();

        $ccmBlockers = $active->where('is_ccm', true)->values();
        $curricula = $active->where('is_ccm', false)->values();

        $warnings = [];

        // ── WHERE WOULD THESE ACTUALLY GO? ────────────────────────────────────────────────────────
        // Asked through NextTermSlot, the SAME resolver MoveFromTermJob uses to decide. Before this
        // the planner selected on (school, term, active) alone and never asked — so an end-of-term
        // rollover on the session's LAST term promised a move for every class, queued a job each,
        // every one no-opped, and the batch reported complete success. Preview/commit count-honesty
        // cannot catch that: the plan and the commit agree, and both are wrong the same way.
        $noNextSlot = [];

        foreach ($curricula as $curriculum) {
            $slot = NextTermSlot::for($curriculum, $schoolId);

            if (! $slot->resolved()) {
                $noNextSlot[$this->describe($curriculum)] = $slot->explain();
            }
        }

        $stuck = count($noNextSlot);

        // BLOCK only when NOTHING can move — that is "you picked the wrong operation", and the
        // answer is an end-of-YEAR rollover. A mixed term is legitimate and merely warned: class
        // levels have different final slots, so some finishing while others continue is the normal
        // shape of a school, not an error.
        if ($stuck > 0 && $stuck === $curricula->count()) {
            $warnings[] = "None of the {$stuck} selected class(es) has a next term slot — this is the "
                .'last term for every one of them. Run an end-of-year rollover instead.';
        } elseif ($stuck > 0) {
            $warnings[] = "{$stuck} of the {$curricula->count()} selected class(es) have no next term "
                .'slot and will not move; the rest will.';
        }

        if ($draining = $this->drainingBatchCount($schoolId)) {
            $warnings[] = $this->drainingWarning($draining);
        }

        return new RolloverPlan(
            kind: RolloverBatchName::KIND_END_OF_TERM,
            schoolId: $schoolId,
            batchName: RolloverBatchName::forTerm($schoolId, (int) $term->id),
            curricula: $curricula,
            pupilCount: $this->countNonWithdrawnPupils($curricula),
            // NOT APPLICABLE, said explicitly. The progression graph governs LEVEL advancement,
            // which only end-of-year performs — so this check never runs here. `progressionCycle`
            // alone could not say that: null is also what a CLEAN end-of-year plan carries, and the
            // UI would have had to branch on `kind` to tell "we never looked" from "we looked and
            // it is fine". See RolloverPlan::progressionIsAcyclic.
            progressionCheckRan: false,
            progressionCycle: null,
            ccmBlockers: $ccmBlockers,
            noNextSlot: $noNextSlot,
            warnings: $warnings,
            blockedBy: array_values(array_filter([
                $ccmBlockers->isEmpty() ? null : 'ccm-active',
                // Every selected class stuck — the rollover would move nobody while reporting success.
                ($stuck > 0 && $stuck === $curricula->count()) ? 'no-next-slot' : null,
            ])),
        );
    }

    /**
     * Every non-CCM curriculum sitting in a class level's FINAL participating slot of the closing
     * session.
     *
     * Both gates are evaluated and BOTH are reported — the caller is not told to fix one, re-run,
     * and discover the other. A registrar making two trips through a rollover they cannot start is
     * the experience this replaces.
     */
    public function planEndOfYear(AcademicSession $source, AcademicSession $target): RolloverPlan
    {
        $schoolId = (int) $source->school_id;

        // THE WALK, CALLED DIRECTLY. See the class docblock — this is the array the command used to
        // reduce to an exit code, and the reason the UI could never name the ring.
        $cycle = ProgressionGraph::findCycle($schoolId);

        $warnings = [];
        $selection = $this->selectFinalSlotCurricula($schoolId, $source, $warnings);

        $ccmBlockers = $selection->where('is_ccm', true)->values();
        $curricula = $selection->where('is_ccm', false)->values();

        if ($draining = $this->drainingBatchCount($schoolId)) {
            $warnings[] = $this->drainingWarning($draining);
        }

        $blockedBy = [];

        if ($cycle !== null) {
            $blockedBy[] = 'progression-cycle';
        }

        if ($ccmBlockers->isNotEmpty()) {
            $blockedBy[] = 'ccm-active';
        }

        return new RolloverPlan(
            kind: RolloverBatchName::KIND_END_OF_YEAR,
            schoolId: $schoolId,
            batchName: RolloverBatchName::forSession($schoolId, (int) $source->id),
            curricula: $curricula,
            pupilCount: $this->countNonWithdrawnPupils($curricula),
            // The check ALWAYS runs for end-of-year, whatever it finds.
            progressionCheckRan: true,
            progressionCycle: $cycle,
            ccmBlockers: $ccmBlockers,
            // End-of-year resolves each level's FINAL slot by design, so "no next slot" is not a
            // failure mode there — it is the selection criterion.
            noNextSlot: [],
            warnings: $warnings,
            blockedBy: $blockedBy,
            placement: $this->placementFor($curricula, $schoolId, $target),
        );
    }

    /**
     * Where every pupil in the selection would land, computed through the SAME resolver the job uses.
     *
     * ── THE SKIP ORDER IS THE JOB'S, IN THE JOB'S ORDER ─────────────────────────────────────────
     * withdrawn → repeater → already-promoted → terminal → advance. It is not "roughly the same
     * checks": order is load-bearing, because a withdrawn pupil who is also marked `repeated` is
     * skipped by the job and would be shown as held by any preview that tested repeat first. A
     * preview that disagrees with the job about who moves is the defect this whole design removes,
     * one level down from the placement rules themselves.
     *
     * ── READ-ONLY, AND THAT IS THE SAFETY CLAIM ─────────────────────────────────────────────────
     * `create: false` throughout. A preview that wrote would mint a year's worth of curricula every
     * time a registrar opened the screen — and worse, it would make the destination exist, so the
     * very flag the screen is here to raise would read "configured" from the second look onward.
     *
     * @param  Collection<int, Curriculum>  $curricula
     */
    private function placementFor(Collection $curricula, int $schoolId, AcademicSession $target): RolloverPlacement
    {
        if ($curricula->isEmpty()) {
            return RolloverPlacement::empty();
        }

        // selectFinalSlotCurricula builds its result with collect()->merge(), so what arrives is a
        // SUPPORT collection with no ->load(). Wrapped rather than re-queried by id: re-querying would
        // re-apply the selection's rules in a second place, which is the duplication this class exists
        // to avoid, and it would silently drop any curriculum the caller had already filtered out.
        $curricula = EloquentCollection::make($curricula->all())->load([
            'classLevelArm.classLevel',
            'classLevelArm.arm',
            'classLevelArm.stream',
            'studentCurricula.student',
        ]);

        $advancers = collect();
        $repeaters = collect();
        $unplaceable = collect();
        $graduating = collect();

        foreach ($curricula as $curriculum) {
            $sourceArm = $curriculum->classLevelArm;
            $sourceLevel = $sourceArm?->classLevel;

            if ($sourceArm === null || $sourceLevel === null) {
                continue;
            }

            $resolver = new NextYearPlacementResolver($curriculum, $schoolId, $target);
            $targetLevel = $resolver->targetLevel($sourceLevel);
            $sourceLabel = $this->describe($curriculum);

            // [destinationKey => ['placement' => NextYearPlacement, 'pupils' => [...]]], so two pupils
            // bound for the same destination become one row rather than two.
            $advancing = [];
            $holding = [];
            $stuck = [];
            $leaving = [];

            foreach ($curriculum->studentCurricula as $episode) {
                if ($episode->status === StudentStatusEnum::WITHDRAWN) {
                    continue;
                }

                if ($episode->status === StudentStatusEnum::REPEATED) {
                    $placement = $resolver->forRepeater($sourceArm, $sourceLevel, create: false);

                    // NOT a ternary into a by-reference parameter — PHP cannot pass the result of an
                    // expression by reference, and the fatal only surfaces once a pupil actually
                    // reaches here.
                    if ($placement->resolved()) {
                        $this->bucket($holding, $placement, $episode, $sourceLevel, $sourceArm);
                    } else {
                        $this->bucket($stuck, $placement, $episode, $sourceLevel, $sourceArm);
                    }

                    continue;
                }

                // ALREADY PROMOTED — the job skips these wherever they now sit, so the preview must
                // not promise to move them. This is what stops a re-run's preview double-counting a
                // cohort that has already gone.
                if ($episode->promoted_to_id !== null) {
                    continue;
                }

                // A terminal level advances nobody. Not a failure — it is what a graduating year is —
                // so it is not "unplaceable" either. But it is not INVISIBLE: these pupils are inside
                // pupil_count, so leaving them out of every bucket makes the confirm's headline
                // ("340 pupils across 12 classes") sit above a table totalling fewer, with the whole
                // leaving cohort as the unexplained difference.
                if ($targetLevel === null) {
                    $leaving[] = [
                        'id' => (int) $episode->student_id,
                        'name' => $episode->student->full_name,
                        'admission_number' => $episode->student->admission_number,
                    ];

                    continue;
                }

                $placement = $resolver->forAdvancer($episode, $sourceArm, $targetLevel, create: false);

                if ($placement->resolved()) {
                    $this->bucket($advancing, $placement, $episode, $targetLevel, $placement->arm);
                } else {
                    $this->bucket($stuck, $placement, $episode, $targetLevel, $placement->arm);
                }
            }

            $advancers = $advancers->concat($this->toGroups($advancing, $sourceLabel));
            $repeaters = $repeaters->concat($this->toGroups($holding, $sourceLabel));

            if ($leaving !== []) {
                $graduating->push(['source' => $sourceLabel, 'pupils' => $leaving]);
            }

            foreach ($stuck as $reason => $entry) {
                $unplaceable->push([
                    'source' => $sourceLabel,
                    'reason' => $reason,
                    'explanation' => $entry['explanation'],
                    'pupils' => $entry['pupils'],
                ]);
            }
        }

        return new RolloverPlacement(
            $advancers->values(),
            $repeaters->values(),
            $unplaceable->values(),
            $graduating->values(),
        );
    }

    /**
     * Accumulate one pupil into a bucket, keyed by destination (or by refusal reason when there is no
     * destination to key on).
     *
     * @param  array<string, array{label: string, placement: NextYearPlacement, explanation: string, pupils: list<array<string, mixed>>}>  $bucket
     */
    private function bucket(array &$bucket, NextYearPlacement $placement, StudentCurriculum $episode, ?ClassLevel $level, ?ClassLevelArm $arm): void
    {
        $key = $placement->resolved() ? (string) $placement->destinationKey() : $placement->reason;

        $bucket[$key] ??= [
            'label' => $this->destinationLabel($level, $arm),
            'placement' => $placement,
            'explanation' => $placement->explain(),
            'pupils' => [],
        ];

        // NOT nullsafe: `student_curricula.student_id` carries a composite `(student_id, school_id)`
        // FK (2026_07_19_130000), so an episode without a student cannot exist and the relation is
        // typed non-nullable. A `?->… ?? 'student#N'` fallback here would be dead code pretending to
        // handle a state the schema forbids — Larastan says so, and it is right.
        $student = $episode->student;

        $bucket[$key]['pupils'][] = [
            'id' => (int) $episode->student_id,
            'name' => $student->full_name,
            // Nullable on the COLUMN, unlike the relation — a pupil may genuinely have no admission
            // number yet, and the screen renders the name alone in that case.
            'admission_number' => $student->admission_number,
        ];
    }

    /**
     * @param  array<string, array{label: string, placement: NextYearPlacement, explanation: string, pupils: list<array<string, mixed>>}>  $bucket
     * @return Collection<int, PlacementGroup>
     */
    private function toGroups(array $bucket, string $sourceLabel): Collection
    {
        return collect($bucket)->map(fn (array $entry, string $key) => new PlacementGroup(
            sourceLabel: $sourceLabel,
            destinationLabel: $entry['label'],
            destinationCurriculumId: $entry['placement']->curriculum?->id === null
                ? null
                : (int) $entry['placement']->curriculum->id,
            destinationKey: $key,
            pupils: $entry['pupils'],
            // CARRIED, never re-derived here. The screen's badge, the panel's count and the commit's
            // acknowledgment set all have to name the same destinations, and two computations of
            // "is this destination safe" would drift the way two key-arrays would.
            destinationHasCompulsorySubjects: $entry['placement']->destinationHasCompulsorySubjects,
        ))->values();
    }

    /** "Year 8 B" for a destination, which has a level and an arm but not yet a curriculum to describe. */
    private function destinationLabel(?ClassLevel $level, ?ClassLevelArm $arm): string
    {
        $label = trim(implode(' ', array_filter([
            $level?->name,
            $arm?->arm?->label,
            $arm?->stream?->name,
        ])));

        return $label !== '' ? $label : '—';
    }

    /**
     * @return Builder<Curriculum>
     */
    private function activeCurriculaInTerm(Term $term)
    {
        return Curriculum::withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $term->school_id)
            ->where('term_id', $term->id)
            ->where('status', 'active');
    }

    /**
     * One pass per class level: find its last participating slot, resolve the source session's Term
     * at that order, and take the active curricula for that term across the level's arms.
     *
     * MAX(term_order) of the LEVEL's participation — not the session's last term, and not a count of
     * rows. Those three answers agree only on a contiguous level.
     *
     * @param  list<string>  $warnings  by reference: a level whose final slot has no term in this
     *                                  session is skipped, and the operator must be told which —
     *                                  silently migrating fewer levels than expected is the failure
     *                                  mode here, and it is invisible in a count.
     *
     * @param-out list<string> $warnings
     *
     * @return Collection<int, Curriculum>
     */
    private function selectFinalSlotCurricula(int $schoolId, AcademicSession $source, array &$warnings): Collection
    {
        $selected = collect();

        $levels = ClassLevel::withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $schoolId)
            ->get();

        foreach ($levels as $level) {
            $finalSlot = ClassLevelTermParticipation::withoutGlobalScope(SchoolScope::class)
                ->where('school_id', $schoolId)
                ->where('class_level_id', $level->id)
                ->max('term_order');

            if ($finalSlot === null) {
                continue;
            }

            $term = Term::withoutGlobalScope(SchoolScope::class)
                ->where('academic_session_id', $source->id)
                ->where('order', $finalSlot)
                ->first();

            if ($term === null) {
                $warnings[] = "Class level [{$level->name}] has a final slot of {$finalSlot}, but {$source->name} has no term at that order — skipped.";

                continue;
            }

            $armIds = ClassLevelArm::withoutGlobalScope(SchoolScope::class)
                ->where('class_level_id', $level->id)
                ->pluck('id');

            if ($armIds->isEmpty()) {
                continue;
            }

            $selected = $selected->merge(
                Curriculum::withoutGlobalScope(SchoolScope::class)
                    ->where('school_id', $schoolId)
                    ->where('term_id', $term->id)
                    ->whereIn('class_level_arm_id', $armIds)
                    ->where('status', 'active')
                    ->get()
            );
        }

        return $selected->values();
    }

    /**
     * @param  Collection<int, Curriculum>  $curricula
     */
    /** "Year 8 B" — the operator-facing name of a class in the plan. */
    private function describe(Curriculum $curriculum): string
    {
        $arm = $curriculum->classLevelArm;

        if ($arm === null) {
            return 'curriculum#'.$curriculum->id;
        }

        $label = trim(implode(' ', array_filter([
            $arm->classLevel?->name,
            $arm->arm?->label,
            $arm->stream?->name,
        ])));

        return $label !== '' ? $label : 'curriculum#'.$curriculum->id;
    }

    private function countNonWithdrawnPupils(Collection $curricula): int
    {
        if ($curricula->isEmpty()) {
            return 0;
        }

        return DB::table('student_curricula')
            ->whereIn('curriculum_id', $curricula->pluck('id'))
            ->whereNotIn('status', ['withdrawn'])
            ->count();
    }

    /**
     * A re-run while a previous batch is draining is HARMLESS — the jobs' own guards no-op the second
     * pass once sources have closed — but it is wasted work, and the plan the operator is reading may
     * not reflect what those jobs are about to change. A warning, never a block: re-queuing a batch
     * that failed partway is a legitimate recovery, and refusing would block it.
     */
    private function drainingBatchCount(int $schoolId): int
    {
        return DB::table('job_batches')
            ->where('name', 'like', RolloverBatchName::likeForSchool($schoolId))
            ->whereNull('finished_at')
            ->whereNull('cancelled_at')
            ->where('pending_jobs', '>', 0)
            ->count();
    }

    private function drainingWarning(int $draining): string
    {
        return "{$draining} rollover batch(es) for this school are still draining. Re-queuing is safe "
            .'(the jobs no-op on work already done) but wasteful, and the plan above may not '
            .'reflect what those jobs are about to change.';
    }
}
