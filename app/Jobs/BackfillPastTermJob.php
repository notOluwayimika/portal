<?php

namespace App\Jobs;

use App\Enums\StudentStatusEnum;
use App\Enums\StudentSubjectStatus;
use App\Enums\TermStatusEnum;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Scopes\SchoolScope;
use App\Models\StudentCurriculum;
use App\Models\StudentSubject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Mirror an active curriculum's structure into a past (completed) term so
 * staff can enter scores, comments and assessments retroactively.
 *
 * The backdated curriculum is created with status 'closed' and its
 * enrollments with status 'promoted' (promoted_to_id -> the source
 * StudentCurriculum row), so the one-active-curriculum-per-student
 * invariant and every current-term flow are untouched. No scores or
 * comments are copied — they are entered fresh.
 *
 * Note: StudentCurriculumObserver logs one academic-anomalies warning per
 * enrollment created here (rows are created before subjects are attached);
 * its auto-attach remediation converges with this job's own firstOrCreate
 * writes, so the noise is expected and harmless.
 */
class BackfillPastTermJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(
        public readonly Curriculum $sourceCurriculum,
        public readonly Term $targetTerm,
        public readonly int $causedByUserId,
    ) {}

    public function handle(): void
    {
        if (! $this->passesGuards()) {
            return;
        }

        $causer = User::find($this->causedByUserId);
        if ($causer) {
            auth()->setUser($causer);
        }

        DB::transaction(function () {
            $targetCurriculum = $this->resolveTargetCurriculum();
            $subjectMap = $this->cloneCurriculumSubjects($targetCurriculum);
            $this->enrollStudents($targetCurriculum, $subjectMap);
        });
    }

    private function passesGuards(): bool
    {
        $source = $this->sourceCurriculum;
        $term = $this->targetTerm;

        $abort = function (string $reason): bool {
            Log::warning("BackfillPastTermJob: {$reason}, aborting", [
                'curriculum_id' => $this->sourceCurriculum->id,
                'target_term_id' => $this->targetTerm->id,
            ]);

            return false;
        };

        if ($source->is_ccm === true) {
            return $abort('source curriculum is CCM');
        }
        if ($source->status !== 'active') {
            return $abort('source curriculum is not active');
        }
        if ($term->id === $source->term_id) {
            return $abort('target term is the source curriculum\'s own term');
        }
        if ($term->status !== TermStatusEnum::COMPLETED) {
            return $abort('target term is not completed');
        }
        if ($term->academicSession()->withoutGlobalScope(SchoolScope::class)->first()?->school_id !== $source->school_id) {
            return $abort('target term belongs to another school');
        }

        return true;
    }

    /**
     * Find (or create) the backdated curriculum in the target term.
     * Matching on the curricula table's unique key guarantees a re-run
     * reuses the previously created target. Immutable marking and grading
     * scheme snapshots are shared with the source; legacy sources continue
     * to use subject-local components.
     */
    private function resolveTargetCurriculum(): Curriculum
    {
        $target = Curriculum::withoutGlobalScope(SchoolScope::class)->firstOrCreate(
            [
                'school_id' => $this->sourceCurriculum->school_id,
                'term_id' => $this->targetTerm->id,
                'class_level_arm_id' => $this->sourceCurriculum->class_level_arm_id,
                'exam_type_id' => $this->sourceCurriculum->exam_type_id,
                'is_ccm' => false,
            ],
            [
                'min_subjects' => $this->sourceCurriculum->min_subjects,
                'status' => 'closed',
                'marking_scheme_id' => $this->sourceCurriculum->marking_scheme_id,
                'grading_scheme_id' => $this->sourceCurriculum->grading_scheme_id,
            ]
        );

        // Repair targets created by the pre-scheme version of this job only
        // while they remain unused. Once any result/component exists, their
        // configuration is historical data and must not be changed here.
        if (! $target->wasRecentlyCreated && $this->canAdoptSourceSchemes($target)) {
            $target->update([
                'marking_scheme_id' => $target->marking_scheme_id ?? $this->sourceCurriculum->marking_scheme_id,
                'grading_scheme_id' => $target->grading_scheme_id ?? $this->sourceCurriculum->grading_scheme_id,
            ]);
        }

        return $target;
    }

    private function canAdoptSourceSchemes(Curriculum $target): bool
    {
        if (
            (! $this->sourceCurriculum->marking_scheme_id || $target->marking_scheme_id)
            && (! $this->sourceCurriculum->grading_scheme_id || $target->grading_scheme_id)
        ) {
            return false;
        }

        return ! $target->curriculumSubjects()
            ->where(function ($query) {
                $query->whereHas('markingComponents')
                    ->orWhereHas('scores')
                    ->orWhereHas('studentResults');
            })
            ->exists();
    }

    /**
     * Clone every curriculum subject onto the backdated curriculum. Scheme-
     * backed curricula resolve shared components through the target
     * curriculum; only legacy curricula copy subject-local components.
     *
     * @return array<int, CurriculumSubject> old curriculum_subject_id => new CurriculumSubject
     */
    private function cloneCurriculumSubjects(Curriculum $targetCurriculum): array
    {
        $subjectMap = [];

        foreach ($this->sourceCurriculum->curriculumSubjects as $oldSubject) {
            $newSubject = CurriculumSubject::firstOrCreate(
                [
                    'curriculum_id' => $targetCurriculum->id,
                    'subject_id' => $oldSubject->subject_id,
                ],
                [
                    'is_compulsory' => $oldSubject->is_compulsory,
                    'display_order' => $oldSubject->display_order,
                    'active' => $oldSubject->active,
                ]
            );

            if (
                $newSubject->wasRecentlyCreated
                && ! $targetCurriculum->marking_scheme_id
                && ! $targetCurriculum->usesCategoricalGrading()
            ) {
                foreach ($oldSubject->markingComponents as $component) {
                    $newSubject->markingComponents()->create([
                        'name' => $component->name,
                        'weight' => $component->weight,
                        'school_id' => $targetCurriculum->school_id,
                        'is_ccm' => false,
                    ]);
                }
            }

            $newSubject->resultStatus()->firstOrCreate([], [
                'status' => 'draft',
                'rejection_reason' => null,
                'updated_by' => $this->causedByUserId,
            ]);

            foreach ($oldSubject->teacherAssignments as $assignment) {
                $newSubject->teacherAssignments()->firstOrCreate([
                    'teacher_id' => $assignment->teacher_id,
                ]);
            }

            $subjectMap[$oldSubject->id] = $newSubject;
        }

        return $subjectMap;
    }

    /**
     * Enroll the source roster into the backdated curriculum as 'promoted'
     * rows pointing at their source enrollment, cloning subject selections.
     * Source rows are never modified.
     *
     * @param  array<int, CurriculumSubject>  $subjectMap  old curriculum_subject_id => new CurriculumSubject
     */
    private function enrollStudents(Curriculum $targetCurriculum, array $subjectMap): void
    {
        foreach ($this->sourceCurriculum->studentCurricula as $sourceEnrollment) {
            if ($sourceEnrollment->status === StudentStatusEnum::WITHDRAWN) {
                continue;
            }

            $backdatedEnrollment = StudentCurriculum::firstOrCreate(
                [
                    'student_id' => $sourceEnrollment->student_id,
                    'curriculum_id' => $targetCurriculum->id,
                ],
                [
                    'status' => StudentStatusEnum::PROMOTED,
                    'promoted_to_id' => $sourceEnrollment->id,
                ]
            );

            foreach ($sourceEnrollment->activeSubjects as $sourceSubject) {
                $newCurriculumSubject = $subjectMap[$sourceSubject->curriculum_subject_id] ?? null;

                if (! $newCurriculumSubject) {
                    continue;
                }

                StudentSubject::firstOrCreate(
                    [
                        'student_curriculum_id' => $backdatedEnrollment->id,
                        'curriculum_subject_id' => $newCurriculumSubject->id,
                    ],
                    [
                        'status' => StudentSubjectStatus::Active,
                    ]
                );
            }
        }
    }
}
