<?php

namespace App\Finance\Console;

use App\Exceptions\BusinessRuleException;
use App\Finance\Enums\GatewayTransactionStatus;
use App\Finance\Models\GatewayTransaction;
use App\Finance\Models\Payment;
use App\Finance\Services\GatewayPendingWindow;
use App\Models\School;
use App\Support\ActiveSchool;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * §6 STEP 7 — the gateway discrepancy report. THE FOUR CLASSES OF §8.1, AND THE FRAME THAT PROVES
 * THEY LOOKED AT EVERYTHING.
 *
 * ── THE FRAME SHIPPED FIRST, AND EMPTY, AND THAT WAS THE POINT ─────────────────────────────────
 *
 * This file was built before §8 was available, with `detectors()` returning an empty list — and the
 * classes were NOT reconstructed from the data model, deliberately. §8 has since been read, and the
 * restraint paid: the derived guess had FIVE classes where §8 names four, and its D3 compared the
 * FEE against the provider's report. §8's D3 is *"which pairs disagree on amount"*. A detector built
 * from the guess would have passed, looked complete, and never once measured what D3 is for.
 *
 * A partition guessed from the schema is plausible by construction and wrong in whichever dimension
 * the schema cannot show. **A class comes from the spec or from whoever owns the decision — never
 * from the availability of data that would support one.** That rule has now been tested from both
 * directions: deriving D3's inputs from what the schema SUGGESTED, and (refused) inventing a
 * fifth class from what `bill_minor` newly PERMITTED. The second is harder to resist, because the
 * column is there and the check is obvious and nothing about it feels like invention.
 *
 * ── ONE QUESTION FOR §8'S OWNER, NAMED RATHER THAN FILLED ─────────────────────────────────────
 *
 * **A payment attached to a NON-SUCCESS transaction falls through all four classes.** D1 filters to
 * `success` and does not see it; D2 asks whether a transaction names the payment and one does; D3
 * only reads pairs it can reconcile; D4 excludes `failed`/`abandoned` as answered. Nothing reports
 * it.
 *
 * It is probably unreachable: `settle()` runs only after a successful verify, and the
 * compare-and-swap attaches the payment in that same act. But *"unreachable by construction"* is
 * exactly the claim this workstream has taken apart three times, and catching states that should not
 * happen is the whole job of this report.
 *
 * So: **are the four intended as exhaustive over the failure space, and is a payment against a
 * non-success transaction covered by one of them or deliberately out of scope?** That is §8's
 * owner's to answer. It is not filled here, because inventing a class from what the data would
 * support is the error §8 already corrected once.
 *
 * ── AND TWO READINGS OF D3, BOTH REFERRED BACK RATHER THAN ASSUMED ────────────────────────────
 *
 * 1. **The arithmetic.** §8's *"pairs disagree on amount"* is literally true of every healthy
 *    settlement, because the transaction carries the gross and the payment carries `gross − fee`
 *    under the parent-bears ruling. D3 therefore checks `amount − fee == payment.amount`. §8's
 *    wording predates that ruling and should be corrected at the source, not only here.
 * 2. **The widening.** A settled pair with NO fee recorded is filed under D3 as *cannot be
 *    reconciled*. That is not a disagreement about an amount — it is an inability to check one —
 *    and calling it D3 is a decision rather than a deduction.
 *
 * Both are named because the alternative is an honesty note that is itself wider than the artifact,
 * which is the one failure mode a report about discrepancies can least afford.
 *
 * ── AN UNBUILT OR PARTIALLY-BUILT REPORT STILL CANNOT RENDER AS A CLEAN ONE ────────────────────
 *
 * The property the empty frame established survives the classes landing, and it is what §8 means by
 * *"zero discrepancies must be distinguishable from the command did not run"*. Coverage is three
 * numbers per population — examined, excluded, unrecognised — asserted against the population's own
 * count. `unrecognised` is separate from *examined-and-clean* because those are opposite facts that
 * a two-number report prints identically.
 *
 * The empty registry is judged BEFORE the coverage arithmetic, because the arithmetic has a
 * degenerate case that would have defeated the whole point: on a database with no gateway
 * transactions, population, examined and unrecognised are all zero and every check passes — so the
 * unbuilt report would have printed a clean result on exactly the environment most likely to run it
 * first. Having no detectors is a fact about this file, not about the data, so it is answered on its
 * own rather than inferred from a count that can collapse.
 *
 * ── COVERAGE: THREE NUMBERS, AND `unrecognised` IS ITS OWN ──────────────────────────────────────
 *
 * Every transaction in the population lands in exactly one bucket, and the three are asserted to sum
 * to the population — a row in two buckets, or in none, is itself a defect in the report and is
 * reported as one rather than quietly absorbed.
 *
 *   examined      — at least one detector evaluated this row and said so.
 *   excluded      — a NAMED rule puts it out of scope, and the name is printed.
 *   unrecognised  — no detector and no exclusion claimed it. NOBODY LOOKED AT THIS ROW.
 *
 * `unrecognised` is separate from `examined-and-clean` because they are opposite facts that a
 * two-number report renders identically. "No findings" over an unexamined population is the exact
 * shape of every instrument this project has been bitten by: a green that measures nothing. The
 * collation tripwire carried the same three numbers for the same reason, and its unrecognised count
 * is what surfaced the constructs it had never been able to see.
 *
 * ── AN EXCLUSION IS A NAMED RULE, NEVER A `WHERE` CLAUSE ────────────────────────────────────────
 *
 * The examined ids come FROM the detector, not from the frame's belief about what the detector
 * looked at. This is not ceremony. A detector that narrows its own query — one extra `AND` for a
 * case somebody decided was uninteresting — silently shrinks the denominator, and every count in
 * this report stays green while covering less. That class is recorded in CLAUDE.md already ("a
 * seventh filter hiding in a query makes a derived set silently narrower while every existing arm
 * stays green"); here the frame is arranged so the narrowing shows up as `unrecognised` instead of
 * as nothing at all. A detector that wants a row out of scope must return it as an exclusion with a
 * reason, where a reader can disagree with it.
 *
 * ── DETECT-ONLY, AND MORE FIRMLY THAN ITS SIBLINGS ─────────────────────────────────────────────
 *
 * {@see AuditLedgerCoherence} has no `--fix` because the right side is unknowable between two
 * consistent stories. This one has no `--fix` for a stronger reason: the right side is not in this
 * database at all. It is at the provider, and the resolution of most classes here is a human reading
 * a Paystack dashboard against these rows. A repair written from this side would be a guess about a
 * third party's records, written into append-only money tables.
 *
 * ── ONE CONSTRAINT THE §8 CLASS SET MUST BE RECONCILED AGAINST ─────────────────────────────────
 *
 * {@see GatewayTransactionStatus} already rules, in its own docblock, that the
 * stuck-transaction query is over `Pending` ONLY — `failed` and `abandoned` are non-terminal at the
 * database but ANSWERED in the business sense, and including them returns every abandoned checkout
 * that ever happened, for ever. If §8 partitions differently, that docblock and this report must be
 * reconciled explicitly rather than one of them silently winning.
 *
 * ── THE OPERATIONAL HALF IS OPEN, AND NO OWNER IS INVENTED FOR IT ──────────────────────────────
 *
 * What ships here is the detection half. The operational half — WHO reads this, HOW FAST, and what
 * they are authorised to do about a finding — is unresolved. So is the pending window itself
 * (`finance.discrepancy.pending_hours`), which is why {@see GatewayPendingWindow} refuses rather
 * than defaulting: the number is the report's meaning, and choosing it is the first operational
 * decision, not a configuration detail.
 *
 * These are named as open rather than assigned. It is not a data-model question, so it is not
 * Developer 1's, and it has not been put to Segun. Writing a plausible owner into this docblock
 * would make an unasked question look answered — the same overclaim as a control that exists only
 * in the client, one document up.
 *
 * ── NOT SCHEDULED, YET ─────────────────────────────────────────────────────────────────────────
 *
 * Its three siblings in `routes/console.php` run daily. This one is deliberately not added there
 * until the detectors land: a nightly job that fails every night because the report is unbuilt is an
 * alarm whose meaning is "not finished", and an alarm that always fires is one people learn to
 * close. Scheduling is owed in the same change as the classes.
 *
 * ── PRIVACY AND ISOLATION ──────────────────────────────────────────────────────────────────────
 *
 * **THIS OUTPUT IS PRIVILEGED. It inherits the sensitivity of the finance module, and it is not for
 * tickets, chat, or email.**
 *
 * §8 requires identifiers, amounts and the discrepancy — *"not a count"* — because a discrepancy
 * without its numbers is not actionable, and the operator running this already holds finance-module
 * access. So it prints amounts, and the standing rule against amounts in reports is not overridden
 * so much as scoped: **the printing was never the exposure; the pasting is.** The constraint
 * therefore travels with the OUTPUT rather than with the command, which is why it is stated here in
 * the terms a reader will need when they are about to copy a row into a ticket.
 *
 * Reads are raw `DB::select` with an EXPLICIT `school_id`, mirroring {@see AuditLedgerCoherence}.
 * Raw is required rather than convenient: a row whose `status` is outside the enum's four values is
 * exactly the kind of thing a discrepancy report exists to surface, and loading it through
 * {@see GatewayTransaction}'s cast would throw before it could be reported.
 * Because raw SQL bypasses `SchoolScope`, the school is supplied by hand on every query, and the
 * command iterates schools explicitly through `ActiveSchool::runFor` (§5.4).
 */
class GatewayDiscrepancyReport extends Command
{
    protected $signature = 'finance:gateway-discrepancy-report {--pending-hours= : Hours a checkout may sit unanswered before it is a finding; overrides finance.discrepancy.pending_hours, which has no default}';

    protected $description = 'READ-ONLY: reconcile gateway checkouts against the money they should have produced; exit non-zero on any finding OR any unexamined transaction (§6 step 7)';

    /**
     * TWO POPULATIONS, NOT ONE — and this is the shape §8 forced rather than a generalisation.
     *
     * D1, D3 and D4 ask about GATEWAY TRANSACTIONS. **D2 asks about PAYMENTS** — "which internal
     * payments of gateway origin have no gateway transaction" — and a payment that no transaction
     * names cannot be a row in the transaction table by definition. Counted against a single
     * transaction denominator, every D2 row would be invisible to the coverage check whose entire
     * purpose is to make invisibility impossible. The frame would have reported clean over the class
     * it could not see.
     *
     * So each side carries its own examined / excluded / population, and the report prints two
     * coverage lines rather than one summed number that describes neither.
     */
    private const TRANSACTIONS = 'transactions';

    private const PAYMENTS = 'payments';

    /** @var list<array{code: string, school_id: int, population: string, id: int, detail: string}> */
    private array $findings = [];

    /** @var array<string, list<int>> */
    private array $examined = [self::TRANSACTIONS => [], self::PAYMENTS => []];

    /** @var array<string, list<array{id: int, rule: string}>> */
    private array $excluded = [self::TRANSACTIONS => [], self::PAYMENTS => []];

    /** @var array<string, int> */
    private array $population = [self::TRANSACTIONS => 0, self::PAYMENTS => 0];

    public function handle(GatewayPendingWindow $window): int
    {
        try {
            $hours = $window->hours($this->option('pending-hours'));
        } catch (BusinessRuleException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $schools = 0;

        foreach (School::query()->get() as $school) {
            ActiveSchool::runFor($school->id, function () use ($school, $hours, &$schools) {
                $schools++;
                $this->population[self::TRANSACTIONS] += $this->countTransactions($school->id);
                $this->population[self::PAYMENTS] += $this->countGatewayPayments($school->id);

                foreach ($this->detectors() as $code => $detector) {
                    $this->absorb($school->id, $code, $detector($school->id, $hours));
                }
            });
        }

        return $this->report($schools, $hours);
    }

    /**
     * THE FOUR CLASSES OF §8.1, IN ITS ORDER. There are FOUR, not five — an earlier draft of this
     * work asserted five, and the fifth was invented.
     *
     * A detector is a PURE FUNCTION of (school, window) returning what it saw. It does not write to
     * this command's state, which is what lets each be tested on its own — hand it a school and an
     * hour count, read the scan back — rather than only through the report that aggregates it.
     *
     * `protected` so a test can substitute the registry and exercise the coverage arithmetic against
     * known scans, including the degenerate ones no real detector produces.
     *
     * THE CONTRACT, which is what makes the coverage arithmetic mean anything:
     *
     *   population  which denominator this scan belongs to — TRANSACTIONS or PAYMENTS.
     *   examined    EVERY id it evaluated, INCLUDING the ones it found nothing wrong with. "Looked
     *               at and clean" is the fact being counted; omitting them reports those rows as
     *               unrecognised, which is the frame working rather than a nuisance.
     *   excluded    anything deliberately skipped, with a NAMED rule — never a narrowed `WHERE`
     *               that leaves the row unaccounted for.
     *   findings    one per discrepancy, `code` from §8's vocabulary.
     *
     * KEYED BY CODE, NOT A POSITIONAL LIST, so the registry can be CENSUSED BY NAME. See
     * `GatewayDiscrepancyFrameTest`: the arms that assert the empty-registry behaviour now inject
     * their own empty registry, which leaves NOTHING checking that the real one is populated — and
     * an emptied registry makes every class find nothing while the command exits 0 looking clean.
     * That is §8's *"zero discrepancies must be distinguishable from the command did not run"*
     * failing one level above where the coverage line catches it. A census by COUNT would not close
     * it either: swapping one detector for a duplicate of another keeps the count at four.
     *
     * @return array<string, callable(int, int): array{
     *     population: string,
     *     examined: list<int>,
     *     excluded: list<array{id: int, rule: string}>,
     *     findings: list<array{code: string, id: int, detail: string}>
     * }>
     */
    protected function detectors(): array
    {
        return [
            'D1' => fn (int $schoolId, int $hours): array => $this->successWithNoPayment($schoolId),
            'D2' => fn (int $schoolId, int $hours): array => $this->gatewayPaymentWithNoTransaction($schoolId),
            'D3' => fn (int $schoolId, int $hours): array => $this->pairDisagreesOnAmount($schoolId),
            'D4' => fn (int $schoolId, int $hours): array => $this->stuckBeyondTheWindow($schoolId, $hours),
        ];
    }

    /**
     * D1 — §8.1(1): a transaction the provider called successful, against which no payment exists.
     *
     * **THE ONE THAT COSTS THE SCHOOL ITS CREDIBILITY**, and the reason §8 refuses to drop these
     * queries even though the whole feature is manual: a parent has paid and the school holds no
     * record of it. Every other class here is an inconsistency; this one is money taken and lost.
     *
     * It examines EVERY success row, not only the offending ones, because "settled and accounted
     * for" is the fact the coverage count is about.
     */
    private function successWithNoPayment(int $schoolId): array
    {
        $rows = DB::select(
            'SELECT id, payment_id, amount_minor, amount_currency, reference
               FROM finance_gateway_transactions
              WHERE school_id = ? AND status COLLATE utf8mb4_bin = ?',
            [$schoolId, GatewayTransactionStatus::Success->value],
        );

        $findings = [];

        foreach ($rows as $row) {
            if ($row->payment_id === null) {
                $findings[] = [
                    'code' => 'D1',
                    'id' => (int) $row->id,
                    'detail' => sprintf(
                        'provider reported SUCCESS for %s and no payment exists. Charged %s.',
                        $row->reference,
                        Money::fromKobo((int) $row->amount_minor, $row->amount_currency)->format(),
                    ),
                ];
            }
        }

        return [
            'population' => self::TRANSACTIONS,
            'examined' => array_map(fn ($r) => (int) $r->id, $rows),
            'excluded' => [],
            'findings' => $findings,
        ];
    }

    /**
     * D2 — §8.1(2): a payment of gateway origin that no transaction accounts for.
     *
     * **THIS IS THE CLASS THAT FORCED THE SECOND DENOMINATOR.** Its population is payments, and a
     * payment no transaction names cannot appear in the transaction table at all. Counted against a
     * transaction denominator it would be invisible — to the very check that exists to prevent
     * invisibility.
     *
     * The join is on `payment_id` AND `school_id` together: the composite is what stops a payment
     * being "accounted for" by another school's transaction, and raw SQL has no `SchoolScope` to
     * fall back on.
     */
    private function gatewayPaymentWithNoTransaction(int $schoolId): array
    {
        $rows = DB::select(
            'SELECT p.id, p.amount_minor, p.amount_currency, p.external_reference, t.id AS txn_id
               FROM finance_payments p
               LEFT JOIN finance_gateway_transactions t
                 ON t.payment_id = p.id AND t.school_id = p.school_id
              WHERE p.school_id = ? AND p.origin COLLATE utf8mb4_bin = ?',
            [$schoolId, Payment::ORIGIN_GATEWAY],
        );

        $findings = [];

        foreach ($rows as $row) {
            if ($row->txn_id === null) {
                $findings[] = [
                    'code' => 'D2',
                    'id' => (int) $row->id,
                    'detail' => sprintf(
                        'payment of gateway origin for %s has no gateway transaction. Reference %s.',
                        Money::fromKobo((int) $row->amount_minor, $row->amount_currency)->format(),
                        $row->external_reference ?? '(none recorded)',
                    ),
                ];
            }
        }

        return [
            'population' => self::PAYMENTS,
            'examined' => array_map(fn ($r) => (int) $r->id, $rows),
            'excluded' => [],
            'findings' => $findings,
        ];
    }

    /**
     * D3 — §8.1(3): a settled pair whose numbers do not reconcile.
     *
     * ── TWO PLACES §8 IS READ RATHER THAN FOLLOWED, BOTH DELIBERATE AND BOTH REFERRED BACK ────
     *
     * §8 says *"which pairs disagree on amount"*. Taken literally that is
     * `transaction.amount_minor <> payment.amount_minor` — and **that is true of every correctly
     * settled pair in this system**, because the transaction carries the GROSS and the payment
     * carries `gross − fee` (`SettleGatewayTransaction:305`, under Developer 1's parent-bears
     * ruling of 2026-08-30). A detector written to the words would flag 100% of healthy
     * settlements, and a report that does that is ignored the second time it runs.
     *
     * §8 predates the ruling; the words and the system drifted rather than either being wrong. The
     * invariant that preserves §8's INTENT — the pair is internally consistent — is:
     *
     *     amount_minor − COALESCE(fee_minor, 0) == payment.amount_minor
     *
     * **This is a reading, not a ruling.** It is flagged to §8's owner as a spec correction rather
     * than left in this docblock alone, because a docblock only reaches whoever opens this file and
     * the next person implementing from §8 would build the literal version.
     *
     * **READING 2 — A MISSING FEE IS FILED UNDER D3, AND THAT IS A WIDENING.**
     *
     * §8's D3 is *"which pairs disagree on amount"*. A pair with no fee recorded does not disagree
     * about anything: it cannot be CHECKED. Reporting it here is a defensible widening of the class
     * — D3 is the only place that looks at pairs at all, and the alternative is a state nobody
     * reports — but it is a second interpretation, not an application of the first, and it is
     * counted as one. See the class docblock's question to §8's owner, which asks about both.
     *
     * The state should be unreachable: `SettleGatewayTransaction::claim()` is the only writer of
     * `payment_id` and it sets `fee_minor` in the SAME statement, so `payment_id NOT NULL` implies
     * `fee_minor NOT NULL` structurally. It is reported rather than coalesced to zero because
     * coalescing would compare the gross against the payment and print a FALSE amount mismatch,
     * sending an operator to look for the wrong thing — worse than either throwing or reporting.
     *
     * `bill_minor` IS PRINTED AND IS NOT A CRITERION. An operator reading a disagreement wants to
     * know what was billed; it is context. It is deliberately NOT a fifth class — §8 names four, and
     * *"the payment covers the bill"* is a check the column now makes POSSIBLE and that nobody has
     * asked for. A class comes from the spec or from whoever owns the decision, never from the
     * availability of data that would support one.
     */
    private function pairDisagreesOnAmount(int $schoolId): array
    {
        $rows = DB::select(
            'SELECT t.id, t.amount_minor, t.amount_currency, t.fee_minor, t.bill_minor,
                    p.id AS payment_id, p.amount_minor AS paid_minor
               FROM finance_gateway_transactions t
               JOIN finance_payments p ON p.id = t.payment_id AND p.school_id = t.school_id
              WHERE t.school_id = ?',
            [$schoolId],
        );

        $findings = [];

        foreach ($rows as $row) {
            $currency = $row->amount_currency;

            // A SETTLED PAIR WITH NO FEE IS IMPOSSIBLE, SO IT IS REPORTED RATHER THAN COALESCED.
            //
            // `SettleGatewayTransaction::claim()` is the ONLY writer of `payment_id`, and it sets
            // `fee_minor` in the SAME statement — so `payment_id NOT NULL` implies `fee_minor NOT
            // NULL` structurally, not by convention. `COALESCE(fee, 0)` would therefore handle a
            // state that cannot occur, and handle it BADLY: comparing gross against the payment and
            // reporting a false amount mismatch, which sends an operator to look for the wrong
            // thing. If it ever does occur, the pair cannot be reconciled at all and that is what
            // the finding should say.
            //
            // Not a new class — D3 IS "the pair does not reconcile", and this is the case where it
            // cannot even be attempted.
            if ($row->fee_minor === null) {
                $findings[] = [
                    'code' => 'D3',
                    'id' => (int) $row->id,
                    'detail' => sprintf(
                        'settled against payment#%d with NO fee recorded, so the pair cannot be '
                        .'reconciled. This should be impossible: claim() writes payment_id and '
                        .'fee_minor in one statement.',
                        (int) $row->payment_id,
                    ),
                ];

                continue;
            }

            $expected = (int) $row->amount_minor - (int) $row->fee_minor;

            if ($expected === (int) $row->paid_minor) {
                continue;
            }

            $findings[] = [
                'code' => 'D3',
                'id' => (int) $row->id,
                'detail' => sprintf(
                    'charged %s less fee %s should credit %s, but payment#%d records %s. Billed %s.',
                    Money::fromKobo((int) $row->amount_minor, $currency)->format(),
                    Money::fromKobo((int) $row->fee_minor, $currency)->format(),
                    Money::fromKobo($expected, $currency)->format(),
                    (int) $row->payment_id,
                    Money::fromKobo((int) $row->paid_minor, $currency)->format(),
                    $row->bill_minor === null
                        ? '(not recorded)'
                        : Money::fromKobo((int) $row->bill_minor, $currency)->format(),
                ),
            ];
        }

        return [
            'population' => self::TRANSACTIONS,
            'examined' => array_map(fn ($r) => (int) $r->id, $rows),
            'excluded' => [],
            'findings' => $findings,
        ];
    }

    /**
     * D4 — §8.1(4): a checkout still unanswered beyond the stated age.
     *
     * **`Pending` ONLY, and the authority for that is not this file.**
     * {@see GatewayTransactionStatus} already ruled it in its own docblock: three of the four states
     * are non-terminal in the DATABASE sense, but only `pending` is unresolved in the BUSINESS
     * sense. Read as the database sense, this query returns every abandoned checkout that ever
     * happened, for ever — and a report nobody can read is a report nobody reads.
     *
     * So `failed` and `abandoned` are EXCLUDED WITH A NAMED RULE rather than filtered out of the
     * query. They are answered, not stuck; and stating that as an exclusion keeps them inside the
     * denominator, where a reader can disagree with the reason, instead of vanishing into a
     * narrowed `WHERE`.
     *
     * THE CUTOFF IS COMPUTED IN PHP, NOT IN SQL. `bin/ci-sql-clock-lint.php` forbids MySQL clock
     * functions in raw SQL — two frames, one table — so the boundary crosses as a bound parameter.
     */
    private function stuckBeyondTheWindow(int $schoolId, int $hours): array
    {
        $rows = DB::select(
            'SELECT id, status, created_at, amount_minor, amount_currency, reference
               FROM finance_gateway_transactions
              WHERE school_id = ? AND status COLLATE utf8mb4_bin <> ?',
            [$schoolId, GatewayTransactionStatus::Success->value],
        );

        $cutoff = CarbonImmutable::now()->subHours($hours);
        $examined = [];
        $excluded = [];
        $findings = [];

        foreach ($rows as $row) {
            if ($row->status !== GatewayTransactionStatus::Pending->value) {
                $excluded[] = [
                    'id' => (int) $row->id,
                    'rule' => 'answered by the provider ('.$row->status.'), not awaiting an answer',
                ];

                continue;
            }

            $examined[] = (int) $row->id;

            $startedAt = CarbonImmutable::parse($row->created_at);

            if ($startedAt->greaterThan($cutoff)) {
                continue;
            }

            $findings[] = [
                'code' => 'D4',
                'id' => (int) $row->id,
                'detail' => sprintf(
                    'still awaiting an answer %d hours after it started (%s), for %s. Reference %s.',
                    $startedAt->diffInHours(CarbonImmutable::now()),
                    $startedAt->toDateTimeString(),
                    Money::fromKobo((int) $row->amount_minor, $row->amount_currency)->format(),
                    $row->reference,
                ),
            ];
        }

        return [
            'population' => self::TRANSACTIONS,
            'examined' => $examined,
            'excluded' => $excluded,
            'findings' => $findings,
        ];
    }

    /**
     * Folds one detector's scan into its own population's totals.
     *
     * @param  array{population: string, examined: list<int>, excluded: list<array{id: int, rule: string}>, findings: list<array{code: string, id: int, detail: string}>}  $scan
     */
    private function absorb(int $schoolId, string $code, array $scan): void
    {
        $population = $scan['population'];

        foreach ($scan['findings'] as $finding) {
            // THE REGISTRY KEY AND THE FINDING'S CODE MUST AGREE. They are written in two places —
            // the key here and the literal inside the detector — and a copy-pasted detector that
            // kept the source's code would file its findings under the wrong class silently, in a
            // report whose entire value is that an operator can act on what it names.
            if ($finding['code'] !== $code) {
                throw new \LogicException(sprintf(
                    'Detector registered as %s emitted a finding coded %s.', $code, $finding['code'],
                ));
            }
        }

        foreach ($scan['examined'] as $id) {
            $this->examined[$population][] = $id;
        }

        foreach ($scan['excluded'] as $exclusion) {
            $this->excluded[$population][] = $exclusion;
        }

        foreach ($scan['findings'] as $finding) {
            $this->findings[] = [
                'code' => $finding['code'],
                'school_id' => $schoolId,
                'population' => $population,
                'id' => $finding['id'],
                'detail' => $finding['detail'],
            ];
        }
    }

    /** Every gateway transaction in this school — the denominator D1, D3 and D4 answer against. */
    private function countTransactions(int $schoolId): int
    {
        $row = DB::select(
            'SELECT COUNT(*) AS n FROM finance_gateway_transactions WHERE school_id = ?',
            [$schoolId],
        );

        return (int) ($row[0]->n ?? 0);
    }

    /** Every payment of gateway origin — D2's denominator, and the reason there are two. */
    private function countGatewayPayments(int $schoolId): int
    {
        $row = DB::select(
            'SELECT COUNT(*) AS n FROM finance_payments WHERE school_id = ? AND origin COLLATE utf8mb4_bin = ?',
            [$schoolId, Payment::ORIGIN_GATEWAY],
        );

        return (int) ($row[0]->n ?? 0);
    }

    /**
     * Prints the findings, then a coverage line PER POPULATION, then decides the exit code.
     *
     * SUCCESS requires no findings AND nothing unrecognised in EITHER population. The second half is
     * what §8 means by *"zero discrepancies must be distinguishable from the command did not run"*:
     * a report is not clean because it found nothing, it is clean because it LOOKED at everything
     * and found nothing.
     */
    private function report(int $schools, int $hours): int
    {
        foreach ($this->findings as $f) {
            $this->error(sprintf(
                '[%s] school#%d %s#%d: %s',
                $f['code'], $f['school_id'], $f['population'] === self::PAYMENTS ? 'payment' : 'txn',
                $f['id'], $f['detail'],
            ));
        }

        $unrecognised = 0;
        $overCounted = false;

        foreach ([self::TRANSACTIONS, self::PAYMENTS] as $population) {
            $examined = count(array_unique($this->examined[$population]));
            $excluded = count(array_unique(array_column($this->excluded[$population], 'id')));
            $missing = $this->population[$population] - $examined - $excluded;

            $this->line(sprintf(
                '%-12s %d total — %d examined, %d excluded, %d unrecognised.',
                $population.':', $this->population[$population], $examined, $excluded, $missing,
            ));

            foreach (array_count_values(array_column($this->excluded[$population], 'rule')) as $rule => $n) {
                $this->line(sprintf('  excluded by "%s": %d', $rule, $n));
            }

            if ($missing < 0) {
                $overCounted = true;
            }

            $unrecognised += max(0, $missing);
        }

        $this->line(sprintf('%d school(s), window %dh.', $schools, $hours));

        // BEFORE THE ARITHMETIC, because the arithmetic has a degenerate case: with no transactions
        // and no payments every count is zero and every check below passes, so an unbuilt report
        // would print a clean result on exactly the environment most likely to run it first. Having
        // no detectors is a fact about this file, not about the data.
        if ($this->detectors() === []) {
            $this->error(
                'No detectors are registered. This report is a frame with nothing in it: it has '
                .'examined nothing and must not be read as a clean result.'
            );

            return self::FAILURE;
        }

        // Not a coverage number — an arithmetic check ON the coverage numbers. A row counted twice,
        // or claimed by a detector that never evaluated it, corrupts every figure above rather than
        // one of them.
        if ($overCounted) {
            $this->error(
                'Coverage does not sum: examined + excluded exceeds a population. A detector is '
                .'reporting rows it did not evaluate, or the same row through two buckets.'
            );

            return self::FAILURE;
        }

        if ($unrecognised > 0) {
            $this->error(sprintf(
                '%d row(s) were examined by NO detector. They fall outside every registered class and '
                .'outside every named exclusion — so no finding about them means nobody looked, not '
                .'that there is nothing there.',
                $unrecognised,
            ));

            return self::FAILURE;
        }

        if ($this->findings !== []) {
            $this->error(sprintf(
                '%d discrepanc(ies) across %d school(s). There is no --fix: repair is a human decision '
                .'with an audit trail, and the other side of most of these is at the provider.',
                count($this->findings), $schools,
            ));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'No discrepancies: %d school(s), %d transaction(s) and %d gateway payment(s), all examined.',
            $schools, $this->population[self::TRANSACTIONS], $this->population[self::PAYMENTS],
        ));

        return self::SUCCESS;
    }
}
