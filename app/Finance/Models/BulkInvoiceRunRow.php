<?php

namespace App\Finance\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Enums\BulkInvoiceRunOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a bulk invoice run made of ONE enrollment (U6 commit 3).
 *
 * ONE ROW PER ENROLLMENT PER RUN, held at the engine by
 * unique(school_id, run_id, enrollment_id) — so the four outcome counts on the run cannot
 * double-count a student the cohort read returned twice.
 *
 * `outcome` PARTITIONS the enrollments the run SAW; it does not partition the School. The billable
 * students the run never enumerated have no row here at all and are carried as
 * {@see BulkInvoiceRun::$unaccounted_count}. See {@see BulkInvoiceRunOutcome}.
 *
 * `invoice_id` MEANS TWO DIFFERENT THINGS depending on `outcome`, and both are useful: on `billed`
 * it is the invoice this run raised, on `already_billed` it is the invoice that was already there.
 * On `failed` and `unplaceable` it is NULL, because no invoice exists to name.
 *
 * `enrollment_id` CARRIES A COMPOSITE (enrollment_id, school_id) FK to `student_curricula`, the same
 * shape `finance_invoices` uses — so a run of School A cannot record a row against School B's
 * episode even if the job asked it to. `student_id` is a plain LOOKUP and is NULLABLE, because the
 * episode's own `student_id` is: a student-less episode is schema-legal and is one of the two shapes
 * the reconciliation exists to surface.
 *
 * @property int $id
 * @property string $uuid
 * @property int $school_id
 * @property int $run_id
 * @property int $enrollment_id
 * @property string $enrollment_uuid
 * @property int|null $student_id
 * @property BulkInvoiceRunOutcome $outcome
 * @property int|null $invoice_id
 * @property string|null $reason non-null ONLY on `failed`
 */
class BulkInvoiceRunRow extends Model
{
    use AddUuid, BelongsToSchool;

    protected $table = 'finance_bulk_invoice_run_rows';

    protected $guarded = ['id'];

    protected $casts = [
        'outcome' => BulkInvoiceRunOutcome::class,
    ];

    /**
     * @return BelongsTo<BulkInvoiceRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(BulkInvoiceRun::class, 'run_id');
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
