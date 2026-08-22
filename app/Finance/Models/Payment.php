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
     * The two values `origin` may hold, named. The authority is the database: the trigger pair
     * `finance_payments_origin_pairing_bi` / `_bu`
     * (2026_08_17_100000_maker_checker_and_payment_origin_as_triggers.php) admits exactly these two
     * spellings, case-sensitively under `COLLATE utf8mb4_bin`, and keys the bank-account pairing off
     * the same two. It replaced TWO CHECKs — `finance_payments_origin_shape` (2026_08_07_110000:91)
     * and `finance_payments_bank_account_origin_shape` (2026_08_10_120000:102-104) — with one
     * predicate, because the pairing subsumes the domain rule and because production is MySQL 5.7.23,
     * which parses and ignores CHECK entirely. These constants are a second READER of that rule, never a second
     * copy of it — the column is what refuses a third value, not this file.
     */
    public const ORIGIN_PORTAL = 'portal';

    public const ORIGIN_MIGRATED = 'migrated';

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
     * Unreachable today: the `finance_payments_origin_pairing_bi` trigger admits exactly `portal` and
     * `migrated`, so no third value can be persisted — and unlike the CHECK it replaced, that is now
     * true on production too (2026_08_17_100000). It exists because the two halves of this
     * decision must not be allowed to drift apart. `isReceiptable()` is an allowlist and refuses the
     * unknown correctly; before this constant, the EXPLANATION was a denylist — every non-portal row
     * got the WCBS text — so the day a third origin is added by an unrelated migration, this system
     * would have told a bursar a specific, false thing about a parent's receipt. The predicate would
     * have been right and the sentence wrong, which is worse than either being obviously broken.
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
     * The account the money landed in. NULL for a migrated row and NOT NULL for a portal one — the
     * `finance_payments_origin_pairing_bi` trigger enforces exactly that pairing (2026_08_17_100000,
     * replacing the CHECK of the same rule), so a receipt (which is only ever issued for a portal
     * payment) always has one to name.
     *
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * MAY THIS SYSTEM ISSUE A RECEIPT FOR THIS PAYMENT? The predicate is `origin`, and it is an
     * ALLOWLIST rather than `!== ORIGIN_MIGRATED` on purpose. The two are equivalent today because
     * the CHECK admits exactly two values — but they differ in what happens on the day a third
     * arrives, and only one of them fails in the safe direction. A denylist would issue a receipt
     * for an origin nobody had decided about; this refuses until someone does.
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
        return $this->origin === self::ORIGIN_PORTAL;
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
     * explain is strictly better than no refusal. The DATABASE is what keeps a third origin from
     * existing (the CHECK); this branch is what keeps the system honest if it ever does.
     */
    public function receiptRefusalReason(): ?string
    {
        return match (true) {
            $this->isReceiptable() => null,
            $this->origin === self::ORIGIN_MIGRATED => self::RECEIPT_REFUSAL_REASON,
            default => self::RECEIPT_REFUSAL_REASON_UNKNOWN_ORIGIN,
        };
    }
}
