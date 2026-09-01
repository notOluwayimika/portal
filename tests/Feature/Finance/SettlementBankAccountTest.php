<?php

use App\Exceptions\BusinessRuleException;
use App\Finance\Models\BankAccount;
use App\Finance\Services\SettlementBankAccount;
use App\Models\School;
use App\Support\ActiveSchool;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * `finance_school_settings.settlement_bank_account_id` — where a school's gateway money lands.
 *
 * Two claims, and they are enforced by different machinery, which is why they are tested apart:
 *
 *   - the RESOLVER refuses when nothing is configured, rather than guessing an account
 *     ({@see SettlementBankAccount});
 *   - the DATABASE refuses a settings row naming ANOTHER school's account, so a resolver that was
 *     wrong, bypassed, or replaced still cannot produce a cross-school destination.
 *
 * The second is the reason the foreign key is composite at all, and its arm plants the row with
 * `DB::table` rather than through the model — an application-level refusal would prove the
 * application, not the constraint, and the constraint is what has to hold when the application is
 * rewritten.
 */
uses(RefreshDatabase::class);

/** Insert a settings row directly, so the arms below exercise the CONSTRAINT and not the model. */
function plantSettings(int $schoolId, ?int $settlementBankAccountId): void
{
    DB::table('finance_school_settings')->insert([
        'school_id' => $schoolId,
        'invoice_number_prefix' => null,
        'settlement_bank_account_id' => $settlementBankAccountId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function settlementAccountFor(School $school): BankAccount
{
    return BankAccount::withoutGlobalScopes()->create([
        'school_id' => $school->id,
        'label' => 'Settlement',
        'bank_name' => 'Test Bank',
        'account_number' => 'SETTLE-'.$school->id,
    ]);
}

// ── (i) the configured id comes back ─────────────────────────────────────────────────────────────

it('returns the configured settlement bank account id', function () {
    $school = School::factory()->create();
    $account = settlementAccountFor($school);
    plantSettings($school->id, $account->id);

    $resolved = ActiveSchool::runFor(
        $school->id,
        fn () => app(SettlementBankAccount::class)->forSchool($school->id),
    );

    expect($resolved)->toBe($account->id);
});

// ── (ii) unset REFUSES, and names the school by id ───────────────────────────────────────────────

it('refuses when no settlement account is configured, naming the school by id and never by name', function () {
    $school = School::factory()->create(['name' => 'Ravensbourne Grammar']);
    plantSettings($school->id, null);

    $resolve = fn () => ActiveSchool::runFor(
        $school->id,
        fn () => app(SettlementBankAccount::class)->forSchool($school->id),
    );

    expect($resolve)->toThrow(BusinessRuleException::class);

    try {
        $resolve();
        $message = null;
    } catch (BusinessRuleException $e) {
        $message = $e->getMessage();
    }

    // The id is the whole address an operator needs. The NAME assertion is the load-bearing half:
    // a school name in an error message is a cross-school leak into logs and API responses, and this
    // codebase has paid for that before. A message built from the model rather than the id would
    // satisfy the first expectation and fail this one.
    expect($message)->toContain('school#'.$school->id)
        ->and($message)->toContain('settlement bank account')
        ->and($message)->not->toContain('Ravensbourne');
});

it('refuses identically when the school has no settings row at all', function () {
    // "No row" and "row with a null column" are different database states and the same operator
    // situation. A resolver that handled only one of them would throw here and return null there,
    // and the null would reach RecordPayment's non-nullable int argument as a TypeError instead of
    // an answerable refusal.
    $school = School::factory()->create();

    $resolve = fn () => ActiveSchool::runFor(
        $school->id,
        fn () => app(SettlementBankAccount::class)->forSchool($school->id),
    );

    expect($resolve)->toThrow(BusinessRuleException::class, 'school#'.$school->id);
});

// ── (iii) the DATABASE refuses another school's account ──────────────────────────────────────────

it('COMPOSITE FK — the database refuses a settings row naming another school\'s bank account', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    $foreignAccount = settlementAccountFor($schoolB);

    // School A's settings row, pointing at School B's account. Planted directly: no model, no scope,
    // no validation — this is the write a bug, a console command or a rewritten service could make.
    $cross = fn () => plantSettings($schoolA->id, $foreignAccount->id);

    expect($cross)->toThrow(QueryException::class);

    try {
        $cross();
    } catch (QueryException $e) {
        // 1452 specifically. A bare QueryException expectation would also be satisfied by a typo in
        // a column name or a NOT NULL omission, either of which would leave this arm green while
        // proving nothing about the composite key — the same trap `kind` set in
        // EnrollmentSchoolIntegrityTest.
        expect((int) $e->errorInfo[1])->toBe(1452);
    }

    expect(DB::table('finance_school_settings')->count())->toBe(0);
});

// ── (iv) the same-school account is accepted ─────────────────────────────────────────────────────

it('accepts a settlement account belonging to the same school', function () {
    // The positive arm is not a formality: without it, a constraint that refused EVERYTHING would
    // pass (iii) and read as correct. It is the mutation guard on the arm above.
    $school = School::factory()->create();
    $account = settlementAccountFor($school);

    plantSettings($school->id, $account->id);

    expect(DB::table('finance_school_settings')
        ->where('school_id', $school->id)
        ->value('settlement_bank_account_id'))->toBe($account->id);
});

// ── (v) the §4 survey, pinned: NOTHING WRITES THIS COLUMN YET ────────────────────────────────────

it('pins that no write path exists for the settlement account, so adding one is a decision and not a drift', function () {
    /*
     * THIS ARM WENT RED ON 2026-09-01 AND WAS REPLACED, WHICH IS WHAT IT WAS FOR.
     *
     * It used to assert that NOTHING in app/ or resources/js touched this column: the write path
     * did not exist, the column was set by hand in the cutover runbook, and the arm existed so that
     * "the next person to add a settings screen would wire this column alongside the prefix as a
     * matter of course" could not happen silently. Its own words: "when a write path lands this
     * goes RED, and whoever wrote it has to come here and replace this arm with the authorization
     * arm the new path deserves. That is the point — the red is the conversation, not an obstacle."
     *
     * The write path landed (feat/audited-bank-account-and-settlement-acts). This is that
     * conversation, recorded as the arm that replaces it, and it asserts two things.
     *
     * ONE — THE SET, NOT THE ABSENCE. An exact allow-list of the files that may name this column, so
     * a FIFTH one still reds. A count would not do: it cannot tell "these four" from "some other
     * four", and the swap is exactly the case that must not slip through.
     *
     * TWO — THE AUTHORIZATION CLAIM THE NEW PATH ACTUALLY MAKES, which is narrower than a
     * permission and is stated rather than implied. There is NO HTTP surface: no route, no
     * controller action, no FormRequest writes this column, so no permission decision has been made
     * and none is being smuggled in. The only writer is an Action reached from a console command,
     * whose authorization IS shell access, and which refuses to run without a named `--actor` it
     * then records (asserted in tests/Feature/Finance/AuditedMoneyDestinationTest.php). When an
     * HTTP surface does arrive it will red this arm again, and the permission it needs is the
     * conversation that red is for.
     */
    $mayNameIt = [
        // The Action that writes it, and the console command's only route to the column.
        'app/Finance/Actions/SetSettlementBankAccount.php',
        // Reads it to flag a deactivation that retires the account settlement still points at.
        'app/Finance/Http/Controllers/BankAccountController.php',
        // The model documents the column; the resolver READS it. Neither writes.
        'app/Finance/Models/SchoolFinanceSettings.php',
        'app/Finance/Services/SettlementBankAccount.php',
    ];

    $found = [];

    $directories = [__DIR__.'/../../../app', __DIR__.'/../../../resources/js'];

    foreach ($directories as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($files as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'ts', 'tsx'], true)) {
                continue;
            }

            $path = $file->getPathname();

            if (str_contains((string) file_get_contents($path), 'settlement_bank_account_id')) {
                $found[] = str_replace(__DIR__.'/../../../', '', $path);
            }
        }
    }

    sort($found);

    expect($found)->toBe($mayNameIt,
        'a file outside the allow-list names settlement_bank_account_id. If it WRITES the column, '
        .'the authorization it needs is a decision — come here and make it. If it only reads, add it '
        .'to the list and say why.');

    // No HTTP surface, asserted directly rather than inferred from the list above: a controller
    // could be added to the list by somebody reading it as bookkeeping. The BankAccountController
    // entry is a READ, and the assertion below is what keeps that true.
    $writesFromHttp = collect($found)
        ->filter(fn (string $p) => str_contains($p, '/Http/'))
        ->filter(function (string $p): bool {
            $body = (string) file_get_contents(__DIR__.'/../../../'.$p);

            // A write would name the column inside an update()/create()/fill() payload. The read
            // this file does have is a ->value('settlement_bank_account_id') on a query.
            return (bool) preg_match('/[\'"]settlement_bank_account_id[\'"]\s*=>/', $body);
        })
        ->values()->all();

    expect($writesFromHttp)->toBe([],
        'an HTTP path now WRITES the settlement account. That needs a permission and probably an '
        .'approval step (docs/handoff/tickets/settlement-account-change-has-no-approval-step.md).');
});
