<?php

/*
 * INTERNAL AUDIT RETURNS A BILL TO FINANCE — the action, its refusals, and the first committed
 * test of the pairing trigger `2026_09_04_100000` installed (App\Finance\Actions\ReturnInvoice).
 *
 * EVERY SEAT HERE IS A SEEDED ONE, for the sibling's reason. `internal_auditor` really holds
 * `finance.invoice.reject`; `accounts_officer` really holds `finance.invoice.generate` — the MAKER
 * — and really does not hold this checker, which is the separation the pair exists to enforce. An
 * ad-hoc role with a hand-picked ability list would prove the action reads `can()`; these prove the
 * grants map and the action agree.
 *
 * `admin` is deliberately used NOWHERE as a refused seat: it holds effectively everything, so a
 * negative arm built on it can pass for a reason other than the one under test.
 */

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\ReturnInvoice;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Enums\InvoiceStatus;
use App\Finance\Models\Invoice;
use App\Finance\Services\ActorName;
use App\Models\Curriculum;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Services\ActivityLog\ActivitySeverityService;
use App\Support\ActiveSchool;
use App\Support\Money;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// THE MEMO IS PROCESS-LIFETIME, SO THE SUITE RESETS IT — and that is a property something
// now asserts rather than an accident. `ActorName::$memo` is keyed "<schoolId>:<userId>" and
// nothing cleared it between files. It was safe only because no test in this repository uses
// `DatabaseMigrations` (measured: zero occurrences under tests/, against 264 files using
// RefreshDatabase) and MySQL does not roll back AUTO_INCREMENT, so ids never recycle within a
// run. Add one re-migrating file and ids restart at 1 while the memo still holds the previous
// file's `1:1` — a name resolved for a different person, surfacing as a flake.
//
// This also gives `flushMemo()` its first caller, which is what its stated model
// `SchoolFinanceSettings::flushPrefixMemo()` has had all along, in that file's own
// `beforeEach`.
beforeEach(function () {
    (new RbacSeeder)->run();
    ActorName::flushMemo();
});

/** A user holding exactly $role in $school, through the real grant path. */
function ri_seat(School $school, string $role): User
{
    $user = User::factory()->create(['school_id' => $school->id]);
    $user->grantSchoolAccess($school, $role);
    $user->flushSchoolAccessCache();

    return $user;
}

/** A school with one enrolled student and one issued, UNRELEASED, UNRETURNED invoice. */
function ri_world(): array
{
    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);

    $invoice = ActiveSchool::runFor($school->id, function () use ($school, $student) {
        StudentCurriculum::create([
            'student_id' => $student->id,
            'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
            'status' => 'active',
        ]);

        return app(GenerateInvoice::class)->handle(
            StudentCurriculum::where('student_id', $student->id)->firstOrFail()->uuid,
            [new InvoiceLineSpec('Tuition', Money::fromKobo(150000), bankAccountId: testBankAccountId($school->id))],
            InvoiceKind::Scheduled,
        );
    });

    // The precondition, asserted rather than assumed — on BOTH axes. Without this the pass arm
    // could be reading state the fixture handed it.
    expect($invoice->{Invoice::RELEASE_STAMP_COLUMN})->toBeNull()
        ->and($invoice->returned_at)->toBeNull()
        ->and($invoice->return_reason)->toBeNull();

    return [$school, $student, $invoice];
}

/** Run $fn and return the BusinessRuleException it threw, or null. */
function ri_refusal(callable $fn): ?BusinessRuleException
{
    try {
        $fn();
    } catch (BusinessRuleException $e) {
        return $e;
    }

    return null;
}

// ── (a) THE RETURN — three columns together, and the release axis untouched ───

it('a — the returning auditor stamps time, actor AND reason, and reviewed_at stays NULL', function () {
    [$school, , $invoice] = ri_world();
    $auditor = ri_seat($school, 'internal_auditor');

    $returned = ActiveSchool::runFor(
        $school->id,
        fn () => app(ReturnInvoice::class)->handle($invoice, $auditor, 'Tuition line is last term\'s rate')
    );

    expect($returned->returned_at)->not->toBeNull()
        ->and((int) $returned->returned_by_user_id)->toBe((int) $auditor->getKey())
        ->and($returned->return_reason)->toBe('Tuition line is last term\'s rate');

    // RETURNED IS A SECOND AXIS, NOT A MOVE ALONG THE FIRST. A returned bill stays unreleased and
    // therefore stays invisible to the payer — the safe direction, and the point of the design.
    expect($returned->{Invoice::RELEASE_STAMP_COLUMN})->toBeNull();

    // Read back from the database, not from the returned model: an in-memory attribute the write
    // never persisted would satisfy the expectations above.
    $row = DB::table('finance_invoices')->where('id', $invoice->id)->first();
    expect($row->returned_at)->not->toBeNull()
        ->and((int) $row->returned_by_user_id)->toBe((int) $auditor->getKey())
        ->and($row->return_reason)->toBe('Tuition line is last term\'s rate')
        ->and($row->reviewed_at)->toBeNull();
});

// ── (b) THE MAKER IS REFUSED, AND THE SENTENCE NAMES THE ABILITY ──────────────

it('b — accounts_officer, the MAKER, is refused and told which ability', function () {
    [$school, , $invoice] = ri_world();
    $officer = ri_seat($school, 'accounts_officer');

    // The discriminating fixture: this seat holds the MAKER ability, so a refusal here can only be
    // the checker ability — not "the user holds nothing". Read inside the school's team context:
    // spatie scopes grants per team, and `can()` outside one answers about the wrong school.
    [$holdsGenerate, $holdsReject] = ActiveSchool::runFor($school->id, fn () => [
        $officer->can('finance.invoice.generate'),
        $officer->can('finance.invoice.reject'),
    ]);
    expect($holdsGenerate)->toBeTrue()->and($holdsReject)->toBeFalse();

    $thrown = ri_refusal(fn () => ActiveSchool::runFor(
        $school->id,
        fn () => app(ReturnInvoice::class)->handle($invoice, $officer, 'wrong fee')
    ));

    expect($thrown)->not->toBeNull()
        ->and($thrown->getMessage())->toContain('finance.invoice.reject');

    expect(DB::table('finance_invoices')->where('id', $invoice->id)->first()->returned_at)->toBeNull();
});

// ── (c) ALREADY RETURNED — the first auditor's reason survives ────────────────

it('c — returning a returned bill keeps the FIRST auditor\'s reason and returner', function () {
    [$school, , $invoice] = ri_world();
    $first = ri_seat($school, 'internal_auditor');
    $second = ri_seat($school, 'internal_auditor');

    ActiveSchool::runFor($school->id, fn () => app(ReturnInvoice::class)->handle($invoice, $first, 'first reason'));
    $afterFirst = DB::table('finance_invoices')->where('id', $invoice->id)->first();

    // The pre-check and the conditional UPDATE would BOTH refuse this and they produce different
    // sentences. Asserting the pre-check's — which names the auditor who actually holds it — pins
    // WHICH guard answered.
    $thrown = ri_refusal(fn () => ActiveSchool::runFor(
        $school->id,
        fn () => app(ReturnInvoice::class)->handle($invoice->fresh(), $second, 'second reason')
    ));

    // TIGHTENED, NOT LOOSENED, when the sentence stopped naming `user#<id>`: a `toContain` became
    // an exact `toBe`, so drift reds here instead of being absorbed.
    expect($thrown)->not->toBeNull()
        ->and($thrown->getMessage())->toBe(
            'Invoice '.$invoice->fresh()->displayNumber().' was already returned to Finance on '
            .Carbon::parse($afterFirst->returned_at)->toDateString().' by '.$first->full_name
            .'. It is awaiting correction.'
        )
        // Asserted SEPARATELY from the name: a present name does not prove an absent id.
        ->and($thrown->getMessage())->not->toContain('user#')
        ->and($thrown->getMessage())->not->toContain($invoice->uuid);

    $afterSecond = DB::table('finance_invoices')->where('id', $invoice->id)->first();

    // All three columns unchanged. Asserting only the reason would pass a write that re-stamped the
    // time or the actor while keeping the text.
    expect($afterSecond->return_reason)->toBe('first reason')
        ->and((int) $afterSecond->returned_by_user_id)->toBe((int) $first->getKey())
        ->and($afterSecond->returned_at)->toBe($afterFirst->returned_at);
});

// ── (d) A VOID BILL HAS NOTHING TO CORRECT ────────────────────────────────────

it('d — a void invoice is refused, and the sentence says why there is nothing to correct', function () {
    [$school, , $invoice] = ri_world();
    $auditor = ri_seat($school, 'internal_auditor');

    // InvoiceStatus has exactly two cases (Issued, Void) — read from the enum, not assumed.
    DB::table('finance_invoices')->where('id', $invoice->id)->update(['status' => InvoiceStatus::Void->value]);

    $thrown = ri_refusal(fn () => ActiveSchool::runFor(
        $school->id,
        fn () => app(ReturnInvoice::class)->handle($invoice, $auditor, 'wrong fee')
    ));

    expect($thrown)->not->toBeNull()
        ->and($thrown->getMessage())->toContain('is void; there is nothing for Finance to correct');

    expect(DB::table('finance_invoices')->where('id', $invoice->id)->first()->returned_at)->toBeNull();
});

// ── (e) ISOLATION — refused by SchoolContext, not by a not-found ──────────────

it('e — an auditor cannot return another school\'s bill, and it is ownership that refuses', function () {
    [$school, , $invoice] = ri_world();

    $other = School::factory()->create();
    $auditor = ri_seat($other, 'internal_auditor');

    // Holds the ability — in their OWN school. So a refusal here is isolation, not authority.
    expect(ActiveSchool::runFor($other->id, fn () => $auditor->can('finance.invoice.reject')))->toBeTrue();

    // NAME THE MECHANISM. The record is handed in directly, so nothing filters it and the refusal
    // is SchoolContext::assertOwns() on the first line — pinned to a guard rather than to a scope,
    // which is the case SchoolScope structurally cannot catch.
    $thrown = ri_refusal(fn () => ActiveSchool::runFor(
        $other->id,
        fn () => app(ReturnInvoice::class)->handle($invoice, $auditor, 'wrong fee')
    ));

    expect($thrown)->not->toBeNull()
        ->and($thrown->getMessage())->toBe('That invoice belongs to another School.');

    expect(DB::table('finance_invoices')->where('id', $invoice->id)->first()->returned_at)->toBeNull();
});

// ── (f) THE COMPARE-AND-SWAP — one return, one truthful refusal ───────────────

it('f — two auditors returning the same bill produce ONE return', function () {
    [$school, , $invoice] = ri_world();
    $first = ri_seat($school, 'internal_auditor');
    $second = ri_seat($school, 'internal_auditor');

    ActiveSchool::runFor($school->id, fn () => app(ReturnInvoice::class)->handle($invoice, $first, 'first reason'));

    expect(fn () => ActiveSchool::runFor(
        $school->id,
        fn () => app(ReturnInvoice::class)->handle($invoice->fresh(), $second, 'second reason')
    ))->toThrow(BusinessRuleException::class);

    expect(DB::table('finance_invoices')->whereNotNull('returned_at')->count())->toBe(1)
        ->and((int) DB::table('finance_invoices')->where('id', $invoice->id)->first()->returned_by_user_id)
        ->toBe((int) $first->getKey());
});

// ── (g) A RELEASED BILL IS A REVERSAL, NOT A RETURN — and the sentence says so ─

it('g — a RELEASED bill is refused, and the refusal carries the void-and-credit-note remedy', function () {
    [$school, , $invoice] = ri_world();
    $auditor = ri_seat($school, 'internal_auditor');
    $reviewer = ri_seat($school, 'internal_auditor');

    DB::table('finance_invoices')->where('id', $invoice->id)->update([
        'reviewed_at' => now(),
        'reviewed_by_user_id' => $reviewer->getKey(),
    ]);

    $thrown = ri_refusal(fn () => ActiveSchool::runFor(
        $school->id,
        fn () => app(ReturnInvoice::class)->handle($invoice->fresh(), $auditor, 'wrong fee')
    ));

    // THE REMEDY IS PART OF THE ASSERTION. An auditor told "no" with no route forward will find one
    // that is not audited; reversal has its own maker-checker path and the sentence must name it.
    expect($thrown)->not->toBeNull()
        ->and($thrown->getMessage())->toBe(
            'Invoice '.$invoice->fresh()->displayNumber().' was already released to its payer by '
            .$reviewer->full_name.'. It cannot be returned; void it and issue a credit note instead.'
        )
        ->and($thrown->getMessage())->not->toContain('user#')
        ->and($thrown->getMessage())->not->toContain($invoice->uuid);

    expect(DB::table('finance_invoices')->where('id', $invoice->id)->first()->returned_at)->toBeNull();
});

// ── (h) A WHITESPACE-ONLY REASON IS REFUSED BY THE ACTION, NOT BY THE DATABASE ─

it('h — a whitespace-only reason is refused by the ACTION', function () {
    [$school, , $invoice] = ri_world();
    $auditor = ri_seat($school, 'internal_auditor');

    // ASSERT THE ACTION'S SENTENCE, NOT A QueryException. The migration assigns non-emptiness to
    // the action in words; if that guard ever moved to the database this arm must RED rather than
    // pass on a different mechanism's refusal.
    $thrown = ri_refusal(fn () => ActiveSchool::runFor(
        $school->id,
        fn () => app(ReturnInvoice::class)->handle($invoice, $auditor, "   \t\n  ")
    ));

    expect($thrown)->not->toBeNull()
        ->and($thrown->getMessage())->toContain('the reason cannot be empty');

    expect(DB::table('finance_invoices')->where('id', $invoice->id)->first()->returned_at)->toBeNull();
});

// ── (i) THE CAP, BOTH DIRECTIONS — a literal 256 and a literal 255 ────────────

it('i — 256 characters is refused naming the length; 255 is accepted and stored whole', function () {
    [$school, , $invoice] = ri_world();
    $auditor = ri_seat($school, 'internal_auditor');

    // LITERAL payloads, never derived from the constant under test: a length built as
    // `REASON_MAX + 1` submits "cap + 1" whatever the cap is and cannot notice the cap loosening.
    $tooLong = str_repeat('x', 256);

    $thrown = ri_refusal(fn () => ActiveSchool::runFor(
        $school->id,
        fn () => app(ReturnInvoice::class)->handle($invoice, $auditor, $tooLong)
    ));

    expect($thrown)->not->toBeNull()
        ->and($thrown->getMessage())->toContain('256 characters')
        ->and($thrown->getMessage())->toContain('the limit is 255');

    expect(DB::table('finance_invoices')->where('id', $invoice->id)->first()->returned_at)->toBeNull();

    // THE ACCEPTING SIDE, or the cap is untested in one direction and an off-by-one only reds one
    // way. Stored WHOLE — a silently truncated 255 would satisfy a length-only assertion.
    $exact = str_repeat('y', 255);
    ActiveSchool::runFor($school->id, fn () => app(ReturnInvoice::class)->handle($invoice->fresh(), $auditor, $exact));

    $row = DB::table('finance_invoices')->where('id', $invoice->id)->first();
    expect($row->return_reason)->toBe($exact)
        ->and(mb_strlen($row->return_reason))->toBe(255);
});

// ── (j) THE TRIGGER BITES — errno 1644, and this is its only committed floor ───

it('j — a raw write setting returned_at without its companions is refused as 1644', function () {
    [, , $invoice] = ri_world();

    // WHY THIS ARM IS HERE AND NOT IN A SCHEMA TEST. CheckConstraintsAsTriggersTest is a
    // SEVEN-RULE WHITELIST from 2026_08_17_100000, not a trigger census — it stayed green when
    // 2026_09_04_100000 added these two triggers. So nothing in the suite would red today if a
    // later migration dropped the pairing guard. This commit is the one that creates the writes it
    // guards, so this is where its floor goes.
    //
    // ERRNO 1644 SPECIFICALLY, NOT "a QueryException was thrown". 1648 means the SIGNAL itself
    // failed on a MESSAGE_TEXT over MySQL's 128-character cap — a BROKEN guard reading as a
    // working one — and an arm asserting only "it threw" cannot tell the two apart.
    $errno = function (callable $fn): ?int {
        try {
            $fn();
        } catch (QueryException $e) {
            return (int) ($e->errorInfo[1] ?? 0);
        }

        return null;
    };

    // returned_at with NO reason.
    expect($errno(fn () => DB::table('finance_invoices')->where('id', $invoice->id)->update([
        'returned_at' => now(),
        'returned_by_user_id' => 1,
    ])))->toBe(1644);

    // returned_at with a reason but NO returner — the second companion, so the arm cannot pass by
    // covering one half of the predicate.
    expect($errno(fn () => DB::table('finance_invoices')->where('id', $invoice->id)->update([
        'returned_at' => now(),
        'return_reason' => 'wrong fee',
    ])))->toBe(1644);

    expect(DB::table('finance_invoices')->where('id', $invoice->id)->first()->returned_at)->toBeNull();
});

// ── (k) THE ACTIVITY ROW — the reason is in it, and it resolves to `warning` ───

it('k — the activity row carries the reason and the actor, and resolves to warning', function () {
    [$school, , $invoice] = ri_world();
    $auditor = ri_seat($school, 'internal_auditor');

    ActiveSchool::runFor($school->id, fn () => app(ReturnInvoice::class)->handle($invoice, $auditor, 'Tuition rate is stale'));

    $row = DB::table('activity_log')
        ->where('log_name', 'finance')->where('event', 'invoice.returned')
        ->orderByDesc('id')->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->causer_id)->toBe((int) $auditor->getKey());

    $properties = json_decode($row->properties, true);

    // THE REASON IS THE PAYLOAD OF THE ACT. A second return overwrites the column, after which
    // this row is the only place the first return's instruction exists.
    expect($properties['return_reason'])->toBe('Tuition rate is stale')
        ->and($properties['invoice_uuid'])->toBe($invoice->uuid);

    // RESOLVED THROUGH THE SERVICE, not by re-reading the config. Reading the config would prove
    // the file contains a string and nothing more — the transposition class the catalogue lint
    // exists for.
    expect(ActivitySeverityService::make()->for($row->log_name, $row->event))->toBe('warning')
        ->and(ActivitySeverityService::make()->for('finance', 'invoice.approved'))->toBe('warning');
});
