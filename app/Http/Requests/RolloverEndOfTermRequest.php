<?php

namespace App\Http\Requests;

use App\Models\Term;
use App\Support\ActiveSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The closing term, resolved school-scoped.
 *
 * ── RESOLUTION IS THE ISOLATION GUARD ────────────────────────────────────────────────────────────
 * The CLI resolves a term with `withoutGlobalScope(SchoolScope::class)` because it has no ambient
 * school and takes the school FROM the term. A request does have one, and must not: a term uuid
 * from another school has to fail to resolve rather than silently roll over a school the operator
 * cannot see. So this pins `school_id` to the active school explicitly instead of dropping the
 * scope — the opposite decision from the command, for the opposite reason.
 */
class RolloverEndOfTermRequest extends FormRequest
{
    private ?Term $resolved = null;

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
            'term_id' => ['required', 'uuid'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $term = Term::query()
                ->where('uuid', (string) $this->input('term_id'))
                // ->id, NOT the model — see RolloverEndOfYearRequest.
                ->where('school_id', (int) ActiveSchool::getOrFail()->id)
                ->first();

            if ($term === null) {
                $validator->errors()->add('term_id', 'That term was not found in this school.');

                return;
            }

            $this->resolved = $term;
        });
    }

    public function term(): Term
    {
        if ($this->resolved === null) {
            throw new \LogicException('term() read before validation succeeded.');
        }

        return $this->resolved;
    }
}
