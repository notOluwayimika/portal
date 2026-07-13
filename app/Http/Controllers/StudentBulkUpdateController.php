<?php

namespace App\Http\Controllers;

use App\Exports\StudentBulkUpdateResultExport;
use App\Exports\StudentBulkUpdateTemplateExport;
use App\Imports\StudentBulkUpdate;
use App\Jobs\ProcessStudentBulkUpdate;
use App\Models\Import;
use App\Services\StudentBulkUpdateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class StudentBulkUpdateController extends Controller
{
    private const SYNC_THRESHOLD = 50;

    public function __construct(private StudentBulkUpdateService $service) {}

    public function store(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        $schoolId = (int) \App\Support\ActiveSchool::id();

        $file = $request->file('file');
        $filePath = $file->store("imports/inbox/{$schoolId}");

        $totalRows = $this->countRows(Storage::path($filePath));

        $import = Import::create([
            'school_id'  => $schoolId,
            'user_id'    => $request->user()->id,
            'type'       => 'student_bulk_update',
            'file_name'  => $file->getClientOriginalName(),
            'file_path'  => $filePath,
            'status'     => 'queued',
            'total_rows' => $totalRows,
        ]);

        if ($totalRows <= self::SYNC_THRESHOLD) {
            $this->runSync($import);
            $import->refresh();
        } else {
            ProcessStudentBulkUpdate::dispatch($import->id);
        }

        return response()->json(['import' => $this->serialize($import)]);
    }

    public function template(Request $request)
    {
        return Excel::download(new StudentBulkUpdateTemplateExport(), 'student-bulk-update-template.xlsx');
    }

    public function status(Request $request, Import $import)
    {
        $this->authorizeSchool($request, $import);
        return response()->json(['import' => $this->serialize($import)]);
    }

    public function report(Request $request, Import $import)
    {
        $this->authorizeSchool($request, $import);

        if (!$import->report_path || !Storage::exists($import->report_path)) {
            abort(404, 'Report is not available yet.');
        }

        return Storage::download($import->report_path, "student-bulk-update-{$import->uuid}.xlsx");
    }

    public function index(Request $request)
    {
        // Import is tenant-scoped (SchoolScope) — no explicit filter needed.
        $imports = Import::query()
            ->where('type', 'student_bulk_update')
            ->latest()
            ->limit($request->integer('limit', 10))
            ->get();

        return response()->json([
            'data' => $imports->map(fn(Import $i) => $this->serialize($i)),
        ]);
    }

    private function runSync(Import $import): void
    {
        $import->forceFill(['status' => 'processing', 'started_at' => now()])->save();

        $this->service->reset();
        $importer = new StudentBulkUpdate($import, $this->service);

        try {
            Excel::import($importer, Storage::path($import->file_path));
        } catch (\Throwable $e) {
            $import->forceFill([
                'status'       => 'failed',
                'error'        => $e->getMessage(),
                'completed_at' => now(),
            ])->save();
            return;
        }

        $reportPath = "imports/{$import->uuid}/result.xlsx";
        Excel::store(new StudentBulkUpdateResultExport($importer->getResults()), $reportPath);

        $import->forceFill([
            'status'       => 'completed',
            'report_path'  => $reportPath,
            'completed_at' => now(),
        ])->save();
    }

    private function authorizeSchool(Request $request, Import $import): void
    {
        $schoolId = (int) \App\Support\ActiveSchool::id();
        abort_unless($import->school_id === $schoolId, 404);
    }

    private function countRows(string $absolutePath): int
    {
        try {
            $sheets = Excel::toArray(new class {}, $absolutePath);
            $sheet = $sheets[0] ?? [];

            if (count($sheet) <= 1) {
                return 0;
            }

            $dataRows = array_slice($sheet, 1);
            $nonEmpty = array_filter($dataRows, function ($row) {
                foreach ((array) $row as $value) {
                    if ($value === null) continue;
                    if (is_string($value) && trim($value) === '') continue;
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
            'uuid'           => $import->uuid,
            'file_name'      => $import->file_name,
            'status'         => $import->status,
            'total_rows'     => $import->total_rows,
            'processed_rows' => $import->processed_rows,
            'succeeded'      => $import->succeeded,
            'failed'         => $import->failed,
            'skipped'        => $import->skipped,
            'started_at'     => $import->started_at?->toIso8601String(),
            'completed_at'   => $import->completed_at?->toIso8601String(),
            'created_at'     => $import->created_at?->toIso8601String(),
            'has_report'     => (bool) $import->report_path,
            'error'          => $import->error,
        ];
    }
}
