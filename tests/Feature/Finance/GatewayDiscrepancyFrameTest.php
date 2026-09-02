<?php

use App\Finance\Console\GatewayDiscrepancyReport;
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
use App\Support\SchoolDay;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * THE FRAME, NOT THE CLASSES. `finance:gateway-discrepancy-report` ships with an empty detector
 * registry because §8 has not been read; what is under test here is the property that makes that
 * safe — an unbuilt or partially-built report CANNOT render as a clean one.
 *
 * Every arm asserts WHICH refusal, never merely that the command exited non-zero. There are four
 * distinct failure modes (no window configured, no detectors registered, rows nobody examined,
 * coverage that does not sum) and an arm satisfied by any of them would pass against a command that
 * always refuses — the broken-closed shape this project has already shipped once, in the tool
 * written to prevent a different false signal.
 */
uses(RefreshDatabase::class);

/**
 * A real transaction on a real invoice. The composite (invoice_id, school_id) foreign key refuses an
 * invented id, and the reference must be minted or the model's own guard refuses the write — so
 * there is no shortcut that produces a row here.
 */
function gdrTransaction(School $school, string $status = 'pending', ?int $ageHours = null): GatewayTransaction
{
    return ActiveSchool::runFor($school->id, function () use ($school, $status, $ageHours) {
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
            // Derived, not literal: two transactions in ONE school is the fixture several arms need,
            // and a hardcoded 1 collides on (school_id, number). It surfaced as a Pest ERROR rather
            // than a failure — 13 of 15 "passed" — which is the summariser trap this repo already
            // records: count errors as reds.
            'number' => Invoice::query()->where('school_id', $school->id)->max('number') + 1,
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
            'reference' => GatewayReference::mint((int) $school->id),
            'amount' => Money::fromKobo(100_000),
            'status' => $status,
            // SET AT INSERT, NEVER BY UPDATE. `finance_gateway_transactions_update_guard` freezes
            // `created_at` — an UPDATE to backdate it is refused with 1644, which is the trigger
            // doing its job and the fixture asking for something the schema forbids.
            'created_at' => $ageHours === null ? now() : now()->subHours($ageHours),
        ]);
    });
}

/** Settle a transaction the way `claim()` does — payment, fee and status in one act. */
function gdrSettle(GatewayTransaction $txn, int $feeKobo, int $paidKobo): int
{
    return ActiveSchool::runFor((int) $txn->school_id, function () use ($txn, $feeKobo, $paidKobo) {
        $payment = gdrPayment($txn->school_id, $txn->invoice_id, $paidKobo);

        // BOTH COLUMNS TOGETHER, because production writes them in one statement: `claim()` sets
        // `payment_id` and `fee_minor` in the same UPDATE. A fixture that set only the payment would
        // construct a state the schema makes impossible — and D3 now reports exactly that state, so
        // the fixture would be measuring its own shortcut.
        DB::table('finance_gateway_transactions')->where('id', $txn->id)->update([
            'payment_id' => $payment,
            'fee_minor' => $feeKobo,
            'fee_currency' => 'NGN',
        ]);

        return $payment;
    });
}

/** A finance_payments row of gateway origin. Returns its id. */
function gdrPayment(int $schoolId, int $invoiceId, int $kobo): int
{
    $invoice = Invoice::withoutGlobalScopes()->findOrFail($invoiceId);

    return (int) DB::table('finance_payments')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'school_id' => $schoolId,
        'student_id' => $invoice->student_id,
        // A per-school integer sequence, not a string — derived so two payments in one school do
        // not collide, and so the fixture does not invent a shape the column refuses.
        'reference' => (int) DB::table('finance_payments')->where('school_id', $schoolId)->max('reference') + 1,
        'amount_minor' => $kobo,
        'amount_currency' => 'NGN',
        'payer_name' => 'Ada Obi',
        'method' => 'card',
        'origin' => 'gateway',
        'bank_account_id' => testBankAccountId($schoolId),
        // THE SCHOOL'S CLOCK, NOT THE SERVER'S. Nigeria is UTC+1, so between 23:00 and midnight UTC
        // the server's date is a day behind the school's — a fixture posting the server date makes
        // the SUITE fail for one hour a day while the application is right, which reads as a flake
        // and invites a re-run. `SchoolDayTest` scans for exactly this and refused the push that
        // carried it.
        'received_at' => SchoolDay::today(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Registers a variant of the command whose detector registry is the given list, under its own name.
 *
 * The substitution is the only way to exercise the coverage arithmetic today: the real registry is
 * empty, so every path past the empty-registry guard would be unreachable, and the frame's central
 * claim would ship unproven until its first consumer arrived to test it by accident.
 *
 * @param  list<callable(int, int): array{examined: list<int>, excluded: list<array{txn_id: int, rule: string}>, findings: list<array{code: string, txn_id: int, detail: string}>}>  $detectors
 */
function gdrWithDetectors(array $detectors): string
{
    $command = new class($detectors) extends GatewayDiscrepancyReport
    {
        protected $signature = 'finance:gdr-fixture {--pending-hours=}';

        /** @var array<int, callable> */
        private array $substituted;

        public function __construct(array $detectors)
        {
            $this->substituted = $detectors;
            parent::__construct();
        }

        protected function detectors(): array
        {
            return $this->substituted;
        }
    };

    app(Kernel::class)->registerCommand($command);

    return 'finance:gdr-fixture';
}

/** A detector that examines exactly the ids it is given and reports nothing wrong with them. */
function gdrCleanScan(array $ids, string $population = 'transactions'): callable
{
    return fn (int $schoolId, int $hours): array => [
        'population' => $population,
        'examined' => $ids,
        'excluded' => [],
        'findings' => [],
    ];
}

// ── THE WINDOW: no default, and the refusal names the key ───────────────────────────────────────

it('refuses to run when no pending window is configured and none is given', function () {
    config()->set('finance.discrepancy.pending_hours', null);

    $this->artisan('finance:gateway-discrepancy-report')
        ->expectsOutputToContain('finance.discrepancy.pending_hours')
        ->assertExitCode(1);
});

it('accepts the flag when the config is unset, and then fails for the OTHER reason', function () {
    config()->set('finance.discrepancy.pending_hours', null);

    // The distinguishing assertion: it got PAST the window refusal (whose message names the config
    // key) and stopped at the empty registry. An arm asserting only a non-zero exit could not tell
    // these apart, and would pass against a command that never reads the flag at all.
    $this->artisan(gdrWithDetectors([]).' --pending-hours=6')
        ->doesntExpectOutputToContain('finance.discrepancy.pending_hours')
        ->expectsOutputToContain('No detectors are registered')
        ->assertExitCode(1);
});

it('refuses a window that is not a positive whole number of hours', function (string $value) {
    config()->set('finance.discrepancy.pending_hours', null);

    $this->artisan('finance:gateway-discrepancy-report --pending-hours='.$value)
        ->expectsOutputToContain('--pending-hours option must be a positive whole number')
        ->assertExitCode(1);
})->with(['0', '-3', 'six', '2.5']);

it('refuses a configured window that is not a positive whole number of hours', function () {
    config()->set('finance.discrepancy.pending_hours', '0');

    $this->artisan('finance:gateway-discrepancy-report')
        ->expectsOutputToContain('configured pending window must be a positive whole number')
        ->assertExitCode(1);
});

// ── THE CENTRAL PROPERTY: unbuilt cannot read as clean ─────────────────────────────────────────

it('refuses with no detectors registered, even on an empty database', function () {
    config()->set('finance.discrepancy.pending_hours', 24);

    // The degenerate case the empty-registry guard exists for. With zero transactions, population,
    // examined and unrecognised are all zero, so every coverage check downstream passes — and this
    // is the state a fresh environment is in the first time anyone runs the command.
    expect(GatewayTransaction::query()->count())->toBe(0);

    $this->artisan(gdrWithDetectors([]))
        ->expectsOutputToContain('No detectors are registered')
        ->assertExitCode(1);
});

it('refuses with no detectors registered when transactions exist', function () {
    config()->set('finance.discrepancy.pending_hours', 24);
    gdrTransaction(School::factory()->create());

    $this->artisan(gdrWithDetectors([]))
        ->expectsOutputToContain('No detectors are registered')
        ->assertExitCode(1);
});

it('registers exactly the four classes of §8.1, BY NAME', function () {
    // THE CENSUS, AND IT IS NOT DECORATION. The two arms above assert the empty-registry behaviour
    // against an INJECTED empty registry, which means nothing else in this file checks that the REAL
    // one is populated. A refactor that emptied it would make every class find nothing and the
    // command would exit 0 looking clean — §8's "zero discrepancies must be distinguishable from the
    // command did not run", failing one level above where the coverage line catches it.
    //
    // BY NAME, NOT BY COUNT. Four is satisfied by swapping one detector for a duplicate of another.
    $registry = (new ReflectionClass(GatewayDiscrepancyReport::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(GatewayDiscrepancyReport::class, 'detectors');
    $method->setAccessible(true);

    expect(array_keys($method->invoke($registry)))->toBe(['D1', 'D2', 'D3', 'D4']);
});

// ── THE COVERAGE ARITHMETIC ────────────────────────────────────────────────────────────────────

it('passes only when every transaction was examined', function () {
    config()->set('finance.discrepancy.pending_hours', 24);
    $txn = gdrTransaction(School::factory()->create());

    $this->artisan(gdrWithDetectors(['D1' => gdrCleanScan([(int) $txn->id])]))
        ->expectsOutputToContain('1 examined, 0 excluded, 0 unrecognised')
        ->expectsOutputToContain('all examined')
        ->assertExitCode(0);
});

it('reports a transaction no detector examined, rather than reporting nothing', function () {
    config()->set('finance.discrepancy.pending_hours', 24);
    $school = School::factory()->create();
    $seen = gdrTransaction($school);
    gdrTransaction($school); // examined by nobody

    // The failure this frame exists to make impossible: a detector that quietly narrows its own
    // scope leaves rows unaccounted for, and without this number the report says "no findings".
    $this->artisan(gdrWithDetectors(['D1' => gdrCleanScan([(int) $seen->id])]))
        ->expectsOutputToContain('1 examined, 0 excluded, 1 unrecognised')
        ->expectsOutputToContain('1 row(s) were examined by NO detector')
        ->assertExitCode(1);
});

it('counts a named exclusion as covered, and prints the rule', function () {
    config()->set('finance.discrepancy.pending_hours', 24);
    $school = School::factory()->create();
    $seen = gdrTransaction($school);
    $skipped = gdrTransaction($school);

    $detector = fn (int $schoolId, int $hours): array => [
        'population' => 'transactions',
        'examined' => [(int) $seen->id],
        'excluded' => [['id' => (int) $skipped->id, 'rule' => 'settled before the window opened']],
        'findings' => [],
    ];

    // An exclusion is covered — but it is covered VISIBLY, with a rule a reader can disagree with,
    // which a narrowed WHERE clause would not have been.
    $this->artisan(gdrWithDetectors(['D9' => $detector]))
        ->expectsOutputToContain('1 examined, 1 excluded, 0 unrecognised')
        ->expectsOutputToContain('excluded by "settled before the window opened": 1')
        ->assertExitCode(0);
});

it('reports a finding against a fully examined population', function () {
    config()->set('finance.discrepancy.pending_hours', 24);
    $txn = gdrTransaction(School::factory()->create());

    $detector = fn (int $schoolId, int $hours): array => [
        'population' => 'transactions',
        'examined' => [(int) $txn->id],
        'excluded' => [],
        'findings' => [['code' => 'D9', 'id' => (int) $txn->id, 'detail' => 'a planted discrepancy']],
    ];

    // Asserts WHICH failure: a finding, not an unexamined row. Both exit 1, and conflating them
    // would hide a detector that reports findings without accounting for what it looked at.
    $this->artisan(gdrWithDetectors(['D9' => $detector]))
        ->expectsOutputToContain('[D9] school#')
        ->doesntExpectOutputToContain('examined by NO detector')
        ->expectsOutputToContain('1 discrepanc(ies)')
        ->assertExitCode(1);
});

it('refuses when the buckets over-count the population', function () {
    config()->set('finance.discrepancy.pending_hours', 24);
    $txn = gdrTransaction(School::factory()->create());

    // Two detectors, each claiming a different id, against a population of one — the shape a
    // detector reporting rows it never evaluated produces. Left unchecked it makes unrecognised
    // negative, which reads as "more than fully covered" and would have printed a clean result.
    $this->artisan(gdrWithDetectors([
        'D1' => gdrCleanScan([(int) $txn->id]),
        'D3' => gdrCleanScan([(int) $txn->id + 1000]),
    ]))
        ->expectsOutputToContain('Coverage does not sum')
        ->doesntExpectOutputToContain('none found')
        ->assertExitCode(1);
});

it('counts the population across every school, under each school context', function () {
    config()->set('finance.discrepancy.pending_hours', 24);
    $a = gdrTransaction(School::factory()->create());
    $b = gdrTransaction(School::factory()->create());

    expect($a->school_id)->not->toBe($b->school_id);

    // Both schools' rows are in the denominator; examining only one leaves the other unrecognised
    // rather than invisible. A report that iterated one school would print "1 transaction(s)".
    $this->artisan(gdrWithDetectors(['D1' => gdrCleanScan([(int) $a->id])]))
        ->expectsOutputToContain('2 total — 1 examined, 0 excluded, 1 unrecognised')
        ->assertExitCode(1);
});

// ── §8's DEFINITION OF DONE ────────────────────────────────────────────────────────────────────
//
// "A deliberately broken pair — a success with no payment, and a payment with no transaction — is
// detected and named by the command." Both are planted here against the REAL registry, not a
// substituted one, so what is measured is the shipped detector rather than a fixture standing in
// for it.

it('D1 — names a transaction the provider called successful against which no payment exists', function () {
    config()->set('finance.discrepancy.pending_hours', 24);
    $txn = gdrTransaction(School::factory()->create(), status: 'success');

    // THE CLASS THAT COSTS THE SCHOOL ITS CREDIBILITY: the parent has paid and nothing records it.
    // ONE ASSERTION PER OUTPUT LINE. `expectsOutputToContain` consumes expectations against
    // SUCCESSIVE lines, so two of them aimed at the same line silently cannot both match — the
    // second looks for a later line and fails. The identifier, the class and the amount are all on
    // the finding line, so they are asserted together, which is also how §8 wants them read:
    // identifiers, amounts and the discrepancy, not a count.
    $this->artisan('finance:gateway-discrepancy-report')
        ->expectsOutputToContain('[D1] school#'.$txn->school_id.' txn#'.$txn->id.': provider reported SUCCESS')
        ->expectsOutputToContain('transactions:')
        ->assertExitCode(1);
});

it('D1 — and says nothing when the same transaction is settled', function () {
    config()->set('finance.discrepancy.pending_hours', 24);
    $txn = gdrTransaction(School::factory()->create(), status: 'success');

    // THE KNOWN NEGATIVE, and it is what stops D1 being a detector that fires on every success row.
    // Without it, `WHERE status = 'success'` with the payment_id test deleted passes the arm above.
    gdrSettle($txn, feeKobo: 1_600, paidKobo: 100_000 - 1_600);

    $this->artisan('finance:gateway-discrepancy-report')
        ->doesntExpectOutputToContain('[D1]')
        ->assertExitCode(0);
});

it('D2 — names a gateway payment no transaction accounts for', function () {
    config()->set('finance.discrepancy.pending_hours', 24);
    $txn = gdrTransaction(School::factory()->create(), status: 'success');

    $paymentId = ActiveSchool::runFor((int) $txn->school_id, function () use ($txn) {
        // Settle it properly so it is neither a D1 nor a D3 finding, then add a SECOND payment that
        // no transaction names — the only thing left for the report to say.
        $settled = gdrPayment($txn->school_id, $txn->invoice_id, 100_000 - 1_600);
        DB::table('finance_gateway_transactions')->where('id', $txn->id)->update([
            'payment_id' => $settled, 'fee_minor' => 1_600, 'fee_currency' => 'NGN',
        ]);

        return gdrPayment($txn->school_id, $txn->invoice_id, 250_000);
    });

    // ITS POPULATION IS PAYMENTS, WHICH IS WHY THE FRAME CARRIES TWO DENOMINATORS. Counted against
    // the transaction count, this row is not in the population at all and would be invisible to the
    // coverage check that exists to make invisibility impossible.
    $this->artisan('finance:gateway-discrepancy-report')
        ->expectsOutputToContain('[D2] school#'.$txn->school_id.' payment#'.$paymentId.': payment of gateway origin')
        ->expectsOutputToContain('payments:')
        ->assertExitCode(1);
});

it('D3 — says NOTHING about a healthy pair, which is the arm that justifies reading §8 rather than following it', function () {
    config()->set('finance.discrepancy.pending_hours', 24);
    $txn = gdrTransaction(School::factory()->create(), status: 'success');

    // A CORRECTLY SETTLED PAIR: the transaction carries the GROSS and the payment carries
    // `gross − fee`, because the parent bears the fee (Dev 1, 2026-08-30).
    gdrSettle($txn, feeKobo: 1_600, paidKobo: 100_000 - 1_600);

    // THIS ARM IS THE INTERPRETATION, MADE EXECUTABLE. §8 says "which pairs disagree on amount", and
    // this pair DOES disagree — 100,000 against 98,400 — by design. A detector written to §8's words
    // literally flags it, and would therefore flag every healthy settlement in the system: a report
    // ignored the second time it runs. The invariant that preserves §8's INTENT is
    // `amount − fee == payment.amount`, and this is where that reading is proven rather than argued.
    $this->artisan('finance:gateway-discrepancy-report')
        ->doesntExpectOutputToContain('[D3]')
        ->assertExitCode(0);
});

it('D3 — names a pair whose numbers do not reconcile', function () {
    config()->set('finance.discrepancy.pending_hours', 24);
    $txn = gdrTransaction(School::factory()->create(), status: 'success');

    // The fee says 1,600 but the payment credited as though it were 5,000. Three DISTINGUISHABLE
    // numbers, so a detector comparing the wrong pair cannot pass by coincidence.
    gdrSettle($txn, feeKobo: 1_600, paidKobo: 100_000 - 5_000);

    $this->artisan('finance:gateway-discrepancy-report')
        ->expectsOutputToContain('[D3] school#'.$txn->school_id.' txn#'.$txn->id.': charged ₦1,000.00 less fee ₦16.00')
        ->assertExitCode(1);
});

it('D4 — names a pending checkout older than the window', function () {
    config()->set('finance.discrepancy.pending_hours', 24);
    $txn = gdrTransaction(School::factory()->create(), ageHours: 30);

    $this->artisan('finance:gateway-discrepancy-report')
        ->expectsOutputToContain('[D4] school#'.$txn->school_id.' txn#'.$txn->id.': still awaiting an answer')
        ->assertExitCode(1);
});

it('D4 — says NOTHING about a pending checkout inside the window, which is what makes --pending-hours real', function () {
    config()->set('finance.discrepancy.pending_hours', 24);
    $txn = gdrTransaction(School::factory()->create(), ageHours: 2);

    // THE THRESHOLD IS D4's ENTIRE CONTENT, so this is the arm that matters. A detector flagging
    // every `pending` row regardless of age passes the positive arm perfectly, and `--pending-hours`
    // becomes decoration — a parameter the report accepts and does not use. The positive shows it
    // fires; only this shows it DISCRIMINATES.
    $this->artisan('finance:gateway-discrepancy-report')
        ->doesntExpectOutputToContain('[D4]')
        ->assertExitCode(0);
});

it('D4 — excludes failed and abandoned BY NAME, keeping them in the denominator', function () {
    config()->set('finance.discrepancy.pending_hours', 24);
    $school = School::factory()->create();
    gdrTransaction($school, status: 'failed', ageHours: 500);

    // ANSWERED, NOT STUCK — the ruling is `GatewayTransactionStatus`'s own docblock, not this file.
    // Read as the database's sense of "non-terminal", D4 returns every abandoned checkout that ever
    // happened, for ever.
    //
    // AND IT IS AN EXCLUSION RATHER THAN A NARROWED `WHERE`, which is the property that makes the
    // whole coverage apparatus worth its cost: the row stays in the denominator with a stated
    // reason a reader can disagree with. A row filtered out of the query cannot be disagreed with,
    // because nobody can see it was ever there. The difference between nobody looked and somebody
    // decided.
    $this->artisan('finance:gateway-discrepancy-report')
        ->doesntExpectOutputToContain('[D4]')
        ->expectsOutputToContain('excluded by "answered by the provider (failed), not awaiting an answer": 1')
        ->assertExitCode(0);
});
