<?php

namespace App\Console\Commands;

use App\Enums\StudentStatusEnum;
use App\Models\Curriculum;
use App\Models\ExamType;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Support\ActiveSchool;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stands up the ONE fixture shape the bulk-reassignment drive cannot be run without: two pupils
 * that render the SAME class label and sit in DIFFERENT curricula.
 *
 * ── WHY THIS EXISTS AS A COMMAND ──────────────────────────────────────────────────────────────────
 * The cohort lock refuses a batch whose pupils do not share one `curriculum_id`. CohortSiblings keys
 * on (class level, term, exam type, is_ccm), so the interesting case — the only case that
 * distinguishes a correct implementation from one comparing the class LABEL — is two pupils on the
 * same `class_level_arm` with different exam types.
 *
 * Measured on the working database when this was written: ZERO active class-level-arms carry more
 * than one exam type, so the case is unreachable by clicking. §2 of the drive runbook therefore
 * renders the ENABLED state and passes while testing nothing — which is the fixture form of the same
 * defect the lock test exists to catch, one layer out.
 *
 * ── GUARDED THE SAME WAY SeedDriveFixture IS, AND ADDITIVE UNLIKE IT ──────────────────────────────
 * Refuses unless the database name matches the drive ALLOWLIST — a denylist only refuses the names
 * someone remembered. It does NOT `migrate:fresh` and it does not touch existing rows: it adds one
 * exam type, one curriculum and one pupil. That is deliberate, because it is a SUPPLEMENT to an
 * already-seeded drive fixture rather than a replacement for it, and a re-run must not destroy the
 * state a half-finished drive was standing on.
 *
 * IDEMPOTENT by lookup, not by truncation: re-running reuses the exam type and curriculum it made
 * last time and reports the same ids.
 */
class SeedCohortLockPair extends Command
{
    protected $signature = 'academics:seed-cohort-lock-pair
        {--school= : school id; defaults to the only school with active curricula}
        {--undo : remove the seeded twin pupil, curriculum and exam type}';

    protected $description = 'Seed the same-label/different-exam-type pupil pair the bulk-reassignment drive needs (drive env ONLY)';

    /** Same allowlist as SeedDriveFixture — see that class on why it is not a denylist. */
    private const DRIVE_DB_PATTERN = '/(^|_)drive(_|$)/';

    private const MARKER = 'Cohort-lock drive pair';

    public function handle(): int
    {
        $database = (string) DB::connection()->getDatabaseName();

        if (! preg_match(self::DRIVE_DB_PATTERN, $database)) {
            $this->error("Refused: database '{$database}' is not a drive database.");
            $this->line('This command writes rows. It runs only against a database whose name carries');
            $this->line('an explicit `drive` token (e.g. portal_drive). Point DB_DATABASE at one.');

            return self::FAILURE;
        }

        $schoolId = $this->option('school') !== null
            ? (int) $this->option('school')
            : $this->resolveSchool();

        if ($schoolId === 0) {
            $this->error('Could not resolve a school with active curricula. Pass --school=<id>.');

            return self::FAILURE;
        }

        return ActiveSchool::runFor(
            $schoolId,
            fn () => $this->option('undo') ? $this->undo($schoolId) : $this->seed($schoolId),
        );
    }

    /**
     * Removes only what this command created, matched by the markers it wrote — never by "the newest
     * rows", which would take whatever a concurrent drive had just produced.
     */
    private function undo(int $schoolId): int
    {
        return DB::transaction(function () use ($schoolId) {
            $student = Student::withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->where('admission_number', 'DRIVE-LOCK-01')
                ->first();

            $examType = ExamType::withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->where('name', 'Drive External')
                ->first();

            $removed = ['episodes' => 0, 'student' => 0, 'curricula' => 0, 'exam_type' => 0];

            if ($student !== null) {
                $removed['episodes'] = StudentCurriculum::withoutGlobalScopes()
                    ->where('student_id', $student->id)->delete();
                $student->forceDelete();
                $removed['student'] = 1;
            }

            if ($examType !== null) {
                // Only curricula on the seeded exam type, and only if no OTHER pupil has since been
                // enrolled into them — a drive that put a real pupil there is not this command's to
                // discard.
                $curricula = Curriculum::withoutGlobalScopes()
                    ->where('school_id', $schoolId)
                    ->where('exam_type_id', $examType->id)
                    ->withCount(['studentCurricula' => fn ($q) => $q->whereNull('ended_at')])
                    ->get();

                foreach ($curricula as $curriculum) {
                    if ((int) $curriculum->student_curricula_count > 0) {
                        $this->warn('Kept curriculum#'.$curriculum->id.' — it still has live enrolments.');

                        continue;
                    }

                    $curriculum->delete();
                    $removed['curricula']++;
                }

                if ($removed['curricula'] === $curricula->count()) {
                    $examType->delete();
                    $removed['exam_type'] = 1;
                }
            }

            $this->info('Removed: '.json_encode($removed));

            return self::SUCCESS;
        });
    }

    private function resolveSchool(): int
    {
        $ids = Curriculum::query()
            ->withoutGlobalScopes()
            ->where('status', 'active')
            ->distinct()
            ->pluck('school_id');

        return $ids->count() === 1 ? (int) $ids->first() : 0;
    }

    private function seed(int $schoolId): int
    {
        // The donor: an active curriculum that already has pupils, so the pair sits next to a real
        // cohort rather than in an empty corner of the screen.
        $donor = Curriculum::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('status', 'active')
            ->whereNotNull('class_level_arm_id')
            ->whereHas('studentCurricula', fn ($q) => $q->whereNull('ended_at'))
            ->with('classLevelArm.classLevel', 'classLevelArm.arm')
            ->orderBy('id')
            ->first();

        if ($donor === null) {
            $this->error('No active curriculum with live enrolments in school#'.$schoolId.'.');

            return self::FAILURE;
        }

        return DB::transaction(function () use ($donor, $schoolId) {
            // A SECOND exam type. This is the axis CohortSiblings keys on and the screen does not
            // render — which is exactly what makes the pair indistinguishable by label.
            $examType = ExamType::withoutGlobalScopes()->firstOrCreate(
                ['school_id' => $schoolId, 'name' => 'Drive External'],
                ['slug' => 'drive-external-'.Str::random(6)],
            );

            if ((int) $examType->id === (int) $donor->exam_type_id) {
                $this->error('The donor curriculum already uses the seeded exam type; nothing to distinguish.');

                return self::FAILURE;
            }

            // SAME class_level_arm as the donor — so the label is not merely equal, it is the same
            // arm. Nothing short of comparing curriculum_id can tell the two apart.
            $twin = Curriculum::withoutGlobalScopes()->firstOrCreate(
                [
                    'school_id' => $schoolId,
                    'class_level_arm_id' => $donor->class_level_arm_id,
                    'exam_type_id' => $examType->id,
                    'term_id' => $donor->term_id,
                ],
                [
                    'status' => 'active',
                    'is_ccm' => (bool) $donor->is_ccm,
                    'min_subjects' => $donor->min_subjects ?? 1,
                ],
            );

            $student = Student::withoutGlobalScopes()->firstOrCreate(
                ['school_id' => $schoolId, 'admission_number' => 'DRIVE-LOCK-01'],
                [
                    'first_name' => 'Lockpair',
                    'last_name' => 'Twin',
                    'gender' => 'female',
                ],
            );

            $episode = StudentCurriculum::withoutGlobalScopes()->firstOrCreate(
                ['student_id' => $student->id, 'curriculum_id' => $twin->id],
                ['status' => StudentStatusEnum::ACTIVE],
            );

            $donorEpisode = StudentCurriculum::withoutGlobalScopes()
                ->where('curriculum_id', $donor->id)
                ->whereNull('ended_at')
                ->orderBy('id')
                ->first();

            $label = trim(implode(' ', array_filter([
                $donor->classLevelArm?->classLevel?->name,
                $donor->classLevelArm?->arm?->label,
            ])));

            $this->info(self::MARKER.' ready in school#'.$schoolId.'.');
            $this->newLine();
            $this->line('Both pupils render: "'.$label.'"');
            $this->newLine();
            $this->table(
                ['role', 'student', 'episode', 'curriculum', 'exam_type'],
                [
                    ['donor (existing)', 'student#'.$donorEpisode?->student_id, 'episode#'.$donorEpisode?->id, 'curriculum#'.$donor->id, 'exam_type#'.$donor->exam_type_id],
                    ['twin (seeded)', 'student#'.$student->id, 'episode#'.$episode->id, 'curriculum#'.$twin->id, 'exam_type#'.$examType->id],
                ],
            );
            $this->newLine();
            $this->line('Runbook §2: tick BOTH. Expect Reassign disabled, amber line');
            $this->line('"…your selection spans 2 classes.", and Export selected (2) still enabled.');
            $this->newLine();
            $this->line('Teardown: php artisan academics:seed-cohort-lock-pair --school='.$schoolId.' --undo');

            return self::SUCCESS;
        });
    }
}
