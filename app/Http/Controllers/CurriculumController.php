<?php

namespace App\Http\Controllers;

use App\Enums\StudentStatusEnum;
use App\Enums\TermStatusEnum;
use App\Http\Resources\CurriculumResource;
use App\Http\Resources\CurriculumSubjectResource;
use App\Http\Resources\MarkingComponentResource;
use App\Jobs\BackfillPastTermJob;
use App\Jobs\MoveFromCcmJob;
use App\Models\Curriculum;
use App\Models\MarkingComponent;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\StudentResult;
use App\Models\StudentSubject;
use App\Models\Subject;
use App\Models\Term;
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

            $school = auth()->user()->school;
            $term = Term::where('uuid', $request->term_id)->first();
            $classLevel = $school->classLevelArms()->where('uuid', $request->class_level_id)->first();
            $examType = $school->examTypes()->where('uuid', $request->exam_type_id)->first();

            $curriculum = $school->curricula()->create([
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

            $school = auth()->user()->school;
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
        $school = auth()->user()->school;
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
        if (!$request->has('status')) {
            $curricula->where('status', 'active');
        }
        $curricula = $curricula->paginate($request->integer('per_page', $limit));
        return response()->json([
            "curricula" => CurriculumResource::collection($curricula),
            "pagination" => [
                "total" => $curricula->total(),
                "per_page" => $curricula->perPage(),
                "current_page" => $curricula->currentPage(),
                "last_page" => $curricula->lastPage(),
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
                        'status' => 'active'
                    ]);
                }
            }

            $curriculumSubject->resultStatus()->create(
                [
                    "status" => "draft",
                    "rejection_reason" => null,
                    "updated_by" => auth()->id()
                ]
            );
            // marking components
            $markingComponents = MarkingComponentResource::collection(MarkingComponent::global()->where('is_ccm', $ccm)->get());
            foreach ($markingComponents as $component) {
                $curriculumSubject->markingComponents()->create(["name" => $component->name, "weight" => $component->weight, "school_id" => auth()->user()->school_id]);
            }

            return response()->json(new CurriculumSubjectResource($curriculumSubject), 201);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());
            return response()->json(['error' => 'Failed to assign subject'], 500);
        }
    }

    public function moveFromCcm(Curriculum $curriculum)
    {
        if (!$curriculum->is_ccm) {
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

        if ($term->academicSession?->school_id !== auth()->user()->school_id) {
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
                !in_array($payload['displayName'] ?? null, [MoveFromCcmJob::class, BackfillPastTermJob::class], true)
            ) {
                continue;
            }

            $command = $payload['data']['command'] ?? '';

            preg_match(
                '/s:21:"App\\\\Models\\\\Curriculum";s:2:"id";i:(\d+)/',
                $command,
                $matches
            );

            if (!empty($matches[1])) {
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
            if (!$result) {
                $isAvailable = false;
                break;
            }
        }
        if ($subjectsOffered->isEmpty()) {
            $isAvailable = false;
        }

        if ($isAvailable && auth()->user()->hasRole('guardian') && $studentCurriculum->status === StudentStatusEnum::ACTIVE) {
            $deadline = $studentCurriculum->curriculum?->term?->result_visible_at;
            if ($deadline && !now()->greaterThan($deadline)) {
                $isAvailable = false;
            }
        }

        return response()->json(['available' => $isAvailable]);


    }
}
