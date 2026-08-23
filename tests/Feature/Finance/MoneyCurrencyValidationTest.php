<?php

// Currency reaches App\Support\Money's constructor, whose ISO-4217 check throws InvalidArgumentException
// (no renderable → 500). The three finance requests that take a currency now mirror that invariant with a
// regex, so a bad case/format is a 422 one frame BEFORE the constructor — the same argument the
// backstop-reachability audit made about DB triggers, one layer up. Refuse, never uppercase.

use App\Finance\Actions\GenerateInvoice;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Models\CreditNote;
use App\Finance\Models\Invoice;
use App\Finance\Models\InvoiceLine;
use App\Models\Curriculum;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use App\Support\SchoolDay;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function mcvUser(School $school, string $role): User
{
    $u = User::factory()->create(['school_id' => $school->id]);
    $u->grantSchoolAccess($school, $role);
    $u->flushSchoolAccessCache();

    return $u;
}

function mcvInvoice(School $school): object
{
    $student = Student::factory()->create(['school_id' => $school->id]);

    return ActiveSchool::runFor($school->id, function () use ($school, $student) {
        $e = StudentCurriculum::create(['student_id' => $student->id, 'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id, 'status' => 'active']);

        return app(GenerateInvoice::class)->handle($e->uuid, [new InvoiceLineSpec('Tuition', Money::fromKobo(100000))], InvoiceKind::Scheduled);
    });
}

/** A fresh active enrollment in $school, as the uuid the generate endpoint takes. */
function mcvEnrollment(School $school): string
{
    $student = Student::factory()->create(['school_id' => $school->id]);

    return ActiveSchool::runFor($school->id, fn () => StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
        'status' => 'active',
    ])->uuid);
}

// ── C-2 / C-4 credit-note: the two refusals do not collide ──

it('C-2 — credit note currency "ngn" (right currency, wrong case) is a 422 naming currency, not a 500', function () {
    $school = School::factory()->create();
    $bursar = mcvUser($school, 'accounts_officer');
    $invoice = mcvInvoice($school);

    $this->actingAs($bursar)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/invoices/{$invoice->uuid}/credit-notes", ['amount_minor' => 1000, 'currency' => 'ngn'])
        ->assertStatus(422)                          // PLANT: drop the regex rule → 500 (Money ctor).
        ->assertJsonValidationErrors(['currency']);
});

it('C-4 — NGN 201, USD 422 (from the Action currency check): the regex and the Action guard do not shadow each other', function () {
    $school = School::factory()->create();
    $bursar = mcvUser($school, 'accounts_officer');
    $invoice = mcvInvoice($school);

    // Well-formed AND matching → 201.
    $this->actingAs($bursar)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/invoices/{$invoice->uuid}/credit-notes", ['amount_minor' => 1000, 'currency' => 'NGN'])
        ->assertCreated();

    // Well-formed but wrong currency → passes the regex, refused by SubmitCreditNote (422), NOT a 500.
    $this->actingAs($bursar)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/invoices/{$invoice->uuid}/credit-notes", ['amount_minor' => 1000, 'currency' => 'USD'])
        ->assertStatus(422);
});

// ── C-5 the rule behaves the same in a different controller (record payment) ──

it('C-5 — record-payment currency "ngn" is a 422; "NGN" is accepted', function () {
    $school = School::factory()->create();
    $bursar = mcvUser($school, 'accounts_officer'); // holds finance.access
    $invoice = mcvInvoice($school);

    $this->actingAs($bursar)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/invoices/{$invoice->uuid}/payments", ['amount_minor' => 1000, 'currency' => 'ngn', 'received_at' => SchoolDay::today(), 'bank_account_id' => testBankAccountUuid(), 'payer_name' => 'Parent'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['currency']);

    $this->actingAs($bursar)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/invoices/{$invoice->uuid}/payments", ['amount_minor' => 1000, 'currency' => 'NGN', 'received_at' => SchoolDay::today(), 'bank_account_id' => testBankAccountUuid(), 'payer_name' => 'Parent'])
        ->assertCreated();
});

// ── F1 — the render throws on non-NGN, so the EDGE must refuse it (cold review, finding 1) ──
//
// Money::format() raises on a non-NGN amount, where the concatenation it replaced printed any
// currency. Both rules below used to be shape-only — `size:3` + `^[A-Z]{3}$` — which refuses 'ngn'
// and ACCEPTS a well-formed 'USD'. That is how a non-NGN row could be written at all, and the
// throw then lands on a READ, after the row has committed: SubmitCreditNote builds its approval
// summary after the transaction ("AFTER the commit, never inside it"), so the failure mode was a
// committed credit note, no notification, and a 500. The guard belongs at the edge, not in the
// render — format() keeps throwing, because a formatter that guesses at a currency it cannot name
// is worse than one that refuses.
//
// A currency census of the production copy before this change found NGN only: 68 currency-bearing
// rows across three populated columns, ten other currency columns empty, no non-NGN and no NULLs.
// Nothing stored needs rescuing; this closes the door on new rows.
//
// BOTH ARMS COUNT ROWS, and that is the point rather than a flourish. A 422 alone would also be
// returned by a handler that wrote the row and then refused, which is the exact failure being
// fixed — the assertion has to be that nothing landed, not that the caller was told no.

it('F1 — a well-formed USD credit note is refused at the edge, and no credit note is written', function () {
    $school = School::factory()->create();
    $bursar = mcvUser($school, 'accounts_officer');
    $invoice = mcvInvoice($school);

    $before = CreditNote::withoutGlobalScopes()->count();

    $this->actingAs($bursar)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/invoices/{$invoice->uuid}/credit-notes", ['amount_minor' => 1000, 'currency' => 'USD'])
        ->assertStatus(422)
        // NAMED, so a 422 raised for some other reason cannot pass this arm.
        ->assertJsonValidationErrors(['currency']);

    expect(CreditNote::withoutGlobalScopes()->count())->toBe($before,
        'A USD credit note was WRITTEN and then refused. The row is what matters here: the old '
        .'failure committed the note and threw while rendering its approval summary, leaving a '
        .'credit note nobody was notified about.');
});

it('F1 — a well-formed USD invoice line is refused at the edge, and no invoice or line is written', function () {
    $school = School::factory()->create();
    $bursar = mcvUser($school, 'accounts_officer'); // holds finance.invoice.generate
    $enrollment = mcvEnrollment($school);

    $invoicesBefore = Invoice::withoutGlobalScopes()->count();
    $linesBefore = InvoiceLine::withoutGlobalScopes()->count();

    $this->actingAs($bursar)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', [
            'enrollment_id' => $enrollment,
            'kind' => InvoiceKind::Scheduled->value,
            'lines' => [['description' => 'Tuition', 'amount_minor' => 100000, 'currency' => 'USD']],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines.0.currency']);

    expect(Invoice::withoutGlobalScopes()->count())->toBe($invoicesBefore)
        ->and(InvoiceLine::withoutGlobalScopes()->count())->toBe($linesBefore,
            'A USD invoice was written. Every read path that renders it — the detail screen, the '
            .'printable, the allocation refusals — goes through Money::format(), which throws on '
            .'non-NGN, so the row would be unreadable rather than merely unusual.');
});
