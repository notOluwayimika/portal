<?php

namespace App\Http\Controllers;

use App\Exports\GuardianImportResultExport;
use App\Exports\GuardianImportTemplateExport;
use App\Http\Requests\GuardianImportRequest;
use App\Imports\GuardianImport;
use App\Jobs\ProcessGuardianImport;
use App\Models\Import;
use App\Notifications\GuardianImportCompletedNotification;
use App\Services\GuardianImportService;
use App\Support\ActiveSchool;
use App\Support\Authz;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class GuardianImportController extends Controller
{
    private const SYNC_THRESHOLD = 50;

    public function __construct(private GuardianImportService $service) {}

    /**
     * POST /api/guardians/import
     */
    public function store(GuardianImportRequest $request)
    {
        Authz::abilityCheck(request()->user(), 'guardian.import', 'GuardianImportController@store');

        $schoolId = (int) ActiveSchool::id();

        $file = $request->file('file');
        $filePath = $file->store("imports/inbox/{$schoolId}");

        $totalRows = $this->countRows(Storage::path($filePath));

        $import = Import::create([
            'school_id' => $schoolId,
            'user_id' => $request->user()->id,
            'type' => 'guardian',
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $filePath,
            'status' => 'queued',
            'total_rows' => $totalRows,
            'update_existing_links' => filter_var($request->input('update_existing_links'), FILTER_VALIDATE_BOOLEAN),
        ]);

        if ($totalRows <= self::SYNC_THRESHOLD) {
            $this->runSync($import);
            $import->refresh();
        } else {
            ProcessGuardianImport::dispatch($import->id, $import->school_id);
        }

        return response()->json(['import' => $this->serialize($import)]);
    }

    /**
     * GET /api/guardians/import/template
     */
    public function template(Request $request)
    {
        Authz::abilityCheck(request()->user(), 'guardian.import', 'GuardianImportController@template');

        return Excel::download(new GuardianImportTemplateExport, 'guardians-import-template.xlsx');
    }

    /**
     * GET /api/guardians/import/{import:uuid}/status
     */
    public function status(Request $request, Import $import)
    {
        Authz::abilityCheck(request()->user(), 'guardian.import', 'GuardianImportController@status');
        $this->authorizeSchool($request, $import);

        return response()->json(['import' => $this->serialize($import)]);
    }

    /**
     * GET /api/guardians/import/{import:uuid}/report
     */
    public function report(Request $request, Import $import)
    {
        Authz::abilityCheck(request()->user(), 'guardian.import', 'GuardianImportController@report');
        $this->authorizeSchool($request, $import);

        if (! $import->report_path || ! Storage::exists($import->report_path)) {
            abort(404, 'Report is not available yet.');
        }

        return Storage::download($import->report_path, "guardian-import-{$import->uuid}.xlsx");
    }

    /**
     * GET /api/guardians/imports
     */
    public function index(Request $request)
    {
        Authz::abilityCheck(request()->user(), 'guardian.import', 'GuardianImportController@index');

        // Import is tenant-scoped (SchoolScope) — no explicit filter needed.
        $imports = Import::query()
            ->where('type', 'guardian')
            ->latest()
            ->limit((int) $request->integer('limit', 10))
            ->get();

        return response()->json([
            'data' => $imports->map(fn (Import $i) => $this->serialize($i)),
        ]);
    }

    /**
     * Run a small import inline using the same GuardianImport class the job uses,
     * then persist the report. Keeps sync and async paths identical.
     */
    private function runSync(Import $import): void
    {
        $import->forceFill(['status' => 'processing', 'started_at' => now()])->save();

        $this->service->reset();
        $importer = new GuardianImport($import, $this->service);

        try {
            Excel::import($importer, Storage::path($import->file_path));
        } catch (\Throwable $e) {
            $import->forceFill([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'completed_at' => now(),
            ])->save();

            return;
        }

        $reportPath = "imports/{$import->uuid}/result.xlsx";
        Excel::store(new GuardianImportResultExport($importer->getResults()), $reportPath);

        $import->forceFill([
            'status' => 'completed',
            'report_path' => $reportPath,
            'completed_at' => now(),
        ])->save();

        try {
            $import->user?->notify(new GuardianImportCompletedNotification($import));
        } catch (\Throwable) {
            // Best-effort; errors already logged by the notification queue worker.
        }
    }

    private function authorizeSchool(Request $request, Import $import): void
    {
        $schoolId = (int) ActiveSchool::id();
        // Isolation/ownership: the import must belong to the active School. Observed
        // via Authz until enforcement (SchoolScope already scopes reads; this is the
        // explicit guard on the bound resource).
        Authz::ensure($import->school_id === $schoolId, 'import.belongs_to_school', 'ownership', 'GuardianImportController@authorizeSchool', 404);
    }

    /**
     * Best-effort row count by peeking at the file. Counts only the first sheet
     * and excludes the header row and fully-empty trailing rows.
     */
    private function countRows(string $absolutePath): int
    {
        try {
            $sheets = Excel::toArray(new class {}, $absolutePath);
            $sheet = $sheets[0] ?? [];

            if (count($sheet) <= 1) {
                return 0;
            }

            // Drop the header row, then non-empty data rows.
            $dataRows = array_slice($sheet, 1);
            $nonEmpty = array_filter($dataRows, function ($row) {
                foreach ((array) $row as $value) {
                    if ($value === null) {
                        continue;
                    }
                    if (is_string($value) && trim($value) === '') {
                        continue;
                    }

                    return true;
                }

                return false;
            });

            return count($nonEmpty);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function serialize(Import $import): array
    {
        return [
            'uuid' => $import->uuid,
            'file_name' => $import->file_name,
            'status' => $import->status,
            'total_rows' => $import->total_rows,
            'processed_rows' => $import->processed_rows,
            'succeeded' => $import->succeeded,
            'failed' => $import->failed,
            'skipped' => $import->skipped,
            'update_existing_links' => (bool) $import->update_existing_links,
            'started_at' => $import->started_at?->toIso8601String(),
            'completed_at' => $import->completed_at?->toIso8601String(),
            'created_at' => $import->created_at?->toIso8601String(),
            'has_report' => (bool) $import->report_path,
            'error' => $import->error,
        ];
    }
}
