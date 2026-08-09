<?php

namespace App\Finance\Models;

use App\Casts\MoneyCast;
use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Enums\LedgerEntryType;
use App\Finance\Models\Concerns\AppendOnly;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One append-only movement in the per-student receivable subledger. Signed Money:
 * a charge is positive, a payment/reversal negative; a student's balance is
 * SUM(amount). Immutable at both the DB (triggers) and model (AppendOnly) layers.
 *
 * @property int $id
 * @property string $uuid
 * @property int $school_id
 * @property int $student_id
 * @property LedgerEntryType $type
 * @property Money $amount
 * @property string $source_type
 * @property int $source_id
 * @property string $narration
 * @property Carbon $posted_at
 * @property Carbon $effective_at
 */
class LedgerTransaction extends Model
{
    use AddUuid, AppendOnly, BelongsToSchool;

    protected $table = 'finance_ledger_transactions';

    protected $guarded = ['id'];

    protected $casts = [
        'type' => LedgerEntryType::class,
        'amount' => MoneyCast::class.':amount_minor,amount_currency',
        // posted_at is a moment (when the row was written); effective_at is a DAY (which period
        // the entry belongs to). The cast difference is the distinction, not an oversight — a
        // period is not a timestamp, and casting effective_at to datetime would invite
        // time-of-day comparisons that mean nothing about a business date.
        'posted_at' => 'datetime',
        'effective_at' => 'date',
    ];
}
