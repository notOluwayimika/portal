<?php

namespace App\Finance\Http\Controllers;

use App\Finance\Actions\AwardStudentDiscount;
use App\Finance\Exports\DiscountAwardImportTemplateExport;
use App\Finance\Http\Requests\StoreDiscountAwardImportRequest;
use App\Finance\Jobs\ProcessDiscountAwardImport;
use App\Finance\Services\DiscountAwardImporter;
use App\Models\Import;
use App\Support\ActiveSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The BSS discount-award import: template out, list in, status back, report down.
 *
 * IT IS THE IMPORT FLOW THIS CODEBASE ALREADY HAS — template download → upload → queued job → status
 * poll → report — the shape `GuardianImportController` and `OpeningBalanceBatchController` both
 * carry. Nothing here is invented, and `App\Models\Import` is the job record, so this commit adds no
 * table and no migration.
 *
 * NOTHING IS VALIDATED SYNCHRONOUSLY, not even a peek at the header. A partial check here would be a
 * second implementation of the file format — the thing {@see DiscountAwardImporter::COLUMNS} exists
 * to prevent — and a header this accepted and the job then rejected would be worse than no check.
 *
 * EVERY READ IS NARROWED TO `type = 'discount_award'`, and that is not tidiness. `imports` is a
 * shared table: guardian imports live in it too, behind `guardian.import`, and their reports carry
 * guardian contact details. Binding `{import:uuid}` without the type filter would let a holder of
 * `finance.discount-award.manage` download a guardian import's report through this route. `SchoolScope`
 * would still confine them to their own school — it is not a cross-school hole — but it is a
 * cross-FEATURE one, and the ability that opens this door was coined to award discounts.
 *
 * NO SCREEN RENDERS ANY OF THIS YET. The operator page is the next commit; these four endpoints are
 * its surface and are reachable, tested and gated in the meantime.
 */
class DiscountAwardImportController extends Controller
{
    /**
     * The `imports.type` this feature owns. A string, matching guardian's `'guardian'` — the column
     * is a plain `string` and coining an enum for two values used in two files would be ceremony.
     */
    public const TYPE = 'discount_award';

    /**
     * GET the template.
     *
     * CSV, and the extension is not cosmetic: the upload below accepts CSV only, because that is what
     * the reader parses. Handing the operator an `.xlsx` from a button sitting above an upload that
     * refuses `.xlsx` is a defect this repo has already shipped once, on the opening-balance screen.
     *
     * It carries no School data — it is the FORMAT, not a list — so there is nothing here for
     * SchoolScope to isolate; the route's `tenant` middleware still establishes context for the
     * permission check itself.
     */
    public function template(): BinaryFileResponse
    {
        return Excel::download(
            new DiscountAwardImportTemplateExport,
            'discount-award-import-template.csv',
            ExcelFormat::CSV,
        );
    }

    /**
     * POST the filled-in list.
     *
     * THE ORDER IS THE METHOD. The file is stored first, because `imports.file_path` is NOT NULL and
     * the row has to carry a real path at insert; then the row, which is what the screen polls; then
     * the dispatch. `queued` is the enum's own word for "inserted, not yet run".
     *
     * `user_id` IS THE ACTOR AND NOT MERELY THE UPLOADER. The job passes it to
     * {@see AwardStudentDiscount}, which re-checks it against
     * `finance.discount-award.manage` on every row, off-request. The permission this route asserts is
     * asserted again where the write happens.
     */
    public function store(StoreDiscountAwardImportRequest $request): JsonResponse
    {
        $user = $request->user();

        // ActiveSchool, NEVER `users.school_id` — Constitution 13. The uploader's own column says
        // where they were created, not which School this request is acting in, and a user with access
        // to two schools would import into the wrong one silently.
        $schoolId = ActiveSchool::getOrFail()->id;

        $file = $request->file('file');

        $path = $file->store(ProcessDiscountAwardImport::directoryFor($schoolId));

        $import = Import::create([
            'school_id' => $schoolId,
            'user_id' => $user->id,
            'type' => self::TYPE,
            'file_name' => (string) $file->getClientOriginalName(),
            'file_path' => $path,
            'status' => 'queued',
        ]);

        ProcessDiscountAwardImport::dispatch($import->id, (int) $import->school_id);

        return response()->json(['import' => $this->serialize($import->refresh())], 201);
    }

    /**
     * The status poll. The screen polls this until the import leaves `queued`/`processing`, then
     * offers the report.
     */
    public function show(string $uuid): JsonResponse
    {
        return response()->json(['import' => $this->serialize($this->find($uuid))]);
    }

    /**
     * The report, streamed straight off storage.
     *
     * IT IS NOT RE-RENDERED HERE. The job wrote it from the outcomes it actually produced; rebuilding
     * it on download would mean re-running the import in order to describe it, and the two copies
     * could disagree about a run that has already happened.
     */
    public function report(string $uuid): StreamedResponse
    {
        $import = $this->find($uuid);

        abort_if(
            $import->report_path === null || ! Storage::exists($import->report_path),
            404,
            'This import has no report. It has not finished, or it failed before any row was read — the reason is on the import itself.',
        );

        return Storage::download(
            $import->report_path,
            'discount-award-import-report-'.$import->uuid.'.csv',
        );
    }

    /**
     * This feature's imports only, in this School only.
     *
     * `SchoolScope` (via `BelongsToSchool`) supplies the School half; the explicit `type` supplies the
     * feature half. Both are `where`s on one query rather than a bound model plus a check afterwards,
     * so a miss is a 404 that says nothing about what exists — which is the right answer to "is there
     * a guardian import with this uuid".
     */
    private function find(string $uuid): Import
    {
        return Import::query()
            ->where('type', self::TYPE)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    /**
     * The wire shape. Counters, not rows: the per-row outcomes are in the report, which is a file
     * because it is what a bursar works from.
     *
     * `skipped` IS NAMED IN THE PAYLOAD AS WHAT IT MEANS. The column is generic; on this import every
     * skipped row is a student already on exactly the policy their row named, which is the outcome a
     * re-upload produces and the one nobody should read as a failure.
     *
     * @return array<string, mixed>
     */
    private function serialize(Import $import): array
    {
        return [
            'uuid' => $import->uuid,
            'file_name' => $import->file_name,
            'status' => $import->status,
            'total_rows' => (int) $import->total_rows,
            'processed_rows' => (int) $import->processed_rows,
            'awarded' => (int) $import->succeeded,
            'already_awarded' => (int) $import->skipped,
            'rejected' => (int) $import->failed,
            'error' => $import->error,
            'has_report' => $import->report_path !== null,
            'started_at' => $import->started_at,
            'completed_at' => $import->completed_at,
        ];
    }
}
