<?php

namespace App\Exports;

use App\Models\Student;
use App\Services\StudentIndexFilters;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * The students export, in its two honest scopes.
 *
 * ── TWO SCOPES, EACH NAMED IN THE CONTROL THAT TRIGGERS IT ───────────────────────────────────────
 * The toolbar's **Export** takes the CURRENT FILTER SET, computed here from the same
 * StudentIndexFilters the index paginates through. The footer's **Export selected (N)** takes
 * EXACTLY the checked ids and nothing else. They are orthogonal: the filter export ignores
 * selection entirely, and the selection export ignores the filters entirely.
 *
 * There is deliberately no "select all matching" concept anywhere between them. That control exists
 * on the guardians index and is a lie there — it materialises only the ids the client happens to
 * hold — and the reason it is not needed here is that a filter-scoped export already answers the
 * question it was invented to answer, without pretending the browser knows about rows it never
 * received.
 *
 * ── THE FILTER SCOPE WAS THE DEFECT ──────────────────────────────────────────────────────────────
 * This exporter used to read `search` and nothing else, while the index filtered on search, class
 * level and arm. So an operator who narrowed to one class and pressed Export silently downloaded
 * the whole school and had no way to tell. Both readers now share one definition.
 */
class StudentsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    /**
     * @param  array<int, string>|null  $onlyUuids  exactly the pupils to export; null means
     *                                              "whatever the request's filters select"
     */
    public function __construct(
        protected Request $request,
        protected ?array $onlyUuids = null,
    ) {}

    public function query()
    {
        $query = Student::query();

        // THE ID SCOPE SHORT-CIRCUITS THE FILTERS RATHER THAN COMPOSING WITH THEM. An operator who
        // ticks four pupils and presses "Export selected (4)" gets four rows, even if the filters
        // behind the page would exclude one of them — the control says which pupils, so the control
        // decides. Composing the two would silently return three and the label would still say 4.
        //
        // Isolation holds without a school predicate here: Student carries SchoolScope, so a uuid
        // from another school resolves to nothing and simply contributes no row.
        if ($this->onlyUuids !== null) {
            $query->whereIn('uuid', $this->onlyUuids);
        } else {
            $query = StudentIndexFilters::apply($query, $this->request);
        }

        return $query
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
