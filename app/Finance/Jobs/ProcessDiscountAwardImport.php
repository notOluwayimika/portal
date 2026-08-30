<?php

namespace App\Finance\Jobs;

use App\Finance\Actions\AwardStudentDiscount;
use App\Finance\Enums\DiscountAwardImportOutcome;
use App\Finance\Services\DiscountAwardImporter;
use App\Jobs\Middleware\SchoolAware;
use App\Jobs\ProcessGuardianImport;
use App\Models\Import;
use App\Models\User;
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
 * The BSS discount-award list, validated and applied OFF THE REQUEST.
 *
 * SHAPE FOLLOWS {@see ProcessGuardianImport}, which is the import flow this codebase already has:
 * `SchoolAware` middleware, `tries = 1`, a long timeout, the causer set for AUDIT ATTRIBUTION ONLY
 * and cleared in a `finally`. `App\Models\Import` is the job record — it already carries `status`,
 * `total_rows`, `processed_rows`, `succeeded`, `failed`, `skipped`, `report_path` and `error`, which
 * is every field this needs, so NO TABLE IS ADDED and none should be.
 *
 * WHY IT IS QUEUED AT ALL. Brookstone's list is roughly one row per scholarship holder and each row
 * is a resolve plus an insert plus an audit entry inside its own transaction. That is small — but it
 * is the same shape as the opening-balance import, it is run on days nobody can afford to debug a
 * timeout, and a synchronous path would have to be a second implementation of the loop. One path.
 *
 * TWO FAILURES ARE DISTINGUISHED, because they are facts about different things:
 *
 *  - THE FILE is unreadable or malformed — no header, a missing required column. That is a fact
 *    about their sheet, so it is written to `error` in words the uploader can act on, and the import
 *    is `failed`.
 *  - THE RUN died — anything else. That is a fact about this system, not about their file, and it
 *    says so, so nobody sends the accounts team hunting a defect that is ours.
 *
 * Neither throws on. `tries = 1` means a throw would leave the import sitting in `processing` with
 * nothing said, and a screen polling a row that will never move is worse than one showing a failure.
 *
 * THE UPLOADER IS THE ACTOR, NOT MERELY THE CAUSER, and that distinction is the point of this job's
 * one deviation from guardian's. {@see AwardStudentDiscount} gates on the actor
 * it is passed, so `imports.user_id` is re-checked against `finance.discount-award.manage` on every
 * row, HERE, off-request, with no route middleware anywhere near it. If the grant was revoked
 * between the upload and the run, the rows are refused — which is the whole reason the gate is on
 * the Action and not only on the route.
 */
class ProcessDiscountAwardImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public int $importId,
        public readonly int $schoolId,
    ) {}

    /**
     * The directory an upload lands in. `school_id` is in the path as well as on the row because a
     * storage listing is one of the few places `SchoolScope` cannot help.
     *
     * THE UPLOAD'S OWN PATH IS NOT DERIVED, unlike the opening-balance import's — it is stored, in
     * `imports.file_path`, because that column exists and is exactly what it is for. OB derives its
     * path from a uuid precisely BECAUSE its batch table has no such column and adding one would have
     * been a second source of truth; here the column is the first source and deriving beside it would
     * be the second.
     */
    public static function directoryFor(int $schoolId): string
    {
        return "imports/discount-award/{$schoolId}";
    }

    /**
     * The report's path, which IS derived — from the import's uuid, and only after the row exists.
     * It is written by this job and read by the download route; nothing else needs to agree with it,
     * and `report_path` records where it actually went.
     */
    public static function reportPathFor(Import $import): string
    {
        return self::directoryFor((int) $import->school_id)."/{$import->uuid}-report.csv";
    }

    /**
     * THE SAME OUTCOMES, AS STRUCTURED DATA, for the screen to render.
     *
     * WHY THIS EXISTS AT ALL. The CSV is a rendering — the outcomes are computed as an array of rows
     * in {@see self::finish()} and then flattened into text. The operator screen has to show every row
     * keyed by the line number and admission number the bursar typed, and the only alternative to
     * writing the structure down here is to PARSE THE CSV BACK, in the client, in TypeScript. That is
     * a hand-rolled parser over commas inside reason sentences, quotes inside a verbatim cell an
     * operator typed, and whatever encoding their spreadsheet chose — and it would fail on exactly the
     * unusual row the bursar most needs to read. Both files are written from ONE `$results` array, in
     * one method, so they cannot describe different runs.
     *
     * IT IS A FILE AND NOT A COLUMN. `imports` is shared with the guardian import
     * (2026_05_15_000000_create_imports_table.php) and carries no JSON column; adding one would be a
     * migration on a shared table for a per-feature payload, and the table already has the pattern for
     * "the outcome lives in storage" in `report_path`. This path is DERIVED from the uuid, the way
     * `reportPathFor` above is derived and for the same reason — nothing else has to agree with it.
     *
     * AN IMPORT THAT RAN BEFORE THIS COMMIT HAS NO SUCH FILE. That is why the controller reports
     * `rows` as null rather than as an empty list: "this run predates the structured report" and "this
     * run produced no rows" are different facts and the screen says different things about them.
     */
    public static function rowsPathFor(Import $import): string
    {
        return self::directoryFor((int) $import->school_id)."/{$import->uuid}-rows.json";
    }

    public function middleware(): array
    {
        return [new SchoolAware];
    }

    public function handle(DiscountAwardImporter $importer): void
    {
        // The declared schoolId is the sole School context (SchoolAware -> ActiveSchool::runFor);
        // BelongsToSchool scoping and creating-fills resolve from it, never from an impersonated
        // causer (Constitution 13).
        $import = Import::find($this->importId);
        if (! $import instanceof Import) {
            Log::error('ProcessDiscountAwardImport: import not found', ['id' => $this->importId]);

            return;
        }

        // Audit attribution only — never an execution identity. Authorization is a separate question
        // and is answered by the Action, from `user_id`, per row.
        $causer = User::find($import->user_id);
        if ($causer instanceof User) {
            app(CauserResolver::class)->setCauser($causer);
        }

        try {
            $this->process($import, $importer);
        } finally {
            app(CauserResolver::class)->setCauser(null);
        }
    }

    private function process(Import $import, DiscountAwardImporter $importer): void
    {
        $import->update(['status' => 'processing', 'started_at' => now()]);

        try {
            ['records' => $records] = $importer->read(Storage::path((string) $import->file_path));
        } catch (InvalidArgumentException $e) {
            // A fact about THEIR file, in their words.
            $this->fail($import, $e->getMessage());

            return;
        }

        try {
            $results = $importer->import($import, $records);
        } catch (Throwable $e) {
            Log::error('ProcessDiscountAwardImport: failed', [
                'import_id' => $import->id,
                'school_id' => $this->schoolId,
                'error' => $e->getMessage(),
            ]);

            // A fact about US. Named separately so nobody hunts their sheet for our defect.
            //
            // IT DOES NOT CLAIM NOTHING WAS AWARDED. Rows are applied one at a time and each commits
            // on its own, so a mid-run fault leaves the rows before it awarded — saying otherwise
            // would send someone looking for a rollback that did not happen. The report is not
            // written on this path (there is no complete result to write), which is why the sentence
            // points at the students' own records instead.
            $this->fail(
                $import,
                'The import stopped partway through. This is a fault in the portal, not in your file. '
                .'Rows are applied one at a time, so some students may already have been awarded — '
                .'check before re-uploading; a student who was awarded will be reported as already '
                .'awarded on the next run, not awarded twice.'
            );

            return;
        }

        $this->finish($import, $results);
    }

    /**
     * Tally the outcomes, write the report, complete the import.
     *
     * THE THREE COUNTERS ARE THE ENUM'S THREE CASES, one each, and `processed_rows` is their sum
     * rather than a fourth thing incremented in a loop. A counter maintained separately from the
     * thing it counts is a counter that can disagree with it.
     *
     * @param  list<array{line_number: int, admission_number: string, discount_percentage: string, discount_applies_to: string, outcome: DiscountAwardImportOutcome, reason: string}>  $results
     */
    private function finish(Import $import, array $results): void
    {
        $count = fn (DiscountAwardImportOutcome $outcome): int => count(array_filter(
            $results,
            fn (array $r) => $r['outcome'] === $outcome,
        ));

        $succeeded = $count(DiscountAwardImportOutcome::Awarded);
        $skipped = $count(DiscountAwardImportOutcome::AlreadyAwarded);
        $failed = $count(DiscountAwardImportOutcome::Rejected);

        // TWO RENDERINGS OF ONE ARRAY, written before the row is completed. The CSV is what a bursar
        // downloads and works from; the JSON is what the screen renders. Neither is derived from the
        // other — deriving would mean parsing back structure this method already has — and both come
        // from `$results`, so a run cannot be described two ways.
        $reportPath = self::reportPathFor($import);
        Storage::put($reportPath, $this->renderReport($results));
        Storage::put(self::rowsPathFor($import), $this->renderRows($results));

        $import->update([
            'status' => 'completed',
            'total_rows' => count($results),
            'processed_rows' => $succeeded + $skipped + $failed,
            'succeeded' => $succeeded,
            'skipped' => $skipped,
            'failed' => $failed,
            'report_path' => $reportPath,
            'completed_at' => now(),
        ]);
    }

    /**
     * THE REPORT, AND IT IS FOR THE BURSAR WHO FILLED IN THE SHEET.
     *
     * EVERY ROW APPEARS, not only the refused ones. "Did my upload land" is the question the file is
     * opened to answer, and a report listing only failures answers it by absence — which reads
     * identically to a report that failed to run.
     *
     * EACH ROW IS IDENTIFIED BY WHAT THEY TYPED: their line number and their own three cells,
     * verbatim. Nothing is read back out of the database to name a row — no student name, no
     * admission number normalised into something they did not write. A trailing space they cannot
     * see on screen is exactly what they need shown back to them.
     *
     * NO SQL AND NO BINDINGS, EVER — {@see DiscountAwardImporter} catches `QueryException` ahead of
     * the generic arm precisely because this file leaves the building.
     *
     * RENDERED ON DEMAND INTO STORAGE rather than assembled by the download route, because `imports`
     * already has a `report_path` column and this is what it is for. The alternative — recomputing
     * the report when it is asked for — would mean re-running the import to describe it.
     *
     * @param  list<array{line_number: int, admission_number: string, discount_percentage: string, discount_applies_to: string, outcome: DiscountAwardImportOutcome, reason: string}>  $results
     */
    private function renderReport(array $results): string
    {
        $handle = fopen('php://temp', 'r+b');

        fputcsv($handle, [
            'line_number',
            'admission_number',
            'discount_percentage',
            'discount_applies_to',
            'outcome',
            'reason',
        ]);

        foreach ($results as $result) {
            fputcsv($handle, [
                $result['line_number'],
                $result['admission_number'],
                $result['discount_percentage'],
                $result['discount_applies_to'],
                $result['outcome']->value,
                $result['reason'],
            ]);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * The same rows the CSV carries, as JSON, for {@see DiscountAwardImportController::serialize()}.
     *
     * THE KEYS ARE THE REPORT'S COLUMN NAMES, deliberately: the screen and the downloaded file show a
     * bursar the same six things under the same names, so "the report says X and the screen says Y" is
     * not a sentence anybody can utter about one run.
     *
     * The outcome is written as the enum's VALUE, not its name — `awarded` / `already_awarded` /
     * `rejected` are what the CSV carries and what the client's union declares.
     *
     * @param  list<array{line_number: int, admission_number: string, discount_percentage: string, discount_applies_to: string, outcome: DiscountAwardImportOutcome, reason: string}>  $results
     */
    private function renderRows(array $results): string
    {
        return (string) json_encode(array_map(fn (array $result) => [
            'line_number' => $result['line_number'],
            'admission_number' => $result['admission_number'],
            'discount_percentage' => $result['discount_percentage'],
            'discount_applies_to' => $result['discount_applies_to'],
            'outcome' => $result['outcome']->value,
            'reason' => $result['reason'],
        ], $results), JSON_THROW_ON_ERROR);
    }

    private function fail(Import $import, string $message): void
    {
        $import->update([
            'status' => 'failed',
            'error' => $message,
            'completed_at' => now(),
        ]);
    }
}
