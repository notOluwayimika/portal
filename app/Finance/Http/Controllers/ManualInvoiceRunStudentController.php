<?php

namespace App\Finance\Http\Controllers;

use App\Finance\Contracts\BillableEnrollmentProvider;
use App\Models\Student;
use App\Services\StudentIndexFilters;
use App\Support\ActiveSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * THE ROSTER THE MANUAL INVOICE RUN IS TICKED FROM — a filtered, paginated page of the active
 * School's students, and nothing else.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS AT ALL, WHICH IS THE FIRST QUESTION A READER WILL ASK
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * The obvious answer is that the screen should fetch `/api/students`, the feed the students index
 * already uses. IT CANNOT, and this is a permission fact rather than a preference:
 *
 *   - `/api/students` carries `permission:student.view` (routes/api.php, the principal read-only
 *     group);
 *   - `student.view` is granted to `admin`, `head_of_school`, `principal` and `form_teacher`
 *     (RbacSeeder::grantsMap());
 *   - `finance.invoice.generate` — the ability BOTH manual-run routes carry, and the one this
 *     route carries — is granted to `admin` and `accounts_officer`.
 *
 * The intersection is `admin` alone. `accounts_officer` — the bursar, who is the operator this
 * whole feature was built for — holds `generate` and NOT `student.view`, so a screen fetching
 * `/api/students` opens onto an empty table with a 403 in the console for exactly the seat it was
 * written for. That is the defect FinanceNavCoverageTest exists to prevent, one level in: not a
 * menu item that 403s on click, but a page that 403s on load.
 *
 * `/v1/finance/accounts` is not a substitute either. It reads `finance_student_accounts`, a
 * PROJECTION whose rows exist only once a student has financial activity — so the students this
 * feature is most likely to be billing, the ones nobody has billed yet, are absent from it — and it
 * offers search and status only, with no class level and no scholarship, which are the two axes
 * brief §1 names.
 *
 * SO THE CHOICE WAS: widen the bursar seat with `student.view` (which also opens the per-student
 * detail and the enrollment-subject reads — an academic-directory ability, and a much larger change
 * than a screen), ship the roster as page PROPS (no new API, but the whole school in the initial
 * payload AND the client then holding every id, which is precisely the condition that makes
 * guardians' "select all matching" look safe to whoever edits this next), or this. This is the one
 * that keeps the client from ever holding the whole set, which is the property brief §1 rests on.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * ONE FILTER DEFINITION, THREE CALLERS — NOT A FOURTH HAND-WRITTEN QUERY BLOCK
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `StudentIndexFilters::apply()` is the SAME class `StudentService::paginate` and `StudentsExport`
 * call, and it was extracted precisely because those two had drifted: the index filtered on search
 * + class level + arm, the export on search alone, so an operator who narrowed to one class and
 * pressed Export downloaded the whole school. Writing this screen's filters out by hand here would
 * be the third copy and the second drift, and the axis it would drift on is which families get
 * billed.
 *
 * It is reached with no compile-time Academics reference: the class lives in `App\Services`, takes
 * an `App\Models\Student` builder, and expresses class level, arm and scholarship as whereHas
 * clauses over relation NAMES. `App\Models\Student` is not among the four models arch rule 3
 * forbids Finance (Curriculum, Score, StudentResult, StudentCurriculum), and
 * `StoreManualInvoiceRunRequest` already reads it directly for the isolation refusal.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * THE SCHOOL IS AN ARGUMENT, NOT AN AMBIENT OPINION
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `ActiveSchool::getOrFail()->id`, and the `where` is written out even though `Student` carries the
 * SchoolScope — the same decision, for the same measured reason, as
 * `StoreManualInvoiceRunRequest::studentIdMap()`. A `super_admin` with no School selected has no
 * ambient School at all, and `Student`'s SchoolScope then falls to its silent-unscoped branch: the
 * roster would list EVERY School's students and the bursar could tick them. `getOrFail()` refuses
 * that request with a 409 before a row is read, and the explicit predicate is what makes the
 * refusal load-bearing rather than decorative.
 *
 * `->id` AND NOT THE MODEL. `getOrFail()` returns a School, and `where('school_id', $school)`
 * reads as correct while matching nothing — the scar three route closures in routes/web.php
 * already carry.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * DISPLAY COMES THROUGH THE PORT, SO THE PICKER AND THE REPORT NAME A STUDENT IDENTICALLY
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `uuid`, `name` and `admission_number` are resolved by `BillableEnrollmentProvider::displayFor()`
 * — the same call `ManualInvoiceRunController::serializeRows()` makes when it names the unplaceable
 * on the report. Formatting a name here instead would be a second definition of `displayName()`,
 * and the operator would meet one spelling while ticking and another while reading what they did.
 *
 * A ROW THE PORT CANNOT DISPLAY IS STILL RENDERED, with those three fields null. It cannot happen
 * while this query and `displayFor()` agree about the School and the soft-delete scope, which they
 * do; dropping the row would be the silent-omission shape this feature's report exists to end, and
 * the screen renders such a row un-tickable with the reason rather than hiding it.
 *
 * `class_label` and `scholarship` do NOT come through the port, because the port carries neither.
 * They are read off the eager-loaded models, and `class_label` is `Student::$student_class` — the
 * SAME accessor the students index renders — so a bursar sees one spelling of a class across both.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT THIS DELIBERATELY DOES NOT ANSWER
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * NO PLACEABILITY FLAG. Whether a student resolves to a current billable enrollment is decided by
 * `BillableEnrollmentProvider::currentForStudent()` at run time, one student at a time, and the run
 * REPORT names the ones that did not by admission number — which brief §2 requires and which is the
 * only account that reflects the state at the moment the charge was raised. A flag computed here
 * would be a second answer to that question, computed earlier, from a different read, and it would
 * be the one the operator trusted.
 *
 * NO "ALL MATCHING" SCOPE AND NO ID-LIST ENDPOINT. This route answers a PAGE. Brief §1 rules that
 * if "invoice all N matching" is ever offered it is resolved server-side from the filter payload
 * and never from a client id list; a route here that returned every matching id would hand the
 * client exactly the list that rule forbids it to hold, and the screen would grow the control the
 * day after.
 *
 * NO WRITES. This controller reads.
 */
class ManualInvoiceRunStudentController extends Controller
{
    /** The page size when the client names none. The students index the bursar already knows. */
    private const PER_PAGE = 25;

    /**
     * The largest page a client may ask for. CLAMPED rather than validated, because a client asking
     * for more should get the most it may have, not an error in the middle of a selection.
     *
     * ═══════════════════════════════════════════════════════════════════════════════════════════
     * IT IS THIS FEATURE'S CEILING, NOT THE SHARED CONTROL'S — the earlier comment here said the
     * opposite and was wrong from the moment this number moved
     * ═══════════════════════════════════════════════════════════════════════════════════════════
     *
     * It used to be 100 *because* that was the top option in
     * `resources/js/components/pagination.tsx`'s `LIMITS`, and the comment said so. That array is
     * shared by fifteen screens whose servers do not agree about a legal `per_page` — two of them
     * clamp nothing at all — so it cannot be raised for this screen's sake, and this screen must
     * therefore stop deriving its ceiling from it. The roster passes its OWN limits list to the
     * shared component (`ROSTER_PAGE_LIMITS` in the screen), topped at this number, so the property
     * the old comment was reaching for still holds: THE CONTROL CANNOT OFFER AN OPTION THE SERVER
     * REFUSES. What changed is which side is the authority. This constant is.
     *
     * ═══════════════════════════════════════════════════════════════════════════════════════════
     * WHY 150 — MEASURED COHORTS, AND THE DISTRIBUTION IS RECORDED SO THE NEXT READER RE-MEASURES
     * ═══════════════════════════════════════════════════════════════════════════════════════════
     *
     * Selection is page-scoped, so the largest page decides the largest cohort a bursar can tick in
     * one go. The unit they actually bill is a class level, and on the production copy (school#1,
     * 611 live students) those cohorts are:
     *
     *     116   107   102   101   99   86
     *
     * FOUR OF SIX EXCEEDED 100, and by between 1 and 16. So the old ceiling met a bursar billing a
     * whole class with the page-scoped tick warning for the sake of a handful of rows — the warning
     * firing on the ordinary case, which is how a warning stops being read. 150 clears the largest
     * measured cohort by 34.
     *
     * IT IS A MEASUREMENT, NOT A ROUND NUMBER, AND IT WILL GO STALE. A school that grows, or a
     * second school with larger classes, moves it. Re-measure rather than re-guess — the query is
     * a `COUNT(DISTINCT students.id)` grouped by class level over students with an ACTIVE episode —
     * and update this list along with the number, so the next reader inherits evidence rather than
     * a number with a story attached.
     *
     * COST IS NOT WHAT DECIDES THIS NUMBER. Two perf commits took a 611-student run from 6030
     * queries to 13 (docs/handoff/reports/perf-manual-run-batch-target-inserts.md), so 150 ticked
     * students is not a consideration on either the roster read or the run. What decides it is how
     * many rows an operator can honestly hold in one page-scoped selection.
     */
    private const MAX_PER_PAGE = 150;

    public function __construct(private readonly BillableEnrollmentProvider $enrollments) {}

    public function index(Request $request): JsonResponse
    {
        $schoolId = ActiveSchool::getOrFail()->id;

        $perPage = max(1, min((int) $request->query('per_page', (string) self::PER_PAGE), self::MAX_PER_PAGE));

        $page = StudentIndexFilters::apply(
            Student::query()->where('students.school_id', $schoolId),
            $request,
        )
            ->with([
                'currentCurriculum.curriculum.classLevelArm.classLevel',
                'currentCurriculum.curriculum.classLevelArm.arm',
                'currentCurriculum.curriculum.classLevelArm.stream',
                // One row renders the scheme's name; without this it is a query per student on the
                // page, the same N+1 StudentsExport already eager-loads its way out of.
                'scholarship',
            ])
            /*
             * ORDERED BY ADMISSION NUMBER, which is the key the run report NAMES a student by. The
             * students index orders by `latest()`; a picker whose order has nothing to do with the
             * report's is one the bursar cannot check a selection against. Nulls sort first under
             * MySQL's ascending order and that is the useful direction: a student with no admission
             * number is one to notice before billing them, not one to bury on the last page.
             */
            ->orderBy('students.admission_number')
            ->orderBy('students.id')
            ->paginate($perPage);

        $display = $this->enrollments->displayFor(
            $page->getCollection()->map(fn (Student $student) => (int) $student->getKey())->all(),
        );

        $rows = $page->getCollection()->map(function (Student $student) use ($display) {
            $info = $display[(int) $student->getKey()] ?? null;

            return [
                // Null only if the port could not display the student at all. The screen renders
                // such a row un-tickable and says why; it does not drop it.
                'uuid' => $info['uuid'] ?? null,
                'name' => $info['name'] ?? null,
                'admission_number' => $info['admission_number'] ?? null,
                // The accessor the students index renders, not a second assembly of the same
                // three parts — see the class docblock.
                'class_label' => $student->student_class,
                'scholarship' => $student->scholarship?->name,
            ];
        })->all();

        return response()->json([
            'data' => $rows,
            /*
             * THE SAME SIX KEYS `StudentController::index` RETURNS, and the last two are not
             * decoration. The shared `Pagination` component derives its Prev/Next DISABLED state
             * from `prev_page_url` / `next_page_url` — `disabled={!meta.next_page_url}` — so a feed
             * that omits them renders both arrows permanently dead while the numbered page buttons
             * still work. FOUND BY THE BROWSER DRIVE, not by a test: every assertion about this
             * endpoint passed, and the four-page roster it produced could not be paged with the
             * control an operator reaches for first.
             */
            'pagination' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'prev_page_url' => $page->previousPageUrl(),
                'next_page_url' => $page->nextPageUrl(),
            ],
        ]);
    }
}
