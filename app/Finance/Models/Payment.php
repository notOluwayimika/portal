<?php

namespace App\Finance\Models;

use App\Casts\MoneyCast;
use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Models\Concerns\AppendOnly;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
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
 * @property Carbon $created_at
 * @property-read Collection<int, PaymentAllocation> $allocations
 */
class Payment extends Model
{
    use AddUuid, AppendOnly, BelongsToSchool;

    /**
     * The floor of the reserved receipt band for MIGRATED payments (opening-balance spec §4).
     * An imported row takes its `reference` from at or above this value so it never interleaves
     * with a portal-issued receipt under UNIQUE (school_id, reference).
     *
     * THE BAND IS SAFE ONLY BECAUSE THE PAYMENT SEQUENCE TAKES NO SEED. Both call sites —
     * RecordPayment.php:79 and RecordAccountPayment.php:82 — call
     * `Sequences::next('finance_payment', …)` with no seed closure, so on first use the counter
     * starts at 0 instead of adopting MAX(reference). Add a seed to "harden" it the way
     * HasAdmissionNumber:55 and HasStaffNumber:54 do — the codebase's dominant pattern, and a
     * one-line change that reads as consistency — and a school whose counter is seeded after an
     * import adopts 900,000,001 and issues every subsequent portal receipt inside the reserved
     * band, permanently: this table is append-only, so nothing can be renumbered afterwards.
     *
     * Pinned by tests/Feature/Finance/PaymentProvenanceTest.php.
     */
    public const MIGRATED_REFERENCE_FLOOR = 900_000_000;

    protected $table = 'finance_payments';

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => MoneyCast::class.':amount_minor,amount_currency',
    ];

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
