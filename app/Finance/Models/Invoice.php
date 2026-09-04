<?php

namespace App\Finance\Models;

use App\Casts\MoneyCast;
use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Enums\InvoiceStatus;
use App\Finance\Exceptions\LedgerImmutableException;
use App\Models\Student;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A Finance-owned invoice bound to one enrollment episode. Never deleted (voiding
 * is a status change + a reversing ledger entry); its money and lines are
 * immutable, only its status and void metadata mutate.
 *
 * `total` is the SNAPSHOT of SUM(lines), derived once inside the creating
 * transaction (F6) and thereafter immutable — the `finance_invoices_total_immutable`
 * BEFORE UPDATE trigger denies any change to the money columns at the DB.
 *
 * `kind` says WHAT the invoice is — the term bill (`scheduled`) or a charge raised
 * outside the schedule against an already-billed episode (`supplementary`). It is
 * FIXED AT CREATION: `finance_invoices_kind_immutable` denies any UPDATE of it,
 * because flipping a live scheduled invoice to supplementary would free the
 * episode's active slot and let a second scheduled invoice be issued alongside it.
 * There is no setter, no fillable exception and no domain path that rewrites it.
 *
 * `active_enrollment_key` is a STORED GENERATED column (= student_curriculum_id
 * while issued AND scheduled, NULL otherwise) carrying a
 * UNIQUE(school_id, active_enrollment_key). It is the DB expression of the
 * set-based invariant "at most one ACTIVE SCHEDULED invoice per enrollment
 * episode" — read-only here; never write it. Supplementary invoices compute a NULL
 * key and are therefore unconstrained: any number, at any time, for one episode.
 *
 * @property int $id
 * @property string $uuid
 * @property int $school_id
 * @property int $student_id
 * @property int $student_curriculum_id
 * @property int $number
 * @property InvoiceStatus $status
 * @property InvoiceKind $kind
 * @property string $billed_to_name
 * @property string $academic_context
 * @property Money $total
 * @property int|null $active_enrollment_key
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by_user_id
 * @property string|null $cancel_reason
 * @property Carbon|null $reviewed_at when Internal Audit released this bill to parents; NULL = not yet visible to the payer
 * @property int|null $reviewed_by_user_id LOOKUP, not an FK. NULL on a grandfathered row (see 2026_08_31_100000)
 * @property Carbon|null $returned_at when Internal Audit sent this bill back to Finance; NULL = never returned
 * @property int|null $returned_by_user_id LOOKUP, not an FK. Paired with returned_at by a trigger (see 2026_09_04_100000)
 * @property string|null $return_reason what Finance is being asked to correct; the entire content of a return
 * @property Carbon $created_at
 */
class Invoice extends Model
{
    use AddUuid, BelongsToSchool;

    protected $table = 'finance_invoices';

    protected $guarded = ['id'];

    protected $casts = [
        'status' => InvoiceStatus::class,
        'kind' => InvoiceKind::class,
        'total' => MoneyCast::class.':total_minor,total_currency',
        'cancelled_at' => 'datetime',
        self::RELEASE_STAMP_COLUMN => 'datetime',
        // NO RETURN_STAMP_COLUMN CONSTANT, and that was decided rather than skipped.
        // RELEASE_STAMP_COLUMN exists because `reviewed_at` had THREE spellings across app/ and
        // ReleasedToPayersHasOneDefinitionTest now pins it to one. `returned_at` has ONE writer
        // (App\Finance\Actions\ReturnInvoice) and no reader yet; a constant plus its arch test,
        // invented ahead of the second call site, asserts a discipline nothing is violating.
        'returned_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Un-deletable at the model layer too (the DB trigger is primary).
        static::deleting(function () {
            throw new LedgerImmutableException('DELETE');
        });
    }

    /**
     * The generic is not decoration, and the reason is the one Payment::allocations() records: without
     * it Larastan reads `$invoice->lines` as a `Collection<int, Model>`, so every typed closure mapped
     * over it is a `method.notFound` on InvoiceLine's own methods. AllocationProposal's destination
     * derivation is the first caller to filter these lines in PHP rather than render them.
     *
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /**
     * The student this invoice bills — a LOOKUP for display and for linking back to their
     * statement, never the isolation boundary (that is `school_id`, via BelongsToSchool).
     *
     * NAMING App\Models\Student FROM INSIDE App\Finance IS ALLOWED AND IS PRECEDENTED: Payment
     * declares the identical relation — `student` (app/Finance/Models/Payment.php:195) — and
     * PaymentReceiptController reads
     * `$payment->student?->full_name` through it. What the boundary forbids is a `DB::table` on a
     * `finance_` literal outside this module (bin/ci-boundary-lint.php) and Finance reaching into
     * ACADEMICS tables — `student_curricula` is the one this module resolves through the ACL port
     * for exactly that reason, which is why GenerateInvoice takes an enrollment UUID and hands
     * back an invoice that already knows its student.
     *
     * `billed_to_name` stays the name every DOCUMENT renders: it is the snapshot taken at billing
     * time and is what the invoice said when it was issued. This relation is for the uuid, and for
     * a caller that genuinely wants the student as they are TODAY.
     *
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Payment allocations against this invoice (read-only, for the settlement derivation).
     * Append-only; includes both ordinary payments and carry-forward credit applied at
     * generation — both reduce the outstanding, and neither is ever un-linked (a void
     * reverses the CHARGE in the ledger, never the allocation). Σ(amount_minor) is one half
     * of `outstanding = total − Σ(allocations) − Σ(approved credit notes)`.
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * Credit notes against this invoice (read-only, for the settlement derivation). Only the
     * APPROVED ones reduce the outstanding — a `submitted` proposal moves no money — so the
     * settlement sum filters on status. Kept as the full relation so both the pending badge
     * and the approved sum can read from one load.
     */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    /**
     * Minimum width of the numeric portion of a rendered invoice number.
     *
     * A MINIMUM, NOT A MAXIMUM — and a GLOBAL constant, not per-School. Padding is a
     * formatting convention, so it lives here rather than as a column on
     * `finance_school_settings`: the prefix is tenant data, the width is not.
     */
    public const NUMBER_PAD_WIDTH = 6;

    /**
     * The number as a human reads it: `BSS-000042`.
     *
     * PRESENTATION-DERIVED, NEVER STORED. `finance_invoices.number` remains the
     * integer that `UNIQUE(school_id, number)` and the Sequences kernel depend on;
     * storing the rendered form would have meant altering a deployed table, backfilling
     * live invoices, and re-deciding the unique index — for a string that can simply be
     * composed on the way out.
     *
     * Three format rules, all from the signed policy §2:
     *
     * 1. The number is zero-padded to a MINIMUM of NUMBER_PAD_WIDTH digits. `str_pad`
     *    is deliberate: it pads up to the width and otherwise returns the string
     *    UNCHANGED, so 1_000_000 renders `1000000` in full rather than being truncated
     *    or wrapped. A fixed-width formatter (`%06d` is fine, but a substr/wrap is not)
     *    would silently change format the day a School's numbering outgrew six digits.
     * 2. The separator is added HERE, not stored. Prefixes are stored separator-less
     *    (`BSS`, `BSP`, `BSPH`, `BSA`) so all four are uniform and the `-` is defined in
     *    one place, rather than depending on each registrar typing a trailing dash.
     * 3. Only the NUMBER is padded — the prefix never is.
     *
     * Defensive: a prefix still carrying a trailing `-` from the earlier mixed model is
     * normalised, so it renders `BSS-000042` and never `BSS--000042`.
     */
    public function displayNumber(): string
    {
        $padded = str_pad((string) $this->number, self::NUMBER_PAD_WIDTH, '0', STR_PAD_LEFT);

        $prefix = SchoolFinanceSettings::invoiceNumberPrefixFor((int) $this->school_id);

        // No prefix configured — the bare padded number, with no leading separator.
        if ($prefix === null) {
            return $padded;
        }

        return rtrim($prefix, '-').'-'.$padded;
    }

    public function isVoid(): bool
    {
        return $this->status === InvoiceStatus::Void;
    }

    /**
     * Exclude voided invoices — the reporting default.
     *
     * Deliberately a NAMED scope, not a global scope. Voidness is a *reporting*
     * concern, not an *existence* one: a global scope would make route-model
     * binding on {invoice:uuid} miss a voided invoice, turning the double-void
     * guard's 422 into a 404 and silently destroying the guard this slice adds.
     * Read models opt in; the audit view simply does not.
     */
    public function scopeExcludingVoid(Builder $query): Builder
    {
        return $query->where('status', '!=', InvoiceStatus::Void->value);
    }

    /**
     * THE ONE SPELLING OF THE RELEASE STAMP COLUMN.
     *
     * The rule had three implementations that agreed only because all three happened to mean
     * not-null: `isReviewed()` (PHP), `scopeReviewed()` (SQL) and an inline
     * `whereNull('reviewed_at')` in `InvoiceReadModel::guardianAccountPositionForStudent()`. Three
     * copies were an ACCIDENTAL CROSS-CHECK — updating one could expose the others — and one
     * definition has none, so the redundancy is replaced by prevention rather than simply removed:
     * `tests/Arch/ReleasedToPayersHasOneDefinitionTest.php` asserts this column name appears in
     * app/ CODE exactly here and nowhere else, so a FOURTH spelling cannot be written without a red.
     *
     * NAMED FOR THE QUESTION THE CALLER IS ASKING, NOT FOR THE COLUMN, and that is the whole point
     * of the rename. Under a future rejection shape that STAMPS a refused bill, "reviewed" becomes
     * true for a bill that must not be shown to the payer; "released to payers" does not. The name
     * therefore survives an answer Brookstone has not given yet, and the callers will not have to
     * be re-read when it lands — only the two members below change.
     */
    public const RELEASE_STAMP_COLUMN = 'reviewed_at';

    /**
     * Has Internal Audit released this bill to parents? (Brookstone, 31 August 2026 — §6.)
     *
     * FALSE DOES NOT MEAN THE BILL IS NOT REAL. An unreleased invoice is `issued`, holds the
     * enrollment's active slot, and has already posted its ledger charge — so it counts against the
     * student's balance exactly as a released one does. Only its VISIBILITY to the payer is gated.
     * That is the whole point of putting the state on this axis rather than on `status`; see
     * 2026_08_31_100000's docblock for the `active_enrollment_key` measurement that forces it.
     *
     * THERE IS DELIBERATELY NO "STAMPED BUT REJECTED READS AS NOT-RELEASED" ARM. It was considered
     * and refused: under the rejection shape specified so far a refusal leaves the stamp NULL, so
     * that state is not constructible, and an arm asserting it would pass because the row cannot
     * exist rather than because this predicate decided anything — a green for the wrong reason,
     * which is worse than the gap it appears to close. It goes in the day Brookstone answer
     * otherwise, and that day changes this method and the scope below and nothing else.
     */
    public function isReleasedToPayers(): bool
    {
        return $this->{self::RELEASE_STAMP_COLUMN} !== null;
    }

    /**
     * The SQL half of `isReleasedToPayers()` — released when `$released`, withheld when not.
     *
     * BOTH DIRECTIONS IN ONE MEMBER, ON PURPOSE. The withheld side is not a second scope with its
     * own predicate: two scopes would be two places to edit the day the rule stops meaning
     * "stamped", and the one that got missed would be the one nobody reads. `withheldFromPayers()`
     * below is a call INTO this method, so the negation cannot drift from the assertion.
     *
     * Written as an explicit `whereNull` rather than `whereNot(fn ($q) => $q->releasedToPayers())`
     * so the emitted SQL is byte-identical to the three predicates this collapses — the withheld
     * read is served by `finance_invoices_school_student_reviewed_index`, and a rename is not the
     * commit in which to hand the optimiser a differently-shaped predicate.
     *
     * A NAMED SCOPE AND NEVER A GLOBAL ONE, for the reason `scopeExcludingVoid()` above records and
     * one more that is specific to this axis. Voidness is a reporting concern; release is a
     * VISIBILITY concern, and it is one-sided: it applies to the parent portal and to nothing else.
     * A global scope would silently withhold unreleased invoices from the bursar, the statement, the
     * duplicate guard's own read and the auditor who is supposed to review them — hiding the bill
     * from the very people the ruling puts in charge of it, and doing so with no call site to read.
     *
     * There are exactly TWO consumers, both in InvoiceReadModel and both parent-facing:
     * `outstandingForStudent()` (released) and `guardianAccountPositionForStudent()` (withheld).
     * The count is the point: `accountPositionForStudent()` is shared with the staff statement and
     * must NOT acquire this predicate — per Brookstone the balance keeps counting the bill.
     */
    public function scopeReleasedToPayers(Builder $query, bool $released = true): Builder
    {
        return $released
            ? $query->whereNotNull(self::RELEASE_STAMP_COLUMN)
            : $query->whereNull(self::RELEASE_STAMP_COLUMN);
    }

    /**
     * The complement of `releasedToPayers()`, spelled as a call into it rather than as a predicate.
     *
     * Exists because the guardian balance projection reads the withheld set directly and
     * `->releasedToPayers(false)` at that call site reads as a mistake rather than as an intent.
     *
     * @param  Builder<Invoice>  $query  the generic is what lets Larastan resolve the scope call
     *                                   below; on a bare `Builder` it is a `method.notFound`
     */
    public function scopeWithheldFromPayers(Builder $query): Builder
    {
        return $query->releasedToPayers(false);
    }
}
