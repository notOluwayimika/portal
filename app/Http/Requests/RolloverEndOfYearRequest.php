<?php

namespace App\Http\Requests;

use App\Models\AcademicSession;
use App\Support\ActiveSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The closing session and the one pupils move into, both resolved school-scoped.
 *
 * ── THE SAME THREE REFUSALS THE CLI MAKES, AS FIELD ERRORS ───────────────────────────────────────
 * RunEndOfYear refuses a missing session, a cross-school pair, and a target equal to the source.
 * Each is restated here against the field that caused it, because an operator picking from two
 * dropdowns needs to know WHICH one is wrong — the command can afford one message to a terminal.
 *
 * ── RESOLUTION IS THE ISOLATION GUARD, AND IT IS PINNED, NOT UNSCOPED ────────────────────────────
 * The CLI drops SchoolScope because it has no ambient school and derives one from the source
 * session. A request has an active school, so a uuid from elsewhere must simply not resolve. That
 * also makes the cross-school check below unreachable through this path — kept anyway, because it
 * is the rule the CLI states and a future caller that resolves differently would need it. Its
 * unreachability is stated rather than left for someone to discover by deleting it and seeing
 * nothing go red.
 */
class RolloverEndOfYearRequest extends FormRequest
{
    private ?AcademicSession $source = null;

    private ?AcademicSession $target = null;

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
            'source_session_id' => ['required', 'uuid'],
            'target_session_id' => ['required', 'uuid'],
            // ── WHAT THE OPERATOR ACCEPTED, ECHOED BACK ─────────────────────────────────────────
            // Destination identities from the preview, sent back VERBATIM. Optional here because
            // this request serves the preview endpoint too, where there is nothing to acknowledge
            // yet — commitEndOfYear treats an absent value as "acknowledged nothing", which is the
            // safe reading: it passes when there is nothing unconfigured and refuses the moment
            // there is.
            //
            // Deliberately NOT validated against the current plan here. A rule that checked these
            // against a freshly computed set would be doing the comparison in the wrong place —
            // before the plan the commit actually dispatches has been built — and would leave the
            // real check looking already done.
            'acknowledged_unconfigured' => ['sometimes', 'array'],
            'acknowledged_unconfigured.*' => ['string', 'max:64'],
        ];
    }

    /**
     * The destinations the operator accepted as unconfigured, as an opaque set.
     *
     * ABSENT MEANS THE EMPTY SET, NOT "SKIP THE CHECK". An older client that never sends the field
     * acknowledges nothing, so it proceeds while nothing is unconfigured and is refused the moment
     * something is — which is exactly the direction a missing acknowledgment should fail in.
     *
     * @return list<string>
     */
    public function acknowledgedUnconfigured(): array
    {
        return array_values(array_unique(array_map(
            static fn ($key) => (string) $key,
            $this->input('acknowledged_unconfigured', []),
        )));
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // ->id, NOT the model: getOrFail() returns a School and `where('school_id', $model)` matches
            // nothing while looking exactly right. Cost a bisect; the id is what a column compares to.
            $schoolId = (int) ActiveSchool::getOrFail()->id;

            $source = $this->resolveSession((string) $this->input('source_session_id'), $schoolId);
            $target = $this->resolveSession((string) $this->input('target_session_id'), $schoolId);

            if ($source === null) {
                $validator->errors()->add('source_session_id', 'That session was not found in this school.');
            }

            if ($target === null) {
                $validator->errors()->add('target_session_id', 'That session was not found in this school.');
            }

            if ($source === null || $target === null) {
                return;
            }

            // Unreachable through this path — both were resolved against one school — and kept
            // because it is the rule, and because a caller resolving differently would need it.
            if ((int) $source->school_id !== (int) $target->school_id) {
                $validator->errors()->add('target_session_id', 'The two sessions belong to different schools.');

                return;
            }

            if ((int) $source->id === (int) $target->id) {
                $validator->errors()->add(
                    'target_session_id',
                    'The target session is the session being closed — pupils cannot roll into the year they are leaving.'
                );

                return;
            }

            $this->source = $source;
            $this->target = $target;
        });
    }

    public function sourceSession(): AcademicSession
    {
        if ($this->source === null) {
            throw new \LogicException('sourceSession() read before validation succeeded.');
        }

        return $this->source;
    }

    public function targetSession(): AcademicSession
    {
        if ($this->target === null) {
            throw new \LogicException('targetSession() read before validation succeeded.');
        }

        return $this->target;
    }

    /**
     * NOT named `session()`. FormRequest extends Illuminate\Http\Request, which already has a
     * `session()` method that Laravel calls internally — overriding it with a two-argument private
     * method kills the process outright rather than raising anything catchable. The equivalent
     * helper on RunEndOfYear (a Command) is safely called `session()`; the name is only unavailable
     * here. Cost an afternoon; renamed rather than commented.
     */
    private function resolveSession(string $uuid, int|string $schoolId): ?AcademicSession
    {
        return AcademicSession::query()
            ->where('uuid', $uuid)
            ->where('school_id', $schoolId)
            ->first();
    }
}
