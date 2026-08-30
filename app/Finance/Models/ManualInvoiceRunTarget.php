<?php

namespace App\Finance\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Contracts\BillableEnrollmentProvider;
use App\Finance\Enums\ManualInvoiceRunOutcome;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ONE STUDENT THE OPERATOR TICKED, and the episode they resolved to — the manual run's substitute
 * for {@see BillableEnrollmentProvider::listForCohort()}.
 *
 * THIS IS THE INSTRUCTION, NOT THE RECORD. {@see ManualInvoiceRunRow} is the record. The two tables
 * carry almost the same key and are not two copies of one thing: a scheduled run does not need a
 * table for its instruction because its instruction is a pair of coordinates and its list is COMPUTED
 * at run time; a manual run's list is GIVEN, so it has to be stored. Storing it is what makes
 * `target_count` an independent source for the cohort equality rather than a tally the job kept about
 * itself — see {@see ManualInvoiceRun}.
 *
 * IT IS KEYED ON THE STUDENT, NOT THE ENROLLMENT, AND THAT IS WHAT MAKES `target_count` HONEST.
 * `student_id` is NOT NULL under UNIQUE(school_id, run_id, student_id), so the run's target count is
 * the number on the bursar's screen — and a list naming the same child twice is refused at the
 * engine with 1062, before the run exists to be started. Keyed on the enrollment it would have
 * counted what SURVIVED RESOLUTION: a selection of 96 in which six students have no current
 * enrollment would report "90 of 90" — balanced, complete, and six families short. This feature
 * issues directly, with no maker-checker, so nothing downstream would ever have caught it.
 *
 * `enrollment_id` IS NULLABLE BECAUSE RESOLUTION IS AN OUTCOME. NULL means "no current billable
 * enrollment", and the run records that student as {@see ManualInvoiceRunOutcome::Unplaceable}
 * rather than dropping them (brief §2: report the unresolved, do not drop them).
 *
 * BOTH IDENTITIES CARRY A COMPOSITE (id, school_id) FK — to `students` and to `student_curricula`,
 * the same shape `finance_invoices` uses. MEASURED on 8.0.43: a row with `enrollment_id` NULL is
 * accepted, a row naming another School's episode is refused 1452, and a row naming another School's
 * STUDENT is refused 1452 **even when `enrollment_id` is NULL**. So the nullable component weakens
 * the enrollment check only on rows that name no enrollment, and the School binding is carried by a
 * key that can never be absent.
 *
 * A STUDENT-LESS EPISODE CANNOT BE A TARGET, and that is correct rather than a gap:
 * `student_curricula.student_id` is nullable and such an episode is schema-legal, but a manual run
 * is driven by people the bursar ticked and there is nobody there to tick.
 *
 * @property int $id
 * @property string $uuid
 * @property int $school_id
 * @property int $run_id
 * @property int $student_id the identity the bursar ticked — the key of this table
 * @property int|null $enrollment_id the episode they resolved to; NULL is `unplaceable`
 * @property string|null $enrollment_uuid NULL exactly when $enrollment_id is
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ManualInvoiceRunTarget extends Model
{
    use AddUuid, BelongsToSchool;

    protected $table = 'finance_manual_invoice_run_targets';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<ManualInvoiceRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ManualInvoiceRun::class, 'run_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
