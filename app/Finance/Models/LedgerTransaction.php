<?php

namespace App\Finance\Models;

use App\Casts\MoneyCast;
use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Enums\LedgerEntryType;
use App\Finance\Models\Concerns\AppendOnly;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

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
 */
class LedgerTransaction extends Model
{
    use AddUuid, AppendOnly, BelongsToSchool;

    protected $table = 'fee_ledger_transactions';

    protected $guarded = ['id'];

    protected $casts = [
        'type' => LedgerEntryType::class,
        'amount' => MoneyCast::class.':amount_minor,amount_currency',
    ];
}
