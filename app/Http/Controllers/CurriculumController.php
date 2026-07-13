<?php

namespace App\Http\Controllers;

use App\Enums\StudentStatusEnum;
use App\Enums\StudentSubjectStatus;
use App\Enums\TermStatusEnum;
use App\Http\Resources\CurriculumResource;
use App\Http\Resources\CurriculumSubjectResource;
use App\Jobs\BackfillPastTermJob;
use App\Jobs\MoveFromCcmJob;
use App\Models\Curriculum;
use App\Models\MarkingComponent;
use App\Models\MarkingScheme;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\StudentResult;
use App\Models\StudentSubject;
use App\Models\Subject;
use App\Models\Term;
use App\Support\ActiveSchool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CurriculumController extends Controller
{
    public function show(Curriculum $curriculum)
    {
        $curriculum->load(['curriculumSubjects.teacherAssignments.teacher']);

        return response()->json(new CurriculumResource($curriculum));
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'term_id' => 'required|string|exists:terms,uuid',
                'class_level_id' => 'required|string',
                'exam_type_id' => 'required|string',
                'min_subjects' => 'required|integer|min:1',
                'status' => 'required|string|in:active,draft,closed',
                'is_ccm' => 'boolean',
            ]);
            \Log::error($request->all());

            $school = ActiveSchool::getOrFail();
            $term = Term::where('uuid', $request->term_id)->first();
            $classLevel = $school->classLevelArms()->where('uuid', $request->class_level_id)->first();
            $examType = $school->examTypes()->where('uuid', $request->exam_type_id)->first();

            $curriculum = $school->curricula()->create([
                'marking_scheme_id' => $classLevel->classLevel->grading_scheme_id
                    ? null
                    : MarkingScheme::query()
                        ->active()
                        ->where('school_id', $school->id)
                        ->where('is_ccm', $request->boolean('is_ccm'))
                        ->latest('version')
                        ->value('id'),
                'grading_scheme_id' => $classLevel->classLevel->grading_scheme_id,
                'term_id' => $term->id,
                'class_level_arm_id' => $classLevel->id,
                'exam_type_id' => $examType->id,
                'min_subjects' => $request->min_subjects,
                'status' => $request->status,
                'is_ccm' => $request->is_ccm,
            ]);

            return response()->json(new CurriculumResource($curriculum), 201);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());

            return response()->json(['error' => 'Failed to create curriculum'], 500);
        }

    }

    public function update(Request $request, Curriculum $curriculum)
    {
        try {
            $request->validate([
                'term_id' => 'required|string|exists:terms,uuid',
                'class_level_id' => 'required|string',
                'exam_type_id' => 'required|string',
                'min_subjects' => 'required|integer|min:1',
                'status' => 'required|string|in:active,draft,closed',
                'is_ccm' => 'boolean',
            ]);

            $school = ActiveSchool::getOrFail();
            $term = Term::where('uuid', $request->term_id)->first();
            $classLevel = $school->classLevelArms()->where('uuid', $request->class_level_id)->first();
            $examType = $school->examTypes()->where('uuid', $request->exam_type_id)->first();

            $curriculum->update([
                'term_id' => $term->id,
                'class_level_arm_id' => $classLevel->id,
                'exam_type_id' => $examType->id,
                'min_subjects' => $request->min_subjects,
                'status' => $request->status,
                'is_ccm' => $request->is_ccm,
            ]);

            return response()->json(new CurriculumResource($curriculum), 200);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());

            return response()->json(['error' => 'Failed to update curriculum'], 500);
        }
    }

    public function destroy(Curriculum $curriculum)
    {
        try {
            $curriculum->delete();

            return response()->json(['message' => 'Curriculum deleted successfully'], 204);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());

            return response()->json(['error' => 'Failed to delete curriculum'], 500);
        }
    }

    public function active()
    {
        return CurriculumResource::collection(Curriculum::where('status', 'active')->get());
    }

    public function index(Request $request)
    {
        $school = ActiveSchool::getOrFail();
        $limit = $request->integer('limit', 25);
        $curricula = $school->curricula();
        // apply filters for term_id, class_level_id and status if they exist
        if ($request->has('term_id')) {
            $term = Term::where('uuid', $request->term_id)->first();
            $curricula = $curricula->where('term_id', $term->id);
        }
        if ($request->has('class_level_id')) {
            $classLevel = $school->classLevelArms()->where('uuid', $request->class_level_id)->first();
            $curricula = $curricula->where('class_level_arm_id', $classLevel->id);
        }
        if ($request->has('status')) {
            $curricula = $curricula->where('status', $request->status);
        }
        if ($request->has('is_ccm')) {
            $curricula = $curricula->where('is_ccm', $request->boolean('is_ccm'));
        }
        if (! $request->has('status')) {
            $curricula->where('status', 'active');
        }
        $curricula = $curricula->paginate($request->integer('per_page', $limit));

        return response()->json([
            'curricula' => CurriculumResource::collection($curricula),
            'pagination' => [
                'total' => $curricula->total(),
                'per_page' => $curricula->perPage(),
                'current_page' => $curricula->currentPage(),
                'last_page' => $curricula->lastPage(),
            ],
        ], 200);
    }

    public function reorder(Request $request, Curriculum $curriculum)
    {
        try {
            $request->validate([
                'order' => 'required|array',
                'order.*.id' => 'required|string',
                'order.*.display_order' => 'required|integer|min:1',
            ]);
            DB::transaction(function () use ($request, $curriculum) {
                $order = $request->order;

                foreach ($order as $item) {
                    $curriculumSubject = $curriculum->curriculumSubjects->where('uuid', $item['id'])->first();
                    if ($curriculumSubject) {
                        $curriculumSubject->update(['display_order' => $item['display_order']]);
                    }
                }

                return response()->json(['message' => 'Subjects reordered successfully'], 200);
            });
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());

            return response()->json(['error' => 'Failed to reorder subjects'], 500);
        }

    }

    public function assignSubject(Curriculum $curriculum, Request $request)
    {
        try {
            $request->validate([
                'subject_id' => 'required|string|exists:subjects,uuid',
                'is_compulsory' => 'boolean',
                'display_order' => 'integer|min:1',
            ]);
            $ccm = $curriculum->is_ccm;
            $subject = Subject::where('uuid', $request->subject_id)->first();

            $curriculumSubject = $curriculum->curriculumSubjects()->create([
                'subject_id' => $subject->id,
                'is_compulsory' => $request->is_compulsory,
                'display_order' => $request->display_order,
            ]);
            if ($request->is_compulsory) {
                $students = $curriculum->studentCurricula;
                foreach ($students as $student) {
                    StudentSubject::updateOrCreate([
                        'student_curriculum_id' => $student->id,
                        'curriculum_subject_id' => $curriculumSubject->id,
                    ], [
                        'status' => 'active',
                    ]);
                }
            }

            $curriculumSubject->resultStatus()->create(
                [
                    'status' => 'draft',
                    'rejection_reason' => null,
                    'updated_by' => auth()->id(),
                ]
            );
            // Legacy curricula continue cloning until explicitly migrated.
            if (! $curriculum->usesCategoricalGrading() && ! $curriculum->marking_scheme_id) {
                $markingComponents = $curriculum->curriculumSubjects()
                    ->whereKeyNot($curriculumSubject->id)
                    ->with('markingComponents')
                    ->first()?->markingComponents;

                $markingComponents ??= MarkingScheme::query()
                    ->active()
                    ->where('school_id', $curriculum->school_id)
                    ->where('is_ccm', $ccm)
                    ->latest('version')
                    ->first()?->components;

                $markingComponents ??= MarkingComponent::global()
                    ->where('school_id', $curriculum->school_id)
                    ->where('is_ccm', $ccm)
                    ->get();
                foreach ($markingComponents as $component) {
                    $curriculumSubject->markingComponents()->create([
                        'name' => $component->name,
                        'weight' => $component->weight,
                        'school_id' => $curriculum->school_id,
                        'is_ccm' => $ccm,
                    ]);
                }
            }

            return response()->json(new CurriculumSubjectResource($curriculumSubject), 201);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());

            return response()->json(['error' => 'Failed to assign subject'], 500);
        }
    }

    public function moveFromCcm(Curriculum $curriculum)
    {
        if (! $curriculum->is_ccm) {
            return response()->json(['error' => 'Curriculum is not a CCM curriculum'], 422);
        }

        MoveFromCcmJob::dispatch($curriculum, auth()->id());

        return response()->json(['message' => 'Migration to non-CCM has been queued'], 202);
    }

    public function backfillTerm(Request $request, Curriculum $curriculum)
    {
        $request->validate([
            'term_id' => 'required|string|exists:terms,uuid',
        ]);

        if ($curriculum->is_ccm) {
            return response()->json(['error' => 'CCM curricula cannot be backfilled'], 422);
        }
        if ($curriculum->status !== 'active') {
            return response()->json(['error' => 'Only active curricula can be used as a backfill source'], 422);
        }

        $term = Term::where('uuid', $request->term_id)->first();

        if ($term->academicSession?->school_id !== ActiveSchool::id()) {
            return response()->json(['error' => 'Term not found'], 404);
        }
        if ($term->id === $curriculum->term_id) {
            return response()->json(['error' => 'Cannot backfill into the curriculum\'s own term'], 422);
        }
        if ($term->status !== TermStatusEnum::COMPLETED) {
            return response()->json(['error' => 'Only completed terms can be backfilled'], 422);
        }

        BackfillPastTermJob::dispatch($curriculum, $term, auth()->id());

        return response()->json(['message' => 'Past-term backfill has been queued'], 202);
    }

    public function queuedCurriculums()
    {
        $curriculumIds = [];

        $jobs = DB::table('jobs')
            ->select('payload')
            ->get();

        foreach ($jobs as $job) {
            $payload = json_decode($job->payload, true);

            if (
                ! in_array($payload['displayName'] ?? null, [MoveFromCcmJob::class, BackfillPastTermJob::class], true)
            ) {
                continue;
            }

            $command = $payload['data']['command'] ?? '';

            preg_match(
                '/s:21:"App\\\\Models\\\\Curriculum";s:2:"id";i:(\d+)/',
                $command,
                $matches
            );

            if (! empty($matches[1])) {
                $curriculumIds[] = (int) $matches[1];
            }
        }

        $uuids = Curriculum::query()
            ->whereIn('id', array_unique($curriculumIds))
            ->pluck('uuid')
            ->values();

        return response()->json([
            'curriculum_uuids' => $uuids,
        ]);
    }

    public function activeResultStatus(Student $student, Curriculum $curriculum)
    {
        $isAvailable = true;
        $studentCurriculum = StudentCurriculum::where('curriculum_id', $curriculum->id)->where('student_id', $student->id)->first();
        $subjectsOffered = $studentCurriculum->activeSubjects;
        foreach ($subjectsOffered as $subject) {
            $result = StudentResult::where('student_id', $student->id)->where('curriculum_subject_id', $subject->curriculum_subject_id)->first();
            if (! $result) {
                $isAvailable = false;
                break;
            }
        }
        if ($subjectsOffered->isEmpty()) {
            $isAvailable = false;
        }

        if ($isAvailable && auth()->user()->hasRole('guardian') && $studentCurriculum->status === StudentStatusEnum::ACTIVE) {
            $deadline = $studentCurriculum->curriculum?->term?->result_visible_at;
            if ($deadline && ! now()->greaterThan($deadline)) {
                $isAvailable = false;
            }
        }

        return response()->json(['available' => $isAvailable]);

    }

    /**
     * GET /api/results/incomplete
     *
     * Admin view of every enrollment that FAILS the activeResultStatus check:
     * student-curricula where at least one active subject has no StudentResult
     * yet, or that have no active subjects at all. Defaults to active
     * curricula in the active school; pass curriculum_id (uuid) to inspect one.
     */
    public function incompleteResults(Request $request)
    {
        $data = $request->validate([
            'curriculum_id' => ['nullable', 'uuid', 'exists:curricula,uuid'],
            'reason' => ['nullable', 'in:missing_results,no_active_subjects'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $schoolId = ActiveSchool::id();
        $reason = $data['reason'] ?? null;

        $noActiveSubjects = fn ($q) => $q->whereDoesntHave(
            'studentSubjects',
            fn ($qq) => $qq->where('status', StudentSubjectStatus::Active)
        );

        $missingResults = fn ($q) => $q->whereHas('studentSubjects', function ($qq) {
            $qq->where('status', StudentSubjectStatus::Active)
                ->whereNotExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('student_results')
                        ->whereColumn('student_results.curriculum_subject_id', 'student_subjects.curriculum_subject_id')
                        ->whereColumn('student_results.student_id', 'student_curricula.student_id');
                });
        });

        $paginated = StudentCurriculum::query()
            ->whereHas('curriculum', function ($q) use ($schoolId, $data) {
                $q->where('school_id', $schoolId)
                    ->when($data['curriculum_id'] ?? null, fn ($qq, $uuid) => $qq->where('uuid', $uuid))
                    ->when(! ($data['curriculum_id'] ?? null), fn ($qq) => $qq->where('status', 'active'));
            })
            ->where(function ($q) use ($reason, $noActiveSubjects, $missingResults) {
                if ($reason === 'no_active_subjects') {
                    $noActiveSubjects($q);
                } elseif ($reason === 'missing_results') {
                    $missingResults($q);
                } else {
                    // enrollments with no active subjects at all, or with at
                    // least one active subject missing its result
                    $q->where($noActiveSubjects)->orWhere($missingResults);
                }
            })
            ->with([
                'student',
                'curriculum.classLevelArm.classLevel',
                'curriculum.classLevelArm.arm',
                'curriculum.term',
                'activeSubjects',
            ])
            ->paginate($data['per_page'] ?? 50);

        $items = collect($paginated->items());

        // One lookup for all results on this page, keyed by student + subject.
        $resultKeys = StudentResult::query()
            ->whereIn('student_id', $items->pluck('student_id'))
            ->whereIn('curriculum_subject_id', $items->flatMap(fn ($sc) => $sc->activeSubjects->pluck('curriculum_subject_id')))
            ->get(['student_id', 'curriculum_subject_id'])
            ->map(fn ($r) => $r->student_id.'-'.$r->curriculum_subject_id)
            ->flip();

        return response()->json([
            'data' => $items->map(function ($sc) use ($resultKeys) {
                $missing = $sc->activeSubjects
                    ->reject(fn ($ss) => $resultKeys->has($sc->student_id.'-'.$ss->curriculum_subject_id))
                    ->map(fn ($ss) => [
                        'uuid' => $ss->curriculumSubject?->uuid,
                        'name' => $ss->curriculumSubject?->subject?->name,
                    ])
                    ->values();

                return [
                    'student_curriculum_uuid' => $sc->uuid,
                    'status' => $sc->status,
                    'student' => [
                        'uuid' => $sc->student?->uuid,
                        'name' => $sc->student?->full_name,
                        'admission_number' => $sc->student?->admission_number,
                    ],
                    'curriculum' => [
                        'uuid' => $sc->curriculum?->uuid,
                        'name' => $sc->curriculum?->full_name,
                        'term' => $sc->curriculum?->term?->name,
                    ],
                    'subjects_offered' => $sc->activeSubjects->count(),
                    'missing_results' => $missing->count(),
                    'missing_subjects' => $missing,
                    'reason' => $sc->activeSubjects->isEmpty() ? 'no_active_subjects' : 'missing_results',
                ];
            }),
            'pagination' => [
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }
}
