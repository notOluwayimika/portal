<?php

namespace App\Jobs;

use App\Exports\StudentBulkUpdateResultExport;
use App\Imports\StudentBulkUpdate;
use App\Models\Import;
use App\Models\User;
use App\Services\StudentBulkUpdateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ProcessStudentBulkUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 1;

    public function __construct(public int $importId) {}

    public function handle(StudentBulkUpdateService $service): void
    {
        $import = Import::find($this->importId);
        if (!$import) {
            Log::error('ProcessStudentBulkUpdate: import not found', ['id' => $this->importId]);
            return;
        }

        $causer = User::find($import->user_id);
        if ($causer) {
            auth()->setUser($causer);
        }

        $import->forceFill([
            'status'     => 'processing',
            'started_at' => now(),
        ])->save();

        $service->reset();
        $importer = new StudentBulkUpdate($import, $service);

        try {
            Excel::import($importer, Storage::path($import->file_path));
        } catch (\Throwable $e) {
            Log::error('ProcessStudentBulkUpdate: failed', [
                'import_id' => $import->id,
                'error'     => $e->getMessage(),
            ]);
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
}
