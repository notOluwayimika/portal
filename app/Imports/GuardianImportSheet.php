<?php

namespace App\Imports;

use App\Models\Import;
use App\Services\GuardianImportService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Sheet-level processor used by GuardianImport. Streams the rows in chunks of 100,
 * delegating each row to GuardianImportService and persisting progress + per-row
 * results onto the Import model. Skips fully-empty rows so trailing blank cells
 * in spreadsheets don't inflate the failure count.
 */
class GuardianImportSheet implements ToCollection, WithHeadingRow, WithChunkReading
{
    /** @var array<int, array<string, mixed>> */
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
        $seen      = 0;

        foreach ($rows as $row) {
            $rowArray = $row instanceof Collection ? $row->toArray() : (array) $row;

            // Ignore fully-empty rows (blank trailing rows, separator rows, etc.).
            if ($this->isEmptyRow($rowArray)) {
                continue;
            }
            $seen++;

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

        $this->service->flushNotifications();

        $this->import->forceFill([
            'processed_rows' => $this->import->processed_rows + $seen,
            'succeeded'      => $this->import->succeeded + $succeeded,
            'failed'         => $this->import->failed + $failed,
            'skipped'        => $this->import->skipped + $skipped,
        ])->save();
    }

    public function chunkSize(): int
    {
        return 100;
    }

    /** @return array<int, array<string, mixed>> */
    public function getResults(): array
    {
        return $this->results;
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($value === null) continue;
            if (is_string($value) && trim($value) === '') continue;
            return false;
        }
        return true;
    }
}
