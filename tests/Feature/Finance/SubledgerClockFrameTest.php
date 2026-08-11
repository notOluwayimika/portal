<?php

use App\Finance\Enums\LedgerEntryType;
use App\Finance\Services\SubledgerPoster;
use App\Models\School;
use App\Models\Student;
use App\Support\ActiveSchool;
use App\Support\Money;
use App\Support\SchoolDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ONE CLOCK FRAME — SubledgerPoster's ledger row and account projection land in the same clock
 * frame, so the two columns are read back consistently.
 *
 * THAT IS THE CLAIM, AND IT IS NARROWER THAN "the same instant". This asserts the frame, which is
 * the property that was broken (19,800s apart on production). It does not assert single-capture:
 * a second now() inside applyToAccount would pass here whenever both calls fall in the same
 * second, and an arm that could catch that would fail intermittently — indistinguishable from
 * flake, on a defect nobody has. Single-capture is structural in post() instead; see its docblock.
 *
 * WHY THIS ARM SETS THE SESSION ZONE RATHER THAN READING IT. The defect only exists when MySQL's
 * session zone differs from app.timezone: `NOW()` is evaluated in the session zone and stores the
 * true instant, then renders back into that zone, and Laravel parses that string as UTC — so the
 * value reads AHEAD by the offset. A PHP-written column is the mirror image (stored early, reads
 * exact). On a machine whose session zone is UTC the two clocks agree and the bug CANNOT appear,
 * so the obvious arm — post a row, assert the timestamps match — would be green for the wrong
 * reason and prove nothing. This one pins the session to '+05:30', production's actual zone, and
 * asserts the frames genuinely differ before asserting the columns agree anyway.
 *
 * The session zone persists for the connection, so it is restored in a finally: leaking it would
 * corrupt every later test in this process in ways that look exactly like flake.
 */
uses(RefreshDatabase::class);

/**
 * Run $body with the MySQL session zone pinned to production's, restoring it afterwards.
 *
 * @template T
 *
 * @param  callable():T  $body
 * @return T
 */
function withProductionSessionZone(callable $body): mixed
{
    $original = DB::selectOne('SELECT @@session.time_zone AS tz')->tz;

    try {
        DB::statement("SET SESSION time_zone = '+05:30'");

        return $body();
    } finally {
        DB::statement('SET SESSION time_zone = ?', [$original]);
        test()->travelBack();   // the body advances the test clock; a leaked offset poisons later tests
    }
}

it('lands the ledger row and the account projection in ONE CLOCK FRAME, under production\'s session zone', function () {
    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);

    withProductionSessionZone(function () use ($school, $student) {
        // THE ARM IS NOT VACUOUS: prove the two clocks are in different frames on THIS connection
        // before proving the columns agree. If this expectation ever fails, the session zone did
        // not take (or app.timezone moved) and everything below would be green for free.
        expect(DB::selectOne('SELECT @@session.time_zone AS tz')->tz)->toBe('+05:30');
        $sqlClock = strtotime(DB::selectOne('SELECT NOW() AS n')->n);
        expect($sqlClock - now()->getTimestamp())->toBeGreaterThan(19700); // ≈ +19,800s (+05:30 vs UTC)

        $poster = app(SubledgerPoster::class);

        $first = ActiveSchool::runFor($school->id, fn () => $poster->post(
            $school->id, $student->id, LedgerEntryType::Charge, Money::fromKobo(10_000),
            'invoice', 1, 'Clock-frame arm — the INSERT branch', SchoolDay::today(),
        ));

        // Read both columns RAW. MySQL renders each into the session zone, which is precisely the
        // string Laravel would parse; a cross-frame write shows up here as a differing string.
        $postedAt = DB::selectOne('SELECT posted_at FROM finance_ledger_transactions WHERE id = ?', [$first->id])->posted_at;
        $account = DB::selectOne(
            'SELECT created_at, updated_at FROM finance_student_accounts WHERE school_id = ? AND student_id = ?',
            [$school->id, $student->id],
        );

        expect(strtotime($account->updated_at) - strtotime($postedAt))->toBe(0)
            ->and($account->updated_at)->toBe($postedAt)   // byte-identical, not merely the same second
            ->and($account->created_at)->toBe($postedAt)
            // PINNED TO THE APPLICATION'S INSTANT, not merely to each other. Every assertion above
            // compares the columns among themselves, and a UNIFORM conversion satisfies all of them:
            // binding `$postedAt->setTimezone('+05:30')` keeps the four byte-identical and the gap
            // below exact while last_activity reads 19,800s into the future again — the shipped
            // defect restored with the guard passing. MySQL renders a TIMESTAMP back into the
            // session zone it parsed in, so this string is what PHP bound; app.timezone is UTC, so
            // strtotime() reads it as the true instant. Under that mutation it fails by ≈19,800.
            ->and(abs(strtotime($postedAt) - now()->getTimestamp()))->toBeLessThan(5);

        // THE ON DUPLICATE KEY BRANCH, which is the one that moves the field the bursar sees:
        // updated_at must follow the SECOND post's instant, and created_at must not move.
        //
        // THE CLOCK IS ADVANCED, AND WITHOUT THAT THIS BLOCK PROVES NOTHING. Both posts otherwise
        // run inside the same wall-clock second, so `$secondPostedAt` is the same string as
        // `$postedAt` and all three expectations below are satisfied by one frozen value — leaving
        // the plausible "the ODKU only needs to move the balance" simplification (deleting
        // `updated_at = VALUES(updated_at)`) GREEN while last_activity freezes at the account's
        // first movement forever. This is NOT the same-instant flakiness refused above: the
        // difference is IMPOSED by the test clock, not raced for, so it is deterministic on any
        // machine. Ordering matters — the SQL-vs-PHP frame check above must run BEFORE this, since
        // MySQL's NOW() does not follow Laravel's test clock. travelBack() in the finally, for the
        // same reason the session zone is restored there: a leaked offset is cross-test poison.
        test()->travel(90)->seconds();

        $second = ActiveSchool::runFor($school->id, fn () => $poster->post(
            $school->id, $student->id, LedgerEntryType::Payment, Money::fromKobo(-4_000),
            'allocation', 1, 'Clock-frame arm — the UPDATE branch', SchoolDay::today(),
        ));

        $secondPostedAt = DB::selectOne('SELECT posted_at FROM finance_ledger_transactions WHERE id = ?', [$second->id])->posted_at;
        $account = DB::selectOne(
            'SELECT created_at, updated_at FROM finance_student_accounts WHERE school_id = ? AND student_id = ?',
            [$school->id, $student->id],
        );

        // The two instants are now 90s apart, so each of these is a real assertion: updated_at
        // FOLLOWED the second post, and created_at did NOT move (the row was updated, not recreated).
        expect(strtotime($secondPostedAt) - strtotime($postedAt))->toBe(90)   // the imposed gap actually landed
            ->and(strtotime($account->updated_at) - strtotime($secondPostedAt))->toBe(0)
            ->and($account->updated_at)->toBe($secondPostedAt)
            ->and($account->created_at)->toBe($postedAt)   // the INSERT's instant, untouched by the update
            // Pinned here TOO, and not only at the top: this is the branch that feeds the screen,
            // and a single pin above would leave it unconverted-only-by-assumption. now() is
            // travelled with the post, so the comparison holds on both sides of the 90s.
            ->and(abs(strtotime($secondPostedAt) - now()->getTimestamp()))->toBeLessThan(5);

        // MONOTONICITY. The bound stamp is captured at the top of post(), BEFORE the row lock,
        // where MySQL's NOW() was evaluated after it — so two posts racing one account can reach
        // the upsert in the opposite order to their capture and a plain assignment would move
        // updated_at BACKWARDS. GREATEST() is what prevents that.
        //
        // The race itself has no deterministic red, so this arm does not stage one. It stages the
        // PROPERTY instead: travel backwards, post again, and the third post binds an instant
        // EARLIER than the row's current updated_at — exactly the value a losing racer would carry.
        // updated_at must not follow it down. Deterministic on any machine, and it goes red the
        // moment GREATEST is reduced to a plain assignment.
        test()->travel(-150)->seconds();   // 60s BEFORE the first post, from the +90 position

        $third = ActiveSchool::runFor($school->id, fn () => $poster->post(
            $school->id, $student->id, LedgerEntryType::Payment, Money::fromKobo(-1_000),
            'allocation', 2, 'Clock-frame arm — a post binding an EARLIER instant', SchoolDay::today(),
        ));

        $thirdPostedAt = DB::selectOne('SELECT posted_at FROM finance_ledger_transactions WHERE id = ?', [$third->id])->posted_at;
        $account = DB::selectOne(
            'SELECT created_at, updated_at, balance_minor FROM finance_student_accounts WHERE school_id = ? AND student_id = ?',
            [$school->id, $student->id],
        );

        expect(strtotime($thirdPostedAt) - strtotime($secondPostedAt))->toBe(-150)  // it really did bind earlier
            ->and($account->updated_at)->toBe($secondPostedAt)   // …and updated_at did NOT move down
            ->and($account->created_at)->toBe($postedAt)         // still the INSERT's instant
            ->and((int) $account->balance_minor)->toBe(5_000);   // 10000 − 4000 − 1000: the balance still moved
    });
});

it('recovers an account row whose updated_at is NULL, instead of latching it there forever', function () {
    // GREATEST returns NULL if ANY argument is NULL, so `updated_at = GREATEST(updated_at, …)` alone
    // would set a NULL column to NULL on every subsequent post — permanently, where the MySQL NOW()
    // it replaced self-healed such a row on the first write. That is a worse failure shape than the
    // monotonicity bug GREATEST was added for, so the clause is wrapped in COALESCE.
    //
    // THE STATE IS REACHABLE, which is why this can be armed at all: `$table->timestamps()` emits
    // both columns NULLABLE (information_schema: IS_NULLABLE = YES) and the table carries no
    // trigger. No path in app/ produces a NULL there today — this plants one directly, which is
    // exactly the shape a future hand-written INSERT into this table would take.
    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);

    DB::insert(
        'INSERT INTO finance_student_accounts
            (uuid, school_id, student_id, balance_minor, balance_currency, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, NULL)',
        [(string) Str::orderedUuid(), $school->id, $student->id, 0, 'NGN', now()->toDateTimeString()],
    );

    expect(DB::selectOne('SELECT updated_at FROM finance_student_accounts WHERE student_id = ?', [$student->id])->updated_at)
        ->toBeNull();   // the precondition is real, not assumed

    $row = ActiveSchool::runFor($school->id, fn () => app(SubledgerPoster::class)->post(
        $school->id, $student->id, LedgerEntryType::Charge, Money::fromKobo(2_500),
        'invoice', 3, 'Null-recovery arm', SchoolDay::today(),
    ));

    $account = DB::selectOne(
        'SELECT updated_at, balance_minor FROM finance_student_accounts WHERE student_id = ?',
        [$student->id],
    );
    $postedAt = DB::selectOne('SELECT posted_at FROM finance_ledger_transactions WHERE id = ?', [$row->id])->posted_at;

    expect($account->updated_at)->not->toBeNull()
        ->and($account->updated_at)->toBe($postedAt)          // recovered to THIS post's instant
        ->and((int) $account->balance_minor)->toBe(2_500);    // and the balance moved as normal
});
