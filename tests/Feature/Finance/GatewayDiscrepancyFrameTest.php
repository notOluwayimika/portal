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
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
function gdrTransaction(School $school): GatewayTransaction
{
    return ActiveSchool::runFor($school->id, function () use ($school) {
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
            'status' => 'pending',
        ]);
    });
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
            return array_values($this->substituted);
        }
    };

    app(Kernel::class)->registerCommand($command);

    return 'finance:gdr-fixture';
}

/** A detector that examines exactly the ids it is given and reports nothing wrong with them. */
function gdrCleanScan(array $ids): callable
{
    return fn (int $schoolId, int $hours): array => [
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
    $this->artisan('finance:gateway-discrepancy-report --pending-hours=6')
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

    $this->artisan('finance:gateway-discrepancy-report')
        ->expectsOutputToContain('No detectors are registered')
        ->assertExitCode(1);
});

it('refuses with no detectors registered when transactions exist', function () {
    config()->set('finance.discrepancy.pending_hours', 24);
    gdrTransaction(School::factory()->create());

    $this->artisan('finance:gateway-discrepancy-report')
        ->expectsOutputToContain('No detectors are registered')
        ->assertExitCode(1);
});

// ── THE COVERAGE ARITHMETIC ────────────────────────────────────────────────────────────────────

it('passes only when every transaction was examined', function () {
    config()->set('finance.discrepancy.pending_hours', 24);
    $txn = gdrTransaction(School::factory()->create());

    $this->artisan(gdrWithDetectors([gdrCleanScan([(int) $txn->id])]))
        ->expectsOutputToContain('1 examined, 0 excluded, 0 unrecognised')
        ->expectsOutputToContain('1 transaction(s) all examined, none found')
        ->assertExitCode(0);
});

it('reports a transaction no detector examined, rather than reporting nothing', function () {
    config()->set('finance.discrepancy.pending_hours', 24);
    $school = School::factory()->create();
    $seen = gdrTransaction($school);
    gdrTransaction($school); // examined by nobody

    // The failure this frame exists to make impossible: a detector that quietly narrows its own
    // scope leaves rows unaccounted for, and without this number the report says "no findings".
    $this->artisan(gdrWithDetectors([gdrCleanScan([(int) $seen->id])]))
        ->expectsOutputToContain('1 examined, 0 excluded, 1 unrecognised')
        ->expectsOutputToContain('1 of 2 transaction(s) were examined by NO detector')
        ->assertExitCode(1);
});

it('counts a named exclusion as covered, and prints the rule', function () {
    config()->set('finance.discrepancy.pending_hours', 24);
    $school = School::factory()->create();
    $seen = gdrTransaction($school);
    $skipped = gdrTransaction($school);

    $detector = fn (int $schoolId, int $hours): array => [
        'examined' => [(int) $seen->id],
        'excluded' => [['txn_id' => (int) $skipped->id, 'rule' => 'settled before the window opened']],
        'findings' => [],
    ];

    // An exclusion is covered — but it is covered VISIBLY, with a rule a reader can disagree with,
    // which a narrowed WHERE clause would not have been.
    $this->artisan(gdrWithDetectors([$detector]))
        ->expectsOutputToContain('1 examined, 1 excluded, 0 unrecognised')
        ->expectsOutputToContain('excluded by "settled before the window opened": 1')
        ->assertExitCode(0);
});

it('reports a finding against a fully examined population', function () {
    config()->set('finance.discrepancy.pending_hours', 24);
    $txn = gdrTransaction(School::factory()->create());

    $detector = fn (int $schoolId, int $hours): array => [
        'examined' => [(int) $txn->id],
        'excluded' => [],
        'findings' => [['code' => 'D9', 'txn_id' => (int) $txn->id, 'detail' => 'a planted discrepancy']],
    ];

    // Asserts WHICH failure: a finding, not an unexamined row. Both exit 1, and conflating them
    // would hide a detector that reports findings without accounting for what it looked at.
    $this->artisan(gdrWithDetectors([$detector]))
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
        gdrCleanScan([(int) $txn->id]),
        gdrCleanScan([(int) $txn->id + 1000]),
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
    $this->artisan(gdrWithDetectors([gdrCleanScan([(int) $a->id])]))
        ->expectsOutputToContain('2 transaction(s) — 1 examined, 0 excluded, 1 unrecognised')
        ->assertExitCode(1);
});
