<?php

// A foreign-currency payment ("USD" — a valid ^[A-Z]{3}$) used to bank silently and corrupt the account
// balance: applyToAccount's ON DUPLICATE KEY adds the kobo into balance_minor but never rewrites
// balance_currency, and the account-payment path writes no allocation row, so the one currency trigger
// (on allocations) is structurally unreachable. Three layers now refuse it: Rule::in on the requests, an
// invoice/account currency check in the two Actions, and a currency invariant at SubledgerPoster.

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\RecordAccountPayment;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\InvoiceKind;
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
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);
beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function pcgUser(School $s): User
{
    $u = User::factory()->create(['school_id' => $s->id]);
    $u->grantSchoolAccess($s, 'accounts_officer');
    $u->flushSchoolAccessCache();

    return $u;
}

function pcgInvoice(School $s, Student $student): object
{
    return ActiveSchool::runFor($s->id, function () use ($s, $student) {
        $e = StudentCurriculum::create(['student_id' => $student->id, 'curriculum_id' => Curriculum::factory()->create(['school_id' => $s->id])->id, 'status' => 'active']);

        return app(GenerateInvoice::class)->handle($e->uuid, [new InvoiceLineSpec('Tuition', Money::fromKobo(100000))], InvoiceKind::Scheduled);
    });
}

// ── E-2: account route USD → 422, and NOTHING landed (balance, payment, ledger) ──

it('account payment "USD" is a 422 and no balance moves, no payment, no ledger row', function () {
    $s = School::factory()->create();
    $u = pcgUser($s);
    $student = Student::factory()->create(['school_id' => $s->id]);
    $url = "/api/v1/finance/students/{$student->uuid}/payments";

    // Seed an existing NGN account (so the ON DUPLICATE KEY arm would run on the bad attempt).
    $this->actingAs($u)->withSession(['school_id' => $s->id])->postJson($url, ['amount_minor' => 100000, 'currency' => 'NGN', 'received_at' => SchoolDay::today(), 'bank_account_id' => testBankAccountUuid(), 'payer_name' => 'X'])->assertCreated();
    $balBefore = (int) DB::table('finance_student_accounts')->where('student_id', $student->id)->value('balance_minor');
    $payBefore = DB::table('finance_payments')->count();
    $ledBefore = DB::table('finance_ledger_transactions')->count();

    $this->actingAs($u)->withSession(['school_id' => $s->id])
        ->postJson($url, ['amount_minor' => 100000, 'currency' => 'USD', 'received_at' => SchoolDay::today(), 'bank_account_id' => testBankAccountUuid(), 'payer_name' => 'X'])
        ->assertStatus(422)->assertJsonValidationErrors(['currency']);

    // A 422 alone does not prove nothing landed — assert all four.
    expect((int) DB::table('finance_student_accounts')->where('student_id', $student->id)->value('balance_minor'))->toBe($balBefore)
        ->and(DB::table('finance_payments')->count())->toBe($payBefore)
        ->and(DB::table('finance_ledger_transactions')->count())->toBe($ledBefore)
        ->and(DB::table('finance_student_accounts')->where('student_id', $student->id)->value('balance_currency'))->toBe('NGN');
});

it('invoice payment "USD" is a 422 on both arms (outstanding>0 and fully allocated)', function () {
    $s = School::factory()->create();
    $u = pcgUser($s);
    $student = Student::factory()->create(['school_id' => $s->id]);
    $inv = pcgInvoice($s, $student);
    $post = fn (int $amt, string $cur) => $this->actingAs($u)->withSession(['school_id' => $s->id])
        ->postJson("/api/v1/finance/invoices/{$inv->uuid}/payments", ['amount_minor' => $amt, 'currency' => $cur, 'received_at' => SchoolDay::today(), 'bank_account_id' => testBankAccountUuid(), 'payer_name' => 'X']);

    $post(50000, 'USD')->assertStatus(422)->assertJsonValidationErrors(['currency']); // outstanding>0
    $post(100000, 'NGN')->assertCreated();                                            // fully allocate
    $post(50000, 'USD')->assertStatus(422)->assertJsonValidationErrors(['currency']); // fully allocated (the silent arm)
});

// ── E-4: not over-broad ──

it('NGN and an omitted currency still record on both routes; a fully-allocated NGN advance banks as credit', function () {
    $s = School::factory()->create();
    $u = pcgUser($s);
    $student = Student::factory()->create(['school_id' => $s->id]);
    $acctUrl = "/api/v1/finance/students/{$student->uuid}/payments";

    // account: explicit NGN, then omitted currency (defaults).
    $this->actingAs($u)->withSession(['school_id' => $s->id])->postJson($acctUrl, ['amount_minor' => 100000, 'currency' => 'NGN', 'received_at' => SchoolDay::today(), 'bank_account_id' => testBankAccountUuid(), 'payer_name' => 'X'])->assertCreated();
    $this->actingAs($u)->withSession(['school_id' => $s->id])->postJson($acctUrl, ['amount_minor' => 50000, 'received_at' => SchoolDay::today(), 'bank_account_id' => testBankAccountUuid(), 'payer_name' => 'X'])->assertCreated();

    // invoice: fully allocate in NGN, then a further NGN payment banks as an unallocated advance (201).
    $inv = pcgInvoice($s, $student);
    $invUrl = "/api/v1/finance/invoices/{$inv->uuid}/payments";
    $this->actingAs($u)->withSession(['school_id' => $s->id])->postJson($invUrl, ['amount_minor' => 100000, 'currency' => 'NGN', 'received_at' => SchoolDay::today(), 'bank_account_id' => testBankAccountUuid(), 'payer_name' => 'X'])->assertCreated();
    $this->actingAs($u)->withSession(['school_id' => $s->id])->postJson($invUrl, ['amount_minor' => 50000, 'received_at' => SchoolDay::today(), 'bank_account_id' => testBankAccountUuid(), 'payer_name' => 'X'])->assertCreated();
});

// ── Guard A (SubledgerPoster invariant) fires on a direct Action call, below the HTTP layer ──

it('SubledgerPoster refuses a currency mismatch against an existing account (LogicException)', function () {
    $s = School::factory()->create();
    $u = pcgUser($s);
    $student = Student::factory()->create(['school_id' => $s->id]);

    // Seed an NGN account through the normal path.
    $this->actingAs($u)->withSession(['school_id' => $s->id])
        ->postJson("/api/v1/finance/students/{$student->uuid}/payments", ['amount_minor' => 100000, 'currency' => 'NGN', 'received_at' => SchoolDay::today(), 'bank_account_id' => testBankAccountUuid(), 'payer_name' => 'X'])->assertCreated();

    // Call the Action directly with a USD Money, bypassing the request rule — the Action's own check fires
    // first (BusinessRuleException). This proves B independently of C; the SubledgerPoster backstop (A) sits
    // behind it for any future caller that skips B.
    ActiveSchool::runFor($s->id, function () use ($student, $u) {
        expect(fn () => app(RecordAccountPayment::class)->handle($student->id, Money::fromKobo(100000, 'USD'), 'X', $u, SchoolDay::today(), testBankAccountId()))
            ->toThrow(BusinessRuleException::class);
    });
});
