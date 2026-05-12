<?php

namespace App\Exports;

use App\Models\Teacher;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class TeachersExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize
{
    public function __construct(protected Request $request) {}

    public function query()
    {
        return Teacher::query()
            ->when($this->request->search, function ($q) {
                $term = '%' . $this->request->search . '%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('first_name', 'LIKE', $term)
                          ->orWhere('last_name', 'LIKE', $term)
                          ->orWhere('staff_number', 'LIKE', $term);
                });
            })
            ->with(['user'])
            ->latest();
    }

    public function headings(): array
    {
        return [
            'Staff Number',
            'First Name',
            'Last Name',
            'Email',
            'Gender',
            'Date of Birth',
            'Address',
            'Qualification',
            'Hire Date',
            'Status',
        ];
    }

    public function map($teacher): array
    {
        return [
            $teacher->staff_number ?? '',
            $teacher->first_name,
            $teacher->last_name,
            $teacher->user?->email ?? '',
            $teacher->gender ?? '',
            $teacher->date_of_birth
                ? \Carbon\Carbon::parse($teacher->date_of_birth)->format('Y-m-d')
                : '',
            $teacher->address ?? '',
            $teacher->qualification ?? '',
            $teacher->hire_date
                ? \Carbon\Carbon::parse($teacher->hire_date)->format('Y-m-d')
                : '',
            $teacher->status ?? '',
        ];
    }
}
