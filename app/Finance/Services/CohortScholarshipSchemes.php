<?php

namespace App\Finance\Services;

use App\Enums\ScholarshipKind;
use App\Finance\Contracts\BillableEnrollment;
use App\Finance\Contracts\BillableEnrollmentProvider;
use App\Models\Scholarship;

/**
 * WHICH SCHEME EACH SCHOLARSHIP-HOLDING COHORT MEMBER IS ON — and the run-level refusal if any of
 * those schemes is not configured.
 *
 * ONE EXPRESSION, TWO READERS, AND THAT IS THE WHOLE REASON IT IS A CLASS. It began as two private
 * methods on the bulk invoice run job, which was correct while the job was
 * the only thing that needed to know. It is not: the PREVIEW has to report the same exclusion, and
 * the preview reporting it from a second copy of the predicate is the defect this extraction
 * exists to prevent rather than a style preference. It has already been paid for once on this
 * screen — BulkInvoiceRunController::preview()'s own docblock records that
 * the last time two copies of the already-billed predicate existed they disagreed, one gained the
 * `kind` filter and the other did not, and a bursar was told to void an invoice that the write it
 * warned about then succeeded against. This is the SECOND shared predicate on that surface, shared
 * for the same reason and by the same means: the reader calls the source, it does not restate it.
 *
 * NAMED IN PROSE AND NOT AS `{@see}` TAGS, for the reason the enum and the port both give: a
 * resolvable tag needs a `use` at the top of this file, and a Service importing the Controller and
 * the Job that call it inverts the dependency for a docblock. The readers depend on this; it does
 * not depend on them.
 *
 * IT DECIDES NOTHING NEW. Every rule below was decided and proven where the run was built — see
 * tests/Feature/Finance/ScholarshipKindAndRunExclusionTest.php. What moved is the location.
 *
 * TWO SEPARATE REFUSALS, because they are two different faults with two different fixes and one
 * message covering both would tell an operator to do the wrong thing:
 *
 *   UNCONFIGURED — the scholarship exists in this School and its `kind` is NULL. Somebody has to
 *                  say whether it is a discount scheme or a sponsored one. Named by NAME, which
 *                  is the only handle an operator has on it in the setup screen.
 *
 *   UNRESOLVABLE — `students.scholarship_id` names a row this School cannot read: it is gone, or
 *                  it belongs to another School. THIS IS SCHEMA-REACHABLE, not a paranoid branch:
 *                  the FK is a plain `scholarship_id` REFERENCES `scholarships (id)` and is NOT
 *                  composite with `school_id`, so nothing at the engine stops School A assigning
 *                  School B a scholarship. Named by ID, because there is no name to print — and
 *                  printing another School's scholarship name here would be a cross-School leak
 *                  through an error message.
 *
 * WHY THIS ONE IS NOT A SILENT SKIP, which is what it would have been if it were left out. A
 * scholarship that does not resolve reads, to every filter downstream, exactly like a student
 * holding no scholarship at all — so a sponsored C2C student whose scholarship row belongs to the
 * wrong School would be BILLED THE STANDARD SCHEDULE, silently, by the very rule written to stop
 * that. The unconfigured refusal would not fire, because there is nothing to find a NULL `kind`
 * on. It is the same fall-through the whole scholarship axis exists to close, arriving through a
 * hole in the lookup rather than through a hole in the data.
 *
 * ISOLATION IS THE ARGUMENT, NOT THE AMBIENT CONTEXT. Both reads name the School they were handed.
 * In the job that is the run's declared `schoolId`; in the preview it is the request's own School.
 * Under `SchoolAware` the ambient School is the same one, so `SchoolScope` agrees and the predicate
 * is redundant — but a read that took it from ambient context would be trusting the very thing
 * `SchoolAware` exists to set.
 *
 * IT DOES NOT TOUCH `students.scholarship_id`, AND IT WRITES NOTHING AT ALL. That is what makes it
 * safe for the preview to call: the preview creates no run row and dispatches no job, and calling
 * this on the way to answering it must not change that.
 */
class CohortScholarshipSchemes
{
    public function __construct(
        private readonly BillableEnrollmentProvider $enrollments,
    ) {}

    /**
     * @param  list<BillableEnrollment>  $cohort
     * @return array{byStudent: array<int, ScholarshipKind>, refusal: string|null}
     */
    public function forCohort(array $cohort, int $schoolId): array
    {
        $studentIds = array_values(array_unique(array_map(
            fn (BillableEnrollment $enrollment) => $enrollment->studentId,
            $cohort,
        )));

        if ($studentIds === []) {
            return ['byStudent' => [], 'refusal' => null];
        }

        // THROUGH THE PORT, NOT A QUERY HERE. The cohort read includes SOFT-DELETED students by a
        // deliberate ruling, and `Student` uses `SoftDeletes` — so the obvious `Student::whereIn()`
        // returns nothing for exactly those students and reads them as holding no scholarship. A
        // trashed sponsored student would then be billed the standard schedule. The port matches
        // the cohort's own soft-delete rule; see its docblock.
        $held = $this->enrollments->scholarshipIdsFor($studentIds, $schoolId);

        if ($held === []) {
            return ['byStudent' => [], 'refusal' => null];
        }

        $scholarships = Scholarship::query()
            ->where('school_id', $schoolId)
            ->whereIn('id', array_values(array_unique($held)))
            ->get()
            ->keyBy('id');

        $byStudent = [];
        $unconfigured = [];
        $unresolvable = [];

        foreach ($held as $studentId => $scholarshipId) {
            $scholarship = $scholarships->get($scholarshipId);

            if (! $scholarship instanceof Scholarship) {
                $unresolvable[$scholarshipId] = true;

                continue;
            }

            if ($scholarship->kind === null) {
                // Keyed by NAME so one scholarship held by forty students is named once.
                $unconfigured[$scholarship->name] = true;

                continue;
            }

            $byStudent[(int) $studentId] = $scholarship->kind;
        }

        return [
            'byStudent' => $byStudent,
            'refusal' => $this->refusal(array_keys($unconfigured), array_keys($unresolvable)),
        ];
    }

    /**
     * THE PREDICATE ITSELF, in one place — asked per student, by both readers, of the map above.
     * The job asks it to decide whether to write a `sponsored` row instead of billing; the preview
     * asks it to decide whether to count the student and skip the invoice question.
     *
     * THERE IS DELIBERATELY NO `sponsoredCount($cohort, $byStudent)` HELPER BESIDE IT. The preview
     * is the only caller that wants a total, and it wants it from a loop it is walking anyway to
     * count `already_billed`; a second method would be a primitive with no consumer, shaped by
     * imagination rather than by use.
     *
     * @param  array<int, ScholarshipKind>  $byStudent
     */
    public function isSponsored(BillableEnrollment $enrollment, array $byStudent): bool
    {
        return ($byStudent[$enrollment->studentId] ?? null) === ScholarshipKind::Sponsored;
    }

    /**
     * The sentence a refused run carries, or null when there is nothing to refuse.
     *
     * IT SAYS WHAT WAS NOT DONE, which the fee-schedule refusal beside it also does: an operator
     * reading `failed` needs to know whether anything was billed before they re-run. Nothing was —
     * this is settled before the first row — and saying so is what makes re-running after the fix
     * obviously safe rather than merely safe.
     *
     * THE PREVIEW PRINTS THIS SAME STRING, unchanged, as it prints the mapper's and the job's own
     * refusals. Past tense included: the preview's banner already frames it as the sentence the run
     * WOULD fail with, and a second wording for the screen is a second thing that can disagree with
     * the job about why a run cannot happen.
     *
     * @param  list<string>  $unconfigured
     * @param  list<int>  $unresolvable
     */
    private function refusal(array $unconfigured, array $unresolvable): ?string
    {
        if ($unconfigured === [] && $unresolvable === []) {
            return null;
        }

        $parts = [];

        if ($unconfigured !== []) {
            sort($unconfigured);

            $parts[] = 'these scholarships do not say which scheme they are: '.implode(', ', $unconfigured)
                .'. Set each one to a discount scholarship (billed here, at the standard fee schedule) '
                .'or a sponsored scholarship (billed by hand, and excluded from this run)';
        }

        if ($unresolvable !== []) {
            sort($unresolvable);

            $parts[] = 'these scholarship records could not be read in this school, so the students '
                .'holding them cannot be classified: '.implode(', ', array_map(
                    fn (int $id) => '#'.$id,
                    $unresolvable,
                )).'. Each one has been deleted or belongs to another school';
        }

        return 'This run was stopped before it billed anyone, and '.implode('; also, ', $parts).'. '
            .'Nothing was invoiced and no student was charged. The run refuses rather than billing '
            .'the standard fee schedule, because a sponsored student billed by mistake looks exactly '
            .'like a successful run until their parent opens a full-price invoice.';
    }
}
