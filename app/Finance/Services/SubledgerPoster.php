<?php

namespace App\Finance\Services;

use App\Finance\Enums\LedgerEntryType;
use App\Finance\Models\LedgerTransaction;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single writer of subledger rows (Engineering Invariant 7: one authoritative
 * entry point). Every charge, payment and reversal posts through here so the row
 * shape is defined once. Finance-private (arch: Services used only in App\Finance).
 *
 * It never opens its own transaction — it is always called INSIDE an Action's
 * transaction, so the ledger post commits atomically with the state change that
 * caused it (a charge can never exist without its invoice, nor a reversal without
 * its cancellation).
 *
 * IT ALSO MAINTAINS THE ACCOUNT PROJECTION. finance_student_accounts.balance_minor
 * is defined as SUM(signed ledger amount_minor) per (school, student); the only way
 * to keep that true CONTINUOUSLY is to move the balance by the SAME delta on the
 * SAME movements the ledger records — which is precisely every call here. Doing it
 * at the single writer (rather than in one Action) is what makes a CHARGE maintain
 * the balance as faithfully as a payment: GenerateInvoice/ApproveVoidRequest/RecordPayment
 * are untouched; they call post() as before and the projection follows for free.
 *
 * The maintenance is an atomic upsert-increment (`balance = balance + :delta`), NOT
 * an app-level read-modify-write: `col = col + delta` is skew-free at InnoDB without
 * any lock, and the ON DUPLICATE KEY resolves the create-or-increment race for a
 * student's first-ever movement in one statement (no get-or-create, no zero-row
 * drift). No account lock is needed here; the pessimistic lock arrives in W3, where
 * applying credit is a genuine read-modify-write of the balance.
 *
 * "SINGLE WRITER" MEANS THE POSTING PATH, not the whole table, and the distinction
 * matters to anyone auditing where finance_student_accounts' timestamps come from.
 * `App\Finance\Console\ReconcileAccounts:84` also writes the row — `$account->save()`
 * under `--fix`, repairing a drifted balance — and StudentAccount leaves $timestamps
 * at the default, so that path stamps updated_at too. It does so through Eloquent,
 * i.e. in the application's clock frame, which is the frame this class now writes in;
 * so the table is single-frame, by two writers rather than one.
 */
final class SubledgerPoster
{
    /**
     * @param  Carbon|\DateTimeInterface|string  $effectiveAt  The BUSINESS date
     *                                                         this entry belongs to — the period a statement or ageing report should count it in. It is a
     *                                                         REQUIRED argument with no default, deliberately: every caller knows which period its entry
     *                                                         belongs to, and a default would silently make that "today" for the callers that do not
     *                                                         (a back-dated receipt, a migrated opening balance, a reversal of a prior period). The
     *                                                         column is NOT NULL, the table is append-only, and a wrong effective date can never be
     *                                                         corrected — only offset by a further entry. Each call site states its reasoning.
     *
     *   posted_at is NOT a parameter: it is when this row was written, which is always now() by
     *   definition. A caller that could choose it could lie about when the system learned a fact,
     *   which is the one thing an audit trail must not permit.
     *
     *   ONE CLOCK FRAME. The instant is captured ONCE here and bound into BOTH writes — the ledger
     *   row's posted_at and the account projection's created_at/updated_at. It used to be captured
     *   twice, from two different clocks: PHP's now() for posted_at and MySQL's NOW() inside the
     *   upsert. Those clocks are not the same one. Laravel writes a UTC wall-clock string that MySQL
     *   parses in the SESSION zone, so a PHP-written column stores early by the session offset and
     *   reads back exact; NOW() is already in the session zone, so it stores the true instant and
     *   reads back AHEAD by that offset — the two paths fail in opposite directions. On production
     *   (session zone +05:30) finance_student_accounts.updated_at therefore read 19,800s into the
     *   future while the ledger row it was derived from read true, and updated_at is surfaced to
     *   staff as `last_activity` on the accounts index (FinanceAccountController:67). That 19,800s
     *   figure is **scaled from the dev reading, not measured on production** — the ZONE is measured
     *   (+05:30, re-confirmed 2026-08-12), the display error itself is not
     *   (docs/handoff/tickets/stored-epoch-offset.md, same words, so the two cannot drift apart).
     *   The defect does not depend on the size of the offset, only on its existence.
     *
     *   WHAT IS PROVEN AND WHAT IS MERELY STRUCTURAL, because the two are not the same claim.
     *   SubledgerClockFrameTest proves the property that was BROKEN: both writes land in ONE CLOCK
     *   FRAME, asserted under a session zone pinned to production's. It does NOT prove they are the
     *   same INSTANT — a second now() inside applyToAccount would still pass it whenever the two
     *   calls fall in the same second, and an arm that could catch that would present as
     *   intermittent red, i.e. as flake. Single-capture is instead structural: the instant is a
     *   local in post() and is passed down, so there is no second call to drift from. Do not read
     *   the arm as proof of it. Pinning the connection zone was considered and refused
     *   (docs/handoff/tickets/stored-epoch-offset.md); it re-renders every timestamp already
     *   written, all of them behind append-only triggers.
     *
     *   NOTHING ENFORCES THE RULE YET. "No MySQL clock function in raw SQL" is a rule on trust
     *   here: the gate that would hold it is docs/handoff/tickets/sql-clock-lint.md, not shipped.
     *   The arm below catches THIS method regressing; a clock read added anywhere else does not
     *   fail a build today.
     */
    public function post(
        int $schoolId,
        int $studentId,
        LedgerEntryType $type,
        Money $amount,
        string $sourceType,
        int $sourceId,
        string $narration,
        Carbon|\DateTimeInterface|string $effectiveAt,
    ): LedgerTransaction {
        // Captured ONCE, before the write, and bound into both — see the docblock's "one clock frame".
        $postedAt = now();

        $row = LedgerTransaction::create([
            'school_id' => $schoolId,
            'student_id' => $studentId,
            'type' => $type,
            'amount' => $amount,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'narration' => $narration,
            'posted_at' => $postedAt,
            'effective_at' => $effectiveAt,
        ]);

        $this->applyToAccount($schoolId, $studentId, $amount, $postedAt);

        return $row;
    }

    /**
     * Move the account balance by the signed delta of the ledger row just posted,
     * creating the row on a student's first-ever movement. One atomic statement:
     *
     *   - INSERT plants a new account seeded to :delta (the first movement's amount);
     *   - ON DUPLICATE KEY adds :delta to the existing balance, `col = col + delta`,
     *     which InnoDB applies to the current committed value under the row lock — so
     *     two concurrent posts to the same account both land, no read-modify-write
     *     skew and no app-level lock.
     *
     * DB::insert (not DB::table — the boundary lint forbids that escape hatch) with a
     * raw finance_ literal is legal HERE: this file is inside app/Finance, and the
     * finance-table-outside-finance rule only fires on the literal OUTSIDE app/Finance.
     * The write bypasses SchoolScope, so school_id is supplied explicitly.
     *
     * THE TIMESTAMPS ARE BOUND, NOT MySQL's. $postedAt is the instant post() captured for the
     * ledger row, formatted exactly the way Eloquent formats posted_at (the connection's
     * 'Y-m-d H:i:s') so the two columns are byte-identical rather than merely close. This is the
     * whole two-clock fix: these columns are now written by the application's clock like every
     * other timestamp in the schema, and are therefore read back in the same frame as the ledger
     * rows they project.
     *
     * THE BALANCE IS UNAFFECTED; THE TIMESTAMP CHANGED CHARACTER, and the difference is worth being
     * exact about. `balance_minor = balance_minor + VALUES(balance_minor)` is untouched, so the
     * skew-free atomic increment and the ON DUPLICATE KEY create-or-increment race resolution
     * behave exactly as before. updated_at does NOT: `NOW()` was evaluated by the server at
     * statement time, i.e. AFTER this statement takes the row lock, and was therefore monotonic by
     * construction. The bound value is captured at the top of post(), BEFORE the lock and before
     * everything the calling Action does in between — GenerateInvoice runs a lockForUpdate, a
     * uniqueness guard, Sequences::next and an Invoice::create in that window. Two posts racing the
     * same account can therefore reach the upsert in the opposite order to their capture, and a
     * plain assignment would let updated_at go BACKWARDS: last_activity showing a time before the
     * payment that just landed.
     *
     * GREATEST(updated_at, VALUES(updated_at)) is what restores monotonicity, and it is inert on
     * the INSERT path, which never reaches this clause.
     *
     * THE COALESCE IS NOT NOISE. `$table->timestamps()` emits both columns NULLABLE — verified
     * against information_schema, `IS_NULLABLE: YES`, and FinanceAccountController:67 already reads
     * `updated_at?->` in agreement with that. MySQL's GREATEST returns NULL if ANY argument is
     * NULL, so a row whose updated_at is NULL would have it set to NULL by the next post, and by
     * every post after that: a PERMANENT latch, where the `NOW()` this replaced self-healed such a
     * row on the first write. No path produces a NULL here today (the upsert binds both columns,
     * the migration backfill stamps both, ReconcileAccounts writes through Eloquent) — the COALESCE
     * exists so that a future hand-written INSERT into this table cannot turn a recoverable state
     * into an unrecoverable one.
     *
     * VALUES() reads the value already bound for the INSERT rather than taking a third
     * binding, and mirrors the `balance_minor + VALUES(balance_minor)` idiom above. (VALUES() in ON
     * DUPLICATE KEY UPDATE is deprecated in MySQL 8.0.20+ in favour of row aliases; this
     * statement's other clause already uses it and consistency inside one statement beats a
     * half-migration. Changing the aliasing style is a separate change.)
     */
    private function applyToAccount(int $schoolId, int $studentId, Money $amount, CarbonInterface $postedAt): void
    {
        // Currency invariant — the layer that catches EVERY future caller, not just today's two Actions.
        // ON DUPLICATE KEY below adds VALUES(balance_minor) into the existing balance_minor but does NOT
        // touch balance_currency, so a mismatched currency would silently add e.g. USD kobo into an NGN
        // balance and leave the label reading NGN. Refuse it before the write. A LogicException (not a
        // BusinessRuleException) deliberately: both payment Actions now guard currency at the edge, so
        // reaching here with a mismatch is a PROGRAMMING error by a new caller — a 500 is the right,
        // loud answer, the same role Money's own constructor plays. DB::selectOne (not DB::table — the
        // boundary lint forbids that on a finance_ literal) reads the current label under this Action's tx.
        $existing = DB::selectOne(
            'SELECT balance_currency FROM finance_student_accounts WHERE school_id = ? AND student_id = ?',
            [$schoolId, $studentId],
        );
        if ($existing !== null && $existing->balance_currency !== $amount->currency) {
            throw new \LogicException(
                "Ledger currency {$amount->currency} does not match account balance currency {$existing->balance_currency} for student {$studentId}."
            );
        }

        // Formatted the way Eloquent formats posted_at (Model::fromDateTime → the connection
        // grammar's 'Y-m-d H:i:s'), so the two columns carry the identical string.
        $stamp = $postedAt->toDateTimeString();

        DB::insert(
            'INSERT INTO finance_student_accounts
                (uuid, school_id, student_id, balance_minor, balance_currency, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                balance_minor = balance_minor + VALUES(balance_minor),
                updated_at = COALESCE(GREATEST(updated_at, VALUES(updated_at)), VALUES(updated_at))',
            [
                (string) Str::orderedUuid(),
                $schoolId,
                $studentId,
                $amount->toKobo(),      // signed: charge +, payment/reversal −
                $amount->currency,
                $stamp,                 // created_at — the instant post() captured
                $stamp,                 // updated_at — the same one, read by VALUES() below
            ],
        );
    }
}
