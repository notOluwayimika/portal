<?php

namespace App\Http\Requests;

use App\Models\ClassLevelTermParticipation;
use App\Models\Scopes\SchoolScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Add one term slot to a class level's participation.
 *
 * PRESENCE IS PARTICIPATION — there is no `participates` boolean, so adding a row IS the act of
 * saying "this level runs that slot", and removing it is the act of saying it does not. The schema
 * carries no "does not participate" state, deliberately: an absent row and a row saying no would be
 * two spellings of one fact.
 *
 * MIRRORS: `class_level_term_participation_unique (class_level_id, term_order)`. Without this rule
 * the duplicate surfaces as a driver error rather than a field message.
 */
class StoreClassLevelParticipationRequest extends FormRequest
{
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
            // tinyint on the column, and a term order is a small positive integer in every school
            // here. Bounded rather than open so a typo cannot create slot 4000.
            'term_order' => ['required', 'integer', 'min:1', 'max:20'],
            // Accepted but not surfaced in v1 — participation is created non-CCM and the toggle is
            // out of the UI until a school needs CCM slots. Kept in the contract so the API does not
            // change shape when it arrives.
            'is_ccm' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $level = $this->route('classLevel');

            if ($level === null || $this->input('term_order') === null) {
                return;
            }

            $exists = ClassLevelTermParticipation::withoutGlobalScope(SchoolScope::class)
                ->where('class_level_id', $level->id)
                ->where('term_order', $this->integer('term_order'))
                ->exists();

            if ($exists) {
                // MIRRORS class_level_term_participation_unique.
                $validator->errors()->add(
                    'term_order',
                    'This class level already runs that term slot.'
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['term_order' => 'term slot'];
    }
}
