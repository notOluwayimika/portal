<?php

namespace App\Finance\Models;

use App\Casts\MoneyCast;
use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Enums\InvoiceStatus;
use App\Finance\Exceptions\LedgerImmutableException;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A Finance-owned invoice bound to one enrollment episode. Never deleted
 * (cancellation is a status change + a reversing ledger entry); its money and
 * lines are immutable, only its status and cancellation metadata mutate.
 *
 * @property int $id
 * @property string $uuid
 * @property int $school_id
 * @property int $student_id
 * @property int $student_curriculum_id
 * @property int $number
 * @property InvoiceStatus $status
 * @property string $billed_to_name
 * @property string $academic_context
 * @property Money $total
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by_user_id
 * @property string|null $cancel_reason
 */
class Invoice extends Model
{
    use AddUuid, BelongsToSchool;

    protected $table = 'finance_invoices';

    protected $guarded = ['id'];

    protected $casts = [
        'status' => InvoiceStatus::class,
        'total' => MoneyCast::class.':total_minor,total_currency',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Un-deletable at the model layer too (the DB trigger is primary).
        static::deleting(function () {
            throw new LedgerImmutableException('DELETE');
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function isCancelled(): bool
    {
        return $this->status === InvoiceStatus::Cancelled;
    }
}
