<?php

namespace App\Http\Requests;

use App\Models\CommentBand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Save a school's WHOLE comment-band set for one exam type in a single request.
 *
 * Band-at-a-time editing would make the set incoherent between calls: moving one band's edge moves
 * its neighbour's, so a per-row API either rejects every intermediate step or lets a half-applied
 * edit sit in the database. Sending the whole ladder makes each save atomic and lets the two rules
 * below be checked against the state that is actually about to exist.
 *
 * Only `min_score` is accepted. The range end is derived on save (the next band's minimum, or
 * CommentBand::MAX_SCORE at the top), which is why gaps and overlaps are not validated here —
 * they cannot be expressed. What must still be checked is the pair that keeps that guarantee true:
 * the ladder starts at 0, and no two bands start at the same score.
 */
class SaveCommentBandsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route sits inside the `permission:academic_setup.manage` group in routes/api.php.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Absent/null = the school's default set. A uuid targets one exam type's override.
            'exam_type_id' => ['nullable', 'uuid', 'exists:exam_types,uuid'],

            'bands' => ['required', 'array', 'min:1'],
            'bands.*.id' => ['nullable', 'uuid'],
            'bands.*.min_score' => ['required', 'numeric', 'min:0', 'max:'.CommentBand::MAX_SCORE],
            'bands.*.label' => ['required', 'string', 'max:50'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var array<int, array{min_score: mixed}>|null $bands */
            $bands = $this->input('bands');

            if (! is_array($bands) || $bands === []) {
                return; // `required` already reported this.
            }

            $minima = array_map(
                static fn ($band) => (float) ($band['min_score'] ?? -1),
                $bands
            );

            // Full coverage reduces to this one rule. Every score at or above the lowest minimum
            // resolves to some band, so a ladder starting at 0 covers the whole scale — and a
            // ladder starting anywhere else leaves scores below it with no suggestions at all.
            if (! in_array(0.0, $minima, true)) {
                $validator->errors()->add(
                    'bands',
                    'The lowest band must start at 0, otherwise scores below it get no comments.'
                );
            }

            // Two bands starting at the same score make the winner arbitrary. The database holds
            // this too (comment_bands_set_min_unique); checking here turns a 500 into a 422.
            if (count($minima) !== count(array_unique($minima, SORT_NUMERIC))) {
                $validator->errors()->add(
                    'bands',
                    'Two bands cannot start at the same score.'
                );
            }
        });
    }
}
