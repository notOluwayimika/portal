<?php

namespace App\Imports;

use App\Models\Import;
use App\Services\GuardianImportService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Streams a guardian import file in chunks of 100, delegating each row to
 * GuardianImportService and persisting progress + per-row results onto the
 * Import model. The accumulated results are exposed via getResults() so the
 * caller (controller for sync, job for queued) can generate the report.
 */
class GuardianImport implements ToCollection, WithHeadingRow, WithChunkReading
{
    /**
     * Per-row results, parallel to the source rows. Each entry: original_row + status + message + guardian_id.
     * @var array<int, array<string, mixed>>
     */
    private array $results = [];

    public function __construct(
        private Import $import,
        private GuardianImportService $service,
    ) {}

    public function collection(Collection $rows): void
    {
        $succeeded = 0;
        $failed    = 0;
        $skipped   = 0;

        foreach ($rows as $row) {
            $rowArray = $row instanceof Collection ? $row->toArray() : (array) $row;

            $outcome = $this->service->processRow(
                row:                 $rowArray,
                schoolId:            $this->import->school_id,
                updateExistingLinks: (bool) $this->import->update_existing_links,
            );

            $this->results[] = array_merge($rowArray, [
                'import_status'  => $outcome['status'],
                'import_message' => $outcome['message'],
                'guardian_id'    => $outcome['guardian_id'],
            ]);

            match ($outcome['status']) {
                'success' => $succeeded++,
                'skipped' => $skipped++,
                'failed'  => $failed++,
            };
        }

        // Flush deferred notifications collected during this chunk's row transactions.
        $this->service->flushNotifications();

        // Persist chunk progress atomically.
        $this->import->forceFill([
            'processed_rows' => $this->import->processed_rows + $rows->count(),
            'succeeded'      => $this->import->succeeded + $succeeded,
            'failed'         => $this->import->failed + $failed,
            'skipped'        => $this->import->skipped + $skipped,
        ])->save();
    }

    public function chunkSize(): int
    {
        return 100;
    }

    /**
     * Per-row outcomes accumulated across all chunks — used to build the result report.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getResults(): array
    {
        return $this->results;
    }
}
