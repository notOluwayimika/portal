<?php

namespace App\Console\Commands;

use App\Models\ClassLevel;
use App\Models\ClassLevelExamType;
use App\Models\ClassLevelTermParticipation;
use App\Models\Curriculum;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\Term;
use App\Models\User;
use App\Support\ActiveSchool;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\CauserResolver;

/**
 * Draft the progression config for a school from the structure it already has.
 *
 * WHY THIS EXISTS. The progression config screens EDIT this config; nothing populates it. On the day
 * they ship, every live school has empty class_level_term_participation, empty exam-type sets and
 * NULL progression pointers — so both migration jobs no-op and nothing rolls over until a human
 * hand-enters all of it. Across six to ten levels per school that is a long, error-prone pass, and
 * `next_class_level_id` is exactly where one wrong link silently strands a cohort at rollover.
 *
 * This turns day one from blank-slate data entry into REVIEW AND ADJUST.
 *
 * ── IT IS A DRAFT, NOT AN AUTHORITY ───────────────────────────────────────────────────────────────
 * Everything here is a proposal the operator ratifies through the progression screens. That is what
 * licenses the `order + 1` inference for next_class_level_id, which the RUNTIME deliberately refuses
 * to do (2026_08_20_110000: `order` is a display field with no uniqueness or contiguity guarantee,
 * so arithmetic silently jumps a deleted level). As a seed it is fine — a human reads it and the
 * cycle checker and the rollover's validate-progression gate are both downstream of them. As a
 * runtime rule it would be wrong. The distinction is the whole reason this is a command and not a
 * fallback inside the job.
 *
 * ── FILL BLANKS ONLY, NEVER CLOBBER ───────────────────────────────────────────────────────────────
 * Participation rows go in through firstOrCreate; scalar columns are written ONLY where currently
 * NULL; exam-type sets are MERGED (missing added, nothing removed). So this is safe to re-run, and —
 * more importantly — safe to run AFTER an operator has hand-corrected something. The seed never
 * overwrites human judgement, which is what lets it be run again when a school adds a class level
 * without anyone having to remember what they changed by hand.
 *
 * ── WHAT IT DOES NOT SEED, DELIBERATELY ───────────────────────────────────────────────────────────
 * `arm_distribution_strategy` — left to the column default (round_robin). Writing it would turn a
 * default into an assertion nobody made.
 * Arm progression maps — label matching is the intended default and a map is an operator OVERRIDE.
 * Seeding overrides would invent decisions and, worse, a map pointing at the wrong level is refused
 * silently by the end-of-year job.
 */
class SeedProgressionDefaults extends Command
{
    protected $signature = 'academics:seed-progression-defaults
        {school : id or uuid of the school}
        {--user= : id of the operator the config is attributed to (required with --commit)}
        {--commit : Write the draft (default is a dry run)}';

    protected $description = 'Draft a school\'s progression config from its existing class structure (dry run by default).';

    /** @var list<string> proposals the operator must rule on rather than accept */
    private array $review = [];

    public function handle(): int
    {
        $school = $this->resolveSchool();

        if ($school === null) {
            return self::FAILURE;
        }

        $operator = null;

        if ($this->option('commit')) {
            $operator = $this->resolveOperator();

            if ($operator === null) {
                return self::FAILURE;
            }
        }

        $levels = ClassLevel::withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $school->id)
            ->orderBy('order')
            ->get();

        if ($levels->isEmpty()) {
            $this->warn("School {$school->id} has no class levels — nothing to draft.");

            return self::SUCCESS;
        }

        $this->line("School {$school->id} ({$school->name}) — {$levels->count()} class level(s)");
        $this->newLine();

        $plan = $levels->map(fn (ClassLevel $level) => $this->draftFor($level, $levels));

        $this->renderPlan($plan);

        if (! $this->option('commit')) {
            $this->newLine();
            $this->warn('DRY RUN — nothing written. Re-run with --commit to apply.');

            return self::SUCCESS;
        }

        $this->apply($plan, $school, $operator);

        return self::SUCCESS;
    }

    /**
     * Infer one level's draft. Reads only; every write happens later in apply().
     *
     * @param  Collection<int, ClassLevel>  $levels
     * @return array<string, mixed>
     */
    private function draftFor(ClassLevel $level, Collection $levels): array
    {
        $curricula = $this->curriculaFor($level);

        return [
            'level' => $level,
            'slots' => $this->inferSlots($curricula),
            'exam_type_ids' => $this->inferExamTypes($curricula),
            'default_exam_type_id' => $this->inferDefaultExamType($level, $curricula),
            'next_class_level_id' => $this->inferNextLevel($level, $levels),
        ];
    }

    /**
     * @return Collection<int, Curriculum>
     */
    private function curriculaFor(ClassLevel $level): Collection
    {
        $armIds = DB::table('class_level_arms')->where('class_level_id', $level->id)->pluck('id');

        if ($armIds->isEmpty()) {
            return collect();
        }

        return Curriculum::withoutGlobalScope(SchoolScope::class)
            ->whereIn('class_level_arm_id', $armIds)
            ->get();
    }

    /**
     * The term ORDERS this level's curricula actually occupy — across every session, not one.
     *
     * A union is the right draft: a level that ran slots 1-2 one year and 1-2-3 the next should be
     * proposed as running all three, and the operator prunes. The opposite error (proposing too few)
     * is the damaging one — a missing participation row makes the end-of-term job no-op for that
     * level with no error anywhere.
     *
     * @param  Collection<int, Curriculum>  $curricula
     * @return list<int>
     */
    private function inferSlots(Collection $curricula): array
    {
        $termIds = $curricula->pluck('term_id')->filter()->unique();

        if ($termIds->isEmpty()) {
            return [];
        }

        return Term::withoutGlobalScope(SchoolScope::class)
            ->whereIn('id', $termIds)
            ->pluck('order')
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Curriculum>  $curricula
     * @return list<int>
     */
    private function inferExamTypes(Collection $curricula): array
    {
        return $curricula->pluck('exam_type_id')->filter()->unique()->sort()->values()->all();
    }

    /**
     * The default is a FALLBACK the operator should choose, so it is proposed only when there is
     * nothing to choose between. A level running two exam types (Year 10 and Year 11 in school 1
     * today, BSS and WAEC) gets NULL and a review line — picking one would be inventing a decision
     * about which certificate a pupil sits.
     *
     * @param  Collection<int, Curriculum>  $curricula
     */
    private function inferDefaultExamType(ClassLevel $level, Collection $curricula): ?int
    {
        if ($level->default_exam_type_id !== null) {
            return null; // already set — fill-blanks-only
        }

        $examTypes = $this->inferExamTypes($curricula);

        if (count($examTypes) === 1) {
            return $examTypes[0];
        }

        if (count($examTypes) > 1) {
            $this->review[] = "{$level->name}: runs ".count($examTypes)
                .' exam types — default_exam_type_id left NULL for you to choose.';
        }

        return null;
    }

    /**
     * THE HIGHEST-RISK FIELD, SO THE STRICTEST RULE. Propose `order + 1` only when EXACTLY ONE level
     * sits at that order and the column is currently NULL. Ambiguous, absent or already-set cases are
     * left alone and reported.
     *
     * @param  Collection<int, ClassLevel>  $levels
     */
    private function inferNextLevel(ClassLevel $level, Collection $levels): ?int
    {
        if ($level->next_class_level_id !== null) {
            return null; // already set — fill-blanks-only
        }

        $candidates = $levels->where('order', $level->order + 1);

        if ($candidates->count() === 1) {
            return (int) $candidates->first()->id;
        }

        if ($candidates->isEmpty()) {
            $this->review[] = "{$level->name}: no level at order ".($level->order + 1)
                .' — left NULL (terminal / graduating year?). Confirm this is the last year.';

            return null;
        }

        $names = $candidates->pluck('name')->implode(', ');
        $this->review[] = "{$level->name}: {$candidates->count()} levels share order "
            .($level->order + 1)." ({$names}) — left NULL, pick one.";

        return null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $plan
     */
    private function renderPlan(Collection $plan): void
    {
        $this->table(
            ['Class level', 'Term slots', 'Exam types', 'Default exam type', 'Next level'],
            $plan->map(function (array $row) {
                $level = $row['level'];

                return [
                    $level->name,
                    $row['slots'] === [] ? '— none inferred' : implode(', ', $row['slots']),
                    count($row['exam_type_ids']) ?: '—',
                    $this->describeProposal($level->default_exam_type_id, $row['default_exam_type_id'], fn ($id) => $this->examTypeName($id)),
                    $this->describeProposal($level->next_class_level_id, $row['next_class_level_id'], fn ($id) => $this->levelName($id)),
                ];
            })->all()
        );

        $emptySlots = $plan->filter(fn (array $row) => $row['slots'] === []);

        if ($emptySlots->isNotEmpty()) {
            $this->newLine();
            $this->warn(
                'NO TERM SLOTS INFERRED for: '.$emptySlots->map(fn ($r) => $r['level']->name)->implode(', ')
                .'. These levels have no curricula to infer from, so both migration jobs will no-op for '
                .'them until participation is entered by hand.'
            );
        }

        if ($this->review !== []) {
            $this->newLine();
            $this->error('REVIEW THESE — a wrong progression link strands a cohort at rollover');
            foreach ($this->review as $line) {
                $this->line('  • '.$line);
            }
        }
    }

    private function describeProposal(?int $existing, ?int $proposed, callable $name): string
    {
        if ($existing !== null) {
            return 'kept: '.$name($existing);
        }

        return $proposed === null ? '— (left NULL)' : 'propose: '.$name($proposed);
    }

    private function examTypeName(int $id): string
    {
        return (string) DB::table('exam_types')->where('id', $id)->value('name');
    }

    private function levelName(int $id): string
    {
        return (string) DB::table('class_levels')->where('id', $id)->value('name');
    }

    /**
     * FILL BLANKS ONLY — see the class docblock. One transaction, so a failure part-way leaves the
     * school's config as it was rather than half-drafted.
     *
     * @param  Collection<int, array<string, mixed>>  $plan
     */
    private function apply(Collection $plan, School $school, User $operator): void
    {
        app(CauserResolver::class)->setCauser($operator);

        try {
            $counts = ActiveSchool::runFor((int) $school->id, function () use ($plan, $school) {
                $participation = 0;
                $examTypes = 0;
                $columns = 0;

                DB::transaction(function () use ($plan, $school, &$participation, &$examTypes, &$columns) {
                    foreach ($plan as $row) {
                        /** @var ClassLevel $level */
                        $level = $row['level'];

                        foreach ($row['slots'] as $slot) {
                            $created = ClassLevelTermParticipation::firstOrCreate([
                                'school_id' => $school->id,
                                'class_level_id' => $level->id,
                                'term_order' => $slot,
                            ], [
                                // v1: participation is seeded NON-CCM. CCM slots are an explicit
                                // decision and the toggle is not in the UI yet.
                                'is_ccm' => false,
                            ]);

                            $participation += $created->wasRecentlyCreated ? 1 : 0;
                        }

                        foreach ($row['exam_type_ids'] as $examTypeId) {
                            $created = ClassLevelExamType::firstOrCreate([
                                'school_id' => $school->id,
                                'class_level_id' => $level->id,
                                'exam_type_id' => $examTypeId,
                            ]);

                            $examTypes += $created->wasRecentlyCreated ? 1 : 0;
                        }

                        $updates = array_filter([
                            'next_class_level_id' => $row['next_class_level_id'],
                            'default_exam_type_id' => $row['default_exam_type_id'],
                        ], fn ($value) => $value !== null);

                        if ($updates !== []) {
                            $level->update($updates);
                            $columns++;
                        }
                    }
                });

                return compact('participation', 'examTypes', 'columns');
            });
        } finally {
            app(CauserResolver::class)->setCauser(null);
        }

        $this->newLine();
        $this->info(
            "Wrote {$counts['participation']} participation row(s), {$counts['examTypes']} exam-type row(s), "
            ."and filled columns on {$counts['columns']} level(s)."
        );
        $this->line('Nothing already set was changed. Review the draft in the progression screens before any rollover.');
    }

    private function resolveSchool(): ?School
    {
        $key = (string) $this->argument('school');

        $school = School::query()
            ->when(ctype_digit($key), fn ($q) => $q->where('id', (int) $key), fn ($q) => $q->where('uuid', $key))
            ->first();

        if ($school === null) {
            $this->error("No school matching [{$key}].");
        }

        return $school;
    }

    private function resolveOperator(): ?User
    {
        $userId = $this->option('user');

        if (! $userId) {
            $this->error('--user is required with --commit: the config rows attribute their audit trail to an operator.');

            return null;
        }

        $user = User::find($userId);

        if ($user === null) {
            $this->error("No user with id {$userId}.");
        }

        // No school-membership check: `users.school_id` is the school-id-fallback the boundary lint
        // refuses and ADR 0042 is retiring, and the causer here is attribution only — the school comes
        // from the argument, not from the operator.
        return $user;
    }
}
