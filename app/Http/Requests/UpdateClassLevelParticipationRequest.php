<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Set a term slot's CCM flag.
 *
 * `required`, NOT `sometimes`, and that is the whole point of the class. The endpoint this replaced
 * inverted whatever it found and accepted no body at all, so the caller could not state an intended
 * state even if it wanted to. Requiring the field means the request carries a DECISION — "this slot
 * is CCM" or "this slot is not" — which is idempotent: sending it twice lands the same row, and a
 * stale client cannot flip a flag by agreeing with itself.
 *
 * Contrast {@see StoreClassLevelParticipationRequest}, where `is_ccm` is `sometimes`: on create an
 * absent key is legitimately "the default, non-CCM", and `$request->boolean()` writes false. On an
 * UPDATE there is no default to fall back to — an absent key would mean "make it false", which is a
 * change nobody asked for.
 */
class UpdateClassLevelParticipationRequest extends FormRequest
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
            'is_ccm' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'is_ccm.required' => 'Say whether this slot is a CCM slot — this endpoint sets the flag, it does not flip it.',
        ];
    }
}
