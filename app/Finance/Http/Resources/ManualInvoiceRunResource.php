<?php

namespace App\Finance\Http\Resources;

use App\Finance\Models\ManualInvoiceRun;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The run's own row — status, who started it, when, and the counters the JOB recorded.
 *
 * WHAT IS *NOT* HERE: the live figures. `ManualInvoiceRunController::show()` re-derives
 * `target_count` and the four outcome counts from the `targets` and `rows` tables and adds them
 * beside this payload, precisely so the report has TWO independent sources rather than one. The
 * counters below are what the job believed at the moment it finished; the live ones are what the
 * tables actually hold. A report that showed only these could not tell a correct run from a run
 * whose reconciliation wrote the wrong number.
 *
 * NULL IS NOT ZERO, ON THE WIRE. Every counter here is nullable in the schema and is passed through
 * UNCAST. A `(int)` on the way out turns "this run has not reconciled yet" into "this run billed
 * nobody" — the §26 state-collapse defect, which has shipped five times in this project and whose
 * most recent instance was pinned by `BulkInvoiceRunScreenTest`'s third claim. `has_figures` is the
 * flag a client should branch on; it reads `target_count`, which the reconciliation writes in the
 * same statement as the other four, so it cannot be true while the rest are null.
 *
 * @mixin ManualInvoiceRun
 */
class ManualInvoiceRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $hasFigures = $this->target_count !== null;

        return [
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'is_terminal' => $this->status->isTerminal(),

            'started_by_name' => $this->whenLoaded('startedBy', fn () => $this->startedBy instanceof User ? $this->startedBy->name : null),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            'failure_reason' => $this->failure_reason,

            'has_figures' => $hasFigures,

            /*
             * WHAT THE JOB RECORDED. `claimed` sits in this list and is NOT a term of the equality
             * below it — the line between them is whether anything is UNKNOWN. An unplaceable
             * student is a finished, correct, reported outcome; a claimed row is a run that does not
             * know what happened to that student, and it is exactly the shortfall. Folding it into
             * the left-hand side balances the sum on precisely the runs the sum exists to catch.
             */
            'recorded' => [
                'target' => $this->target_count,
                'billed' => $this->billed_count,
                'failed' => $this->failed_count,
                'unplaceable' => $this->unplaceable_count,
                'claimed' => $this->claimed_count,
            ],
        ];
    }
}
