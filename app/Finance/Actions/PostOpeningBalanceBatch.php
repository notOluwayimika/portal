<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Enums\LedgerEntryType;
use App\Finance\Enums\OpeningBalanceBatchStatus;
use App\Finance\Enums\OpeningBalanceRowStatus;
use App\Finance\Models\OpeningBalanceBatch;
use App\Finance\Models\OpeningBalanceRow;
use App\Finance\Models\Payment;
use App\Finance\Services\SubledgerPoster;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * §9 step 4b — post one validated opening-balance batch into the subledger (spec §3).
 *
 * THE NAME DOES NOT BEGIN WITH `Submit`, and that is load-bearing rather than taste:
 * bin/ci-boundary-lint.php's approval-seam-count rule enumerates app/Finance/Actions/Submit*.php from
 * the filesystem and requires exactly one such file per finance `*_SUBMIT` Permission case. This
 * commit adds no Permission case, so a `Submit…` filename here would fail that lint immediately. It
 * is also the honest name: this Action does not submit anything for approval — §8's gate is 4c, and
 * when it lands, posting happens ON approval and this is what the approval calls.
 *
 * NOTHING CALLS IT YET, DELIBERATELY. `finance:import-opening-balances` refuses any non-dry run
 * before it reads a byte, naming 4c — following commit 1's own precedent and its reasoning: "there is
 * no posting path to reach, and a run that got as far as opening a file before refusing would suggest
 * there is". A flag nobody is supposed to use is weaker than no flag. The Action is exercised by
 * tests only until 4c ships the gate.
 *
 * ── WHAT IT WRITES (§3, and NOTHING else) ──
 *
 * Step 1 — every POSITIVE fee-type balance becomes ONE ledger charge, through SubledgerPoster::post:
 *   type = charge, amount = that row's balance, source_type = 'opening_balance_row',
 *   source_id = the staged row id, narration = "<fee_type_label verbatim> — Balance Brought Forward".
 * The label is carried VERBATIM (R7): it is a string snapshot, never resolved onto a fee_item_id,
 * because the opening balance comes from a CLOSED term whose schedule the portal may not hold.
 *
 * Step 2 — every NEGATIVE balance becomes part of ONE NETTED migrated payment PER STUDENT, plus its
 * ledger `payment` credit. NOT one per fee type: "a payment belongs to the STUDENT ACCOUNT (school +
 * student), not to an invoice" (2026_07_19_100002_create_fee_payments_tables.php:11), so one payment
 * per fee type would assert an attribution the table cannot hold. The per-fee-type breakdown of the
 * credit stays on the staged rows, which is where it is auditable.
 *
 * WHY A PAYMENT ROW AND NOT A BARE LEDGER CREDIT — this is the whole reason step 2 is shaped this
 * way, and it was checked against the code rather than assumed. GenerateInvoice reads carry-forward
 * credit from the ACCOUNT BALANCE (:193) but sources the ALLOCATION from Payment rows ordered by id
 * (:386-396), drawing each payment's unallocated remainder in turn (:398-421); its own comment admits
 * a balance-only credit leaves the leftover unallocated (:425-428). A bare ledger credit therefore
 * nets the balance correctly and leaves the next invoice reading FULLY OUTSTANDING — right total,
 * wrong statement. A migrated payment row is inserted before any portal payment exists, so it sorts
 * first under orderBy('id') and is drawn first.
 *
 * Step 3 — nothing else. NO INVOICE (R6, three reasons each sufficient: the import cannot choose the
 * enrollment episode; if the portal has rolled over, that episode is the one native billing must use
 * and UNIQUE (school_id, active_enrollment_key) would refuse it; and R4 forbids the portal
 * ORIGINATING a document WCBS already issued). No re-bill.
 *
 * ── THE RESERVED BAND, AND WHY THIS IS NOT `Sequences` ──
 *
 * `reference` is the school-scoped receipt sequence under UNIQUE (school_id, reference). A migrated
 * row takes it from the reserved high band (>= Payment::MIGRATED_REFERENCE_FLOOR), allocated HERE,
 * inside this transaction, from MAX(reference) within that band for that school.
 *
 * IT MUST NEVER CALL Sequences::next('finance_payment', …), and the reason cuts both ways:
 *   - the counter starts at 0 for a school that has taken no portal payment (both live call sites
 *     pass NO seed closure, deliberately), so a migrated row would be handed reference 1 — a receipt
 *     number indistinguishable from a real one, on a table that is append-only and can never be
 *     renumbered; and
 *   - it would ADVANCE the live counter, so the school's next real receipt would skip numbers it
 *     never issued.
 * This is the seed trap (§4, Payment::MIGRATED_REFERENCE_FLOOR's docblock,
 * 2026_08_07_110000_add_provenance_to_finance_payments.php) approached from the other side: that
 * docblock warns a THIRD call site on this scope would be pinned by neither existing seed case and
 * must arrive with its own. This Action is that third site and it resolves it by NOT BEING ONE — it
 * touches no sequence at all, so there is no counter for a future "hardening" to seed.
 *
 * The allocation is a read-then-write and is NOT serialised by a lock. It does not need to be:
 * UNIQUE (school_id, reference) is the backstop that makes a collision a loud 1062 rather than a
 * silent duplicate, and G1 already permits at most one posted batch per school, so two concurrent
 * posts for one school cannot both survive to commit anyway.
 *
 * ── §4's OPEN DECISION ON `external_reference`, RULED HERE ──
 *
 * §4 leaves it owed by commit 4: is `(school_id, external_reference)` unique where non-null?
 * THE RULING IS NO — DUPLICATES ARE LEGAL, and the reason is that the scenario §4 named as the risk
 * has since been closed by a stronger guard in this same commit. §4's worry was that "two batches
 * filed under different batch references and carrying the same WCBS receipt would post that receipt
 * twice and the database would have no opinion". G1 means a school has at most ONE posted batch, ever,
 * and G1b means that batch can never be un-posted or deleted to make room for another — so there is no
 * second posting run for a duplicate to arrive on. Adding a unique index would therefore constrain
 * nothing that G1 does not already make unreachable, while making a legitimate WCBS extract that
 * repeats a bill reference across two students abort a cutover mid-post. `external_reference` is a
 * provenance breadcrumb back to the source system, not an idempotency key; idempotency is at the
 * batch (`ob_batches_school_reference_unique`) and now at the post (G1).
 */
final class PostOpeningBalanceBatch
{
    /**
     * The width of every snapshot column this Action writes into — `finance_ledger_transactions`.`narration`
     * and `finance_payments`.`payer_name` are both `varchar(255)`, counted in CHARACTERS by MySQL on a
     * utf8mb4 column, and `sql_mode` carries STRICT_TRANS_TABLES so an over-length value is error 1406,
     * not a silent truncation.
     */
    public const SNAPSHOT_COLUMN_MAX = 255;

    /**
     * THE THREE STRING PARTS THIS ACTION DERIVES, EXPOSED SO THE VALIDATOR CAN SIZE ITS INPUTS AGAINST
     * THEM. Nothing here is truncated, ever: R7 carries the fee-type label VERBATIM into the narration
     * because it is what a parent reads on their statement, and a silently shortened fee type is worse
     * than a refused file. The refusal therefore belongs at the validator, where a file problem belongs
     * (4a's own lesson) — so these are `public` and `ImportOpeningBalances` derives its length rules
     * from them. Edit a literal here and the limit that guards it must move with it; the arithmetic is
     * asserted in OpeningBalancePostingTest so an edit that forgets cannot land.
     */
    public const NARRATION_SUFFIX = ' — Balance Brought Forward';

    public const PAYER_NAME_PREFIX = 'Balance brought forward (WCBS batch ';

    public const PAYER_NAME_SUFFIX = ')';

    public function __construct(private readonly SubledgerPoster $ledger) {}

    /**
     * @return OpeningBalanceBatch the posted batch, re-read under the lock
     */
    public function handle(OpeningBalanceBatch $batch, User $actor): OpeningBalanceBatch
    {
        // Rule 13: a financial write with no context fails closed rather than inferring one. Every
        // read below goes through SchoolScope, so a mismatched context would silently read an empty
        // row set and post nothing — a successful-looking no-op. Refuse instead.
        $schoolId = ActiveSchool::id();
        if ($schoolId === null) {
            throw new BusinessRuleException('No active School context: an opening-balance batch cannot be posted.');
        }
        if ((int) $batch->school_id !== $schoolId) {
            throw new BusinessRuleException('That opening-balance batch belongs to another School.');
        }

        return DB::transaction(function () use ($batch, $actor, $schoolId) {
            // Re-read UNDER LOCK. The status is the whole authorisation for this write, and reading it
            // off the passed model would decide on a value fetched before the transaction opened.
            $locked = OpeningBalanceBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== OpeningBalanceBatchStatus::Validated) {
                throw new BusinessRuleException(
                    "Only a validated opening-balance batch can be posted; this one is {$locked->status->value}."
                );
            }

            // Ok rows only. In a validated batch every row IS ok by construction — `validated` means no
            // rejected row and no batch-level finding — so this filter should never remove anything.
            // It is here because "should never" is not a guarantee, and posting a rejected row is the
            // one mistake this Action cannot undo.
            $rows = OpeningBalanceRow::query()
                ->where('batch_id', $locked->id)
                ->where('status', OpeningBalanceRowStatus::Ok)
                ->orderBy('id')
                ->get();

            /** @var array<int, Money> $creditByStudent */
            $creditByStudent = [];
            /** @var array<int, string|null> $billReferenceByStudent */
            $billReferenceByStudent = [];

            foreach ($rows as $row) {
                $balance = $row->balance;
                $studentId = $row->student_id;

                // Both are structurally guaranteed on an `ok` row (a blank balance and an unresolved
                // admission number are each a named rejection), so reaching either of these is a
                // PROGRAMMING error upstream, not an operator one — a loud 500, the same role
                // SubledgerPoster's currency LogicException plays.
                if ($balance === null || $studentId === null) {
                    throw new \LogicException(
                        "Opening balance row {$row->id} is marked ok but carries no balance or no resolved student."
                    );
                }

                if ($balance->isZero()) {
                    // A stated zero is a real statement — "this student owes nothing on this fee type" —
                    // and it is kept on the staged row. It moves no money, so it posts nothing: a zero
                    // ledger row would be a movement that did not happen.
                    continue;
                }

                if (! $balance->isNegative()) {
                    // Step 1 — one charge per positive fee-type line. Sourced to the STAGED ROW, which
                    // is what makes the per-fee-type breakdown auditable and what §12's export
                    // exclusion predicate (source_type = 'opening_balance_row') selects on.
                    $this->ledger->post(
                        $schoolId,
                        $studentId,
                        LedgerEntryType::Charge,
                        $balance,
                        'opening_balance_row',
                        (int) $row->id,
                        $row->fee_type_label.self::NARRATION_SUFFIX,
                    );

                    continue;
                }

                // Step 2, accumulation — net this student's credit lines. Money::plus throws on a
                // currency mismatch, so a batch mixing currencies within one student aborts the whole
                // transaction rather than netting figures that are not comparable.
                $credit = $balance->times(-1);
                $creditByStudent[$studentId] = isset($creditByStudent[$studentId])
                    ? $creditByStudent[$studentId]->plus($credit)
                    : $credit;

                // The FIRST non-null bill reference among the student's credit lines. The netted
                // payment is one row and the file may carry several references for it, so no single
                // value can be complete — this is a breadcrumb back to WCBS, and the full per-line set
                // stays on the staged rows where it is not lossy. Null when the file gave none.
                if (($billReferenceByStudent[$studentId] ?? null) === null) {
                    $billReferenceByStudent[$studentId] = $row->wcbs_bill_reference;
                }
            }

            $reference = $this->nextMigratedReference($schoolId);

            foreach ($creditByStudent as $studentId => $credit) {
                $payment = Payment::create([
                    'school_id' => $schoolId,
                    'student_id' => $studentId,
                    'reference' => $reference,
                    'amount' => $credit,
                    // A snapshot of who paid, and the honest answer is that this system does not know:
                    // the cash arrived at WCBS. The batch reference is what a bursar can actually trace,
                    // so that is what is snapshotted rather than a name nobody supplied.
                    'payer_name' => self::PAYER_NAME_PREFIX.$locked->batch_reference.self::PAYER_NAME_SUFFIX,
                    // `method` is a snapshot label with no enum and no CHECK behind it (§4 states that
                    // limit); `origin` is THE predicate, and it has a CHECK. Every path that needs to
                    // know where money came from filters on origin.
                    'method' => 'migrated',
                    'origin' => 'migrated',
                    'external_reference' => $billReferenceByStudent[$studentId] ?? null,
                    // NULL, and not an oversight: nobody in this system received this money. The
                    // column is "who took the payment at the counter", and attributing it to the
                    // person who ran the post would assert a cash handover that never happened. WHO
                    // posted is recorded where it belongs, on the batch (posted_by_user_id).
                    'received_by_user_id' => null,
                ]);

                // The crediting ledger row. BYTE-IDENTICAL in vocabulary to both live payment paths —
                // LedgerEntryType::Payment, source_type 'payment', sourced to the payment — so
                // finance:audit-ledger-coherence needs no new case for it (ADR 0047, assertion I1/I2).
                //
                // THE BATCH REFERENCE IS DELIBERATELY NOT IN THIS STRING, and leaving it out is what
                // makes the validator's batch-reference limit a single number. Both derived strings
                // embed operator input, and a narration carrying the reference AS WELL would have been
                // the tighter of the two constraints (its fixed parts are 49 characters against
                // payer_name's 37), so the limit would have had to be the MIN of the two — a second
                // arithmetic nobody would maintain. This narration's parts are all bounded by the
                // Action itself, so it cannot overflow whatever the operator types; the batch
                // reference is on the payment row's own payer_name, one column away.
                $this->ledger->post(
                    $schoolId,
                    $studentId,
                    LedgerEntryType::Payment,
                    $credit->times(-1),
                    'payment',
                    (int) $payment->getKey(),
                    'Payment #'.$reference.self::NARRATION_SUFFIX,
                );

                $reference++;
            }

            // The transition. G1's generated key fires on THIS statement: a second batch reaching
            // `posted` for the same school is refused 1062 by ob_batches_posted_school_unique, and
            // from here the two G1b triggers deny every way back out.
            $locked->update([
                'status' => OpeningBalanceBatchStatus::Posted,
                'posted_at' => now(),
                'posted_by_user_id' => $actor->id,
            ]);

            return $locked;
        });
    }

    /**
     * The first free reference in the reserved migrated band for this school.
     *
     * MAX within the band, floored at Payment::MIGRATED_REFERENCE_FLOOR — so the first migrated
     * receipt a school ever gets is FLOOR + 1, and a school that somehow already holds migrated rows
     * continues above them rather than colliding. The band filter is not decoration: without it, MAX
     * would read a school's portal receipts and, for a school with none, return null — landing
     * migrated rows at 1.
     *
     * Read through the model, not DB::table — the boundary lint forbids that escape hatch inside
     * app/Finance. Payment is SchoolScope-bound and the explicit school_id is the belt to that scope.
     */
    private function nextMigratedReference(int $schoolId): int
    {
        $highest = (int) Payment::query()
            ->where('school_id', $schoolId)
            ->where('reference', '>=', Payment::MIGRATED_REFERENCE_FLOOR)
            ->max('reference');

        return max($highest, Payment::MIGRATED_REFERENCE_FLOOR) + 1;
    }
}
