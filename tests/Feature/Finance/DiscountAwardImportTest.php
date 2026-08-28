<?php

/*
 * THE BSS DISCOUNT-AWARD IMPORT — a spreadsheet pairing students with the percentage they were
 * awarded, carried in, one row becoming one StudentDiscountAward.
 *
 * WHAT MAKES THE FIXTURES DISCRIMINATING, stated up front because a fixture whose degrees of freedom
 * have collapsed passes for the wrong reason while its name stays true:
 *
 *   - THE SCHOOL CARRIES SEVERAL ACTIVE POLICIES WHEREVER A ROW HAS TO PICK ONE. An arm asserting
 *     that a row lands on "the 50% discountable policy" proves nothing in a school that has exactly
 *     one policy — an implementation ignoring the percentage, the base, or both, lands on the same
 *     row. So the resolution arms are run against 25%-discountable, 50%-discountable AND 50%-total,
 *     which is three-way: getting the percentage right and the base wrong is a different answer from
 *     getting both right.
 *   - THE BASE ARM USES THE SAME PERCENTAGE ON BOTH SIDES. TUITION ONLY and THE WHOLE BILL are both
 *     50%, so the ONLY thing that can tell the two policies apart is the column under test. Two
 *     different percentages would let a resolver that ignores the base still land correctly.
 *   - THE ISOLATION ARM USES THE SAME ADMISSION NUMBER IN BOTH SCHOOLS and asserts by student ID.
 *     `admission_number` is unique per SCHOOL, not globally, so "it found A student" is not the
 *     claim; "it found THIS school's student" is, and only an id can say so.
 *   - THE POLICY-STATE ARM FLIPS THE STATUS AND RE-RUNS. "Superseded and retired do not match" is
 *     half a claim: it also holds for a resolver that matches nothing at all. The same sheet, the
 *     same school, one status changed, awarding — that is the other half.
 *   - THE ROUTE GATE AND THE ACTION GATE ARE SEPARATE ARMS, because a route-only gate is the defect
 *     this repo has already shipped once, and one arm through the HTTP door cannot tell the two
 *     apart.
 */

use App\Enums\ScholarshipKind;
use App\Finance\Actions\AwardStudentDiscount;
use App\Finance\Enums\DiscountBase;
use App\Finance\Enums\DiscountPolicyStatus;
use App\Finance\Exports\DiscountAwardImportTemplateExport;
use App\Finance\Http\Controllers\DiscountAwardImportController;
use App\Finance\Jobs\ProcessDiscountAwardImport;
use App\Finance\Models\DiscountPolicy;
use App\Finance\Models\StudentDiscountAward;
use App\Finance\Services\DiscountAwardImporter;
use App\Models\Activity;
use App\Models\Import;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Scholarship;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\ActiveSchool;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    Storage::fake();
});

const DA_ACCESS = 'finance.access';

const DA_MANAGE = 'finance.discount-award.manage';

/**
 * `da` PREFIX, and the world helpers are written here rather than imported from
 * OpeningBalanceOperatorScreenTest — Pest defines a file's functions when it loads that file, so
 * calling another file's helper works only if that file happened to load first. That is a load-order
 * dependency, and it fails as a collision the day both files load in one process.
 *
 * A web-session user holding EXACTLY $permissions. Permission-keyed and not role-keyed, for the
 * reason PaymentRecordGateTest gives: role membership is what a grants commit changes, so a
 * role-keyed actor would move with the thing under test and the gate arms would stop measuring the
 * gate.
 *
 * @param  list<string>  $permissions
 */
function daUser(School $school, array $permissions): User
{
    $roleName = 'da_'.substr(md5(implode(',', $permissions)), 0, 10);
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

function daSchool(): School
{
    return School::factory()->create();
}

/** A scholarship in $school. `$kind` null is the unconfigured backfill state every real row is in. */
function daScholarship(School $school, ?ScholarshipKind $kind = ScholarshipKind::Discount): Scholarship
{
    return ActiveSchool::runFor($school->id, fn () => Scholarship::create([
        'school_id' => $school->id,
        'name' => 'Scheme '.Str::random(6),
        'kind' => $kind,
    ]));
}

/** A student in $school with a given admission number, optionally holding a scholarship. */
function daStudent(School $school, string $admission, ?Scholarship $scholarship = null): Student
{
    return ActiveSchool::runFor($school->id, fn () => Student::factory()->create([
        'school_id' => $school->id,
        'admission_number' => $admission,
        'scholarship_id' => $scholarship?->id,
    ]));
}

/**
 * A percentage discount policy in $school.
 *
 * WRITTEN THROUGH THE MODEL AND NOT THROUGH THE GOVERNANCE FLOW, deliberately and only here: these
 * arms are about the IMPORT, and driving four submit-and-approve round trips per arm would make the
 * fixture the subject. The invariant that only ApproveDiscountPolicyChange writes the catalog in
 * PRODUCTION is asserted by DiscountPolicyTest's own arm over `app/`, which does not see this file.
 */
function daPolicy(School $school, int $percent, DiscountBase $base, array $overrides = []): DiscountPolicy
{
    return ActiveSchool::runFor($school->id, fn () => DiscountPolicy::create(array_merge([
        'school_id' => $school->id,
        'name' => 'BSS '.$percent.'% '.$base->value.' '.Str::random(4),
        'basis' => 'percent',
        'percent' => $percent,
        'base' => $base,
        'requires_approval' => false,
        'status' => DiscountPolicyStatus::Active,
    ], $overrides)));
}

/** The header the reader requires, built from the COLUMNS map so it cannot drift from the format. */
function daCsv(array $dataLines): UploadedFile
{
    $header = implode(',', array_keys(DiscountAwardImporter::COLUMNS));

    return UploadedFile::fake()->createWithContent(
        'bss-'.Str::random(6).'.csv',
        $header."\n".implode("\n", $dataLines)."\n",
    );
}

/** Upload through the real endpoint. QUEUE_CONNECTION=sync, so the job runs before this returns. */
function daUpload(User $actor, School $school, UploadedFile $file)
{
    return test()->actingAs($actor)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/discount-award-imports', ['file' => $file]);
}

/** Upload and return the finished import's payload. */
function daRun(User $actor, School $school, array $dataLines): array
{
    $response = daUpload($actor, $school, daCsv($dataLines));
    $response->assertCreated();

    $uuid = $response->json('import.uuid');

    return test()->actingAs($actor)->withSession(['school_id' => $school->id])
        ->getJson("/api/v1/finance/discount-award-imports/{$uuid}")
        ->assertOk()
        ->json('import');
}

/** The report, downloaded through its own route and parsed back — never read off the objects. */
function daReport(User $actor, School $school, string $uuid): array
{
    $response = test()->actingAs($actor)->withSession(['school_id' => $school->id])
        ->get("/api/v1/finance/discount-award-imports/{$uuid}/report")
        ->assertOk();

    $csv = $response->streamedContent();
    $handle = fopen('php://temp', 'r+b');
    fwrite($handle, $csv);
    rewind($handle);

    $header = fgetcsv($handle);
    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        if ($row === [null]) {
            continue;
        }
        $rows[] = array_combine($header, $row);
    }
    fclose($handle);

    return ['raw' => $csv, 'rows' => $rows];
}

/*
|--------------------------------------------------------------------------
| (i) A valid row awards, against a policy it had to CHOOSE
|--------------------------------------------------------------------------
*/

it('awards a valid row, and the award points at the policy matching the PAIR', function () {
    $school = daSchool();
    $actor = daUser($school, [DA_ACCESS, DA_MANAGE]);
    $student = daStudent($school, 'ADM-001', daScholarship($school));

    // THREE active policies, so "it picked the only one" is not an available explanation. Getting
    // the percentage right and the base wrong lands on $wrongBase; getting the base right and the
    // percentage wrong lands on $wrongPercent. Only one row is the right answer.
    $wrongPercent = daPolicy($school, 25, DiscountBase::Discountable);
    $target = daPolicy($school, 50, DiscountBase::Discountable);
    $wrongBase = daPolicy($school, 50, DiscountBase::Total);

    $import = daRun($actor, $school, ['ADM-001,50,TUITION ONLY']);

    expect($import['status'])->toBe('completed')
        ->and($import['total_rows'])->toBe(1)
        ->and($import['awarded'])->toBe(1)
        ->and($import['already_awarded'])->toBe(0)
        ->and($import['rejected'])->toBe(0);

    $award = StudentDiscountAward::withoutGlobalScopes()->where('student_id', $student->id)->sole();

    expect((int) $award->discount_policy_id)->toBe($target->id)
        ->and((int) $award->discount_policy_id)->not->toBe($wrongPercent->id)
        ->and((int) $award->discount_policy_id)->not->toBe($wrongBase->id)
        // The actor is recorded, and it is the uploader — not a system id and not null.
        ->and((int) $award->created_by_user_id)->toBe($actor->id)
        ->and((int) $award->school_id)->toBe($school->id);
});

/*
|--------------------------------------------------------------------------
| (ii) The base column is READ, not defaulted
|--------------------------------------------------------------------------
*/

it('resolves TUITION ONLY and THE WHOLE BILL to DIFFERENT policies at the same percentage', function () {
    $school = daSchool();
    $actor = daUser($school, [DA_ACCESS, DA_MANAGE]);
    $scholarship = daScholarship($school);

    $tuition = daStudent($school, 'ADM-010', $scholarship);
    $whole = daStudent($school, 'ADM-011', $scholarship);

    // SAME PERCENTAGE ON BOTH. The base column is then the only thing in the file that can tell
    // these two policies apart, so an implementation that defaults it — in EITHER direction — puts
    // both students on one policy and this arm reds.
    $discountable = daPolicy($school, 50, DiscountBase::Discountable);
    $total = daPolicy($school, 50, DiscountBase::Total);

    $import = daRun($actor, $school, [
        'ADM-010,50,TUITION ONLY',
        'ADM-011,50,THE WHOLE BILL',
    ]);

    expect($import['awarded'])->toBe(2)->and($import['rejected'])->toBe(0);

    $awardFor = fn (Student $s) => (int) StudentDiscountAward::withoutGlobalScopes()
        ->where('student_id', $s->id)->sole()->discount_policy_id;

    expect($awardFor($tuition))->toBe($discountable->id)
        ->and($awardFor($whole))->toBe($total->id)
        // Stated as an inequality too: if the two policies were ever fixtured to the same row, the
        // two assertions above would both pass while proving nothing.
        ->and($discountable->id)->not->toBe($total->id);
});

it('refuses a row whose applies-to column is blank rather than defaulting it', function () {
    $school = daSchool();
    $actor = daUser($school, [DA_ACCESS, DA_MANAGE]);
    $student = daStudent($school, 'ADM-012', daScholarship($school));

    daPolicy($school, 50, DiscountBase::Discountable);
    daPolicy($school, 50, DiscountBase::Total);

    $import = daRun($actor, $school, ['ADM-012,50,']);

    expect($import['rejected'])->toBe(1)->and($import['awarded'])->toBe(0);

    expect(StudentDiscountAward::withoutGlobalScopes()->where('student_id', $student->id)->count())
        ->toBe(0);

    $report = daReport($actor, $school, $import['uuid']);

    expect($report['rows'][0]['reason'])
        ->toContain('TUITION ONLY')
        ->toContain('THE WHOLE BILL');
});

it('accepts the two phrases case-insensitively, and the enum values, and refuses anything else', function () {
    expect(DiscountAwardImporter::parseAppliesTo('  the   whole   bill '))->toBe(DiscountBase::Total)
        ->and(DiscountAwardImporter::parseAppliesTo('Tuition Only'))->toBe(DiscountBase::Discountable)
        ->and(DiscountAwardImporter::parseAppliesTo('total'))->toBe(DiscountBase::Total)
        ->and(DiscountAwardImporter::parseAppliesTo('discountable'))->toBe(DiscountBase::Discountable)
        // Near misses, refused by name. "Whole bill" is the plausible one and it is NOT an answer.
        ->and(DiscountAwardImporter::parseAppliesTo('Whole bill'))->toBeNull()
        ->and(DiscountAwardImporter::parseAppliesTo('everything'))->toBeNull()
        ->and(DiscountAwardImporter::parseAppliesTo('tuition'))->toBeNull()
        ->and(DiscountAwardImporter::parseAppliesTo(''))->toBeNull();
});

it('reads a percentage as a whole number and refuses everything the CHECK could not hold', function () {
    // The bounds are LITERALS, not derived from the parser or from the column. `percent BETWEEN 1
    // AND 100` is the database's own rule, declared in
    // database/migrations/2026_07_26_140000_create_finance_discount_policies.php, and an expectation
    // computed from the thing under test could only restate it.
    expect(DiscountAwardImporter::parsePercentage('50'))->toBe(50)
        ->and(DiscountAwardImporter::parsePercentage(' 50 % '))->toBe(50)
        ->and(DiscountAwardImporter::parsePercentage('050'))->toBe(50)
        ->and(DiscountAwardImporter::parsePercentage('1'))->toBe(1)
        ->and(DiscountAwardImporter::parsePercentage('100'))->toBe(100)
        // Both sides of both bounds, so an off-by-one reds in either direction.
        ->and(DiscountAwardImporter::parsePercentage('0'))->toBeNull()
        ->and(DiscountAwardImporter::parsePercentage('101'))->toBeNull()
        // A cast would read these as 50 and award a percentage nobody wrote.
        ->and(DiscountAwardImporter::parsePercentage('50.5'))->toBeNull()
        ->and(DiscountAwardImporter::parsePercentage('5e1'))->toBeNull()
        ->and(DiscountAwardImporter::parsePercentage('0.5'))->toBeNull()
        ->and(DiscountAwardImporter::parsePercentage('fifty'))->toBeNull()
        ->and(DiscountAwardImporter::parsePercentage(''))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| (iii) An unauthored pair is REFUSED, and no policy is created
|--------------------------------------------------------------------------
*/

it('refuses a pair with no active policy, names the pair, and creates NO policy', function () {
    $school = daSchool();
    $actor = daUser($school, [DA_ACCESS, DA_MANAGE]);
    $student = daStudent($school, 'ADM-020', daScholarship($school));

    // The school has 50% of TUITION ONLY. The row asks for 75% of THE WHOLE BILL — a pair nobody
    // approved. An import that created what it could not find would land here.
    daPolicy($school, 50, DiscountBase::Discountable);

    $before = DiscountPolicy::withoutGlobalScopes()->count();

    $import = daRun($actor, $school, ['ADM-020,75,THE WHOLE BILL']);

    expect($import['rejected'])->toBe(1)->and($import['awarded'])->toBe(0);

    // COUNTED GLOBALLY, without the School scope: a policy created in the wrong school would still
    // be a policy this import created, and a scoped count could not see it.
    expect(DiscountPolicy::withoutGlobalScopes()->count())->toBe($before)
        ->and(StudentDiscountAward::withoutGlobalScopes()->where('student_id', $student->id)->count())->toBe(0);

    $report = daReport($actor, $school, $import['uuid']);

    expect($report['rows'][0]['outcome'])->toBe('rejected')
        // The PAIR is named, both halves, so the bursar knows what to have approved.
        ->and($report['rows'][0]['reason'])->toContain('75% of THE WHOLE BILL');
});

/*
|--------------------------------------------------------------------------
| (iv)/(v) The scholarship refusals, in words the uploader can act on
|--------------------------------------------------------------------------
*/

it('refuses a student on no scholarship, and names them by the admission number THEY typed', function () {
    $school = daSchool();
    $actor = daUser($school, [DA_ACCESS, DA_MANAGE]);
    $student = daStudent($school, 'ADM-030', null);

    daPolicy($school, 50, DiscountBase::Discountable);

    $import = daRun($actor, $school, ['ADM-030,50,TUITION ONLY']);

    expect($import['rejected'])->toBe(1);

    $report = daReport($actor, $school, $import['uuid']);

    expect($report['rows'][0]['reason'])
        ->toContain('holds no scholarship')
        // BY WHAT THEY TYPED. The Action raises `Student [#<id>]`; a bursar holding a spreadsheet
        // knows the admission number and nothing else, so the id is rewritten into it.
        ->toContain('[ADM-030]')
        ->not->toContain("[#{$student->id}]");

    // AND NO NAME LEAVES THE BUILDING. The report is a file somebody downloads; Finance owns no
    // name and none is read back out of the database to identify a row.
    expect($report['raw'])
        ->not->toContain($student->first_name)
        ->not->toContain($student->last_name);
});

it('refuses a student on a SPONSORED scholarship — the award could never be applied', function () {
    $school = daSchool();
    $actor = daUser($school, [DA_ACCESS, DA_MANAGE]);
    $student = daStudent($school, 'ADM-031', daScholarship($school, ScholarshipKind::Sponsored));

    daPolicy($school, 50, DiscountBase::Discountable);

    $import = daRun($actor, $school, ['ADM-031,50,TUITION ONLY']);

    expect($import['rejected'])->toBe(1)->and($import['awarded'])->toBe(0);

    expect(StudentDiscountAward::withoutGlobalScopes()->where('student_id', $student->id)->count())
        ->toBe(0);

    $report = daReport($actor, $school, $import['uuid']);

    expect($report['rows'][0]['reason'])
        ->toContain('may only be made against a discount scholarship')
        ->toContain('excluded from the bulk run');
});

it('refuses a student whose scholarship nobody has classified yet', function () {
    $school = daSchool();
    $actor = daUser($school, [DA_ACCESS, DA_MANAGE]);
    daStudent($school, 'ADM-032', daScholarship($school, null));

    daPolicy($school, 50, DiscountBase::Discountable);

    $import = daRun($actor, $school, ['ADM-032,50,TUITION ONLY']);

    expect($import['rejected'])->toBe(1);

    expect(daReport($actor, $school, $import['uuid'])['rows'][0]['reason'])
        ->toContain('not configured yet');
});

/*
|--------------------------------------------------------------------------
| (vi) A re-upload is SAFE, and a changed row is not
|--------------------------------------------------------------------------
*/

it('reports a re-upload of the same sheet as ALREADY AWARDED, not as failure', function () {
    $school = daSchool();
    $actor = daUser($school, [DA_ACCESS, DA_MANAGE]);
    $scholarship = daScholarship($school);

    daStudent($school, 'ADM-040', $scholarship);
    daStudent($school, 'ADM-041', $scholarship);

    daPolicy($school, 50, DiscountBase::Discountable);
    daPolicy($school, 100, DiscountBase::Total);

    $sheet = [
        'ADM-040,50,TUITION ONLY',
        'ADM-041,100,THE WHOLE BILL',
    ];

    $first = daRun($actor, $school, $sheet);

    expect($first['awarded'])->toBe(2)
        ->and($first['already_awarded'])->toBe(0)
        ->and($first['rejected'])->toBe(0);

    // THE SAME BYTES, AGAIN. This is the case a bursar produces by being unsure the first upload
    // worked, and it must not read as two failures.
    $second = daRun($actor, $school, $sheet);

    expect($second['awarded'])->toBe(0)
        ->and($second['already_awarded'])->toBe(2)
        ->and($second['rejected'])->toBe(0)
        ->and($second['total_rows'])->toBe(2)
        ->and($second['processed_rows'])->toBe(2);

    // AND NOTHING WAS WRITTEN TWICE.
    expect(StudentDiscountAward::withoutGlobalScopes()->count())->toBe(2);

    $report = daReport($actor, $school, $second['uuid']);

    expect(array_column($report['rows'], 'outcome'))->toBe(['already_awarded', 'already_awarded'])
        ->and($report['rows'][0]['reason'])->toContain('needs no action');
});

it('REFUSES a row asking for a different policy than the student already holds', function () {
    $school = daSchool();
    $actor = daUser($school, [DA_ACCESS, DA_MANAGE]);
    $student = daStudent($school, 'ADM-042', daScholarship($school));

    $held = daPolicy($school, 50, DiscountBase::Discountable);
    daPolicy($school, 100, DiscountBase::Total);

    daRun($actor, $school, ['ADM-042,50,TUITION ONLY']);

    // The corrected sheet asks for something ELSE. Folding this into "already awarded" would report
    // the word "already" over a row whose whole purpose was to change what this family pays.
    $second = daRun($actor, $school, ['ADM-042,100,THE WHOLE BILL']);

    expect($second['rejected'])->toBe(1)
        ->and($second['already_awarded'])->toBe(0)
        ->and($second['awarded'])->toBe(0);

    expect((int) StudentDiscountAward::withoutGlobalScopes()->where('student_id', $student->id)
        ->sole()->discount_policy_id)->toBe($held->id);

    expect(daReport($actor, $school, $second['uuid'])['rows'][0]['reason'])
        ->toContain('already on 50% of TUITION ONLY')
        ->toContain('asks for 100% of THE WHOLE BILL')
        ->toContain('does not change an award that already exists');
});

/*
|--------------------------------------------------------------------------
| (vii) Isolation — the join key only means anything inside a school
|--------------------------------------------------------------------------
*/

it('refuses a row for a student who exists only in ANOTHER school', function () {
    $school = daSchool();
    $other = daSchool();

    $actor = daUser($school, [DA_ACCESS, DA_MANAGE]);

    // The acting school has a roster — an EMPTY one would reject every row for the wrong reason and
    // this arm would pass without ever exercising the school boundary.
    daStudent($school, 'ADM-050', daScholarship($school));

    $foreign = daStudent($other, 'ADM-999', daScholarship($other));

    daPolicy($school, 50, DiscountBase::Discountable);

    $import = daRun($actor, $school, [
        'ADM-050,50,TUITION ONLY',
        'ADM-999,50,TUITION ONLY',
    ]);

    expect($import['awarded'])->toBe(1)->and($import['rejected'])->toBe(1);

    expect(StudentDiscountAward::withoutGlobalScopes()->where('student_id', $foreign->id)->count())
        ->toBe(0);

    $report = daReport($actor, $school, $import['uuid']);
    $refused = collect($report['rows'])->firstWhere('admission_number', 'ADM-999');

    expect($refused['outcome'])->toBe('rejected')
        ->and($refused['reason'])->toContain('No student in this school has the admission number [ADM-999]');
});

it('resolves a SHARED admission number to THIS school\'s student, checked by id', function () {
    $school = daSchool();
    $other = daSchool();

    $actor = daUser($school, [DA_ACCESS, DA_MANAGE]);

    // The same string in both schools — legal, because the unique index is
    // (school_id, admission_number) and not (admission_number). This is the arm that a
    // school-blind roster passes only by luck, and it is asserted by ID because the LABEL is by
    // construction the same on both sides.
    $mine = daStudent($school, 'SHARED-1', daScholarship($school));
    $theirs = daStudent($other, 'SHARED-1', daScholarship($other));

    daPolicy($school, 50, DiscountBase::Discountable);

    $import = daRun($actor, $school, ['SHARED-1,50,TUITION ONLY']);

    expect($import['awarded'])->toBe(1);

    $award = StudentDiscountAward::withoutGlobalScopes()->sole();

    expect((int) $award->student_id)->toBe($mine->id)
        ->and((int) $award->student_id)->not->toBe($theirs->id)
        ->and($mine->id)->not->toBe($theirs->id);
});

/*
|--------------------------------------------------------------------------
| (viii) THE GATE — twice, because one arm cannot tell the two apart
|--------------------------------------------------------------------------
*/

it('the ROUTE refuses a caller who holds finance.access but not the award ability', function () {
    $school = daSchool();

    // Holds the GROUP gate every finance route sits behind, and nothing else. So a 403 here is the
    // new ability refusing, and a 200 would be the group gate admitting them — which is exactly the
    // hole a borrowed `finance.*` permission would have left open.
    $denied = daUser($school, [DA_ACCESS]);

    test()->actingAs($denied)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/discount-award-imports', ['file' => daCsv([])])
        ->assertForbidden();

    test()->actingAs($denied)->withSession(['school_id' => $school->id])
        ->getJson('/api/v1/finance/discount-award-imports/template')
        ->assertForbidden();

    // The positive control, in the same arm: the SAME request, one permission added, is admitted.
    // Without it, a route that refused everybody would pass the two assertions above.
    $allowed = daUser($school, [DA_ACCESS, DA_MANAGE]);

    daUpload($allowed, $school, daCsv([]))->assertCreated();
});

it('the ACTION refuses a caller without the award ability, with no route anywhere near it', function () {
    $school = daSchool();
    $student = daStudent($school, 'ADM-060', daScholarship($school));
    $policy = daPolicy($school, 50, DiscountBase::Discountable);

    // EVERYTHING ELSE IS VALID: an active percentage policy in this school, a student on a discount
    // scholarship, and a real School context. The ONLY thing wrong is the actor's grant, so the
    // refusal cannot be some other guard passing for the wrong reason.
    $denied = daUser($school, [DA_ACCESS]);

    $call = fn (User $actor) => ActiveSchool::runFor(
        $school->id,
        fn () => app(AwardStudentDiscount::class)->handle($student->id, $policy->id, $actor->id),
    );

    expect(fn () => $call($denied))->toThrow(AuthorizationException::class, DA_MANAGE);

    expect(StudentDiscountAward::withoutGlobalScopes()->where('student_id', $student->id)->count())
        ->toBe(0);

    // The positive control. Same call, same fixture, one permission added — so the arm can tell
    // "refused because of the ability" from "refused for any reason at all".
    $allowed = daUser($school, [DA_ACCESS, DA_MANAGE]);

    $award = $call($allowed);

    expect((int) $award->discount_policy_id)->toBe($policy->id)
        ->and((int) $award->created_by_user_id)->toBe($allowed->id);
});

it('the ACTION refuses an actor id that names nobody', function () {
    $school = daSchool();
    $student = daStudent($school, 'ADM-061', daScholarship($school));
    $policy = daPolicy($school, 50, DiscountBase::Discountable);

    expect(fn () => ActiveSchool::runFor(
        $school->id,
        fn () => app(AwardStudentDiscount::class)->handle($student->id, $policy->id, 99999999),
    ))->toThrow(AuthorizationException::class);

    expect(StudentDiscountAward::withoutGlobalScopes()->count())->toBe(0);
});

it('the QUEUED JOB gates on its own actor — no route is consulted when the rows are written', function () {
    $school = daSchool();
    $student = daStudent($school, 'ADM-062', daScholarship($school));

    daPolicy($school, 50, DiscountBase::Discountable);

    // THE WINDOW A ROUTE GATE STRUCTURALLY CANNOT COVER. The upload's permission was checked at the
    // door; the rows are written later, by a worker, with no middleware within reach — so a grant
    // revoked in between, or an import row composed by any other caller, reaches the Action with
    // whatever actor it names. Here the named actor holds the group gate and NOT the award ability.
    $unentitled = daUser($school, [DA_ACCESS]);

    $path = daCsv(['ADM-062,50,TUITION ONLY'])
        ->store(ProcessDiscountAwardImport::directoryFor($school->id));

    $import = ActiveSchool::runFor($school->id, fn () => Import::create([
        'school_id' => $school->id,
        'user_id' => $unentitled->id,
        'type' => DiscountAwardImportController::TYPE,
        'file_name' => 'bss.csv',
        'file_path' => $path,
        'status' => 'queued',
    ]));

    // Run the job inside the School context its SchoolAware middleware would supply, and nothing
    // else. No request, no route, no `permission:` middleware anywhere in the call.
    ActiveSchool::runFor(
        $school->id,
        fn () => (new ProcessDiscountAwardImport($import->id, $school->id))
            ->handle(app(DiscountAwardImporter::class)),
    );

    $import->refresh();

    expect($import->status)->toBe('completed')
        ->and((int) $import->failed)->toBe(1)
        ->and((int) $import->succeeded)->toBe(0)
        ->and((int) $import->skipped)->toBe(0);

    expect(StudentDiscountAward::withoutGlobalScopes()->where('student_id', $student->id)->count())
        ->toBe(0);

    expect(Storage::get((string) $import->report_path))
        ->toContain('no longer hold the permission');

    // THE POSITIVE CONTROL, and it is what makes the arm about the ABILITY rather than about running
    // a job by hand: the same file, the same fixture, an entitled actor — and it awards.
    $entitled = daUser($school, [DA_ACCESS, DA_MANAGE]);

    $second = ActiveSchool::runFor($school->id, fn () => Import::create([
        'school_id' => $school->id,
        'user_id' => $entitled->id,
        'type' => DiscountAwardImportController::TYPE,
        'file_name' => 'bss.csv',
        'file_path' => daCsv(['ADM-062,50,TUITION ONLY'])
            ->store(ProcessDiscountAwardImport::directoryFor($school->id)),
        'status' => 'queued',
    ]));

    ActiveSchool::runFor(
        $school->id,
        fn () => (new ProcessDiscountAwardImport($second->id, $school->id))
            ->handle(app(DiscountAwardImporter::class)),
    );

    expect((int) $second->refresh()->succeeded)->toBe(1)
        ->and(StudentDiscountAward::withoutGlobalScopes()->where('student_id', $student->id)->count())
        ->toBe(1);
});

/*
|--------------------------------------------------------------------------
| (ix) Policy STATE — and the flip that proves the pair was otherwise fine
|--------------------------------------------------------------------------
*/

it('does not match a superseded or a retired policy, and matches once one is made active', function () {
    $school = daSchool();
    $actor = daUser($school, [DA_ACCESS, DA_MANAGE]);
    daStudent($school, 'ADM-070', daScholarship($school));

    $superseded = daPolicy($school, 50, DiscountBase::Discountable, ['status' => DiscountPolicyStatus::Superseded]);
    daPolicy($school, 50, DiscountBase::Discountable, ['status' => DiscountPolicyStatus::Retired]);

    $sheet = ['ADM-070,50,TUITION ONLY'];

    $first = daRun($actor, $school, $sheet);

    expect($first['rejected'])->toBe(1)->and($first['awarded'])->toBe(0);
    expect(daReport($actor, $school, $first['uuid'])['rows'][0]['reason'])
        ->toContain('no active discount policy for 50% of TUITION ONLY');

    // THE OTHER HALF OF THE CLAIM. "Superseded and retired do not match" also holds for a resolver
    // that matches nothing at all. One status changed, same sheet, same school — and it awards.
    ActiveSchool::runFor($school->id, fn () => DiscountPolicy::query()
        ->whereKey($superseded->id)
        ->update(['status' => DiscountPolicyStatus::Active->value]));

    $second = daRun($actor, $school, $sheet);

    expect($second['awarded'])->toBe(1)->and($second['rejected'])->toBe(0);

    expect((int) StudentDiscountAward::withoutGlobalScopes()->sole()->discount_policy_id)
        ->toBe($superseded->id);
});

it('refuses a row when TWO active policies sit on the same pair, rather than choosing one', function () {
    $school = daSchool();
    $actor = daUser($school, [DA_ACCESS, DA_MANAGE]);
    $student = daStudent($school, 'ADM-071', daScholarship($school));

    $a = daPolicy($school, 50, DiscountBase::Discountable);
    $b = daPolicy($school, 50, DiscountBase::Discountable);

    $import = daRun($actor, $school, ['ADM-071,50,TUITION ONLY']);

    expect($import['rejected'])->toBe(1)->and($import['awarded'])->toBe(0);

    // NEITHER was chosen. An implementation taking `->first()` would satisfy "an award exists".
    expect(StudentDiscountAward::withoutGlobalScopes()->where('student_id', $student->id)->count())
        ->toBe(0);

    expect(daReport($actor, $school, $import['uuid'])['rows'][0]['reason'])
        ->toContain('2 active discount policies')
        ->toContain($a->name)
        ->toContain($b->name);
});

/*
|--------------------------------------------------------------------------
| The file, the report, and the template
|--------------------------------------------------------------------------
*/

it('fails the whole import, in the uploader\'s words, when a required column is missing', function () {
    $school = daSchool();
    $actor = daUser($school, [DA_ACCESS, DA_MANAGE]);

    $file = UploadedFile::fake()->createWithContent(
        'bss.csv',
        "admission_number,discount_percentage\nADM-080,50\n",
    );

    $response = daUpload($actor, $school, $file)->assertCreated();
    $uuid = $response->json('import.uuid');

    $import = test()->actingAs($actor)->withSession(['school_id' => $school->id])
        ->getJson("/api/v1/finance/discount-award-imports/{$uuid}")
        ->json('import');

    expect($import['status'])->toBe('failed')
        ->and($import['error'])->toContain('discount_applies_to')
        ->and($import['has_report'])->toBeFalse();

    // And the report route says so rather than serving an empty file.
    test()->actingAs($actor)->withSession(['school_id' => $school->id])
        ->get("/api/v1/finance/discount-award-imports/{$uuid}/report")
        ->assertNotFound();
});

it('accounts for EVERY line of the sheet in the report, awarded rows included', function () {
    $school = daSchool();
    $actor = daUser($school, [DA_ACCESS, DA_MANAGE]);
    $scholarship = daScholarship($school);

    daStudent($school, 'ADM-090', $scholarship);
    daStudent($school, 'ADM-091', null);

    daPolicy($school, 50, DiscountBase::Discountable);

    $import = daRun($actor, $school, [
        'ADM-090,50,TUITION ONLY',
        'ADM-091,50,TUITION ONLY',
        'ADM-092,50,TUITION ONLY',
    ]);

    $report = daReport($actor, $school, $import['uuid']);

    // "Did my upload land" is answered by presence, not by absence. A report of failures only
    // reads identically to a report that failed to run.
    expect($report['rows'])->toHaveCount(3)
        ->and(array_column($report['rows'], 'outcome'))->toBe(['awarded', 'rejected', 'rejected'])
        // Line numbers are the ones an operator sees: the header is line 1.
        ->and(array_column($report['rows'], 'line_number'))->toBe(['2', '3', '4'])
        ->and((int) $import['total_rows'])->toBe(3)
        ->and((int) $import['processed_rows'])->toBe(3);
});

it('echoes the three cells back VERBATIM, whitespace included, and leaks no SQL', function () {
    $school = daSchool();
    $actor = daUser($school, [DA_ACCESS, DA_MANAGE]);
    daStudent($school, 'ADM-100', daScholarship($school));

    daPolicy($school, 50, DiscountBase::Discountable);

    // Leading/trailing spaces the operator cannot see on screen. The row still awards — the join
    // trims — and the report shows them exactly what is in their file.
    $import = daRun($actor, $school, ['" ADM-100 "," 50 "," tuition only "']);

    expect($import['awarded'])->toBe(1);

    $report = daReport($actor, $school, $import['uuid']);

    expect($report['rows'][0]['admission_number'])->toBe(' ADM-100 ')
        ->and($report['rows'][0]['discount_percentage'])->toBe(' 50 ')
        ->and($report['rows'][0]['discount_applies_to'])->toBe(' tuition only ')
        // The sibling defect this import must not repeat: a QueryException's message interpolates
        // its bindings into the SQL, and this file leaves the building.
        ->and($report['raw'])->not->toContain('select ')
        ->not->toContain('insert into')
        ->not->toContain('Connection: mysql');
});

it('issues a template whose headings ARE the COLUMNS map, in the map order', function () {
    // THE GENERATED BYTES, not the export's arrays — a template is only a template if the thing
    // that comes out of it is the thing the reader accepts.
    $csv = Excel::raw(new DiscountAwardImportTemplateExport, ExcelFormat::CSV);

    $lines = array_values(array_filter(explode("\n", str_replace("\r", '', $csv)), fn ($l) => $l !== ''));

    expect(str_getcsv($lines[0]))->toBe(array_keys(DiscountAwardImporter::COLUMNS));

    // THE SAMPLES MUST DIFFER ON THE AXIS THAT COSTS MONEY. Two rows both saying TUITION ONLY would
    // teach that the third column is a constant to be copied down — the misreading that turns
    // "100% of tuition" into "100% of everything" for a cohort.
    $samples = array_map(fn ($l) => str_getcsv($l), array_slice($lines, 1));

    expect($samples)->toHaveCount(count(DiscountAwardImportTemplateExport::SAMPLE_ROWS))
        ->and(array_column($samples, 2))
        ->toBe(array_keys(DiscountAwardImporter::APPLIES_TO_CANONICAL));

    // AND THE TEMPLATE IT ISSUES IS A SHEET ITS OWN READER ACCEPTS. A template offering a phrase the
    // reader refuses is the defect the opening-balance template shipped in another form.
    foreach ($samples as $sample) {
        expect(DiscountAwardImporter::parseAppliesTo($sample[2]))->not->toBeNull()
            ->and(DiscountAwardImporter::parsePercentage($sample[1]))->not->toBeNull();
    }
});

/*
|--------------------------------------------------------------------------
| The shared `imports` table is not a back door
|--------------------------------------------------------------------------
*/

it('will not serve another feature\'s import through this route', function () {
    $school = daSchool();
    $actor = daUser($school, [DA_ACCESS, DA_MANAGE]);

    // A guardian import — same table, same school, a different feature behind a different ability,
    // and its report carries guardian contact details.
    $guardian = ActiveSchool::runFor($school->id, fn () => Import::create([
        'school_id' => $school->id,
        'user_id' => $actor->id,
        'type' => 'guardian',
        'file_name' => 'guardians.xlsx',
        'file_path' => 'imports/inbox/x.xlsx',
        'status' => 'completed',
        'report_path' => 'imports/inbox/x-report.xlsx',
    ]));

    test()->actingAs($actor)->withSession(['school_id' => $school->id])
        ->getJson("/api/v1/finance/discount-award-imports/{$guardian->uuid}")
        ->assertNotFound();

    test()->actingAs($actor)->withSession(['school_id' => $school->id])
        ->get("/api/v1/finance/discount-award-imports/{$guardian->uuid}/report")
        ->assertNotFound();
});

it('will not serve another school\'s import of this very type', function () {
    $school = daSchool();
    $other = daSchool();

    $actor = daUser($school, [DA_ACCESS, DA_MANAGE]);
    $foreignActor = daUser($other, [DA_ACCESS, DA_MANAGE]);

    daStudent($other, 'ADM-110', daScholarship($other));
    daPolicy($other, 50, DiscountBase::Discountable);

    $foreign = daRun($foreignActor, $other, ['ADM-110,50,TUITION ONLY']);

    expect($foreign['awarded'])->toBe(1);

    test()->actingAs($actor)->withSession(['school_id' => $school->id])
        ->getJson("/api/v1/finance/discount-award-imports/{$foreign['uuid']}")
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| The award is audited, and the import is the writer the exemption anticipated
|--------------------------------------------------------------------------
*/

it('writes the resolved terms into the audit trail, attributed to the uploader', function () {
    $school = daSchool();
    $actor = daUser($school, [DA_ACCESS, DA_MANAGE]);
    $student = daStudent($school, 'ADM-120', daScholarship($school));

    $policy = daPolicy($school, 100, DiscountBase::Total);

    daRun($actor, $school, ['ADM-120,100,THE WHOLE BILL']);

    $award = StudentDiscountAward::withoutGlobalScopes()->where('student_id', $student->id)->sole();

    $entry = Activity::query()
        ->where('event', AwardStudentDiscount::AWARDED)
        ->where('subject_type', StudentDiscountAward::class)
        ->where('subject_id', $award->id)
        ->firstOrFail();

    expect((int) $entry->causer_id)->toBe($actor->id)
        // The RESOLVED terms, not the policy id alone: "did this child's discount go up or down" is
        // the only question anyone asks of this trail, and an integer cannot answer it.
        ->and($entry->properties['to']['percent'])->toBe(100)
        ->and($entry->properties['to']['base'])->toBe('total')
        ->and($entry->properties['to']['discount_policy_id'])->toBe($policy->id);
});

it('refuses to award through a BusinessRuleException without leaving a partial row', function () {
    $school = daSchool();
    $actor = daUser($school, [DA_ACCESS, DA_MANAGE]);
    $student = daStudent($school, 'ADM-130', daScholarship($school));

    // requires_approval: the Action's fourth policy refusal. It is a BusinessRuleException, so it
    // travels the row-reason path — and the row must leave nothing behind.
    daPolicy($school, 50, DiscountBase::Discountable, ['requires_approval' => true]);

    $import = daRun($actor, $school, ['ADM-130,50,TUITION ONLY']);

    expect($import['rejected'])->toBe(1);

    expect(StudentDiscountAward::withoutGlobalScopes()->where('student_id', $student->id)->count())
        ->toBe(0);

    expect(daReport($actor, $school, $import['uuid'])['rows'][0]['reason'])
        ->toContain('requires per-application approval');
});
