<?php

namespace App\Finance\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Enums\ManualInvoiceRunOutcome;
use App\Finance\Jobs\ProcessManualInvoiceRun;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WHAT THE MANUAL RUN MADE OF ONE ENROLLMENT — and, before that, the CLAIM on it.
 *
 * THE ROW IS INSERTED BEFORE THE INVOICE EXISTS, which is the entire difference between this table
 * and {@see BulkInvoiceRunRow}. There the row is written after the invoice
 * (`ProcessBulkInvoiceRun:446` bills, `:593` records), so `UNIQUE(school_id, run_id, enrollment_id)`
 * sits downstream of the money and its 1062 arrives only once a duplicate invoice has already
 * committed. Here {@see ProcessManualInvoiceRun} INSERTs `outcome = claimed` first, so the same index
 * refuses a re-execution's second attempt while there is still nothing to undo, and then UPDATEs this
 * row with what actually happened.
 *
 * `created_at` IS THE CLAIM INSTANT, by construction — the row is inserted at the claim and at no
 * other moment. That is why there is no separate `claimed_at`: a second copy of a fact the table
 * already holds is a thing that can disagree with it.
 *
 * A ROW STILL READING `claimed` AFTER THE RUN FINISHED IS NOT BILLED AND NOT RETRIED, and it will not
 * fix itself — there is no sweeper. It is nonetheless the state worth having: see
 * {@see ManualInvoiceRunOutcome} for the comparison against what it replaces (an invoice with no row,
 * i.e. money on a family's balance that nothing records).
 *
 * `invoice_id` NAMES THE INVOICE THIS RUN RAISED, on `billed` only — and unlike the scheduled run's
 * column it never means "the one that was already there", because a manual run has no
 * `already_billed` outcome to mean it with.
 *
 * TWO UNIQUE INDEXES, AND THE CLAIM IS THE STUDENT ONE. `UNIQUE(school_id, run_id, student_id)` is
 * what the claim rests on: `student_id` is NOT NULL, so it refuses a second claim for every outcome
 * — including {@see ManualInvoiceRunOutcome::Unplaceable}, whose `enrollment_id` is NULL and would
 * slip straight past an enrollment-keyed index, because NULLs do not collide in a MySQL unique
 * index. `UNIQUE(school_id, run_id, enrollment_id)` is RETAINED beside it, unchanged, and constrains
 * only the rows that name an episode: it is the last thing standing between a resolver that maps two
 * ticked students onto ONE episode and that episode being billed twice inside a single run.
 *
 * `student_id` IS NOT NULL HERE, unlike the scheduled run's row, whose column is nullable because
 * that run walks EPISODES and a student-less episode is schema-legal. This run walks STUDENTS, so
 * there is always one.
 *
 * @property int $id
 * @property string $uuid
 * @property int $school_id
 * @property int $run_id
 * @property int $student_id
 * @property int|null $enrollment_id NULL exactly on `unplaceable`
 * @property string|null $enrollment_uuid NULL exactly when $enrollment_id is
 * @property ManualInvoiceRunOutcome $outcome
 * @property int|null $invoice_id non-null ONLY on `billed`
 * @property string|null $reason non-null ONLY on `failed`
 * @property Carbon|null $created_at THE CLAIM INSTANT
 * @property Carbon|null $updated_at when the outcome was written — equal to created_at on a stuck claim
 */
class ManualInvoiceRunRow extends Model
{
    use AddUuid, BelongsToSchool;

    protected $table = 'finance_manual_invoice_run_rows';

    protected $guarded = ['id'];

    protected $casts = [
        'outcome' => ManualInvoiceRunOutcome::class,
    ];

    /**
     * @return BelongsTo<ManualInvoiceRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ManualInvoiceRun::class, 'run_id');
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
