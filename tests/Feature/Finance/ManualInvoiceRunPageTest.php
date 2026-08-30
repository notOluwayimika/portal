<?php

/*
 * THE BULK MANUAL INVOICING SCREEN — its two page routes, the roster feed built for it, and the one
 * coupling that makes its destination picker work.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THE PROPS ARE ASSERTED AND NOT THE 200
 *
 * The shape is FeeSchedulesScreenTest's and OpeningBalanceOperatorScreenTest's, and it exists for
 * the defect the latter records: `/finance/opening-balances/import` bound
 * `ActiveSchool::getOrFail()` — the School MODEL — into `where('school_id', …)`, matched nothing,
 * rendered an EMPTY term select, and returned 200 with every assertion passing. A test that asserts
 * a page renders cannot tell a working screen from one whose selects are empty, and a browser drive
 * is what found it.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE ROSTER FEED IS THE THING THIS COMMIT ADDED THAT DID NOT EXIST, and its own arms are here for
 * one reason above all the others: the screen cannot fetch `/api/students`, because that route
 * carries `student.view` and `finance.invoice.generate` intersects with it on `admin` ALONE. The
 * bursar seat this whole feature was built for — `accounts_officer` — holds the second and not the
 * first. That fact is asserted below against RbacSeeder::grantsMap() rather than left in a docblock,
 * because it is the premise the feed exists on, and a premise nobody checks is how a feed gets
 * "simplified" back onto the endpoint that 403s.
 */

use App\Enums\Permission as PermissionEnum;
use App\Enums\ScholarshipKind;
use App\Enums\StudentStatusEnum;
use App\Enums\TermStatusEnum;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Scholarship;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Term;
use App\Models\User;
use App\Support\ActiveSchool;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

const MIRP_ACCESS = 'finance.access';
const MIRP_GENERATE = 'finance.invoice.generate';
const MIRP_PAGE = '/finance/manual-invoice-runs';
const MIRP_ROSTER = '/api/v1/finance/manual-invoice-runs/students';

/**
 * A School with TWO class levels and TWO arms, created so a count can be wrong.
 *
 * The levels are inserted out of `order` deliberately — the route orders by `order`, and rows
 * inserted in that order would satisfy the assertion whether the orderBy is there or not. Only the
 * FIRST level carries an arm, so `class_level_arms` has a shape a test can distinguish from "every
 * arm, always" and the screen's arm narrowing has something to narrow.
 */
function mirpSchool(): array
{
    $school = School::factory()->create();

    return ActiveSchool::runFor($school->id, function () use ($school) {
        $session = AcademicSession::create([
            'school_id' => $school->id, 'name' => '2026/2027-'.Str::random(4),
            'slug' => 'sess-'.Str::random(8), 'is_current' => true,
        ]);
        $term = Term::create([
            'academic_session_id' => $session->id, 'school_id' => $school->id, 'name' => 'First Term',
            'slug' => 'term-'.Str::random(8), 'order' => 1, 'start_date' => now()->subMonth(),
            'end_date' => now()->addMonths(2), 'status' => TermStatusEnum::ACTIVE->value,
        ]);

        $upper = ClassLevel::create(['school_id' => $school->id, 'name' => 'JSS 2', 'order' => 2]);
        $lower = ClassLevel::create(['school_id' => $school->id, 'name' => 'JSS 1', 'order' => 1]);

        $armRow = Arm::create(['school_id' => $school->id, 'label' => strtoupper(Str::random(3))]);
        $classLevelArm = ClassLevelArm::create([
            'school_id' => $school->id,
            'class_level_id' => $lower->id,
            'arm_id' => $armRow->id,
        ]);

        return compact('school', 'term', 'lower', 'upper', 'armRow', 'classLevelArm');
    });
}

/** A student in $ctx's School, ACTIVELY enrolled at $ctx['classLevelArm'] unless told otherwise. */
function mirpStudent(
    array $ctx,
    string $admissionNumber,
    bool $enrolled = true,
    ?Scholarship $scholarship = null,
): Student {
    return ActiveSchool::runFor($ctx['school']->id, function () use ($ctx, $admissionNumber, $enrolled, $scholarship) {
        $student = Student::factory()->create([
            'school_id' => $ctx['school']->id,
            'admission_number' => $admissionNumber,
            'scholarship_id' => $scholarship?->id,
        ]);

        if ($enrolled) {
            StudentCurriculum::create([
                'student_id' => $student->id,
                'school_id' => $ctx['school']->id,
                'curriculum_id' => Curriculum::factory()->create([
                    'school_id' => $ctx['school']->id,
                    'class_level_arm_id' => $ctx['classLevelArm']->id,
                    'term_id' => $ctx['term']->id,
                ])->id,
                'status' => StudentStatusEnum::ACTIVE,
            ]);
        }

        return $student;
    });
}

function mirpScholarship(array $ctx, ScholarshipKind $kind): Scholarship
{
    return ActiveSchool::runFor($ctx['school']->id, fn () => Scholarship::create([
        'school_id' => $ctx['school']->id,
        'name' => 'Scheme '.Str::random(6),
        'kind' => $kind,
    ]));
}

/**
 * A web-session user holding EXACTLY $permissions, through a role minted for that set — the shape
 * every finance screen test uses, for its reason: role membership is what a grants commit changes,
 * so a role-keyed actor would move with the thing under test.
 *
 * @param  list<string>  $permissions
 */
function mirpUser(School $school, array $permissions): User
{
    $roleName = 'mirp_'.substr(md5(implode(',', $permissions)), 0, 10);
    $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role->syncPermissions($permissions);

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->grantSchoolAccess($school, $roleName);
    $user->flushSchoolAccessCache();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** The acting seat, always with an explicit School session — no route relies on ambient leakage. */
function mirpAs(User $actor, School $school)
{
    return test()->actingAs($actor)->withSession(['school_id' => $school->id]);
}

// ── The selection page and its props ─────────────────────────────────────────────────────────────

it('1a — serves the selection screen with the four filter catalogs it cannot fetch', function () {
    /*
     * THE PROPS ARE THE ARM. Class levels, arms and scholarships are unfetchable by this seat — the
     * only API listing them is `GET /api/students/resources`, which sits under
     * `academic_setup.manage` — so if these come back empty the page renders, looks entirely
     * healthy, and offers three filters that select nothing.
     *
     * WATCHED RED by binding `ActiveSchool::getOrFail()` (the MODEL) rather than `->id` into the
     * class-level query and the `class_level_arms` builder: both come back empty.
     */
    $ctx = mirpSchool();
    $scheme = mirpScholarship($ctx, ScholarshipKind::Discount);

    mirpAs(mirpUser($ctx['school'], [MIRP_ACCESS, MIRP_GENERATE]), $ctx['school'])
        ->get(MIRP_PAGE)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/finance/manual-invoice-runs/index')
            ->has('class_levels', 2)
            // ORDERED BY `order`, not by id — the rows were inserted JSS 2 first, so an arm that
            // read them back in insertion order would see JSS 2 here.
            ->where('class_levels.0.name', 'JSS 1')
            // UUIDs, not ids. StudentIndexFilters matches class level, arm and scholarship by uuid,
            // so an integer here is a filter that silently matches nothing — the failure mode with
            // no error attached.
            ->where('class_levels.0.id', $ctx['lower']->uuid)
            ->has('arms', 1)
            ->where('arms.0.id', $ctx['armRow']->uuid)
            // ONE pair, because only the lower level is armed. A screen offering every arm under
            // every level would satisfy a laxer assertion and would narrow nothing.
            ->has('class_level_arms', 1)
            ->where('class_level_arms.0.class_level', $ctx['lower']->uuid)
            ->where('class_level_arms.0.arm', $ctx['armRow']->uuid)
            ->has('scholarships', 1)
            ->where('scholarships.0.uuid', $scheme->uuid));
});

it('1b — shows School B its own catalogs and none of School A’s', function () {
    // The isolation a browser drive checks by eye, asserted. Every prop is built from an explicit
    // School-scoped query; this is the arm that reds if any of them loses its bound.
    $a = mirpSchool();
    $b = mirpSchool();
    mirpScholarship($a, ScholarshipKind::Discount);
    $bScheme = mirpScholarship($b, ScholarshipKind::Sponsored);

    mirpAs(mirpUser($b['school'], [MIRP_ACCESS, MIRP_GENERATE]), $b['school'])
        ->get(MIRP_PAGE)
        ->assertOk()
        ->assertInertia(function ($page) use ($a, $b, $bScheme) {
            $props = $page->toArray()['props'];

            $levelUuids = collect($props['class_levels'])->pluck('id')->all();
            $armUuids = collect($props['arms'])->pluck('id')->all();
            $schemeUuids = collect($props['scholarships'])->pluck('uuid')->all();

            expect($levelUuids)->toBe([$b['lower']->uuid, $b['upper']->uuid])
                ->and($armUuids)->toBe([$b['armRow']->uuid])
                ->and($schemeUuids)->toBe([$bScheme->uuid])
                // Stated the other way round too: A's rows are ABSENT, not merely B's present.
                ->and(array_intersect($levelUuids, [$a['lower']->uuid, $a['upper']->uuid]))->toBe([])
                ->and(array_intersect($armUuids, [$a['armRow']->uuid]))->toBe([]);
        });
});

it('1c — refuses the screen to a seat that can only VIEW finance', function () {
    // `finance.access` alone must not reach a screen that raises charges with no approval behind
    // them. The sidebar item keys on the same ability, so a visible entry can never 403 on click.
    $ctx = mirpSchool();

    mirpAs(mirpUser($ctx['school'], [MIRP_ACCESS]), $ctx['school'])
        ->get(MIRP_PAGE)
        ->assertForbidden();
});

it('1d — the run report page renders the uuid it was given, unbound', function () {
    /*
     * THE UUID IS A PROP, NOT A MODEL BINDING, and the arm proves the shell does not resolve it: a
     * uuid naming no run at all still renders, because isolation and the 404 are decided by the FEED
     * (`GET /api/v1/finance/manual-invoice-runs/{run}`), which is where the run is actually read.
     * Binding here would put a second School check on a second path, and the shell and its feed
     * could then disagree about whether a run exists.
     */
    $ctx = mirpSchool();
    $stranger = (string) Str::uuid();

    mirpAs(mirpUser($ctx['school'], [MIRP_ACCESS, MIRP_GENERATE]), $ctx['school'])
        ->get(MIRP_PAGE.'/'.$stranger)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/finance/manual-invoice-runs/show')
            ->where('runUuid', $stranger));
});

// ── The roster feed ──────────────────────────────────────────────────────────────────────────────

it('2a — lists the School’s students by admission number, with class and scheme', function () {
    /*
     * ORDER IS ASSERTED BY VALUE, and the fixture is built so the order can be WRONG: the students
     * are created in the opposite sequence to the one the endpoint must return, so an implementation
     * with no `orderBy` at all would come back in insertion order and red this arm.
     *
     * The class label is `Student::$student_class` — the accessor the students index renders — so a
     * bursar meets one spelling of a class on both screens.
     */
    $ctx = mirpSchool();
    $scheme = mirpScholarship($ctx, ScholarshipKind::Discount);

    mirpStudent($ctx, 'ADM-003');
    mirpStudent($ctx, 'ADM-001', scholarship: $scheme);
    mirpStudent($ctx, 'ADM-002');

    $response = mirpAs(mirpUser($ctx['school'], [MIRP_ACCESS, MIRP_GENERATE]), $ctx['school'])
        ->getJson(MIRP_ROSTER)
        ->assertOk();

    expect($response->json('pagination.total'))->toBe(3)
        ->and($response->json('data.*.admission_number'))->toBe(['ADM-001', 'ADM-002', 'ADM-003'])
        // The scheme is on exactly the student who holds it, so a serializer that put the same name
        // on every row — or none on any — reds.
        ->and($response->json('data.0.scholarship'))->toBe($scheme->name)
        ->and($response->json('data.1.scholarship'))->toBeNull()
        // A class label, and the arm's own label inside it, so an accessor returning '' would red.
        ->and($response->json('data.0.class_label'))->toContain('JSS 1')
        ->and($response->json('data.0.class_label'))->toContain($ctx['armRow']->label)
        // Addressed by uuid, which is what `store` takes. An integer id here would be a second
        // addressing scheme for one entity, and one the run endpoint would refuse.
        ->and($response->json('data.0.uuid'))->toBeString();
});

it('2b — a SPONSORED student is on the roster, because this feature exists to bill them', function () {
    /*
     * THE SCHEDULED RUN EXCLUDES SPONSORED STUDENTS, its predicate is shared with its preview and
     * pinned by a test — which makes it exactly the thing somebody copies onto this path. Bulk
     * manual invoicing is the mechanism that produces the C2C session bills
     * (`scholarship-and-cutover-decisions.md` §4), so an exclusion here would drop the very students
     * the feature was built for, silently, on a screen that looked complete.
     *
     * The arm is POSITIVE — the sponsored student is PRESENT — because that is the direction a
     * copied exclusion breaks.
     */
    $ctx = mirpSchool();
    $sponsored = mirpStudent($ctx, 'ADM-100', scholarship: mirpScholarship($ctx, ScholarshipKind::Sponsored));

    $response = mirpAs(mirpUser($ctx['school'], [MIRP_ACCESS, MIRP_GENERATE]), $ctx['school'])
        ->getJson(MIRP_ROSTER)
        ->assertOk();

    expect($response->json('data.*.uuid'))->toContain($sponsored->uuid);
});

it('2c — a student with NO enrolment is still on the roster, and is not silently dropped', function () {
    /*
     * WHETHER A STUDENT CAN BE BILLED IS THE RUN'S QUESTION, NOT THE PICKER'S. Filtering the
     * unplaceable out here would produce a roster that quietly disagrees with the school's own
     * student list, and it would remove the bursar's ability to discover — on the run report, by
     * admission number — that six of their ninety have no current enrolment. Brief §2 requires the
     * unresolved be REPORTED rather than dropped; a picker that pre-drops them makes that report
     * unreachable.
     */
    $ctx = mirpSchool();
    $unenrolled = mirpStudent($ctx, 'ADM-200', enrolled: false);

    $response = mirpAs(mirpUser($ctx['school'], [MIRP_ACCESS, MIRP_GENERATE]), $ctx['school'])
        ->getJson(MIRP_ROSTER)
        ->assertOk();

    expect($response->json('data.*.uuid'))->toContain($unenrolled->uuid)
        // …and it is honest about having no class, rather than inventing one.
        ->and($response->json('data.0.class_label'))->toBeNull();
});

it('2d — filters on class level, arm and scheme, and "none" is not a scheme', function () {
    /*
     * THE FILTERS ARE `StudentIndexFilters`, the SAME class the students index and the students
     * export apply — one definition, three callers. That class was extracted because the index and
     * the export had already drifted (the export filtered on search alone, so narrowing to one class
     * and pressing Export downloaded the whole school); a fourth hand-written block here would be
     * the next drift, and its axis is which families get billed.
     *
     * The fixture puts a student at a level with NO arm, so a class-level filter that quietly also
     * required an arm would red.
     */
    $ctx = mirpSchool();
    $scheme = mirpScholarship($ctx, ScholarshipKind::Discount);

    $inLower = mirpStudent($ctx, 'ADM-301', scholarship: $scheme);
    $alsoLower = mirpStudent($ctx, 'ADM-302');
    $unenrolled = mirpStudent($ctx, 'ADM-303', enrolled: false);

    $actor = mirpUser($ctx['school'], [MIRP_ACCESS, MIRP_GENERATE]);

    // By class level: the two enrolled students, not the unenrolled one.
    $byLevel = mirpAs($actor, $ctx['school'])
        ->getJson(MIRP_ROSTER.'?class_level='.$ctx['lower']->uuid)->assertOk();

    expect($byLevel->json('data.*.admission_number'))->toBe(['ADM-301', 'ADM-302']);

    // By the OTHER class level: nobody is enrolled there, so it must be empty rather than "all".
    $byUpper = mirpAs($actor, $ctx['school'])
        ->getJson(MIRP_ROSTER.'?class_level='.$ctx['upper']->uuid)->assertOk();

    expect($byUpper->json('data'))->toBe([])
        ->and($byUpper->json('pagination.total'))->toBe(0);

    // By scheme uuid: exactly its holder.
    $byScheme = mirpAs($actor, $ctx['school'])
        ->getJson(MIRP_ROSTER.'?scholarship='.$scheme->uuid)->assertOk();

    expect($byScheme->json('data.*.uuid'))->toBe([$inLower->uuid]);

    /*
     * `none` IS THE SENTINEL FOR "on no scheme at all", and it is a DIFFERENT question from the
     * empty value, which means "do not filter". Both non-holders must come back and the holder must
     * not — an implementation treating `none` as "no filter" would return all three and red.
     */
    $byNone = mirpAs($actor, $ctx['school'])
        ->getJson(MIRP_ROSTER.'?scholarship=none')->assertOk();

    expect($byNone->json('data.*.admission_number'))->toBe(['ADM-302', 'ADM-303'])
        ->and($byNone->json('data.*.uuid'))->not->toContain($inLower->uuid)
        ->and($byNone->json('data.*.uuid'))->toContain($unenrolled->uuid);

    // And searching by admission number narrows to one, so the search clause is GROUPED rather than
    // escaping the filters beside it.
    $bySearch = mirpAs($actor, $ctx['school'])
        ->getJson(MIRP_ROSTER.'?class_level='.$ctx['lower']->uuid.'&search=ADM-302')->assertOk();

    expect($bySearch->json('data.*.admission_number'))->toBe(['ADM-302']);
});

it('2e — paginates, and CLAMPS the page size at 100 rather than refusing', function () {
    /*
     * THE CEILING IS PART OF THE SCREEN'S CENTRAL COMPROMISE, so it is pinned by VALUE.
     *
     * Selection is page-scoped, so the largest page decides the largest cohort a bursar can tick in
     * one go. The screen tells the operator that ceiling in words and offers to put a cohort on one
     * page when it fits; both statements are false the moment this number moves, and neither is
     * visible to a test of the screen. The literals below are LITERALS on purpose — a test that
     * derived its payload from the constant could only ever restate the constant, which is how a cap
     * test survives the cap being raised.
     */
    $ctx = mirpSchool();
    foreach (range(1, 5) as $i) {
        mirpStudent($ctx, sprintf('ADM-4%02d', $i));
    }

    $actor = mirpUser($ctx['school'], [MIRP_ACCESS, MIRP_GENERATE]);

    $firstPage = mirpAs($actor, $ctx['school'])->getJson(MIRP_ROSTER.'?per_page=2')->assertOk();

    expect($firstPage->json('data'))->toHaveCount(2)
        ->and($firstPage->json('pagination.last_page'))->toBe(3)
        ->and($firstPage->json('pagination.per_page'))->toBe(2);

    $secondPage = mirpAs($actor, $ctx['school'])->getJson(MIRP_ROSTER.'?per_page=2&page=2')->assertOk();

    expect($secondPage->json('data.*.admission_number'))->toBe(['ADM-403', 'ADM-404']);

    /*
     * THE TWO PAGE URLS, PINNED — AND THIS ARM EXISTS BECAUSE THE BROWSER DRIVE FOUND THEM MISSING.
     *
     * The shared `Pagination` component derives its arrows' disabled state from these two fields
     * (`disabled={!meta.next_page_url}`), so a feed that omits them renders Prev and Next
     * permanently dead while the numbered page buttons still work. Every assertion in this file
     * passed with them absent: the endpoint's data, counts and ordering were all correct, and the
     * four-page roster simply could not be paged with the control an operator reaches for first.
     * That is the class of defect a drive exists to catch and a feature test structurally cannot —
     * so the fix gets an assertion here rather than staying a thing somebody once noticed.
     *
     * Asserted as PRESENT-or-NULL per position, which is what the component actually reads: page 1
     * of 3 has a next and no previous, and the middle page has both.
     */
    expect($firstPage->json('pagination.prev_page_url'))->toBeNull()
        ->and($firstPage->json('pagination.next_page_url'))->toBeString()
        ->and($secondPage->json('pagination.prev_page_url'))->toBeString()
        ->and($secondPage->json('pagination.next_page_url'))->toBeString();

    $lastPage = mirpAs($actor, $ctx['school'])->getJson(MIRP_ROSTER.'?per_page=2&page=3')->assertOk();

    expect($lastPage->json('pagination.next_page_url'))->toBeNull()
        ->and($lastPage->json('pagination.prev_page_url'))->toBeString();

    // ACCEPTED at the ceiling…
    expect(mirpAs($actor, $ctx['school'])->getJson(MIRP_ROSTER.'?per_page=100')->assertOk()
        ->json('pagination.per_page'))->toBe(100);

    // …and CLAMPED above it, not refused: a client asking for more gets the most it may have rather
    // than an error in the middle of a selection.
    expect(mirpAs($actor, $ctx['school'])->getJson(MIRP_ROSTER.'?per_page=101')->assertOk()
        ->json('pagination.per_page'))->toBe(100);
});

it('2f — School B’s roster holds none of School A’s students, and with no School it is REFUSED', function () {
    /*
     * ISOLATION, IN BOTH DIRECTIONS THAT MATTER.
     *
     * The positive half is ordinary: B sees B's students. The second half is what `getOrFail()`
     * buys. A `super_admin` has no ambient School when none is selected, and `Student`'s SchoolScope
     * then falls to its SILENT-UNSCOPED branch — so without this refusal the feed would list EVERY
     * School's students and the bursar could tick them into a run. ADR 0036/0040: the bypass is
     * AUTHORIZATION, never ISOLATION, and it reaches the controller here precisely because the
     * permission middleware has been bypassed.
     *
     * IT IS A 403, NOT THE 409 THE RUN REPORT ANSWERS, and the difference is which mechanism speaks
     * rather than a disagreement about the rule. `ActiveSchool::getOrFail()` is
     * `abort_unless(…, 403, 'No active school selected.')`
     * (app/Support/ActiveSchool.php:70 (getOrFail)), so a
     * controller that asks for the School itself refuses at 403; the run report never calls it and
     * is refused instead by `rbac.fail_closed_models` on the MODEL, whose render is a 409. The
     * message is the same sentence either way. MEASURED, not assumed — this arm was written
     * expecting 409 and the endpoint answered 403, which is the more direct refusal of the two.
     */
    $a = mirpSchool();
    $b = mirpSchool();
    $studentA = mirpStudent($a, 'ADM-500');
    $studentB = mirpStudent($b, 'ADM-501');

    $response = mirpAs(mirpUser($b['school'], [MIRP_ACCESS, MIRP_GENERATE]), $b['school'])
        ->getJson(MIRP_ROSTER)
        ->assertOk();

    expect($response->json('data.*.uuid'))->toBe([$studentB->uuid])
        ->and($response->json('data.*.uuid'))->not->toContain($studentA->uuid)
        ->and($response->json('pagination.total'))->toBe(1);

    config(['auth.gate_before_superadmin' => true]);
    $super = User::factory()->create(['school_id' => null]);
    setPermissionsTeamId(null);
    $super->assignRole('super_admin');
    $super->flushSchoolAccessCache();

    test()->actingAs($super)->getJson(MIRP_ROSTER)
        ->assertStatus(403)
        ->assertJsonPath('message', 'No active school selected.');
});

it('2g — the roster is refused to a seat that cannot generate an invoice', function () {
    // The feed carries the SAME ability as the page and as both run routes, so a control that is
    // visible can never 403 on click and the page cannot be reached by a seat its data would refuse.
    $ctx = mirpSchool();
    mirpStudent($ctx, 'ADM-600');

    mirpAs(mirpUser($ctx['school'], [MIRP_ACCESS]), $ctx['school'])
        ->getJson(MIRP_ROSTER)
        ->assertForbidden();
});

// ── The premises this screen rests on ────────────────────────────────────────────────────────────

it('3a — the bursar seat CANNOT reach /api/students, which is why the roster feed exists', function () {
    /*
     * THE PREMISE, MADE EXECUTABLE. Every argument for building a Finance-side roster rests on one
     * fact: `student.view` and `finance.invoice.generate` intersect on `admin` alone, so the seat
     * this feature was built for — `accounts_officer` — would meet a 403 where the roster should be.
     *
     * If that ever stops being true, the honest thing is to reconsider the feed; what must not
     * happen is the fact quietly changing while a docblock keeps asserting it. So the CLAIM is
     * checked, not the conclusion.
     *
     * Read from RbacSeeder::grantsMap() rather than from a list here — a second copy of the map is
     * the drift being guarded against.
     */
    $roster = PermissionEnum::STUDENT_VIEW->value;
    $generate = PermissionEnum::FINANCE_INVOICE_GENERATE->value;

    $map = RbacSeeder::grantsMap();

    $generators = collect($map)->filter(fn (array $g) => in_array($generate, $g, true))->keys();
    $canFetchStudents = $generators->filter(fn (string $role) => in_array($roster, $map[$role], true));
    $cannotFetchStudents = $generators->diff($canFetchStudents)->values();

    // NOT VACUOUS: somebody must hold the generate ability, or the filter above proved nothing.
    expect($generators->count())->toBeGreaterThan(0,
        'No role in grantsMap() holds '.$generate.' — the filter matched nothing, so this arm '
        .'measured nothing. Either the ability was renamed or every invoicing seat was removed.');

    /*
     * POSITIVE, AND IT WAS WRITTEN AS A NEGATION FIRST — `->not->toBe([], $message)`, which
     * PestNegatedExpectationMessagesTest is right to refuse. Pest's `->not->` is a proxy rather than
     * a matcher: it runs the positive assertion and, on success, throws its OWN sentence with every
     * argument shortened-exported into it, so the message below never reached the output at all. A
     * reader who tripped that arm got a printed array where this paragraph should have been.
     *
     * The claim is unchanged — "at least one invoicing seat cannot fetch the students index" — and
     * counting says it in the direction the matcher can report: the count lands in the failure
     * output, and the roles are interpolated into the message so the reader is told WHICH seats were
     * examined rather than being left to re-derive the map.
     */
    expect($cannotFetchStudents->count())->toBeGreaterThan(0,
        'EVERY role that may generate an invoice now also holds '.$roster.' (generators: '
        .$generators->implode(', ').'), so the whole reason ManualInvoiceRunStudentController exists '
        .'has gone away: the screen could fetch /api/students directly. Re-read that controller\'s '
        .'docblock before deleting anything — the other half of its argument is that a page is a page '
        .'and not a client-held id list — but do not leave the docblock asserting a permission fact '
        .'that is no longer true.');

    // And the seat the feature is FOR is named, so this arm cannot pass on some other role's account.
    expect(in_array($generate, $map['accounts_officer'], true))->toBeTrue()
        ->and(in_array($roster, $map['accounts_officer'], true))->toBeFalse();
});

it('3b — every role that may start a manual run may also fetch the accounts its lines need', function () {
    /*
     * THE DESTINATION PICKER IS A FETCH, NOT A PROP, AND THAT IS ONLY SAFE BECAUSE OF THIS.
     * `GET /api/v1/finance/bank-accounts` is gated on `finance.bank-account.manage`; this screen is
     * gated on `finance.invoice.generate`. Two different abilities. A role holding the second and
     * not the first opens the screen, fetches the accounts, gets a 403, and every line's "Paid into"
     * select is empty — a form that cannot be submitted, because S11 made a destination mandatory on
     * every charge line.
     *
     * The same coupling FeeSchedulesScreenTest checks for its own screen, checked again here rather
     * than assumed to transfer: the two screens are gated on different abilities, so one holding
     * says nothing about the other.
     */
    $generate = PermissionEnum::FINANCE_INVOICE_GENERATE->value;
    $accounts = PermissionEnum::FINANCE_BANK_ACCOUNT_MANAGE->value;

    $broken = [];
    foreach (RbacSeeder::grantsMap() as $role => $grants) {
        if (in_array($generate, $grants, true) && ! in_array($accounts, $grants, true)) {
            $broken[] = $role;
        }
    }

    expect($broken)->toBe([],
        'Role(s) ['.implode(', ', $broken)."] hold {$generate} but not {$accounts}. Bulk manual "
        .'invoicing FETCHES /api/v1/finance/bank-accounts for its destination picker, which is gated '
        .'on the latter, so that seat would open the screen onto an empty destination select on every '
        .'line and could start no run at all. Either grant the second ability, or make the accounts a '
        .'prop on the route.');

    $holders = collect(RbacSeeder::grantsMap())->filter(fn (array $g) => in_array($generate, $g, true))->keys();

    expect($holders->count())->toBeGreaterThan(0,
        'No role in grantsMap() holds '.$generate.' — the loop above iterated and matched nothing.');
});
