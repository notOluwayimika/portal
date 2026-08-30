<?php

/*
 * THE MANUAL INVOICE RUN — claim-then-bill, and the one-active-run guard.
 *
 * Slice 1 of docs/handoff/bulk-manual-invoicing-brief.md. Every guard below was PLANTED and watched
 * red before it was believed; the plants and their verbatim red text are in the branch report. The
 * claims these arms exist to break, in the order they matter:
 *
 *   1. A RE-EXECUTION MUST NOT PRODUCE A SECOND INVOICE. This is the whole commit. On the scheduled
 *      path the row is written AFTER the invoice (ProcessBulkInvoiceRun:446 bills, :593 records), so
 *      its unique index sits downstream of the money and a re-execution leaves a duplicate invoice
 *      that no row records. Here the row is written FIRST, and the index refuses the second claim
 *      before GenerateInvoice is reached. The discriminating proof is the mutation: with the claim
 *      write removed, arm 1b produces the duplicate.
 *
 *   2. THE REFUSAL MUST BE AT THE ENGINE, not in the job. Arm 1c bypasses the job entirely and
 *      raw-inserts a duplicate claim.
 *
 *   3. THE COHORT EQUALITY MUST GO SHORT ON A STUCK CLAIM AND MUST BALANCE ON AN UNPLACEABLE ONE.
 *      `billed + failed + unplaceable == target_count`, with `claimed_count` recorded beside it as
 *      the diagnosis and NOT as a term. The line between the two is whether anything is unknown.
 *
 *   3b. AND `target_count` MUST BE WHAT THE BURSAR TICKED. The targets are keyed on the STUDENT for
 *      exactly this reason: keyed on the enrollment, a student who resolves to nothing could not be
 *      a target at all, and a selection of 96 with six unresolvable would report "90 of 90" —
 *      balanced, complete, and six families short, on a feature that issues with no maker-checker.
 *
 *   4. AT MOST ONE NON-TERMINAL RUN PER SCHOOL, AT THE DATABASE — and the key must RELEASE when the
 *      run reaches a terminal state. Arm 4a is the refusal; arm 4b is its mutation guard, because a
 *      generated column that is `school_id` unconditionally passes 4a and breaks every School's
 *      second run forever.
 *
 *   5. ISOLATION. The run's School is an argument on the job, and the composite FK is what makes a
 *      cross-School claim unrepresentable rather than merely unlikely.
 *
 * THE JOB IS DISPATCHED, NEVER CALLED, and every dispatch happens OUTSIDE any ActiveSchool::runFor —
 * so SchoolAware is under test rather than assumed.
 */

use App\Enums\StudentStatusEnum;
use App\Enums\TermStatusEnum;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Enums\ManualInvoiceRunOutcome;
use App\Finance\Enums\ManualInvoiceRunStatus;
use App\Finance\Jobs\ProcessManualInvoiceRun;
use App\Finance\Models\Invoice;
use App\Finance\Models\ManualInvoiceRun;
use App\Finance\Models\ManualInvoiceRunLine;
use App\Finance\Models\ManualInvoiceRunRow;
use App\Finance\Models\ManualInvoiceRunTarget;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Term;
use App\Support\ActiveSchool;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * A School with one set of coordinates. A manual run does not read coordinates — that is the whole
 * reason it has its own tables — but `student_curricula` needs a curriculum and the curriculum needs
 * somewhere to sit.
 *
 * @return array{school: School, term: Term, level: ClassLevel, arm: ClassLevelArm}
 */
function mirSchool(): array
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
            'school_id' => $school->id,
            'class_level_id' => $level->id,
            'arm_id' => Arm::create(['school_id' => $school->id, 'label' => strtoupper(Str::random(3))])->id,
        ]);

        return compact('school', 'term', 'level', 'arm');
    });
}

/** A student in $ctx's School with one ACTIVE enrollment. Returns the enrollment. */
function mirEnrollment(array $ctx): StudentCurriculum
{
    return ActiveSchool::runFor($ctx['school']->id, function () use ($ctx) {
        $student = mirStudent($ctx);

        return StudentCurriculum::create([
            'student_id' => $student->id,
            'school_id' => $ctx['school']->id,
            'curriculum_id' => Curriculum::factory()->create([
                'school_id' => $ctx['school']->id,
                'class_level_arm_id' => $ctx['arm']->id,
                'term_id' => $ctx['term']->id,
            ])->id,
            'status' => StudentStatusEnum::ACTIVE,
        ]);
    });
}

/**
 * A student with NO enrollment at all — the shape the resolver cannot place, and the shape that
 * could not be represented at all while the targets were keyed on the enrollment.
 */
function mirStudent(array $ctx): Student
{
    return ActiveSchool::runFor($ctx['school']->id, fn () => Student::factory()->create([
        'school_id' => $ctx['school']->id,
        'admission_number' => 'ADM-'.Str::random(8),
    ]));
}

/**
 * The run a controller will insert in the second commit: `pending`, with its lines and its targets
 * already written. Nothing is dispatched here — see {@see mirDispatch()}.
 *
 * A TARGET IS A STUDENT. Pass a `StudentCurriculum` for a student the resolver placed, or a bare
 * `Student` for one it could not — the second writes `enrollment_id = NULL`, which is the whole
 * point of the student-keyed table and is unrepresentable without it.
 *
 * @param  list<StudentCurriculum|Student>  $targets
 */
function mirRun(array $ctx, array $targets, int $amountMinor = 250000): ManualInvoiceRun
{
    return ActiveSchool::runFor($ctx['school']->id, function () use ($ctx, $targets, $amountMinor) {
        $run = ManualInvoiceRun::create([
            'school_id' => $ctx['school']->id,
            'status' => ManualInvoiceRunStatus::Pending,
        ]);

        ManualInvoiceRunLine::create([
            'school_id' => $ctx['school']->id,
            'run_id' => $run->id,
            'description' => 'Replacement locker key',
            'amount' => Money::fromKobo($amountMinor, 'NGN'),
            'bank_account_id' => testBankAccountId($ctx['school']->id),
            'sort_order' => 0,
        ]);

        foreach ($targets as $target) {
            $placed = $target instanceof StudentCurriculum;

            ManualInvoiceRunTarget::create([
                'school_id' => $ctx['school']->id,
                'run_id' => $run->id,
                'student_id' => $placed ? $target->student_id : $target->id,
                'enrollment_id' => $placed ? $target->id : null,
                'enrollment_uuid' => $placed ? $target->uuid : null,
            ]);
        }

        return $run;
    });
}

/** Dispatch on the sync queue with NO ambient context — SchoolAware is what must supply it. */
function mirDispatch(ManualInvoiceRun $run, int $schoolId): ManualInvoiceRun
{
    ProcessManualInvoiceRun::dispatch($run->id, $schoolId);

    return ManualInvoiceRun::withoutGlobalScopes()->find($run->id);
}

/**
 * @return array<string, int> outcome => count, over the rows of one run
 *
 * KEY-SORTED, so an arm compares a SET of buckets and not the order rows happened to be written in.
 * Without it a planted claim ahead of a billed row reds a correct run on nothing but insertion order.
 */
function mirOutcomes(ManualInvoiceRun $run): array
{
    $counts = ManualInvoiceRunRow::withoutGlobalScopes()->where('run_id', $run->id)
        ->get()->groupBy(fn (ManualInvoiceRunRow $row) => $row->outcome->value)
        ->map->count()->all();

    ksort($counts);

    return $counts;
}

/** How many invoices exist for one episode, of any kind. Unscoped: the assertion is about rows. */
function mirInvoiceCount(StudentCurriculum $enrollment): int
{
    return Invoice::withoutGlobalScopes()->where('student_curriculum_id', $enrollment->id)->count();
}

/**
 * The MySQL driver error code a write produced, or null if it was accepted. The assertion is on the
 * CODE, never on the message — a message is prose and a code is the contract.
 */
function mirDriverCode(Closure $write): ?int
{
    try {
        $write();
    } catch (QueryException $e) {
        return (int) ($e->errorInfo[1] ?? 0);
    }

    return null;
}

/**
 * Raw claim-row insert, bypassing the model, the job and every scope.
 *
 * `$studentId` is separable from `$enrollment` on purpose: the rows table now carries TWO unique
 * indexes and an arm that cannot vary the student independently of the episode can only ever exercise
 * one of them.
 */
function mirRawClaim(int $schoolId, int $runId, ?StudentCurriculum $enrollment, string $outcome = 'claimed', ?int $studentId = null): void
{
    DB::table('finance_manual_invoice_run_rows')->insert([
        'uuid' => (string) Str::uuid(),
        'school_id' => $schoolId,
        'run_id' => $runId,
        'student_id' => $studentId ?? $enrollment?->student_id,
        'enrollment_id' => $enrollment?->id,
        'enrollment_uuid' => $enrollment?->uuid,
        'outcome' => $outcome,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** Raw TARGET insert, same purpose one table earlier. */
function mirRawTarget(int $schoolId, int $runId, ?int $studentId, ?int $enrollmentId, ?string $enrollmentUuid): void
{
    DB::table('finance_manual_invoice_run_targets')->insert([
        'uuid' => (string) Str::uuid(),
        'school_id' => $schoolId,
        'run_id' => $runId,
        'student_id' => $studentId,
        'enrollment_id' => $enrollmentId,
        'enrollment_uuid' => $enrollmentUuid,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** Raw run insert, bypassing the model and every scope — the one-active-run guard's only real test. */
function mirRawRun(int $schoolId, string $status): void
{
    DB::table('finance_manual_invoice_runs')->insert([
        'uuid' => (string) Str::uuid(),
        'school_id' => $schoolId,
        'status' => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// ═════════════════════════════════════════════════════════════════════════════════════════════════
// 0 — THE HAPPY PATH, so every arm below has something to be a deviation FROM
// ═════════════════════════════════════════════════════════════════════════════════════════════════

it('bills one supplementary invoice per target and balances billed + failed == target_count', function () {
    $ctx = mirSchool();
    $a = mirEnrollment($ctx);
    $b = mirEnrollment($ctx);

    $run = mirDispatch(mirRun($ctx, [$a, $b]), $ctx['school']->id);

    expect($run->status)->toBe(ManualInvoiceRunStatus::Completed)
        ->and($run->failure_reason)->toBeNull()
        ->and(mirOutcomes($run))->toBe(['billed' => 2])
        ->and($run->target_count)->toBe(2)
        ->and($run->billed_count)->toBe(2)
        ->and($run->failed_count)->toBe(0)
        ->and($run->unplaceable_count)->toBe(0)
        ->and($run->claimed_count)->toBe(0)
        // THE EQUALITY ITSELF, written as the sentence it is rather than left implied by the five
        // figures above. `claimed_count` is deliberately absent from the left-hand side.
        ->and($run->billed_count + $run->failed_count + $run->unplaceable_count)->toBe($run->target_count);

    // ONE invoice each, and SUPPLEMENTARY — not scheduled. The kind is what puts this path outside
    // the generated-column backstop, which is the reason the claim exists at all.
    expect(mirInvoiceCount($a))->toBe(1)->and(mirInvoiceCount($b))->toBe(1);

    $kinds = Invoice::withoutGlobalScopes()
        ->whereIn('student_curriculum_id', [$a->id, $b->id])->pluck('kind')->all();

    expect($kinds)->toBe([InvoiceKind::Supplementary, InvoiceKind::Supplementary]);
});

// ═════════════════════════════════════════════════════════════════════════════════════════════════
// 1 — CLAIM-THEN-BILL. The commit.
// ═════════════════════════════════════════════════════════════════════════════════════════════════

it('1a — a re-execution of the same run raises NO second invoice, and writes no second row', function () {
    $ctx = mirSchool();
    $a = mirEnrollment($ctx);

    $run = mirDispatch(mirRun($ctx, [$a]), $ctx['school']->id);

    expect(mirInvoiceCount($a))->toBe(1)
        ->and(mirOutcomes($run))->toBe(['billed' => 1]);

    // THE RE-EXECUTION. Exactly what `tries = 1` exists to prevent on the scheduled path and what
    // this path must survive without it: the same job, the same run id, dispatched again. The run is
    // `completed`, so the job re-walks the target list from the top.
    $again = mirDispatch($run, $ctx['school']->id);

    // The claim is refused by UNIQUE(school_id, run_id, enrollment_id) BEFORE GenerateInvoice is
    // reached, so no invoice is raised and the existing row is untouched.
    expect(mirInvoiceCount($a))->toBe(1)
        ->and(mirOutcomes($again))->toBe(['billed' => 1])
        ->and(ManualInvoiceRunRow::withoutGlobalScopes()->where('run_id', $run->id)->count())->toBe(1);

    // AND THE RUN'S OWN REPORT SURVIVES THE SECOND PASS INTACT. The counts are re-derived from the
    // rows PERSISTED, not from what this execution did, so the `billed` row the first pass wrote is
    // still counted and the equality still balances — a re-execution reports the run, not the pass.
    // The run does not wear the word `failed` for having been correctly refused: the nobody-billed
    // rule needs `failed == target_count`, and nothing failed.
    expect($again->status)->toBe(ManualInvoiceRunStatus::Completed)
        ->and($again->billed_count)->toBe(1)
        ->and($again->failed_count)->toBe(0)
        ->and($again->claimed_count)->toBe(0)
        ->and($again->target_count)->toBe(1)
        ->and($again->billed_count + $again->failed_count + $again->unplaceable_count)->toBe($again->target_count);
});

it('1c — the claim index refuses a duplicate at the ENGINE, and no invoice follows it', function () {
    $ctx = mirSchool();
    $a = mirEnrollment($ctx);

    $run = mirDispatch(mirRun($ctx, [$a]), $ctx['school']->id);

    // BYPASSES THE JOB, THE MODEL AND EVERY SCOPE. The claim's whole value is that it is a database
    // refusal rather than a trusted one, so the test that establishes it must not go through the
    // code that would be trusted.
    expect(mirDriverCode(fn () => mirRawClaim($ctx['school']->id, $run->id, $a)))->toBe(1062);

    // THE CLAIM IS THE STUDENT INDEX, and this is the arm that says so rather than leaving it to the
    // schema: same student, a DIFFERENT episode. The enrollment index cannot see this write; the
    // student index refuses it. Without a separable student the two indexes are indistinguishable.
    $other = mirEnrollment($ctx);
    expect(mirDriverCode(fn () => mirRawClaim($ctx['school']->id, $run->id, $other, 'claimed', $a->student_id)))->toBe(1062);

    // AND THE ENROLLMENT INDEX IS STILL LIVE, likewise proved on its own axis: a different student
    // pointed at an episode this run has already recorded. That is the resolver-bug shape — two
    // ticked children mapped onto ONE episode — and it is the last thing between it and a double
    // charge inside a single run.
    expect(mirDriverCode(fn () => mirRawClaim($ctx['school']->id, $run->id, $a, 'claimed', $other->student_id)))->toBe(1062);

    // A different student AND a different episode is a different key on both, and is admitted — so
    // the four refusals above are the composite keys doing their job, not the table refusing every
    // second row.
    expect(mirDriverCode(fn () => mirRawClaim($ctx['school']->id, $run->id, $other)))->toBeNull();

    // NO SECOND INVOICE anywhere as a result of any of those writes.
    expect(mirInvoiceCount($a))->toBe(1)->and(mirInvoiceCount($other))->toBe(0);
});

it('1e — an unplaceable claim is refused a second time too, which an enrollment-keyed index could not do', function () {
    $ctx = mirSchool();
    $orphan = mirStudent($ctx);
    $run = mirRun($ctx, [$orphan]);

    // `enrollment_id` is NULL on both rows, and NULLs DO NOT COLLIDE in a MySQL unique index — so
    // UNIQUE(school_id, run_id, enrollment_id) admits any number of these. The student index is what
    // refuses the second, and without it the new outcome would arrive with a hole shaped exactly
    // like itself.
    expect(mirDriverCode(fn () => mirRawClaim($ctx['school']->id, $run->id, null, 'unplaceable', $orphan->id)))->toBeNull()
        ->and(mirDriverCode(fn () => mirRawClaim($ctx['school']->id, $run->id, null, 'unplaceable', $orphan->id)))->toBe(1062);
});

it('1d — the outcome domain is a trigger: a fourth value is refused 1644, in the exact lowercase', function () {
    $ctx = mirSchool();
    $a = mirEnrollment($ctx);
    $b = mirEnrollment($ctx);
    $run = mirRun($ctx, [$a]);

    // `already_billed` and `sponsored` are the two the scheduled run has and this one deliberately
    // does not. Neither has a producer here, and the database is what makes that true rather than a
    // convention someone can drift away from.
    expect(mirDriverCode(fn () => mirRawClaim($ctx['school']->id, $run->id, $a, 'already_billed')))->toBe(1644)
        ->and(mirDriverCode(fn () => mirRawClaim($ctx['school']->id, $run->id, $a, 'sponsored')))->toBe(1644)
        // COLLATE utf8mb4_bin is load-bearing: without it the table's utf8mb4_unicode_ci makes
        // 'Billed' a legal value that every where('outcome','billed') read would then miss.
        ->and(mirDriverCode(fn () => mirRawClaim($ctx['school']->id, $run->id, $a, 'Billed')))->toBe(1644)
        // And the values that ARE the domain are admitted, so 1644 above is the value list and not
        // the trigger refusing everything. `unplaceable` is now one of them, which is the half of
        // this arm that would have stayed red if the trigger had not been widened with the feature.
        ->and(mirDriverCode(fn () => mirRawClaim($ctx['school']->id, $run->id, $b, 'billed')))->toBeNull()
        ->and(mirDriverCode(fn () => mirRawClaim($ctx['school']->id, $run->id, null, 'unplaceable', mirStudent($ctx)->id)))->toBeNull();
});

// ═════════════════════════════════════════════════════════════════════════════════════════════════
// 3 — THE EQUALITY MUST GO SHORT ON A STUCK CLAIM
// ═════════════════════════════════════════════════════════════════════════════════════════════════

it('3a — a run finishing with a claim outstanding does NOT satisfy billed + failed == target_count', function () {
    $ctx = mirSchool();
    $stuck = mirEnrollment($ctx);
    $ok = mirEnrollment($ctx);

    $run = mirRun($ctx, [$stuck, $ok]);

    // THE STATE A DEATH BETWEEN CLAIM AND BILL LEAVES BEHIND, reconstructed rather than simulated: a
    // `claimed` row with no invoice, planted before the run walks the list. The job's own claim for
    // this target then hits 1062, attempt() logs it, and the row stays `claimed` — which is exactly
    // what a re-execution over a previously-stranded claim does.
    ActiveSchool::runFor($ctx['school']->id, fn () => mirRawClaim($ctx['school']->id, $run->id, $stuck));

    $run = mirDispatch($run, $ctx['school']->id);

    expect(mirOutcomes($run))->toBe(['billed' => 1, 'claimed' => 1])
        ->and($run->target_count)->toBe(2)
        ->and($run->billed_count)->toBe(1)
        ->and($run->failed_count)->toBe(0)
        ->and($run->unplaceable_count)->toBe(0)
        ->and($run->claimed_count)->toBe(1);

    // THE ALARM. Two claims about one sum, and they are different claims: the equality is SHORT, and
    // it is short by exactly the number of stuck claims. The second is what makes `claimed_count`
    // the diagnosis; putting it on the left-hand side would satisfy the first and destroy the alarm.
    expect($run->billed_count + $run->failed_count + $run->unplaceable_count)->not->toBe($run->target_count)
        ->and($run->billed_count + $run->failed_count + $run->unplaceable_count)
        ->toBe($run->target_count - $run->claimed_count);

    // AND NOBODY WAS CHARGED FOR THE STUCK ONE. That is the whole trade this design makes: a visible
    // unknown in place of an invoice with no row.
    expect(mirInvoiceCount($stuck))->toBe(0)->and(mirInvoiceCount($ok))->toBe(1);
});

it('3b — a failed enrollment IS a term of the equality, so the sum still balances', function () {
    $ctx = mirSchool();
    $ok = mirEnrollment($ctx);
    $doomed = mirEnrollment($ctx);

    $run = mirRun($ctx, [$ok, $doomed]);

    // The episode vanishes between the target being written and the invoice being raised — a
    // withdrawal landing mid-run does this, and GenerateInvoice then throws its own sentence. The
    // claim is already committed, so the row exists and is UPDATED to `failed`.
    $run = ActiveSchool::runFor($ctx['school']->id, function () use ($run, $doomed) {
        DB::table('student_curricula')->where('id', $doomed->id)->update(['uuid' => (string) Str::uuid()]);

        return $run;
    });

    $run = mirDispatch($run, $ctx['school']->id);

    expect(mirOutcomes($run))->toBe(['billed' => 1, 'failed' => 1])
        ->and($run->billed_count + $run->failed_count + $run->unplaceable_count)->toBe($run->target_count)
        ->and($run->claimed_count)->toBe(0)
        ->and($run->status)->toBe(ManualInvoiceRunStatus::Completed);

    // A `failed` row carries its reason and NO invoice id — the two branches of step 4 write
    // different columns, so neither state can carry the other's evidence.
    $row = ManualInvoiceRunRow::withoutGlobalScopes()
        ->where('run_id', $run->id)->where('enrollment_id', $doomed->id)->sole();

    expect($row->invoice_id)->toBeNull()->and($row->reason)->not->toBeNull();
    expect(mirInvoiceCount($doomed))->toBe(0);
});

it('3c — a ticked student who resolves to nothing IS counted, lands unplaceable, and the sum balances', function () {
    $ctx = mirSchool();
    $placed = mirEnrollment($ctx);
    $orphan = mirStudent($ctx);   // no enrollment at all — the resolver cannot place them

    $run = mirDispatch(mirRun($ctx, [$placed, $orphan]), $ctx['school']->id);

    // THE NUMBER THE BURSAR TICKED. This is the assertion the whole re-key exists for: keyed on the
    // enrollment, $orphan could not have been a target at all and this run would have reported
    // "1 of 1" over a selection of two — balanced, complete, and one family short.
    expect($run->target_count)->toBe(2)
        ->and(mirOutcomes($run))->toBe(['billed' => 1, 'unplaceable' => 1])
        ->and($run->billed_count)->toBe(1)
        ->and($run->failed_count)->toBe(0)
        ->and($run->unplaceable_count)->toBe(1)
        // NOTHING IS UNKNOWN ABOUT THEM, so this is not a claim and the run is not short.
        ->and($run->claimed_count)->toBe(0)
        ->and($run->billed_count + $run->failed_count + $run->unplaceable_count)->toBe($run->target_count)
        ->and($run->status)->toBe(ManualInvoiceRunStatus::Completed)
        ->and($run->failure_reason)->toBeNull();

    // THE ROW NAMES WHO, which is the deliverable — a bursar who ticked two and billed one must be
    // told which one and why, not left to count. And it carries no episode and no invoice.
    $row = ManualInvoiceRunRow::withoutGlobalScopes()
        ->where('run_id', $run->id)->where('student_id', $orphan->id)->sole();

    expect($row->outcome)->toBe(ManualInvoiceRunOutcome::Unplaceable)
        ->and($row->enrollment_id)->toBeNull()
        ->and($row->enrollment_uuid)->toBeNull()
        ->and($row->invoice_id)->toBeNull()
        ->and($row->reason)->toBeNull();
});

it('3d — a NULLABLE component leaves the enrollment FK unenforced for that row, and ONLY that row', function () {
    $ctx = mirSchool();
    $other = mirSchool();
    $mine = mirEnrollment($ctx);
    $theirs = mirEnrollment($other);
    $orphan = mirStudent($ctx);
    $run = mirRun($ctx, [$mine]);

    // MEASURED, NOT ASSUMED. MySQL's only FK match mode is MATCH SIMPLE
    // (information_schema.REFERENTIAL_CONSTRAINTS.MATCH_OPTION = NONE), so a composite FK is skipped
    // for a row with a NULL component. That is what makes `enrollment_id` nullable possible at all,
    // and it is what this arm pins — because if it ever stopped being true, an unplaceable target
    // could not be recorded and the count of what the bursar ticked would silently narrow again.
    expect(mirDriverCode(fn () => mirRawTarget($ctx['school']->id, $run->id, $orphan->id, null, null)))->toBeNull();

    // AND THE GUARANTEE THE COMPOSITE FK EXISTS FOR IS INTACT FOR EVERY ROW THAT NAMES AN EPISODE.
    $mineB = mirEnrollment($ctx);
    expect(mirDriverCode(fn () => mirRawTarget($ctx['school']->id, $run->id, $theirs->student_id, $theirs->id, $theirs->uuid)))->toBe(1452)
        ->and(mirDriverCode(fn () => mirRawTarget($ctx['school']->id, $run->id, $mineB->student_id, 999999999, (string) Str::uuid())))->toBe(1452);

    // AND NO ROW IS LEFT UNGUARDED, which is the part a nullable FK column would otherwise cost. The
    // School binding is carried by `student_id`, which is NOT NULL and has its own composite FK — so
    // a cross-School student is refused 1452 even on a row whose `enrollment_id` is NULL.
    expect(mirDriverCode(fn () => mirRawTarget($ctx['school']->id, $run->id, $theirs->student_id, null, null)))->toBe(1452)
        ->and(mirDriverCode(fn () => mirRawTarget($ctx['school']->id, $run->id, null, null, null)))->toBe(1048);

    // AND THE TARGET LIST CANNOT NAME THE SAME CHILD TWICE — the docblock says so, so it is pinned
    // here rather than left as a claim about the schema. Refused at the moment the list is written,
    // which is before the run exists to be started. Note it is refused on the STUDENT even when the
    // second entry names a different episode, which an enrollment-keyed table could not do.
    expect(mirDriverCode(fn () => mirRawTarget($ctx['school']->id, $run->id, $mine->student_id, $mine->id, $mine->uuid)))->toBe(1062)
        ->and(mirDriverCode(fn () => mirRawTarget($ctx['school']->id, $run->id, $orphan->id, null, null)))->toBe(1062);
});

// ═════════════════════════════════════════════════════════════════════════════════════════════════
// 4 — ONE NON-TERMINAL RUN PER SCHOOL, AT THE DATABASE
// ═════════════════════════════════════════════════════════════════════════════════════════════════

it('4a — a second non-terminal run is refused 1062 by the generated-column index, at the ENGINE', function () {
    $ctx = mirSchool();

    // A `pending` run exists. Both non-terminal statuses compute the key, so both collide with it.
    ActiveSchool::runFor($ctx['school']->id, fn () => ManualInvoiceRun::create([
        'school_id' => $ctx['school']->id,
        'status' => ManualInvoiceRunStatus::Pending,
    ]));

    expect(mirDriverCode(fn () => mirRawRun($ctx['school']->id, 'pending')))->toBe(1062)
        ->and(mirDriverCode(fn () => mirRawRun($ctx['school']->id, 'running')))->toBe(1062);

    // ANOTHER SCHOOL IS UNAFFECTED — the key is the school_id, so it isolates rather than
    // serialising the whole platform onto one run at a time.
    $other = mirSchool();
    expect(mirDriverCode(fn () => mirRawRun($other['school']->id, 'pending')))->toBeNull();
});

it('4b — the key RELEASES on a terminal status, so a School can run again', function () {
    $ctx = mirSchool();
    $a = mirEnrollment($ctx);

    $first = mirDispatch(mirRun($ctx, [$a]), $ctx['school']->id);
    expect($first->status)->toBe(ManualInvoiceRunStatus::Completed);

    // THE MUTATION GUARD FOR 4a, and it is not optional. A generated column defined as `school_id`
    // unconditionally passes 4a perfectly and refuses every School's SECOND run for ever after —
    // a guard that looks alive while having become a permanent outage. Only a positive arm crossing
    // the axis 4a removes can tell the two apart.
    expect(mirDriverCode(fn () => mirRawRun($ctx['school']->id, 'pending')))->toBeNull();

    // And both terminal states release it, not just the one the happy path happens to reach.
    ActiveSchool::runFor($ctx['school']->id, fn () => DB::table('finance_manual_invoice_runs')
        ->where('school_id', $ctx['school']->id)->update(['status' => 'failed']));

    expect(mirDriverCode(fn () => mirRawRun($ctx['school']->id, 'pending')))->toBeNull();
});

it('4c — the status domain is a trigger: a fifth value is refused 1644', function () {
    $ctx = mirSchool();

    // A fifth non-terminal status added without extending `active_run_key`'s expression would open a
    // hole in 4a shaped exactly like the new value. The database refuses it instead.
    expect(mirDriverCode(fn () => mirRawRun($ctx['school']->id, 'paused')))->toBe(1644)
        ->and(mirDriverCode(fn () => mirRawRun($ctx['school']->id, 'Pending')))->toBe(1644);
});

// ═════════════════════════════════════════════════════════════════════════════════════════════════
// 5 — ISOLATION
// ═════════════════════════════════════════════════════════════════════════════════════════════════

it('5a — a claim naming another School\'s episode is unrepresentable: the composite FK refuses it', function () {
    $a = mirSchool();
    $b = mirSchool();
    $theirs = mirEnrollment($b);

    $run = mirRun($a, [mirEnrollment($a)]);

    // 1452 — the FK, not the unique index. School A's run physically cannot record a row against
    // School B's episode, whatever the job believes.
    expect(mirDriverCode(fn () => mirRawClaim($a['school']->id, $run->id, $theirs)))->toBe(1452);

    // And the same is true one table earlier, of the INSTRUCTION: the target list cannot name it
    // either, so the job is never asked to. BOTH identities are refused independently — the episode
    // by the enrollment FK, and the child by the student FK, which is the one that still holds when
    // the enrollment is NULL.
    expect(mirDriverCode(fn () => mirRawTarget($a['school']->id, $run->id, $theirs->student_id, $theirs->id, $theirs->uuid)))->toBe(1452)
        ->and(mirDriverCode(fn () => mirRawTarget($a['school']->id, $run->id, $theirs->student_id, null, null)))->toBe(1452);
});

// ═════════════════════════════════════════════════════════════════════════════════════════════════
// 6 — THE PER-RUN REFUSALS, both settled before the first claim
// ═════════════════════════════════════════════════════════════════════════════════════════════════

it('6a — a run with no lines is refused whole, having claimed nobody', function () {
    $ctx = mirSchool();
    $a = mirEnrollment($ctx);

    $run = mirRun($ctx, [$a]);
    ActiveSchool::runFor($ctx['school']->id, fn () => ManualInvoiceRunLine::withoutGlobalScopes()
        ->where('run_id', $run->id)->delete());

    $run = mirDispatch($run, $ctx['school']->id);

    // ZERO ROWS EXIST AND NOTHING WAS BILLED — the property every per-run refusal has, and the
    // reason both conditions are settled before the loop. Claiming a hundred targets and then
    // failing every one of them at the Action would turn a caller's mistake into what reads as an
    // outage.
    expect($run->status)->toBe(ManualInvoiceRunStatus::Failed)
        ->and($run->failure_reason)->toContain('no lines')
        ->and(mirOutcomes($run))->toBe([])
        ->and(mirInvoiceCount($a))->toBe(0)
        // The counts stay NULL: a refused run counted nothing, and a 0 here would say "we looked and
        // found none" about a run that never looked.
        ->and($run->target_count)->toBeNull();
});

it('6b — a run with no targets is refused whole', function () {
    $ctx = mirSchool();

    $run = mirDispatch(mirRun($ctx, []), $ctx['school']->id);

    expect($run->status)->toBe(ManualInvoiceRunStatus::Failed)
        ->and($run->failure_reason)->toContain('no targets')
        ->and(mirOutcomes($run))->toBe([]);
});

it('6c — a run where every target failed is recorded as failed, not completed', function () {
    $ctx = mirSchool();
    $one = mirEnrollment($ctx);
    $two = mirEnrollment($ctx);

    $run = mirRun($ctx, [$one, $two]);

    ActiveSchool::runFor($ctx['school']->id, function () use ($one, $two) {
        DB::table('student_curricula')->whereIn('id', [$one->id, $two->id])
            ->update(['uuid' => DB::raw('UUID()')]);
    });

    $run = mirDispatch($run, $ctx['school']->id);

    // The nobody-billed rule: "Completed — 0 billed, 2 failed" is a green word over what is far more
    // likely to be an outage than two independently broken episodes. It is a heuristic about SHAPE
    // and the run has no way to do better.
    expect($run->status)->toBe(ManualInvoiceRunStatus::Failed)
        ->and($run->failure_reason)->toContain('RE-RUNNING IS NOT FREE')
        ->and($run->billed_count)->toBe(0)
        ->and($run->failed_count)->toBe(2)
        // AND THE EQUALITY STILL BALANCES. A failed row is a term; the run is `failed` because of
        // the heuristic, not because the accounting broke.
        ->and($run->billed_count + $run->failed_count + $run->unplaceable_count)->toBe($run->target_count);
});

it('6d — a selection nobody could be placed from is COMPLETED, not failed, and balances', function () {
    $ctx = mirSchool();

    $run = mirDispatch(mirRun($ctx, [mirStudent($ctx), mirStudent($ctx)]), $ctx['school']->id);

    // The nobody-billed rule is `failed === target_count`, and an unplaceable row is not a failed
    // row — so a selection where nobody could be placed stays silent on it. That is the right
    // answer: nothing went wrong in the portal, and the report says exactly what happened. Same
    // property ProcessBulkInvoiceRun::reconcile() gets from sponsored rows, reached the same way.
    expect($run->status)->toBe(ManualInvoiceRunStatus::Completed)
        ->and($run->failure_reason)->toBeNull()
        ->and($run->billed_count)->toBe(0)
        ->and($run->failed_count)->toBe(0)
        ->and($run->unplaceable_count)->toBe(2)
        ->and($run->billed_count + $run->failed_count + $run->unplaceable_count)->toBe($run->target_count);
});
