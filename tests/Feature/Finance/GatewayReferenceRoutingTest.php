<?php

use App\Finance\Enums\InvoiceKind;
use App\Finance\Enums\InvoiceStatus;
use App\Finance\Models\GatewayTransaction;
use App\Finance\Models\Invoice;
use App\Finance\Services\GatewayReference;
use App\Models\Curriculum;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Support\ActiveSchool;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The reference is a CROSS-COMPONENT CONTRACT, and this file is its enforcement.
 *
 * The webhook derives the school from the reference so its lookup can run with `SchoolScope`
 * intact. That puts a correctness requirement on a component that does not exist yet — the
 * initialise call of step 3 — and the requirement's failure mode is silent in the worst direction:
 * a hand-built reference is accepted, the parent is charged, Paystack delivers, and the webhook
 * answers 200 having found nothing. Money taken, no payment recorded, one log line.
 *
 * A docblock on `GatewayReference` would not have stopped that: whoever writes step 3 has no reason
 * to read the webhook's documentation. So the rule is enforced at the WRITE, where the mistake is
 * made, and these arms are what stop the enforcement being removed by someone who finds it
 * inconvenient.
 *
 * THE NEGATIVE ARMS ARE THE POINT. A round-trip test alone would pass against a system with no
 * guard at all — `mint()` then `schoolIdFrom()` agrees with itself by construction. What proves the
 * contract is enforced is that the WRONG constructions are refused.
 */
uses(RefreshDatabase::class);

/**
 * A REAL invoice, not `invoice_id => 1`.
 *
 * The first version of this helper invented an id, which the composite (invoice_id, school_id)
 * foreign key refused with a 1452. The three REFUSAL arms still passed — the guard throws before
 * the insert is attempted — so only the ACCEPTANCE arm broke, and it broke as a Pest ERROR rather
 * than a failure. A summariser reading the `failures` bucket alone reported the file clean.
 *
 * That left the guard with no working known-negative: a guard refusing EVERYTHING would have shown
 * exactly the same green. The broken-closed shape `bin/db-exclusive` shipped with, reached this
 * time through the fixture rather than the matcher.
 */
function grrTransaction(School $school, string $reference): GatewayTransaction
{
    return ActiveSchool::runFor($school->id, function () use ($school, $reference) {
        $student = Student::factory()->create(['school_id' => $school->id]);
        $curriculum = Curriculum::factory()->create(['school_id' => $school->id]);
        $enrollment = StudentCurriculum::create([
            'student_id' => $student->id,
            'curriculum_id' => $curriculum->id,
            'status' => 'active',
        ]);

        $invoice = Invoice::create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'student_curriculum_id' => $enrollment->id,
            'number' => 1,
            'status' => InvoiceStatus::Issued,
            'kind' => InvoiceKind::Scheduled,
            'billed_to_name' => 'Ada Obi',
            'academic_context' => '2026/2027 First Term',
            'total' => Money::fromKobo(100_000),
        ]);

        return GatewayTransaction::create([
            'school_id' => $school->id,
            'invoice_id' => $invoice->id,
            'provider' => 'paystack',
            'reference' => $reference,
            'amount' => Money::fromKobo(100_000),
            'status' => 'pending',
        ]);
    });
}

it('routes a minted reference back to the school that minted it', function () {
    $school = School::factory()->create();

    expect(GatewayReference::schoolIdFrom(GatewayReference::mint((int) $school->id)))
        ->toBe((int) $school->id);
});

it('refuses to store a hand-built reference, at the write, before anyone is charged', function () {
    $school = School::factory()->create();

    // The natural mistake in step 3: a reference that looks perfectly reasonable and is unique.
    // Accepted by Paystack, and unfindable when the delivery comes back.
    expect(fn () => grrTransaction($school, 'INV-'.$school->id.'-000042'))
        ->toThrow(RuntimeException::class, 'must be minted by GatewayReference::mint()');
});

it('refuses a reference minted for a DIFFERENT school', function () {
    $a = School::factory()->create();
    $b = School::factory()->create();

    // Well-formed, routable, and routes somewhere else. The webhook would enter school B's context
    // and never find this row — the same silent loss, reached by a subtler mistake.
    expect(fn () => grrTransaction($a, GatewayReference::mint((int) $b->id)))
        ->toThrow(RuntimeException::class, 'but the row belongs to school#'.$a->id);
});

it('accepts a properly minted reference', function () {
    $school = School::factory()->create();

    // THE KNOWN NEGATIVE for the guard itself. Without this arm a guard that refused EVERYTHING
    // would pass every test above — the broken-closed failure that bin/db-exclusive shipped with.
    $reference = GatewayReference::mint((int) $school->id);

    expect(grrTransaction($school, $reference)->reference)->toBe($reference);
});

it('refuses the shapes a parser could be fooled by', function (string $reference) {
    expect(GatewayReference::schoolIdFrom($reference))->toBeNull();
})->with([
    'wrong prefix' => 'psk-1-abcdef',
    'no prefix' => '1-abcdef',
    'too few segments' => 'bpsk-1',
    'too many segments' => 'bpsk-1-abc-def',
    // `(int) '1e3'` is 1000 and `(int) '2 '` is 2 — a cast would route these to a REAL school.
    'scientific notation' => 'bpsk-1e3-abcdef',
    'trailing space' => 'bpsk-2 -abcdef',
    'school zero' => 'bpsk-0-abcdef',
    'negative' => 'bpsk--1-abcdef',
    'empty' => '',
]);
