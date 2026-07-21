<?php

namespace App\Finance\Models;

use App\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * School-scoped Finance configuration (§7 of the signed accounting policy).
 *
 * At most one row per School — `UNIQUE(school_id)` in the schema, so that is a
 * database fact, not a convention this class has to defend.
 *
 * Only `invoice_number_prefix` exists today. The policy names two further
 * configurables (waiver approver §5, repeat treatment §6); they are deliberately
 * absent until they have consumers, and adding them later is an additive column.
 *
 * @property int $school_id
 * @property string|null $invoice_number_prefix
 */
class SchoolFinanceSettings extends Model
{
    use BelongsToSchool;

    protected $table = 'finance_school_settings';

    protected $guarded = ['id'];

    /**
     * The invoice-number prefix for a School, or null when none is configured.
     *
     * Reads through the School scope like any other Finance query. A School with no
     * settings row simply has no prefix — the absence of configuration is a valid,
     * expected state, not an error, which is why every School keeps its bare number
     * until someone sets one.
     */
    public static function invoiceNumberPrefixFor(int $schoolId): ?string
    {
        // Memoised per request: serialising a student's invoice list would otherwise
        // issue one settings query PER INVOICE, all for the same School. Keyed by
        // school_id, so a multi-School response stays correct.
        if (array_key_exists($schoolId, self::$prefixMemo)) {
            return self::$prefixMemo[$schoolId];
        }

        // NO withoutGlobalScopes() — the boundary lint forbids that escape hatch in
        // Finance, and it was never needed: `Invoice` is School-scoped, so any invoice
        // whose number is being rendered is already in the active School. The explicit
        // school_id keeps the memo correctly keyed; SchoolScope does the isolating.
        // A foreign School's settings therefore resolve to null and the invoice falls
        // back to its bare number — the safe degradation, not a leak.
        $prefix = static::query()
            ->where('school_id', $schoolId)
            ->value('invoice_number_prefix');

        // Treat an empty string as "unset" so a blank config value cannot produce a
        // display number that differs from the bare one by an invisible character.
        return self::$prefixMemo[$schoolId] = ($prefix === null || $prefix === '') ? null : $prefix;
    }

    /**
     * Drop the memo. Tests that change a School's prefix mid-request need this;
     * so does any long-running worker that outlives a settings change.
     */
    public static function flushPrefixMemo(): void
    {
        self::$prefixMemo = [];
    }

    /** @var array<int, string|null> */
    private static array $prefixMemo = [];
}
