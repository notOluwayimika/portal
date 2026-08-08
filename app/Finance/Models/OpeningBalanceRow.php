<?php

namespace App\Finance\Models;

use App\Casts\MoneyCast;
use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Enums\OpeningBalanceRowStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One (student × fee type) line in a staged WCBS extract (§9 step 4a) — the file as it arrived, plus
 * what validation made of it. Nothing here is posted; the posting Action is 4b.
 *
 * ONE ROW PER (STUDENT × FEE TYPE), not one row per student — R5's shape, held by
 * unique(school_id, batch_id, admission_number, fee_type_label) (R9).
 *
 * `balance` is SIGNED: positive is owed, negative is credit, and R8 reads the instrument off that
 * sign. There is deliberately no non-negative constraint anywhere near it — one would reject every
 * student who is in credit.
 *
 * `student_total_balance` is the student's STATED total, repeated identically on every row of that
 * student's group. It is L1's independent witness (§1): without it the checksum degrades to "the
 * lines sum to themselves".
 *
 * EVERY AMOUNT IS NULLABLE, and that is §2's "Blank ≠ zero" made structural. A blank or unparseable
 * cell is staged as ABSENT and the row is rejected with a named finding; it is never coerced to
 * zero, because a zero is a claim that the student owes nothing and the file did not make it.
 *
 * `student_id` is a LOOKUP, not an FK. A row that resolves to no student is a reportable outcome
 * (§7: "Student in WCBS, absent from the portal → Reject the row and name it"), and an FK would
 * turn that report into a constraint violation that aborts the run.
 *
 * `admission_number` is stored EXACTLY as it appeared in the file — untrimmed. The trim happens in
 * the comparison, never in what is kept, so an operator looking at a duplicate-after-trim finding
 * can see the whitespace that caused it.
 *
 * @property int $id
 * @property string $uuid
 * @property int $school_id
 * @property int $batch_id
 * @property int $line_number
 * @property string|null $admission_number
 * @property string|null $wcbs_student_ref
 * @property string $fee_type_label
 * @property Money|null $balance
 * @property Money|null $student_total_balance
 * @property string|null $wcbs_bill_reference
 * @property int|null $student_id
 * @property OpeningBalanceRowStatus $status
 * @property array<int, array<string, mixed>>|null $findings
 */
class OpeningBalanceRow extends Model
{
    use AddUuid, BelongsToSchool;

    protected $table = 'finance_opening_balance_rows';

    protected $guarded = ['id'];

    protected $casts = [
        'status' => OpeningBalanceRowStatus::class,
        'findings' => 'array',
        'balance' => MoneyCast::class.':balance_minor,balance_currency',
        'student_total_balance' => MoneyCast::class.':student_total_balance_minor,student_total_balance_currency',
    ];

    /**
     * @return BelongsTo<OpeningBalanceBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(OpeningBalanceBatch::class, 'batch_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
