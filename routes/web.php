<?php

use App\Enums\StudentStatusEnum;
use App\Enums\TeacherStatusEnum;
use App\Http\Controllers\AuthenticationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\StudentController;
use App\Http\Resources\ClassLevelArmResource;
use App\Http\Resources\ClassLevelResource;
use App\Http\Resources\CurriculumResource;
use App\Http\Resources\CurriculumSubjectResource;
use App\Http\Resources\GradeBoundaryResource;
use App\Http\Resources\GuardianResource;
use App\Http\Resources\StudentCurriculumResource;
use App\Http\Resources\StudentResource;
use App\Http\Resources\SubjectResultStatusResource;
use App\Http\Resources\TeacherResource;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\GradeBoundary;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\StudentResult;
use App\Models\SubjectResultStatus;
use App\Models\Teacher;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('auth/login', [
        'canResetPassword' => Features::enabled(Features::resetPasswords()),
        'canRegister' => Features::enabled(Features::registration()),
        'status' => session('status'),
    ]);
})->middleware('guest')->name('home');

// Route::get('/cleanup', function () {
//     try {
//         $groups = DB::table('scores')
//             ->select('student_id', 'curriculum_subject_id', DB::raw('COUNT(*) as score_count'))
//             ->groupBy('student_id', 'curriculum_subject_id')
//             ->get();
//         DB::transaction(function () use ($groups) {
//             foreach ($groups as $group) {

//                 // 2. Load curriculum subject with marking components
//                 $curriculumSubject = CurriculumSubject::with('markingComponents')
//                     ->find($group->curriculum_subject_id);

//                 if (!$curriculumSubject) {
//                     continue;
//                 }

//                 $markingCount = $curriculumSubject->markingComponents->count();

//                 // 3. If mismatch, delete StudentResult
//                 if ((int) $group->score_count !== $markingCount) {

//                     StudentResult::where('student_id', $group->student_id)
//                         ->where('curriculum_subject_id', $group->curriculum_subject_id)
//                         ->delete();
//                 }
//             }
//         });
//         return response()->json(['message' => 'Cleanup completed successfully']);
//     } catch (\Throwable $th) {
//         return response()->json(['message' => 'Error occurred while cleaning up', 'error' => $th->getMessage()], 500);
//     }


// });

Route::middleware(['auth', 'tenant', 'role:admin'])->group(function () {

    Route::get('/setup/head-of-schools', function () {
        return Inertia::render('admin/head-of-schools/index');
    })->name('admin.dashboard');

    Route::get('/setup/teacher-assignments', function () {
        return Inertia::render('admin/teacher-assignments/index');
    })->name('admin.teacher-assignments');

    Route::inertia('school-setup', 'admin/SchoolSetup')->name('school.setup');
    Route::inertia('setup', 'admin/school-setup')->name('setup');

    // Route::get('setup/')
    Route::get('setup/curricula-ccm', function () {
        return Inertia::render('admin/curricula/ccm');
    })->name('setup.curricula.ccm');

    Route::get('setup/curricula/{curriculum:uuid}', function (Curriculum $curriculum) {
        return Inertia::render('admin/curriculum/show', [
            'curriculum' => new CurriculumResource($curriculum),
        ]);
    })->name('setup.curricula.show');

    // Students
    Route::get('students', function () {
        return Inertia::render('admin/students/index', [
            'student_statuses' => StudentStatusEnum::options()
        ]);
    })->name('students.index');

    Route::get('students/bulk-update', function () {
        return Inertia::render('admin/students/bulk-update');
    })->name('students.bulk-update');

    Route::get('students/{student:uuid}', function (Student $student) {
        $student->load([
            'photoFile',
            'currentCurriculum.curriculum.classLevelArm.classLevel',
            'currentCurriculum.curriculum.classLevelArm.arm',
            'currentCurriculum.curriculum.classLevelArm.stream',
            'currentCurriculum.curriculum.term',
            'guardians.user',
            'guardians.photoFile',
            'studentCurricula.curriculum.classLevelArm.classLevel',
            'studentCurricula.curriculum.classLevelArm.arm',
            'studentCurricula.curriculum.term',
        ]);

        return Inertia::render('admin/students/show', [
            'student' => new StudentResource($student),
            'student_statuses' => StudentStatusEnum::options(),
        ]);
    })->name('students.show');

    // Teachers
    Route::get('teachers', function () {
        return Inertia::render('admin/teachers/index', [
            'teacher_statuses' => TeacherStatusEnum::options(),
        ]);
    })->name('teachers.index');


    // Guardian index
    Route::get('guardians', function () {
        return Inertia::render('admin/guardians/index', [
            'guardian_statuses' => \App\Enums\GuardianStatusEnum::options(),
        ]);
    })->name('guardians.index');

    // Bulk guardian import (must come before /{guardian:uuid} so it isn't shadowed).
    Route::get('guardians/import', function () {
        return Inertia::render('admin/guardians/import');
    })->name('guardians.import');

    Route::get('notices', function () {
        return Inertia::render('admin/notices/index');
    })->name('notices.index');

    // Activity log (read-only audit feed). Per-action access is gated by
    // activity_log.* permissions in the API layer.
    Route::get('activity-logs', function () {
        return Inertia::render('admin/activity-logs/index');
    })->name('activity-logs.index');

    Route::get('activity-logs/{id}', function (string $id) {
        return Inertia::render('admin/activity-logs/index', ['initialActivityId' => $id]);
    })->whereNumber('id')->name('activity-logs.show');

    // Guardian profile
    Route::get('guardians/{guardian:uuid}', function (Guardian $guardian) {
        $guardian->load([
            'user',
            'photoFile',
            'students.photoFile',
            'students.currentCurriculum.curriculum.classLevelArm.classLevel',
            'students.currentCurriculum.curriculum.classLevelArm.arm',
            'students.currentCurriculum.curriculum.term',
        ]);
        return Inertia::render('admin/guardians/show', [
            'guardian' => new GuardianResource($guardian),
        ]);
    })->name('guardians.show');

    // Guardian audit history
    Route::get('guardians/{guardian:uuid}/audit', function (Guardian $guardian) {
        $guardian->load(['user']);
        return Inertia::render('admin/guardians/audit', [
            'guardian' => new GuardianResource($guardian),
        ]);
    })->name('guardians.audit');


    Route::post('students', [App\Http\Controllers\StudentController::class, 'store']);
    Route::put('students/{student:uuid}', [App\Http\Controllers\StudentController::class, 'update']);


});

Route::middleware(['auth', 'tenant', 'role:admin|head_of_school'])->group(function () {

    Route::get('outstanding-comments', function () {
        return Inertia::render('admin/outstanding-comments/index');
    })->name('outstanding-comments.index');



    // Review results
    Route::get('setup/review/results', function () {
        return Inertia::render('admin/review/index');
    })->name('setup.review.results');
    Route::get('setup/review/pending', function () {
        return Inertia::render('admin/review/pending');
    })->name('setup.review.pending');

    Route::prefix('reports')->group(function () {
        Route::get('results-per-class', function () {
            $schoolId = auth()->user()->school_id;
            $classLevels = ClassLevel::where('school_id', $schoolId)->get();
            return Inertia::render('reports/results-per-class', [
                'classLevels' => ClassLevelResource::collection($classLevels)
            ]);
        })->name('reports.result-per-class');

        Route::get('broadsheets', function () {
            $schoolId = auth()->user()->school_id;
            $classLevels = ClassLevel::where('school_id', $schoolId)->get();
            return Inertia::render('reports/broadsheets', [
                'classLevels' => ClassLevelResource::collection($classLevels)
            ]);
        })->name('reports.broadsheets');
    });



});

Route::middleware(['auth', 'tenant', 'role:admin|head_of_school|teacher|guardian'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'show'])->name('dashboard');
    Route::post('dashboard/refresh', [DashboardController::class, 'refresh'])->middleware('throttle:1,1')->name('dashboard.refresh');
    Route::get('dashboard/onboarding', [DashboardController::class, 'onboardingState'])->name('dashboard.onboarding');

    Route::get('setup/teacher/{teacher:uuid}', function (Teacher $teacher) {
        return Inertia::render('teacher/show', [
            'teacher' => new TeacherResource($teacher),
        ]);
    })->name('setup.teachers.show');

    Route::get('setup/curriculum-subject/{curriculumSubject:uuid}', function (CurriculumSubject $curriculumSubject) {
        $curriculumSubject->load([
            'curriculum',
            'subject',
            'markingComponents',
            'scores.student',
            'scores.markingComponent',
            'studentAssignments' => function ($query) {
                $query->where('status', 'active')
                    ->with('studentCurriculum.student');
            },
            'resultStatus',
        ]);
        return Inertia::render('curriculum-subject/show', [
            'curriculumSubject' => new CurriculumSubjectResource($curriculumSubject),
        ]);
    })->name('setup.curriculumSubjects.show');

    // student curricula
    Route::get('setup/student-curricula/{student:uuid}', function (Student $student) {
        $student->load(['studentCurricula.curriculum.examType', 'studentCurricula.curriculum.classLevelArm.classLevel', 'studentCurricula.curriculum.academicSession', 'studentCurricula.promotedTo', 'studentCurricula.curriculum.term']);

        return Inertia::render('admin/student-curricula/index', [
            'student' => new StudentResource($student),
        ]);
    })->name('setup.studentCurricula.index');

    Route::get('setup/student-curricula/{studentCurriculum:uuid}/subjects', function (StudentCurriculum $studentCurriculum) {
        $studentCurriculum->load(['student']);

        return Inertia::render('admin/student-curricula/show', [
            'student' => new StudentResource($studentCurriculum->student),
            'studentCurriculum' => new StudentCurriculumResource($studentCurriculum),
        ]);
    })->name('setup.studentCurricula.index');


});

Route::middleware(['auth', 'tenant', 'role:guardian|admin|head_of_school'])->group(function () {


    Route::get('class-level/{classLevel:uuid}/results', function (ClassLevel $classLevel) {
        ini_set('memory_limit', '512M');
        $start = microtime(true);
        $classLevelArms = ClassLevelArm::where('class_level_id', $classLevel->id)->get();
        // $classLevelArms->load([
        //     'curricula.curriculumSubjects',
        //     'curricula.studentCurricula.studentSubjects' => function ($query) {
        //         $query->where('status', 'active');
        //     },
        //     'curricula.studentCurricula.studentSubjects.curriculumSubject.studentResults.student',
        //     'curricula.studentCurricula.studentSubjects.curriculumSubject.resultStatus',
        //     'curricula.studentCurricula.studentSubjects.curriculumSubject.subject',
        //     'curricula.studentCurricula.student',
        //     'curricula.studentCurricula.curriculum.examType.gradeBoundaries',
        //     'curricula.studentCurricula.curriculum.term',

        // ]);
        $classLevelArms = ClassLevelArm::with([
            'curricula.curriculumSubjects',
            'curricula.studentCurricula.studentSubjects' => function ($query) {
                $query->where('status', 'active');
            },
            'curricula.studentCurricula.studentSubjects.curriculumSubject.subject',
            'curricula.studentCurricula.studentSubjects.curriculumSubject.studentResults' => function ($query) {
                $query->select([
                    'id',
                    'curriculum_subject_id',
                    'student_id',
                    'total_score',
                    'grade',
                ]);
            },
            'curricula.studentCurricula.studentSubjects.curriculumSubject.studentResults.student:id,uuid,first_name,middle_name,last_name',
            'curricula.studentCurricula.studentSubjects.curriculumSubject.resultStatus',
            'curricula.studentCurricula.student',
            'curricula.studentCurricula.curriculum.examType.gradeBoundaries',
            'curricula.studentCurricula.curriculum.term',
        ])
            ->where('class_level_id', $classLevel->id)
            ->get();
        logger()->info([
            'arms' => $classLevelArms->count(),
            'curricula' => $classLevelArms->pluck('curricula')->flatten()->count(),
            'student_curricula' => $classLevelArms
                ->pluck('curricula')
                ->flatten()
                ->pluck('studentCurricula')
                ->flatten()
                ->count(),
        ]);


        $data = ClassLevelArmResource::collection($classLevelArms);

        logger()->info([
            'resource_time' => microtime(true) - $start,
        ]);

        $defaultGradeBoundaries = GradeBoundary::where('exam_type_id', null)->get();
        return Inertia::render('student/results/list', [
            'classLevelArms' => ClassLevelArmResource::collection($classLevelArms),
            'defaultGradeBoundaries' => GradeBoundaryResource::collection($defaultGradeBoundaries)
        ]);
    })->name('setup.classLevels.show');
    Route::get('students/{student:uuid}/results/active', function (Student $student) {
        $studentCurricula = StudentCurriculum::with([
            'curriculum.examType.gradeBoundaries',
            'curriculum.term',
            'studentSubjects' => function ($query) {
                $query->where('status', 'active');
            },

            'studentSubjects.curriculumSubject.studentResults.student',
            'studentSubjects.curriculumSubject.resultStatus',
            'studentSubjects.curriculumSubject.subject',
        ])
            ->where('student_id', $student->id)
            ->where('status', 'active')
            ->get();
        $defaultGradeBoundaries = GradeBoundary::where('exam_type_id', null)->get();

        if (auth()->user()->hasRole('guardian')) {

            $studentCurricula = $studentCurricula->filter(function ($studentCurriculum) {

                $deadline = $studentCurriculum?->curriculum?->term?->result_visible_at;
                if (is_null($deadline)) {
                    return true;
                }
                if ($studentCurriculum->status !== StudentStatusEnum::ACTIVE) {
                    return true;
                }

                return now()->greaterThan($deadline);
            })->values();
        }
        return Inertia::render('student/results/active', [
            'student' => new StudentResource($student),
            'studentCurricula' => StudentCurriculumResource::collection($studentCurricula),
            'defaultGradeBoundaries' => GradeBoundaryResource::collection($defaultGradeBoundaries)
        ]);
    })->name('admin.dashboard');
    Route::get('students/{student:uuid}/results/{studentCurriculum:uuid}', function (Student $student, StudentCurriculum $studentCurriculum) {
        $studentCurricula = StudentCurriculum::with([
            'curriculum.examType.gradeBoundaries',
            'studentSubjects' => function ($query) {
                $query->where('status', 'active');
            },
            'studentSubjects.curriculumSubject.studentResults.student',
            'studentSubjects.curriculumSubject.resultStatus',
            'studentSubjects.curriculumSubject.subject',
        ])
            ->where('student_id', $student->id)
            ->where('id', $studentCurriculum->id)
            ->get();
        $defaultGradeBoundaries = GradeBoundary::where('exam_type_id', null)->get();
        if (auth()->user()->hasRole('guardian')) {

            $studentCurricula = $studentCurricula->filter(function ($studentCurriculum) {

                $deadline = $studentCurriculum?->curriculum?->term?->result_visible_at;
                if (is_null($deadline)) {
                    return true;
                }
                if ($studentCurriculum->status !== StudentStatusEnum::ACTIVE) {
                    return true;
                }

                return now()->greaterThan($deadline);
            })->values();
        }
        return Inertia::render('student/results/active', [
            'student' => new StudentResource($student),
            'studentCurricula' => StudentCurriculumResource::collection($studentCurricula),
            'defaultGradeBoundaries' => GradeBoundaryResource::collection($defaultGradeBoundaries)
        ]);
    })->name('admin.dashboard')->withoutScopedBindings();
});

Route::middleware(['auth', 'tenant', 'role:guardian'])->group(function () {
    Route::get('parent/dashboard', function () {
        return redirect()->route('parent.wards');
        return Inertia::render('parent/dashboard');
    })->name('parent.dashboard');
    Route::get('parent/wards', function () {
        return Inertia::render('parent/wards');
    })->name('parent.wards');
});

Route::middleware(['auth', 'tenant', 'role:boarding_parent'])->group(function () {
    Route::get('boarding-parent/behavioral-assessments', function () {
        return Inertia::render('boarding-parent/behavioral-assessments/index');
    })->name('boarding-parent.behavioral-assessments');
});

Route::middleware(['auth', 'tenant', 'role:form_teacher'])->group(function () {
    Route::get('form-teacher/comments', function () {
        return Inertia::render('form-teacher/comments/index');
    })->name('form-teacher.comments');
});

Route::middleware(['auth', 'tenant', 'role:admin|head_of_school'])->group(function () {
    Route::get('head-of-school/comments', function () {
        return Inertia::render('head-of-school/comments/index');
    })->name('head-of-school.comments');
});


require __DIR__ . '/settings.php';
