<?php

namespace App\Services;

use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The filter set behind the students index — declared once, applied by every reader of it.
 *
 * ── ONE DEFINITION, TWO CALLERS, FOR THE REASON CohortSiblings GIVES ──────────────────────────────
 * `StudentService::paginate` decides WHICH pupils an operator is looking at; `StudentsExport`
 * decides which pupils they take away in a file. Those are the same question asked twice, and until
 * this class existed they were answered by two hand-written query blocks that had already drifted:
 * the index filtered on search + class level + arm, the export filtered on search ALONE. So an
 * operator who narrowed to "Year 9 B" and pressed Export silently downloaded the whole school.
 *
 * That is the defect this class closes, and closing it by extraction rather than by copying the two
 * missing `when()` blocks into the exporter is the point — a second correct copy is a third drift
 * waiting to happen the next time a filter is added to the screen.
 *
 * ── THE FILTERS COMPOSE TO ONE CLASS, AND THAT IS INTENDED ────────────────────────────────────────
 * A pupil has one active curriculum bound to one class_level_arm, so `class_level` AND `arm` applied
 * together narrow to a single class-level-arm rather than unioning. Both are matched by UUID, never
 * by label: two arms in one level can share a label and differ only by stream.
 *
 * ── SCHOOL ISOLATION IS NOT RESTATED HERE, DELIBERATELY ───────────────────────────────────────────
 * `Student` carries the SchoolScope global scope, which both callers inherit on the request path.
 * Restating `school_id` here would read as the guard and is not: it would be unreachable on every
 * path this class is used from, and a test could not distinguish it from its absence. The isolation
 * that matters for the id-driven export lives where the ids are resolved, not here.
 */
class StudentIndexFilters
{
    /**
     * @param  Builder<Student>  $query
     * @return Builder<Student>
     */
    public static function apply(Builder $query, Request $request): Builder
    {
        return $query
            ->when($request->filled('search'), function (Builder $q) use ($request) {
                $term = '%'.$request->string('search').'%';

                // GROUPED. Without the closure the trailing orWhere clauses escape any filter
                // applied alongside them, so "Year 9 B" + a search term would return every pupil in
                // the school whose surname matched. The index had this grouping; the export did not
                // have the filters at all.
                $q->where(function (Builder $inner) use ($term) {
                    $inner->where('first_name', 'LIKE', $term)
                        ->orWhere('last_name', 'LIKE', $term)
                        ->orWhere('admission_number', 'LIKE', $term);
                });
            })
            // Filter by the pupil's ACTIVE enrolment, not by any enrolment they have ever held —
            // a pupil who sat in 9B last term and 9S now is in 9S, and a filter that matched their
            // history would put them under both.
            ->when($request->filled('class_level'), fn (Builder $q) => $q->whereHas(
                'currentCurriculum.curriculum.classLevelArm.classLevel',
                fn (Builder $cl) => $cl->where('uuid', $request->string('class_level')),
            ))
            ->when($request->filled('arm'), fn (Builder $q) => $q->whereHas(
                'currentCurriculum.curriculum.classLevelArm.arm',
                fn (Builder $a) => $a->where('uuid', $request->string('arm')),
            ));
    }
}
