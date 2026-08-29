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
     * The survey behind this arm: `finance_school_settings` has no controller, no FormRequest, no
     * route and no screen. `invoice_number_prefix` — the only other substantive column — is written
     * by nothing but tests, and app/Support/SchoolDay.php says so in its own words ("carries one
     * substantive column today and no screen to set it from"). The cutover runbook sets both by
     * hand.
     *
     * That absence is deliberate and it is not this commit's to close. But an absence recorded only
     * in a report is wallpaper: the next person to add a settings screen would wire this column
     * alongside the prefix as a matter of course, and a screen that sets where a school's money
     * lands needs an authorization decision that a prefix field does not.
     *
     * So the absence is enforced. When a write path lands this goes RED, and whoever wrote it has to
     * come here and replace this arm with the authorization arm the new path deserves. That is the
     * point — the red is the conversation, not an obstacle.
     */
    $offenders = [];

    $directories = [__DIR__.'/../../../app', __DIR__.'/../../../resources/js'];

    foreach ($directories as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($files as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'ts', 'tsx'], true)) {
                continue;
            }

            $path = $file->getPathname();

            // The resolver READS the column and the model documents it; neither writes it.
            if (str_ends_with($path, 'SettlementBankAccount.php') || str_ends_with($path, 'SchoolFinanceSettings.php')) {
                continue;
            }

            if (str_contains((string) file_get_contents($path), 'settlement_bank_account_id')) {
                $offenders[] = str_replace(__DIR__.'/../../../', '', $path);
            }
        }
    }

    expect($offenders)->toBe([]);
});
