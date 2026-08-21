<?php

namespace App\Exports;

use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class StudentsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    public function __construct(protected Request $request) {}

    public function query()
    {
        return Student::query()
            ->when($this->request->search, function ($q) {
                $term = '%'.$this->request->search.'%';
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
                // Eager-loaded, not lazy: map() runs once per student, so reading these through the
                // relation without loading them here is one query per row per relation on an export
                // that can span the whole school.
                'sportHouse',
                'scholarship',
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
            // APPENDED, not interleaved. The eight columns above keep their positions so anything
            // downstream that reads this file by column index still lines up.
            'Admission Date',
            'Sport House',
            'Scholarship',
            'Nationality',
            'Other Nationality',
            'State of Origin',
            'Religion',
            'Previous School',
            'Address',
        ];
    }

    public function map($student): array
    {
        $currentCurriculum = $student->currentCurriculum;
        $classLevelArm = $currentCurriculum?->curriculum?->classLevelArm;

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
                ? Carbon::parse($student->date_of_birth)->format('Y-m-d')
                : '',
            $class,
            $currentCurriculum?->status?->value ?? '',
            // `admission_date` is cast to `date` on the model, so it is a Carbon instance or null —
            // no parse() needed, unlike `date_of_birth` above, which is not cast.
            $student->admission_date?->format('Y-m-d') ?? '',
            $student->sportHouse->name ?? '',
            $student->scholarship->name ?? '',
            $student->nationality ?? '',
            $student->other_nationality ?? '',
            $student->state_of_origin ?? '',
            $student->religion ?? '',
            $student->previous_school ?? '',
            $student->address ?? '',
        ];
    }
}
