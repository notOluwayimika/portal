<?php

namespace App\Jobs;

use App\Exports\GuardianImportResultExport;
use App\Imports\GuardianImport;
use App\Models\Import;
use App\Models\User;
use App\Notifications\GuardianImportCompletedNotification;
use App\Services\GuardianImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ProcessGuardianImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 1;

    public function __construct(public int $importId) {}

    public function handle(GuardianImportService $service): void
    {
        $import = Import::find($this->importId);
        if (!$import) {
            Log::error('ProcessGuardianImport: import not found', ['id' => $this->importId]);
            return;
        }

        // Re-establish auth context so BelongsToSchool global scope picks up the right school.
        $causer = User::find($import->user_id);
        if ($causer) {
            auth()->setUser($causer);
        }

        $import->forceFill([
            'status'     => 'processing',
            'started_at' => now(),
        ])->save();

        $service->reset();
        $importer = new GuardianImport($import, $service);

        try {
            Excel::import($importer, Storage::path($import->file_path));
        } catch (\Throwable $e) {
            Log::error('ProcessGuardianImport: failed', [
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

        // Persist result report.
        $reportPath = "imports/{$import->uuid}/result.xlsx";
        Excel::store(new GuardianImportResultExport($importer->getResults()), $reportPath);

        $import->forceFill([
            'status'       => 'completed',
            'report_path'  => $reportPath,
            'completed_at' => now(),
        ])->save();

        if ($causer) {
            try {
                $causer->notify(new GuardianImportCompletedNotification($import));
            } catch (\Throwable $e) {
                Log::error('Failed to send guardian import completion email', [
                    'import_id' => $import->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
    }
}
