<?php

namespace App\Finance\Models;

use App\Casts\MoneyCast;
use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Http\Resources\PaymentResource;
use App\Finance\Models\Concerns\AppendOnly;
use App\Finance\Services\AllocationProposal;
use App\Models\Student;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A payment against the student ACCOUNT (school + student), not an invoice — the
 * allocation is the money→invoice link, so unallocated/advance payments are
 * expressible. Append-only.
 *
 * @property int $id
 * @property string $uuid
 * @property int $school_id
 * @property int $student_id
 * @property int $reference
 * @property Money $amount
 * @property string $payer_name
 * @property string $method
 * @property string $origin
 * @property string|null $external_reference
 * @property int|null $received_by_user_id
 * @property int|null $bank_account_id
 * @property Carbon $created_at
 * @property-read Collection<int, PaymentAllocation> $allocations
 * @property-read Student|null $student
 * @property-read BankAccount|null $bankAccount
 * @property Carbon $received_at
 * @property string|null $received_at_reason
 */
class Payment extends Model
{
    use AddUuid, AppendOnly, BelongsToSchool;

    /**
     * The floor of the reserved receipt band for MIGRATED payments (opening-balance spec §4).
     * An imported row takes its `reference` from at or above this value so it never interleaves
     * with a portal-issued receipt under UNIQUE (school_id, reference).
     *
     * THE BAND IS SAFE ONLY BECAUSE THE PAYMENT SEQUENCE TAKES NO SEED. Both live call sites —
     * RecordPayment::handle and RecordAccountPayment::handle — call
     * `Sequences::next('finance_payment', …)` with no seed closure, so on first use the counter
     * starts at 0 instead of adopting MAX(reference). Add a seed to "harden" it the way
     * HasAdmissionNumber and HasStaffNumber do — the codebase's dominant pattern, and a one-line
     * change that reads as consistency — and a school whose counter is seeded after an import
     * adopts 900,000,001 and issues every subsequent portal receipt inside the reserved band,
     * permanently: this table is append-only, so nothing can be renumbered afterwards.
     *
     * The two Actions SHARE one counter (same scope, same key), and Sequences evaluates a seed on
     * first use only — so seeding either one alone corrupts the band for both. Both are pinned, one
     * case each, in tests/Feature/Finance/PaymentProvenanceTest.php. A THIRD call site on this
     * scope would be pinned by neither and must arrive with its own case.
     */
    public const MIGRATED_REFERENCE_FLOOR = 900_000_000;

    /**
     * The three values `origin` may hold, named. The authority is the database: the trigger pair
     * `finance_payments_origin_pairing_bi` / `_bu` — installed by
     * 2026_08_17_100000_maker_checker_and_payment_origin_as_triggers.php and REPLACED IN PLACE by
     * 2026_08_25_100000_finance_payment_origin_admits_gateway.php — admits exactly these three
     * spellings, case-sensitively under `COLLATE utf8mb4_bin`, and keys the bank-account pairing off
     * the same three. It replaced TWO CHECKs — `finance_payments_origin_shape` (2026_08_07_110000:91)
     * and `finance_payments_bank_account_origin_shape` (2026_08_10_120000:102-104) — with one
     * predicate, because the pairing subsumes the domain rule and because production is MySQL 5.7.23,
     * which parses and ignores CHECK entirely. These constants are a second READER of that rule, never a second
     * copy of it — the column is what refuses a fourth value, not this file.
     */
    public const ORIGIN_PORTAL = 'portal';

    public const ORIGIN_MIGRATED = 'migrated';

    /**
     * Money collected by an ONLINE PAYMENT PROVIDER and settled into one of the school's accounts.
     *
     * IT NAMES THE CATEGORY, NOT THE PROVIDER, and that is the whole decision rather than a
     * shortening. `finance_payments` is append-only, so a value written into live money rows can
     * never be corrected; `paystack` would mean a second provider needs a migration of rows that
     * cannot be migrated. Which provider, and which transaction, travel per-row in
     * `external_reference` — the column that already exists for exactly this purpose.
     *
     * ITS PAIRING ARM MIRRORS `portal`, NOT `migrated`: a gateway payment DOES name a bank account,
     * the settlement account the provider pays out into, and the bursar reconciles it against a
     * statement the same way. `migrated` is the odd arm because that money never entered one of our
     * accounts at all.
     */
    public const ORIGIN_GATEWAY = 'gateway';

    /**
     * WHY A RECEIPT IS REFUSED FOR A MIGRATED ROW, in the words the operator reads. One string,
     * here, because two consumers state this rule and they must state the same thing: the receipt
     * route refuses with it (PaymentReceiptController) and PaymentResource carries it onto the
     * statement so the row can say why before anyone clicks. A second spelling of it in the UI is
     * exactly the drift this constant exists to prevent.
     *
     * IT IS A FACTUAL CLAIM ABOUT WCBS, so it is reachable ONLY from `origin = 'migrated'` — see
     * receiptRefusalReason() below, which matches rather than defaulting to it.
     */
    public const RECEIPT_REFUSAL_REASON = 'This payment was collected in the previous system before the cutover and '
        .'brought across as an opening balance. Brookstone never issued a receipt for it from this system, so this '
        .'system will not print one now. The receipt the parent holds is the one the previous system issued.';

    /**
     * The refusal for an origin this code does not recognise. It states what is actually known —
     * that provenance could not be confirmed — and asserts NOTHING about where the money came from.
     *
     * Unreachable today: the `finance_payments_origin_pairing_bi` trigger admits exactly `portal`,
     * `migrated` and `gateway`, so no fourth value can be persisted — and unlike the CHECK it
     * replaced, that is now true on production too (2026_08_17_100000, widened by
     * 2026_08_25_100000). It exists because the two halves of this decision must not be allowed to
     * drift apart. `isReceiptable()` is an allowlist and refuses the unknown correctly; before this
     * constant, the EXPLANATION was a denylist — every non-portal row got the WCBS text — so the day
     * a further origin is added by an unrelated migration, this system would have told a bursar a
     * specific, false thing about a parent's receipt. The predicate would have been right and the
     * sentence wrong, which is worse than either being obviously broken.
     *
     * THAT DAY HAS SINCE ARRIVED ONCE, AND THIS CONSTANT IS WHY IT COST NOTHING. `gateway` landed in
     * 2026_08_25_100000 and is receiptable, so it takes the `null` branch — but had it not been, the
     * neutral sentence, not the WCBS one, is what it would have been given.
     */
    public const RECEIPT_REFUSAL_REASON_UNKNOWN_ORIGIN = 'This system cannot confirm that it collected this payment, '
        .'so it will not issue a receipt for it. Ask the bursar’s office to check how this payment was recorded '
        .'before anything is issued to the payer.';

    protected $table = 'finance_payments';

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => MoneyCast::class.':amount_minor,amount_currency',
        // A business DAY, not a moment: "received on the 3rd" carries no time of day and the
        // operator is never asked for one.
        'received_at' => 'date',
    ];

    /**
     * The generic is not decoration: without it Larastan reads `allocations()->get()` as a
     * `Collection<int, Model>`, and every typed closure mapped over it (the receipt's allocation
     * rows) is an `argument.type` error plus an undefined-method one for each Invoice call.
     *
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * Amount − Σ(allocations): what this payment has NOT yet settled and is therefore still sitting on
     * the account as credit.
     *
     * ONE EXPRESSION WITH TWO CONSUMERS, which is why it is on the model rather than in either of
     * them. {@see PaymentResource} puts it on the statement so a row can
     * offer the allocation screen, and {@see AllocationProposal} builds the
     * proposal from it. A second spelling is how two surfaces come to disagree about how much of a
     * payment is unspent — and one of them would be the screen that writes rows about it.
     *
     * UNFLOORED, deliberately. `docs/handoff/tickets/nothing-constrains-allocations-to-a-payments-amount.md`
     * names flooring this at zero as explicitly NOT the fix: it hides the state on the one surface
     * that would have shown it, and leaves the row in the ledger. A negative value means the
     * allocation table holds more than this payment carries — a violating row that predates the
     * payment-axis trigger, or arrived around it — and it must surface rather than clamp.
     *
     * Uses the loaded relation when there is one (the statement eager-loads it) and queries otherwise,
     * so a list read does not become N+1 and a single payment still answers correctly.
     */
    public function unallocatedAmount(): Money
    {
        $allocatedKobo = $this->relationLoaded('allocations')
            ? $this->allocations->sum(fn (PaymentAllocation $allocation) => $allocation->amount->toKobo())
            : (int) $this->allocations()->sum('amount_minor');

        return $this->amount->minus(Money::fromKobo((int) $allocatedKobo, $this->amount->currency));
    }

    /**
     * The student whose ACCOUNT this payment sits on. `student_id` has been on this table since it
     * was created; the relation is added here because the receipt is the first surface that names
     * the student rather than only the payer, and a payer name is not a student name (a parent, an
     * employer or a sponsor may pay).
     *
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * The account the money landed in. NULL for a migrated row and NOT NULL for a portal or a gateway
     * one — the `finance_payments_origin_pairing_bi` trigger enforces exactly that pairing
     * (2026_08_17_100000, replacing the CHECK of the same rule; widened to the gateway arm by
     * 2026_08_25_100000), so a receipt — which is only ever issued for a portal or a gateway payment,
     * both of which name an account — always has one to name. A gateway row names the SETTLEMENT
     * account the provider paid out into.
     *
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * MAY THIS SYSTEM ISSUE A RECEIPT FOR THIS PAYMENT? The predicate is `origin`, and it is an
     * ALLOWLIST rather than `!== ORIGIN_MIGRATED` on purpose.
     *
     * THE DAY THE TWO WOULD HAVE DIVERGED HAS HAPPENED, WHICH IS WHY THE SHAPE STAYS. When there were
     * two origins the allowlist and `!== ORIGIN_MIGRATED` were equivalent, and the docblock said they
     * differed only "on the day a third arrives". `gateway` is that third (2026_08_25_100000). A
     * denylist would have issued a receipt for it automatically, the moment the migration ran and
     * before anyone had decided whether this system may claim to have collected that money. The
     * decision — it may; a gateway payment IS receiptable — is taken HERE, by adding the value to the
     * list, in the same commit as the writer that produces it. A fourth origin is refused again until
     * someone does the same for it.
     *
     * `in_array` WITH STRICT COMPARISON, not a `match` or an `||` chain, so the list reads as a list:
     * the set is the thing being maintained, and the next arrival should be a one-line edit that is
     * visible as such in a diff.
     *
     * NOT `MIGRATED_REFERENCE_FLOOR`. The floor is a receipt-NUMBERING fact — the reserved band a
     * migrated row draws its `reference` from so it cannot collide with a portal-issued one. Using
     * it as a provenance test would be a heuristic standing where a CHECK-constrained column already
     * answers the question exactly, and it would answer wrongly the moment a school's portal counter
     * is ever seeded into the band (the failure the floor's own docblock warns about, one column
     * away).
     */
    public function isReceiptable(): bool
    {
        return in_array($this->origin, [self::ORIGIN_PORTAL, self::ORIGIN_GATEWAY], true);
    }

    /**
     * The stated reason a receipt is refused, or null when one may be issued.
     *
     * A MATCH ON `origin`, NOT A DEFAULT. The first version was
     * `isReceiptable() ? null : RECEIPT_REFUSAL_REASON` — an allowlist predicate with a DENYLIST
     * explanation, so any origin that was not `portal` was told it came from WCBS. The refusal was
     * right; the sentence would have been a specific false claim about a parent's payment, put on
     * the wire and shown to a bursar.
     *
     * The unknown branch RETURNS A NEUTRAL REASON rather than throwing, deliberately. Throwing would
     * turn an unrecognised row into a 500 on a page an operator deliberately opened — and a 500
     * destroys the refusal itself, so the operator learns nothing at all, which is the failure mode
     * the whole "never silently hide the row" rule exists to prevent. A refusal that declines to
     * explain is strictly better than no refusal. The DATABASE is what keeps an unrecognised origin
     * from existing — the `finance_payments_origin_pairing_bi` TRIGGER, not a CHECK, since
     * 2026_08_17_100000; this branch is what keeps the system honest if one ever does.
     *
     * NO ARM WAS ADDED FOR `gateway`, and that is correct rather than an omission. A gateway payment
     * is receiptable, so it takes the FIRST arm and answers `null` — the same answer a portal payment
     * gives, through the same branch. An arm of its own would be a second spelling of `isReceiptable()`
     * inside the method that already calls it.
     */
    public function receiptRefusalReason(): ?string
    {
        return match (true) {
            $this->isReceiptable() => null,
            $this->origin === self::ORIGIN_MIGRATED => self::RECEIPT_REFUSAL_REASON,
            default => self::RECEIPT_REFUSAL_REASON_UNKNOWN_ORIGIN,
        };
    }

    /** The operational code for a refused receipt — `notification_deliveries.skip_reason`'s vocabulary. */
    public const RECEIPT_REFUSAL_CODE_MIGRATED = 'receipt_refused_migrated';

    public const RECEIPT_REFUSAL_CODE_UNKNOWN_ORIGIN = 'receipt_refused_unknown_origin';

    /**
     * The same refusal, as a CODE rather than a sentence — or null when a receipt may be issued.
     *
     * ── WHY A SECOND VALUE AT ALL ──
     *
     * {@see RECEIPT_REFUSAL_REASON} is ~250 characters of parent-facing prose.
     * `notification_deliveries.skip_reason` is `string(64)`, and its existing vocabulary is
     * `hard_bounce`, `no_address`, `unsubscribe`, `sms_invalid_number` — machine codes a bursar
     * greps when a parent rings. The sentence does not fit and would be the wrong kind of value
     * there even if it did. Two audiences, two strings.
     *
     * ── AND WHY IT BRANCHES ON THE REASON, NEVER ON `origin` AGAIN ──
     *
     * A single code would reproduce, one column over, the exact defect {@see receiptRefusalReason}'s
     * docblock exists to record: an unknown-origin payment filed under `receipt_refused_migrated`
     * would tell a bursar the money came from WCBS when the system's own position is that it cannot
     * confirm where it came from. The sentence would be right and the code wrong — and the code is
     * the one that gets searched.
     *
     * So the map is driven off the RETURNED CONSTANT. Re-inspecting `$this->origin` here would be a
     * THIRD read of one decision, and the one most likely to fall out of step when a fourth origin
     * appears, because it would sit furthest from the arms that own the rule. One decision, one
     * branch point, and this method cannot disagree with its twin because it asks its twin.
     *
     * ── ITS DESTINATION IS NOT YET WIRED, AND THAT IS DELIBERATE ──
     *
     * Nothing consumes this today. `NotificationType::PAYMENT_RECEIVED`'s dispatch site has an
     * unresolved design question — a subject-level refusal is not one of the three delivery-scoped
     * conditions `FanOutNotificationJob` expresses, and where it belongs instead is open. **Do not
     * read this method's existence as evidence that a wiring exists.** The value and its
     * non-drift property are correct regardless of where they are eventually consumed, which is why
     * it lands separately from the consumer.
     */
    public function receiptRefusalCode(): ?string
    {
        return match ($this->receiptRefusalReason()) {
            null => null,
            self::RECEIPT_REFUSAL_REASON => self::RECEIPT_REFUSAL_CODE_MIGRATED,
            default => self::RECEIPT_REFUSAL_CODE_UNKNOWN_ORIGIN,
        };
    }
}
