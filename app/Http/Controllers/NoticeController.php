<?php

namespace App\Http\Controllers;

use App\Enums\StudentStatusEnum;
use App\Http\Resources\NoticeCategoryResource;
use App\Http\Resources\NoticeResource;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Notice;
use App\Models\NoticeCategory;
use App\Models\Student;
use App\Models\StudentCurriculum;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class NoticeController extends Controller
{
    public function index(Request $request)
    {
        $query = Notice::with(['category', 'creator', 'classLevels', 'classLevelArms', 'students'])
            ->orderBy('created_at', 'desc');

        if ($search = $request->input('search')) {
            $query->where('title', 'like', "%{$search}%");
        }

        if ($categoryId = $request->input('category')) {
            $cat = NoticeCategory::where('uuid', $categoryId)->first();
            if ($cat) {
                $query->where('notice_category_id', $cat->id);
            }
        }

        if ($request->filled('gender')) {
            $query->where('target_gender', $request->input('gender'));
        }

        if ($request->filled('class_level')) {
            $cl = ClassLevel::where('uuid', $request->input('class_level'))->first();
            if ($cl) {
                $query->whereHas('classLevels', fn ($q) => $q->where('class_levels.id', $cl->id));
            }
        }

        if ($request->filled('class_level_arm')) {
            $cla = ClassLevelArm::where('uuid', $request->input('class_level_arm'))->first();
            if ($cla) {
                $query->whereHas('classLevelArms', fn ($q) => $q->where('class_level_arms.id', $cla->id));
            }
        }

        if ($request->filled('starts_from')) {
            $query->where('starts_at', '>=', $request->input('starts_from'));
        }

        if ($request->filled('starts_to')) {
            $query->where('starts_at', '<=', $request->input('starts_to'));
        }

        if ($request->filled('status')) {
            $now = now();
            if ($request->input('status') === 'active') {
                $query->where('starts_at', '<=', $now)
                    ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
            } elseif ($request->input('status') === 'ended') {
                $query->whereNotNull('ends_at')->where('ends_at', '<', $now);
            } elseif ($request->input('status') === 'scheduled') {
                $query->where('starts_at', '>', $now);
            }
        }

        $notices = $query->paginate($request->input('per_page', 15));

        return response()->json([
            'data' => NoticeResource::collection($notices),
            'pagination' => [
                'total' => $notices->total(),
                'per_page' => $notices->perPage(),
                'current_page' => $notices->currentPage(),
                'last_page' => $notices->lastPage(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'category_id' => ['required', 'string'],
            'target_gender' => ['nullable', 'string', Rule::in(['male', 'female'])],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'class_level_ids' => ['nullable', 'array'],
            'class_level_ids.*' => ['string'],
            'class_level_arm_ids' => ['nullable', 'array'],
            'class_level_arm_ids.*' => ['string'],
            'student_ids' => ['nullable', 'array'],
            'student_ids.*' => ['string'],
        ]);

        $category = NoticeCategory::where('uuid', $data['category_id'])->firstOrFail();

        $notice = DB::transaction(function () use ($data, $category, $request) {
            $notice = Notice::create([
                'school_id' => session('school_id') ?? $request->user()->school_id,
                'title' => $data['title'],
                'body' => $data['body'],
                'notice_category_id' => $category->id,
                'target_gender' => $data['target_gender'] ?? null,
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            if (!empty($data['class_level_ids'])) {
                $clIds = ClassLevel::whereIn('uuid', $data['class_level_ids'])->pluck('id');
                $notice->classLevels()->attach($clIds);
            }

            if (!empty($data['class_level_arm_ids'])) {
                $claIds = ClassLevelArm::whereIn('uuid', $data['class_level_arm_ids'])->pluck('id');
                $notice->classLevelArms()->attach($claIds);
            }

            if (!empty($data['student_ids'])) {
                $studentIds = Student::whereIn('uuid', $data['student_ids'])->pluck('id');
                $notice->students()->attach($studentIds);
            }

            return $notice;
        });

        $notice->load(['category', 'creator', 'classLevels', 'classLevelArms', 'students']);

        return Response::created(new NoticeResource($notice));
    }

    public function show(Notice $notice)
    {
        $notice->load(['category', 'creator', 'classLevels', 'classLevelArms', 'students']);

        return response()->json(['data' => new NoticeResource($notice)]);
    }

    public function update(Request $request, Notice $notice)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'category_id' => ['required', 'string'],
            'target_gender' => ['nullable', 'string', Rule::in(['male', 'female'])],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'class_level_ids' => ['nullable', 'array'],
            'class_level_ids.*' => ['string'],
            'class_level_arm_ids' => ['nullable', 'array'],
            'class_level_arm_ids.*' => ['string'],
            'student_ids' => ['nullable', 'array'],
            'student_ids.*' => ['string'],
        ]);

        $category = NoticeCategory::where('uuid', $data['category_id'])->firstOrFail();

        DB::transaction(function () use ($data, $category, $notice) {
            $notice->update([
                'title' => $data['title'],
                'body' => $data['body'],
                'notice_category_id' => $category->id,
                'target_gender' => $data['target_gender'] ?? null,
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'] ?? null,
            ]);

            $clIds = !empty($data['class_level_ids'])
                ? ClassLevel::whereIn('uuid', $data['class_level_ids'])->pluck('id')
                : [];
            $notice->classLevels()->sync($clIds);

            $claIds = !empty($data['class_level_arm_ids'])
                ? ClassLevelArm::whereIn('uuid', $data['class_level_arm_ids'])->pluck('id')
                : [];
            $notice->classLevelArms()->sync($claIds);

            $studentIds = !empty($data['student_ids'])
                ? Student::whereIn('uuid', $data['student_ids'])->pluck('id')
                : [];
            $notice->students()->sync($studentIds);
        });

        $notice->load(['category', 'creator', 'classLevels', 'classLevelArms', 'students']);

        return Response::success(new NoticeResource($notice));
    }

    public function destroy(Notice $notice)
    {
        $notice->delete();

        return response()->noContent();
    }

    public function end(Notice $notice)
    {
        $notice->update(['ends_at' => now()]);

        $notice->load(['category', 'creator', 'classLevels', 'classLevelArms', 'students']);

        return Response::success(new NoticeResource($notice));
    }

    // --- Categories ---

    public function categories()
    {
        $categories = NoticeCategory::orderBy('is_default', 'desc')
            ->orderBy('name')
            ->get();

        return Response::success(NoticeCategoryResource::collection($categories));
    }

    public function storeCategory(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:20'],
        ]);

        $slug = Str::slug($data['name']);

        $exists = NoticeCategory::where('slug', $slug)->exists();
        if ($exists) {
            return response()->json(['message' => 'A category with this name already exists.'], 422);
        }

        $category = NoticeCategory::create([
            'name' => $data['name'],
            'slug' => $slug,
            'color' => $data['color'] ?? 'gray',
            'is_default' => false,
            'school_id' => session('school_id') ?? $request->user()->school_id,
        ]);

        return Response::created(new NoticeCategoryResource($category));
    }

    public function destroyCategory(NoticeCategory $noticeCategory)
    {
        if ($noticeCategory->is_default) {
            return response()->json(['message' => 'Default categories cannot be deleted.'], 422);
        }

        if ($noticeCategory->notices()->exists()) {
            return response()->json(['message' => 'Cannot delete a category that has notices.'], 422);
        }

        $noticeCategory->delete();

        return response()->noContent();
    }

    // --- Guardian endpoint ---

    public function forGuardian(Request $request)
    {
        $user = $request->user();
        $guardian = $user->guardian;

        if (!$guardian) {
            return Response::success([]);
        }

        $students = $guardian->students()
            ->with('currentCurriculum.curriculum.classLevelArm.classLevel')
            ->get();

        if ($students->isEmpty()) {
            return Response::success([]);
        }

        $studentIds = $students->pluck('id')->toArray();

        // Build per-student lookup: map each student's gender to their class/arm IDs
        $studentProfiles = [];
        $classLevelArmIds = [];
        $classLevelIds = [];

        foreach ($students as $student) {
            $profile = ['gender' => $student->gender, 'classLevelId' => null, 'classLevelArmId' => null];
            $curriculum = $student->currentCurriculum;

            if ($curriculum && $curriculum->curriculum && $curriculum->curriculum->classLevelArm) {
                $cla = $curriculum->curriculum->classLevelArm;
                $profile['classLevelArmId'] = $cla->id;
                $classLevelArmIds[] = $cla->id;

                if ($cla->classLevel) {
                    $profile['classLevelId'] = $cla->classLevel->id;
                    $classLevelIds[] = $cla->classLevel->id;
                }
            }

            $studentProfiles[$student->id] = $profile;
        }

        $classLevelArmIds = array_unique($classLevelArmIds);
        $classLevelIds = array_unique($classLevelIds);

        $candidates = Notice::with(['category', 'classLevels', 'classLevelArms', 'students'])
            ->active()
            ->where(function ($query) use ($studentIds, $classLevelIds, $classLevelArmIds) {
                $query
                    ->where(function ($q) {
                        $q->whereDoesntHave('classLevels')
                            ->whereDoesntHave('classLevelArms')
                            ->whereDoesntHave('students');
                    })
                    ->orWhereHas('students', fn ($q) => $q->whereIn('students.id', $studentIds))
                    ->orWhereHas('classLevels', fn ($q) => $q->whereIn('class_levels.id', $classLevelIds))
                    ->orWhereHas('classLevelArms', fn ($q) => $q->whereIn('class_level_arms.id', $classLevelArmIds));
            })
            ->orderBy('starts_at', 'desc')
            ->limit(50)
            ->get();

        // Post-filter: for each notice, check that at least one ward matches
        // both the targeting scope AND the gender filter
        $notices = $candidates->filter(function (Notice $notice) use ($studentProfiles, $studentIds) {
            if (!$notice->target_gender) {
                return true;
            }

            $noticeStudentIds = $notice->students->pluck('id')->toArray();
            $noticeClassLevelIds = $notice->classLevels->pluck('id')->toArray();
            $noticeClassLevelArmIds = $notice->classLevelArms->pluck('id')->toArray();
            $isGlobalTarget = empty($noticeStudentIds) && empty($noticeClassLevelIds) && empty($noticeClassLevelArmIds);

            foreach ($studentProfiles as $sid => $profile) {
                if ($profile['gender'] !== $notice->target_gender) {
                    continue;
                }

                if ($isGlobalTarget) {
                    return true;
                }

                if (in_array($sid, $noticeStudentIds)) {
                    return true;
                }

                if ($profile['classLevelId'] && in_array($profile['classLevelId'], $noticeClassLevelIds)) {
                    return true;
                }

                if ($profile['classLevelArmId'] && in_array($profile['classLevelArmId'], $noticeClassLevelArmIds)) {
                    return true;
                }
            }

            return false;
        })->take(20)->values();

        return Response::success($notices->map(function (Notice $notice) use ($studentIds) {
            $forStudents = $notice->students
                ->filter(fn (Student $student) => in_array($student->id, $studentIds, true))
                ->map(fn (Student $student) => trim("{$student->first_name} {$student->last_name}"))
                ->values();

            return [
                'id' => $notice->uuid,
                'title' => $notice->title,
                'body' => $notice->body,
                'type' => $notice->category?->slug ?? 'general',
                'category' => $notice->category?->name ?? 'General',
                'badge_colour' => $notice->category?->color ?? 'gray',
                'starts_at' => $notice->starts_at?->toIso8601String(),
                'time' => $notice->starts_at?->diffForHumans(),
                'for_students' => $forStudents,
            ];
        }));
    }
}
