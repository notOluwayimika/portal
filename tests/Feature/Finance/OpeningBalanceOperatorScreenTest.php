<?php

/*
 * §9 step 5b-iii — U12b, THE OPERATOR SCREEN, over HTTP.
 *
 * WHAT THIS FILE PROVES, AND WHAT IT LEAVES TO THE VALIDATOR'S OWN 47KB. OpeningBalanceImportTest
 * drives the console command and owns every rule about the FILE — L1's three outcomes, the length
 * refusals, the duplicate-key fold, the ingest-completeness accounting. Those are not re-proved here
 * and must not be: the validator is now ONE implementation behind both callers, so a second copy of
 * its coverage would be a second thing to keep in step.
 *
 * What is proved here is that the SCREEN'S PATH reaches the same verdict and shows it: the upload
 * creates the batch before it reads a byte, the job validates off the request, the findings arrive on
 * the wire in the shape the page renders, only a `validated` batch can be offered for approval, and
 * every route is behind the maker ability.
 *
 * THE QUEUE IS `sync` IN TESTS (phpunit.xml:45), so a dispatch runs inline and these arms see the
 * finished batch. That is deliberate rather than convenient — it exercises the job's own middleware
 * and its School context, which a Queue::fake() would skip while still reporting green.
 */

use App\Finance\Console\ImportOpeningBalances;
use App\Finance\Enums\OpeningBalanceBatchStatus;
use App\Finance\Exports\OpeningBalanceImportTemplateExport;
use App\Finance\Models\OpeningBalanceBatch;
use App\Models\AcademicSession;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Support\ActiveSchool;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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

const OBOS_ACCESS = 'finance.access';

const OBOS_SUBMIT = 'finance.opening-balance.submit';

const OBOS_APPROVE = 'finance.opening-balance.approve';

/**
 * A web-session user holding EXACTLY $permissions — the shape PaymentRecordGateTest and
 * OpeningBalanceImportTemplateTest both use, for their reason: role membership is what a grants
 * commit changes, so a role-keyed actor would move with the thing under test.
 *
 * @param  list<string>  $permissions
 */
function obosUser(School $school, array $permissions): User
{
    $roleName = 'obos_'.substr(md5(implode(',', $permissions)), 0, 10);
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

/** @return array{school: School, term: Term} */
function obosSchool(): array
{
    $school = School::factory()->create();

    return ActiveSchool::runFor($school->id, function () use ($school) {
        $session = AcademicSession::create([
            'school_id' => $school->id, 'name' => '2026/2027-'.Str::random(4),
            'slug' => 'sess-'.Str::random(8), 'is_current' => true,
        ]);
        $term = Term::create([
            'academic_session_id' => $session->id, 'school_id' => $school->id, 'name' => 'Third Term',
            'slug' => 'term-'.Str::random(8), 'order' => 3, 'start_date' => now()->subMonths(4),
            'end_date' => now()->subMonth(), 'status' => 'active',
        ]);

        return ['school' => $school, 'term' => $term];
    });
}

function obosStudent(School $school, string $admission): Student
{
    return Student::factory()->create(['school_id' => $school->id, 'admission_number' => $admission]);
}

/** The header the validator requires, built from the COLUMNS map so it cannot drift from the format. */
function obosCsv(array $dataLines): UploadedFile
{
    $header = implode(',', array_keys(ImportOpeningBalances::COLUMNS));

    return UploadedFile::fake()->createWithContent(
        'wcbs-'.Str::random(6).'.csv',
        $header."\n".implode("\n", $dataLines)."\n",
    );
}

function obosUpload(User $actor, array $ctx, UploadedFile $file, string $controlTotal, array $overrides = [])
{
    return test()->actingAs($actor)->withSession(['school_id' => $ctx['school']->id])
        ->postJson('/api/v1/finance/opening-balance-batches', array_merge([
            'file' => $file,
            'control_total' => $controlTotal,
            'closing_term' => $ctx['term']->id,
            'as_at' => '2026-08-06',
        ], $overrides));
}

// ── The happy path: upload → validated, off the request ──────────────────────────────────────────

it('uploads an extract, validates it OFF the request, and reports a clean batch as validated', function () {
    $ctx = obosSchool();
    $maker = obosUser($ctx['school'], [OBOS_ACCESS, OBOS_SUBMIT]);
    obosStudent($ctx['school'], 'ADM-1');

    $response = obosUpload($maker, $ctx, obosCsv([
        'ADM-1,W1,Tuition,100000.00,145000.00,BILL-1',
        'ADM-1,W1,Bus,45000.00,145000.00,BILL-1',
    ]), '145000.00');

    $response->assertCreated()
        ->assertJsonPath('status', OpeningBalanceBatchStatus::Validated->value)
        ->assertJsonPath('row_count', 2)
        ->assertJsonPath('file_row_count', 2)
        ->assertJsonPath('can_submit', true)
        // The attestation is on the batch, in the only sanctioned wire shape for money.
        ->assertJsonPath('control_total.amount_minor', 14500000)
        ->assertJsonPath('control_total.currency', 'NGN')
        // THE UPLOAD ANSWERS IN THE SAME SHAPE AS THE POLL. The page holds one "active batch" and
        // renders `rejected_rows` from it immediately, so a 201 without that key blanked the screen
        // on a TypeError while every server assertion stayed green. Found by driving it.
        ->assertJsonStructure(['rejected_rows', 'rejected_rows_truncated']);

    // NOTHING POSTED. The screen validates and stages; the post is a second person's approval.
    expect((int) DB::scalar('SELECT COUNT(*) FROM finance_ledger_transactions'))->toBe(0)
        ->and((int) DB::scalar('SELECT COUNT(*) FROM finance_payments'))->toBe(0);
});

// ── PROOF — L1 reaches the screen, naming the student's LINE NUMBERS ─────────────────────────────

it('PROOF — an L1 failure surfaces on the screen naming the student\'s line numbers, and rejects the WHOLE row-group', function () {
    // 100,000 + 45,000 = 145,000, but the file states 145,000.01 on both of that student's rows.
    // §1's L1 rejects the row-GROUP, so both line numbers must reach the operator — posting three of
    // a student's four lines is worse than posting none.
    $ctx = obosSchool();
    $maker = obosUser($ctx['school'], [OBOS_ACCESS, OBOS_SUBMIT]);
    obosStudent($ctx['school'], 'ADM-BAD');

    $uuid = obosUpload($maker, $ctx, obosCsv([
        'ADM-BAD,W2,Tuition,100000.00,145000.01,BILL-2',
        'ADM-BAD,W2,Bus,45000.00,145000.01,BILL-2',
    ]), '145000.01')->assertCreated()->json('uuid');

    $body = test()->actingAs($maker)->withSession(['school_id' => $ctx['school']->id])
        ->getJson("/api/v1/finance/opening-balance-batches/{$uuid}")
        ->assertOk()
        ->assertJsonPath('status', OpeningBalanceBatchStatus::Rejected->value)
        ->assertJsonPath('can_submit', false)
        ->json();

    expect(array_column($body['rejected_rows'], 'line_number'))->toBe([2, 3]);

    $codes = collect($body['rejected_rows'])
        ->flatMap(fn (array $row) => array_column($row['findings'], 'code'))
        ->unique()->values()->all();

    expect($codes)->toBe(['student_total_mismatch']);

    // BOTH SIDES of the failed check reach the operator — that is what makes the finding actionable,
    // and 4a's docblock said this screen is where those figures would be read from.
    //
    // GROUPED, since ADR 0054. This assertion used to read '145000.00' with a comment explaining
    // that Money::toNaira() renders plain decimals and that the test asserted it "as it actually is
    // rather than as a reader might expect it to look" — an honest note about a real gap, and the
    // gap is now closed rather than documented: the screen renders what the operator's own stat
    // tile renders.
    expect($body['rejected_rows'][0]['findings'][0]['message'])
        ->toContain('₦145,000.00')
        ->and($body['rejected_rows'][0]['findings'][0]['message'])->toContain('₦145,000.01');

    // THE PRIVACY RULE, ASSERTED RATHER THAN TRUSTED. The whole payload is searched for the
    // student's NAME: the report is line numbers and admission numbers, and this screen is the same
    // report reaching a wider audience.
    $student = ActiveSchool::runFor($ctx['school']->id, fn () => Student::query()->firstOrFail());
    expect(json_encode($body))->not->toContain($student->first_name)
        ->and(json_encode($body))->toContain('ADM-BAD');
});

// ── PROOF — L2 is a BATCH finding and rejects no row ─────────────────────────────────────────────

it('PROOF — an L2 failure surfaces as a BATCH finding, not a row one, and rejects nothing', function () {
    // §1 L1 and L2 are DIFFERENT checks. L1 is file integrity — it can reject a student's rows. L2 is
    // completeness against WCBS, and a control total that disagrees says nothing about any single
    // row, so it must never mark one. The file below is internally perfect; only the attestation
    // differs.
    $ctx = obosSchool();
    $maker = obosUser($ctx['school'], [OBOS_ACCESS, OBOS_SUBMIT]);
    obosStudent($ctx['school'], 'ADM-1');

    $uuid = obosUpload($maker, $ctx, obosCsv([
        'ADM-1,W1,Tuition,100000.00,100000.00,BILL-1',
    ]), '999999.00')->assertCreated()->json('uuid');

    $body = test()->actingAs($maker)->withSession(['school_id' => $ctx['school']->id])
        ->getJson("/api/v1/finance/opening-balance-batches/{$uuid}")
        ->assertOk()->json();

    expect($body['status'])->toBe(OpeningBalanceBatchStatus::Rejected->value)
        ->and(array_column($body['findings'], 'code'))->toContain('control_total_mismatch')
        // THE ROW SIDE IS EMPTY. This is the assertion that makes the arm about L2's SCOPE rather
        // than merely about it firing.
        ->and($body['rejected_rows'])->toBe([])
        // …and the row was still staged, because L2 rejects nothing.
        ->and($body['row_count'])->toBe(1);
});

// ── PROOF — only a `validated` batch may be offered for approval ─────────────────────────────────

it('PROOF — a maker cannot submit a batch that is not validated', function () {
    $ctx = obosSchool();
    $maker = obosUser($ctx['school'], [OBOS_ACCESS, OBOS_SUBMIT]);
    obosStudent($ctx['school'], 'ADM-1');

    // A batch the validator rejected — the state a maker is most likely to try to push past.
    $uuid = obosUpload($maker, $ctx, obosCsv([
        'ADM-1,W1,Tuition,100000.00,100000.00,BILL-1',
    ]), '1.00')->assertCreated()->json('uuid');

    test()->actingAs($maker)->withSession(['school_id' => $ctx['school']->id])
        ->postJson("/api/v1/finance/opening-balance-batches/{$uuid}/submit")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Only a validated opening-balance batch can be submitted for approval; this one is rejected.');

    ActiveSchool::runFor($ctx['school']->id, function () use ($uuid) {
        $batch = OpeningBalanceBatch::query()->where('uuid', $uuid)->firstOrFail();
        expect($batch->status)->toBe(OpeningBalanceBatchStatus::Rejected)
            ->and($batch->submitted_by_user_id)->toBeNull();
    });
});

it('submits a VALIDATED batch, and it then appears on the checker\'s pending queue', function () {
    $ctx = obosSchool();
    $maker = obosUser($ctx['school'], [OBOS_ACCESS, OBOS_SUBMIT]);
    $checker = obosUser($ctx['school'], [OBOS_ACCESS, OBOS_APPROVE]);
    obosStudent($ctx['school'], 'ADM-1');

    $uuid = obosUpload($maker, $ctx, obosCsv([
        'ADM-1,W1,Tuition,100000.00,100000.00,BILL-1',
    ]), '100000.00')->assertCreated()->json('uuid');

    test()->actingAs($maker)->withSession(['school_id' => $ctx['school']->id])
        ->postJson("/api/v1/finance/opening-balance-batches/{$uuid}/submit")
        ->assertOk()
        ->assertJsonPath('status', OpeningBalanceBatchStatus::Submitted->value);

    // THE TWO HALVES MEET. Before this commit nothing could reach `submitted`, so the queue that
    // 5a built and 5b-ii made decidable rendered zero rows on every real database.
    test()->actingAs($checker)->withSession(['school_id' => $ctx['school']->id])
        ->getJson('/api/v1/finance/opening-balance-batches/pending')
        ->assertOk()
        ->assertJsonPath('data.0.batch_reference', OpeningBalanceBatch::query()->where('uuid', $uuid)->value('batch_reference'))
        ->assertJsonPath('data.0.can_approve', true);
});

// ── §7's idempotency key, at the ENGINE ──────────────────────────────────────────────────────────

it('refuses a second upload under the same batch reference, at the database', function () {
    $ctx = obosSchool();
    $maker = obosUser($ctx['school'], [OBOS_ACCESS, OBOS_SUBMIT]);
    obosStudent($ctx['school'], 'ADM-1');

    $line = 'ADM-1,W1,Tuition,100000.00,100000.00,BILL-1';

    obosUpload($maker, $ctx, obosCsv([$line]), '100000.00', ['batch_reference' => 'WCBS-ONCE'])
        ->assertCreated();

    // 1062 from `unique(school_id, batch_reference)`, mapped to 409 by bootstrap/app.php. A guard
    // clause someone can delete is not what stops a cutover being imported twice.
    obosUpload($maker, $ctx, obosCsv([$line]), '100000.00', ['batch_reference' => 'WCBS-ONCE'])
        ->assertStatus(409);

    expect(ActiveSchool::runFor($ctx['school']->id, fn () => OpeningBalanceBatch::query()->count()))->toBe(1);
});

// ── The gate on every maker route ────────────────────────────────────────────────────────────────

it('PROOF — the template and every maker route 403 for a user without the maker ability', function () {
    // finance.access alone reaches the module, and the CHECKER's ability is included deliberately:
    // the person who approves a cutover is not thereby the person who uploads one, and neither is
    // admitted by the group gate.
    $ctx = obosSchool();
    $checker = obosUser($ctx['school'], [OBOS_ACCESS, OBOS_APPROVE]);
    $maker = obosUser($ctx['school'], [OBOS_ACCESS, OBOS_SUBMIT]);
    obosStudent($ctx['school'], 'ADM-1');

    $uuid = obosUpload($maker, $ctx, obosCsv([
        'ADM-1,W1,Tuition,100000.00,100000.00,BILL-1',
    ]), '100000.00')->assertCreated()->json('uuid');

    $as = fn (User $u) => test()->actingAs($u)->withSession(['school_id' => $ctx['school']->id]);

    $as($checker)->get('/api/v1/finance/opening-balance-batches/import/template')->assertForbidden();
    $as($checker)->getJson('/api/v1/finance/opening-balance-batches')->assertForbidden();
    $as($checker)->getJson("/api/v1/finance/opening-balance-batches/{$uuid}")->assertForbidden();
    $as($checker)->get("/api/v1/finance/opening-balance-batches/{$uuid}/report")->assertForbidden();
    $as($checker)->postJson("/api/v1/finance/opening-balance-batches/{$uuid}/submit")->assertForbidden();
    $as($checker)->postJson('/api/v1/finance/opening-balance-batches', [])->assertForbidden();

    // Not vacuous: the SAME requests succeed for the maker, so the 403s above are the ability and
    // not a broken route.
    $as($maker)->get('/api/v1/finance/opening-balance-batches/import/template')->assertOk();
    $as($maker)->getJson("/api/v1/finance/opening-balance-batches/{$uuid}")->assertOk();
});

// ── The operator screen itself ───────────────────────────────────────────────────────────────────

it('serves the operator screen to a maker, with the terms it needs, and 403s a checker', function () {
    $ctx = obosSchool();

    // THE `terms` PROP IS ASSERTED, NOT JUST THE 200, and this arm is here because the 200 alone
    // was green while the prop was EMPTY: the route bound `ActiveSchool::getOrFail()` — a School
    // MODEL — into `where('school_id', …)`, which matched nothing and rendered a form whose term
    // select had no options and whose submit button could never enable. A browser drive found it;
    // an assertion on the page rendering never could.
    test()->actingAs(obosUser($ctx['school'], [OBOS_ACCESS, OBOS_SUBMIT]))
        ->withSession(['school_id' => $ctx['school']->id])
        ->get('/finance/opening-balances/import')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/finance/opening-balances/import')
            ->has('terms', 1)
            ->where('terms.0.id', $ctx['term']->id));

    test()->actingAs(obosUser($ctx['school'], [OBOS_ACCESS, OBOS_APPROVE]))
        ->withSession(['school_id' => $ctx['school']->id])
        ->get('/finance/opening-balances/import')
        ->assertForbidden();
});

// ── FIX 3 — an .xlsx gets a sentence, not Laravel's default ──────────────────────────────────────

it('refuses a REAL .xlsx with a sentence that tells the operator what to do about it', function () {
    // THE MISTAKE THE FLOW INVITES. The template is a CSV; a data team opens it in Excel to fill it
    // in and saves it back as a workbook. Laravel's default — "The file must be a file of type: csv,
    // txt" — names the rule and not the remedy, on the one day this feature is used.
    //
    // A REAL xlsx, not a renamed text file: `mimes` sniffs the contents, so a fake would be refused
    // for a reason this arm is not about.
    $ctx = obosSchool();
    $maker = obosUser($ctx['school'], [OBOS_ACCESS, OBOS_SUBMIT]);

    $xlsx = UploadedFile::fake()->createWithContent(
        'filled-template.xlsx',
        Excel::raw(new OpeningBalanceImportTemplateExport, ExcelFormat::XLSX),
    );

    $response = obosUpload($maker, $ctx, $xlsx, '145000.00');

    $response->assertStatus(422)->assertJsonValidationErrors('file');

    expect($response->json('errors.file.0'))
        ->toContain('reads CSV only')
        ->and($response->json('errors.file.0'))->toContain('Save As')
        // The default message must be GONE, not merely accompanied.
        ->and($response->json('errors.file.0'))->not->toContain('must be a file of type');

    // And nothing was staged: a refused upload spends no idempotency key.
    expect(ActiveSchool::runFor($ctx['school']->id, fn () => OpeningBalanceBatch::query()->count()))->toBe(0);
});

// ── FIX 2 — the Columns and Notes sheets, now on the screen ──────────────────────────────────────

it('renders the FORMAT on the screen, from the same map the template renders', function () {
    // The template is a single-sheet CSV and cannot carry a format reference. These rules did not
    // vanish with the sheets — they moved here, and they are read from the same constants, so the
    // file an operator fills in and the reference beside it cannot drift.
    $ctx = obosSchool();

    test()->actingAs(obosUser($ctx['school'], [OBOS_ACCESS, OBOS_SUBMIT]))
        ->withSession(['school_id' => $ctx['school']->id])
        ->get('/finance/opening-balances/import')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('columns', count(ImportOpeningBalances::COLUMNS))
            ->where('columns.0.column', array_key_first(ImportOpeningBalances::COLUMNS))
            ->where('columns.0.notes', ImportOpeningBalances::COLUMNS[array_key_first(ImportOpeningBalances::COLUMNS)]['notes'])
            ->has('notes', count(OpeningBalanceImportTemplateExport::NOTES))
            ->etc());
});

// ── The report download ──────────────────────────────────────────────────────────────────────────

it('streams a report carrying the batch findings and the rejected rows, and no student name', function () {
    $ctx = obosSchool();
    $maker = obosUser($ctx['school'], [OBOS_ACCESS, OBOS_SUBMIT]);
    obosStudent($ctx['school'], 'ADM-BAD');

    $uuid = obosUpload($maker, $ctx, obosCsv([
        'ADM-BAD,W2,Tuition,100000.00,145000.01,BILL-2',
        'ADM-BAD,W2,Bus,45000.00,145000.01,BILL-2',
    ]), '145000.01')->assertCreated()->json('uuid');

    $response = test()->actingAs($maker)->withSession(['school_id' => $ctx['school']->id])
        ->get("/api/v1/finance/opening-balance-batches/{$uuid}/report")
        ->assertOk();

    $csv = $response->streamedContent();
    $student = ActiveSchool::runFor($ctx['school']->id, fn () => Student::query()->firstOrFail());

    expect($csv)->toContain('scope,line_number,admission_number,code,message')
        ->and($csv)->toContain('student_total_mismatch')
        ->and($csv)->toContain('ADM-BAD')
        // A FILE LEAVES THE BUILDING HERE, which is why the same rule is asserted twice.
        ->and($csv)->not->toContain($student->first_name);
});

// ── Isolation ────────────────────────────────────────────────────────────────────────────────────

it('cannot read another School\'s batch', function () {
    // Inherited from BelongsToSchool + SchoolScope rather than written, which is exactly why it is
    // asserted: an inherited guarantee nobody checks is the kind a later withoutGlobalScopes()
    // quietly removes.
    $mine = obosSchool();
    $theirs = obosSchool();

    $myMaker = obosUser($mine['school'], [OBOS_ACCESS, OBOS_SUBMIT]);
    $theirMaker = obosUser($theirs['school'], [OBOS_ACCESS, OBOS_SUBMIT]);
    obosStudent($theirs['school'], 'ADM-1');

    $uuid = obosUpload($theirMaker, $theirs, obosCsv([
        'ADM-1,W1,Tuition,100000.00,100000.00,BILL-1',
    ]), '100000.00')->assertCreated()->json('uuid');

    test()->actingAs($myMaker)->withSession(['school_id' => $mine['school']->id])
        ->getJson("/api/v1/finance/opening-balance-batches/{$uuid}")
        ->assertNotFound();
});
