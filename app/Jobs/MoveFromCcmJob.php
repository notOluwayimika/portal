<?php

namespace App\Jobs;

use App\Enums\StudentSubjectStatus;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\MarkingComponent;
use App\Models\Scopes\SchoolScope;
use App\Models\StudentCurriculum;
use App\Models\StudentSubject;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MoveFromCcmJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;

    public function __construct(
        public readonly Curriculum $curriculum,
        public readonly int $causedByUserId,
    ) {
    }

    public function handle(): void
    {
        if ($this->curriculum->is_ccm !== true) {
            Log::warning('MoveFromCcmJob: curriculum is not CCM, aborting', [
                'curriculum_id' => $this->curriculum->id,
            ]);
            return;
        }

        $causer = User::find($this->causedByUserId);
        if ($causer) {
            auth()->setUser($causer);
        }

        DB::transaction(function () {
            $curriculum = $this->curriculum;
            $curriculum->update(['status' => 'closed']);

            $targetCurriculum = $this->resolveTargetCurriculum($curriculum);

            $subjectMap = $this->cloneCurriculumSubjects($curriculum, $targetCurriculum);

            $this->migrateStudents($curriculum, $targetCurriculum, $subjectMap);
        });
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
     * @param array<int, CurriculumSubject> $subjectMap old curriculum_subject_id => new CurriculumSubject
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

                if (!$newCurriculumSubject) {
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
