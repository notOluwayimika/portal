<?php

namespace App\Finance\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Enums\VoidRequestStatus;
use App\Finance\Exceptions\LedgerImmutableException;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A request to void an invoice — Ph3b maker-checker. Structurally a twin of {@see CreditNote}'s
 * lifecycle (submitted_by / decided_by / decided_at / rejection_reason + a DB CHECK making
 * submitted_by = decided_by unrepresentable, + a money-immutable/status-mutable trigger, DELETE
 * denied). What differs is the SEMANTICS: this document carries no money of its own — approval
 * flips the invoice to void and posts the reversing ledger entry ({@see App\Finance\Actions\ApproveVoidRequest}).
 *
 * The invoice is NOT touched until approval — it stays 'issued', in the balance, and occupying
 * its F7 "one active invoice per episode" slot while a request is pending. `open_key` (a stored
 * generated column = invoice_id while submitted, else NULL) + UNIQUE enforces one open request
 * per invoice.
 *
 * @property int $id
 * @property string $uuid
 * @property int $school_id
 * @property int $invoice_id
 * @property string $reason
 * @property VoidRequestStatus $status
 * @property int|null $submitted_by
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $rejection_reason
 * @property Carbon $created_at
 */
class VoidRequest extends Model
{
    use AddUuid, BelongsToSchool;

    protected $table = 'finance_void_requests';

    protected $guarded = ['id'];

    protected $casts = [
        'status' => VoidRequestStatus::class,
        'decided_at' => 'datetime',
    ];

    /**
     * Legal state machine (mirrors CreditNote::TRANSITIONS). Created directly in `submitted`;
     * `approved` and `rejected` are both TERMINAL — rework is a fresh request, the rejected one
     * kept for audit.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        'submitted' => ['approved', 'rejected'],
        'approved' => [],
        'rejected' => [],
    ];

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new LedgerImmutableException('DELETE');
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === VoidRequestStatus::Submitted;
    }

    /** Is $to a legal next state from the current status? */
    public function canTransitionTo(VoidRequestStatus $to): bool
    {
        // status is a VoidRequestStatus enum → its value is always a TRANSITIONS key.
        return in_array($to->value, self::TRANSITIONS[$this->status->value], true);
    }

    /**
     * Apply a decision (approve / reject) — records the checker in decided_by and stamps
     * decided_at. Mirrors CreditNote::transitionTo: an illegal transition throws, and a
     * rejection with no reason is refused at the domain layer. This performs ONLY the status
     * write; ApproveVoidRequest flips the invoice + posts the reversal in the SAME transaction.
     * decided_by is the CHECKER — the DB CHECK and the Policy both refuse a checker who is the
     * maker, so this never re-checks it.
     */
    public function transitionTo(VoidRequestStatus $to, int $checkerId, ?string $reason = null): void
    {
        if (! $this->canTransitionTo($to)) {
            throw new \DomainException("Cannot transition void request from [{$this->status->value}] to [{$to->value}].");
        }

        if ($to === VoidRequestStatus::Rejected && ($reason === null || trim($reason) === '')) {
            throw new \InvalidArgumentException('A rejection reason is required.');
        }

        $this->update([
            'status' => $to,
            'decided_by' => $checkerId,
            'decided_at' => now(),
            'rejection_reason' => $to === VoidRequestStatus::Rejected ? $reason : null,
        ]);
    }
}
