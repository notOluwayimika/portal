<?php

namespace App\Services;

use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\GradeBoundary;
use App\Models\StudentCurriculum;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class BroadsheetService
{
    /**
     * Fixed ordering for the marking-component columns, matching the column
     * order used in both the CCM and End of Term broadsheet templates.
     */
    private const COMPONENT_ORDER = [
        'continuous assessment 1' => 1,
        'continuous assessment 2' => 2,
        'half term exam' => 3,
        'examination' => 4,
    ];

    private const COMPONENT_LABELS = [
        'continuous assessment 1' => 'CA 1',
        'continuous assessment 2' => 'CA 2',
        'half term exam' => 'HT',
        'examination' => 'Exam',
    ];

    /**
     * Group the curricula belonging to a class level (across all its
     * class_level_arms) into "broadsheets" - sets of curricula that are
     * identical except for class_level_arm_id.
     */
    public function groups(ClassLevel $classLevel, ?string $status, ?bool $isCcm): array
    {
        $curricula = Curriculum::query()
            ->whereHas('classLevelArm', fn($q) => $q->where('class_level_id', $classLevel->id))
            ->when($status, fn($q) => $q->where('status', $status))
            ->when(!is_null($isCcm), fn($q) => $q->where('is_ccm', $isCcm))
            ->with(['term.academicSession', 'examType', 'classLevelArm.arm', 'classLevelArm.classLevel', 'classLevelArm.stream'])
            ->get();

        $groups = $curricula->groupBy(fn(Curriculum $c) => implode('|', [
            $c->term_id,
            $c->exam_type_id,
            $c->is_ccm ? '1' : '0',
            $c->status,
            $c->min_subjects,
        ]));

        return $groups->map(function (Collection $members) {
            $first = $members->first();
            $term = $first->term;

            return [
                'curriculum_id' => $first->uuid,
                'term' => [
                    'name' => $term->name,
                    'full_name' => $term->name . ' - ' . $term->academicSession->name,
                ],
                'exam_type' => $first->examType->name,
                'is_ccm' => $first->is_ccm,
                'status' => $first->status,
                'arms' => $members
                    ->map(fn(Curriculum $c) => $this->classLabel($c->classLevelArm))
                    ->values()
                    ->all(),
                'arm_count' => $members->count(),
            ];
        })->values()->all();
    }

    /**
     * Build the full broadsheet for a curriculum: every sibling curriculum
     * (same term/exam type/is_ccm/status/min_subjects across the class
     * level's arms), one table grouped by class.
     */
    public function build(Curriculum $curriculum): array
    {
        $curriculum->loadMissing(['classLevelArm.classLevel', 'classLevelArm.arm', 'term.academicSession', 'examType', 'school']);

        $classLevel = $curriculum->classLevelArm->classLevel;

        $siblings = Curriculum::query()
            ->whereHas('classLevelArm', fn($q) => $q->where('class_level_id', $classLevel->id))
            ->where('term_id', $curriculum->term_id)
            ->where('exam_type_id', $curriculum->exam_type_id)
            ->where('is_ccm', $curriculum->is_ccm)
            ->where('status', $curriculum->status)
            ->where('min_subjects', $curriculum->min_subjects)
            ->with([
                'classLevelArm.arm',
                'classLevelArm.classLevel',
                'classLevelArm.stream',
                'curriculumSubjects.subject',
                'curriculumSubjects.markingComponents',
                'curriculumSubjects.scores',
                'curriculumSubjects.studentResults',
                'studentCurricula.student',
            ])
            ->orderBy('class_level_arm_id')
            ->get();

        $columnSubjects = $this->buildColumnModel($siblings->first(), $curriculum->is_ccm);
        $boundaries = $this->resolveGradeBoundaries($curriculum);

        $sn = 0;
        $classes = $siblings->map(function (Curriculum $sibling) use ($columnSubjects, $boundaries, &$sn) {
            $students = $sibling->studentCurricula->map(function (StudentCurriculum $studentCurriculum) use ($sibling, $columnSubjects, $boundaries, &$sn) {
                $student = $studentCurriculum->student;

                if (!$student) {
                    return null;
                }

                $subjectsData = [];
                $gpSum = 0.0;
                $gpCount = 0;

                foreach ($columnSubjects as $col) {
                    $curriculumSubject = $sibling->curriculumSubjects->firstWhere('subject_id', $col['subject_id']);
                    $cell = $this->buildCell($student->id, $curriculumSubject, $col, $boundaries);

                    if ($cell['gp'] !== null) {
                        $gpSum += (float) $cell['gp'];
                        $gpCount++;
                    }

                    $subjectsData[(string) $col['subject_id']] = $cell;
                }

                return [
                    'sn' => ++$sn,
                    'name' => trim($student->last_name . ', ' . $student->first_name . ($student->middle_name ? ' ' . $student->middle_name : '')),
                    'gender' => $student->gender,
                    'subjects' => $subjectsData,
                    'gpa' => $gpCount > 0 ? number_format($gpSum / $gpCount, 1) : null,
                ];
            })->filter()->values();

            return [
                'label' => $this->classLabel($sibling->classLevelArm),
                'students' => $students->all(),
            ];
        })->values()->all();

        $school = $curriculum->school;
        $term = $curriculum->term;

        return [
            'school_name' => $school->name,
            'class_level' => $classLevel->name,
            'term' => [
                'name' => $term->name,
                'full_name' => $term->name . ' - ' . $term->academicSession->name,
            ],
            'exam_type' => $curriculum->examType->name,
            'is_ccm' => $curriculum->is_ccm,
            'status' => $curriculum->status,
            'subjects' => $columnSubjects,
            'classes' => $classes,
        ];
    }

    /**
     * Build the column model from a representative curriculum's subjects.
     */
    private function buildColumnModel(?Curriculum $curriculum, bool $isCcm): array
    {
        if (!$curriculum) {
            return [];
        }

        return $curriculum->curriculumSubjects
            ->sortBy('display_order')
            ->map(function (CurriculumSubject $cs) use ($isCcm) {
                $components = $this->orderedComponents($cs->markingComponents);

                $columns = $components->map(fn($mc) => [
                    'key' => Str::slug($mc->name, '_'),
                    'label' => $this->componentLabel($mc->name),
                    'name' => $mc->name,
                ])->values()->all();

                if ($isCcm) {
                    $columns[] = ['key' => 'total', 'label' => 'CCM', 'name' => 'CCM'];
                } else {
                    $caWeight = (float) $components
                        ->filter(fn($mc) => Str::lower(trim($mc->name)) !== 'examination')
                        ->sum(fn($mc) => (float) $mc->weight);
                    $examWeight = (float) $components
                        ->filter(fn($mc) => Str::lower(trim($mc->name)) === 'examination')
                        ->sum(fn($mc) => (float) $mc->weight);

                    $columns[] = ['key' => 'ca_total', 'label' => 'CA (' . round($caWeight * 100) . '%)', 'name' => 'CA Total'];
                    $columns[] = ['key' => 'exam_total', 'label' => 'Exam (' . round($examWeight * 100) . '%)', 'name' => 'Exam Total'];
                    $columns[] = ['key' => 'total', 'label' => 'Total Score', 'name' => 'Total Score'];
                }

                $columns[] = ['key' => 'grade', 'label' => 'Grade', 'name' => 'Grade'];
                $columns[] = ['key' => 'gp', 'label' => 'GP', 'name' => 'GP'];

                return [
                    'subject_id' => $cs->subject_id,
                    'name' => $cs->subject->name,
                    'columns' => $columns,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Build the per-student, per-subject cell values for one column group.
     */
    private function buildCell(int $studentId, ?CurriculumSubject $curriculumSubject, array $column, Collection $boundaries): array
    {
        $cell = [];
        foreach ($column['columns'] as $colDef) {
            $cell[$colDef['key']] = null;
        }

        if (!$curriculumSubject) {
            return $cell;
        }

        $componentScores = [];
        foreach ($curriculumSubject->scores->where('student_id', $studentId) as $score) {
            $mc = $curriculumSubject->markingComponents->firstWhere('id', $score->marking_component_id);

            if ($mc) {
                $componentScores[Str::lower(trim($mc->name))] = (float) $score->score;
            }
        }

        $caSum = 0.0;
        $caHasValue = false;
        $examScore = null;
        $allSum = 0.0;
        $allHasValue = false;

        foreach ($componentScores as $name => $score) {
            $allSum += $score;
            $allHasValue = true;

            if ($name === 'examination') {
                $examScore = $score;
            } else {
                $caSum += $score;
                $caHasValue = true;
            }
        }

        foreach ($column['columns'] as $colDef) {
            $key = $colDef['key'];

            switch ($key) {
                case 'ca_total':
                    $cell[$key] = $caHasValue ? round($caSum, 2) : null;
                    break;
                case 'exam_total':
                    $cell[$key] = $examScore !== null ? round($examScore, 2) : null;
                    break;
                case 'total':
                    $cell[$key] = $allHasValue ? round($allSum, 2) : null;
                    break;
                case 'grade':
                case 'gp':
                    // handled below
                    break;
                default:
                    $name = Str::lower(trim($colDef['name']));
                    $cell[$key] = $componentScores[$name] ?? null;
                    break;
            }
        }

        $studentResult = $curriculumSubject->studentResults->firstWhere('student_id', $studentId);

        if ($studentResult && $studentResult->grade) {
            $cell['grade'] = $studentResult->grade;
            $cell['gp'] = $this->gradePointForGrade($studentResult->grade, $boundaries);
        }

        return $cell;
    }

    private function gradePointForGrade(?string $grade, Collection $boundaries): ?string
    {
        if (!$grade) {
            return null;
        }

        $boundary = $boundaries->firstWhere('grade', $grade);

        return $boundary?->grade_point;
    }

    private function resolveGradeBoundaries(Curriculum $curriculum): Collection
    {
        $boundaries = GradeBoundary::where('school_id', $curriculum->school_id)
            ->where('exam_type_id', $curriculum->exam_type_id)
            ->get();

        if ($boundaries->isEmpty()) {
            $boundaries = GradeBoundary::where('school_id', $curriculum->school_id)
                ->whereNull('exam_type_id')
                ->get();
        }

        return $boundaries;
    }

    private function orderedComponents(Collection $components): Collection
    {
        return $components->sortBy(function ($mc) {
            $key = Str::lower(trim($mc->name));

            return self::COMPONENT_ORDER[$key] ?? (100 + $mc->id);
        })->values();
    }

    private function componentLabel(string $name): string
    {
        $key = Str::lower(trim($name));

        return self::COMPONENT_LABELS[$key] ?? $name;
    }

    private function classLabel(ClassLevelArm $classLevelArm): string
    {
        $label = str_replace('Year ', '', $classLevelArm->classLevel->name) . $classLevelArm->arm->label;

        if ($classLevelArm->stream) {
            $label .= ' ' . $classLevelArm->stream->name;
        }

        return $label;
    }
}
