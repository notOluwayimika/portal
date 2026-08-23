<?php

/*
 * THE BOUNDARY HOUR — the one assertion that matters, and the one #229's drive could not make.
 *
 * That drive ran at 18:06 WAT, where the server's day and the school's day are the same date, so it
 * could not have detected the bug it was meant to cover. This file travels to the hour where they
 * differ and asserts BOTH directions: the school's answer and the server's answer, at the same
 * instant, are different dates. An arm that only checked the helper would pass even if the bug did
 * not exist, and would therefore prove nothing about why the helper is there.
 *
 * 00:30 in Lagos is 23:30 UTC on the PREVIOUS day. That single hour is when a bursar recording a
 * payment had their own current date refused as back-dated — for a reason field the modal had not
 * rendered, because the browser correctly believed it was already tomorrow.
 */

use App\Finance\Http\Requests\RecordAccountPaymentRequest;
use App\Finance\Http\Requests\RecordPaymentRequest;
use App\Support\SchoolDay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The instant: 23:30 UTC on 2026-08-09, which is 00:30 on 2026-08-10 in Lagos.
 *
 * Chosen with the app timezone at its real value rather than by manipulating config: the bug is
 * about the gap between two timezones at one instant, so the test has to fix the INSTANT and read
 * both clocks, never fix a clock and read the instant.
 */
function schoolDayBoundaryInstant(): Carbon
{
    return Carbon::parse('2026-08-09 23:30:00', 'UTC');
}

it('returns the SCHOOL day while the server is still on the previous one', function () {
    $this->travelTo(schoolDayBoundaryInstant());

    $serverDay = now()->toDateString();
    $schoolDay = SchoolDay::today();

    // Both directions, so the arm fails for the reason it claims rather than by coincidence.
    expect($serverDay)->toBe('2026-08-09',
        'The server is not on the previous day at this instant, so this test is no longer standing '
        .'at the boundary it was written for and proves nothing about it.');

    expect($schoolDay)->toBe('2026-08-10',
        'SchoolDay::today() agreed with the server instead of the school. At 00:30 in Lagos the '
        .'school is on the 10th; a business date taken from the server clock here is a day early, '
        .'which is the defect that refused a bursar their own current date for one hour a day.');

    expect($schoolDay)->not->toBe($serverDay);
});

it('is the SAME instant read on two clocks, not a different moment', function () {
    // The distinction the helper depends on: SchoolDay::now() must not be "now plus an hour". If it
    // were, every business date derived from it would drift on every call rather than only at the
    // boundary, and the arm above would still pass.
    $this->travelTo(schoolDayBoundaryInstant());

    expect(SchoolDay::now()->getTimestamp())->toBe(now()->getTimestamp(),
        'SchoolDay::now() returned a different INSTANT from now(). It is meant to be the same moment '
        .'expressed in the school’s timezone — a shifted instant would be a clock that is simply '
        .'wrong, and it would corrupt every date derived from it, not just the boundary hour.');

    expect(SchoolDay::now()->timezone->getName())->toBe('Africa/Lagos');
});

it('agrees with the server for the other twenty-three hours', function () {
    // Otherwise the helper would look correct in the test above while quietly moving every OTHER
    // business date by a day, which is a far larger defect than the one being fixed.
    $this->travelTo(Carbon::parse('2026-08-09 12:00:00', 'UTC'));

    expect(SchoolDay::today())->toBe(now()->toDateString());
});

it('the payment FormRequests take their day from the school, not the server', function (string $class) {
    // THE CALL SITES, not just the helper. RecordPaymentRequest's rules are built from a date string
    // at construction time, so the rule array itself is the observable: at the boundary hour it must
    // carry the school's day. This is what makes the bug fixed rather than merely fixable.
    $this->travelTo(schoolDayBoundaryInstant());

    /** @var FormRequest $request */
    $request = new $class;
    $rules = $request->rules();

    $receivedAt = implode('|', $rules['received_at']);
    $reason = implode('|', $rules['received_at_reason']);

    // str_contains + toBeTrue, NOT toContain($needle, $message): Pest's toContain treats every
    // extra argument as ANOTHER NEEDLE, so a failure message passed there is silently searched for
    // in the subject and the arm fails for the wrong reason. Same family as #222's negated-
    // expectation trap — a matcher quietly reinterpreting the thing you meant as an explanation.
    expect(str_contains($receivedAt, 'before_or_equal:2026-08-10'))->toBeTrue(
        $class.' caps received_at at the SERVER day ('.$receivedAt.'), so at 00:30 in Lagos a bursar '
        .'entering today’s date is refused for being in the future.');

    expect(str_contains($reason, 'required_unless:received_at,2026-08-10'))->toBeTrue(
        $class.' demands a back-dating reason for the school’s own current date ('.$reason.').');
})->with([
    // KEYED, and not merely for readable output. An unkeyed dataset of these two class strings
    // produces ZERO tests and a bare "failed" with no message — Pest derives each case's name from
    // the value, and two long backslash-laden strings collide into something it cannot register.
    // Either one ALONE passes, which is what makes it dangerous: the arm looks written, the file
    // looks green-ish, and nothing ran. Measured here before it could be mistaken for a runner
    // hiccup; keys give each case a name of its own and the arm executes.
    'invoice route' => [RecordPaymentRequest::class],
    'account route' => [RecordAccountPaymentRequest::class],
]);

it('at the boundary hour the rule REFUSES the server day and accepts the school day', function () {
    // THE CONSEQUENCE, not just the rule text. The arm above asserts the rules array carries
    // `required_unless:received_at,2026-08-10`; this one runs a payload through it and shows what
    // that means for the two candidate dates at the same instant. It is the assertion the fixture
    // defect needed: production is correct, and a value taken from the server clock is REFUSED by
    // it — so a test posting one is measuring the wrong day, not catching a bug.
    //
    // Validator directly, no HTTP and no database: this is the clock file, and an arm here must not
    // be able to fail because an enrollment fixture changed shape.
    $this->travelTo(schoolDayBoundaryInstant());

    expect(now()->toDateString())->toBe('2026-08-09')
        ->and(SchoolDay::today())->toBe('2026-08-10');

    // THE DATE RULES ONLY, and this arm used to take the whole array. The claim above said "no
    // HTTP and no database" and was FALSE: RecordPaymentRequest's bank_account_id carries
    // Rule::exists(BankAccount…), so validating a full payload queried finance_bank_accounts on
    // every run. Narrowing to the two rules under test makes the sentence true rather than
    // softening it, and the query-count assertion below makes it enforced rather than merely
    // written — a claim about hermeticity that nothing checks decays the first time someone adds a
    // rule to the request.
    $rules = array_intersect_key(
        (new RecordPaymentRequest)->rules(),
        array_flip(['received_at', 'received_at_reason']),
    );

    $payload = fn (string $receivedAt) => ['received_at' => $receivedAt];

    DB::flushQueryLog();
    DB::enableQueryLog();

    $schoolDay = Validator::make($payload(SchoolDay::today()), $rules);
    $serverDay = Validator::make($payload(now()->toDateString()), $rules);

    // Force both to run their rules before the log is read — Validator is lazy.
    $schoolDayErrors = $schoolDay->errors();
    $serverDayErrors = $serverDay->errors();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toBe([],
        'This arm touched the database. It is the clock file: it must be able to fail for a clock '
        .'reason and no other, and a rule that reaches for a row is a rule that can fail because a '
        .'fixture changed. '.count($queries).' query/queries ran: '
        .implode(' | ', array_map(fn ($q) => $q['query'], $queries)));

    expect($schoolDayErrors->has('received_at_reason'))->toBeFalse(
        'The school’s own current date was treated as back-dated at 00:30 in Lagos — the defect '
        .'SchoolDay exists to end.');

    expect($serverDayErrors->has('received_at_reason'))->toBeTrue(
        'The SERVER’s date passed without a back-dating reason at the boundary hour. It is a day '
        .'early, so a test fixture posting it is not exercising the same-day path it believes it '
        .'is — which is exactly how InvoiceKindOnReadPathsTest came to fail for one hour a day.');
});

it('leaves no test fixture posting a business date from the server clock', function () {
    // THE STATIC SIBLING of the app/Finance scan below, over tests/ — and it exists because the
    // scan below could not have caught what actually happened. Production was already correct;
    // InvoiceKindOnReadPathsTest posted `now()->format('Y-m-d')` into a rule built from
    // SchoolDay::today(), so for one hour a day the SUITE failed while the application was right.
    // A red that appears and disappears with the wall clock is the worst kind: it reads as a flake,
    // and the standing advice for a flake is to re-run it.
    //
    // KNOWN LIMITS, stated as limits rather than as things to fix later — same voice as the
    // money-lint's own note, and for the same reason: a reader who believes this is exhaustive is
    // worse off than one who knows its edges, because they will read a green run as proof.
    //
    //   BIND-THEN-USE is not caught.        $d = now()->toDateString();  ...  'received_at' => $d,
    //   A MULTI-LINE SPLIT is not caught.   'received_at' =>
    //                                           now()->toDateString(),
    //
    // Both need to follow a value across lines or across a binding, which is flow analysis, and
    // this is a grep with a carve-out list. What it does buy is that the SHAPE THAT ACTUALLY
    // HAPPENED cannot recur silently, and that the remaining routes are conspicuous rather than
    // casual.
    $root = dirname(__DIR__, 2);
    $offenders = [];

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        foreach (file($file->getPathname()) as $i => $line) {
            // str_starts_with, NOT str_contains. This line used to read
            // `if (str_contains(ltrim($line), '//'))`, which skips any line carrying a TRAILING
            // comment — so a genuine offender followed by `// note` was invisible, and four of the
            // five spellings below escaped through it. The money-lint's isComment() had it right
            // from the start; this is the same test, spelled the same way.
            $t = ltrim($line);
            if (str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*') || str_starts_with($t, '#')) {
                continue;
            }

            if (! preg_match("/'received_at'\s*=>(.*)$/", $line, $m)) {
                continue;
            }
            $rhs = $m[1];

            // CARVE-OUT 1 — the correct spelling. SchoolDay::today() / SchoolDay::now() is the
            // school's clock, which is the whole point.
            if (str_contains($rhs, 'SchoolDay::')) {
                continue;
            }

            // CARVE-OUT 2 — a DELIBERATE offset. `now()->subDays(3)` is a back-dated fixture
            // testing the back-dating path on purpose; it is a different day on either clock, so
            // the boundary hour cannot make it flip. Flagging it would force a baseline entry, and
            // the baseline is empty on purpose.
            if (preg_match('/(sub|add)(Day|Days|Week|Weeks|Month|Months|Year|Years)\s*\(/', $rhs)) {
                continue;
            }

            // The server's clock, in every spelling that reaches a date: now()->toDateString(),
            // now()->format('Y-m-d'), the bare today() helper, Carbon::now(), Carbon::today().
            // The rule used to name only the first two, which is four of five missed.
            if (preg_match('/\b(now\(\)|today\(\)|Carbon::now|Carbon::today)/', $rhs)) {
                $offenders[] = str_replace($root.'/', '', $file->getPathname()).':'.($i + 1);
            }
        }
    }

    expect($offenders)->toBe([],
        'A test fixture posts a business date taken from the server clock: '.implode(', ', $offenders)
        .'. Use App\Support\SchoolDay::today(). Between 23:00 and 00:00 UTC the server is on the '
        .'previous day, so this fixture and the FormRequest rule it is measured against are a day '
        .'apart — the test fails for one hour out of every twenty-four while the application is '
        .'entirely correct.');
});

it('leaves no business date in app/Finance taking its day from the server clock', function () {
    // THE RECONCILIATION, MADE PERMANENT. The survey that drove this change was a one-off: it was
    // the oracle, and #229's lesson is that nothing compared the diff to it. This arm is that
    // comparison, kept — a new `now()->toDateString()` under app/Finance is a business date on the
    // server's clock and fails here rather than being noticed a fortnight later.
    //
    // Scoped to app/Finance because that is where dates are business facts. An export filename or a
    // saved-filter default legitimately uses the server clock and is not caught by this.
    $root = dirname(__DIR__, 3).'/app/Finance';
    $offenders = [];

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        foreach (file($file->getPathname()) as $i => $line) {
            if (str_contains($line, 'now()->toDateString()') && ! str_contains(ltrim($line), '//')) {
                $offenders[] = str_replace($root.'/', '', $file->getPathname()).':'.($i + 1);
            }
        }
    }

    expect($offenders)->toBe([],
        'A business date under app/Finance is taken from the server clock: '.implode(', ', $offenders)
        .'. Use App\Support\SchoolDay::today() — the server is on the previous day between 00:00 and '
        .'01:00 WAT, and these values land in append-only tables that can never be corrected.');
});
