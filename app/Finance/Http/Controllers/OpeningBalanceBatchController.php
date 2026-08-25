<?php

namespace App\Finance\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\ApproveOpeningBalanceBatch;
use App\Finance\Actions\PostOpeningBalanceBatch;
use App\Finance\Actions\RejectOpeningBalanceBatch;
use App\Finance\Actions\SubmitOpeningBalanceBatch;
use App\Finance\Enums\OpeningBalanceBatchStatus;
use App\Finance\Enums\OpeningBalanceRowStatus;
use App\Finance\Exports\OpeningBalanceImportTemplateExport;
use App\Finance\Http\Requests\RejectOpeningBalanceBatchRequest;
use App\Finance\Http\Requests\StoreOpeningBalanceImportRequest;
use App\Finance\Http\Resources\OpeningBalanceBatchResource;
use App\Finance\Jobs\ProcessOpeningBalanceImport;
use App\Finance\Models\OpeningBalanceBatch;
use App\Finance\Services\OpeningBalanceInterpretation;
use App\Http\Controllers\GuardianImportController;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The opening-balance cutover's read surface (§9 step 5a's `pending`, §9 step 5b-i's `template`) and,
 * as of §9 step 5b-ii, ITS DECISION SURFACE: `approve` and `reject`.
 *
 * AND, AS OF §9 STEP 5b-iii, THE MAKER'S: `store`, `show`, `index`, `report` and `submit` — spec §2's
 * U12b. The read surface, the decision and the operator screen now all hang off this one controller,
 * which is deliberate: they are one aggregate, and the alternative was a second noun in the route
 * list for the same table.
 *
 * WHAT CHANGED AND WHEN, because two earlier versions of this docblock said the opposite and a reader
 * who knows either will look for it. 4c shipped Submit/Approve/RejectOpeningBalanceBatch as DOMAIN
 * ONLY — real Actions with real guards, reachable over no HTTP path at all. 5a added the pending feed
 * and said the decision was still unbuilt. 5b-ii built the decision and said submitting was still not
 * HTTP. **Both halves now exist**, and the build order was exit-before-entrance on purpose: a maker
 * who can submit a batch nothing can decide is the shipped-and-unreachable shape this feature has
 * already produced three times.
 *
 * THE MAKER'S HALF IS THE GUARDIAN IMPORT'S FLOW, deliberately and not by coincidence: template
 * download → upload → queued job → status poll → report, the shape
 * {@see GuardianImportController} already carries. What differs is that
 * guardian needs an `Import` row to track its job and this feature does not — the batch IS that
 * record — so no table was added and none should be.
 *
 * NEITHER DECISION RE-IMPLEMENTS A GUARD. The Actions own the transaction, the locked re-read of
 * `status` and `submitted_by_user_id`, and every posting mechanic; this file validates, authorizes
 * and delegates. Shape is DiscountPolicyChangeController::approve/reject: Gate::authorize on the
 * record-level maker ≠ checker Policy, BusinessRuleException → 422, the Resource back.
 *
 * `pending` follows CreditNoteController::pending / VoidRequestController::pending exactly, envelope
 * included: `{"data": [...]}`. Every feed on the queue answers in one shape, because the page maps
 * one declared list over all of them.
 */
class OpeningBalanceBatchController extends Controller
{
    /**
     * How many rejected rows the status payload carries before it is cut. Truncation is ANNOUNCED —
     * `rejected_rows_truncated` — because a cut list that looks complete is how a partial report
     * becomes a false all-clear. The full set is always in the CSV the report route streams.
     */
    private const ROWS_ON_SCREEN = 200;

    public function __construct(private readonly OpeningBalanceInterpretation $interpretation) {}

    /**
     * Every batch awaiting a second signature in the active School — School isolation is automatic
     * via BelongsToSchool on the model, exactly as the four siblings rely on it.
     *
     * `submitted` is the ONLY pending state: 4c made it the one state a batch may be approved from
     * (OpeningBalanceBatchStatus::Submitted), and `validated` explicitly is not — a validated batch
     * has not been offered for a decision yet, so it is not the checker's business.
     */
    public function pending(): JsonResponse
    {
        $batches = OpeningBalanceBatch::query()
            ->where('status', OpeningBalanceBatchStatus::Submitted->value)
            ->with('submittedBy')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => OpeningBalanceBatchResource::collection($batches),
        ]);
    }

    /**
     * §9 step 5b-i (R13) — the import template, issued BY THE PLATFORM.
     *
     * Same shape as GuardianImportController@template: a download of an export that renders the
     * validator's own COLUMNS map, so the format the operator fills in and the format the importer
     * reads back cannot drift apart. This is the download only; the upload screen is still to come.
     *
     * THE GATE IS THE MAKER ABILITY, and it coins nothing: `finance.opening-balance.submit` is the
     * submit half of the triple §9 step 4c already ships
     * (`app/Enums/Permission.php:230-232 (FINANCE_OPENING_BALANCE_SUBMIT)`). The person who
     * downloads the template is the person who will upload the file — so the checker's
     * `…approve` (which gates `pending` above) is the wrong ability here, and a template behind
     * `finance.access` alone would hand the format to everyone who can read a statement.
     *
     * It carries no School data — it is the FORMAT, not an extract — so there is nothing here for
     * SchoolScope to isolate; the route's `tenant` middleware still establishes context for the
     * permission check itself.
     */
    public function template(): BinaryFileResponse
    {
        // CSV, and the extension is not cosmetic: this screen's own upload accepts CSV only, because
        // that is what the validator's read() parses. Handing the operator an .xlsx from a button
        // sitting above an upload that refuses .xlsx is the defect §9 step 5b-iii shipped and this
        // corrects. The format the button issues and the format the next step accepts are now one.
        return Excel::download(
            new OpeningBalanceImportTemplateExport,
            'opening-balance-import-template.csv',
            ExcelFormat::CSV,
        );
    }

    /**
     * §9 step 5b-iii — the operator uploads a WCBS extract. Validation happens OFF the request.
     *
     * THE ORDER HERE IS THE WHOLE METHOD, and every step is placed where it is for a reason 4a
     * already paid for:
     *
     *  1. THE BATCH ROW FIRST, before a byte is read, so §7's idempotency key is enforced by
     *     `unique(school_id, batch_reference)` at the ENGINE. A re-upload of the same reference
     *     raises 1062, which bootstrap/app.php maps to 409 — a guard clause someone can delete is
     *     not what protects a cutover from being imported twice.
     *  2. THE CONTROL TOTAL IS WRITTEN AT THAT INSERT, passing or failing (§12 decision 2). A figure
     *     kept only when the check succeeds cannot be reviewed after a rejection, which is exactly
     *     when someone asks what was claimed.
     *  3. THE FILE IS STORED AT A PATH DERIVED FROM THE UUID, which the row now has. No column, no
     *     queue payload field — {@see ProcessOpeningBalanceImport::pathFor} is the single definition
     *     and both sides compute it.
     *  4. DISPATCH. The batch is `draft` until the job speaks, and `draft` is the enum's own word for
     *     "inserted, not yet run to completion".
     *
     * NOTHING IS VALIDATED SYNCHRONOUSLY, not even a peek at the header. A partial check here would
     * be a second implementation of the file format — the thing R13 and the extracted validator both
     * exist to prevent — and a header this accepted and the job then rejected would be worse than no
     * check at all.
     */
    public function store(StoreOpeningBalanceImportRequest $request): JsonResponse
    {
        $batch = OpeningBalanceBatch::create([
            'batch_reference' => $request->batchReference(),
            'filename' => (string) $request->file('file')->getClientOriginalName(),
            'status' => OpeningBalanceBatchStatus::Draft,
            'cutover_date' => (string) $request->input('as_at'),
            'term_id' => (int) $request->input('closing_term'),
            'control_total' => $request->controlTotal(),
            // The console run has no authenticated causer and writes null here; this path always
            // has one, and it is who the job attributes its activity to.
            'uploaded_by_user_id' => $request->user()->id,
        ]);

        $request->file('file')->storeAs(
            dirname(ProcessOpeningBalanceImport::pathFor($batch)),
            basename(ProcessOpeningBalanceImport::pathFor($batch)),
        );

        ProcessOpeningBalanceImport::dispatch($batch->id, (int) $batch->school_id);

        // `withRows: true` — the SAME shape `show` returns. The page holds one "active batch" object
        // and polls it, so an upload response missing `rejected_rows` made the very first render read
        // `.length` off undefined and blank the screen. Found by driving it, not by a test asserting
        // 201. One shape for one thing.
        return response()->json($this->serialize($batch->refresh(), withRows: true), 201);
    }

    /**
     * The status poll and the findings, in one shape. The screen polls this until the batch leaves
     * `draft`, then renders the operator report from the same payload.
     *
     * IT CARRIES THE REJECTED ROWS, AND THE PRIVACY RULE IS WHAT DECIDES WHICH FIELDS. Line numbers,
     * admission numbers, finding codes and the finding MESSAGES — never a name. The messages carry
     * both sides of a failed L1 ("Σ of this student's 2 balances = X but student_total_balance = Y"),
     * which is exactly what 4a's docblock said U12b would read them for, and they are a fact about
     * two figures in the operator's own file rather than about a person.
     */
    public function show(OpeningBalanceBatch $batch): JsonResponse
    {
        return response()->json($this->serialize($batch, withRows: true));
    }

    /**
     * The maker's recent uploads — guardian's `index`, and its isolation: the model is School-scoped
     * by BelongsToSchool, so there is no explicit filter to forget.
     */
    public function index(): JsonResponse
    {
        $batches = OpeningBalanceBatch::query()->orderByDesc('id')->limit(10)->get();

        return response()->json([
            'data' => $batches->map(fn (OpeningBalanceBatch $b) => $this->serialize($b))->all(),
        ]);
    }

    /**
     * The operator report as a downloadable CSV — guardian's per-row report, in the form this
     * feature's report actually takes.
     *
     * IT IS RENDERED ON DEMAND, not stored. Guardian writes an xlsx at the end of its job and keeps
     * the path on the `Import` row; here every figure already lives in the batch's `findings` JSON
     * and the staged rows, so a stored artifact would be a second copy that can disagree with the
     * screen. It also means no `report_path` column — this commit adds no schema.
     *
     * SAME PRIVACY RULE AS THE SCREEN, and the same reason it matters more here: a file leaves the
     * building. Line numbers, admission numbers, codes and messages.
     */
    public function report(OpeningBalanceBatch $batch): StreamedResponse
    {
        $rows = $batch->rows()
            ->where('status', OpeningBalanceRowStatus::Rejected->value)
            ->orderBy('line_number')
            ->get();

        $filename = 'opening-balance-report-'.$batch->batch_reference.'.csv';

        return response()->streamDownload(function () use ($batch, $rows) {
            $out = fopen('php://output', 'wb');

            fputcsv($out, ['scope', 'line_number', 'admission_number', 'code', 'message']);

            foreach ($batch->findings ?? [] as $finding) {
                fputcsv($out, ['batch', '', '', $finding['code'] ?? '', $finding['message'] ?? '']);
            }

            foreach ($rows as $row) {
                foreach ($row->findings ?? [] as $finding) {
                    fputcsv($out, ['row', $row->line_number, $row->admission_number, $finding['code'] ?? '', $finding['message'] ?? '']);
                }
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * §9 step 5b-iii — SUBMIT FOR APPROVAL. Nothing posts here and nothing may: the Action moves a
     * `validated` batch to `submitted` and notifies the checkers, and the post is the checker's
     * approval (§8).
     *
     * The `validated`-only rule is the Action's, re-read under lock, and this method does not copy
     * it — a PHP guard in front of a locked re-read is how the locked one goes untested.
     */
    public function submit(OpeningBalanceBatch $batch, SubmitOpeningBalanceBatch $action): JsonResponse
    {
        try {
            $submitted = $action->handle($batch, request()->user());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->serialize($submitted, withRows: true));
    }

    /**
     * The maker's wire shape for a batch. Guardian's private `serialize()`, and for its reason: the
     * upload, the poll and the list must answer identically or a screen reading all three grows a
     * per-endpoint branch.
     *
     * IT IS NOT OpeningBalanceBatchResource. That one is the approvals QUEUE's row — five types in
     * one declared shape, with `can_approve`/`can_reject` and no counters. This is the maker's view
     * of their own upload: counters, findings and whether it can be submitted. Merging them would
     * make one payload answer two questions and force every consumer to ignore half of it.
     *
     * @return array<string, mixed>
     */
    private function serialize(OpeningBalanceBatch $batch, bool $withRows = false): array
    {
        $payload = [
            'uuid' => $batch->uuid,
            'batch_reference' => $batch->batch_reference,
            'filename' => $batch->filename,
            'status' => $batch->status->value,
            'row_count' => $batch->row_count,
            'file_row_count' => $batch->file_row_count,
            // Money on the wire is always {amount_minor, currency} through the VO — never a float.
            'control_total' => $batch->control_total,
            // Not nullsafe: `cutover_date` is NOT NULL on the table and non-nullable on the model, so
            // `?->` reads as though it might be absent and Larastan refuses the pretence.
            'cutover_date' => $batch->cutover_date->toDateString(),
            'findings' => $batch->findings ?? [],
            // WHAT THIS BATCH SAYS, in the sign convention's own words. Not a finding — findings are
            // defects, and this is a correct batch stating its reading so a human can disagree with
            // it. It is the only control against an inverted convention, which L1 and L2 cannot see
            // because they compare the file against itself. Computed from the staged rows on every
            // read, so it can never describe a batch other than the one that would post.
            //
            // NULL UNTIL THE JOB HAS SPOKEN, and that is the whole point of the guard. `draft` means
            // "inserted, not yet run to completion": no row is staged yet, so the summary read an
            // empty set and stated, in the same emphatic words it uses for a real verdict, that the
            // two sides cancel exactly and that the operator should refuse the batch if that is
            // wrong — about a file nobody had read, beside the "Validating…" spinner. A control that
            // delivers a confident verdict on nothing is a control an operator learns to scroll past,
            // and this one only works if it is read.
            'interpretation' => $batch->status === OpeningBalanceBatchStatus::Draft
                ? null
                : $this->interpretation->for($batch),
            // The one flag the screen needs that it must not infer: only a `validated` batch may be
            // offered for approval, and the state machine is the server's to report.
            'can_submit' => $batch->status === OpeningBalanceBatchStatus::Validated,
            'submitted_at' => $batch->submitted_at?->toIso8601String(),
            'created_at' => $batch->created_at?->toIso8601String(),
        ];

        if (! $withRows) {
            return $payload;
        }

        $rejected = $batch->rows()
            ->where('status', OpeningBalanceRowStatus::Rejected->value)
            ->orderBy('line_number')
            ->limit(self::ROWS_ON_SCREEN + 1)
            ->get();

        // Announced, never silent — a cut list that looked complete is how a partial report becomes
        // a false all-clear. The command makes the same announcement at its own limit.
        $payload['rejected_rows_truncated'] = $rejected->count() > self::ROWS_ON_SCREEN;
        $payload['rejected_rows'] = $rejected->take(self::ROWS_ON_SCREEN)->map(fn ($row) => [
            'line_number' => $row->line_number,
            'admission_number' => $row->admission_number,
            'findings' => $row->findings ?? [],
        ])->values()->all();

        return $payload;
    }

    /**
     * §9 step 5b-ii — APPROVE, WHICH IS THE POST. There is no separate posting call and there must
     * never be one: {@see ApproveOpeningBalanceBatch} runs {@see PostOpeningBalanceBatch}
     * inside its own transaction, so this single request writes one ledger charge per positive
     * fee-type line and one netted migrated payment per student in credit — and G1b's two triggers
     * then deny every exit from `posted`, UPDATE and DELETE alike. There is no un-post, no delete and
     * no second attempt.
     *
     * That is why the queue puts a confirmation in front of this route and in front of no other type's
     * (resources/js/lib/finance/approval-feeds.ts). The confirmation is a UI courtesy, not a control:
     * the controls are the ability on the route, the Policy below, the Action's locked re-read and the
     * database's CHECK, and every one of them holds against a client that never renders a dialog.
     */
    public function approve(OpeningBalanceBatch $batch, ApproveOpeningBalanceBatch $action): JsonResponse
    {
        Gate::authorize('approve', $batch);

        try {
            $approved = $action->handle($batch, request()->user());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new OpeningBalanceBatchResource($approved));
    }

    /**
     * §9 step 5b-ii — REJECT. Moves no money: the batch goes to `rejected` with the checker's reason,
     * and its staged rows are retained so a refused cutover stays readable. A corrected extract comes
     * back as a NEW batch under a new reference (§7's idempotency key); there is no way back to
     * `validated` from here.
     */
    public function reject(RejectOpeningBalanceBatchRequest $request, OpeningBalanceBatch $batch, RejectOpeningBalanceBatch $action): JsonResponse
    {
        Gate::authorize('reject', $batch);

        try {
            $rejected = $action->handle($batch, (string) $request->input('reason'), $request->user());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new OpeningBalanceBatchResource($rejected));
    }
}
