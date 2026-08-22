<?php

use App\Finance\Actions\AllocatePayment;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\RecordAccountPayment;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Exceptions\AllocationRefused;
use App\Finance\Models\Invoice;
use App\Finance\Models\Payment;
use App\Finance\Models\PaymentAllocation;
use App\Finance\Models\StudentAccount;
use App\Finance\Services\AllocationProposal;
use App\Models\Curriculum;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use App\Support\SchoolDay;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * U10 COMMIT 2 — THE WRITE SIDE. The third writer of finance_payment_allocations, its guards, and the
 * database floor underneath them.
 *
 * TWO THINGS THIS FILE IS ORGANISED AROUND.
 *
 * THE ACTION REFUSES BEFORE THE DATABASE DOES, and both halves are armed. Arms 5–9 are the friendly
 * refusals an operator can act on; arm 12 bypasses the Action entirely and shows every trigger still
 * firing with its own message. A guard that has only ever been seen from above the Action is a guard
 * nobody has checked is there.
 *
 * AND AN ALLOCATION MOVES NO MONEY. Arm 1 asserts the account balance is byte-identical before and
 * after — if this Action ever grows a SubledgerPoster::post call, that arm is what reds, because cash
 * that arrived once would then be credited twice.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** @return array{0: School, 1: User, 2: Student} */
function apwSetup(): array
{
    $school = School::factory()->create();
    $officer = User::factory()->create(['school_id' => $school->id]);
    $officer->grantSchoolAccess($school, 'accounts_officer');
    $officer->flushSchoolAccessCache();

    return [$school, $officer, Student::factory()->create(['school_id' => $school->id])];
}

/** One invoice of $kobo on a fresh enrollment episode (one active SCHEDULED invoice per episode). */
function apwInvoice(School $school, Student $student, int $kobo): Invoice
{
    $enrollment = StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
        'status' => 'active',
    ]);

    return app(GenerateInvoice::class)->handle(
        $enrollment->uuid,
        [new InvoiceLineSpec('Tuition', Money::fromKobo($kobo))],
        InvoiceKind::Scheduled,
    );
}

/** An account-scoped payment of $kobo — no invoice named, so the whole amount is remainder. */
function apwPayment(School $school, Student $student, User $officer, int $kobo): Payment
{
    return app(RecordAccountPayment::class)->handle(
        $student->id, Money::fromKobo($kobo), 'Parent', $officer, SchoolDay::today(), testBankAccountId($school->id),
    );
}

function apwFingerprint(Payment $payment): string
{
    return app(AllocationProposal::class)->for($payment)['fingerprint'];
}

/** @return list<array{invoice_id: string, amount_minor: int}> */
function apwDirections(array $pairs): array
{
    return array_map(
        static fn (array $pair) => ['invoice_id' => $pair[0]->uuid, 'amount_minor' => $pair[1]],
        $pairs,
    );
}

it('PROOF 1 — accepting the proposal writes the rows, names the operator, and moves NO money', function () {
    [$school, $officer, $student] = apwSetup();

    ActiveSchool::runFor($school->id, function () use ($school, $officer, $student) {
        $a = apwInvoice($school, $student, 3000);
        $b = apwInvoice($school, $student, 5000);
        $payment = apwPayment($school, $student, $officer, 8000);

        $balanceBefore = (int) StudentAccount::query()->where('student_id', $student->id)->value('balance_minor');

        $created = app(AllocatePayment::class)->handle(
            $payment,
            apwDirections([[$a, 3000], [$b, 5000]]),
            apwFingerprint($payment),
            $officer,
        );

        expect($created)->toHaveCount(2);

        $rows = PaymentAllocation::query()->where('payment_id', $payment->id)->orderBy('id')->get();

        expect($rows->pluck('allocation_rule')->all())
            ->toBe([PaymentAllocation::RULE_OPERATOR_DIRECTED_REMAINDER, PaymentAllocation::RULE_OPERATOR_DIRECTED_REMAINDER])
            ->and($rows->pluck('allocation_overridden')->all())->toBe([false, false])
            ->and($rows->pluck('allocation_override_reason')->all())->toBe([null, null])
            // The row names its human. Null here would mean "no human chose this", which is a false
            // statement about a row an operator submitted — and the pairing trigger refuses it anyway.
            ->and($rows->pluck('allocated_by_user_id')->all())->toBe([$officer->id, $officer->id]);

        // AN ALLOCATION IS A SETTLEMENT LINK, NOT A LEDGER EVENT. The cash was credited when the
        // payment was recorded; crediting it again here would double-count it.
        expect((int) StudentAccount::query()->where('student_id', $student->id)->value('balance_minor'))
            ->toBe($balanceBefore);

        // What DID change: the invoices are settled and the payment has nothing left.
        expect(app(AllocationProposal::class)->for($payment->refresh())['payment']['unallocated']->toKobo())->toBe(0);
    });
});

it('PROOF 2 — departing from the proposal without a reason is refused, on the override_reason field', function () {
    [$school, $officer, $student] = apwSetup();

    ActiveSchool::runFor($school->id, function () use ($school, $officer, $student) {
        $a = apwInvoice($school, $student, 3000);
        $b = apwInvoice($school, $student, 5000);
        $payment = apwPayment($school, $student, $officer, 8000);

        // The proposal is 3000/5000 (oldest first). This is 1000/5000 — a departure on the first row.
        expect(fn () => app(AllocatePayment::class)->handle(
            $payment, apwDirections([[$a, 1000], [$b, 5000]]), apwFingerprint($payment), $officer,
        ))->toThrow(AllocationRefused::class);

        expect(PaymentAllocation::query()->where('payment_id', $payment->id)->count())->toBe(0,
            'a refused submission must write nothing — the table is append-only, so a partial write is permanent');
    });
});

it('PROOF 3 — the override marker and reason are PER ROW: the changed row carries them, the untouched row does not', function () {
    [$school, $officer, $student] = apwSetup();

    ActiveSchool::runFor($school->id, function () use ($school, $officer, $student) {
        $a = apwInvoice($school, $student, 3000);
        $b = apwInvoice($school, $student, 5000);
        $payment = apwPayment($school, $student, $officer, 8000);

        app(AllocatePayment::class)->handle(
            $payment,
            apwDirections([[$a, 1000], [$b, 5000]]),
            apwFingerprint($payment),
            $officer,
            'Parent asked for the trip fee to be settled first.',
        );

        $rows = PaymentAllocation::query()->where('payment_id', $payment->id)->orderBy('id')->get()->keyBy('invoice_id');

        // The row the operator changed.
        expect($rows[$a->id]->allocation_overridden)->toBeTrue()
            ->and($rows[$a->id]->allocation_override_reason)->toBe('Parent asked for the trip fee to be settled first.');

        // And the one they did not. Stamping this as overridden would assert a choice about it that
        // nobody made, on a row that can never be corrected.
        expect($rows[$b->id]->allocation_overridden)->toBeFalse()
            ->and($rows[$b->id]->allocation_override_reason)->toBeNull();
    });
});

it('PROOF 4 — DECLINING a proposed allocation is a departure too, and needs its reason', function () {
    [$school, $officer, $student] = apwSetup();

    ActiveSchool::runFor($school->id, function () use ($school, $officer, $student) {
        $a = apwInvoice($school, $student, 3000);
        $b = apwInvoice($school, $student, 5000);
        $payment = apwPayment($school, $student, $officer, 8000);

        // The proposal offers 3000 for $a. Submitting ONLY $b leaves $a at zero — an operator
        // decision, and one a comparison that walked only the submitted rows would record as nothing.
        expect(fn () => app(AllocatePayment::class)->handle(
            $payment, apwDirections([[$b, 5000]]), apwFingerprint($payment), $officer,
        ))->toThrow(AllocationRefused::class);

        // With the reason, it lands — and the row that WAS written is not itself a departure.
        app(AllocatePayment::class)->handle(
            $payment, apwDirections([[$a, 0], [$b, 5000]]), apwFingerprint($payment), $officer, 'Term bill is under dispute.',
        );

        $rows = PaymentAllocation::query()->where('payment_id', $payment->id)->get();

        expect($rows)->toHaveCount(1, 'a zero direction must write no row — an allocation of nothing asserts a settlement that did not happen')
            ->and($rows->first()->invoice_id)->toBe($b->id)
            ->and($rows->first()->allocation_overridden)->toBeFalse();
    });
});

it('PROOF 5 — a reason with nothing to explain is refused rather than silently discarded', function () {
    [$school, $officer, $student] = apwSetup();

    ActiveSchool::runFor($school->id, function () use ($school, $officer, $student) {
        $a = apwInvoice($school, $student, 3000);
        $payment = apwPayment($school, $student, $officer, 3000);

        // allocation_override_reason must be null when the marker is false (the pairing trigger says
        // so), so accepting this would mean dropping text the operator typed.
        expect(fn () => app(AllocatePayment::class)->handle(
            $payment, apwDirections([[$a, 3000]]), apwFingerprint($payment), $officer, 'No reason needed.',
        ))->toThrow(AllocationRefused::class);
    });
});

it('PROOF 6 — over the INVOICE outstanding: the Action refuses in words, naming the invoice', function () {
    [$school, $officer, $student] = apwSetup();

    ActiveSchool::runFor($school->id, function () use ($school, $officer, $student) {
        $a = apwInvoice($school, $student, 3000);
        $payment = apwPayment($school, $student, $officer, 9000);

        try {
            app(AllocatePayment::class)->handle(
                $payment, apwDirections([[$a, 5000]]), apwFingerprint($payment), $officer, 'Overpay this one.',
            );
            throw new RuntimeException('the Action allowed an allocation above the invoice outstanding');
        } catch (AllocationRefused $e) {
            expect($e->getMessage())->toContain($a->displayNumber())
                ->and($e->getMessage())->toContain('still owes')
                // The FIELD is what lets the screen put this on the row it is about rather than in a
                // banner over a table of eight editable amounts.
                ->and($e->field)->toBe('allocations.0.amount_minor');
        }
    });
});

it('PROOF 7 — over the PAYMENT remainder: refused on the allocations field, before the trigger is reached', function () {
    [$school, $officer, $student] = apwSetup();

    ActiveSchool::runFor($school->id, function () use ($school, $officer, $student) {
        $a = apwInvoice($school, $student, 6000);
        $b = apwInvoice($school, $student, 6000);
        // 10000 of remainder against 12000 of outstanding: each row is individually legal and the
        // PAIR is not, which is the axis finance_allocation_not_over_payment_amount guards.
        $payment = apwPayment($school, $student, $officer, 10000);

        try {
            app(AllocatePayment::class)->handle(
                $payment, apwDirections([[$a, 6000], [$b, 6000]]), apwFingerprint($payment), $officer, 'Settle both.',
            );
            throw new RuntimeException('the Action allowed Σ(allocations) above the payment amount');
        } catch (AllocationRefused $e) {
            expect($e->getMessage())->toContain('more than this payment has left')
                ->and($e->field)->toBe('allocations');
        }

        expect((int) PaymentAllocation::query()->where('payment_id', $payment->id)->sum('amount_minor'))->toBe(0,
            'the first row must not have landed before the second was refused — the whole submission is one transaction');
    });
});

it('PROOF 8 — a stale fingerprint refuses rather than guessing whether the operator or the world moved', function () {
    [$school, $officer, $student] = apwSetup();

    ActiveSchool::runFor($school->id, function () use ($school, $officer, $student) {
        $a = apwInvoice($school, $student, 3000);
        $payment = apwPayment($school, $student, $officer, 8000);

        $stale = apwFingerprint($payment);

        // The world moves: a second invoice is raised, which draws this payment's credit forward and
        // changes both the remainder and the set of open invoices the operator was shown.
        apwInvoice($school, $student, 2000);

        try {
            app(AllocatePayment::class)->handle(
                $payment, apwDirections([[$a, 3000]]), $stale, $officer,
            );
            throw new RuntimeException('a stale proposal token was accepted');
        } catch (AllocationRefused $e) {
            expect($e->field)->toBe('fingerprint')
                ->and($e->getMessage())->toContain('Reload');
        }
    });
});

it('PROOF 9 — an invoice the proposal never offered, a duplicate row, and a negative amount are each refused', function () {
    [$schoolA, $officerA, $studentA] = apwSetup();
    [$schoolB, $officerB, $studentB] = apwSetup();

    $foreign = ActiveSchool::runFor($schoolB->id, fn () => apwInvoice($schoolB, $studentB, 4000));

    ActiveSchool::runFor($schoolA->id, function () use ($schoolA, $officerA, $studentA, $foreign) {
        $a = apwInvoice($schoolA, $studentA, 3000);
        $payment = apwPayment($schoolA, $studentA, $officerA, 8000);
        $fingerprint = apwFingerprint($payment);

        // Another School's invoice. Isolation is not authorization (ADR 0036): the officer holds the
        // ability and must still be refused.
        try {
            app(AllocatePayment::class)->handle(
                $payment, [['invoice_id' => $foreign->uuid, 'amount_minor' => 1000]], $fingerprint, $officerA, 'x',
            );
            throw new RuntimeException('an invoice from another School was accepted');
        } catch (AllocationRefused $e) {
            expect($e->field)->toBe('allocations.0.invoice_id');
        }

        // The same invoice twice. Summing them would guess; refusing each independently would let the
        // pair clear every per-row cap while their total does not.
        try {
            app(AllocatePayment::class)->handle(
                $payment, apwDirections([[$a, 2000], [$a, 1000]]), $fingerprint, $officerA, 'x',
            );
            throw new RuntimeException('one invoice was accepted twice in one submission');
        } catch (AllocationRefused $e) {
            expect($e->field)->toBe('allocations.1.invoice_id');
        }

        // A negative allocation would RAISE an invoice's outstanding — an un-allocation in an
        // allocation's shape, on a table with no UPDATE and no DELETE.
        try {
            app(AllocatePayment::class)->handle(
                $payment, apwDirections([[$a, -1000]]), $fingerprint, $officerA, 'x',
            );
            throw new RuntimeException('a negative allocation was accepted');
        } catch (AllocationRefused $e) {
            expect($e->field)->toBe('allocations.0.amount_minor');
        }

        expect(PaymentAllocation::query()->where('payment_id', $payment->id)->count())->toBe(0);
    });
});

it('PROOF 10 — a payment with nothing left to allocate is refused', function () {
    [$school, $officer, $student] = apwSetup();

    ActiveSchool::runFor($school->id, function () use ($school, $officer, $student) {
        $a = apwInvoice($school, $student, 3000);
        $payment = apwPayment($school, $student, $officer, 3000);

        app(AllocatePayment::class)->handle($payment, apwDirections([[$a, 3000]]), apwFingerprint($payment), $officer);

        $b = apwInvoice($school, $student, 2000);

        expect(fn () => app(AllocatePayment::class)->handle(
            $payment->refresh(), apwDirections([[$b, 1000]]), apwFingerprint($payment), $officer, 'x',
        ))->toThrow(AllocationRefused::class);
    });
});

it('PROOF 11 — both routes are gated on finance.payment.allocate, and the POST answers with FIELD errors', function () {
    [$school, $officer, $student] = apwSetup();

    [$invoice, $payment, $fingerprint] = ActiveSchool::runFor($school->id, function () use ($school, $officer, $student) {
        $invoice = apwInvoice($school, $student, 3000);
        $payment = apwPayment($school, $student, $officer, 9000);

        return [$invoice, $payment, apwFingerprint($payment)];
    });

    $url = '/api/v1/finance/payments/'.$payment->uuid.'/allocations';

    $admin = User::factory()->create(['school_id' => $school->id]);
    $admin->grantSchoolAccess($school, 'admin');
    $admin->flushSchoolAccessCache();

    $this->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson($url, ['fingerprint' => $fingerprint, 'allocations' => [['invoice_id' => $invoice->uuid, 'amount_minor' => 3000]]])
        ->assertForbidden();

    // THE REFUSAL IS A FIELD ERROR, not a page-level banner. This is the whole reason
    // AllocationRefused carries a field.
    $this->actingAs($officer)->withSession(['school_id' => $school->id])
        ->postJson($url, [
            'fingerprint' => $fingerprint,
            'allocations' => [['invoice_id' => $invoice->uuid, 'amount_minor' => 5000]],
            'override_reason' => 'Overpay it.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['allocations.0.amount_minor']);

    $this->actingAs($officer)->withSession(['school_id' => $school->id])
        ->postJson($url, [
            'fingerprint' => $fingerprint,
            'allocations' => [['invoice_id' => $invoice->uuid, 'amount_minor' => 3000]],
        ])
        ->assertStatus(201);
});

it('PROOF 13 — a NUMERIC STRING amount is not a departure, and does not write an override nobody made', function () {
    /*
     * THE COLD REVIEW'S STOP, ARMED. `integer` in a FormRequest is
     * `filter_var($value, FILTER_VALIDATE_INT) !== false`, so the JSON string "3000" passed validation
     * and arrived as a string; the Action decides `allocation_overridden` with `!==`, and
     * `"3000" !== 3000` is TRUE. A submission byte-identical to the proposal was therefore recorded as
     * an override, with a reason the operator was compelled to invent, on a table that has no UPDATE.
     *
     * Every arm below posts through the REAL ROUTE with a genuine JSON string, because that is the only
     * way the defect is reachable — no existing arm posted a non-int at all, which is why twelve green
     * proofs and three green suites did not see it.
     */
    [$school, $officer, $student] = apwSetup();

    // ── a. THE STRING THAT IS THE PROPOSAL: no reason needed, no override written.
    [$a, $payA, $fpA] = ActiveSchool::runFor($school->id, function () use ($school, $officer, $student) {
        $a = apwInvoice($school, $student, 3000);
        $payA = apwPayment($school, $student, $officer, 3000);

        return [$a, $payA, apwFingerprint($payA)];
    });

    $this->actingAs($officer)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/payments/'.$payA->uuid.'/allocations', [
            'fingerprint' => $fpA,
            'allocations' => [['invoice_id' => $a->uuid, 'amount_minor' => '3000']],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['allocations.0.amount_minor']);

    expect(PaymentAllocation::query()->where('payment_id', $payA->id)->count())->toBe(0,
        'a string amount must be refused at the edge, not cast and written');

    // The same submission as a real integer lands, and lands as NOT overridden.
    $this->actingAs($officer)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/payments/'.$payA->uuid.'/allocations', [
            'fingerprint' => $fpA,
            'allocations' => [['invoice_id' => $a->uuid, 'amount_minor' => 3000]],
        ])
        ->assertStatus(201);

    $rowA = PaymentAllocation::query()->where('payment_id', $payA->id)->sole();
    expect($rowA->allocation_overridden)->toBeFalse()
        ->and($rowA->allocation_override_reason)->toBeNull();

    // ── b. A STRING THAT IS A REAL DEPARTURE still needs its reason, and still records the override.
    [$b, $payB, $fpB] = ActiveSchool::runFor($school->id, function () use ($school, $officer) {
        $st = Student::factory()->create(['school_id' => $school->id]);
        $b = apwInvoice($school, $st, 3000);
        $payB = apwPayment($school, $st, $officer, 3000);

        return [$b, $payB, apwFingerprint($payB)];
    });

    // Refused at the edge as a string — the rule does not care whether the value is a departure.
    $this->actingAs($officer)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/payments/'.$payB->uuid.'/allocations', [
            'fingerprint' => $fpB,
            'allocations' => [['invoice_id' => $b->uuid, 'amount_minor' => '1000']],
            'override_reason' => 'Part-settle only.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['allocations.0.amount_minor']);

    // As an integer it is a genuine departure: the marker and the reason are still written. Fixing the
    // false positive must not have cost the true one.
    $this->actingAs($officer)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/payments/'.$payB->uuid.'/allocations', [
            'fingerprint' => $fpB,
            'allocations' => [['invoice_id' => $b->uuid, 'amount_minor' => 1000]],
            'override_reason' => 'Part-settle only.',
        ])
        ->assertStatus(201);

    $rowB = PaymentAllocation::query()->where('payment_id', $payB->id)->sole();
    expect($rowB->allocation_overridden)->toBeTrue()
        ->and($rowB->allocation_override_reason)->toBe('Part-settle only.');

    // ── c. THE QUIETEST CASE: "0" as a string on a row the proposal already proposed zero for.
    //    Uncast, this demanded a reason for a submission identical to the proposal and then wrote the
    //    reason NOWHERE — the zero row writes no row at all — which is the silent discard the Action's
    //    own guard exists to refuse.
    [$c1, $c2, $payC, $fpC] = ActiveSchool::runFor($school->id, function () use ($school, $officer) {
        $st = Student::factory()->create(['school_id' => $school->id]);
        $c1 = apwInvoice($school, $st, 3000);
        $c2 = apwInvoice($school, $st, 1000);
        $payC = apwPayment($school, $st, $officer, 2000);   // proposal: 2000 on c1, 0 on c2

        return [$c1, $c2, $payC, apwFingerprint($payC)];
    });

    expect(app(AllocationProposal::class)->for($payC)['invoices'][1]['proposed']->toKobo())->toBe(0,
        'the fixture must actually produce a zero-proposed row, or this arm proves nothing');

    $this->actingAs($officer)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/payments/'.$payC->uuid.'/allocations', [
            'fingerprint' => $fpC,
            'allocations' => [
                ['invoice_id' => $c1->uuid, 'amount_minor' => 2000],
                ['invoice_id' => $c2->uuid, 'amount_minor' => '0'],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['allocations.1.amount_minor']);

    // The same thing as integers is the proposal verbatim: it lands with NO reason asked for.
    $this->actingAs($officer)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/payments/'.$payC->uuid.'/allocations', [
            'fingerprint' => $fpC,
            'allocations' => [
                ['invoice_id' => $c1->uuid, 'amount_minor' => 2000],
                ['invoice_id' => $c2->uuid, 'amount_minor' => 0],
            ],
        ])
        ->assertStatus(201);

    $rowsC = PaymentAllocation::query()->where('payment_id', $payC->id)->get();
    expect($rowsC)->toHaveCount(1, 'the zero row still writes nothing')
        ->and($rowsC->first()->allocation_overridden)->toBeFalse()
        ->and($rowsC->first()->allocation_override_reason)->toBeNull();

    // ── d. A NON-NUMERIC STRING is a field error, never a silent cast to zero.
    [$d, $payD, $fpD] = ActiveSchool::runFor($school->id, function () use ($school, $officer) {
        $st = Student::factory()->create(['school_id' => $school->id]);
        $d = apwInvoice($school, $st, 3000);
        $payD = apwPayment($school, $st, $officer, 3000);

        return [$d, $payD, apwFingerprint($payD)];
    });

    $this->actingAs($officer)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/payments/'.$payD->uuid.'/allocations', [
            'fingerprint' => $fpD,
            'allocations' => [['invoice_id' => $d->uuid, 'amount_minor' => 'three thousand']],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['allocations.0.amount_minor']);

    expect(PaymentAllocation::query()->where('payment_id', $payD->id)->count())->toBe(0);
});

it('PROOF 14 — the Action itself refuses a numeric string, for the off-HTTP callers its docblock names', function () {
    /*
     * THE OTHER HALF OF THE SAME STOP. `integer:strict` shuts the HTTP door and nothing else: this
     * Action is documented as reachable off-HTTP, and a job or a console command handing it
     * `['amount_minor' => '3000']` meets no FormRequest anywhere in the path. This arm calls the Action
     * DIRECTLY, which is the only way to see whether the cast inside it is doing anything.
     */
    [$school, $officer, $student] = apwSetup();

    ActiveSchool::runFor($school->id, function () use ($school, $officer, $student) {
        $a = apwInvoice($school, $student, 3000);
        $payment = apwPayment($school, $student, $officer, 3000);

        // The proposal is 3000 on $a. Handed in as a STRING, with no reason — which is what the
        // uncast comparison used to refuse, because "3000" !== 3000.
        app(AllocatePayment::class)->handle(
            $payment,
            [['invoice_id' => $a->uuid, 'amount_minor' => '3000']],
            apwFingerprint($payment),
            $officer,
        );

        $row = PaymentAllocation::query()->where('payment_id', $payment->id)->sole();

        expect($row->amount->toKobo())->toBe(3000)
            ->and($row->allocation_overridden)->toBeFalse()
            ->and($row->allocation_override_reason)->toBeNull();
    });
});

it('PROOF 15 — the PAGE route is gated too, and not only the two API routes', function () {
    /*
     * THE COLD REVIEW'S SECOND FINDING. Removing the middleware from the web route left
     * tests/Feature/Finance 715/715, tests/Feature/Rbac 335/335 and --group=arch 103/103 all green:
     * PROOF 11 covers the two API doors, and FinanceNavCoverageTest's arm is a TEXT check on
     * statement.tsx that issues no request at all. The page's own gate was asserted nowhere.
     */
    [$school, $officer, $student] = apwSetup();

    $payment = ActiveSchool::runFor($school->id, function () use ($school, $officer, $student) {
        apwInvoice($school, $student, 3000);

        return apwPayment($school, $student, $officer, 3000);
    });

    $url = '/finance/payments/'.$payment->uuid.'/allocate';

    // `admin` carries finance.access — enough for the statement this page is reached from — and must
    // still be refused here. Directing money is not the same authority as reading where it went.
    $admin = User::factory()->create(['school_id' => $school->id]);
    $admin->grantSchoolAccess($school, 'admin');
    $admin->flushSchoolAccessCache();

    $this->actingAs($admin)->withSession(['school_id' => $school->id])->get($url)->assertForbidden();
    $this->actingAs($officer)->withSession(['school_id' => $school->id])->get($url)->assertOk();
});

it('PROOF 12 — the DATABASE is still the floor: every guard fires on a write that never enters the Action', function () {
    [$school, $officer, $student] = apwSetup();

    [$invoice, $payment] = ActiveSchool::runFor($school->id, function () use ($school, $officer, $student) {
        return [apwInvoice($school, $student, 9000), apwPayment($school, $student, $officer, 5000)];
    });

    // Every insert below is RAW, so no cap in any Action can be what refuses it. Each asserts the
    // trigger's own MESSAGE and not merely SQLSTATE 45000 — roughly fifty triggers in this schema
    // signal 45000, so the code alone would not say which guard spoke.
    $row = fn (array $overrides) => array_merge([
        'uuid' => (string) Str::uuid(),
        'school_id' => $school->id,
        'payment_id' => $payment->id,
        'invoice_id' => $invoice->id,
        'amount_minor' => 1000,
        'amount_currency' => 'NGN',
        'allocation_rule' => PaymentAllocation::RULE_OPERATOR_DIRECTED_REMAINDER,
        'allocation_overridden' => false,
        'allocation_override_reason' => null,
        'allocated_by_user_id' => $officer->id,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);

    $refuses = function (array $overrides, string $fragment) use ($row) {
        try {
            DB::table('finance_payment_allocations')->insert($row($overrides));
            throw new RuntimeException("the database accepted a row it must refuse: {$fragment}");
        } catch (QueryException $e) {
            expect((int) $e->errorInfo[1])->toBe(1644)
                ->and((string) $e->errorInfo[2])->toContain($fragment);
        }
    };

    // a — an operator-directed row with no operator.
    $refuses(['allocated_by_user_id' => null], 'allocated_by_user_id is required for that rule');

    // b — and an ENGINE row WITH one. Only the pair makes NULL mean "no human chose this".
    $refuses(
        ['allocation_rule' => PaymentAllocation::RULE_CREDIT_APPLIED_FORWARD_OLDEST_FIRST],
        'the two engine rules must leave allocated_by_user_id null',
    );

    // c — the marker without its reason, and d — a blank one, which is the same audit hole reached
    // by pressing the space bar.
    $refuses(['allocation_overridden' => true], 'allocation_override_reason is required');
    $refuses(['allocation_overridden' => true, 'allocation_override_reason' => '   '], 'allocation_override_reason is required');

    // e — and the reason without its marker.
    $refuses(['allocation_override_reason' => 'why'], 'allocation_override_reason is required');

    // f — an engine rule claiming a human overrode it.
    $refuses(
        [
            'allocation_rule' => PaymentAllocation::RULE_PAYMENT_AGAINST_NAMED_INVOICE,
            'allocated_by_user_id' => null,
            'allocation_overridden' => true,
            'allocation_override_reason' => 'why',
        ],
        'Only an operator-directed allocation may be overridden',
    );

    // g — the payment axis, unchanged by any of the above and reached with a legal provenance row.
    $refuses(['amount_minor' => 5001], 'Allocation would exceed the payment amount');

    expect(PaymentAllocation::query()->where('payment_id', $payment->id)->count())->toBe(0);
});
