<?php

namespace App\Finance\Jobs;

use App\Finance\Enums\OpeningBalanceBatchStatus;
use App\Finance\Models\OpeningBalanceBatch;
use App\Finance\Services\OpeningBalanceFileValidator;
use App\Jobs\Middleware\SchoolAware;
use App\Jobs\ProcessGuardianImport;
use App\Models\User;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Spatie\Activitylog\CauserResolver;
use Throwable;

/**
 * §9 step 5b-iii — the operator screen's upload, validated OFF THE REQUEST.
 *
 * WHY IT IS QUEUED AT ALL, since the console path is synchronous. A real WCBS extract is one row per
 * student PER FEE TYPE — a few thousand rows for a school — and every one of them is an INSERT. A
 * synchronous request would time out on the single day this feature is used, which is the day nobody
 * can afford to debug it. Shape follows {@see ProcessGuardianImport} because that is the
 * import flow this codebase already has: `SchoolAware` middleware, `tries = 1`, a long timeout, and
 * the causer set for AUDIT ATTRIBUTION ONLY.
 *
 * THE BATCH IS THE JOB RECORD, AND NO TABLE WAS ADDED. Guardian needs an `Import` row to track its
 * job; `finance_opening_balance_batches` already is one — it carries `status`, `findings`,
 * `row_count`, `file_row_count` and the control total, and the controller inserts it in `draft`
 * before dispatching. `draft` is exactly the enum's own words for this state: *"Inserted, not yet run
 * to completion."* The validator moves it to `validated` or `rejected`; nothing here invents a
 * status.
 *
 * NOR IS THERE A `file_path` COLUMN. The upload is stored at a path DERIVED from the batch's uuid
 * ({@see self::pathFor}), so the controller and this job compute the same location from the same
 * fact instead of passing one through a queue payload that a retry or an inspection cannot read.
 *
 * TWO FAILURES ARE DISTINGUISHED, because they are different facts about different things:
 *
 *  - THE FILE is unreadable or malformed — no header, a missing required column. That is a fact
 *    about the extract, so it becomes a BATCH FINDING in the same `findings` JSON every other
 *    file-level defect uses, and the batch is `rejected`. The operator reads it on the screen beside
 *    the rest.
 *  - THE RUN died — anything else. That is a fact about this system, not about their file, and it is
 *    recorded under its own code so nobody sends a data team to look for a defect that is ours.
 *
 * Neither case throws on. `tries = 1` means a throw would leave the batch sitting in `draft` with
 * nothing said, and a screen polling a batch that will never move is worse than one showing a
 * failure.
 */
class ProcessOpeningBalanceImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public int $batchId,
        public readonly int $schoolId,
    ) {}

    /**
     * Where a batch's uploaded extract lives, derived rather than stored.
     *
     * The uuid is the batch's own identifier and is generated at insert, so the controller can store
     * the file the moment the row exists and this job can find it with no column, no payload field
     * and no second source of truth. `school_id` is in the path as well as in the row because a
     * storage listing is one of the few places `SchoolScope` cannot help.
     */
    public static function pathFor(OpeningBalanceBatch $batch): string
    {
        return "imports/opening-balance/{$batch->school_id}/{$batch->uuid}.csv";
    }

    public function middleware(): array
    {
        return [new SchoolAware];
    }

    public function handle(OpeningBalanceFileValidator $validator): void
    {
        // The declared schoolId is the sole School context (SchoolAware -> ActiveSchool::runFor);
        // BelongsToSchool scoping and creating-fills resolve from it, never from an impersonated
        // causer (§5.6 / Constitution 13).
        $batch = OpeningBalanceBatch::find($this->batchId);
        if (! $batch instanceof OpeningBalanceBatch) {
            Log::error('ProcessOpeningBalanceImport: batch not found', ['id' => $this->batchId]);

            return;
        }

        // Audit attribution only — never an execution identity.
        $causer = $batch->uploaded_by_user_id === null ? null : User::find($batch->uploaded_by_user_id);
        if ($causer instanceof User) {
            app(CauserResolver::class)->setCauser($causer);
        }

        try {
            $this->process($batch, $validator);
        } finally {
            app(CauserResolver::class)->setCauser(null);
        }
    }

    private function process(OpeningBalanceBatch $batch, OpeningBalanceFileValidator $validator): void
    {
        $path = Storage::path(self::pathFor($batch));

        try {
            ['records' => $records, 'blankLines' => $blankLines] = $validator->read($path);
        } catch (InvalidArgumentException $e) {
            // A fact about THEIR file. Same JSON, same screen, same shape as every other file defect.
            $this->reject($batch, 'file_unreadable', $e->getMessage());

            return;
        }

        try {
            // The control total is read back off the batch rather than carried in the payload: the
            // controller wrote the operator's attestation there at insert (§12 decision 2), and a
            // second copy in the queue message could disagree with the figure the screen shows.
            $validator->stage($batch, $records, $blankLines, $batch->control_total ?? Money::fromKobo(0));
        } catch (Throwable $e) {
            Log::error('ProcessOpeningBalanceImport: failed', [
                'batch_id' => $batch->id,
                'school_id' => $this->schoolId,
                'error' => $e->getMessage(),
            ]);

            // A fact about US. Named separately so nobody hunts their extract for our defect.
            $this->reject($batch, 'import_failed',
                'The import did not finish. This is a fault in the portal, not in the file — the batch '
                .'staged nothing usable and must be re-uploaded under a NEW reference once it is fixed.');
        }
    }

    /**
     * Record a batch-level failure and stop the batch, preserving anything already in `findings`.
     *
     * `rejected` and not a new state: §8 asks for one refusal state, and the enum's own docblock
     * says `rejected` covers "at least one batch-level finding". A governance rejection stays
     * distinguishable from this one by `rejection_reason` + `decided_by_user_id`, which only a
     * checker's decision ever sets.
     */
    private function reject(OpeningBalanceBatch $batch, string $code, string $message): void
    {
        $findings = $batch->findings ?? [];
        $findings[] = ['code' => $code, 'message' => $message];

        $batch->update([
            'status' => OpeningBalanceBatchStatus::Rejected,
            'findings' => $findings,
        ]);
    }
}
