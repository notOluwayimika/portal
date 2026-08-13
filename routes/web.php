<?php

use App\Enums\GuardianStatusEnum;
use App\Enums\Permission;
use App\Enums\StudentStatusEnum;
use App\Enums\TeacherStatusEnum;
use App\Finance\Console\ImportOpeningBalances;
use App\Finance\Exports\OpeningBalanceImportTemplateExport;
use App\Http\Controllers\ClassResultsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\PrincipalController;
use App\Http\Controllers\ResultSignatureController;
use App\Http\Controllers\SchoolSwitchController;
use App\Http\Controllers\SchoolUserController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\SuperAdmin\AdminController as SuperAdminAdminController;
use App\Http\Controllers\SuperAdmin\RbacMatrixController as SuperAdminRbacMatrixController;
use App\Http\Controllers\SuperAdmin\SchoolController as SuperAdminSchoolController;
use App\Http\Resources\ClassLevelResource;
use App\Http\Resources\CommentBandResource;
use App\Http\Resources\CurriculumResource;
use App\Http\Resources\CurriculumSubjectResource;
use App\Http\Resources\GradeBoundaryResource;
use App\Http\Resources\GuardianResource;
use App\Http\Resources\StudentCurriculumResource;
use App\Http\Resources\StudentResource;
use App\Http\Resources\TeacherResource;
use App\Models\ClassLevel;
use App\Models\CommentBand;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\GradeBoundary;
use App\Models\Guardian;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\StudentResult;
use App\Models\Teacher;
use App\Models\Term;
use App\Services\ResultSignatureService;
use App\Support\ActiveSchool;
use App\Support\ApprovalAbility;
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

// School selection / switching (any authenticated user)
Route::middleware('auth')->group(function () {
    Route::get('/select-school', [SchoolSwitchController::class, 'show'])->name('school.select');
    Route::post('/select-school', [SchoolSwitchController::class, 'switch'])->name('school.switch');
});

// Impersonation (ADR 0045). START is platform-admin gated; the controller
// re-checks flag-independently because `permission:` resolves through the
// Gate, which the super-admin bypass answers regardless of the grant.
Route::middleware(['auth', 'role:super_admin', 'permission:rbac.impersonate'])
    ->post('/impersonation', [ImpersonationController::class, 'store'])
    ->name('impersonation.store');

// STOP carries `auth` and NOTHING ELSE, deliberately. Inside a session the
// acting user IS the target, who holds neither the role nor the permission —
// any authorization here reading the acting user's grants would 403 the
// operator and strand them inside the session. The session's own existence is
// the authorization, checked in the controller.
Route::middleware('auth')
    ->delete('/impersonation', [ImpersonationController::class, 'destroy'])
    ->name('impersonation.destroy');

// Super admin area (manage schools + admins)
Route::middleware(['auth', 'role:super_admin'])->prefix('super-admin')->group(function () {
    Route::redirect('/', '/super-admin/schools')->name('super-admin.home');

    Route::get('/schools', [SuperAdminSchoolController::class, 'index'])->name('super-admin.schools');
    Route::post('/schools', [SuperAdminSchoolController::class, 'store'])->name('super-admin.schools.store');
    Route::put('/schools/{school:uuid}', [SuperAdminSchoolController::class, 'update'])->name('super-admin.schools.update');
    Route::post('/schools/{school:uuid}/fallback-signature', [SuperAdminSchoolController::class, 'updateFallbackSignature'])->name('super-admin.schools.fallback-signature.update');
    Route::delete('/schools/{school:uuid}/fallback-signature', [SuperAdminSchoolController::class, 'destroyFallbackSignature'])->name('super-admin.schools.fallback-signature.destroy');

    Route::get('/admins', [SuperAdminAdminController::class, 'index'])->name('super-admin.admins');
    Route::post('/admins', [SuperAdminAdminController::class, 'store'])->name('super-admin.admins.store');
    Route::put('/admins/{uuid}/schools', [SuperAdminAdminController::class, 'syncSchools'])->name('super-admin.admins.schools');

    // RBAC matrix (C6): site-wide role→permission grants. Edits GRANTS only —
    // the enum is code; roles/permissions are not creatable at runtime. The
    // role travels by NAME (roles are global rows; names are the stable key).
    Route::get('/rbac', [SuperAdminRbacMatrixController::class, 'index'])->name('super-admin.rbac');
    Route::put('/rbac/roles/{roleName}/permissions', [SuperAdminRbacMatrixController::class, 'syncPermissions'])->name('super-admin.rbac.sync');
    Route::put('/rbac/roles/{roleName}/two-factor', [SuperAdminRbacMatrixController::class, 'toggleTwoFactor'])->name('super-admin.rbac.two-factor');
});

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

// RBAC administration (C5): the school-admin Users module — list this School's
// users and sync their roles. Gated on its own permission (D5), held by `admin`;
// super_admin reaches it via the Gate::before bypass. Separate group so the page
// and its write share one authorization boundary.
Route::middleware(['auth', 'tenant', 'permission:rbac.manage_users'])->group(function () {
    Route::get('/setup/users', [SchoolUserController::class, 'index'])->name('setup.users.index');
    Route::put('/setup/users/{user:uuid}/roles', [SchoolUserController::class, 'syncRoles'])->name('setup.users.roles.sync');
});

// Finance bursar/admin UI — the page shell only; all data is fetched client-side from
// /api/v1/finance/*. Gated on finance.access (the same permission the API group requires),
// so a user who cannot read the API never lands on a page that would 403 on load. Issuing
// a credit note needs finance.credit-note.issue too — enforced by the API (the <Can> gate
// on the button is convenience, not the guard).
Route::middleware(['auth', 'tenant', 'permission:finance.access'])->group(function () {
    // The bursar landing page — the accounts index. Data (accounts + KPIs) is fetched
    // client-side from /api/v1/finance/accounts; this is only the page shell.
    Route::get('/finance', fn () => Inertia::render('admin/finance/index'))
        ->name('admin.finance.index');

    // Bank accounts (S6/U3 commit 1) — the school's own banking configuration. Gated on
    // finance.bank-account.manage IN ADDITION to the group's finance.access, so the page is not
    // reachable by everyone who can view finance: it is configuration, and the nav entry keys on the
    // same permission so a menu item can never render for someone the route would refuse.
    Route::get('/finance/bank-accounts', fn () => Inertia::render('admin/finance/bank-accounts'))
        ->middleware('permission:finance.bank-account.manage')
        ->name('admin.finance.bank-accounts');

    /*
     * The fee-schedules screen (U1 commit 2) — where a school's prices are authored.
     *
     * Gated on `finance.fee-schedule.manage` IN ADDITION to the group's finance.access, the
     * bank-accounts precedent above and the same reasoning: this is AUTHORING, not viewing.
     * Everyone who can read finance must not be offered a screen that sets prices. The nav entry
     * keys on the same ability so a visible item can never 403 on click.
     *
     * TERMS AND CLASS LEVELS ARE PROPS, NOT A FETCH — the same reason the opening-balance operator
     * screen below carries its terms as props: the only API listing terms is gated on
     * `academic_data.view`, an ability the finance seat does not hold. Widening that seat or coining
     * a finance-side terms endpoint are each a bigger change than the screen.
     *
     * BANK ACCOUNTS ARE NOT PROPS, and that is a decision rather than an omission. The picker
     * fetches GET /api/v1/finance/bank-accounts, gated on `finance.bank-account.manage` — a
     * DIFFERENT ability from the one gating this page. Props are for data the seat CANNOT fetch;
     * accounts are data it can, and a second source for them is the drift shape. The implicit
     * coupling that makes the fetch safe is asserted rather than assumed — see the grants arm in
     * tests/Feature/Finance/FeeSchedulesScreenTest.php.
     */
    Route::get('/finance/fee-schedules', function () {
        // `->id`, NOT the model — see the opening-balance route below, where binding the School
        // MODEL into `where('school_id', …)` matched nothing and rendered an EMPTY term select that
        // every test still passed. That scar is not being reopened one screen over.
        $schoolId = ActiveSchool::getOrFail()->id;

        return Inertia::render('admin/finance/fee-schedules', [
            'terms' => Term::query()
                ->where('school_id', $schoolId)
                ->with('academicSession')
                ->orderByDesc('id')
                ->get()
                // Term::displayLabel() — the METHOD, not a fourth copy of the expression. The same
                // string the opening-balance term select, the fee-schedules list and the approvals
                // queue name a term by; two screens naming one term differently is how an operator
                // picks the wrong one.
                ->map(fn (Term $term) => ['id' => $term->id, 'label' => $term->displayLabel()])
                ->values(),
            'class_levels' => ClassLevel::query()
                ->where('school_id', $schoolId)
                ->orderBy('order')
                ->get()
                ->map(fn (ClassLevel $level) => ['id' => $level->id, 'name' => $level->name])
                ->values(),
        ]);
    })
        ->middleware('permission:finance.fee-schedule.manage')
        ->name('admin.finance.fee-schedules');

    /*
     * The discount-policies screen (U2) — where the discount catalog is authored.
     *
     * Gated on `finance.discount-policy.change.submit` IN ADDITION to the group's finance.access,
     * following Bank accounts and Fee schedules: this is CONFIGURATION, and everyone who can read
     * finance must not be offered a screen that defines what may be taken off a bill.
     *
     * THE SPLIT THAT BIT U1 CANNOT HAPPEN HERE, and that is a fact about the permission catalog rather
     * than a hope. Fee schedules has two abilities — `manage` opens the screen and
     * `fee-schedule.change.submit` sends a proposal — so a seat holding the first without the second
     * gets a page whose buttons 403 (FeeSchedulesScreenTest pins the gate that fixes it). Discount
     * policies has NO `manage` ability at all: the three cases are change.submit / change.approve /
     * change.reject (App\Enums\Permission:178-180), nothing on this page writes a policy directly, and
     * every control on it posts the SAME endpoint the page gate names. Page gate and button gate are
     * therefore one permission by construction, so the page asks once, here.
     *
     * NO PROPS. Unlike fee schedules there is nothing here the seat cannot fetch: the catalog comes
     * from GET /api/v1/finance/discount-policies, which carries only the group's finance.access —
     * held by every role in grantsMap() that holds this route's ability (asserted in
     * tests/Feature/Finance/DiscountPoliciesScreenTest.php, not assumed).
     */
    Route::get('/finance/discount-policies', fn () => Inertia::render('admin/finance/discount-policies'))
        ->middleware('permission:finance.discount-policy.change.submit')
        ->name('admin.finance.discount-policies');

    Route::get('/finance/students/{student:uuid}/statement', function (Student $student) {
        return Inertia::render('admin/finance/statement', [
            'student' => ['uuid' => $student->uuid, 'name' => $student->full_name],
        ]);
    })->name('admin.finance.statement');

    // The checker's pending-approvals queue (Ph3 + Ph3b). VISIBILITY (who may open the queue) is
    // separated from AUTHORITY (who may approve a given ROW — the per-row can_approve): the page
    // is gated on holding ANY finance CHECKER ability, not the credit-note one specifically. The
    // set is DERIVED from the ApprovalAbility convention over the Permission catalog, so a future
    // instance (refunds' finance.refund.approve) auto-joins the moment its permission exists — no
    // edit here. Before this (D1, found by the drive) a void-only checker got a full-page 403 and
    // the Ph3b per-feed 403-tolerance never ran. super_admin stays excluded exactly as on every
    // other checker surface (ADR 0040 bypass-exclusion / ADR 0045 no ambient domain grants) — the
    // route's pre-fix behaviour for them is preserved; the derived gate below carries the same
    // exclusion because each listed ability is a checker action.
    $financeCheckerAbilities = implode('|', array_values(array_filter(
        array_map(fn (Permission $case) => $case->value, Permission::cases()),
        fn (string $ability) => str_starts_with($ability, 'finance.')
            && ApprovalAbility::isExcludedFromSuperAdminBypass($ability),
    )));

    Route::get('/finance/approvals', fn () => Inertia::render('admin/finance/approvals'))
        ->middleware('permission:'.$financeCheckerAbilities)
        ->name('admin.finance.approvals');

    /*
     * The opening-balance operator screen — U12b (§9 step 5b-iii). Gated on the MAKER ability, the
     * same one that gates the template and the upload: this is where a bursar-office operator brings
     * a school's closing position across from WCBS. A checker does not need it, and `finance.access`
     * alone must not reach it.
     *
     * THE TERMS ARE PROPS, NOT A FETCH. The form needs the term being CLOSED OUT, and the only API
     * that lists terms is gated on `academic_data.view` — an ability the finance maker seat does not
     * hold. Passing them from the route avoids either widening that seat or coining a finance-side
     * terms endpoint, both of which would be a bigger change than the screen.
     *
     * Scoped by the active School twice over, and the explicit `where` is the REDUNDANT one. This
     * comment used to say `terms` "is not a BelongsToSchool model, so this one is written rather than
     * inherited" — false since before this branch (`git show 59e1da8:app/Models/Term.php` line 16 is
     * `use BelongsToSchool, LogsActivity;`), which means SchoolScope already bounds this query. The
     * `where` is kept rather than deleted because the route runs inside `tenant` and an explicit
     * predicate on a props query is readable at the call site: the next person to read this closure can
     * see what the select is bounded by without going to the model to find out.
     */
    Route::get('/finance/opening-balances/import', function () {
        // `->id`, NOT the model. `ActiveSchool::getOrFail()` returns a School (ActiveSchool.php:66)
        // while `id()` returns an int, and binding the MODEL into a `where` on `school_id` matched
        // nothing and rendered the form with an EMPTY term select — a screen that looks fine and
        // cannot be submitted. Every test still passed, because they assert the page renders. The
        // browser drive is what found it; the arm in OpeningBalanceOperatorScreenTest asserting the
        // prop is populated is what keeps it found.
        $terms = Term::query()
            ->where('school_id', ActiveSchool::getOrFail()->id)
            ->with('academicSession')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Term $term) => [
                'id' => $term->id,
                // Term::displayLabel() — the same method the fee-schedules list and the approvals queue
                // read. This expression used to live here in full, and was copied twice.
                'label' => $term->displayLabel(),
            ])
            ->values();

        // THE COLUMNS AND NOTES SHEETS, MOVED ONTO THE SCREEN. The template is now a single-sheet
        // CSV — it cannot carry a format reference — and the rules it used to carry are the ones
        // behind the expensive failures. A rule that lives only in a document is a rule the person
        // filling in the file never sees (spec, Commit 4).
        //
        // BOTH ARE READ FROM THE SAME CONSTANTS THE TEMPLATE RENDERS, so there is still exactly one
        // source of truth for the format: `ImportOpeningBalances::COLUMNS` (the public alias of the
        // validator's own map) and the export's `NOTES`. No second representation is introduced —
        // the screen is a third READER of the map, not a copy of it.
        $columns = collect(ImportOpeningBalances::COLUMNS)
            ->map(fn (array $meta, string $column) => [
                'column' => $column,
                'group' => $meta['group'],
                'required' => $meta['required'],
                'format' => $meta['format'],
                'example' => $meta['example'],
                'notes' => $meta['notes'],
            ])
            ->values();

        $notes = collect(OpeningBalanceImportTemplateExport::NOTES)
            ->map(fn (array $note) => ['rule' => $note[0], 'meaning' => $note[1]])
            ->values();

        return Inertia::render('admin/finance/opening-balances/import', [
            'terms' => $terms,
            'columns' => $columns,
            'notes' => $notes,
        ]);
    })
        ->middleware('permission:finance.opening-balance.submit')
        ->name('admin.finance.opening-balances.import');
});

Route::middleware(['auth', 'tenant', 'permission:admin_area.access'])->group(function () {
    Route::get('/setup/principals', [PrincipalController::class, 'index'])->name('principals.index');
    Route::post('/setup/principals', [PrincipalController::class, 'store'])->name('principals.store');
    Route::delete('/setup/principals/{principal:uuid}', [PrincipalController::class, 'destroy'])->name('principals.destroy');

    Route::get('/setup/head-of-schools', function () {
        return Inertia::render('admin/head-of-schools/index');
    })->name('headOfSchools.index');

    Route::get('/setup/teacher-assignments', function () {
        return Inertia::render('admin/teacher-assignments/index');
    })->name('admin.teacher-assignments');

    Route::inertia('school-setup', 'admin/SchoolSetup')->name('school.setup');
    Route::inertia('setup', 'admin/setup')->name('setup');

    // Route::get('setup/')
    Route::get('setup/curricula-ccm', function () {
        return Inertia::render('admin/curricula/ccm');
    })->name('setup.curricula.ccm');

    Route::get('setup/curricula-backfill', function () {
        return Inertia::render('admin/curricula/backfill');
    })->name('setup.curricula.backfill');

    Route::get('setup/curricula/{curriculum:uuid}', function (Curriculum $curriculum) {
        return Inertia::render('admin/curriculum/show', [
            'curriculum' => new CurriculumResource($curriculum),
        ]);
    })->name('setup.curricula.show');

    // Students (write-oriented; index + profile view live in the admin|principal
    // group below so principals get read-only access).
    Route::get('students/bulk-update', function () {
        return Inertia::render('admin/students/bulk-update');
    })->name('students.bulk-update');

    // Teachers
    Route::get('teachers', function () {
        return Inertia::render('admin/teachers/index', [
            'teacher_statuses' => TeacherStatusEnum::options(),
        ]);
    })->name('teachers.index');

    // Guardian index
    Route::get('guardians', function () {
        return Inertia::render('admin/guardians/index', [
            'guardian_statuses' => GuardianStatusEnum::options(),
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

    // Notification queue health. The page itself renders for anyone who can
    // reach this group; its DATA endpoint is gated on `activity_log.view_system`
    // (see NotificationQueueHealthController for why that permission is reused
    // rather than a new one minted in v1).
    Route::get('notifications/queue-health', function () {
        return Inertia::render('admin/notifications/queue-health');
    })->name('notifications.queue-health');

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

    Route::post('students', [StudentController::class, 'store']);
    Route::put('students/{student:uuid}', [StudentController::class, 'update']);

});

// Read-only student index + profile. Principals (oversight role) share this with
// admins; write controls are hidden in the UI and their API routes exclude principal.
Route::middleware(['auth', 'tenant', 'permission:student_directory.view'])->group(function () {
    Route::get('students', function () {
        return Inertia::render('admin/students/index', [
            'student_statuses' => StudentStatusEnum::options(),
        ]);
    })->name('students.index');

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
});

Route::middleware(['auth', 'tenant', 'permission:result_review.access'])->group(function () {

    // Review results
    Route::get('setup/review/results', function () {
        return Inertia::render('admin/review/index');
    })->name('setup.review.results');
});

Route::middleware(['auth', 'tenant', 'permission:report.view'])->group(function () {
    Route::get('setup/review/pending', function () {
        return Inertia::render('admin/review/pending');
    })->name('setup.review.pending');

    // Enrollments failing the result-readiness check
    Route::get('results/incomplete', function () {
        return Inertia::render('admin/results/incomplete');
    })->name('results.incomplete');

    Route::get('outstanding-comments', function () {
        return Inertia::render('admin/outstanding-comments/index');
    })->name('outstanding-comments.index');

    Route::get('reports/broadsheets', function () {
        $classLevels = ClassLevel::all();

        return Inertia::render('reports/broadsheets', [
            'classLevels' => ClassLevelResource::collection($classLevels),
        ]);
    })->name('reports.broadsheets');
});

Route::middleware(['auth', 'tenant', 'permission:report.view'])->get('reports/results-per-class', function () {
    $schoolId = ActiveSchool::id();
    $classLevels = ClassLevel::where('school_id', $schoolId)
        ->with(['classLevelArms.classLevel.arms', 'classLevelArms.arm', 'classLevelArms.stream'])
        ->get();

    return Inertia::render('reports/results-per-class', [
        'classLevels' => ClassLevelResource::collection($classLevels),
    ]);
})->name('reports.result-per-class');

Route::middleware(['auth', 'tenant', 'permission:curriculum_subject.view'])->group(function () {
    Route::get('setup/teacher/{teacher:uuid}', function (Teacher $teacher) {
        return Inertia::render('teacher/show', [
            'teacher' => new TeacherResource($teacher),
        ]);
    })->name('setup.teachers.show');

    Route::get('setup/curriculum-subject/{curriculumSubject:uuid}', function (CurriculumSubject $curriculumSubject) {
        // ISOLATION. `curriculum_subjects` carries no `school_id` and no SchoolScope — it is owned
        // through its curriculum — so route-model binding alone will happily resolve ANOTHER
        // school's uuid here. That was not merely a leak: `Student` IS school-scoped, so the page
        // then rendered with every `studentResults.student` and `scores.student` serialized as
        // null, and the score grid crashed on `result.student.id`. The 404 is the fix; the
        // null-tolerance in score-entry-page.tsx is the belt to this braces.
        //
        // getOrFail(), not id(): with no active school BOTH sides would be null and a bare
        // `===` would pass, which is the fail-OPEN direction on an isolation check.
        abort_unless(
            $curriculumSubject->curriculum?->school_id === ActiveSchool::getOrFail()->id,
            404
        );

        $curriculumSubject->load([
            'curriculum',
            'curriculum.examType.gradeBoundaries',
            'curriculum.markingScheme.components',
            // `.activeComments` so a CATEGORICAL grid gets its suggestions the same way the
            // numeric one gets `commentBands` — with the page, never per student.
            'curriculum.gradingScheme.items.activeComments',
            'subject',
            'markingComponents',
            'scores.student',
            'scores.markingComponent',
            'studentAssignments' => function ($query) {
                $query->where('status', 'active')
                    ->with('studentCurriculum.student');
            },
            'resultStatus',
            'studentResults.student',
            'studentResults.gradingSchemeItem',
        ]);

        return Inertia::render('curriculum-subject/show', [
            'curriculumSubject' => new CurriculumSubjectResource($curriculumSubject),
            'defaultGradeBoundaries' => GradeBoundaryResource::collection(
                GradeBoundary::whereNull('exam_type_id')->get()
            ),
            // Comment suggestions for the score grid, RESOLVED HERE for this subject's exam type
            // (its own set if it has one, the school default otherwise) and shipped with the page.
            // A handful of rows, so the grid never fetches per student — CommentCell stays a pure
            // function of its props even on a table of hundreds.
            'commentBands' => CommentBandResource::collection(
                CommentBand::setFor($curriculumSubject->curriculum?->exam_type_id)
            ),
        ]);
    })->name('setup.curriculumSubjects.show');

    // student curricula subject management (drill-down; admin/head/teacher/guardian only)
    Route::get('setup/student-curricula/{studentCurriculum:uuid}/subjects', function (StudentCurriculum $studentCurriculum) {
        $studentCurriculum->load(['student']);

        return Inertia::render('admin/student-curricula/show', [
            'student' => new StudentResource($studentCurriculum->student),
            'studentCurriculum' => new StudentCurriculumResource($studentCurriculum),
        ]);
    })->name('setup.studentCurricula.show');

});

// Student curricula (academic records) index — read-only view shared with principals.
Route::middleware(['auth', 'tenant', 'permission:student_curriculum.view'])->group(function () {
    Route::get('setup/student-curricula/{student:uuid}', function (Student $student) {
        $student->load(['studentCurricula.curriculum.examType', 'studentCurricula.curriculum.classLevelArm.classLevel', 'studentCurricula.curriculum.academicSession', 'studentCurricula.promotedTo', 'studentCurricula.curriculum.term']);

        return Inertia::render('admin/student-curricula/index', [
            'student' => new StudentResource($student),
        ]);
    })->name('setup.studentCurricula.index');
});

Route::middleware(['auth', 'tenant', 'permission:dashboard.view'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'show'])->name('dashboard');
    Route::post('dashboard/refresh', [DashboardController::class, 'refresh'])->middleware('throttle:1,1')->name('dashboard.refresh');
    Route::get('dashboard/onboarding', [DashboardController::class, 'onboardingState'])->name('dashboard.onboarding');
});

Route::middleware(['auth', 'tenant', 'permission:result_signature.manage'])->group(function () {
    Route::get('/result-signature', [ResultSignatureController::class, 'edit'])->name('result-signature.edit');
    Route::post('/result-signature', [ResultSignatureController::class, 'update'])->name('result-signature.update');
    Route::delete('/result-signature', [ResultSignatureController::class, 'destroy'])->name('result-signature.destroy');
});

Route::middleware(['auth', 'tenant', 'permission:result.view'])->group(function () {

    Route::get('class-level/{classLevel:uuid}/results', [ClassResultsController::class, 'classLevel'])
        ->name('setup.classLevels.show');

    Route::get('class-level-arm/{classLevelArm:uuid}/results', [ClassResultsController::class, 'classLevelArm'])
        ->name('setup.classLevelArms.results');
    Route::get('students/{student:uuid}/results/active', function (Student $student) {
        $studentCurricula = StudentCurriculum::with([
            'student',
            'curriculum.examType.gradeBoundaries',
            'curriculum.markingScheme.components',
            'curriculum.term.academicSession.terms',
            'studentSubjects' => function ($query) {
                $query->where('status', 'active');
            },

            'studentSubjects.curriculumSubject.studentResults.student',
            'studentSubjects.curriculumSubject.resultStatus',
            'studentSubjects.curriculumSubject.subject',
            'studentSubjects.curriculumSubject.markingComponents',
            'studentSubjects.curriculumSubject.teacherAssignments.teacher',
        ])
            ->where('student_id', $student->id)
            ->where('status', 'active')
            ->get();
        $defaultGradeBoundaries = GradeBoundary::where('exam_type_id', null)->get();

        if (auth()->user()->hasRole('guardian')) {

            $studentCurricula = $studentCurricula->filter(function ($studentCurriculum) {

                if ($studentCurriculum->status !== StudentStatusEnum::ACTIVE) {
                    return true;
                }

                if (! $studentCurriculum->principal_approval) {
                    return false;
                }

                $deadline = $studentCurriculum?->curriculum?->term?->result_visible_at;
                if (is_null($deadline)) {
                    return true;
                }

                return now()->greaterThan($deadline);
            })->values();
        }

        return Inertia::render('student/results/active', [
            'student' => new StudentResource($student),
            'studentCurricula' => StudentCurriculumResource::collection($studentCurricula),
            'defaultGradeBoundaries' => GradeBoundaryResource::collection($defaultGradeBoundaries),
            'resultSignatures' => app(ResultSignatureService::class)->forCurricula(
                $studentCurricula,
                School::findOrFail(ActiveSchool::id()),
            ),
        ]);
    })->name('students.results.active');
    Route::get('students/{student:uuid}/results/{studentCurriculum:uuid}', function (Student $student, StudentCurriculum $studentCurriculum) {
        $studentCurricula = StudentCurriculum::with([
            'student',
            'curriculum.examType.gradeBoundaries',
            'curriculum.term.academicSession.terms',
            'studentSubjects' => function ($query) {
                $query->where('status', 'active');
            },
            'studentSubjects.curriculumSubject.studentResults.student',
            'studentSubjects.curriculumSubject.resultStatus',
            'studentSubjects.curriculumSubject.subject',
            'studentSubjects.curriculumSubject.markingComponents',
            'studentSubjects.curriculumSubject.teacherAssignments.teacher',
        ])
            ->where('student_id', $student->id)
            ->where('id', $studentCurriculum->id)
            ->get();
        $defaultGradeBoundaries = GradeBoundary::where('exam_type_id', null)->get();
        if (auth()->user()->hasRole('guardian')) {

            $studentCurricula = $studentCurricula->filter(function ($studentCurriculum) {

                if ($studentCurriculum->status !== StudentStatusEnum::ACTIVE) {
                    return true;
                }

                if (! $studentCurriculum->principal_approval) {
                    return false;
                }

                $deadline = $studentCurriculum?->curriculum?->term?->result_visible_at;
                if (is_null($deadline)) {
                    return true;
                }

                return now()->greaterThan($deadline);
            })->values();
        }

        return Inertia::render('student/results/active', [
            'student' => new StudentResource($student),
            'studentCurricula' => StudentCurriculumResource::collection($studentCurricula),
            'defaultGradeBoundaries' => GradeBoundaryResource::collection($defaultGradeBoundaries),
            'resultSignatures' => app(ResultSignatureService::class)->forCurricula(
                $studentCurricula,
                School::findOrFail(ActiveSchool::id()),
            ),
        ]);
    })->name('students.results.show')->withoutScopedBindings();
});

Route::middleware(['auth', 'tenant', 'permission:parent_portal.access'])->group(function () {
    Route::get('parent/dashboard', function () {
        return redirect()->route('parent.wards');

        return Inertia::render('parent/dashboard');
    })->name('parent.dashboard');
    Route::get('parent/wards', function () {
        return Inertia::render('parent/wards');
    })->name('parent.wards');
});

Route::middleware(['auth', 'tenant', 'permission:boarding_portal.access'])->group(function () {
    Route::get('boarding-parent/behavioral-assessments', function () {
        return Inertia::render('boarding-parent/behavioral-assessments/index');
    })->name('boarding-parent.behavioral-assessments');
});

Route::middleware(['auth', 'tenant', 'permission:manage_form_teacher_comments'])->group(function () {
    Route::get('form-teacher/comments', function () {
        return Inertia::render('form-teacher/comments/index');
    })->name('form-teacher.comments');
});

Route::middleware(['auth', 'tenant', 'permission:manage_head_of_school_comments'])->group(function () {
    Route::get('head-of-school/comments', function () {
        return Inertia::render('head-of-school/comments/index');
    })->name('head-of-school.comments');
});

Route::middleware(['auth', 'tenant', 'permission:manage_key_stage_coordinator_comments'])->group(function () {
    Route::get('key-stage-coordinator/comments', function () {
        return Inertia::render('key-stage-coordinator/comments/index');
    })->name('key-stage-coordinator.comments');
});

require __DIR__.'/settings.php';
