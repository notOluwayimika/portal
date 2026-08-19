<?php

namespace App\Finance\Http\Requests;

use App\Support\ActiveSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The pricing coordinates a bulk invoice run is asked for (U6 commit 4) — a term and a class level,
 * and nothing else.
 *
 * ONE REQUEST FOR BOTH THE PREVIEW AND THE START, deliberately. They take the same two ids and must
 * accept exactly the same set of them: a preview that validates more loosely than the start would
 * answer a question about coordinates the start then refuses, and a preview that validates more
 * tightly would hide a run the operator can legitimately ask for. Two classes with identical rules
 * is two places for that to drift.
 *
 * SCOPED TO THE ACTIVE SCHOOL, and this is the same hole {@see FeeScheduleRequest} closed for the
 * same pair of columns. `finance_bulk_invoice_runs` carries THREE SINGLE-column foreign keys —
 * `term_id` → `terms.id`, `class_level_id` → `class_levels.id`, `school_id` → `schools.id` (read
 * from the migration's `foreignId()->constrained()` calls, none of which is composite) — so another
 * School's term id is a perfectly valid reference at the engine. Without the `where` below, School A
 * could insert a run in its own School keyed to School B's term; the job would resolve no schedule
 * at those coordinates and fail the run, so nothing would be BILLED wrongly, but the row would sit
 * in the list stating a term that is not this School's and the operator would go looking for a
 * missing price list that never belonged to them.
 *
 * Written as an explicit `where` rather than through the scoped model because `Rule::exists` queries
 * the TABLE, and no global scope applies to it.
 */
class BulkInvoiceRunCoordinatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route's `permission:finance.invoice.generate` is the authority. Repeating it here
        // would be a second copy of the gate, and the one that gets forgotten when it moves.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'term_id' => ['required', 'integer', Rule::exists('terms', 'id')->where('school_id', ActiveSchool::id())],
            'class_level_id' => ['required', 'integer', Rule::exists('class_levels', 'id')->where('school_id', ActiveSchool::id())],
        ];
    }

    public function termId(): int
    {
        return (int) $this->validated('term_id');
    }

    public function classLevelId(): int
    {
        return (int) $this->validated('class_level_id');
    }
}
