<?php

namespace App\Http\Requests;

use App\Enums\GenderTypeEnum;
use App\Enums\GuardianIdTypeEnum;
use App\Enums\GuardianStatusEnum;
use App\Enums\MaritalStatusEnum;
use App\Models\Guardian;
use App\Models\User;
use App\Support\PhoneNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validates a guardian-detail update.
 *
 * Sensitive fields (email, phone) are gated by the `guardian.update_credentials`
 * permission when the underlying user has an active login.
 */
class GuardianUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('guardian.update') ?? false;
    }

    /**
     * REFUSE, do not strip.
     *
     * This method used to `remove()` `email` and `phone` from the payload when the
     * actor lacked `guardian.update_credentials`, and the request then answered
     * **200 with the field unchanged**. That is a second, independent "it was not
     * saving": the operator is shown success and believes the address changed. It is
     * the same failure mode as the unvalidated `student_links` on the create path,
     * from the opposite direction — one dropped the input silently, this dropped it
     * silently too, and both reported the drop as done.
     *
     * A HARD 403 IS THE PROJECT'S OWN STANDING RULING, not a new preference: the
     * ruling of 2026-07-21, recorded in full at
     * `tests/Feature/GuardianManagementTest.php:242-254`, says "hard 403, not
     * 200-with-silent-ignore", and names the future UX (200 plus an explicit
     * "email ignored" signal) as needing a deliberate response contract that does
     * not exist yet. Until then, 403. The message names the fields so the operator
     * knows what to remove rather than guessing.
     *
     * THE RULING'S TEST DOES NOT ACTUALLY EXERCISE THIS. It acts as `registrar`,
     * and `registrar` holds no route access at all (`RbacSeeder.php:299-306`,
     * "No route access: registrar appeared in no pre-swap role: group"), so its 403
     * comes from the route's own `permission:academic_setup.manage` middleware
     * before this class is ever constructed — the assertion passes identically with
     * or without any credential logic here. That test is left untouched and the gap
     * is covered by a new arm acting as a role that DOES reach the route.
     *
     * ORDERING: base authorization is checked first and this returns without
     * pre-empting it, so an actor lacking `guardian.update` entirely still gets
     * authorize()'s refusal rather than a credential-specific message about a route
     * they could not use anyway.
     */
    protected function prepareForValidation(): void
    {
        $guardian = $this->route('guardian');
        if (! $guardian) {
            return;
        }

        $actor = $this->user();

        if (! ($actor?->can('guardian.update') ?? false)) {
            return;
        }

        $hasCredPerm = $actor->can('guardian.update_credentials');
        $user = $guardian->user;
        $loginActive = $user && ! $user->isDisabled();

        if ($hasCredPerm || ! $loginActive) {
            return;
        }

        // AN ATTEMPTED CHANGE, NOT MERE PRESENCE. This refused on `has($field)` in the
        // first cut of this change and that was a regression, not a stricter rule:
        // `edit-guardian-modal.tsx` prefills the form from the record (`:60-79`) and
        // posts every non-empty key (`:138-141`), and `phone` is required and therefore
        // ALWAYS present. So an actor holding `guardian.update` without
        // `guardian.update_credentials` got a hard 403 on every save — including an
        // occupation-only edit — with a message telling them to remove a field the
        // modal gives them no way to omit. Item 20's intent was to replace a FALSE
        // SUCCESS with an honest refusal, not to replace it with an unconditional one.
        //
        // Only reachable today through a runtime matrix edit (the seeded map bundles
        // GUARDIAN_UPDATE with GUARDIAN_UPDATE_CREDENTIALS — RbacSeeder.php:153-156 —
        // and `registrar`, which holds one without the other, reaches no route at all:
        // RbacSeeder.php:299-306). A per-school matrix edit is a supported operation,
        // so this is a lockout waiting for a permission change rather than a
        // hypothetical.
        $blocked = array_values(array_filter(
            ['email', 'phone'],
            fn (string $field) => $this->attemptsToChange($field, $user, $guardian),
        ));

        if ($blocked === []) {
            return;
        }

        abort(403, sprintf(
            'Changing %s for a guardian with an active login requires the "guardian.update_credentials" permission. '
                .'Nothing was saved — remove %s from your edit, or ask an administrator to make this change.',
            implode(' and ', $blocked),
            count($blocked) === 1 ? 'it' : 'them',
        ));
    }

    /**
     * Does the payload actually try to MOVE this credential field off its stored value?
     *
     * Absent ⇒ no. Equal to what is stored ⇒ no, and that is the whole point: a client
     * that round-trips the record is not asking for a change.
     *
     * The two fields compare differently and that asymmetry is deliberate:
     *
     *  - `email` lives on `users`, is the sole authentication key
     *    (FortifyServiceProvider looks the account up by it) and is stored lowered by
     *    every writer here — so it is compared case-insensitively and trimmed, with
     *    null and '' treated as the same absence.
     *  - `phone` lives on `guardians` and is stored E.164-normalised by
     *    GuardianService::createGuardianWithUser but NOT by
     *    GuardianService::update — see the ticket on that — so `08031110001` and
     *    `+2348031110001` can both legitimately name the same stored number.
     *    Compared through PhoneNormalizer, which is the only comparison that does
     *    not turn a formatting difference into a 403.
     */
    private function attemptsToChange(string $field, ?User $user, Guardian $guardian): bool
    {
        if (! $this->request->has($field)) {
            return false;
        }

        $submitted = $this->input($field);

        if ($field === 'email') {
            $stored = $user?->email;

            return Str::lower(trim((string) $submitted)) !== Str::lower(trim((string) $stored));
        }

        $stored = $guardian->phone;

        if (PhoneNormalizer::equals((string) $submitted, (string) $stored)) {
            return false;
        }

        return trim((string) $submitted) !== trim((string) $stored);
    }

    public function rules(): array
    {
        $guardian = $this->route('guardian');
        $userId = $guardian?->user_id;

        return [
            'first_name' => ['sometimes', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'gender' => ['nullable', 'string', Rule::in(GenderTypeEnum::values())],
            'phone' => ['sometimes', 'string', 'max:50'],
            'whatsapp_number' => ['nullable', 'string', 'max:50'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:50'],
            'occupation' => ['nullable', 'string', 'max:255'],
            'employer_name' => ['nullable', 'string', 'max:255'],
            'marital_status' => ['nullable', 'string', Rule::in(MaritalStatusEnum::values())],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'id_type' => ['nullable', 'string', Rule::in(GuardianIdTypeEnum::values())],
            'id_number' => ['nullable', 'string', 'max:255'],
            'id_expiry_date' => ['nullable', 'date'],
            'status' => ['sometimes', 'string', Rule::enum(GuardianStatusEnum::class)],
            'email' => [
                'sometimes',
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
        ];
    }
}
