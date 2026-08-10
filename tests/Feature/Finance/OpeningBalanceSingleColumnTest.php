<?php

/*
 * §9 — THE CUTOVER FILE IS ONE MONEY COLUMN.
 *
 * Brookstone's extract carries three columns — Admission Number, Balance, Last Amended On — and no
 * split by fee type. The architecture is unchanged and the POSTING PATH IS NOT TOUCHED: a single
 * synthetic fee type feeds PostOpeningBalanceBatch's two steps exactly as several would. What
 * changed is the file and the validator.
 *
 * THE SIGN CONVENTION IS THE LOAD-BEARING FACT, confirmed in writing by the project lead and held in
 * OpeningBalanceFileValidator::SIGN_CONVENTION:
 *
 *   NEGATIVE = the school owes the family; they paid in advance and are in CREDIT.
 *   POSITIVE = the family owes the school; ARREARS.
 *
 * Both directions are proved against the LEDGER, not against the staged rows, because the staged row
 * is just the file read back — it is the posting that decides what the sign means to a parent. Swap
 * either mapping and the arms below go red.
 *
 * THE FIXTURE IS BROOKSTONE'S ACTUAL SAMPLE: six rows, -846100, 0, 29000, -2597500, -61800, 0,
 * Σ = -3,476,400. Two of the six are zero, which is the case a "sum is right" test would miss.
 */

use App\Enums\TermStatusEnum;
use App\Finance\Actions\PostOpeningBalanceBatch;
use App\Finance\Enums\LedgerEntryType;
use App\Finance\Enums\OpeningBalanceBatchStatus;
use App\Finance\Enums\OpeningBalanceRowStatus;
use App\Finance\Models\OpeningBalanceBatch;
use App\Finance\Models\OpeningBalanceRow;
use App\Finance\Models\Payment;
use App\Finance\Services\OpeningBalanceFileValidator;
use App\Finance\Services\OpeningBalanceInterpretation;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Term;
use App\Models\User;
use App\Support\ActiveSchool;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** THE HEADER BROOKSTONE ACTUALLY SENDS — three columns, and only one of them is money. */
const OBSC_HEADER = 'admission_number,balance';

/** @return array{school: School, term: Term, level: ClassLevel, arm: ClassLevelArm} */
function obscSchool(): array
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
        $level = ClassLevel::create(['school_id' => $school->id, 'name' => 'JSS 1', 'order' => 1]);
        $arm = ClassLevelArm::create([
            'school_id' => $school->id, 'class_level_id' => $level->id,
            'arm_id' => Arm::create(['school_id' => $school->id, 'label' => strtoupper(Str::random(3))])->id,
        ]);

        return ['school' => $school, 'term' => $term, 'level' => $level, 'arm' => $arm];
    });
}

function obscStudent(array $ctx, string $admission): Student
{
    return ActiveSchool::runFor($ctx['school']->id, function () use ($ctx, $admission) {
        $student = Student::factory()->create([
            'school_id' => $ctx['school']->id, 'admission_number' => $admission,
        ]);
        StudentCurriculum::create([
            'student_id' => $student->id, 'school_id' => $ctx['school']->id,
            'curriculum_id' => Curriculum::factory()->create([
                'school_id' => $ctx['school']->id,
                'class_level_arm_id' => $ctx['arm']->id,
                'term_id' => $ctx['term']->id,
            ])->id,
            'status' => 'active',
        ]);

        return $student;
    });
}

function obscCsv(array $lines, string $header = OBSC_HEADER): string
{
    $path = tempnam(sys_get_temp_dir(), 'obsc').'.csv';
    file_put_contents($path, $header."\n".implode("\n", $lines)."\n");

    return $path;
}

function obscRun(array $ctx, string $csv, array $overrides = []): int
{
    return test()->artisan('finance:import-opening-balances', array_merge([
        '--file' => $csv,
        '--school' => (string) $ctx['school']->id,
        '--closing-term' => (string) $ctx['term']->id,
        '--as-at' => '2026-08-06',
        '--control-total' => '0.00',
        '--batch-reference' => 'BATCH-'.Str::random(8),
        '--dry-run' => true,
    ], $overrides))->run();
}

/** Post the batch: submit it, then run the posting Action directly — the approval's own call. */
function obscPost(array $ctx): OpeningBalanceBatch
{
    return ActiveSchool::runFor($ctx['school']->id, function () use ($ctx) {
        $batch = OpeningBalanceBatch::query()->firstOrFail();
        $batch->update(['status' => OpeningBalanceBatchStatus::Submitted]);

        return app(PostOpeningBalanceBatch::class)
            ->handle($batch, User::factory()->create(['school_id' => $ctx['school']->id]));
    });
}

// ── The file format ──────────────────────────────────────────────────────────

it('accepts a file carrying ONLY admission_number and balance', function () {
    // THE WHOLE POINT. Before this commit the header check refused this file outright — three of the
    // six columns were required — and no row was ever read.
    $ctx = obscSchool();
    obscStudent($ctx, 'STU001');

    $code = obscRun($ctx, obscCsv(['STU001,-8461.00']), ['--control-total' => '-8461.00']);

    expect($code)->toBe(0,
        'A file with only the join key and the money column was refused. That is Brookstone\'s actual '
        .'extract, and refusing it is the thing this commit exists to stop.');

    $batch = OpeningBalanceBatch::withoutGlobalScopes()->firstOrFail();
    expect($batch->status)->toBe(OpeningBalanceBatchStatus::Validated)
        ->and($batch->row_count)->toBe(1);
});

it('files an absent fee type under the constant, and honours a supplied one verbatim', function () {
    // BOTH HALVES IN ONE ARM, because either alone would pass an implementation that got the other
    // wrong — always applying the constant, or never applying it.
    $ctx = obscSchool();
    obscStudent($ctx, 'STU001');
    obscStudent($ctx, 'STU002');

    obscRun($ctx,
        obscCsv(['STU001,Tuition,5000.00', 'STU002,,7000.00'], 'admission_number,fee_type_label,balance'),
        ['--control-total' => '12000.00']);

    $labels = OpeningBalanceRow::withoutGlobalScopes()
        ->orderBy('line_number')->pluck('fee_type_label', 'admission_number')->all();

    expect($labels['STU001'])->toBe('Tuition',
        'A supplied fee type was not honoured. The per-fee-type capability has to survive in the FILE, '
        .'so an extract that CAN split still does, with no code change.')
        ->and($labels['STU002'])->toBe(OpeningBalanceFileValidator::FALLBACK_FEE_TYPE_LABEL,
            'A blank fee type did not take the fallback constant. finance_fee_items carries the label '
            .'verbatim onto the statement narration, so a blank one would read as " — Balance Brought Forward".');
});

it('reaches the statement narration as the constant, spelled exactly once in the codebase', function () {
    // THE CONSTANT IS OPERATOR-VISIBLE AND PERMANENT — the ledger is append-only, so this string is
    // on a parent's statement for as long as the migrated balance is. Asserted at the NARRATION, not
    // at the staged row, because the staged row is not what anybody reads.
    $ctx = obscSchool();
    obscStudent($ctx, 'STU001');

    obscRun($ctx, obscCsv(['STU001,29000.00']), ['--control-total' => '29000.00']);
    obscPost($ctx);

    $narration = DB::table('finance_ledger_transactions')
        ->where('type', LedgerEntryType::Charge->value)->value('narration');

    expect($narration)->toBe(
        OpeningBalanceFileValidator::FALLBACK_FEE_TYPE_LABEL.' — Balance Brought Forward');
});

// ── The sign convention, proved at the LEDGER in both directions ─────────────

it('a POSITIVE balance becomes a ledger CHARGE — the family owes the school', function () {
    $ctx = obscSchool();
    $student = obscStudent($ctx, 'STU001');

    obscRun($ctx, obscCsv(['STU001,29000.00']), ['--control-total' => '29000.00']);
    obscPost($ctx);

    $entries = DB::table('finance_ledger_transactions')->where('student_id', $student->id)->get();

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->type)->toBe(LedgerEntryType::Charge->value,
            'A POSITIVE balance did not post a CHARGE. Under the confirmed convention positive is '
            .'ARREARS — the family owes the school — and a charge is the only entry that says so.')
        ->and((int) $entries[0]->amount_minor)->toBe(2900000);

    expect(Payment::withoutGlobalScopes()->count())->toBe(0,
        'A positive balance created a payment. That would credit a family that owes money.');
});

it('a NEGATIVE balance becomes a migrated PAYMENT — the school owes the family', function () {
    $ctx = obscSchool();
    $student = obscStudent($ctx, 'STU001');

    obscRun($ctx, obscCsv(['STU001,-8461.00']), ['--control-total' => '-8461.00']);
    obscPost($ctx);

    $payment = Payment::withoutGlobalScopes()->firstOrFail();
    expect($payment->amount->toKobo())->toBe(846100,
        'A NEGATIVE balance did not net into a migrated payment of its magnitude. Under the confirmed '
        .'convention negative is CREDIT — they paid in advance — and a payment is what carries that '
        .'forward onto the next invoice.')
        ->and($payment->origin)->toBe('migrated')
        ->and($payment->bank_account_id)->toBeNull();

    // And no charge: a credit that also raised a charge would net to zero and read as "square".
    expect(DB::table('finance_ledger_transactions')
        ->where('student_id', $student->id)->where('type', LedgerEntryType::Charge->value)->count())
        ->toBe(0,
            'A negative balance also raised a charge. The family would be billed for money they had '
            .'already paid.');
});

// ── Zero rows ────────────────────────────────────────────────────────────────

it('stages and COUNTS a zero row, and posts nothing for it', function () {
    // TWO OF BROOKSTONE'S SIX ROWS ARE ZERO. If a zero were dropped instead of staged, the file's row
    // count would not reconcile against what was ingested and the ingest-completeness finding — the
    // one control that says a row went missing — would go quiet.
    $ctx = obscSchool();
    obscStudent($ctx, 'STU001');
    obscStudent($ctx, 'STU002');

    obscRun($ctx, obscCsv(['STU001,0.00', 'STU002,29000.00']), ['--control-total' => '29000.00']);

    $batch = OpeningBalanceBatch::withoutGlobalScopes()->firstOrFail();
    expect($batch->row_count)->toBe(2, 'A zero row was not STAGED.')
        ->and($batch->file_row_count)->toBe(2, 'A zero row was not COUNTED as read.')
        ->and($batch->findings ?? [])->toBe([],
            'A zero row raised a batch finding. A student who is square is a real statement about '
            .'that student, not a defect in the file.');

    $zero = OpeningBalanceRow::withoutGlobalScopes()->where('admission_number', 'STU001')->firstOrFail();
    expect($zero->status)->toBe(OpeningBalanceRowStatus::Ok)
        ->and(array_column($zero->findings ?? [], 'code'))->toContain('nothing_to_post');

    obscPost($ctx);

    // One ledger row in total — the OTHER student's charge. The zero posted nothing.
    expect(DB::table('finance_ledger_transactions')->count())->toBe(1,
        'A zero balance posted a ledger row. A zero movement is a movement that did not happen.');
});

// ── The optional columns ─────────────────────────────────────────────────────

it('does not reject a student for stating no total, and still checks one that is stated', function () {
    // L1 USED TO REJECT THIS. `student_total_balance` absent meant `l1_not_checkable`, which rejects
    // the student's whole row-group — so on Brookstone's file every single row would have failed.
    $ctx = obscSchool();
    obscStudent($ctx, 'STU001');
    obscStudent($ctx, 'STU002');

    // STU001 states no total. STU002 states a WRONG one and must still be caught.
    obscRun($ctx,
        obscCsv([
            'STU001,Tuition,5000.00,',
            'STU002,Tuition,7000.00,9999.00',
        ], 'admission_number,fee_type_label,balance,student_total_balance'),
        ['--control-total' => '14999.00']);

    $rows = OpeningBalanceRow::withoutGlobalScopes()->get()->keyBy('admission_number');

    expect($rows['STU001']->status)->toBe(OpeningBalanceRowStatus::Ok,
        'A student was rejected for not stating a total, which the optional column expressly permits.');
    expect($rows['STU002']->status)->toBe(OpeningBalanceRowStatus::Rejected,
        'A WRONG stated total was not caught. The column is optional; it is not advisory.');
    expect(array_column($rows['STU002']->findings ?? [], 'code'))->toContain('student_total_mismatch');
});

it('L2 still has a left-hand side when the file states no totals at all', function () {
    // The batch control total is untouched and remains the operator's attestation, so L2 must still
    // compare against something. With no stated totals the position is DERIVED from each student's
    // own balances — and the derivation is NAMED on a mismatch rather than passed off as the file's
    // word. Without it, L2 would sum over zero students and report every conforming file as wrong.
    $ctx = obscSchool();
    obscStudent($ctx, 'STU001');
    obscStudent($ctx, 'STU002');

    $clean = obscRun($ctx, obscCsv(['STU001,-8461.00', 'STU002,29000.00']), ['--control-total' => '20539.00']);
    expect($clean)->toBe(0, 'L2 failed on a file whose balances sum to exactly the control total.');

    // And it still bites: the same file with a control total that is wrong by one kobo.
    $ctx2 = obscSchool();
    obscStudent($ctx2, 'STU001');
    obscRun($ctx2, obscCsv(['STU001,-8461.00']), ['--control-total' => '-8461.01']);

    $findings = OpeningBalanceBatch::withoutGlobalScopes()
        ->where('school_id', $ctx2['school']->id)->firstOrFail()->findings ?? [];

    // str_contains + toBeTrue, NOT toContain — Pest reads toContain's second argument as ANOTHER
    // NEEDLE, so a message passed there is asserted as a substring and always fails.
    expect(in_array('control_total_mismatch', array_column($findings, 'code'), true))->toBeTrue(
        'L2 accepted a control total that disagrees with the file. Deriving the left-hand side must '
        .'not turn the check off.');
    expect(implode(' ', array_column($findings, 'message')))->toContain('DERIVED');
});

// ── The interpretation summary — the control nobody had ──────────────────────

it('states its own reading of Brookstone’s actual six rows', function () {
    // THE ONE CONTROL AGAINST AN INVERTED SIGN CONVENTION. Σ of the balances IS the control total, so
    // an operator reading the figure off the same file matches perfectly under an inverted reading
    // and the batch posts silently — permanently, because G1b means a posted batch cannot be undone.
    // The summary converts that invisible inversion into a claim a human has to agree with.
    $ctx = obscSchool();
    foreach (['STU001', 'STU002', 'STU003', 'STU004', 'STU005', 'STU006'] as $admission) {
        obscStudent($ctx, $admission);
    }

    // Brookstone's sample, verbatim: -846100, 0, 29000, -2597500, -61800, 0 (kobo), Σ = -3,476,400.
    obscRun($ctx, obscCsv([
        'STU001,-8461.00',
        'STU002,0.00',
        'STU003,290.00',
        'STU004,-25975.00',
        'STU005,-618.00',
        'STU006,0.00',
    ]), ['--control-total' => '-34764.00']);

    $batch = OpeningBalanceBatch::withoutGlobalScopes()->firstOrFail();
    $summary = ActiveSchool::runFor($ctx['school']->id,
        fn () => app(OpeningBalanceInterpretation::class)->for($batch));

    expect($summary['students'])->toBe(6)
        ->and($summary['credit_students'])->toBe(3)
        ->and($summary['credit_total']->toKobo())->toBe(3505400)   // 846100 + 2597500 + 61800
        ->and($summary['arrears_students'])->toBe(1)
        ->and($summary['arrears_total']->toKobo())->toBe(29000)
        ->and($summary['square_students'])->toBe(2)
        ->and($summary['net']->toKobo())->toBe(-3476400,
            'The net position does not match Brookstone\'s stated Σ of -3,476,400 kobo.');

    // THE SENTENCE, in the direction a bursar reads. "The school OWES FAMILIES" cannot be read two
    // ways; a signed figure alone can, which is the confusion this control exists to catch.
    expect($summary['sentence'])->toContain('school OWES FAMILIES')
        ->and($summary['sentence'])->toContain('3 student(s) are in CREDIT')
        ->and($summary['sentence'])->toContain('2 student(s) are square');

    // And the convention is quoted from the constant, not re-worded here or on the screen.
    expect($summary['convention'])->toBe(OpeningBalanceFileValidator::SIGN_CONVENTION);
});

it('states the magnitude in the sentence, grouped, to the kobo', function () {
    // THE ARM THAT DID NOT EXIST, and its absence is why a drive was what caught the problem. The
    // arms above assert the COUNTS and the direction; none of them asserted the FIGURES, so a
    // summary off by a factor of 100 — the error a cutover cannot survive and cannot undo — would
    // have passed every one of them.
    //
    // It is asserted as the exact rendered STRING, not as kobo, because the string is the control.
    // A bursar does not read `amount_minor`; they read a sentence, and it has to be legible at a
    // glance: `₦3,505,400.00` is, `₦3505400.00` is a seven-digit run somebody has to count. The
    // page's own formatNaira groups; this now matches it, so the two renderings of one figure on
    // one screen cannot disagree in shape.
    //
    // Brookstone's actual sample, in NAIRA as they send it: -846100, 0, 29000, -2597500, -61800, 0.
    $ctx = obscSchool();
    foreach (['STU001', 'STU002', 'STU003', 'STU004', 'STU005', 'STU006'] as $admission) {
        obscStudent($ctx, $admission);
    }

    obscRun($ctx, obscCsv([
        'STU001,-846100.00',
        'STU002,0.00',
        'STU003,29000.00',
        'STU004,-2597500.00',
        'STU005,-61800.00',
        'STU006,0.00',
    ]), ['--control-total' => '-3476400.00']);

    $summary = ActiveSchool::runFor($ctx['school']->id, fn () => app(OpeningBalanceInterpretation::class)
        ->for(OpeningBalanceBatch::withoutGlobalScopes()->firstOrFail()));

    // The kobo, so a formatter change cannot quietly move the underlying figure either.
    expect($summary['net']->toKobo())->toBe(-347640000)
        ->and($summary['credit_total']->toKobo())->toBe(350540000)
        ->and($summary['arrears_total']->toKobo())->toBe(2900000);

    // And the sentence, verbatim — the thing that is actually quoted at whoever runs the cutover.
    expect($summary['sentence'])->toBe(
        '3 student(s) are in CREDIT — the school owes them ₦3,505,400.00 in total. '
        .'1 student(s) are in ARREARS — they owe the school ₦29,000.00 in total. '
        .'2 student(s) are square and will post nothing. '
        .'Net: the school OWES FAMILIES ₦3,476,400.00. '
        .'If that is not what this file means, the sign convention is inverted — do not approve it.');
});

it('reverses its sentence when the school is owed', function () {
    // The other direction, because a summary that says "OWES FAMILIES" unconditionally would pass the
    // arm above while being useless — it is a sentence a bursar has to be able to DISAGREE with.
    $ctx = obscSchool();
    obscStudent($ctx, 'STU001');

    obscRun($ctx, obscCsv(['STU001,29000.00']), ['--control-total' => '29000.00']);

    $summary = ActiveSchool::runFor($ctx['school']->id, fn () => app(OpeningBalanceInterpretation::class)
        ->for(OpeningBalanceBatch::withoutGlobalScopes()->firstOrFail()));

    expect($summary['sentence'])->toContain('school IS OWED')
        ->and($summary['sentence'])->not->toContain('OWES FAMILIES');
});

it('carries the interpretation onto the operator’s screen payload', function () {
    // The summary has to reach the person who clicks "Submit for approval", not just the console.
    // Asserted through the HTTP surface the screen actually polls.
    $ctx = obscSchool();
    obscStudent($ctx, 'STU001');
    obscRun($ctx, obscCsv(['STU001,-8461.00']), ['--control-total' => '-8461.00']);

    $user = User::factory()->create(['school_id' => $ctx['school']->id]);
    // accounts_officer, NOT admin: `finance.opening-balance.submit` gates this route and the bursar
    // is the maker who holds it (RbacSeeder:396). admin does not, which a 403 said plainly.
    $user->grantSchoolAccess($ctx['school'], 'accounts_officer');
    $user->flushSchoolAccessCache();

    $batch = OpeningBalanceBatch::withoutGlobalScopes()->firstOrFail();

    $this->actingAs($user)->withSession(['school_id' => $ctx['school']->id])
        ->getJson('/api/v1/finance/opening-balance-batches/'.$batch->uuid)
        ->assertOk()
        ->assertJsonPath('interpretation.credit_students', 1)
        ->assertJsonPath('interpretation.square_students', 0)
        ->assertJsonPath('interpretation.convention', OpeningBalanceFileValidator::SIGN_CONVENTION);
});
