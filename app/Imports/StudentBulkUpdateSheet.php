<?php

namespace App\Imports;

use App\Models\Import;
use App\Services\StudentBulkUpdateService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class StudentBulkUpdateSheet implements ToCollection, WithHeadingRow, WithChunkReading
{
    /** @var array<int, array<string, mixed>> */
    private array $results = [];

    public function __construct(
        private Import $import,
        private StudentBulkUpdateService $service,
    ) {}

    public function collection(Collection $rows): void
    {
        $succeeded = 0;
        $failed    = 0;
        $skipped   = 0;
        $seen      = 0;

        foreach ($rows as $row) {
            $rowArray = $row instanceof Collection ? $row->toArray() : (array) $row;

            if ($this->isEmptyRow($rowArray)) {
                continue;
            }
            $seen++;

            $outcome = $this->service->processRow(
                row:      $rowArray,
                schoolId: $this->import->school_id,
            );

            $this->results[] = array_merge($rowArray, [
                'update_status'  => $outcome['status'],
                'update_message' => $outcome['message'],
            ]);

            match ($outcome['status']) {
                'success' => $succeeded++,
                'skipped' => $skipped++,
                'failed'  => $failed++,
            };
        }

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
