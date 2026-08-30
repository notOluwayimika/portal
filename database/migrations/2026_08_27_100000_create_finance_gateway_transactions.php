<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE RECORD OF ONE CHECKOUT ATTEMPT AT AN ONLINE PAYMENT PROVIDER — the table `origin = 'gateway'`
 * has had nowhere to point at since 2026_08_25_100000 admitted that origin.
 *
 * WHY IT IS A SEPARATE TABLE AND NOT COLUMNS ON `finance_payments`. A payment is money the school
 * HAS, and `finance_payments` is append-only for exactly that reason. A checkout attempt is a
 * conversation with a third party: it is born knowing nothing, may end in nothing, may end hours
 * after it began, and may be reported to us twice because no provider promises a webhook is
 * delivered once. None of those states can live on a row that may never be updated. So the mutable
 * conversation lives here, and the immutable money is written ONCE — at the single moment this row
 * reaches `success` — into the table that can never take it back.
 *
 * ── THE IDEMPOTENCY STORY, WHICH IS THE WHOLE POINT OF THE INDEXES ───────────────────────────────
 *
 * Two unique indexes, and they answer two DIFFERENT questions. Conflating them is how a duplicate
 * webhook becomes a duplicate payment.
 *
 *   `finance_gateway_transactions_provider_reference_unique` on (provider, reference)
 *       ONE ATTEMPT PER REFERENCE. `reference` is OURS — the string this system generates and sends
 *       to the provider at initiation, and the string the provider echoes back on every webhook and
 *       every verify call. Scoped by `provider` because the namespace is the provider's, not the
 *       world's, and NOT scoped by school: the reference crosses the wire to a third party, so it
 *       must be unique across the whole estate or two schools' checkouts collide at the provider.
 *
 *   `finance_gateway_transactions_payment_unique` on (payment_id)
 *       ONE PAYMENT PER ATTEMPT, ENFORCED BY THE DATABASE. This is the index the webhook handler is
 *       to be made idempotent BY — a conditional `UPDATE … SET payment_id = ? WHERE id = ? AND
 *       payment_id IS NULL` inside the same transaction as the `RecordPayment` call, whose affected
 *       row count is the answer. Two concurrent deliveries of the same event cannot both see a NULL
 *       and both write, because the second one's UPDATE matches nothing and its transaction rolls
 *       the payment back with it. MySQL admits many NULLs in a UNIQUE index, so the pending rows —
 *       which are most of them — do not collide with each other.
 *
 * NEITHER IS A `SELECT` FOLLOWED BY AN `INSERT`. A check-then-insert has a window between the two
 * halves, and the window is precisely where a retried webhook lands; that is what "idempotent by
 * unique-index collision" means and why the constraint is in the schema rather than in a handler.
 *
 * ── WHAT IT REFERS TO ────────────────────────────────────────────────────────────────────────────
 *
 * `invoice_id`, REQUIRED, one invoice per attempt. The alternative — an attempt that settles several
 * invoices at once — is section 11 decision 5 and it is NOT decided; requiring one invoice is the
 * shape the designed flow actually has (a guardian sees what a ward owes and settles an invoice) and
 * it is the low-regret choice, because unlike `finance_payments` this table is NOT append-only and
 * carries no money of its own, so widening the grain later is an ordinary migration of ordinary
 * rows. Guessing the wider grain now would put a nullable column with no writer into live schema.
 *
 * NO `student_id`, deliberately, though every sibling finance table has one. It would be derivable
 * from the invoice by a single join and therefore a second place for the same fact to be stored —
 * and the only way a denormalised copy earns its place is a read that cannot afford the join, which
 * no consumer of this table has. `school_id` is the exception and is NOT the same case: it is the
 * isolation boundary, uniform on every `finance_` table by convention (arch rule 5), and the
 * composite foreign keys below are what stop it diverging from the parent it was copied from.
 *
 * `payment_id`, NULLABLE. A pending, failed or abandoned attempt names no payment because there is
 * none. The composite FK pairs it with `school_id` so a settled attempt can never name another
 * school's money.
 *
 * `paid_at`, THE PROVIDER'S INSTANT, not ours. `finance_payments.received_at` is append-only and can
 * never be corrected, and a payment made on Friday and delivered to us on Monday belongs to Friday —
 * RecordPayment's own docblock reasons exactly this way about a counter payment. This column is
 * where that Friday is kept, so the writer of the payment has the right date to hand rather than the
 * date the webhook happened to arrive.
 *
 * `provider`, NAMING THE PROVIDER, which is the opposite of the decision `finance_payments.origin`
 * took — and the two are consistent rather than in tension. `origin` names the CATEGORY because it
 * is written into append-only money rows a second provider could never migrate. This table is
 * mutable, provider-specific by its nature (`reference` and `provider_reference` are meaningless
 * without knowing whose namespace they are in), and naming the provider here is what lets `origin`
 * stay category-shaped there.
 *
 * ── WHY THE GUARDS ARE TRIGGERS AND THE CHECK IS ALMOST DECORATION ───────────────────────────────
 *
 * Production is MySQL 5.7.23, which PARSES AND IGNORES `CHECK` entirely (2026_08_17_100000's
 * measurement, restated by 2026_08_25_100000). Anything load-bearing therefore has to be a trigger,
 * or it is enforced on the developer's machine and absent on the one holding real money — the worst
 * possible arrangement, because the green is local and the gap is remote.
 *
 * THIS FILE ADDS NO `CHECK` AT ALL, and the first version of it added two — for "uniformity" with
 * the sixteen `%_currency_shape` constraints that already exist. That was wrong twice over, and both
 * halves are now MEASURED on a real MySQL **5.7.23** rather than reasoned about (2026-08-28):
 *
 *   1. IT WOULD HAVE BROKEN THE DEPLOY. `assertShape()` reads its constraints back out of
 *      `information_schema.TABLE_CONSTRAINTS`. On 5.7.23 the `ALTER … ADD CONSTRAINT … CHECK`
 *      RETURNS SUCCESS — no 1064, the grammar accepts it — and then
 *      `TABLE_CONSTRAINTS` reports **0 rows** for it and `SHOW CREATE TABLE` omits it entirely. So
 *      the read-back would have found the constraint missing and thrown, AFTER every `CREATE TABLE`
 *      and `CREATE TRIGGER` in this file had already committed (DDL commits implicitly). Production
 *      would be left with both tables and all six triggers present and this migration UNRECORDED —
 *      `migrate` re-run dies on 1050 and there is no `down()` to run because nothing was recorded.
 *      Local `bin/quality-clean-db` cannot see it: on 8.0.43 the constraints do materialise.
 *
 *   2. AND IT WOULD HAVE ENFORCED NOTHING ANYWHERE. Same probe, same server: after that ALTER
 *      "succeeded", `INSERT … VALUES ('ngn')` was ACCEPTED. On 8.0.43 the constraint is real but
 *      unreachable, because a `CHECK` is evaluated AFTER every `BEFORE` trigger and the insert guard
 *      below already refuses the same value first. Inert on one server, shadowed on the other —
 *      exactly the object `CheckConstraintsAsTriggersTest` was written to keep out of this schema.
 *
 * The currency shape lives in the insert guard, which is the copy that is live on both servers. It
 * is written ONCE, in one place, and that place is a trigger.
 *
 * SIX TRIGGERS, THREE ON EACH TABLE:
 *
 *   `_insert_guard`  — the status domain (four values, case-sensitive), a positive amount, the
 *                      currency shape, and the fee pairing. A checkout for nothing, or for `-1`, is
 *                      not a state the provider can produce and not one this system should store.
 *
 *   `_update_guard`  — identity and the amount immutable from insert; `success` terminal FOR STATUS;
 *                      no return to `pending`; every fact reported by the provider WRITE-ONCE; and
 *                      the status domain and fee pairing again, because an UPDATE puts a value in a
 *                      column just as an INSERT does. Read that method's docblock before changing
 *                      it — the first version of this guard froze the whole row at `success`, which
 *                      would have made the settlement columns below physically unwritable while the
 *                      suite stayed green.
 *
 *   `_no_delete`     — an attempt is retained for audit, settled or not. The failed and abandoned
 *                      rows are the entire input to the discrepancy report; deleting them is
 *                      deleting the evidence of the thing being reconciled.
 *
 *   The events table carries `_insert_guard`, `_update_guard` and `_no_delete` — append-only with
 *   exactly ONE door, a single redaction per row, because a raw payload that can be freely edited is
 *   not evidence and a payload that can NEVER be purged is indefinite retention of payer PII decided
 *   by omission. See createEventsTable()'s RETENTION paragraph.
 *
 * ── THE SETTLEMENT DATA IS HERE BECAUSE IT CANNOT BE CAPTURED LATER (boundary §5, §8.2, §14) ──────
 *
 * `fee_minor` / `fee_currency`, `settlement_reference` and `settled_at` record what the provider
 * kept, which payout the money arrived in, and when. Each is reported ONCE, in an event that has
 * passed by the time anyone asks. The child table `finance_gateway_transaction_events` holds the raw
 * bodies of every delivery, plural — a single `payload` column would destroy each earlier delivery
 * as the next arrived, which is the precise loss §8.2 exists to prevent, arriving through the
 * mechanism meant to prevent it.
 *
 * ALL OF IT IS DATA ONLY. Nothing here reads the fee, reconciles a payout or reports a discrepancy;
 * those are §6 steps 6 and 7. The columns land now because a column added later is NULL for every
 * transaction that happened before it existed, permanently.
 *
 * ── TWO RULES THIS FILE FOLLOWS, BOTH PAID FOR ON THIS BRANCH ───────────────────────────────────
 *
 * Neither is style. Each was violated here, measured by a cold review, and each is now pinned by a
 * tripwire in `GatewayTransactionSchemaTest` so the next table cannot repeat it quietly.
 *
 * **BOTH DOORS.** Every domain rule enforced at INSERT is enforced at UPDATE, or is documented as
 * deliberately insert-only with a reason. A rule at one door is not a rule — it is a rule with a
 * hole shaped like the other door, and the hole is invisible because the guarded door keeps biting.
 * It happened twice in one commit: the currency shape was insert-only (so `SET amount_currency =
 * 'ngn'` was accepted, and `Money`'s constructor then throws on READ, making the row's amount
 * unreadable by every consumer), and the events `source` domain was insert-only. That is why the
 * shared predicates live in their own methods — `statusDomainBody()`, `feePairingBody()`,
 * `currencyShapeBody()`, `sourceDomainBody()` — and both guards CALL them. A predicate written twice
 * is a predicate that will come to differ; a predicate written once cannot.
 *
 * The insert-only exceptions, stated rather than left to be inferred: `amount_minor > 0` and "a
 * delivery must carry a payload" are insert-only BECAUSE the update guards freeze those columns
 * outright, so no update can reach a violating value. `redacted_at IS NULL` is insert-only for the
 * mirror reason — its update counterpart is the redaction door itself.
 *
 * **BINARY COLLATION ON EVERY STRING COMPARISON THAT GUARDS A VALUE.** Under the tables' default
 * `utf8mb4_unicode_ci`, `=` and `<=>` are case- AND accent-insensitive, so `'ngn'`, `'ṄGN'` and
 * `'NGŇ'` all compare equal to `'NGN'` — and a freeze arm written `NOT (NEW.provider <=> OLD.provider)`
 * therefore permits `paystack` → `PAYSTACK`. `2026_08_17_100000` records this class already, for the
 * DOMAIN arms: omitting `COLLATE utf8mb4_bin` from ONE arm is the quiet failure, because the others
 * keep biting and the guard still looks alive. What this branch adds to that lesson is that it
 * applies to the FREEZE and WRITE-ONCE arms too, not only to domain arms — a column frozen under a
 * case-insensitive collation is not frozen, and on an evidence table that means the string stored
 * stops being provably the string that arrived.
 *
 * `MESSAGE_TEXT` IS CAPPED AT 128 CHARACTERS and every sentence below is counted, not eyeballed.
 * Past it, 8.0.43 does not truncate: `SIGNAL` itself fails with 1648/HY000, so the row is still
 * refused but the guard stops speaking its own refusal and every caller classifying on the driver
 * code gets the wrong answer (measured on this repo by 2026_08_25_100000).
 *
 * VERIFIED BY SHAPE, NOT BY EXIT CODE (ADR 0052). `CREATE TABLE`, `ALTER TABLE` and `CREATE TRIGGER`
 * returning success are not evidence that the right table, index, constraint or trigger exists — a
 * mis-timed trigger and a mis-columned index are created just as successfully. Everything this
 * migration claims to create is read back out of `information_schema` and the migration throws
 * rather than record itself unless the shape is what it asked for.
 */
return new class extends Migration
{
    private const TABLE = 'finance_gateway_transactions';

    private const INSERT_GUARD = 'finance_gateway_transactions_insert_guard';

    private const UPDATE_GUARD = 'finance_gateway_transactions_update_guard';

    private const NO_DELETE = 'finance_gateway_transactions_no_delete';

    private const EVENTS_TABLE = 'finance_gateway_transaction_events';

    private const EVENTS_UPDATE_GUARD = 'finance_gateway_transaction_events_update_guard';

    private const EVENTS_NO_DELETE = 'finance_gateway_transaction_events_no_delete';

    private const EVENTS_INSERT_GUARD = 'finance_gateway_transaction_events_insert_guard';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();

            $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();

            // Composite (invoice_id, school_id) FK (F3) — references finance_invoices(id, school_id),
            // so an attempt's School can never diverge from the invoice it is settling. Declared as a
            // bare column plus the composite, NOT foreignId()->constrained(), which would add a second
            // single-column FK saying less than this one already says.
            $table->unsignedBigInteger('invoice_id');

            // Whose namespace `reference` and `provider_reference` live in. See the docblock for why
            // this names the provider while finance_payments.origin names the category.
            $table->string('provider');

            // OURS, generated at initiation and echoed back by the provider on every message about
            // this attempt. Unique per provider — the idempotency key of the whole flow.
            $table->string('reference');

            // THEIRS, learned at verification. Nullable because it does not exist until the provider
            // has answered, and unique per provider for the same reason ours is: two of our attempts
            // must never claim the same transaction at their end.
            $table->string('provider_reference')->nullable();

            // What the payer was asked for (ADR 0038 — integer minor units + ISO-4217).
            $table->bigInteger('amount_minor');
            $table->char('amount_currency', 3);

            // GatewayTransactionStatus. Every row is born pending; the guards below own the domain.
            $table->string('status')->default('pending');

            // The PROVIDER'S instant of payment — the value finance_payments.received_at is to be
            // taken from, because that column is append-only and Friday's money is Friday's.
            $table->timestamp('paid_at')->nullable();

            // Why the provider says it failed, in the provider's words. For the bursar following up
            // and for the discrepancy report; never parsed, never branched on.
            $table->string('failure_reason')->nullable();

            // The guardian who started the checkout. Nullable: a verify-on-return or a webhook may be
            // the first thing this system sees, and an attempt with no signed-in initiator is a real
            // state, not a defect to be papered over with a placeholder user.
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();

            // The money this attempt produced, once it produced any. Composite FK with school_id, so a
            // settled attempt can never name another school's payment.
            $table->unsignedBigInteger('payment_id')->nullable();

            // ── SETTLEMENT (boundary §5, §8.2, §14) ─────────────────────────────────────────────
            //
            // WHAT THE PROVIDER KEPT. A gateway does not hand over what the payer paid — it hands
            // over the payment minus its fee, days later, in a batch. Every one of these three is
            // reported ONCE, by the provider, at a moment that has passed by the time anyone asks;
            // §8.2's whole reason for putting them in scope now is that they CANNOT BE RECOVERED
            // AFTERWARDS. A column added later is a column that is NULL for every transaction that
            // happened before it existed, permanently, and no amount of later work fixes that.
            //
            // NULLABLE, AND THAT IS NOT OPTIONALITY. They are unknown at initiation and unknown at
            // success; settlement is a separate later event. The write-once rule in the update guard
            // is what makes NULL mean "not reported yet" rather than "possibly overwritten".
            //
            // The fee is money and takes the ADR 0038 pair. Its currency is pinned to the amount's
            // by the guards — a fee in a different currency from the payment it was taken out of is
            // not a fee this system can reason about.
            $table->bigInteger('fee_minor')->nullable();
            $table->char('fee_currency', 3)->nullable();

            // The provider's identifier for the PAYOUT this transaction was settled in — the string
            // a bursar matches against the bank statement line, which carries the batch and not the
            // individual payment. Without it, a settled transaction cannot be tied to the credit
            // that actually arrived, and reconciliation is guesswork over dates and amounts.
            $table->string('settlement_reference')->nullable();

            // When the provider says it settled. NOT when we noticed, and not `paid_at` — a payment
            // collected on Friday and settled the following Tuesday has two different true dates,
            // and the discrepancy report needs both to say which leg is late.
            $table->timestamp('settled_at')->nullable();

            $table->timestamps();

            $table->foreign(['invoice_id', 'school_id'], 'finance_gateway_transactions_invoice_school_foreign')
                ->references(['id', 'school_id'])->on('finance_invoices')->restrictOnDelete();

            $table->foreign(['payment_id', 'school_id'], 'finance_gateway_transactions_payment_school_foreign')
                ->references(['id', 'school_id'])->on('finance_payments')->restrictOnDelete();

            // ONE ATTEMPT PER REFERENCE, and ONE PAYMENT PER ATTEMPT. See the docblock: these answer
            // two different questions and the second is the one the webhook is made idempotent by.
            $table->unique(['provider', 'reference'], 'finance_gateway_transactions_provider_reference_unique');
            $table->unique(['provider', 'provider_reference'], 'finance_gateway_transactions_provider_ref_unique');
            $table->unique(['payment_id'], 'finance_gateway_transactions_payment_unique');

            // The reconciliation read: one school's unsettled attempts.
            $table->index(['school_id', 'status'], 'finance_gateway_transactions_school_status_index');
        });

        // The parent key the events table's composite FK references (the F3 idiom —
        // `finance_invoices_id_school_unique` and `finance_payments_id_school_unique` exist for
        // exactly this, 2026_07_19_110001:33-34). Without it the child's (transaction, school) FK
        // cannot be created at all.
        DB::statement(
            'ALTER TABLE '.self::TABLE.' ADD UNIQUE finance_gateway_transactions_id_school_unique (id, school_id)'
        );

        $this->createEventsTable();

        $this->installTrigger(self::INSERT_GUARD, 'INSERT', $this->insertGuardBody());
        $this->installTrigger(self::UPDATE_GUARD, 'UPDATE', $this->updateGuardBody());
        $this->installTrigger(self::NO_DELETE, 'DELETE', $this->noDeleteBody());

        $this->assertShape();
    }

    /**
     * Drops the triggers, then the table — which takes the CHECK, the foreign keys and every index
     * with it. Nothing else in the schema was altered, so nothing else needs reversing.
     *
     * NAMING THE RESIDUAL HONESTLY: `finance_payments` keeps the `gateway` arm of its origin pairing
     * after this rolls back, so a gateway payment remains WRITABLE with nothing left to describe
     * where it came from. That is correct and deliberate — the origin arm is 2026_08_25_100000's to
     * own and reversing someone else's migration from this one is how two files come to disagree
     * about a rule — but it is a state to recognise rather than be surprised by. Reachable only by
     * rolling this migration back on an environment that has already written gateway payments, which
     * no environment has: production is five migrations behind this one and its finance tables are
     * empty.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::EVENTS_INSERT_GUARD);
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::EVENTS_UPDATE_GUARD);
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::EVENTS_NO_DELETE);
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::NO_DELETE);
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::UPDATE_GUARD);
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::INSERT_GUARD);

        // The CHILD FIRST — it holds the foreign key into the parent, so dropping the parent while
        // it stands is 1217 and the rollback aborts half-done.
        Schema::dropIfExists(self::EVENTS_TABLE);
        Schema::dropIfExists(self::TABLE);
    }

    /**
     * The status domain, as one heredoc, so the INSERT and UPDATE spellings of it cannot drift.
     *
     * `COALESCE(…, 0)` for the same reason the origin pairing needs it: a NULL status makes every arm
     * NULL, `NULL OR NULL OR …` is NULL, `NOT NULL` is NULL — which is not TRUE, so a bare
     * `IF NOT (…)` lets a NULL straight through. The column is NOT NULL today; this is the belt
     * behind a brace that is holding, and it survives someone relaxing the column.
     *
     * `COLLATE utf8mb4_bin` ON EVERY ARM. Under the table's utf8mb4_unicode_ci, `status = 'success'`
     * also matches `'Success'` and `'SUCCESS'` — case variants every `status = 'success'` report
     * filter would ALSO match, so the guard would read green while admitting values nobody wrote a
     * filter for. Omitting it from ONE arm is the quiet failure: the others keep biting, so the
     * guard still looks alive.
     */
    private function statusDomainBody(): string
    {
        return <<<'SQL'
            IF NOT COALESCE(
                   NEW.status COLLATE utf8mb4_bin = 'pending'
                OR NEW.status COLLATE utf8mb4_bin = 'success'
                OR NEW.status COLLATE utf8mb4_bin = 'failed'
                OR NEW.status COLLATE utf8mb4_bin = 'abandoned', 0) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'finance_gateway_transactions.status must be pending, success, failed or abandoned.';
            END IF;
            SQL;
    }

    /**
     * THE CURRENCY SHAPE, SHARED BY BOTH DOORS — extracted for the reason the class docblock's
     * BOTH DOORS section gives. It was insert-only for one commit, and the cold review measured the
     * consequence: with the `CHECK` gone (correctly) and no shape arm on UPDATE, `SET amount_currency
     * = 'ngn'` was ACCEPTED, and `Money`'s constructor then throws on read, so the row's amount is
     * unreadable by any consumer. The rule now lives in one method and both guards call it.
     */
    private function currencyShapeBody(): string
    {
        return <<<'SQL'
            IF NEW.amount_currency COLLATE utf8mb4_bin NOT REGEXP '^[A-Z]{3}$' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'finance_gateway_transactions.amount_currency must be three upper-case letters (ISO-4217).';
            END IF;
            SQL;
    }

    /** The status domain, the fee pairing and the currency shape — all shared — plus a positive amount. */
    private function insertGuardBody(): string
    {
        return $this->statusDomainBody()."\n".$this->feePairingBody()."\n".$this->currencyShapeBody()."\n".<<<'SQL'
            IF NEW.amount_minor <= 0 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'finance_gateway_transactions.amount_minor must be greater than zero: nothing is not a checkout.';
            END IF;
            SQL;
    }

    /**
     * THE FEE PAIRING, shared by both guards because a rule enforced at one door only is not a rule.
     *
     * BOTH HALVES OR NEITHER, and the currency must be the AMOUNT'S. A fee of 1500 with no currency
     * is not a money value (ADR 0038 — the pair IS the value), and a fee denominated differently
     * from the payment it was deducted from cannot be subtracted from it: `Money::minus` throws on a
     * currency mismatch, so the mismatch would surface as a 500 on the reconciliation screen rather
     * than as the refusal it should have been at the write.
     *
     * A ZERO FEE IS LEGITIMATE and a negative one is not — some providers waive the fee on some
     * channels, and a waived fee is 0, reported. Note the difference from `amount_minor`, where zero
     * is refused: nobody checks out for nothing, but plenty of transactions settle at no charge.
     */
    private function feePairingBody(): string
    {
        return <<<'SQL'
            IF (NEW.fee_minor IS NULL) <> (NEW.fee_currency IS NULL) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'finance_gateway_transactions: fee_minor and fee_currency are one value; set both or neither.';
            END IF;
            IF NEW.fee_minor IS NOT NULL AND NEW.fee_minor < 0 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'finance_gateway_transactions.fee_minor may not be negative; a waived fee is zero.';
            END IF;
            IF NEW.fee_currency IS NOT NULL
               AND NEW.fee_currency COLLATE utf8mb4_bin <> NEW.amount_currency COLLATE utf8mb4_bin THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'finance_gateway_transactions.fee_currency must match amount_currency.';
            END IF;
            SQL;
    }

    /**
     * WHAT MAY MOVE ON THIS ROW AND WHAT MAY NOT.
     *
     * ── A CORRECTION TO THE FIRST VERSION OF THIS GUARD, WRITTEN DOWN BECAUSE IT WAS NEARLY SHIPPED ──
     *
     * The first version made `success` terminal ABSOLUTELY: any UPDATE of a settled row was refused.
     * That was wrong, and wrong in the direction that quietly loses data rather than the direction
     * that fails loudly. SETTLEMENT HAPPENS AFTER SUCCESS — that is what settlement IS. The provider
     * collects on Friday and pays out on Tuesday, and `fee_minor`, `settlement_reference` and
     * `settled_at` are all reported in that later event. A guard that froze the row at `success`
     * would have made the three columns boundary §5 requires PHYSICALLY UNWRITABLE, on a table whose
     * whole justification (§8.2) is capturing data that cannot be recovered afterwards. The suite
     * would have stayed green: nothing existed yet to write them.
     *
     * SO TERMINALITY IS NARROWED TO WHAT IT WAS ACTUALLY FOR — stopping a replayed webhook from
     * re-settling — and the mechanism is WRITE-ONCE rather than freeze-the-row:
     *
     *   · `success` is terminal FOR STATUS. A settled attempt may not become failed, abandoned or
     *     pending. This is the arm that makes a duplicate delivery harmless.
     *   · Every LEARNED FACT is write-once: once non-NULL, `provider_reference`, `paid_at`,
     *     `payment_id`, `failure_reason`, the fee pair, `settlement_reference` and `settled_at` may
     *     never be rewritten. NULL → value is the only transition each of them has.
     *
     *     `payment_id` IS IN THAT LIST AND IS NOT PROVIDER-REPORTED — it is ours, and a reader who
     *     takes "provider-reported" as the rule's scope will wrongly assume it is excluded. It needs
     *     the protection MORE than the others, for a sharper reason: `UNIQUE (payment_id)` stops two
     *     ROWS naming one payment and says nothing about one row going value → NULL → a different
     *     value. Step 4's idempotency is a compare-and-swap on `payment_id IS NULL`, so that
     *     predicate has to be a ONE-WAY DOOR or a replayed delivery could unlink and relink and the
     *     compare-and-swap would hand out a second settlement. The write-once arm is what closes it;
     *     the UNIQUE alone does not, and `payment_id_is_a_one_way_door` is the test that pins it.
     *   · Identity and the amount are immutable outright, from insert.
     *
     * NULL → VALUE IS THE WHOLE DIFFERENCE, and it is what makes a NULL in these columns MEAN
     * something: "not reported yet", never "possibly overwritten by a later delivery". A
     * reconciliation that cannot trust that distinction cannot use the column at all.
     *
     * `<=>` FOR THE NULLABLE COLUMNS and `<>` for the NOT NULL ones. `NULL <> NULL` is NULL, not
     * FALSE, so a plain `<>` on a nullable column can never fire — the write-once rules would have
     * been wallpaper in exactly the cases they exist for. `NOT (a <=> b)` is the null-safe spelling.
     * Nothing here DECLAREs a variable, so the #95 variable-collation trap does not arise: every
     * comparison is NEW.x against OLD.x, the same column, the same collation.
     *
     * THE STATUS ARM IS FIRST, and the order is load-bearing rather than stylistic: a replayed
     * webhook that tries to move a settled row should be refused for being settled, which is the
     * true reason, rather than for whichever column it happened to touch on the way.
     */
    private function updateGuardBody(): string
    {
        return $this->statusDomainBody()."\n".$this->feePairingBody()."\n".$this->currencyShapeBody()."\n".<<<'SQL'
            IF OLD.status COLLATE utf8mb4_bin = 'success' AND NEW.status COLLATE utf8mb4_bin <> 'success' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'finance_gateway_transactions: a settled attempt is final; its status may not change.';
            END IF;
            IF NEW.status COLLATE utf8mb4_bin = 'pending' AND OLD.status COLLATE utf8mb4_bin <> 'pending' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'finance_gateway_transactions: status may not return to pending once it has left it.';
            END IF;
            IF NEW.id <> OLD.id
                OR NEW.school_id <> OLD.school_id
                OR NEW.invoice_id <> OLD.invoice_id
                OR NEW.uuid COLLATE utf8mb4_bin <> OLD.uuid
                OR NOT (NEW.created_at <=> OLD.created_at)
                OR NOT (NEW.provider COLLATE utf8mb4_bin <=> OLD.provider)
                OR NOT (NEW.reference COLLATE utf8mb4_bin <=> OLD.reference)
                OR NEW.amount_minor <> OLD.amount_minor
                OR NOT (NEW.amount_currency COLLATE utf8mb4_bin <=> OLD.amount_currency) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'finance_gateway_transactions: id, school, invoice, provider, reference, amount, created_at frozen.';
            END IF;
            IF (OLD.provider_reference   IS NOT NULL AND NOT (NEW.provider_reference   COLLATE utf8mb4_bin <=> OLD.provider_reference))
               OR (OLD.paid_at              IS NOT NULL AND NOT (NEW.paid_at              <=> OLD.paid_at))
               OR (OLD.payment_id           IS NOT NULL AND NOT (NEW.payment_id           <=> OLD.payment_id))
               OR (OLD.failure_reason       IS NOT NULL AND NOT (NEW.failure_reason       COLLATE utf8mb4_bin <=> OLD.failure_reason))
               OR (OLD.fee_minor            IS NOT NULL AND NOT (NEW.fee_minor            <=> OLD.fee_minor))
               OR (OLD.fee_currency         IS NOT NULL AND NOT (NEW.fee_currency         COLLATE utf8mb4_bin <=> OLD.fee_currency))
               OR (OLD.settlement_reference IS NOT NULL AND NOT (NEW.settlement_reference COLLATE utf8mb4_bin <=> OLD.settlement_reference))
               OR (OLD.settled_at           IS NOT NULL AND NOT (NEW.settled_at           <=> OLD.settled_at)) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'finance_gateway_transactions: a fact learned about this attempt is written once, never rewritten.';
            END IF;
            SQL;
    }

    /**
     * THE RAW DELIVERIES — boundary §5's "raw webhook payloads", PLURAL, and the plural is why this
     * is a child table rather than a `payload` column on the row.
     *
     * ONE COLUMN CANNOT HOLD THEM. A transaction receives several messages: `charge.success`, then
     * a verify response when the payer returns, then a settlement event days later. A single JSON
     * column holds the last one and silently destroys the others on each write — which is the exact
     * failure §8.2 names, arriving through the mechanism meant to prevent it. It also collides with
     * the parent's write-once rules: the settlement delivery arrives long after `success`.
     *
     * APPEND-ONLY, by the Constitution §15C idiom (`_no_update` / `_no_delete` triggers). A raw
     * payload that can be edited is not evidence, and evidence is the only thing this table is for:
     * a dispute six months from now is answered by what the provider actually sent, not by what this
     * system concluded from it.
     *
     * IT RECORDS REJECTED DELIVERIES TOO — that is what `source` is for and why nothing here asserts
     * the payload was trusted. A delivery whose signature failed verification is exactly the one an
     * investigation wants to read.
     *
     * ── RETENTION, DECIDED HERE RATHER THAN BY OMISSION ─────────────────────────────────────────
     *
     * A payload carries PAYER PII: the customer's email, often a name, the card BIN and last four,
     * sometimes an IP. Putting that in an append-only table and saying nothing about retention is
     * how indefinite retention of NDPA-relevant data becomes a schema's default — a decision nobody
     * took, arrived at by silence.
     *
     * THE COST OF RETROFITTING, AT THE STRENGTH IT ACTUALLY HOLDS. An earlier draft of this paragraph
     * said retrofitting "means dropping guards on a live table holding that data". That is FALSE and
     * is corrected here rather than left for someone to cite: it is an `ADD COLUMN` plus a trigger
     * swap, and `installTrigger()` below already performs exactly that swap idempotently, with no
     * data migration and no window in which the table is unguarded. The true argument is weaker and
     * still sufficient — retrofitting is cheap but requires someone to NOTICE the gap first, and
     * shipping the door now makes the retention policy a code change against a schema that already
     * permits it rather than a schema change against live money data.
     *
     * SO THE FULL PAYLOAD IS KEPT — a live dispute is answered by what the provider actually sent,
     * and §7's "the payer succeeded and our handler threw" is diagnosed from exactly the fields a
     * write-time redaction would have discarded — AND `redacted_at` is the one door out.
     *
     * REDACTION HAS TO PROVE ITSELF, and the first version of this guard did not make it. It required
     * `redacted_at` to move and said NOTHING about the payload, so
     * `UPDATE … SET redacted_at = NOW()` alone was ACCEPTED — and the already-redacted arm then
     * refused every subsequent UPDATE. That is the worst outcome available: the payer PII stays in
     * the row, permanently unredactable, unreachable through `_no_delete`, and REPORTED AS HANDLED by
     * the very query (`WHERE redacted_at IS NOT NULL`) someone would run to demonstrate compliance. A
     * control that certifies itself is worse than no control, because it stops anyone looking.
     *
     * THE REDACTED FORM IS ONE DEFINED VALUE — `payload IS NULL` — and not merely "different from
     * before". "Different" admits a payload edited into something else entirely, which would leave
     * `redacted_at IS NOT NULL` meaning "somebody changed this" rather than "the body is gone". With
     * NULL, the two columns are locked together in both directions: the insert guard refuses a
     * delivery with no payload, and the update guard refuses a redaction that leaves one. So
     * `redacted_at IS NOT NULL` ⟺ `payload IS NULL`, and the compliance query is load-bearing.
     *
     * The rest of the door: a raw row cannot be edited, a redacted row cannot be edited again, a row
     * cannot be inserted pre-redacted, and a redaction may change NOTHING else — `id` included, which
     * the first version also omitted while its own test was named "and nothing else".
     *
     * WHAT IS STILL OWED, named so it is not mistaken for done. There is no schedule, no command and
     * no stated period — this ships the ABILITY only, and the policy is a decision for the data owner.
     *
     * AND `redacted_at` REACHES ONE ROW IN ONE DATABASE, which is the part of retention this table
     * cannot answer at all. A payload redacted on production remains in every dump taken before the
     * redaction, in the binlog, and in the production copy on a developer machine — which is where
     * this project's ordinary workflow puts it, since findings are derived against a copy of live.
     * Row-level redaction is forward-only and has no reach into any of those. That is the larger
     * retention surface, it extends well past this table, and it is ticketed rather than built here:
     * docs/handoff/tickets/gateway-payload-retention.md.
     *
     * ONE MORE OPEN QUESTION IN THE SAME TICKET: `redacted_at` records WHEN and nothing else. On a
     * table where redaction is the only evidence-destruction path there is, "who" and "why" are most
     * of the value of the control, and a `redacted_by` / reason pair is a decision deliberately not
     * taken here rather than an oversight.
     *
     * THE GAP THIS DOES NOT CLOSE, named rather than left to be discovered: a webhook whose
     * reference matches NO transaction has nowhere to go, because every row here hangs off a parent
     * and a school. An unmatched-delivery log is a separate concern and belongs with the webhook
     * handler (§6 step 4), not here.
     */
    private function createEventsTable(): void
    {
        Schema::create(self::EVENTS_TABLE, function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();

            // Denormalised from the parent, uniform across every finance_ table (arch rule 5), and
            // paired with the parent id in the composite FK below so it cannot diverge from it.
            $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();
            $table->unsignedBigInteger('gateway_transaction_id');

            // Where this arrived from — a provider-initiated webhook, or a verify call we made.
            // Domain-guarded like the parent's status, and for the same reason.
            $table->string('source');

            // The provider's own event name (`charge.success`, `transfer.success`). Nullable: a
            // verify RESPONSE is not an event and has no name, and inventing one would put a string
            // this system made up into a column whose whole point is that the provider wrote it.
            $table->string('event')->nullable();

            // THE BODY, VERBATIM. Never parsed here, never trusted here — stored.
            //
            // IT CARRIES PAYER PII. A Paystack delivery routinely contains the customer's email,
            // often a name, the card BIN and last four, and sometimes an IP. That is why the column
            // below exists: see the RETENTION paragraph in createEventsTable()'s docblock.
            $table->json('payload')->nullable();

            // WHEN THIS ROW'S PAYLOAD WAS REDACTED, and the reason this migration ships a redaction
            // path on day one rather than the day someone asks for one.
            //
            // The table is append-only. On an append-only table, "we will add retention later" means
            // dropping guards on a live table holding payer PII — so the capability has to exist
            // BEFORE there is anything to purge, or it effectively never exists. This column is that
            // capability, and it is deliberately the ONLY door: the update guard refuses every
            // UPDATE except a redaction, refuses a second redaction, and refuses a row born
            // pre-redacted. NULL means the payload is exactly what arrived.
            //
            // NOTHING REDACTS ANYTHING YET, and that is correct for this change — no schedule, no
            // command, no policy on how long is long enough. What ships here is the ABILITY, so that
            // decision is a code change against a schema that already permits it rather than a
            // migration against production money data. The policy itself is still owed.
            $table->timestamp('redacted_at')->nullable();

            $table->timestamps();

            $table->foreign(['gateway_transaction_id', 'school_id'], 'finance_gateway_transaction_events_txn_school_foreign')
                ->references(['id', 'school_id'])->on(self::TABLE)->restrictOnDelete();

            // The investigation read: every delivery for one transaction, oldest first.
            $table->index(['gateway_transaction_id', 'id'], 'finance_gateway_transaction_events_txn_index');
        });

        // APPEND-ONLY WITH EXACTLY ONE DOOR — see the RETENTION paragraph above. The order of the
        // arms is the rule: already-redacted is refused first (so a second redaction is told the
        // true reason), then any UPDATE that is not a redaction, then the columns a redaction may
        // not touch. `updated_at` moves and `created_at` does not — when the row arrived is part of
        // the evidence; when it was redacted is `redacted_at`.
        $this->installTrigger(self::EVENTS_UPDATE_GUARD, 'UPDATE', $this->sourceDomainBody()."\n".<<<'SQL'
            IF OLD.redacted_at IS NOT NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'finance_gateway_transaction_events: this delivery is already redacted; redaction happens once.';
            END IF;
            IF NEW.redacted_at IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'finance_gateway_transaction_events is a raw record: UPDATE is denied except a redaction.';
            END IF;
            IF NEW.payload IS NOT NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'finance_gateway_transaction_events: a redaction must clear the payload; redacted_at alone is a lie.';
            END IF;
            IF NEW.id <> OLD.id
                OR NEW.school_id <> OLD.school_id
                OR NEW.gateway_transaction_id <> OLD.gateway_transaction_id
                OR NEW.uuid COLLATE utf8mb4_bin <> OLD.uuid
                OR NOT (NEW.source COLLATE utf8mb4_bin <=> OLD.source)
                OR NOT (NEW.event COLLATE utf8mb4_bin <=> OLD.event)
                OR NOT (NEW.created_at <=> OLD.created_at) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'finance_gateway_transaction_events: a redaction may clear the payload and change nothing else.';
            END IF;
            SQL, self::EVENTS_TABLE);

        $this->installTrigger(self::EVENTS_NO_DELETE, 'DELETE', <<<'SQL'
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                'finance_gateway_transaction_events is retained for audit: DELETE is denied.';
            SQL, self::EVENTS_TABLE);

        $this->installTrigger(self::EVENTS_INSERT_GUARD, 'INSERT', $this->sourceDomainBody()."\n".<<<'SQL'
            IF NEW.redacted_at IS NOT NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'finance_gateway_transaction_events: a delivery is stored as it arrived; it cannot be born redacted.';
            END IF;
            IF NEW.payload IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'finance_gateway_transaction_events: a delivery must carry the body it arrived with.';
            END IF;
            SQL, self::EVENTS_TABLE);
    }

    /** 69 characters, counted. */
    private function noDeleteBody(): string
    {
        return <<<'SQL'
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                'finance_gateway_transactions is retained for audit: DELETE is denied.';
            SQL;
    }

    /**
     * THE SOURCE DOMAIN, SHARED BY BOTH DOORS. It was insert-only for one commit, and the cold review
     * measured the consequence: `source` was frozen on UPDATE by a comparison under the column's own
     * case-INSENSITIVE collation, so `webhook` → `WEBHOOK` was accepted — a value the insert guard
     * refuses, reachable on the update path, under cover of a redaction. The events table could hold
     * a value the schema claims is impossible, and every reader trusting the domain inherits it.
     *
     * Two independent things had to be wrong for that: the domain rule at one door only (BOTH DOORS,
     * class docblock) and a bare string comparison (BINARY COLLATION, same place). Both are closed
     * here, and both are now pinned by tripwires in GatewayTransactionSchemaTest.
     */
    private function sourceDomainBody(): string
    {
        return <<<'SQL'
            IF NOT COALESCE(NEW.source COLLATE utf8mb4_bin = 'webhook'
                         OR NEW.source COLLATE utf8mb4_bin = 'verify', 0) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'finance_gateway_transaction_events.source must be webhook or verify.';
            END IF;
            SQL;
    }

    /**
     * Create one trigger, then PROVE it is there — name, timing, event and table — from
     * `information_schema`. Idempotent so the rollback/re-up leg of `bin/quality-clean-db` re-asserts
     * rather than 1359s on a trigger of the same name.
     */
    private function installTrigger(string $name, string $event, string $body, ?string $table = null): void
    {
        $table ??= self::TABLE;

        DB::unprepared('DROP TRIGGER IF EXISTS '.$name);
        DB::unprepared(
            "CREATE TRIGGER {$name} BEFORE {$event} ON {$table}
             FOR EACH ROW
             BEGIN
                {$body}
             END"
        );

        $read = DB::selectOne(
            'SELECT ACTION_TIMING AS timing, EVENT_MANIPULATION AS event, EVENT_OBJECT_TABLE AS tbl
               FROM information_schema.TRIGGERS
              WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            [$name],
        );

        if ($read === null) {
            throw new RuntimeException(
                "Trigger [{$name}] does not exist after CREATE TRIGGER returned success. Refusing to record "
                .'this migration as applied: on 5.7 there is no CHECK behind these guards.'
            );
        }

        if ($read->timing !== 'BEFORE' || $read->event !== $event || $read->tbl !== $table) {
            throw new RuntimeException(
                "Trigger [{$name}] exists with the wrong shape: got {$read->timing} {$read->event} on {$read->tbl}, "
                ."expected BEFORE {$event} on {$table}. A trigger with the right name and the wrong "
                .'timing or event fires on writes nobody guarded and misses the ones they did.'
            );
        }
    }

    /**
     * Read the whole shape back — every column, every index this migration named, the two composite
     * foreign keys and the CHECK — and refuse to record the migration unless it is what was asked
     * for. ADR 0052: a statement that returned success is not evidence of a shape.
     *
     * The indexes are checked by NAME AND COLUMN SET, not by name alone. A unique index carrying the
     * wrong columns is the failure that matters here — `UNIQUE (payment_id, school_id)` would be
     * created just as successfully as `UNIQUE (payment_id)` and would let one attempt settle twice.
     */
    private function assertShape(): void
    {
        $columns = collect(DB::select(
            'SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [self::TABLE],
        ))->pluck('name')->all();

        $missing = array_diff([
            'id', 'uuid', 'school_id', 'invoice_id', 'provider', 'reference', 'provider_reference',
            'amount_minor', 'amount_currency', 'status', 'paid_at', 'failure_reason',
            'initiated_by_user_id', 'payment_id',
            // Boundary §5 / §8.2 — the settlement facts, which cannot be recovered if they are not
            // captured at the moment they are reported. Asserted here so a future ALTER that drops
            // one fails at the migration rather than at a reconciliation nobody can complete.
            'fee_minor', 'fee_currency', 'settlement_reference', 'settled_at',
            'created_at', 'updated_at',
        ], $columns);

        if ($missing !== []) {
            throw new RuntimeException(
                self::TABLE.' is missing columns after CREATE TABLE returned success: '.implode(', ', $missing)
            );
        }

        $this->assertIndex('finance_gateway_transactions_provider_reference_unique', ['provider', 'reference'], true);
        $this->assertIndex('finance_gateway_transactions_provider_ref_unique', ['provider', 'provider_reference'], true);
        $this->assertIndex('finance_gateway_transactions_payment_unique', ['payment_id'], true);
        $this->assertIndex('finance_gateway_transactions_school_status_index', ['school_id', 'status'], false);
        $this->assertIndex('finance_gateway_transactions_id_school_unique', ['id', 'school_id'], true);

        $constraints = collect(DB::select(
            'SELECT CONSTRAINT_NAME AS name FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [self::TABLE],
        ))->pluck('name')->all();

        $missingConstraints = array_diff([
            'finance_gateway_transactions_invoice_school_foreign',
            'finance_gateway_transactions_payment_school_foreign',
        ], $constraints);

        if ($missingConstraints !== []) {
            throw new RuntimeException(
                self::TABLE.' is missing constraints after ALTER TABLE returned success: '
                .implode(', ', $missingConstraints).'. A composite (child, school_id) foreign key is what '
                .'stops a row naming another school\'s invoice or payment.'
            );
        }

        $eventColumns = collect(DB::select(
            'SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [self::EVENTS_TABLE],
        ))->pluck('name')->all();

        $missingEventColumns = array_diff(
            ['id', 'uuid', 'school_id', 'gateway_transaction_id', 'source', 'event', 'payload', 'redacted_at', 'created_at', 'updated_at'],
            $eventColumns,
        );

        if ($missingEventColumns !== []) {
            throw new RuntimeException(
                self::EVENTS_TABLE.' is missing columns after CREATE TABLE returned success: '
                .implode(', ', $missingEventColumns).'. This table is the only record of what the provider '
                .'actually sent, and boundary §8.2 exists because that cannot be recovered afterwards.'
            );
        }

        // The payload must be JSON, not a string. A TEXT column accepts a truncated or malformed body
        // silently; JSON refuses it at the write, which is the difference between evidence and a
        // string that looks like evidence.
        $payloadType = DB::scalar(
            'SELECT DATA_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [self::EVENTS_TABLE, 'payload'],
        );

        if ($payloadType !== 'json') {
            throw new RuntimeException(
                self::EVENTS_TABLE.'.payload is ['.(string) $payloadType.'], expected [json].'
            );
        }

        // NULLABLE IS LOAD-BEARING, not incidental: NULL is the defined redacted form, and the two
        // guards lock it to `redacted_at` in both directions. A NOT NULL payload would make the
        // redaction the guard demands impossible to perform.
        $payloadNullable = DB::scalar(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [self::EVENTS_TABLE, 'payload'],
        );

        if ($payloadNullable !== 'YES') {
            throw new RuntimeException(
                self::EVENTS_TABLE.'.payload must be NULLABLE — NULL is the redacted form, and the update '
                .'guard requires a redaction to clear it. NOT NULL makes redaction unperformable.'
            );
        }

        $eventConstraints = collect(DB::select(
            'SELECT CONSTRAINT_NAME AS name FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [self::EVENTS_TABLE],
        ))->pluck('name')->all();

        if (! in_array('finance_gateway_transaction_events_txn_school_foreign', $eventConstraints, true)) {
            throw new RuntimeException(
                self::EVENTS_TABLE.' is missing its composite (gateway_transaction_id, school_id) foreign key. '
                .'Without it a delivery can be filed against another school\'s transaction.'
            );
        }
    }

    /**
     * One index, by name, column set AND uniqueness. `SEQ_IN_INDEX` orders the columns, because
     * (provider, reference) and (reference, provider) are different indexes with the same members.
     *
     * @param  list<string>  $expected
     */
    private function assertIndex(string $name, array $expected, bool $unique): void
    {
        $rows = DB::select(
            'SELECT COLUMN_NAME AS col, NON_UNIQUE AS non_unique FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
              ORDER BY SEQ_IN_INDEX',
            [self::TABLE, $name],
        );

        $actual = array_map(fn ($row) => $row->col, $rows);

        if ($actual !== $expected) {
            throw new RuntimeException(
                "Index [{$name}] on ".self::TABLE.' has columns ['.implode(', ', $actual)
                .'], expected ['.implode(', ', $expected).']. An index with the right name and the wrong '
                .'columns enforces a different rule from the one this migration claims to add.'
            );
        }

        if ($unique && (int) $rows[0]->non_unique !== 0) {
            throw new RuntimeException(
                "Index [{$name}] on ".self::TABLE.' is not UNIQUE. It is the idempotency key of the gateway '
                .'flow; non-unique, it permits exactly the duplicate it exists to refuse.'
            );
        }
    }
};
