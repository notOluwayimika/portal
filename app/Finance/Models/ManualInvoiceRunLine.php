<?php

namespace App\Finance\Models;

use App\Casts\MoneyCast;
use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ONE LINE OF THE MANUAL RUN'S BILL — one set for the WHOLE target list, never one per student.
 *
 * The scheduled run pins a `fee_schedule_id` and maps its lines from the catalog. A manual run has no
 * catalog row to point at: the operator typed the description, the amount and the destination, so the
 * run has to store them or a failed run has no record of what it tried to bill and a re-run means
 * re-typing.
 *
 * MONEY IS `amount_minor` + `amount_currency` through {@see MoneyCast} — integer minor units and an
 * explicit ISO-4217 code (Constitution 10, ADRs 0002/0037). Never a float, never `decimal:`.
 *
 * `bank_account_id` IS NOT NULLABLE, which is narrower than {@see InvoiceLineSpec::$bankAccountId}
 * permits. S11 made a destination REQUIRED on every charge line; every line here is a charge, there
 * is no fee item to read a default off, and there is no default to invent. A run whose lines could
 * reach the Action without one is a run that fails at the last step FOR EVERY STUDENT, having already
 * claimed them all. The composite (bank_account_id, school_id) FK is what stops a line naming another
 * School's account — a database refusal, not a trusted one.
 *
 * @property int $id
 * @property string $uuid
 * @property int $school_id
 * @property int $run_id
 * @property string $description snapshot text — copied onto the invoice line, never re-joined
 * @property Money|null $amount
 * @property int $bank_account_id
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ManualInvoiceRunLine extends Model
{
    use AddUuid, BelongsToSchool;

    protected $table = 'finance_manual_invoice_run_lines';

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => MoneyCast::class.':amount_minor,amount_currency',
    ];

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
