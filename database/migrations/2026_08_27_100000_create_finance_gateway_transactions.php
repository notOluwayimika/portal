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
 * So the three real rules are triggers, and the `CHECK` added here carries ONLY the currency shape —
 * added for uniformity with the ten columns 2026_08_01_120000 constrained, and named to its
 * convention (`{table}_{column}_shape`) so a future sweep over `%_currency_shape` finds this column
 * with the others rather than silently missing it. The same rule is ALSO in the insert guard, which
 * is the copy that is actually live on production. That duplication is deliberate and is the one
 * place in this file where a rule is written twice; the trigger is the authority.
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
 *   The events table carries `_no_update`, `_no_delete` and `_source_guard` — append-only, because a
 *   raw payload that can be edited is not evidence.
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

    private const CURRENCY_CHECK = 'finance_gateway_transactions_amount_currency_shape';

    private const FEE_CURRENCY_CHECK = 'finance_gateway_transactions_fee_currency_shape';

    private const EVENTS_TABLE = 'finance_gateway_transaction_events';

    private const EVENTS_NO_UPDATE = 'finance_gateway_transaction_events_no_update';

    private const EVENTS_NO_DELETE = 'finance_gateway_transaction_events_no_delete';

    private const EVENTS_SOURCE_GUARD = 'finance_gateway_transaction_events_source_guard';

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

        // Uniformity with the ten columns 2026_08_01_120000 constrained, under that migration's
        // naming and its COLLATE utf8mb4_bin (without which 'ngn' matches ^[A-Z]{3}$ and the
        // constraint is a false all-clear). NOT the live enforcement — see the docblock; production
        // is 5.7.23 and ignores CHECK. The insert guard carries the same rule.
        DB::statement(
            'ALTER TABLE '.self::TABLE.' ADD CONSTRAINT '.self::CURRENCY_CHECK."
             CHECK (amount_currency IS NULL OR amount_currency COLLATE utf8mb4_bin REGEXP '^[A-Z]{3}\$')"
        );

        DB::statement(
            'ALTER TABLE '.self::TABLE.' ADD CONSTRAINT '.self::FEE_CURRENCY_CHECK."
             CHECK (fee_currency IS NULL OR fee_currency COLLATE utf8mb4_bin REGEXP '^[A-Z]{3}\$')"
        );

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
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::EVENTS_SOURCE_GUARD);
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::EVENTS_NO_DELETE);
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::EVENTS_NO_UPDATE);
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

    /** The status domain and the fee pairing, plus a positive amount and the currency shape. */
    private function insertGuardBody(): string
    {
        return $this->statusDomainBody()."\n".$this->feePairingBody()."\n".<<<'SQL'
            IF NEW.amount_minor <= 0 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'finance_gateway_transactions.amount_minor must be greater than zero: nothing is not a checkout.';
            END IF;
            IF NEW.amount_currency COLLATE utf8mb4_bin NOT REGEXP '^[A-Z]{3}$' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'finance_gateway_transactions.amount_currency must be three upper-case letters (ISO-4217).';
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
     *   · Every fact learned FROM THE PROVIDER is write-once: once non-NULL, `provider_reference`,
     *     `paid_at`, `payment_id`, `failure_reason`, the fee pair, `settlement_reference` and
     *     `settled_at` may never be rewritten. NULL → value is the only transition each of them has.
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
        return $this->statusDomainBody()."\n".$this->feePairingBody()."\n".<<<'SQL'
            IF OLD.status COLLATE utf8mb4_bin = 'success' AND NEW.status COLLATE utf8mb4_bin <> 'success' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'finance_gateway_transactions: a settled attempt is final; its status may not change.';
            END IF;
            IF NEW.status COLLATE utf8mb4_bin = 'pending' AND OLD.status COLLATE utf8mb4_bin <> 'pending' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'finance_gateway_transactions: status may not return to pending once it has left it.';
            END IF;
            IF NEW.school_id <> OLD.school_id
                OR NEW.invoice_id <> OLD.invoice_id
                OR NEW.uuid <> OLD.uuid
                OR NOT (NEW.provider <=> OLD.provider)
                OR NOT (NEW.reference <=> OLD.reference)
                OR NEW.amount_minor <> OLD.amount_minor
                OR NOT (NEW.amount_currency <=> OLD.amount_currency) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'finance_gateway_transactions: school, invoice, provider, reference and amount are immutable.';
            END IF;
            IF (OLD.provider_reference   IS NOT NULL AND NOT (NEW.provider_reference   <=> OLD.provider_reference))
               OR (OLD.paid_at              IS NOT NULL AND NOT (NEW.paid_at              <=> OLD.paid_at))
               OR (OLD.payment_id           IS NOT NULL AND NOT (NEW.payment_id           <=> OLD.payment_id))
               OR (OLD.failure_reason       IS NOT NULL AND NOT (NEW.failure_reason       <=> OLD.failure_reason))
               OR (OLD.fee_minor            IS NOT NULL AND NOT (NEW.fee_minor            <=> OLD.fee_minor))
               OR (OLD.fee_currency         IS NOT NULL AND NOT (NEW.fee_currency         <=> OLD.fee_currency))
               OR (OLD.settlement_reference IS NOT NULL AND NOT (NEW.settlement_reference <=> OLD.settlement_reference))
               OR (OLD.settled_at           IS NOT NULL AND NOT (NEW.settled_at           <=> OLD.settled_at)) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'finance_gateway_transactions: a fact reported by the provider is written once and never rewritten.';
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
            $table->json('payload');

            $table->timestamps();

            $table->foreign(['gateway_transaction_id', 'school_id'], 'finance_gateway_transaction_events_txn_school_foreign')
                ->references(['id', 'school_id'])->on(self::TABLE)->restrictOnDelete();

            // The investigation read: every delivery for one transaction, oldest first.
            $table->index(['gateway_transaction_id', 'id'], 'finance_gateway_transaction_events_txn_index');
        });

        $this->installTrigger(self::EVENTS_NO_UPDATE, 'UPDATE', <<<'SQL'
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                'finance_gateway_transaction_events is a raw record of what arrived: UPDATE is denied.';
            SQL, self::EVENTS_TABLE);

        $this->installTrigger(self::EVENTS_NO_DELETE, 'DELETE', <<<'SQL'
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                'finance_gateway_transaction_events is retained for audit: DELETE is denied.';
            SQL, self::EVENTS_TABLE);

        $this->installTrigger(self::EVENTS_SOURCE_GUARD, 'INSERT', <<<'SQL'
            IF NOT COALESCE(NEW.source COLLATE utf8mb4_bin = 'webhook'
                         OR NEW.source COLLATE utf8mb4_bin = 'verify', 0) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'finance_gateway_transaction_events.source must be webhook or verify.';
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
            self::CURRENCY_CHECK,
            self::FEE_CURRENCY_CHECK,
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
            ['id', 'uuid', 'school_id', 'gateway_transaction_id', 'source', 'event', 'payload', 'created_at', 'updated_at'],
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
