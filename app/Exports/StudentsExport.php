<?php

namespace App\Exports;

use App\Models\Student;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class StudentsExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize
{
    public function __construct(protected Request $request) {}

    public function query()
    {
        return Student::query()
            ->when($this->request->search, function ($q) {
                $term = '%' . $this->request->search . '%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('first_name', 'LIKE', $term)
                          ->orWhere('last_name', 'LIKE', $term)
                          ->orWhere('admission_number', 'LIKE', $term);
                });
            })
            ->with([
                'currentCurriculum.curriculum.classLevelArm.classLevel',
                'currentCurriculum.curriculum.classLevelArm.arm',
                'currentCurriculum.curriculum.classLevelArm.stream',
            ])
            ->latest();
    }

    public function headings(): array
    {
        return [
            'Admission Number',
            'First Name',
            'Middle Name',
            'Last Name',
            'Gender',
            'Date of Birth',
            'Class',
            'Status',
        ];
    }

    public function map($student): array
    {
        $currentCurriculum = $student->currentCurriculum;
        $classLevelArm     = $currentCurriculum?->curriculum?->classLevelArm;

        $class = implode(' ', array_filter([
            $classLevelArm?->classLevel?->name,
            $classLevelArm?->arm?->label,
            $classLevelArm?->stream?->name
                ? "({$classLevelArm->stream->name})"
                : null,
        ])) ?: 'N/A';

        return [
            $student->admission_number ?? '',
            $student->first_name,
            $student->middle_name ?? '',
            $student->last_name,
            $student->gender ?? '',
            $student->date_of_birth
                ? \Carbon\Carbon::parse($student->date_of_birth)->format('Y-m-d')
                : '',
            $class,
            $currentCurriculum?->status?->value ?? '',
        ];
    }
}
