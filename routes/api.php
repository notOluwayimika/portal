<?php

use App\Finance\Http\Controllers\PaystackWebhookController;
use App\Http\Controllers\Api\AuthenticationController;
use App\Http\Controllers\ClassLevelArmController;
use App\Http\Controllers\ClassLevelArmProgressionController;
use App\Http\Controllers\ClassLevelProgressionController;
use App\Http\Controllers\CommentBandController;
use App\Http\Controllers\CommentEntryController;
use App\Http\Controllers\CurriculumController;
use App\Http\Controllers\CurriculumSubjectController;
use App\Http\Controllers\ExamTypeController;
use App\Http\Controllers\GradeBoundaryController;
use App\Http\Controllers\GradingSchemeController;
use App\Http\Controllers\GuardianController;
use App\Http\Controllers\HeadOfSchoolController;
use App\Http\Controllers\MarkingComponentController;
use App\Http\Controllers\NoticeController;
use App\Http\Controllers\PrincipalApprovalController;
use App\Http\Controllers\RolloverController;
use App\Http\Controllers\ScholarshipController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\SportHouseController;
use App\Http\Controllers\StudentBulkReassignmentController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentCurriculumController;
use App\Http\Controllers\StudentReassignmentController;
use App\Http\Controllers\StudentSubjectController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\SubjectResultStatusController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\TeacherSchoolAccessController;
use App\Http\Controllers\TermController;
use Illuminate\Support\Facades\Route;

// Authentication (public)
Route::post('/login', [AuthenticationController::class, 'login']);

// Switch active school (session + token); accessible to any authenticated user
Route::middleware('auth:sanctum')->post('/switch-school', [AuthenticationController::class, 'switchSchool']);

// Logout (any authenticated user — C2's one declared access deviation: this
// previously sat inside the admin|head_of_school|form_teacher group, locking
// every other role out of ending its own session).
Route::middleware('auth:sanctum')->post('/logout', [AuthenticationController::class, 'logout']);

// NOTE: the shared read endpoints (sessions, class-structure, exam-types,
// subjects, grade-boundaries, curricula, sport-houses, scholarships, …) used to
// be declared here without auth and re-declared inside the auth group below;
// Laravel matched the first (unauthenticated) declaration, leaking them. They
// now live only inside the authenticated groups.
Route::middleware(['auth:sanctum', 'tenant', 'permission:academic_setup.manage'])->group(function () {
    Route::get('/user', [AuthenticationController::class, 'user']);

    // Dashboard analytics API
    require __DIR__.'/endpoints/dashboard.php';

    // protected session routes
    Route::post('/sessions', [SessionController::class, 'store']);
    Route::put('/sessions/{session:uuid}', [SessionController::class, 'update']);
    Route::delete('/sessions/{session:uuid}', [SessionController::class, 'destroy']);
    Route::post('/sessions/{session:uuid}/current', [SessionController::class, 'setCurrent']);

    // protected class structure (level and arms)
    Route::post('/class-structure', [ClassLevelArmController::class, 'store']);
    Route::patch('/class-structure/{classLevelArm:uuid}', [ClassLevelArmController::class, 'update']);
    Route::delete('/class-structure/{classLevelArm:uuid}', [ClassLevelArmController::class, 'destroy']);
    Route::post('/class-structure/toggle', [ClassLevelArmController::class, 'toggle']);
    Route::post('/class-structure/levels', [ClassLevelArmController::class, 'storeLevel']);
    Route::put('/class-structure/levels/{classLevel:uuid}', [ClassLevelArmController::class, 'updateLevel']);
    Route::delete('/class-structure/levels/{classLevel:uuid}', [ClassLevelArmController::class, 'destroyLevel']);
    Route::post('/class-structure/arms', [ClassLevelArmController::class, 'storeArm']);
    Route::put('/class-structure/arms/{arm:uuid}', [ClassLevelArmController::class, 'updateArm']);
    Route::delete('/class-structure/arms/{arm:uuid}', [ClassLevelArmController::class, 'destroyArm']);
    /*
     * Progression config, per class level (M1). Where a level's pupils go at end of year, which term
     * slots it runs, and which exam types it offers — the tables the migration jobs read and which,
     * until now, could only be populated with SQL.
     *
     * `academic_setup.manage` is the right gate and already exists: this is academic configuration,
     * the same class of act as editing the class structure it sits beside. No new permission — one is
     * not added until a screen checks it.
     *
     * Every segment here is literal before its `{uuid}` wildcard (`/progression`, `/participation`,
     * `/exam-types` all follow `{classLevel:uuid}` rather than competing with it), so none can be
     * swallowed the way `/curricula/queued` was (#277).
     */
    Route::get('/class-levels/{classLevel:uuid}/progression', [ClassLevelProgressionController::class, 'show']);
    Route::put('/class-levels/{classLevel:uuid}/progression', [ClassLevelProgressionController::class, 'update']);
    Route::post('/class-levels/{classLevel:uuid}/participation', [ClassLevelProgressionController::class, 'storeParticipation']);
    /*
     * withoutScopedBindings(): Laravel implicitly SCOPES a nested binding that uses a custom key,
     * resolving the child through a relation named after the parameter (`participations()`), which
     * this model does not have — it is `termParticipation()`. Rather than rename a relation to satisfy
     * route-binding conventions, the binding is left unscoped and the CONTROLLER checks ownership
     * explicitly (`abort_unless($participation->class_level_id === $classLevel->id, 404)`), which is
     * visible, tested, and the same nested-route-integrity idiom StudentCurriculumController uses.
     * Both models are School-scoped, so this closes the remaining same-school mismatch.
     */
    Route::patch('/class-levels/{classLevel:uuid}/participation/{participation:uuid}', [ClassLevelProgressionController::class, 'updateParticipation'])->withoutScopedBindings();
    Route::delete('/class-levels/{classLevel:uuid}/participation/{participation:uuid}', [ClassLevelProgressionController::class, 'destroyParticipation'])->withoutScopedBindings();
    Route::put('/class-levels/{classLevel:uuid}/exam-types', [ClassLevelProgressionController::class, 'syncExamTypes']);

    /*
     * Arm progression map (M2). Which arm of a level feeds which arm of the level its pupils move
     * into — the operator's override, consulted before label matching and distribution.
     *
     * Same `academic_setup.manage` gate as the rest of the progression config, and literal segments
     * after the `{classLevel:uuid}` wildcard so nothing can be swallowed (#277).
     */
    Route::get('/class-levels/{classLevel:uuid}/arm-map', [ClassLevelArmProgressionController::class, 'index']);
    Route::put('/class-levels/{classLevel:uuid}/arm-map', [ClassLevelArmProgressionController::class, 'sync']);
    Route::delete('/class-levels/{classLevel:uuid}/arm-map', [ClassLevelArmProgressionController::class, 'destroyAll']);

    Route::post('/class-structure/streams', [ClassLevelArmController::class, 'storeStream']);
    Route::put('/class-structure/streams/{stream:uuid}', [ClassLevelArmController::class, 'updateStream']);
    Route::delete('/class-structure/streams/{stream:uuid}', [ClassLevelArmController::class, 'destroyStream']);

    // protected exam types routes
    Route::post('/exam-types', [ExamTypeController::class, 'store']);
    Route::put('/exam-types/{examType:uuid}', [ExamTypeController::class, 'update']);
    Route::delete('/exam-types/{examType:uuid}', [ExamTypeController::class, 'destroy']);

    // protected sport house routes
    Route::get('/sport-houses', [SportHouseController::class, 'index']);
    Route::post('/sport-houses', [SportHouseController::class, 'store']);
    Route::put('/sport-houses/{sportHouse:uuid}', [SportHouseController::class, 'update']);
    Route::delete('/sport-houses/{sportHouse:uuid}', [SportHouseController::class, 'destroy']);

    // protected scholarship routes
    Route::get('/scholarships', [ScholarshipController::class, 'index']);
    Route::post('/scholarships', [ScholarshipController::class, 'store']);
    Route::put('/scholarships/{scholarship:uuid}', [ScholarshipController::class, 'update']);
    Route::delete('/scholarships/{scholarship:uuid}', [ScholarshipController::class, 'destroy']);

    // protected subject routes
    Route::post('/subjects', [SubjectController::class, 'store']);
    Route::put('/subjects/{subject:uuid}', [SubjectController::class, 'update']);
    Route::delete('/subjects/{subject:uuid}', [SubjectController::class, 'destroy']);

    // protected grade boundary routes
    Route::post('/grade-boundaries', [GradeBoundaryController::class, 'store']);
    Route::put('/grade-boundaries/{gradeBoundary:uuid}', [GradeBoundaryController::class, 'update']);
    Route::delete('/grade-boundaries/{gradeBoundary:uuid}', [GradeBoundaryController::class, 'destroy']);

    // protected comment routes (score-entry comment suggestions, per school)
    //
    // Entry routes are nested under their PARENT on purpose: CommentEntry has no school_id and no
    // SchoolScope, so the parent is what proves ownership before an entry is touched at all. The
    // controller re-checks that pairing rather than trusting the binding — {gradingSchemeItem} in
    // particular has no SchoolScope to fail closed on.
    Route::get('/comment-bands', [CommentBandController::class, 'index']);
    Route::put('/comment-bands', [CommentBandController::class, 'save']);
    Route::post('/comment-bands/load-defaults', [CommentBandController::class, 'loadDefaults']);
    Route::put('/comment-bands/{commentBand:uuid}/entries/reorder', [CommentEntryController::class, 'reorderOnBand']);
    Route::post('/comment-bands/{commentBand:uuid}/entries', [CommentEntryController::class, 'storeOnBand']);
    Route::put('/comment-bands/{commentBand:uuid}/entries/{entry:uuid}', [CommentEntryController::class, 'updateOnBand'])->withoutScopedBindings();
    Route::delete('/comment-bands/{commentBand:uuid}/entries/{entry:uuid}', [CommentEntryController::class, 'destroyOnBand'])->withoutScopedBindings();

    // Categorical curricula band on a RATING, not a score range, so their comments hang off the
    // grading scheme item. Same controller, same limits, same behaviour — see CommentEntry.
    Route::get('/grading-schemes/{gradingScheme:uuid}/rating-comments', [CommentBandController::class, 'ratingComments']);
    Route::put('/grading-scheme-items/{gradingSchemeItem:uuid}/comments/reorder', [CommentEntryController::class, 'reorderOnRating']);
    Route::post('/grading-scheme-items/{gradingSchemeItem:uuid}/comments', [CommentEntryController::class, 'storeOnRating']);
    Route::put('/grading-scheme-items/{gradingSchemeItem:uuid}/comments/{entry:uuid}', [CommentEntryController::class, 'updateOnRating'])->withoutScopedBindings();
    Route::delete('/grading-scheme-items/{gradingSchemeItem:uuid}/comments/{entry:uuid}', [CommentEntryController::class, 'destroyOnRating'])->withoutScopedBindings();
    Route::get('/grading-schemes', [GradingSchemeController::class, 'index']);
    Route::post('/grading-schemes', [GradingSchemeController::class, 'store']);
    Route::put('/grading-schemes/{gradingScheme:uuid}', [GradingSchemeController::class, 'update']);

    // protected curricula routes
    Route::post('/curricula', [CurriculumController::class, 'store']);
    Route::post('/curricula/{curriculum:uuid}/subjects', [CurriculumController::class, 'assignSubject']);
    Route::put('/curricula/{curriculum:uuid}', [CurriculumController::class, 'update']);
    Route::patch('/curricula/{curriculum:uuid}/subjects/reorder', [CurriculumController::class, 'reorder']);
    Route::delete('/curricula/{curriculum:uuid}', [CurriculumController::class, 'destroy']);

    // protected curriculum subjects routes
    Route::get('/curriculum-subjects/{curriculumSubject:uuid}', [CurriculumSubjectController::class, 'show']);
    Route::post('/curriculum-subjects/{curriculumSubject:uuid}/approve', [CurriculumSubjectController::class, 'approve']);
    Route::post('/curriculum-subjects/{curriculumSubject:uuid}/reject', [CurriculumSubjectController::class, 'reject']);
    Route::patch('/curriculum-subjects/{curriculumSubject:uuid}', [CurriculumSubjectController::class, 'update']);

    Route::post('/curriculum-subjects/{curriculumSubject:uuid}/teachers', [CurriculumSubjectController::class, 'assignTeacher']);
    Route::delete('/curriculum-subjects/{curriculumSubject:uuid}/teachers/{teacher:uuid}', [CurriculumSubjectController::class, 'unassignTeacher'])->withoutScopedBindings();
    Route::delete('/curriculum-subjects/{curriculumSubject:uuid}', [CurriculumSubjectController::class, 'destroy']);
    // get setup data
    Route::get('/setup-data', [SetupController::class, 'index']);

    // protected term routes — guarded by this group's permission:academic_setup.manage.
    //
    // SCOPED bindings, deliberately: {term:uuid} is resolved THROUGH $session->terms(), so a term
    // belonging to another session 404s at the router instead of reaching the controller. These
    // two carried ->withoutScopedBindings(), which let a foreign uuid through to
    // `$session->terms()->find($term->id)` — null — and a 500 on the next line. The controller
    // also uses findOrFail now, so the methods are correct even if this flag is ever removed
    // again; the route is the declarative half, the controller the defensive one.
    Route::post('/sessions/{session:uuid}/terms', [TermController::class, 'store']);
    Route::put('/sessions/{session:uuid}/terms/{term:uuid}', [TermController::class, 'update']);
    Route::delete('/sessions/{session:uuid}/terms/{term:uuid}', [TermController::class, 'destroy']);

    // protected marking components
    Route::put('/marking-components/{markingComponent}', [MarkingComponentController::class, 'update']);
    Route::delete('/marking-components/{markingComponent}', [MarkingComponentController::class, 'destroy']);

    // student curricula
    Route::post('/students/{student:uuid}/curricula/promote', [StudentCurriculumController::class, 'promote']);
    Route::post('/students/{student:uuid}/curricula', [StudentCurriculumController::class, 'register']);
    Route::patch('/student-curricula/{studentCurriculum:uuid}', [StudentCurriculumController::class, 'updateStatus']);

    // Reassignment — moving one placed pupil into a sibling arm (8B -> 8S) after the jobs have run.
    // Gated with promote() above rather than under its own permission: both are operator corrections
    // to where a pupil sits, and academic_setup.manage is the permission the progression screens
    // already carry.
    Route::get('/student-curricula/{studentCurriculum:uuid}/reassignment-options', [StudentReassignmentController::class, 'show']);
    Route::post('/student-curricula/{studentCurriculum:uuid}/reassign', [StudentReassignmentController::class, 'store']);

    // The cohort form of the same correction: every pupil in 9B to 9S in one all-or-nothing batch.
    // Same permission as the single move above — it is the same operation, applied to a selection.
    Route::post('/students/bulk-reassign', [StudentBulkReassignmentController::class, 'store']);
});

// ── M4 · ROLLOVER OPERATOR SURFACE ────────────────────────────────────────────────────────────────
// Its OWN permission, not academic_setup.manage: that gates reversible, one-row config edits and is
// held by roles including form_teacher, while a rollover moves every pupil in the school across a
// year boundary and cannot be undone by re-editing a row. The plan deferred coining it until
// something checked it ("no permission exists until something checks it") — this group is the first
// checker.
Route::middleware(['auth:sanctum', 'tenant', 'permission:academics.rollover'])->group(function () {
    Route::post('/rollover/end-of-term/preview', [RolloverController::class, 'previewEndOfTerm']);
    Route::post('/rollover/end-of-term', [RolloverController::class, 'commitEndOfTerm']);
    Route::post('/rollover/end-of-year/preview', [RolloverController::class, 'previewEndOfYear']);
    Route::post('/rollover/end-of-year', [RolloverController::class, 'commitEndOfYear']);
    // Resolution AT the block: the ccm-active gate names the classes and this folds them. Same
    // permission as the rollover it unblocks — it is a step of that operation, not a separate one.
    Route::post('/rollover/fold-ccm', [RolloverController::class, 'foldCcm']);
    Route::get('/rollover/batches', [RolloverController::class, 'batches']);
});

Route::middleware(['auth:sanctum', 'tenant', 'permission:academic_setup.manage'])->group(function () {

    // student subject management

    // withoutScopedBindings: prevents Laravel auto-scoping {studentCurriculum} to {student}
    // (it would look for $student->studentCurriculums() but the relation is studentCurricula()).
    Route::prefix('students/{student:uuid}/enrollments/{studentCurriculum:uuid}')
        ->withoutScopedBindings()
        ->group(function () {
            // GET subjects + subjects/history are declared in the principal-inclusive
            // read group below; the write routes (store/drop/restore/end) stay here.
            Route::post('subjects', [StudentSubjectController::class, 'store']);
            Route::patch('subjects/{studentSubject:uuid}/drop', [StudentSubjectController::class, 'drop'])->withoutScopedBindings();
            Route::patch('subjects/{studentSubject:uuid}/restore', [StudentSubjectController::class, 'restore']);
            Route::patch('end', [StudentCurriculumController::class, 'unenroll']);
        });

    // curriculum subject archival
    Route::patch('/curriculum-subjects/{curriculumSubject:uuid}/archive', [CurriculumSubjectController::class, 'archive']);
    Route::patch('/curriculum-subjects/{curriculumSubject:uuid}/unarchive', [CurriculumSubjectController::class, 'unarchive']);
    // Stop offering a subject AND withdraw everyone taking it, as one act. Shares
    // `curriculum_subject.archive` with archive() above — it IS an archive, plus the
    // enrollment half archive() was always missing — so no new permission and no RBAC
    // oracle regeneration.
    Route::patch('/curriculum-subjects/{curriculumSubject:uuid}/withdraw', [CurriculumSubjectController::class, 'withdraw']);

    // protected marking components
    Route::get('/marking-components', [MarkingComponentController::class, 'index']);
    Route::post('/marking-components', [MarkingComponentController::class, 'sync']);
    Route::put('/marking-components/{markingComponent}', [MarkingComponentController::class, 'update']);
    Route::delete('/marking-components/{markingComponent}', [MarkingComponentController::class, 'destroy']);

    // subject result status
    Route::get('/subject-result-statuses', [SubjectResultStatusController::class, 'index']);

    require __DIR__.'/endpoints/student.php';
    require __DIR__.'/endpoints/teacher.php';
    require __DIR__.'/endpoints/guardian.php';
    require __DIR__.'/endpoints/head-of-school.php';
});
Route::middleware(['auth:sanctum', 'tenant', 'permission:admin_area.access'])->group(function () {
    // Head of Schools
    Route::get('/heads-of-schools', [HeadOfSchoolController::class, 'index']);
    Route::post('/heads-of-schools', [HeadOfSchoolController::class, 'store']);
    Route::delete('/heads-of-schools/{teacher:uuid}', [HeadOfSchoolController::class, 'destroy']);

    Route::post('/guardians/{guardian:uuid}/password', [GuardianController::class, 'setPassword']);

    // Teacher multi-school access (school_user pivot); admin can only grant
    // schools they themselves can access.
    Route::put('/teachers/{teacher:uuid}/schools', [TeacherSchoolAccessController::class, 'sync']);

    // CCM -> non-CCM curriculum migration
    Route::post('/curricula/{curriculum:uuid}/move-from-ccm', [CurriculumController::class, 'moveFromCcm']);

    // Mirror an active curriculum into a past (completed) term for retroactive entry
    Route::post('/curricula/{curriculum:uuid}/backfill-term', [CurriculumController::class, 'backfillTerm']);

    /*
     * Which curricula already have one of the two migrations above sitting on the queue. The CCM and
     * backfill screens poll this to disable a button they have already pressed.
     *
     * CurriculumController::queuedCurriculums has existed since those screens were written; it simply
     * had no route, so /api/curricula/queued fell through to `GET /curricula/{curriculum:uuid}` in the
     * academic_data.view group below, route-model binding looked for a curriculum whose uuid is the
     * literal string "queued", and both screens showed "Resource not found" — a 404 that reads like a
     * missing record rather than a missing route.
     *
     * ORDER MATTERS, AND IT IS WHY THIS SITS HERE. A literal segment must be registered BEFORE the
     * `{curriculum:uuid}` wildcard or the wildcard swallows it — the same reason
     * `/curricula/active` is declared immediately above `/curricula/{curriculum:uuid}` further down.
     * This group is registered earlier in the file, so the ordering holds.
     *
     * IN THIS GROUP, NOT BESIDE `/curricula/active`, deliberately: it reports the state of the two
     * admin-only migrations above, so it takes their privilege (admin_area.access) rather than the
     * broader academic_data.view that reference reads use.
     */
    Route::get('/curricula/queued', [CurriculumController::class, 'queuedCurriculums']);

    // Teacher role assignments (boarding parent / form teacher / head of school)
    require __DIR__.'/endpoints/teacher-assignment.php';

    // Notices (admin CRUD)
    require __DIR__.'/endpoints/notice.php';
});

Route::middleware(['auth:sanctum', 'tenant', 'permission:principal_approval.manage'])->group(function () {
    Route::patch('/class-levels/{classLevel:uuid}/principal-approval', [PrincipalApprovalController::class, 'classLevel']);
    Route::patch('/class-level-arms/{classLevelArm:uuid}/principal-approval', [PrincipalApprovalController::class, 'classLevelArm']);
});

// Finance (walking skeleton) — manual invoice/payment entry points. Finance-
// specific roles (accounts_officer, …) + maker-checker are Ph2/Ph3; gated on admin
// for now.
Route::middleware(['auth:sanctum', 'tenant', 'permission:finance.access'])->group(function () {
    require __DIR__.'/endpoints/finance.php';
});

Route::middleware(['auth:sanctum', 'tenant', 'permission:academic_data.view'])->group(function () {
    // Shared read data (previously leaked as unauthenticated public routes).
    Route::get('/sessions', [SessionController::class, 'index']);
    Route::get('/sessions/{session:uuid}/terms', [TermController::class, 'index']);
    Route::get('/class-structure', [ClassLevelArmController::class, 'index']);
    Route::get('/class-level-arms', [ClassLevelArmController::class, 'list']);
    Route::get('/exam-types', [ExamTypeController::class, 'index']);
    Route::get('/subjects', [SubjectController::class, 'index']);
    Route::get('/grade-boundaries/{examType:uuid}', [GradeBoundaryController::class, 'index']);
    Route::get('/curricula', [CurriculumController::class, 'index']);
    Route::get('/curricula/active', [CurriculumController::class, 'active']);
    Route::get('/curricula/{curriculum:uuid}', [CurriculumController::class, 'show']);

    // Activity log module (read-only audit feed). Fine-grained access is
    // gated per-endpoint by activity_log.* permissions.
    require __DIR__.'/endpoints/activity-log.php';
});

Route::middleware(['auth:sanctum', 'tenant', 'permission:report.view'])->group(function () {
    // Enrollments failing the result-readiness check (incomplete results)
    Route::get('/results/incomplete', [CurriculumController::class, 'incompleteResults']);

    // Read-only subject listing; feeds the pending-results page, which
    // principals can also see.
    Route::get('/curriculum-subjects', [CurriculumSubjectController::class, 'index']);

    // Broadsheets + outstanding comments (read-only reports)
    require __DIR__.'/endpoints/broadsheet.php';
    require __DIR__.'/endpoints/outstanding-comments.php';
});

Route::middleware(['auth:sanctum', 'tenant', 'permission:score.manage'])->group(function () {
    Route::get('/marking-components/overlapping/{curriculum:uuid}', [MarkingComponentController::class, 'getOverlapping']);
    // comment on student subject
    Route::post('/student-subjects/{studentSubject:uuid}/comment', [StudentSubjectController::class, 'storeComment']);

    // assign score and marking component for teachers;
    Route::get('/teachers/{teacher:uuid}/subjects', [TeacherController::class, 'subjects']);
    Route::get('/teachers/{teacher:uuid}', [TeacherController::class, 'show']);

    // protected curriculum subject
    Route::get('/curriculum-subjects/{curriculumSubject:uuid}/result-status', [CurriculumSubjectController::class, 'getResultStatus']);
    Route::post('/curriculum-subjects/{curriculumSubject:uuid}/marking-components', [CurriculumSubjectController::class, 'assignMarkingComponent']);
    Route::post('/curriculum-subjects/{curriculumSubject:uuid}/scores', [CurriculumSubjectController::class, 'assignScore']);
    // Clearing a score is a DELETE, not a POST of 0. An absent row means "not entered yet" and a
    // stored 0 means "scored zero" — the old clear-by-zero made the second unrecordable. Same
    // group, so it inherits the same ability as entering a score; no new permission.
    Route::delete('/curriculum-subjects/{curriculumSubject:uuid}/scores', [CurriculumSubjectController::class, 'clearScore']);
    Route::post('/curriculum-subjects/{curriculumSubject:uuid}/submit', [CurriculumSubjectController::class, 'submit']);

    // Categorical grading is SCORE ENTRY, not setup — the teacher-facing act of recording a
    // result, exactly like `assignScore` two lines up. It sat under `academic_setup.manage`
    // (alongside approve/reject and the curriculum matrix), which meant a teacher who could enter
    // numeric scores could not record a categorical rating for the same class, while anyone who
    // could edit the academic structure could. `score.manage` is the ability that matches the act.
    Route::put('/curriculum-subjects/{curriculumSubject:uuid}/categorical-results/{student:uuid}', [CurriculumSubjectController::class, 'assignCategoricalResult'])
        ->withoutScopedBindings();

    // Clearing a rating is a DELETE, for the same reason clearing a score is one two
    // lines up: the grid's placeholder option is disabled, so a rating could only ever
    // be overwritten and "not assessed" was unreachable once anything was picked. Same
    // group, so it inherits the same ability as setting the rating; no new permission.
    Route::delete('/curriculum-subjects/{curriculumSubject:uuid}/categorical-results/{student:uuid}', [CurriculumSubjectController::class, 'clearCategoricalResult'])
        ->withoutScopedBindings();
});

Route::middleware(['auth:sanctum', 'tenant', 'permission:student_status.view'])->group(function () {
    // protected guardian routes
    // `guardian_self`: identity, not custody. A parent may read their own guardian
    // row's ward list and no other's; the row is resolved server-side from the
    // acting user and the active School, never trusted from the uuid on the URL.
    Route::get('/guardians/{guardian:uuid}/students', [GuardianController::class, 'students'])
        ->middleware('guardian_self');
    Route::get('/students/{student:uuid}/result-status', [StudentController::class, 'activeResultStatus'])->middleware('guardian_ward');
    Route::get('/students/{student:uuid}/curriculum/{curriculum:uuid}/result-status', [CurriculumController::class, 'activeResultStatus'])->middleware('guardian_ward')->withoutScopedBindings();
});

// Read-only student data also available to principals (oversight role). The
// matching write routes (store/update/delete, subject add/drop/restore) stay in
// their admin-scoped groups above, so principals cannot mutate these records.
Route::middleware(['auth:sanctum', 'tenant', 'permission:student.view'])->group(function () {
    Route::get('/students', [StudentController::class, 'index']);
    Route::get('/students/{student:uuid}', [StudentController::class, 'show']);

    Route::get('students/{student:uuid}/enrollments/{studentCurriculum:uuid}/subjects', [StudentSubjectController::class, 'index'])
        ->withoutScopedBindings();
    Route::get('students/{student:uuid}/enrollments/{studentCurriculum:uuid}/subjects/history', [StudentSubjectController::class, 'history'])
        ->withoutScopedBindings();
});

Route::middleware(['auth:sanctum', 'tenant', 'permission:result.view'])->group(function () {
    Route::get('/curriculum-subjects/{curriculumSubject:uuid}/year-average', [CurriculumSubjectController::class, 'getYearAverage']);
    Route::get('/curriculum-subjects/{curriculumSubject:uuid}/teachers', [CurriculumSubjectController::class, 'getTeachers']);
    Route::get('/student-curricula/{studentCurriculum:uuid}', [StudentCurriculumController::class, 'getTeacherDetails'])->middleware('guardian_ward');
    Route::get('/student-curricula/{studentCurriculum:uuid}/curriculum-subject/{curriculumSubject:uuid}', [StudentCurriculumController::class, 'getScoresWithMarkingComponents'])->middleware('guardian_ward')->withoutScopedBindings();
});

Route::middleware(['auth:sanctum', 'tenant', 'permission:parent_portal.access'])->group(function () {
    Route::get('/guardian/notices', [NoticeController::class, 'forGuardian']);

    // The parent portal's ward list. Gated on the SAME ability as the page that
    // consumes it (`parent/wards`, routes/web.php) — the page previously fed off
    // /api/guardians/{uuid}/students, which sits under `student_status.view`, so a
    // guardian role holding one ability but not the other rendered the page and
    // then silently failed to fill it. Takes no guardian id: see
    // GuardianController::wards.
    Route::get('/parent/wards', [GuardianController::class, 'wards']);
});

/*
 * The parent portal's FINANCE read — what the authenticated guardian's wards owe.
 *
 * ITS OWN GROUP, not folded into the parent_portal group above and emphatically not into the
 * `finance.access` group further down: same ability, separate declaration, so the finance surface a
 * parent reaches is one file that can be read in full rather than a line inside a longer list. The
 * file itself carries the reasoning for the path and the gate.
 */
Route::middleware(['auth:sanctum', 'tenant', 'permission:parent_portal.access'])->group(function () {
    require __DIR__.'/endpoints/parent-finance.php';
});

// Form teachers may record assessments when the school has no boarding
// parents (enforced server-side in ResolvesAssessmentAccess).
Route::middleware(['auth:sanctum', 'tenant', 'permission:assessment.record'])->group(function () {
    require __DIR__.'/endpoints/behavioral-assessment.php';
    require __DIR__.'/endpoints/psychomotor-skill.php';
});

Route::middleware(['auth:sanctum', 'tenant', 'permission:manage_form_teacher_comments'])->group(function () {
    require __DIR__.'/endpoints/form-teacher.php';
});

// Primary's senior comment, mirroring the form-teacher and head-of-school groups
// above: one permission, one prefix, the same shape of controller.
Route::middleware(['auth:sanctum', 'tenant', 'permission:manage_key_stage_coordinator_comments'])->group(function () {
    require __DIR__.'/endpoints/key-stage-coordinator.php';
});

// Notifications (v1 — in-app feed). Required at TOP LEVEL, not inside a
// `permission:` group: reading your own notifications is not a privilege any
// role grants, so the file declares its own middleware and the ownership filter
// in the controller is the authorization. See routes/endpoints/notifications.php.
require __DIR__.'/endpoints/notifications.php';

/*
|--------------------------------------------------------------------------
| Paystack webhook — OUTSIDE every auth group, on purpose
|--------------------------------------------------------------------------
|
| Paystack delivers server-to-server. It has no session, no cookie and no Sanctum token, so this
| route cannot sit inside `auth:sanctum` and cannot carry an ability: there is no user to hold one.
| Its authentication is the `x-paystack-signature` HMAC over the raw body, checked as the first
| statement in the controller, before any lookup and before anything is written.
|
| DELIBERATELY NOT gated on `parent_portal.access` + GuardianPaymentAuthorisation. That pairing is
| the right gate for the INITIALISE route — a parent, in session, starting a payment against an
| invoice they may pay. It is meaningless here and applying it would only guarantee a 401 for every
| genuine delivery. The two halves of the payment path have different callers and different proofs.
|
| NO CSRF: api.php routes do not carry the `web` middleware group, so there is no token to exempt.
| Stated because "add it to the CSRF except list" is the reflex, and doing so would imply this route
| is under a protection it is not under.
|
| THROTTLED, but generously. Paystack retries on a schedule and a burst of legitimate deliveries
| during a fees deadline is expected; the limit is here to bound an unsigned flood, and unsigned
| requests are refused before they touch the database anyway.
*/
Route::post('/webhooks/paystack', PaystackWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.paystack');
