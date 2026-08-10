<?php

// Currency reaches App\Support\Money's constructor, whose ISO-4217 check throws InvalidArgumentException
// (no renderable → 500). The three finance requests that take a currency now mirror that invariant with a
// regex, so a bad case/format is a 422 one frame BEFORE the constructor — the same argument the
// backstop-reachability audit made about DB triggers, one layer up. Refuse, never uppercase.

use App\Finance\Actions\GenerateInvoice;
use App\Finance\DTOs\InvoiceLineSpec;
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

        return app(GenerateInvoice::class)->handle($e->uuid, [new InvoiceLineSpec('Tuition', Money::fromKobo(100000))]);
    });
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
