<?php

namespace App\Finance\Console;

use App\Exceptions\BusinessRuleException;
use App\Finance\Enums\GatewayTransactionStatus;
use App\Finance\Models\GatewayTransaction;
use App\Finance\Services\GatewayPendingWindow;
use App\Models\School;
use App\Support\ActiveSchool;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * §6 STEP 7 — the gateway discrepancy report. THE FRAME SHIPS HERE; THE DETECTORS DO NOT YET.
 *
 * ── WHY AN EMPTY DETECTOR LIST IS THE HONEST STATE AND NOT A STUB ───────────────────────────────
 *
 * The classes this report must detect are specified in §8 of the boundary and addendum documents.
 * That specification was not available when this was written, and the classes were NOT reconstructed
 * from the data model — deliberately. A partition guessed from the schema is plausible by
 * construction and wrong in the one dimension nobody thought of: what reads here as one class
 * ("nothing has answered for this checkout") may be two in §8, split on a distinction the schema
 * cannot show. Rebuilding a detector costs more than waiting for the spec, and a report that looks
 * complete while missing the class it was written for is the expensive failure, not the late one.
 *
 * So `detectors()` returns an empty list, and the coverage arithmetic below makes that VISIBLE
 * rather than silent: with no detectors, every transaction is `unrecognised`, and the command exits
 * FAILURE saying so. **An unbuilt report cannot render as a clean one.** That is the single property
 * this file exists to establish, and it is the property that survives whatever §8 turns out to say.
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
 * Ids only — `school#<id>`, `txn#<id>`. No payer, no reference, no amount: a reference is a
 * provider-side identifier for a real person's payment and this output goes to logs.
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

    /** @var list<array{code: string, school_id: int, txn_id: int, detail: string}> */
    private array $findings = [];

    /** @var list<int> */
    private array $examined = [];

    /** @var list<array{txn_id: int, rule: string}> */
    private array $excluded = [];

    private int $population = 0;

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
                $this->population += $this->countTransactions($school->id);

                foreach ($this->detectors() as $detector) {
                    $this->absorb($school->id, $detector($school->id, $hours));
                }
            });
        }

        return $this->report($schools, $hours);
    }

    /**
     * THE CLASSES GO HERE, ONE CALLABLE EACH, AND THE LIST IS EMPTY UNTIL §8 SAYS WHAT THEY ARE.
     *
     * A detector is a PURE FUNCTION of (school, window) returning what it saw. It does not write to
     * this command's state, which is what lets each one be tested on its own — hand it a school and
     * an hour count, read the scan back — rather than only through the report that aggregates it.
     *
     * `protected` rather than `private` so a test can substitute the registry and exercise the
     * coverage arithmetic against known scans. Without that the arithmetic could only be tested
     * through real detectors, which do not exist yet — and a frame whose central claim is "an
     * unexamined row is visible" cannot ship with that claim unproven until its first consumer.
     *
     * The contract, which is what makes the coverage arithmetic mean anything:
     *
     *   examined   EVERY transaction id it evaluated, INCLUDING the ones it found nothing wrong
     *              with. "Looked at and clean" is the fact being counted; omitting those rows
     *              reports them as unrecognised, which is the frame working, not a nuisance.
     *   excluded   anything it deliberately skips, with a NAMED rule — never a narrowed `WHERE`
     *              that leaves the row unaccounted for.
     *   findings   one entry per discrepancy, `code` drawn from §8's vocabulary.
     *
     * @return list<callable(int, int): array{
     *     examined: list<int>,
     *     excluded: list<array{txn_id: int, rule: string}>,
     *     findings: list<array{code: string, txn_id: int, detail: string}>
     * }>
     */
    protected function detectors(): array
    {
        return [];
    }

    /**
     * Folds one detector's scan into the run's totals.
     *
     * @param  array{
     *     examined: list<int>,
     *     excluded: list<array{txn_id: int, rule: string}>,
     *     findings: list<array{code: string, txn_id: int, detail: string}>
     * }  $scan
     */
    private function absorb(int $schoolId, array $scan): void
    {
        foreach ($scan['examined'] as $txnId) {
            $this->examined[] = $txnId;
        }

        foreach ($scan['excluded'] as $exclusion) {
            $this->excluded[] = $exclusion;
        }

        foreach ($scan['findings'] as $finding) {
            $this->findings[] = [
                'code' => $finding['code'],
                'school_id' => $schoolId,
                'txn_id' => $finding['txn_id'],
                'detail' => $finding['detail'],
            ];
        }
    }

    /** Every gateway transaction in this school — the denominator the three coverage numbers sum to. */
    private function countTransactions(int $schoolId): int
    {
        $row = DB::select(
            'SELECT COUNT(*) AS n FROM finance_gateway_transactions WHERE school_id = ?',
            [$schoolId],
        );

        return (int) ($row[0]->n ?? 0);
    }

    /**
     * Prints the findings, then the three coverage numbers, then decides the exit code.
     *
     * SUCCESS requires BOTH no findings AND nothing unrecognised. The second half is the one that
     * matters today and the one that keeps mattering: a report is not clean because it found
     * nothing, it is clean because it LOOKED at everything and found nothing.
     */
    private function report(int $schools, int $hours): int
    {
        $examined = count(array_unique($this->examined));
        $excluded = count(array_unique(array_column($this->excluded, 'txn_id')));
        $unrecognised = $this->population - $examined - $excluded;

        foreach ($this->findings as $f) {
            $this->error(sprintf(
                '[%s] school#%d txn#%d: %s',
                $f['code'], $f['school_id'], $f['txn_id'], $f['detail'],
            ));
        }

        $this->line(sprintf(
            'Coverage over %d school(s), window %dh: %d transaction(s) — %d examined, %d excluded, %d unrecognised.',
            $schools, $hours, $this->population, $examined, $excluded, $unrecognised,
        ));

        foreach (array_count_values(array_column($this->excluded, 'rule')) as $rule => $n) {
            $this->line(sprintf('  excluded by "%s": %d', $rule, $n));
        }

        // BEFORE THE ARITHMETIC, BECAUSE THE ARITHMETIC HAS A DEGENERATE CASE. On a school with no
        // gateway transactions at all, population, examined and unrecognised are all zero and every
        // check below passes — so an unbuilt report would print a clean result on an empty database,
        // which is exactly the state a fresh environment is in. The registry being empty is a fact
        // about THIS FILE, not about the data, so it is judged on its own.
        if ($this->detectors() === []) {
            $this->error(
                'No detectors are registered. The §8 class set is not yet specified, so this report is a '
                .'frame with nothing in it: it has examined nothing and must not be read as a clean result.'
            );

            return self::FAILURE;
        }

        // Not a coverage number: an arithmetic check ON the coverage numbers. A row counted twice or
        // lost between buckets means a detector is misreporting what it looked at, which corrupts
        // every number above rather than just one.
        if ($unrecognised < 0) {
            $this->error(sprintf(
                'Coverage does not sum: %d examined + %d excluded exceeds a population of %d. A detector '
                .'is reporting rows it did not evaluate, or the same row through two buckets.',
                $examined, $excluded, $this->population,
            ));

            return self::FAILURE;
        }

        if ($unrecognised > 0) {
            $this->error(sprintf(
                '%d of %d transaction(s) were examined by NO detector. %s',
                $unrecognised, $this->population,
                'They fall outside every registered detector and outside every named exclusion — so no '
                .'finding about them means nobody looked, not that there is nothing there.',
            ));

            return self::FAILURE;
        }

        if ($this->findings !== []) {
            $this->error(sprintf(
                '%d discrepanc(ies) across %d school(s). There is no --fix: the other side of every one of '
                .'these is at the provider, not in this database.',
                count($this->findings), $schools,
            ));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Gateway discrepancies: %d school(s), %d transaction(s) all examined, none found.',
            $schools, $this->population,
        ));

        return self::SUCCESS;
    }
}
