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
 * One student's line in a staged WCBS extract (§9 commit 1) — the file as it arrived, plus what
 * validation made of it. Nothing here is posted; the posting Action is commit 4.
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
 * @property Money|null $prior_arrears
 * @property Money|null $wcbs_billed_total
 * @property Money|null $paid_to_date
 * @property Money|null $wcbs_total_balance
 * @property string|null $wcbs_bill_reference
 * @property \Illuminate\Support\Carbon|null $last_payment_date
 * @property int|null $student_id
 * @property OpeningBalanceRowStatus $status
 * @property array<int, array<string, mixed>>|null $findings
 * @property Money|null $expected_billed
 */
class OpeningBalanceRow extends Model
{
    use AddUuid, BelongsToSchool;

    protected $table = 'finance_opening_balance_rows';

    protected $guarded = ['id'];

    protected $casts = [
        'status' => OpeningBalanceRowStatus::class,
        'last_payment_date' => 'date',
        'findings' => 'array',
        'prior_arrears' => MoneyCast::class.':prior_arrears_minor,prior_arrears_currency',
        'wcbs_billed_total' => MoneyCast::class.':wcbs_billed_total_minor,wcbs_billed_total_currency',
        'paid_to_date' => MoneyCast::class.':paid_to_date_minor,paid_to_date_currency',
        'wcbs_total_balance' => MoneyCast::class.':wcbs_total_balance_minor,wcbs_total_balance_currency',
        'expected_billed' => MoneyCast::class.':expected_billed_minor,expected_billed_currency',
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
