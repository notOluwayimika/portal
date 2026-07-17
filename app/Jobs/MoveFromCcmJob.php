<?php

namespace App\Jobs;

use App\Enums\StudentSubjectStatus;
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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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

        return $oldSubject->effectiveMarkingComponents()
            ->mapWithKeys(function (MarkingComponent $oldComponent) use ($newByName) {
                $newComponent = $newByName->get(Str::lower(trim($oldComponent->name)));

                return $newComponent
                    ? [$oldComponent->id => ['old' => $oldComponent, 'new' => $newComponent]]
                    : [];
            });
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
                    'status' => $oldStudentCurriculum->status,
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
            $oldStudentCurriculum->update(['status' => 'promoted']);
        }
    }
}
