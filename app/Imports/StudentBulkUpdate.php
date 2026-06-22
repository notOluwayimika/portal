<?php

namespace App\Imports;

use App\Models\Import;
use App\Services\StudentBulkUpdateService;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class StudentBulkUpdate implements WithMultipleSheets
{
    private StudentBulkUpdateSheet $sheet;

    public function __construct(Import $import, StudentBulkUpdateService $service)
    {
        $this->sheet = new StudentBulkUpdateSheet($import, $service);
    }

    public function sheets(): array
    {
        return [0 => $this->sheet];
    }

    /** @return array<int, array<string, mixed>> */
    public function getResults(): array
    {
        return $this->sheet->getResults();
    }
}
