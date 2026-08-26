<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The ids behind "Export selected (N)".
 *
 * Selection on the students index is PAGE-SCOPED by construction: there is no "select all matching"
 * control, so the ids here are always pupils the operator actually saw. The cap therefore bounds a
 * human's ticking, not a client's imagination — it exists so a hand-assembled or replayed request
 * cannot ask for an unbounded `whereIn`, not because any real page could reach it.
 *
 * Unknown or foreign uuids are NOT an error. Student carries SchoolScope, so a uuid from another
 * school contributes no row, and a pupil deleted between page load and click contributes no row —
 * both are "that pupil is not in your export", which is the truth, and neither is worth failing an
 * export the operator is watching. This differs from the bulk-reassign path on purpose: there, a
 * stale id must fail loudly because a WRITE would otherwise land on the wrong record.
 */
class ExportSelectedStudentsRequest extends FormRequest
{
    /**
     * The page size cap on the students index is 100; 500 leaves room for a future larger page
     * without inviting an unbounded query.
     */
    private const MAX_IDS = 500;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_IDS],
            'ids.*' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => 'Select at least one pupil to export.',
            'ids.min' => 'Select at least one pupil to export.',
            'ids.max' => 'You can export up to '.self::MAX_IDS.' selected pupils at once.',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function uuids(): array
    {
        /** @var array<int, string> $ids */
        $ids = $this->validated()['ids'];

        // Deduplicated so a repeated id cannot produce a duplicate row, which would make the file
        // disagree with the count the button showed.
        return array_values(array_unique($ids));
    }
}
