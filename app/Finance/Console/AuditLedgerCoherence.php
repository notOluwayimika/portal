<?php

namespace App\Finance\Console;

use App\Finance\Enums\LedgerEntryType;
use App\Models\School;
use App\Support\ActiveSchool;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The document↔ledger COHERENCE detector (ADR 0047; ships as a thin vertical slice per ADR 0046).
 * Its sibling {@see ReconcileAccounts} verifies exactly one thing —
 *
 *     finance_student_accounts.balance_minor  ?=  SUM(signed ledger amount_minor)
 *
 * — treating the ledger as truth. That is correct and load-bearing, but it means NOTHING checks
 * the ledger against the DOCUMENTS that produced it. If a void posted two reversals, or a reversal
 * whose amount did not match the charge, or an invoice were flipped to void by a path that posted
 * no reversal at all, reconcile-accounts would report NO drift — the projection would faithfully
 * mirror the wrong ledger, and both would agree with each other while being wrong about the
 * documents. Today that boundary is guarded only by the control flow of four Actions
 * (ApproveVoidRequest even says so in its own comment). This command is the ledger-level check
 * that convention lacked.
 *
 * ── WHY DETECT-ONLY (there is no --fix, and that is the finding, not a limitation) ──
 * reconcile-accounts can offer --fix because it has a KNOWN-RIGHT side: the ledger is definitionally
 * truth, the balance a projection, repair is mechanical. This detector has no such side. A void
 * invoice with no reversal has two equally consistent stories — a reversal that should have posted
 * and did not, or a status that should never have flipped — and repairing either way writes
 * money-affecting data on a guess (forgive a real debt, or re-charge a cancelled invoice). Worse, it
 * CANNOT repair the ledger even if it wanted to: finance_ledger_transactions is append-only, enforced
 * by the named no_update / no_delete triggers (Constitution §15C), so a --fix could only touch the
 * document side — the side it is least sure is wrong. So: report, exit FAILURE, let a human decide.
 * --dry-run is meaningless for a detector that never writes; there is none.
 *
 * ── PLACEMENT (a sibling, not a flag on reconcile-accounts) ──
 * The two commands have DIFFERENT truth models and therefore different repair semantics; merging
 * them yields one --fix that repairs one class of finding and refuses another — a footgun with money
 * behind it. Everything else follows the reconcile-accounts pattern: lives in App\Finance\Console
 * (touches Finance tables, arch-private); registered in bootstrap/app.php via ->withCommands;
 * scheduled daily in routes/console.php; §5.4 — reads School-owned data, so iterates Schools
 * EXPLICITLY via ActiveSchool::runFor.
 *
 * ── QUERY STRATEGY ──
 * Driven from the DOCUMENT side (invoices / credit notes with correlated subqueries over the
 * ledger), which uses the existing (school_id, student_id) index and adds nothing to the hot write
 * path — there is deliberately no index on (source_type, source_id). Reads use raw DB::select with
 * an EXPLICIT school_id: raw is required (a corrupt `type` cannot be loaded through the model's enum
 * cast — it would throw before we could report it), and it is the sanctioned in-module escape — the
 * boundary lint forbids DB::table on a finance_ table even inside app/Finance, so this mirrors
 * SubledgerPoster's raw DB::insert. Because raw SQL bypasses SchoolScope, school_id is supplied by
 * hand on every query.
 *
 * Vocabulary columns (`type`, `source_type`) are compared with BINARY (byte-exact). MySQL's default
 * collation is case- and trailing-space-INsensitive, so a plain `type IN ('charge', …)` would treat
 * 'CHARGE' / 'charge ' as equal to 'charge' and miss them — the very values that break the exact PHP
 * enum cast (LedgerEntryType::from). BINARY catches them, and keeps a corrupt row from masquerading
 * as a valid movement in the aggregate checks (it is I1's finding alone).
 *
 * Each assertion is its own method so a bite-proof can disable EXACTLY one and watch only its
 * findings disappear:
 *   I1 checkTypeVocabulary            — every row's `type` is a known LedgerEntryType
 *   I2 checkSourceIntegrity           — `source_type` known + referenced row exists in-school
 *   I3 checkIssuedHasNoReversal       — an issued invoice has NO reversal
 *   I4 checkVoidHasOneMatchingReversal— a void invoice has EXACTLY ONE reversal = −Σcharges
 *   I5 checkCreditNotePostingMatchesStatus — approved ⇒ one posting = −amount; else none
 *   I6 checkChargeMatchesTotal        — exactly one charge row = invoice total (R3: single-charge)
 *   I7 checkCurrencyCoherence         — every row's amount_currency = its source's & account's
 */
class AuditLedgerCoherence extends Command
{
    protected $signature = 'finance:audit-ledger-coherence';

    protected $description = 'READ-ONLY: verify the subledger is coherent with the documents that produced it; exit non-zero on any incoherence (ADR 0047)';

    /** The three source_type values → the table each points at. `allocation` is absent by design (R4). */
    private const SOURCE_TABLES = [
        'invoice' => 'finance_invoices',
        'payment' => 'finance_payments',
        'credit_note' => 'finance_credit_notes',
    ];

    /** @var list<array{code: string, school_id: int, doc_type: string, doc_id: int, detail: string}> */
    private array $findings = [];

    public function handle(): int
    {
        $checked = 0;

        foreach (School::query()->get() as $school) {
            ActiveSchool::runFor($school->id, function () use ($school, &$checked) {
                $checked++;
                $this->checkTypeVocabulary($school->id);                 // I1
                $this->checkSourceIntegrity($school->id);                // I2
                $this->checkIssuedHasNoReversal($school->id);            // I3
                $this->checkVoidHasOneMatchingReversal($school->id);     // I4
                $this->checkCreditNotePostingMatchesStatus($school->id); // I5
                $this->checkChargeMatchesTotal($school->id);             // I6
                $this->checkCurrencyCoherence($school->id);              // I7
            });
        }

        if ($this->findings === []) {
            $this->info("Ledger coherence: {$checked} school(s) checked, no incoherence.");

            return self::SUCCESS;
        }

        foreach ($this->findings as $f) {
            $this->error(sprintf(
                '[%s] school=%d %s#%d: %s',
                $f['code'], $f['school_id'], $f['doc_type'], $f['doc_id'], $f['detail'],
            ));
        }

        $this->error(sprintf(
            '%d incoherence(s) across %d school(s). This is a document↔ledger mismatch — a human must '
            .'decide; there is no --fix (the ledger is append-only and the right side is unknowable).',
            count($this->findings), $checked,
        ));

        return self::FAILURE;
    }

    private function addFinding(string $code, int $schoolId, string $docType, int $docId, string $detail): void
    {
        $this->findings[] = [
            'code' => $code,
            'school_id' => $schoolId,
            'doc_type' => $docType,
            'doc_id' => $docId,
            'detail' => $detail,
        ];
    }

    /** I1 — every ledger row's `type` is one of the four LedgerEntryType cases (BINARY-exact; see class doc). */
    private function checkTypeVocabulary(int $schoolId): void
    {
        $valid = array_map(fn (LedgerEntryType $c) => $c->value, LedgerEntryType::cases());
        $placeholders = implode(',', array_fill(0, count($valid), '?'));

        $rows = DB::select(
            "SELECT id, type FROM finance_ledger_transactions
             WHERE school_id = ? AND BINARY type NOT IN ($placeholders)",
            [$schoolId, ...$valid],
        );

        foreach ($rows as $r) {
            $this->addFinding('I1', $schoolId, 'ledger', (int) $r->id,
                sprintf("type '%s' is not a known LedgerEntryType (expected one of: %s)", $r->type, implode(', ', $valid)));
        }
    }

    /** I2 — `source_type` is known (BINARY-exact), and the referenced row exists within the same school. */
    private function checkSourceIntegrity(int $schoolId): void
    {
        $known = array_keys(self::SOURCE_TABLES);
        $placeholders = implode(',', array_fill(0, count($known), '?'));

        $unknown = DB::select(
            "SELECT id, source_type FROM finance_ledger_transactions
             WHERE school_id = ? AND BINARY source_type NOT IN ($placeholders)",
            [$schoolId, ...$known],
        );
        foreach ($unknown as $r) {
            $this->addFinding('I2', $schoolId, 'ledger', (int) $r->id,
                sprintf("source_type '%s' is not a known document type", $r->source_type));
        }

        // The table names are internal constants, never user input — safe to interpolate.
        foreach (self::SOURCE_TABLES as $type => $table) {
            $dangling = DB::select(
                "SELECT l.id, l.source_id FROM finance_ledger_transactions l
                 WHERE l.school_id = ? AND BINARY l.source_type = ?
                   AND NOT EXISTS (SELECT 1 FROM {$table} d WHERE d.id = l.source_id AND d.school_id = ?)",
                [$schoolId, $type, $schoolId],
            );
            foreach ($dangling as $r) {
                $this->addFinding('I2', $schoolId, 'ledger', (int) $r->id,
                    sprintf("source_type '%s' references %s id %d, which does not exist in this school", $type, $table, $r->source_id));
            }
        }
    }

    /** I3 — an `issued` invoice has NO reversal rows (InvoiceStatus is issued|void). */
    private function checkIssuedHasNoReversal(int $schoolId): void
    {
        $rows = DB::select(
            "SELECT i.id, COUNT(l.id) AS n
             FROM finance_invoices i
             JOIN finance_ledger_transactions l
               ON l.source_id = i.id AND l.school_id = ?
              AND BINARY l.source_type = 'invoice' AND BINARY l.type = 'reversal'
             WHERE i.school_id = ? AND i.status = 'issued'
             GROUP BY i.id",
            [$schoolId, $schoolId],
        );

        foreach ($rows as $r) {
            $this->addFinding('I3', $schoolId, 'invoice', (int) $r->id,
                "issued invoice has {$r->n} reversal row(s), expected 0");
        }
    }

    /** I4 — a `void` invoice has EXACTLY ONE reversal, its amount = −Σ(charge rows). */
    private function checkVoidHasOneMatchingReversal(int $schoolId): void
    {
        $rows = DB::select(
            "SELECT i.id,
                (SELECT COUNT(*) FROM finance_ledger_transactions r
                   WHERE r.school_id = ? AND r.source_id = i.id
                     AND BINARY r.source_type = 'invoice' AND BINARY r.type = 'reversal') AS rev_count,
                (SELECT COALESCE(SUM(r2.amount_minor),0) FROM finance_ledger_transactions r2
                   WHERE r2.school_id = ? AND r2.source_id = i.id
                     AND BINARY r2.source_type = 'invoice' AND BINARY r2.type = 'reversal') AS rev_sum,
                (SELECT COALESCE(SUM(c.amount_minor),0) FROM finance_ledger_transactions c
                   WHERE c.school_id = ? AND c.source_id = i.id
                     AND BINARY c.source_type = 'invoice' AND BINARY c.type = 'charge') AS charge_sum
             FROM finance_invoices i
             WHERE i.school_id = ? AND i.status = 'void'",
            [$schoolId, $schoolId, $schoolId, $schoolId],
        );

        foreach ($rows as $r) {
            $revCount = (int) $r->rev_count;
            if ($revCount !== 1) {
                $this->addFinding('I4', $schoolId, 'invoice', (int) $r->id,
                    "void invoice has {$revCount} reversal row(s), expected exactly 1");

                continue;
            }

            $revSum = (int) $r->rev_sum;      // == the single reversal's amount when rev_count is 1
            $chargeSum = (int) $r->charge_sum;
            if ($revSum !== -$chargeSum) {
                $this->addFinding('I4', $schoolId, 'invoice', (int) $r->id,
                    sprintf('reversal amount %d does not negate the charge sum %d (expected %d)', $revSum, $chargeSum, -$chargeSum));
            }
        }
    }

    /** I5 — an approved credit note has one posting = −amount; a submitted/rejected one has none. */
    private function checkCreditNotePostingMatchesStatus(int $schoolId): void
    {
        $rows = DB::select(
            "SELECT cn.id, cn.status, cn.amount_minor,
                (SELECT COUNT(*) FROM finance_ledger_transactions p
                   WHERE p.school_id = ? AND p.source_id = cn.id
                     AND BINARY p.source_type = 'credit_note' AND BINARY p.type = 'credit_note') AS post_count,
                (SELECT COALESCE(SUM(p2.amount_minor),0) FROM finance_ledger_transactions p2
                   WHERE p2.school_id = ? AND p2.source_id = cn.id
                     AND BINARY p2.source_type = 'credit_note' AND BINARY p2.type = 'credit_note') AS post_sum
             FROM finance_credit_notes cn
             WHERE cn.school_id = ?",
            [$schoolId, $schoolId, $schoolId],
        );

        foreach ($rows as $cn) {
            $postCount = (int) $cn->post_count;

            if ($cn->status === 'approved') {
                if ($postCount !== 1) {
                    $this->addFinding('I5', $schoolId, 'credit_note', (int) $cn->id,
                        "approved credit note has {$postCount} posting(s), expected exactly 1");

                    continue;
                }
                $posted = (int) $cn->post_sum;   // == the single posting when post_count is 1
                $expected = -(int) $cn->amount_minor;
                if ($posted !== $expected) {
                    $this->addFinding('I5', $schoolId, 'credit_note', (int) $cn->id,
                        sprintf('posting %d does not equal −amount %d', $posted, $expected));
                }

                continue;
            }

            // submitted | rejected — money never moved.
            if ($postCount !== 0) {
                $this->addFinding('I5', $schoolId, 'credit_note', (int) $cn->id,
                    "{$cn->status} credit note has {$postCount} posting(s), expected 0");
            }
        }
    }

    /** I6 — every invoice has EXACTLY ONE charge row, equal to the invoice total (R3: single-charge today). */
    private function checkChargeMatchesTotal(int $schoolId): void
    {
        $rows = DB::select(
            "SELECT i.id, i.total_minor,
                (SELECT COUNT(*) FROM finance_ledger_transactions c
                   WHERE c.school_id = ? AND c.source_id = i.id
                     AND BINARY c.source_type = 'invoice' AND BINARY c.type = 'charge') AS charge_count,
                (SELECT COALESCE(SUM(c2.amount_minor),0) FROM finance_ledger_transactions c2
                   WHERE c2.school_id = ? AND c2.source_id = i.id
                     AND BINARY c2.source_type = 'invoice' AND BINARY c2.type = 'charge') AS charge_sum
             FROM finance_invoices i
             WHERE i.school_id = ?",
            [$schoolId, $schoolId, $schoolId],
        );

        foreach ($rows as $inv) {
            $chargeCount = (int) $inv->charge_count;
            if ($chargeCount !== 1) {
                $this->addFinding('I6', $schoolId, 'invoice', (int) $inv->id,
                    "invoice has {$chargeCount} charge row(s), expected exactly 1");

                continue;
            }

            $charge = (int) $inv->charge_sum;   // == the single charge when charge_count is 1
            if ($charge !== (int) $inv->total_minor) {
                $this->addFinding('I6', $schoolId, 'invoice', (int) $inv->id,
                    sprintf('charge amount %d does not equal invoice total %d', $charge, (int) $inv->total_minor));
            }
        }
    }

    /**
     * I7 — currency coherence: every ledger row's amount_currency matches BOTH its source document's
     * currency and its student account's currency. Its own method (not folded into I2) so it can be
     * bite-proofed independently; the joins drop rows whose source_type is unknown or dangling —
     * that is I2's finding, not a currency one.
     */
    private function checkCurrencyCoherence(int $schoolId): void
    {
        $currencyColumn = [
            'invoice' => ['finance_invoices', 'total_currency'],
            'payment' => ['finance_payments', 'amount_currency'],
            'credit_note' => ['finance_credit_notes', 'amount_currency'],
        ];

        foreach ($currencyColumn as $type => [$table, $column]) {
            $rows = DB::select(
                "SELECT l.id, l.amount_currency, d.{$column} AS doc_currency
                 FROM finance_ledger_transactions l
                 JOIN {$table} d ON d.id = l.source_id AND d.school_id = ?
                 WHERE l.school_id = ? AND BINARY l.source_type = ? AND l.amount_currency <> d.{$column}",
                [$schoolId, $schoolId, $type],
            );
            foreach ($rows as $r) {
                $this->addFinding('I7', $schoolId, 'ledger', (int) $r->id,
                    sprintf("amount_currency '%s' does not match %s currency '%s'", $r->amount_currency, $type, $r->doc_currency));
            }
        }

        $rows = DB::select(
            'SELECT l.id, l.amount_currency, a.balance_currency
             FROM finance_ledger_transactions l
             JOIN finance_student_accounts a ON a.student_id = l.student_id AND a.school_id = ?
             WHERE l.school_id = ? AND l.amount_currency <> a.balance_currency',
            [$schoolId, $schoolId],
        );
        foreach ($rows as $r) {
            $this->addFinding('I7', $schoolId, 'ledger', (int) $r->id,
                sprintf("amount_currency '%s' does not match account currency '%s'", $r->amount_currency, $r->balance_currency));
        }
    }
}
