<?php

namespace App\Jobs;

use App\Enums\StudentStatusEnum;
use App\Enums\StudentSubjectStatus;
use App\Exceptions\CcmFoldRefused;
use App\Jobs\Middleware\SchoolAware;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\MarkingComponent;
use App\Models\MarkingScheme;
use App\Models\Scopes\SchoolScope;
use App\Models\Score;
use App\Models\StudentCurriculum;
use App\Models\StudentSubject;
use App\Models\User;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Activitylog\CauserResolver;

class MoveFromCcmJob implements ShouldQueue
{
    // Batchable is REQUIRED, not decorative: the inline fold control dispatches N of these through
    // Bus::batch, and PendingBatch refuses any job without the trait. The rollover jobs shipped
    // without it and `--commit` had never once worked — every test fakes the bus, and
    // BusFake::batch() returns a PendingBatchFake that SKIPS ensureJobIsBatchable() entirely, so a
    // faked suite is structurally incapable of noticing. Same trap, caught before it bit this time.
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(
        public readonly Curriculum $curriculum,
        public readonly int $causedByUserId,
        public readonly int $schoolId,
    ) {}

    public function middleware(): array
    {
        return [new SchoolAware];
    }

    public function handle(): void
    {
        if ($this->curriculum->is_ccm !== true) {
            Log::warning('MoveFromCcmJob: curriculum is not CCM, aborting', [
                'curriculum_id' => $this->curriculum->id,
            ]);

            return;
        }

        if ($this->schoolId !== (int) $this->curriculum->school_id) {
            Log::warning('MoveFromCcmJob: declared schoolId does not match the curriculum school, aborting', [
                'curriculum_id' => $this->curriculum->id,
            ]);

            return;
        }

        // Audit attribution only — never auth()->setUser() (§5.6). School
        // context comes solely from the declared schoolId via SchoolAware.
        $causer = User::find($this->causedByUserId);
        if ($causer) {
            app(CauserResolver::class)->setCauser($causer);
        }

        try {
            DB::transaction(function () {
                $curriculum = $this->curriculum;

                $targetCurriculum = $this->resolveTargetCurriculum($curriculum);
                $curriculum->update(['status' => 'closed']);
                $subjectMap = $this->cloneCurriculumSubjects($curriculum, $targetCurriculum);

                $this->migrateStudents($curriculum, $targetCurriculum, $subjectMap);
            });
        } finally {
            app(CauserResolver::class)->setCauser(null);
        }
    }

    /**
     * Find (or create) the non-CCM curriculum that mirrors $curriculum.
     * Matching on the same unique key as the curricula table guarantees
     * a re-run reuses the previously created target.
     */
    private function resolveTargetCurriculum(Curriculum $curriculum): Curriculum
    {
        return Curriculum::withoutGlobalScope(SchoolScope::class)->firstOrCreate(
            [
                'school_id' => $curriculum->school_id,
                'term_id' => $curriculum->term_id,
                'class_level_arm_id' => $curriculum->class_level_arm_id,
                'exam_type_id' => $curriculum->exam_type_id,
                'is_ccm' => false,
            ],
            [
                'min_subjects' => $curriculum->min_subjects,
                'status' => $curriculum->status,
            ]
        );
    }

    /**
     * Clone every curriculum subject onto the target curriculum, seeding
     * marking components, result status and teacher assignments.
     *
     * @return array<int, CurriculumSubject> old curriculum_subject_id => new CurriculumSubject
     */
    private function cloneCurriculumSubjects(Curriculum $curriculum, Curriculum $targetCurriculum): array
    {
        $subjectMap = [];

        foreach ($curriculum->curriculumSubjects as $oldSubject) {
            $newSubject = CurriculumSubject::firstOrCreate(
                [
                    'curriculum_id' => $targetCurriculum->id,
                    'subject_id' => $oldSubject->subject_id,
                ],
                [
                    'is_compulsory' => $oldSubject->is_compulsory,
                    'display_order' => $oldSubject->display_order,
                    'active' => $oldSubject->active,
                    'archived_at' => $oldSubject->archived_at,
                    'archived_by_user_id' => $oldSubject->archived_by_user_id,
                ]
            );

            if ($newSubject->wasRecentlyCreated) {
                $this->attachMarkingComponents($newSubject, $targetCurriculum);
                $this->createResultStatus($newSubject);
            }

            $this->migrateTeacherAssignments($oldSubject, $newSubject);

            $componentMap = $this->mapOverlappingMarkingComponents($oldSubject, $newSubject);
            $this->migrateScores($oldSubject, $newSubject, $componentMap);

            $subjectMap[$oldSubject->id] = $newSubject;
        }

        return $subjectMap;
    }

    /**
     * Seed the new curriculum subject from the school's non-CCM global
     * marking component templates, so it never inherits CCM weights.
     */
    private function attachMarkingComponents(CurriculumSubject $newSubject, Curriculum $targetCurriculum): void
    {
        $scheme = MarkingScheme::query()
            ->active()
            ->where('school_id', $targetCurriculum->school_id)
            ->where('is_ccm', $targetCurriculum->is_ccm)
            ->latest('version')
            ->first();

        if ($scheme) {
            $targetCurriculum->update(['marking_scheme_id' => $scheme->id]);

            return;
        }

        $markingComponents = MarkingComponent::global()
            ->where('school_id', $targetCurriculum->school_id)
            ->where('is_ccm', $targetCurriculum->is_ccm)
            ->get();

        foreach ($markingComponents as $component) {
            $newSubject->markingComponents()->create([
                'name' => $component->name,
                'weight' => $component->weight,
                'school_id' => $targetCurriculum->school_id,
                'is_ccm' => $targetCurriculum->is_ccm,
            ]);
        }
    }

    private function createResultStatus(CurriculumSubject $newSubject): void
    {
        $newSubject->resultStatus()->firstOrCreate([], [
            'status' => 'draft',
            'rejection_reason' => null,
            'updated_by' => $this->causedByUserId,
        ]);
    }

    private function migrateTeacherAssignments(CurriculumSubject $oldSubject, CurriculumSubject $newSubject): void
    {
        foreach ($oldSubject->teacherAssignments as $assignment) {
            $newSubject->teacherAssignments()->firstOrCreate([
                'teacher_id' => $assignment->teacher_id,
            ]);
        }
    }

    /**
     * Match each old (CCM) marking component to its non-CCM counterpart on
     * the new subject by normalized name, e.g. "Continuous Assessment 1" ->
     * "Continuous Assessment 1".
     *
     * @return Collection<int, array{old: MarkingComponent, new: MarkingComponent}> keyed by old marking_component_id
     */
    private function mapOverlappingMarkingComponents(CurriculumSubject $oldSubject, CurriculumSubject $newSubject): Collection
    {
        $newByName = $newSubject->effectiveMarkingComponents()
            ->keyBy(fn (MarkingComponent $component) => Str::lower(trim($component->name)));

        $map = collect();
        $dropped = [];

        foreach ($oldSubject->effectiveMarkingComponents() as $oldComponent) {
            $newComponent = $newByName->get(Str::lower(trim($oldComponent->name)));

            if ($newComponent !== null) {
                $map[$oldComponent->id] = ['old' => $oldComponent, 'new' => $newComponent];

                continue;
            }

            // ── AN UNMATCHED COMPONENT IS ONLY A PROBLEM IF IT CARRIES MARKS ────────────────────
            // A CCM component with no non-CCM counterpart and NO scores is ordinary: the two schemes
            // simply differ. One with scores is data about to be destroyed.
            $scored = Score::where('curriculum_subject_id', $oldSubject->id)
                ->where('marking_component_id', $oldComponent->id)
                ->count();

            if ($scored > 0) {
                $dropped[] = ['name' => $oldComponent->name, 'scores' => $scored];
            }
        }

        // ── REFUSE RATHER THAN DROP ────────────────────────────────────────────────────────────
        // This match is by NORMALISED NAME, so a CCM component whose name has no counterpart was
        // silently skipped — and migrateScores only ever queries the components that DID match, so
        // those marks were never even read. The pupil still promoted, the episode still linked, the
        // job still reported success: a silent drop is indistinguishable from a clean fold at every
        // level above this line, which is exactly why the check has to live here at the miss site
        // rather than in a surface trying to detect it afterwards.
        //
        // MEASURED BEFORE BUILDING (2026-08-26): across all 17 folded CCM curricula — 310 subjects,
        // 11,828 scored component-rows — ZERO were dropped. Not because the matcher is safe, but
        // because school#1 has no marking schemes at all, so every fold ran the legacy
        // subject-local path where cloneCurriculumSubjects had copied the components and the names
        // matched BY CONSTRUCTION. The matcher was handed pre-matched inputs, never tested.
        //
        // That changes when CCM arrival is configured rather than hand-made: the target scheme is
        // resolved by (school, is_ccm, active, latest version), so the CCM and non-CCM schemes
        // become two independently-editable objects. school#2 already carries the asymmetry — its
        // CCM scheme has one component where the non-CCM has three — currently in the safe
        // direction (CCM is a subset). One component the other way and every fold loses marks.
        if ($dropped !== []) {
            throw new CcmFoldRefused(
                // ── THE OPERATOR'S VOCABULARY FIRST, THE IDS AFTER ─────────────────────────────
                // This said `curriculum#4` and `subject#2` — six lines below a gate that says
                // "Year 9 A". The whole argument for surfacing this reason is that the operator gets
                // AN ACTION THEY CAN TAKE, and there is no screen where anyone looks up a curriculum
                // by integer id, so the remedy was correct and unactionable in the same breath. The
                // component name was already human, which is what made the ids beside it read as a
                // convention rather than an oversight.
                //
                // The ids are KEPT, in a trailing parenthetical: whoever reads failed_jobs still
                // needs a handle. What changed is which vocabulary LEADS.
                'Refusing to fold '.$this->describeCurriculum().': '
                .count($dropped).' scored marking component(s) on '.$this->describeSubject($oldSubject)
                .' have no counterpart on the non-CCM side and their marks would be lost — '
                .collect($dropped)->map(fn (array $d) => "\"{$d['name']}\" ({$d['scores']} score(s))")->implode(', ')
                .'. Add matching component(s) to the non-CCM marking scheme, then fold again.'
                .' (curriculum#'.$oldSubject->curriculum_id.', subject#'.$oldSubject->subject_id.')'
            );
        }

        return $map;
    }

    /**
     * The class as an operator names it, falling back to the id only when there is nothing to name.
     *
     * Uses `$this->curriculum` rather than re-reading `$oldSubject->curriculum`: they are the same
     * row — cloneCurriculumSubjects iterates `$curriculum->curriculumSubjects` — and the job already
     * holds it, so the label costs the arm relations and nothing else. It is built only on the
     * refusal path, once per refusal.
     */
    private function describeCurriculum(): string
    {
        return $this->curriculum->operatorLabel() ?? 'curriculum#'.$this->curriculum->id;
    }

    /**
     * The subject by NAME. This is the sharper half of the two ids: a reader can guess
     * `curriculum#4` is the class they just clicked Fold on, but nothing on the screen tells them
     * which SUBJECT — and the remedy is per-subject.
     */
    private function describeSubject(CurriculumSubject $subject): string
    {
        $name = $subject->subject?->name;

        return $name !== null && trim($name) !== '' ? $name : 'subject#'.$subject->subject_id;
    }

    /**
     * Copy scores for marking components that exist on both the old (CCM)
     * and new (non-CCM) subject, so marks already entered (e.g. CA1, Half
     * Term Exam) carry over instead of being lost. The score is rescaled by
     * the components' weight ratio, e.g. a 25/50 score on a 0.5-weighted
     * component becomes 5/10 on a 0.1-weighted component.
     *
     * @param  Collection<int, array{old: MarkingComponent, new: MarkingComponent}>  $componentMap  old marking_component_id => component pair
     */
    private function migrateScores(CurriculumSubject $oldSubject, CurriculumSubject $newSubject, Collection $componentMap): void
    {
        if ($componentMap->isEmpty()) {
            return;
        }

        $oldScores = Score::where('curriculum_subject_id', $oldSubject->id)
            ->whereIn('marking_component_id', $componentMap->keys()->all())
            ->get();

        foreach ($oldScores as $oldScore) {
            ['old' => $oldComponent, 'new' => $newComponent] = $componentMap[$oldScore->marking_component_id];

            $oldWeight = (float) $oldComponent->weight;

            if ($oldWeight <= 0) {
                continue;
            }

            // The scores table stores one decimal place (decimal(4,1)), so
            // round to match what will actually be persisted.
            $convertedScore = round((float) $oldScore->score * ((float) $newComponent->weight / $oldWeight), 1);

            Score::firstOrCreate(
                [
                    'student_id' => $oldScore->student_id,
                    'curriculum_subject_id' => $newSubject->id,
                    'marking_component_id' => $newComponent->id,
                ],
                [
                    'curriculum_subject_id' => $newSubject->id,
                    'score' => $convertedScore,
                    'created_by' => $this->causedByUserId,
                ]
            );
        }
    }

    /**
     * @param  array<int, CurriculumSubject>  $subjectMap  old curriculum_subject_id => new CurriculumSubject
     */
    private function migrateStudents(Curriculum $curriculum, Curriculum $targetCurriculum, array $subjectMap): void
    {
        foreach ($curriculum->studentCurricula as $oldStudentCurriculum) {

            $newStudentCurriculum = StudentCurriculum::firstOrCreate(
                [
                    'student_id' => $oldStudentCurriculum->student_id,
                    'curriculum_id' => $targetCurriculum->id,
                ],
                [
                    // The new row is a fresh enrollment in the target curriculum. NARROWED fix (S1
                    // promotion-link closure): never inherit 'promoted' — a promoted new row would carry no
                    // link and never will (the source of the pre-closure promoted-with-NULL rows), and it is
                    // now forbidden by the student_curricula_promoted_requires_link CHECK. An old 'promoted'
                    // becomes 'active' (the enrollment the student now holds); every other status is inherited
                    // unchanged — deliberately narrowed, because whether a withdrawn/repeated student should
                    // be CCM-migrated at all is a separate CCM-workflow question, not this closure's to answer.
                    'status' => $oldStudentCurriculum->status === StudentStatusEnum::PROMOTED
                        ? StudentStatusEnum::ACTIVE
                        : $oldStudentCurriculum->status,
                ]
            );

            foreach ($oldStudentCurriculum->activeSubjects as $oldStudentSubject) {
                $newCurriculumSubject = $subjectMap[$oldStudentSubject->curriculum_subject_id] ?? null;

                if (! $newCurriculumSubject) {
                    continue;
                }

                StudentSubject::firstOrCreate(
                    [
                        'student_curriculum_id' => $newStudentCurriculum->id,
                        'curriculum_subject_id' => $newCurriculumSubject->id,
                    ],
                    [
                        'status' => StudentSubjectStatus::Active,
                    ]
                );
            }
            // Record the promotion LINK, not just the status (S1 commit 5). This is the third legitimate
            // writer of promoted_to_id; it had $newStudentCurriculum in hand and was setting status='promoted'
            // without it, manufacturing a promoted-without-link row. The new episode is the same student
            // (student_id copied at :278) in the same school (the :56 guard aborts otherwise, under SchoolAware),
            // so the composite (promoted_to_id, student_id, school_id) FK accepts it.
            $oldStudentCurriculum->update([
                'status' => 'promoted',
                'promoted_to_id' => $newStudentCurriculum->id,
            ]);
        }
    }
}
