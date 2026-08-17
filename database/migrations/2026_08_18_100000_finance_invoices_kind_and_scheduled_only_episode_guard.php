<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * INVOICES GAIN A KIND, AND THE ONE-PER-EPISODE GUARD CONSTRAINS ONLY THE SCHEDULED ONE.
 *
 * THE PROBLEM, MEASURED. A School cannot raise any charge outside the term's scheduled fees. The only
 * two writers of `LedgerEntryType::Charge` are `GenerateInvoice` and `PostOpeningBalanceBatch` (the
 * once-per-School cutover import), and there is no add-line-to-an-existing-invoice path —
 * `2026_07_19_120000`'s docblock says so and records it as a residual gap, because the invoice total
 * is snapshotted at creation and frozen by `finance_invoices_total_immutable`. So a damaged
 * appliance, a trip, a lost book or a late fee could only be billed by VOIDING the term invoice and
 * re-issuing it, which discards every payment allocation already made against it.
 *
 * THE SHAPE OF THE FIX. `finance_invoices` gains a KIND — `scheduled` (the term bill) vs
 * `supplementary` (anything raised outside the schedule). The set-based invariant from slice 2 is
 * re-keyed to constrain SCHEDULED invoices only; supplementary invoices are unconstrained — any
 * number, at any time, against the same episode. See `App\Finance\Enums\InvoiceKind` for the
 * ubiquitous-language half.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * 1 — THE COLUMN. `VARCHAR(16) NOT NULL`, and DELIBERATELY WITH NO DEFAULT once this migration is
 *     done. A writer that forgets `kind` must meet a loud **1364** (`ER_NO_DEFAULT_FOR_FIELD`), not
 *     a silently-wrong value: the whole point of the column is that it decides whether the
 *     double-billing guard applies, so a default would let an omission quietly pick a side.
 *
 *     That is the opposite decision from `finance_invoice_lines.kind` (`2026_07_21_120000`), which
 *     took `DEFAULT 'charge'` precisely because every pre-existing line WAS a charge and the default
 *     could not misclassify anything. The difference is that a line's kind carries meaning only;
 *     this column carries an INVARIANT, and an invariant that can be defaulted into is not one.
 *
 *     THE DEFAULT IS NEVERTHELESS USED AS A TRANSIENT, AND THAT IS NOT A CONTRADICTION.
 *     `ADD COLUMN … NOT NULL` with no default on a populated table does not fail on MySQL — it fills
 *     every existing row with the type's implicit default (`''`), which is outside the domain. DDL
 *     commits implicitly, so a backfill `UPDATE` issued afterwards is a SECOND transaction and there
 *     is a window — however short, and permanently if the migration dies between the two — in which
 *     live invoice rows hold a kind that is not a kind. Adding the column WITH `DEFAULT 'scheduled'`
 *     and dropping the default immediately afterwards removes that window entirely: every existing
 *     row is correct at the instant the column exists. The backfill `UPDATE` below is kept anyway,
 *     and is expected to affect 0 rows — it is the statement that makes the intent legible, and it
 *     is followed by a COUNT that refuses to continue if anything is not `scheduled`.
 *
 *     Backfilling to `scheduled` is correct BY CONSTRUCTION and not by assumption: before this
 *     migration the only invoice-creating path is `GenerateInvoice`, raising the enrollment
 *     episode's fees, which is exactly what `scheduled` means.
 *
 * 2 — THE RE-KEY, AS ONE `ALTER`, SO THE GUARD IS NEVER ABSENT. Slice 2 enforces "at most one ACTIVE
 *     invoice per enrollment episode" with a STORED generated column and a unique index:
 *
 *         active_enrollment_key = IF(status = 'issued', student_curriculum_id, NULL)
 *         UNIQUE (school_id, active_enrollment_key)
 *
 *     It becomes the same thing restricted to scheduled invoices:
 *
 *         active_enrollment_key = IF(status = 'issued' AND kind = 'scheduled', student_curriculum_id, NULL)
 *
 *     BOTH PROPERTIES SLICE 2 RECORDED ARE PRESERVED, and both are load-bearing:
 *
 *       (a) It stays GENERATED, not application-maintained, so no code path can forget to set or
 *           clear it — the invariant holds by construction rather than by discipline.
 *       (b) Voiding still recomputes it to NULL, and NULLs do not collide in a MySQL unique index,
 *           so the policy's "repeat = billed fresh" re-bill after a void still works. A naive
 *           UNIQUE(school_id, student_curriculum_id) would forbid that re-bill, because voided
 *           invoices are append-only and never leave the table. Raising a supplementary invoice now
 *           reaches NULL by the SECOND arm instead of the first, by exactly the same mechanism.
 *
 *     THE WINDOW, AND WHY THERE ISN'T ONE. Changing the expression of a STORED generated column
 *     requires the index over it to be dropped, and for the moment between the DROP and the re-ADD
 *     the double-billing guard does not exist. That window cannot be closed by holding both indexes
 *     at once — the OLD index keys on `status = 'issued'` alone, so while it stands a supplementary
 *     invoice collides with the episode's scheduled one, which is the very thing being fixed.
 *
 *     It IS closed by making the three changes ONE `ALTER TABLE` statement. MySQL applies DROP
 *     INDEX, MODIFY COLUMN and ADD UNIQUE within a single table rebuild, and MySQL 8.0's atomic DDL
 *     commits or rolls back that rebuild as a unit. There is therefore no committed state in which
 *     the table has the new expression and no unique index over it.
 *
 *     `DB::transaction()` IS NOT USED AND WOULD BE THEATRE. DDL commits implicitly on MySQL — every
 *     `ALTER TABLE` and `CREATE TRIGGER` below ends any open transaction — so wrapping this method
 *     would produce the APPEARANCE of atomicity across statements while providing none. One
 *     statement is the only real atomicity available here, which is why the re-key is one statement.
 *     The migration's remaining steps are individually idempotent and are re-assertable by re-running.
 *
 *     COLLATION, STATED RATHER THAN LEFT TO BE REDISCOVERED. `kind = 'scheduled'` in the generated
 *     expression is evaluated under the table's `utf8mb4_unicode_ci`, so it would also match
 *     `'Scheduled'`. That is the SAFE direction — a case variant would still be CONSTRAINED by the
 *     unique index rather than escaping it — and it is unreachable anyway, because the domain trigger
 *     below pins membership with `COLLATE utf8mb4_bin` and refuses the variant on the way in. The
 *     expression matches `2026_07_19_120000`'s `status = 'issued'`, which is bare for the same reason.
 *
 * 3 — `kind` IS IMMUTABLE, VIA ITS OWN TRIGGER RATHER THAN AN ARM OF THE EXISTING ONE.
 *
 *     WHY IT MUST BE IMMUTABLE AT THE DATABASE. `finance_invoices` legitimately allows UPDATE (the
 *     status flips issued → void). If `kind` were mutable the whole invariant is bypassable in two
 *     statements: flip the episode's live scheduled invoice to `supplementary` — its generated key
 *     recomputes to NULL, freeing the episode's slot while that invoice is still issued and still
 *     collecting payments — then issue a SECOND scheduled invoice. That is precisely the
 *     double-billing slice 2's index exists to prevent, reached around it.
 *
 *     WHICH MECHANISM, AND WHY THIS ONE. The choice was between adding `kind` to
 *     `finance_invoices_total_immutable`'s denied set and adding a trigger alongside it. A SEPARATE
 *     TRIGGER was chosen, for two reasons. First, that trigger's NAME states what it guards — the
 *     money snapshot, F6, `total = SUM(lines)` — and folding an unrelated column into it would make
 *     the name a lie and the message misleading (an operator refused for editing `kind` would be
 *     told the invoice total is snapshotted at creation). Second, it is the precedent this schema
 *     already set: `2026_08_17_100000` added `BEFORE UPDATE` triggers to five tables that already
 *     carried one rather than folding them in, on the stated ground that "a table's existing guard
 *     keeps its own name, its own message and its own tests". Multiple triggers per table with the
 *     same timing and event are documented legal from MySQL 5.7.2 (DOCUMENTED, not measured here —
 *     no 5.7 was available; the same standing as every other trigger in this schema, per
 *     `docs/finance/check-constraints-on-mysql-5-7.md`).
 *
 * 4 — THE DOMAIN OF `kind` IS A TRIGGER, NOT A `CHECK`. Production is MySQL 5.7 and PARSES AND
 *     DISCARDS `CHECK` silently — `docs/finance/check-constraints-on-mysql-5-7.md`, which ships in
 *     the tree. A `CHECK (kind IN (…))` here would be enforced locally, absent on the server that
 *     holds the money, and would read green in every test. So: `BEFORE INSERT` and `BEFORE UPDATE`,
 *     `SIGNAL SQLSTATE '45000'`, per `2026_08_17_100000`.
 *
 *     `COLLATE utf8mb4_bin` IS LOAD-BEARING, same reasoning as `2026_08_17_100000`'s rule 7. Under
 *     the table's default `utf8mb4_unicode_ci`, `kind IN ('scheduled','supplementary')` also admits
 *     `'SCHEDULED'` and `'Supplementary'` — values no report filter, no Eloquent cast and no enum
 *     `from()` would match, so the guard would read green while admitting values nobody wrote code
 *     for. `App\Finance\Enums\InvoiceKind` is the source of the two literals; they are spelled once
 *     here because a trigger body cannot read PHP.
 *
 *     TRIGGER ORDER, AND WHAT IT MEANS FOR THE MESSAGE AN OPERATOR SEES. Among triggers with the
 *     same timing and event and no FOLLOWS/PRECEDES, MySQL fires them in CREATION order (measured on
 *     8.0.43 in `2026_08_17_100000`). `finance_invoices_total_immutable` was created in
 *     `2026_07_19_120000` and therefore fires first on any UPDATE; it is indifferent to `kind`. Of
 *     the two added here, the DOMAIN `_bu` is created before the IMMUTABILITY one, so:
 *
 *         UPDATE … SET kind = 'garbage'        → refused by the domain trigger (not a valid kind)
 *         UPDATE … SET kind = 'supplementary'  → passes the domain trigger, refused as IMMUTABLE
 *         UPDATE … SET status = 'void'         → both pass (kind unchanged); the void proceeds
 *
 *     Both refusals are 1644. The domain `_bu` is not redundant with the immutability trigger even
 *     though every kind CHANGE is refused: it is the arm that would still bite if the immutability
 *     rule were ever relaxed, and it costs one comparison on a table whose UPDATE path is a
 *     once-per-invoice status flip.
 *
 * NEITHER MESSAGE CONTAINS A SINGLE QUOTE, AND THAT IS NOT A STYLE CHOICE. MySQL accepts an escaped
 * apostrophe in a `SIGNAL` message at `CREATE` time and then STORES THE BODY WITH THE ESCAPE
 * STRIPPED — the trigger works in place, and `mysqldump` emits SQL that will not re-import. Verified
 * again here, on 8.0.43, on this migration's own first draft: both the possessive in the
 * immutability message and the quoted enum VALUES in the domain message came back from
 * `information_schema.TRIGGERS` unescaped.
 *
 * `TriggerBodiesAreDumpSafeTest` caught the immutability one and NOT the domain one, and the reason
 * is worth recording: that test's invariant is an ODD count of single quotes. The possessive left
 * five and was flagged; `''scheduled'' or ''supplementary''` left TWELVE — balanced, therefore green,
 * and still `MESSAGE_TEXT = 'kind must be exactly 'scheduled' or 'supplementary'.'`, which is not
 * valid SQL. Balance is necessary and not sufficient. Both messages here now carry no quote at all,
 * which is the only shape that survives the round trip, and the domain message names the two values
 * as bare words.
 *
 * `MESSAGE_TEXT` IS CAPPED AT 128 CHARACTERS, MEASURED HERE THE HARD WAY. The immutability
 * message was first written as a full explanation of the attack it refuses; MySQL 8.0.43 answered
 * **1648** (`ER_COND_ITEM_TOO_LONG`, "Data too long for condition item 'MESSAGE_TEXT'") at SIGNAL
 * time — not at `CREATE TRIGGER` time, which succeeded and read back with the correct shape. So a
 * trigger whose message is too long installs cleanly, passes an `information_schema` read-back, and
 * then raises the WRONG ERROR CODE on every write it was supposed to refuse. It still refuses the
 * write, which is why this is a subtle failure rather than an open hole: any test asserting only
 * `toThrow(QueryException::class)` would have stayed green. Both messages here are under the cap,
 * and the reasoning that did not fit lives in this docblock, where it belongs.
 *
 * VERIFIED BY SHAPE, NOT BY EXIT CODE (ADR 0052). An `ALTER TABLE` that returned success is not
 * evidence that the right index exists over the right expression — a mis-scoped generated column and
 * a non-unique index are both created just as successfully. So after the re-key this migration reads
 * `information_schema` back and refuses to record itself unless the index is present, UNIQUE, and
 * covers exactly `(school_id, active_enrollment_key)` in that order, and the column is STORED
 * GENERATED over an expression naming `kind`. Each `CREATE TRIGGER` is likewise read back for name,
 * timing and event.
 */
return new class extends Migration
{
    private const TABLE = 'finance_invoices';

    private const INDEX = 'finance_invoices_active_enrollment_unique';

    private const KEY_COLUMN = 'active_enrollment_key';

    private const DOMAIN_STEM = 'finance_invoices_kind_domain';

    private const IMMUTABLE_TRIGGER = 'finance_invoices_kind_immutable';

    /**
     * The expression before and after. Kept as constants so `up()` and `down()` cannot drift, and so
     * the read-back below asserts against the same text the ALTER used.
     */
    private const KEY_EXPRESSION_AFTER = "IF(status = 'issued' AND kind = 'scheduled', student_curriculum_id, NULL)";

    private const KEY_EXPRESSION_BEFORE = "IF(status = 'issued', student_curriculum_id, NULL)";

    public function up(): void
    {
        // ── 1. The column. DEFAULT 'scheduled' only for the instant it takes to populate the
        //    existing rows correctly; dropped immediately below so an omission is a loud 1364.
        DB::statement(
            'ALTER TABLE '.self::TABLE." ADD COLUMN kind VARCHAR(16) NOT NULL DEFAULT 'scheduled' AFTER status"
        );

        // Expected to affect 0 rows — the ADD already populated them. Kept because the intent
        // ("every invoice that exists today is a term bill") should be legible in the migration and
        // not only in its docblock, and because it is what makes the assertion below meaningful.
        DB::statement('UPDATE '.self::TABLE." SET kind = 'scheduled' WHERE kind <> 'scheduled'");

        $stray = (int) DB::selectOne(
            'SELECT COUNT(*) AS c FROM '.self::TABLE." WHERE kind COLLATE utf8mb4_bin <> 'scheduled'"
        )->c;

        if ($stray !== 0) {
            throw new RuntimeException(
                "Refusing to continue: {$stray} row(s) in ".self::TABLE.' hold a kind other than '
                ."'scheduled' after the backfill. Every invoice raised before this migration is a "
                .'term bill by construction, so this means either the backfill did not run or '
                .'something wrote this column already. The generated key below would silently treat '
                .'those rows as unconstrained.'
            );
        }

        DB::statement('ALTER TABLE '.self::TABLE.' ALTER COLUMN kind DROP DEFAULT');

        // ── 2. The re-key, as ONE statement. See the docblock: this is the only atomicity MySQL
        //    offers here, and it is what keeps the double-billing guard from ever being absent.
        DB::statement(
            'ALTER TABLE '.self::TABLE.'
                DROP INDEX '.self::INDEX.',
                MODIFY COLUMN '.self::KEY_COLUMN.' BIGINT UNSIGNED
                    GENERATED ALWAYS AS ('.self::KEY_EXPRESSION_AFTER.') STORED,
                ADD UNIQUE '.self::INDEX.' (school_id, '.self::KEY_COLUMN.')'
        );

        $this->assertReKeyShape(self::KEY_EXPRESSION_AFTER, 'kind');

        // ── 3 & 4. The domain arms first, then immutability — see the docblock's ordering note.
        $domainBody =
            "IF NEW.kind COLLATE utf8mb4_bin NOT IN ('scheduled', 'supplementary') THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'finance_invoices.kind must be exactly scheduled or supplementary (lowercase, exact).';
             END IF;";

        $this->installTrigger(self::DOMAIN_STEM.'_bi', 'INSERT', $domainBody);
        $this->installTrigger(self::DOMAIN_STEM.'_bu', 'UPDATE', $domainBody);

        $this->installTrigger(
            self::IMMUTABLE_TRIGGER,
            'UPDATE',
            "IF NEW.kind COLLATE utf8mb4_bin <> OLD.kind COLLATE utf8mb4_bin THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'finance_invoices.kind is fixed at creation: UPDATE of kind is denied (it would free the active-invoice slot for this episode).';
             END IF;"
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::IMMUTABLE_TRIGGER);
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::DOMAIN_STEM.'_bu');
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::DOMAIN_STEM.'_bi');

        // Restore slice 2's expression and the column, in the same one-statement shape — so the
        // rollback leg of bin/quality-clean-db is also never left with an unguarded table.
        DB::statement(
            'ALTER TABLE '.self::TABLE.'
                DROP INDEX '.self::INDEX.',
                MODIFY COLUMN '.self::KEY_COLUMN.' BIGINT UNSIGNED
                    GENERATED ALWAYS AS ('.self::KEY_EXPRESSION_BEFORE.') STORED,
                ADD UNIQUE '.self::INDEX.' (school_id, '.self::KEY_COLUMN.')'
        );

        $this->assertReKeyShape(self::KEY_EXPRESSION_BEFORE, 'status');

        DB::statement('ALTER TABLE '.self::TABLE.' DROP COLUMN kind');
    }

    /**
     * ADR 0052 — read the shape back rather than trusting the ALTER's exit code.
     *
     * Three separate facts, because a green on one says nothing about the others: the generated
     * column is STORED and its expression names the column it is supposed to key on; the index
     * exists and is UNIQUE (`NON_UNIQUE = 0`); and it covers exactly (school_id, key) in that
     * ORDER — a two-column unique index with the columns swapped constrains the same set, but one
     * with an extra or missing column does not, and `SHOW INDEX` reports either just as happily.
     *
     * @param  string  $mustName  a token the generation expression must contain, so the read-back
     *                            fails if the MODIFY silently kept the old expression
     */
    private function assertReKeyShape(string $expression, string $mustName): void
    {
        $column = DB::selectOne(
            'SELECT EXTRA, GENERATION_EXPRESSION FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [self::TABLE, self::KEY_COLUMN],
        );

        if ($column === null || ! str_contains(strtoupper((string) $column->EXTRA), 'STORED GENERATED')) {
            throw new RuntimeException(
                self::TABLE.'.'.self::KEY_COLUMN.' is not a STORED GENERATED column after the ALTER. '
                .'An application-maintained column reintroduces the "someone forgets to clear it" '
                .'failure mode slice 2 exists to remove.'
            );
        }

        if (! str_contains((string) $column->GENERATION_EXPRESSION, $mustName)) {
            throw new RuntimeException(
                self::TABLE.'.'.self::KEY_COLUMN.' generation expression does not mention ['.$mustName
                .']: got ['.(string) $column->GENERATION_EXPRESSION.'], expected the sense of ['
                .$expression.']. The MODIFY reported success without changing the expression, so the '
                .'unique index below would still constrain the wrong set of rows.'
            );
        }

        $index = DB::select(
            'SELECT NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
              ORDER BY SEQ_IN_INDEX',
            [self::TABLE, self::INDEX],
        );

        if ($index === []) {
            throw new RuntimeException(
                'Index ['.self::INDEX.'] does not exist after the ALTER returned success. Refusing to '
                .'record this migration: the double-billing guard is absent.'
            );
        }

        if ((int) $index[0]->NON_UNIQUE !== 0) {
            throw new RuntimeException(
                'Index ['.self::INDEX.'] exists but is NOT UNIQUE. A non-unique index over the '
                .'generated key permits exactly the duplicate it was created to refuse.'
            );
        }

        $covered = array_map(static fn ($row) => $row->COLUMN_NAME, $index);

        if ($covered !== ['school_id', self::KEY_COLUMN]) {
            throw new RuntimeException(
                'Index ['.self::INDEX.'] covers ['.implode(', ', $covered).'], expected exactly '
                .'[school_id, '.self::KEY_COLUMN.'] in that order.'
            );
        }
    }

    /**
     * Create one trigger idempotently, then PROVE it is there — name, timing and event — from
     * `information_schema`. Pattern and reasoning from `2026_08_17_100000`.
     */
    private function installTrigger(string $name, string $event, string $body): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.$name);
        DB::unprepared(
            "CREATE TRIGGER {$name} BEFORE {$event} ON ".self::TABLE.'
             FOR EACH ROW
             BEGIN
                '.$body.'
             END'
        );

        $read = DB::selectOne(
            'SELECT ACTION_TIMING AS timing, EVENT_MANIPULATION AS event, EVENT_OBJECT_TABLE AS tbl
               FROM information_schema.TRIGGERS
              WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            [$name],
        );

        if ($read === null) {
            throw new RuntimeException(
                "Trigger [{$name}] does not exist after CREATE TRIGGER returned success. Refusing to "
                .'record this migration as applied: the guard it claims to install is absent, and on '
                .'5.7 there is no CHECK behind it.'
            );
        }

        if ($read->timing !== 'BEFORE' || $read->event !== $event || $read->tbl !== self::TABLE) {
            throw new RuntimeException(
                "Trigger [{$name}] exists with the wrong shape: got {$read->timing} {$read->event} on "
                ."{$read->tbl}, expected BEFORE {$event} on ".self::TABLE.'. A trigger with the right '
                .'name and the wrong timing or event fires on writes nobody guarded and misses the '
                .'ones they did.'
            );
        }
    }
};
