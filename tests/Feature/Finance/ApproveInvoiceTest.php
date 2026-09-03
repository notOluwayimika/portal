<?php

/*
 * INTERNAL AUDIT RELEASES A BILL — the action, its refusals, and the pairing that makes the
 * attestation real (App\Finance\Actions\ApproveInvoice).
 *
 * EVERY SEAT HERE IS A SEEDED ONE. `internal_auditor` really holds `finance.invoice.approve` and
 * nothing else in finance; `accounts_officer` really holds thirteen finance abilities and not this
 * one — and it is the MAKER (`finance.invoice.generate`), which is the seat the maker-checker pair
 * exists to keep out. An ad-hoc role with a hand-picked ability list would prove the action reads
 * `can()`; these prove the grants map and the action agree.
 *
 * `admin` is deliberately used NOWHERE as a refused seat: it holds effectively everything, so a
 * negative arm built on it can pass for a reason other than the one under test.
 */

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\ApproveInvoice;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Enums\InvoiceStatus;
use App\Finance\Models\Invoice;
use App\Models\Curriculum;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/** A user holding exactly $role in $school, through the real grant path. */
function ai_seat(School $school, string $role): User
{
    $user = User::factory()->create(['school_id' => $school->id]);
    $user->grantSchoolAccess($school, $role);
    $user->flushSchoolAccessCache();

    return $user;
}

/** A school with one enrolled student and one issued, UNRELEASED invoice. */
function ai_world(): array
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

    // The precondition, asserted rather than assumed: a freshly generated bill is UNRELEASED.
    // Without this the pass arm could be reading a stamp the fixture handed it.
    expect($invoice->{Invoice::RELEASE_STAMP_COLUMN})->toBeNull()
        ->and($invoice->reviewed_by_user_id)->toBeNull();

    return [$school, $student, $invoice];
}

// ── (a) THE ATTESTATION — both columns, together, naming the actor ────────────

it('a — the approving auditor stamps BOTH the time and themselves', function () {
    [$school, , $invoice] = ai_world();
    $auditor = ai_seat($school, 'internal_auditor');

    $released = ActiveSchool::runFor($school->id, fn () => app(ApproveInvoice::class)->handle($invoice, $auditor));

    // THE PAIRING IS THE POINT. `reviewed_at` set with a NULL user already means "grandfathered by
    // 2026_08_31_100000 — nobody reviewed this", so a live approval landing in that shape would be
    // a fabricated audit record indistinguishable, forever, from the pre-control book. Both halves
    // are asserted, and the actor is asserted by IDENTITY rather than merely non-null: a stamp
    // naming the wrong user is as false as a stamp naming nobody.
    expect($released->{Invoice::RELEASE_STAMP_COLUMN})->not->toBeNull()
        ->and((int) $released->reviewed_by_user_id)->toBe((int) $auditor->getKey());

    // Read back from the database, not from the returned model: an in-memory attribute the write
    // never persisted would satisfy the expectation above.
    $row = DB::table('finance_invoices')->where('id', $invoice->id)->first();
    expect($row->reviewed_at)->not->toBeNull()
        ->and((int) $row->reviewed_by_user_id)->toBe((int) $auditor->getKey());
});

// ── (b) THE REFUSAL — a real seat, holding other finance abilities ────────────

it('b — accounts_officer, the MAKER, is refused and told why', function () {
    [$school, , $invoice] = ai_world();
    $officer = ai_seat($school, 'accounts_officer');

    // The discriminating fixture: this seat holds thirteen finance abilities including
    // `finance.invoice.generate`, so a refusal here can only be the approve ability — not
    // "the user holds nothing".
    // Read inside the school's team context: spatie scopes grants per team, and `can()` outside one
    // answers about the wrong school — a precondition that silently reads false would make the
    // refusal below pass for the wrong reason.
    [$holdsGenerate, $holdsApprove] = ActiveSchool::runFor($school->id, fn () => [
        $officer->can('finance.invoice.generate'),
        $officer->can('finance.invoice.approve'),
    ]);
    expect($holdsGenerate)->toBeTrue()->and($holdsApprove)->toBeFalse();

    $thrown = null;
    try {
        ActiveSchool::runFor($school->id, fn () => app(ApproveInvoice::class)->handle($invoice, $officer));
    } catch (BusinessRuleException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    // A NON-EMPTY message. A bare abort reaches the client as {"message": ""} and the panels read
    // it with `??`, which does not substitute for an empty string — the refusal would render as
    // nothing at all.
    expect($thrown->getMessage())->not->toBe('');

    // Refused, not merely reported.
    expect(Invoice::withoutGlobalScopes()->find($invoice->id)->{Invoice::RELEASE_STAMP_COLUMN})->toBeNull();
});

// ── (c) ALREADY RELEASED — no silent overwrite of a colleague's signature ─────

it('c — approving an already-released bill leaves the first reviewer in place', function () {
    [$school, , $invoice] = ai_world();
    $first = ai_seat($school, 'internal_auditor');
    $second = ai_seat($school, 'internal_auditor');

    ActiveSchool::runFor($school->id, fn () => app(ApproveInvoice::class)->handle($invoice, $first));
    $afterFirst = DB::table('finance_invoices')->where('id', $invoice->id)->first();

    // Same stacking, same discipline: the pre-check and the conditional UPDATE would BOTH refuse
    // this, and they produce different sentences. Asserting the pre-check's — which names the
    // auditor who actually holds the attestation — pins which guard answered.
    $thrown = null;
    try {
        ActiveSchool::runFor($school->id, fn () => app(ApproveInvoice::class)->handle($invoice->fresh(), $second));
    } catch (BusinessRuleException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->getMessage())->toContain('already released by user#'.$first->getKey());

    $afterSecond = DB::table('finance_invoices')->where('id', $invoice->id)->first();

    // The attestation is UNCHANGED — the actor AND the timestamp. Asserting only the actor would
    // pass a write that re-stamped the time while keeping the name.
    expect((int) $afterSecond->reviewed_by_user_id)->toBe((int) $first->getKey())
        ->and($afterSecond->reviewed_at)->toBe($afterFirst->reviewed_at);
});

// ── (d) A VOID BILL IS NOT RELEASABLE ─────────────────────────────────────────

it('d — a void invoice is refused', function () {
    [$school, , $invoice] = ai_world();
    $auditor = ai_seat($school, 'internal_auditor');

    // InvoiceStatus has exactly two cases (Issued, Void) — read from the enum, not assumed.
    DB::table('finance_invoices')->where('id', $invoice->id)
        ->update(['status' => InvoiceStatus::Void->value]);

    expect(fn () => ActiveSchool::runFor($school->id, fn () => app(ApproveInvoice::class)->handle($invoice, $auditor)))
        ->toThrow(BusinessRuleException::class);

    expect(DB::table('finance_invoices')->where('id', $invoice->id)->first()->reviewed_at)->toBeNull();
});

// ── ISOLATION — another school's bill is unknown, not forbidden ───────────────

it('e — an auditor cannot release another school\'s bill', function () {
    [$school, , $invoice] = ai_world();

    $other = School::factory()->create();
    $auditor = ai_seat($other, 'internal_auditor');

    // Holds the ability — in their OWN school. So a refusal here is isolation, not authority.
    expect(ActiveSchool::runFor($other->id, fn () => $auditor->can('finance.invoice.approve')))->toBeTrue();

    // NAME THE MECHANISM, NOT "it threw" — and the sentence CHANGED when the action took the
    // sibling shape, deliberately.
    //
    // It used to be 'No invoice … exists for school#…': the action resolved the bill by uuid, and
    // BelongsToSchool made a foreign one resolve to null. That refusal was INCIDENTAL — it came
    // from a global scope, which `withoutGlobalScopes()` bypasses, and a bite-proof removing it
    // left this arm green because the conditional UPDATE carried the same scope and refused
    // instead. Two mechanisms, one assertion, and no way to tell which answered.
    //
    // Now the record is handed in directly — nothing filters it — and the refusal is
    // SchoolContext::assertOwns(), an explicit ownership check on the first line. The arm is
    // stronger for being pinned to a guard rather than to a scope, and this is the case the
    // house harness (SchoolContextGuardTest) calls the one SchoolScope cannot catch.
    $thrown = null;
    try {
        ActiveSchool::runFor($other->id, fn () => app(ApproveInvoice::class)->handle($invoice, $auditor));
    } catch (BusinessRuleException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->getMessage())->toBe('That invoice belongs to another School.');

    expect(DB::table('finance_invoices')->where('id', $invoice->id)->first()->reviewed_at)->toBeNull();
});

// ── THE COMPARE-AND-SWAP — one attestation, one honest refusal ────────────────

it('f — two auditors releasing the same bill produce ONE attestation', function () {
    [$school, , $invoice] = ai_world();
    $first = ai_seat($school, 'internal_auditor');
    $second = ai_seat($school, 'internal_auditor');

    ActiveSchool::runFor($school->id, fn () => app(ApproveInvoice::class)->handle($invoice, $first));

    // The second call's pre-read already sees the stamp, so this asserts the OUTCOME rather than
    // the race: exactly one row carries an attestation, and it names the auditor who won. The
    // conditional UPDATE is what makes that true when the two interleave rather than queue —
    // asserting the interleaving itself needs a second connection and belongs with the other
    // concurrency arms, which run under DatabaseTruncation.
    expect(fn () => ActiveSchool::runFor($school->id, fn () => app(ApproveInvoice::class)->handle($invoice->fresh(), $second)))
        ->toThrow(BusinessRuleException::class);

    expect(DB::table('finance_invoices')->whereNotNull('reviewed_at')->count())->toBe(1)
        ->and((int) DB::table('finance_invoices')->where('id', $invoice->id)->first()->reviewed_by_user_id)
        ->toBe((int) $first->getKey());
});
