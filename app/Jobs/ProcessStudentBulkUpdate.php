<?php

namespace App\Jobs;

use App\Exports\StudentBulkUpdateResultExport;
use App\Imports\StudentBulkUpdate;
use App\Jobs\Middleware\SchoolAware;
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
use Spatie\Activitylog\CauserResolver;

class ProcessStudentBulkUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public int $importId,
        public readonly int $schoolId,
    ) {}

    public function middleware(): array
    {
        return [new SchoolAware];
    }

    public function handle(StudentBulkUpdateService $service): void
    {
        // The declared schoolId is the sole School context (SchoolAware ->
        // ActiveSchool::runFor()); never an impersonated causer (§5.6).
        $import = Import::find($this->importId);
        if (! $import) {
            Log::error('ProcessStudentBulkUpdate: import not found', ['id' => $this->importId]);

            return;
        }

        // Audit attribution only — not an execution identity.
        $causer = User::find($import->user_id);
        if ($causer) {
            app(CauserResolver::class)->setCauser($causer);
        }

        try {
            $this->process($import, $service);
        } finally {
            app(CauserResolver::class)->setCauser(null);
        }
    }

    private function process(Import $import, StudentBulkUpdateService $service): void
    {
        $import->forceFill([
            'status' => 'processing',
            'started_at' => now(),
        ])->save();

        $service->reset();
        $importer = new StudentBulkUpdate($import, $service);

        try {
            Excel::import($importer, Storage::path($import->file_path));
        } catch (\Throwable $e) {
            Log::error('ProcessStudentBulkUpdate: failed', [
                'import_id' => $import->id,
                'error' => $e->getMessage(),
            ]);
            $import->forceFill([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'completed_at' => now(),
            ])->save();

            return;
        }

        $reportPath = "imports/{$import->uuid}/result.xlsx";
        Excel::store(new StudentBulkUpdateResultExport($importer->getResults()), $reportPath);

        $import->forceFill([
            'status' => 'completed',
            'report_path' => $reportPath,
            'completed_at' => now(),
        ])->save();
    }
}
