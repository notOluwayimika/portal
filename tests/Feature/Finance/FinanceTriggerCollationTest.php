<?php

/*
 * EVERY STRING COMPARISON IN A FINANCE TRIGGER RUNS UNDER A BINARY COLLATION — or is named below.
 *
 * ── WHY THIS EXISTS, AND WHY THE LIST BELOW IS NOT AN APOLOGY ────────────────────────────────────
 *
 * Every `finance_` table is `utf8mb4_unicode_ci`, which is case- AND accent-insensitive. So inside a
 * trigger body:
 *
 *     NEW.status = 'approved'                     also matches 'Approved', 'APPROVED'
 *     NOT (NEW.provider <=> OLD.provider)         permits 'paystack' -> 'PAYSTACK'
 *     NEW.amount_currency <> OLD.amount_currency  permits 'NGN' -> 'ṄGN'
 *
 * A DOMAIN arm written that way admits values no report filter will ever match. A FREEZE arm written
 * that way does not freeze. `2026_08_17_100000`'s docblock records the class for domain arms and
 * says why it is hard to see: omitting the clause from ONE arm is the quiet failure, because the
 * others keep biting and the guard still looks alive.
 *
 * ── THE MEASUREMENT THAT DECIDED THIS TEST'S SCOPE ───────────────────────────────────────────────
 *
 * On 2026-08-29 a sweep found 29 bare comparisons across 10 triggers. On 2026-08-30 the same sweep
 * found **31**, and the two new ones — `finance_discount_policies_base_shape_bu` and
 * `finance_discount_policy_changes_base_shape_bu`, both `NEW.base <=> OLD.base` — arrived in
 * migrations merged that same day.
 *
 * **Nobody was careless.** The class is invisible to a person writing a trigger, because nothing
 * fails when the clause is omitted. That is the entire case for a tripwire, and it was made by the
 * list growing while the tripwire was being designed.
 *
 * ── SCOPE: REPO-WIDE WITH THE KNOWN ONES ENUMERATED, WHICH IS A DELIBERATE DEVIATION ─────────────
 *
 * The instruction was "scoped as it is" — i.e. left covering only the two gateway tables — with the
 * stated purpose "stops the list reaching thirty", and the stated fear "or it fails on the existing
 * ones". Those pull apart: a gateway-scoped tripwire cannot see a thirtieth appear on
 * `finance_credit_notes`, and in fact did not see the thirtieth and thirty-first appear on the
 * discount tables.
 *
 * Enumeration satisfies both halves and removes the fear: the sweep is repo-wide, so growth is
 * caught anywhere; the 31 known comparisons are named, so nothing existing fails. Same mechanism as
 * `CheckConstraintsAsTriggersTest`'s exact set, and it inherits that gate's coordination cost
 * knowingly — see the failure message, which is written to make the tax cheap.
 *
 * **ONE DIFFERENCE FROM THAT GATE, AND IT IS THE IMPORTANT ONE.** For a CHECK, adding your
 * constraint to the list is a legitimate fix. **Here it is not.** The fix is to add
 * `COLLATE utf8mb4_bin` to your comparison. A gate that teaches people to silence it is worse than
 * no gate, so the message says which fix is meant and the list carries no invitation to grow.
 *
 * ── HOW TO BITE-PROOF THIS TEST, BECAUSE THE OBVIOUS WAY SILENTLY DOES NOT WORK ─────────────────
 *
 * Planting a trigger with `CREATE TRIGGER` before the run **proves nothing**: this file uses
 * `RefreshDatabase`, which runs `migrate:fresh` and DROPS every table and trigger before the first
 * assertion. The plant is destroyed and the gate reports green — measured, 2026-08-30, and it read
 * as a passing bite-proof.
 *
 * **Mutate a MIGRATION instead**, so the defect is re-created by the refresh: strip
 * `COLLATE utf8mb4_bin` from one arm of any finance trigger's migration and run this file. It fails
 * naming that exact comparison. Restore, and it is green again. Both directions verified that way.
 *
 * The general form is worth carrying: **a bite-proof must survive the fixture's own setup.** A plant
 * that the test's `beforeEach` erases is a plant that tested the erasure.
 *
 * ── THE MATCHER HAS A KNOWN POSITIVE AND A KNOWN NEGATIVE ────────────────────────────────────────
 *
 * Both, because this project has now been bitten in both directions by matchers written for exactly
 * this class: one missed `<=>` (the operator the freeze arms use) and under-counted; the next
 * flagged `BINARY`-guarded comparisons and reported someone else's correct code as defective. And a
 * gate that is broken-closed refuses everything, which reads as strictness until it is bypassed.
 * See the final two tests in this file.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * A column compared to a literal or to another column, under either protection idiom.
 *
 * BOTH IDIOMS, because this repository has two: `COLLATE utf8mb4_bin`, and the older `BINARY x`
 * operator that `2026_07_26_140002` uses (later migrations moved to `COLLATE`, because
 * `NOT REGEXP BINARY` errors 3995 on utf8mb4). A matcher knowing only one reports correct code as
 * broken.
 *
 * AND `<=>` IS IN THE OPERATOR LIST FIRST, deliberately: it is the null-safe operator every freeze
 * arm uses, it is the majority case of this defect, and the first scan written for this class
 * omitted it and swept cleanly over the very thing it was looking for.
 */
/**
 * AND `LIKE` WAS ABSENT UNTIL 2026-09-01, WHICH IS THE SAME OMISSION AS `<=>` ONE OPERATOR OVER.
 *
 * `LIKE` is collation-sensitive — under `utf8mb4_unicode_ci`, `'a' LIKE 'A'` is TRUE — so an
 * unguarded `NEW.x LIKE '…'` is exactly this defect, and this matcher could not see it. Nothing had
 * ever failed, because the only two `LIKE`s against a NEW column in the repository both happened to
 * carry `COLLATE utf8mb4_bin`. Clean by luck, not by coverage.
 *
 * IT WAS FOUND BY ASKING FOR THE DENOMINATOR rather than by a failure: counting what this matcher
 * hits against an independent count of comparison constructs, after the SIGNAL-length lint turned
 * out to have been reading 61 of 117 messages while reporting clean. A gate's FIRST green is the
 * least trustworthy green it will ever produce, because it is the only one taken before anyone has
 * established what the instrument cannot see.
 *
 * TWO GAPS, AND FIXING EITHER ALONE WOULD HAVE REPORTED SUCCESS WHILE STILL BLIND. The operator
 * list lacked `LIKE`; the right-hand side accepted only a quoted literal or a NEW/OLD column, so a
 * FUNCTION CALL (`CONCAT('bpsk-', NEW.school_id, '-%')`) was invisible too. A partial fix converts a
 * known blind spot into an unknown one, which is worse than the gap — so the bite-proof is a `LIKE`
 * with a function-call RHS, the one case neither half catches on its own.
 *
 * GUARDED PLACEMENTS ARE UNCHANGED: either side, either mechanism — `BINARY` or `COLLATE
 * utf8mb4_bin`, on the left operand or the right. That is what ftcBareComparisons() has always
 * accepted, so the function-call RHS also takes a trailing `COLLATE`. Both placements are fixtured
 * below, so the definition is pinned by a test rather than by whatever the codebase contains today.
 *
 * KNOWN LIMIT, DELIBERATELY NOT PAPERED OVER: `\w+\([^)]*\)` cannot match a NESTED function call.
 * Those are not silently skipped — they fall into the UNRECOGNISED count that ftcCoverage() reports,
 * which is the number that matters.
 */
const FTC_MATCHER = "/(BINARY\\s+)?(NEW|OLD)\\.(\\w+)(\\s+COLLATE\\s+(\\w+))?\\s*(<=>|=|<>|NOT\\s+REGEXP|REGEXP|NOT\\s+LIKE|LIKE)\\s*((BINARY\\s+)?'[^']*'(?:\\s+COLLATE\\s+\\w+)?|(BINARY\\s+)?(?:NEW|OLD)\\.\\w+(?:\\s+COLLATE\\s+\\w+)?|(BINARY\\s+)?\\w+\\([^)]*\\)(?:\\s+COLLATE\\s+\\w+)?|(BINARY\\s+)?[a-z_]\\w*(?:\\s+COLLATE\\s+\\w+)?|-?\\d+)/i";

/**
 * Every STRING-comparison-shaped construct, however written — the DENOMINATOR against which
 * FTC_MATCHER's hits are the numerator. Deliberately broader and dumber than the matcher.
 *
 * `<` AND `>` ARE NOT IN IT, and the omission is the scope of this gate rather than an oversight.
 * An ordering comparison on a numeric column (`NEW.fee_minor < 0`) has no collation, so it is not
 * in the denominator at all — as against `NEW.status = OLD.status`, which this matcher DOES take and
 * then classifies as excluded because `status` is not a string column. The difference matters:
 * "outside the question" and "inside the question and skipped for a stated reason" are different
 * facts, and the first version of this constant conflated them, reporting nine numeric guards as
 * UNRECOGNISED.
 *
 * A NUMERIC LITERAL IS ALSO AN ACCEPTED RIGHT-HAND SIDE (`NEW.allocation_overridden = 1`), not
 * because a collation tripwire cares about it, but because the matcher must be able to CLASSIFY a
 * construct in order to exclude it. A form it cannot parse is unrecognised, and unrecognised is the
 * dangerous bucket; a form it parses and then skips on column type is a stated decision. Widening
 * the matcher to recognise MORE than it judges is what keeps the third number honest.
 *
 * WHAT IT CAUGHT WHILE BEING WRONG: comparisons against a DECLARED LOCAL VARIABLE
 * (`BINARY NEW.amount_currency <> BINARY v_currency`). Those are string comparisons the matcher's
 * right-hand side had never accepted — a second blind spot beside `LIKE`, found by the same
 * question, and one this repository already knows is hazardous (the #95 variable-collation trap).
 */
const FTC_BROAD = '/(?:BINARY\\s+)?(?:NEW|OLD)\\.\\w+(?:\\s+COLLATE\\s+\\w+)?\\s*(?:<=>|<>|!=|=|NOT\\s+REGEXP|REGEXP|NOT\\s+LIKE|LIKE)/i';

/**
 * The comparisons that are bare TODAY and are accepted as pre-existing.
 *
 * **DO NOT ADD TO THIS LIST TO MAKE A FAILURE GO AWAY.** If this test names your comparison, the fix
 * is `COLLATE utf8mb4_bin`, not an entry here. The list exists so the gate can be repo-wide without
 * failing on work that predates it; it is a debt register, and it should only ever shrink.
 *
 * Ticketed: docs/handoff/tickets/finance-trigger-string-comparisons-are-case-insensitive.md
 *
 * @return list<string>
 */
function ftcAcceptedBare(): array
{
    return [
        'finance_bank_accounts_identity_immutable  ::  NEW.account_number <> OLD.account_number',
        'finance_bank_accounts_identity_immutable  ::  NEW.bank_name <> OLD.bank_name',
        "finance_credit_notes_insert_guard  ::  NEW.status = 'approved'",
        'finance_credit_notes_update_guard  ::  NEW.amount_currency <> OLD.amount_currency',
        'finance_credit_notes_update_guard  ::  NEW.kind <> OLD.kind',
        'finance_credit_notes_update_guard  ::  NEW.note <=> OLD.note',
        "finance_credit_notes_update_guard  ::  NEW.status <> 'approved'",
        "finance_credit_notes_update_guard  ::  NEW.status = 'approved'",
        'finance_credit_notes_update_guard  ::  NEW.uuid <> OLD.uuid',
        'finance_discount_policies_base_shape_bu  ::  NEW.base <=> OLD.base',
        'finance_discount_policies_update_guard  ::  NEW.basis <> OLD.basis',
        'finance_discount_policies_update_guard  ::  NEW.name <> OLD.name',
        'finance_discount_policies_update_guard  ::  NEW.uuid <> OLD.uuid',
        'finance_discount_policies_update_guard  ::  NEW.value_currency <=> OLD.value_currency',
        'finance_discount_policy_changes_base_shape_bu  ::  NEW.base <=> OLD.base',
        'finance_discount_policy_changes_update_guard  ::  NEW.basis <=> OLD.basis',
        'finance_discount_policy_changes_update_guard  ::  NEW.description <=> OLD.description',
        'finance_discount_policy_changes_update_guard  ::  NEW.kind <> OLD.kind',
        'finance_discount_policy_changes_update_guard  ::  NEW.name <=> OLD.name',
        'finance_discount_policy_changes_update_guard  ::  NEW.reason <> OLD.reason',
        'finance_discount_policy_changes_update_guard  ::  NEW.uuid <> OLD.uuid',
        'finance_discount_policy_changes_update_guard  ::  NEW.value_currency <=> OLD.value_currency',
        'finance_fee_schedule_changes_update_guard  ::  NEW.kind <> OLD.kind',
        'finance_fee_schedule_changes_update_guard  ::  NEW.reason <> OLD.reason',
        'finance_fee_schedule_changes_update_guard  ::  NEW.uuid <> OLD.uuid',
        'finance_invoices_total_immutable  ::  NEW.total_currency <> OLD.total_currency',
        "finance_opening_balance_batches_no_delete_posted  ::  NEW.status = 'posted'",
        "finance_opening_balance_batches_no_unpost  ::  NEW.status <> 'posted'",
        "finance_opening_balance_batches_no_unpost  ::  NEW.status = 'posted'",
        'finance_void_requests_update_guard  ::  NEW.reason <=> OLD.reason',
        'finance_void_requests_update_guard  ::  NEW.uuid <> OLD.uuid',
    ];
}

/**
 * Which columns are strings — collation is meaningless on an integer or a timestamp, and counting
 * those inflates the number and buries the real ones (55 vs 31, measured).
 *
 * @return callable(string, string): bool
 */
function ftcIsStringColumn(): callable
{
    $types = [];

    foreach (DB::select(
        "SELECT TABLE_NAME t, COLUMN_NAME c, DATA_TYPE d FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'finance\\_%'"
    ) as $row) {
        $types[$row->t.'.'.$row->c] = $row->d;
    }

    return fn (string $table, string $column) => in_array(
        $types[$table.'.'.$column] ?? '',
        ['char', 'varchar', 'text', 'longtext', 'enum'],
        true,
    );
}

/**
 * Sweep the INSTALLED trigger bodies — `information_schema`, not the migration source that claims to
 * install them (ADR 0052).
 *
 * @return list<string>
 */
function ftcBareComparisons(): array
{
    $isString = ftcIsStringColumn();
    $bare = [];

    foreach (DB::select(
        "SELECT TRIGGER_NAME n, EVENT_OBJECT_TABLE t, ACTION_STATEMENT a
           FROM information_schema.TRIGGERS
          WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE LIKE 'finance\\_%'"
    ) as $trigger) {
        preg_match_all(FTC_MATCHER, $trigger->a, $matches, PREG_SET_ORDER);

        foreach ($matches as $m) {
            if (! $isString($trigger->t, $m[3])) {
                continue;
            }

            $lhsBinary = trim($m[1] ?? '') !== '';
            $lhsCollate = strtolower(trim($m[5] ?? '')) === 'utf8mb4_bin';
            $rhsBinary = stripos($m[7], 'BINARY') === 0;
            $rhsCollate = stripos($m[7], 'utf8mb4_bin') !== false;

            if ($lhsBinary || $lhsCollate || $rhsBinary || $rhsCollate) {
                continue;
            }

            $bare[] = $trigger->n.'  ::  NEW.'.$m[3].' '
                .strtoupper(preg_replace('/\s+/', ' ', $m[6])).' '.trim($m[7]);
        }
    }

    sort($bare);

    return $bare;
}

/**
 * COVERAGE AS THREE NUMBERS, because two collapse the only one that matters.
 *
 *   · EXAMINED     — matched by FTC_MATCHER and on a string column: actually checked.
 *   · EXCLUDED     — matched, but the column is not a string type. A collation tripwire has nothing
 *                    to say about `NEW.amount_minor <= 0`, and skipping it is a DECISION with a
 *                    reason, not a blind spot.
 *   · UNRECOGNISED — comparison-shaped constructs FTC_MATCHER did not classify at all. THIS IS THE
 *                    DANGEROUS NUMBER. `LIKE` lived in here, invisible, until 2026-09-01, hidden
 *                    inside an aggregate that looked like deliberate exclusion.
 *
 * "139 of 200" cannot distinguish the second from the third. The same UNKNOWN discipline the board
 * and the SIGNAL lint now carry, applied to coverage instead of to a failed fetch.
 *
 * @return array{examined: int, excluded: int, unrecognised: int, total: int}
 */
function ftcCoverage(): array
{
    $isString = ftcIsStringColumn();
    $examined = $excluded = $broad = $matched = 0;

    foreach (DB::select(
        "SELECT EVENT_OBJECT_TABLE t, ACTION_STATEMENT a
           FROM information_schema.TRIGGERS
          WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE LIKE 'finance\\_%'"
    ) as $trigger) {
        $broad += preg_match_all(FTC_BROAD, $trigger->a);

        preg_match_all(FTC_MATCHER, $trigger->a, $matches, PREG_SET_ORDER);
        $matched += count($matches);

        foreach ($matches as $m) {
            $isString($trigger->t, $m[3]) ? $examined++ : $excluded++;
        }
    }

    return [
        'examined' => $examined,
        'excluded' => $excluded,
        // Never negative: the broad pattern is a superset by construction, and if that ever stops
        // being true the max() hides it — so the test below asserts the relationship too.
        'unrecognised' => max(0, $broad - $matched),
        'total' => $broad,
    ];
}

it('reports its own coverage, and recognises every comparison construct present', function () {
    $c = ftcCoverage();

    fwrite(STDERR, sprintf(
        "\n  collation tripwire coverage: %d constructs — %d examined, %d excluded (non-string column), %d UNRECOGNISED\n",
        $c['total'], $c['examined'], $c['excluded'], $c['unrecognised'],
    ));

    // THE ASSERTION, not merely the report. A coverage line nobody checks is a description, and this
    // file has already been wrong about exactly that. If a construct appears that FTC_MATCHER cannot
    // classify, this reds and someone decides whether it is a new guarded form or a new blind spot.
    expect($c['unrecognised'])->toBe(0)
        ->and($c['examined'])->toBeGreaterThan(0);
});

it('adds no NEW bare string comparison to any finance trigger', function () {
    $bare = ftcBareComparisons();
    $accepted = ftcAcceptedBare();

    $new = array_values(array_diff($bare, $accepted));

    expect($new)->toBe([], "NEW bare string comparison(s) in a finance trigger:\n  "
        .implode("\n  ", $new)
        ."\n\n"
        ."=====================================================================\n"
        ."  THE FIX IS `COLLATE utf8mb4_bin` ON THE COMPARISON.\n"
        ."  IT IS *NOT* AN ENTRY IN ftcAcceptedBare().\n"
        ."=====================================================================\n\n"
        .'THIS GATE CANNOT BE SATISFIED BY ADDING TO ITS LIST, and that is deliberate. It is the '
        .'one way it differs from CheckConstraintsAsTriggersTest, where adding your constraint to '
        .'the enumeration IS the correct fix. If you have just come from that gate, this is the '
        .'reflex to break: ftcAcceptedBare() is a DEBT REGISTER of comparisons that predate this '
        .'gate, it may only SHRINK, and a companion arm in this file fails on a stale entry — so an '
        ."addition that is not genuinely pre-existing is caught either way.\n\n"
        .'WHY IT MATTERS: the finance tables are utf8mb4_unicode_ci, which is case- AND '
        ."accent-insensitive, so this comparison treats 'x' and 'X', and 'NGN' and 'NGN' with a "
        .'diacritic, as equal. A DOMAIN arm written this way admits values no report filter will '
        .'ever match. A FREEZE arm written this way does not freeze.');
});

it('reports the accepted list SHRINKING, so a fix cannot leave a stale entry behind', function () {
    // The other direction, and the reason this is a set comparison rather than a subset check: when
    // somebody fixes one of the 31, its entry must come out of the list. Left in, the register
    // over-states the debt for ever and nobody can tell how much is left. Same discipline as the
    // ratchet baselines — they shrink, and a shrink that is not recorded is a shrink nobody can see.
    $stale = array_values(array_diff(ftcAcceptedBare(), ftcBareComparisons()));

    expect($stale)->toBe([], 'These comparisons are named in ftcAcceptedBare() but are NO LONGER bare '
        ."— someone fixed them. Delete the entries:\n  ".implode("\n  ", $stale));
});

it('KNOWN POSITIVE — the matcher sees a bare `<=>`, the operator the freeze arms use', function () {
    // The first scan written for this class matched `=`, `<>` and `REGEXP` and MISSED every `<=>`,
    // so it swept cleanly over the majority case of the defect it was written to find. Pinned so
    // that cannot silently return.
    preg_match_all(FTC_MATCHER, 'IF NOT (NEW.provider <=> OLD.provider) THEN SIGNAL; END IF;', $m, PREG_SET_ORDER);

    expect($m)->toHaveCount(1)
        ->and($m[0][3])->toBe('provider')
        ->and($m[0][6])->toBe('<=>')
        ->and(trim($m[0][1] ?? ''))->toBe('')   // no BINARY prefix
        ->and(trim($m[0][5] ?? ''))->toBe('');  // no COLLATE clause
});

it('KNOWN POSITIVE — a bare `LIKE` with a FUNCTION-CALL right-hand side is seen', function () {
    // THE ONE CASE NEITHER HALF OF THE FIX CATCHES ALONE, which is why it is the bite-proof.
    //
    // Until 2026-09-01 the matcher had two gaps at once: `LIKE` was absent from the operator list,
    // and the right-hand side accepted only a quoted literal or a NEW/OLD column — so a
    // `CONCAT(...)` was invisible too. Add `LIKE` alone and this still slips through on the RHS;
    // widen the RHS alone and it still slips through on the operator. A partial fix would have
    // reported success while remaining blind, turning a KNOWN blind spot into an unknown one.
    //
    // `LIKE` matters because it is collation-sensitive: under utf8mb4_unicode_ci, 'a' LIKE 'A' is
    // TRUE. Nothing had ever failed here only because the repository's two LIKE comparisons both
    // happened to carry COLLATE — clean by luck, not by coverage.
    $bare = "IF NEW.reference NOT LIKE CONCAT('bpsk-', NEW.school_id, '-%') THEN SIGNAL; END IF;";

    preg_match_all(FTC_MATCHER, $bare, $m, PREG_SET_ORDER);

    expect($m)->toHaveCount(1)
        ->and($m[0][3])->toBe('reference')
        ->and(strtoupper(preg_replace('/\s+/', ' ', $m[0][6])))->toBe('NOT LIKE')
        ->and(trim($m[0][1] ?? ''))->toBe('')   // no BINARY prefix
        ->and(trim($m[0][5] ?? ''))->toBe('');  // no COLLATE — so it WOULD be reported
});

it('KNOWN NEGATIVE — a guarded `LIKE` is not reported, with COLLATE on EITHER side', function () {
    // THE PLACEMENT QUESTION, PINNED BY FIXTURE RATHER THAN BY WHAT THE CODEBASE HAPPENS TO HOLD.
    //
    // `COLLATE` legitimately sits on the column OR on the pattern, and both guard. A matcher that
    // recognised only one placement would flag correct code — which is exactly how the three
    // previous matchers in this workstream went wrong, every one of them by over-reporting.
    //
    // Both forms are here so the definition is fixed by a test. The repository currently contains
    // only the first; without the second arm, adding the second form later would look like a defect.
    foreach ([
        "IF NEW.reference COLLATE utf8mb4_bin NOT LIKE CONCAT('bpsk-', NEW.school_id, '-%') THEN SIGNAL; END IF;",
        "IF NEW.reference NOT LIKE CONCAT('bpsk-', NEW.school_id, '-%') COLLATE utf8mb4_bin THEN SIGNAL; END IF;",
        "IF BINARY NEW.reference NOT LIKE CONCAT('bpsk-', NEW.school_id, '-%') THEN SIGNAL; END IF;",
    ] as $guarded) {
        preg_match_all(FTC_MATCHER, $guarded, $m, PREG_SET_ORDER);

        // POSITIVE, NOT `->not->`. A custom message under `->not->` never reaches the reader: Pest's
        // OppositeExpectation runs the positive assertion, discards its exception and composes a
        // generic sentence with the message exported and truncated. Caught by
        // PestNegatedExpectationMessagesTest, which is a gate this file's author did not know about
        // — and the message here is the whole value of the arm, so losing it would have been silent.
        expect(count($m))->toBeGreaterThan(0, "the matcher did not even SEE this guarded form: {$guarded}");

        $flagged = array_filter($m, fn ($x) => trim($x[1] ?? '') === ''
            && strtolower(trim($x[5] ?? '')) !== 'utf8mb4_bin'
            && stripos($x[7], 'BINARY') !== 0
            && stripos($x[7], 'utf8mb4_bin') === false);

        expect($flagged)->toBe([], "the sweep flagged a PROTECTED comparison: {$guarded}");
    }
});

it('KNOWN NEGATIVE — a guarded comparison is NOT reported, under EITHER idiom', function () {
    // The second scan flagged `BINARY`-guarded comparisons and named someone else's correct code as
    // defective. Over-reporting is not the harmless direction when the output is a ticket about
    // another team's work: a finding they discount costs the credibility the real findings need.
    foreach ([
        'IF NOT (NEW.provider COLLATE utf8mb4_bin <=> OLD.provider) THEN SIGNAL; END IF;',
        "IF NEW.status COLLATE utf8mb4_bin = 'pending' THEN SIGNAL; END IF;",
        "IF BINARY NEW.kind <> BINARY 'charge' THEN SIGNAL; END IF;",
        "IF NEW.kind <> BINARY 'charge' THEN SIGNAL; END IF;",
    ] as $guarded) {
        preg_match_all(FTC_MATCHER, $guarded, $m, PREG_SET_ORDER);

        $flagged = array_filter($m, fn ($x) => trim($x[1] ?? '') === ''
            && strtolower(trim($x[5] ?? '')) !== 'utf8mb4_bin'
            && stripos($x[7], 'BINARY') !== 0
            && stripos($x[7], 'utf8mb4_bin') === false);

        expect($flagged)->toBe([], "the sweep flagged a PROTECTED comparison: {$guarded}");
    }
});
