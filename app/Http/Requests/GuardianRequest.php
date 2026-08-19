<?php

namespace App\Http\Requests;

use App\Enums\GenderTypeEnum;
use App\Enums\GuardianIdTypeEnum;
use App\Enums\GuardianRelationshipEnum;
use App\Enums\GuardianStatusEnum;
use App\Enums\MaritalStatusEnum;
use App\Support\ActiveSchool;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class GuardianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Trim admission numbers before they are matched against the database.
     *
     * NO LOWERING STEP, and that is a finding rather than an omission.
     * `admission_number` is machine-generated in UPPERCASE by
     * `HasAdmissionNumber::buildAdmissionNumber` (prefix `GFA/{year}/` + a
     * zero-padded counter), and the column's collation is `utf8mb4_unicode_ci` —
     * derived from information_schema on 2026-08-18, not assumed — which is
     * case-INSENSITIVE. So `Rule::exists` already matches a lower-cased entry, and
     * adding a `Str::upper` here would be a second, silently-diverging normalisation
     * rule that breaks the moment anyone adopts a prefix with a lowercase letter in
     * it. Trimming is different: whitespace is not collation-folded and a pasted
     * admission number routinely carries it.
     */
    protected function prepareForValidation(): void
    {
        $links = $this->input('student_links');

        if (! is_array($links)) {
            return;
        }

        foreach ($links as $i => $link) {
            if (is_array($link) && isset($link['admission_number']) && is_string($link['admission_number'])) {
                $links[$i]['admission_number'] = trim($link['admission_number']);
            }
        }

        $this->merge(['student_links' => $links]);
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('PATCH') || $this->isMethod('PUT');

        return [
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'gender' => ['nullable', 'string', Rule::in(GenderTypeEnum::values())],
            'phone' => ['required', 'string', 'max:50'],
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
            'status' => [$isUpdate ? 'sometimes' : 'nullable', 'string', Rule::enum(GuardianStatusEnum::class)],
            'can_login' => ['nullable', 'boolean'],
            'email' => [
                Rule::requiredIf(fn () => filter_var($this->input('can_login'), FILTER_VALIDATE_BOOLEAN)),
                'nullable',
                'email',
                'max:255',
                // UPDATE ONLY. On CREATE this rule was fighting the service it
                // guards: GuardianService::createGuardianWithUser is written to
                // REUSE an existing users row ("One human = one User §6.2"), and a
                // hard 422 here meant re-adding a parent who already has an account
                // — the multi-school parent the reuse exists for — had no way
                // forward at all. That is what drove the reported school to add the
                // same mother once per child and manufacture three guardians rows.
                // On UPDATE it stays: pointing one guardian's email at another
                // registered account is a genuine collision, not a reuse.
                ...($isUpdate ? [Rule::unique('users', 'email')->ignore($this->guardian?->user_id)] : []),
            ],
            'relationship' => ['nullable', 'string', Rule::in(GuardianRelationshipEnum::values())],
            'photo' => ['nullable', 'image', 'mimes:jpeg,jpg,png', 'max:2048'],

            // student_links WAS ENTIRELY UNVALIDATED and the controller read it
            // straight off `input()`. An admission number that did not resolve was
            // discarded with no error, no log and a 201 — "the information was not
            // saving", reported as a bug and indistinguishable from success.
            'student_links' => ['nullable', 'array'],
            'student_links.*' => ['array'],
            'student_links.*.admission_number' => [
                'required', 'string', 'max:255',
                // THE `school_id` PREDICATE IS THE ISOLATION-CRITICAL LINE IN THIS
                // FILE. `Rule::exists` runs RAW SQL and applies no Eloquent global
                // scope, so Student's SchoolScope does NOT protect it — without this
                // where(), any school's admission number resolves and the controller
                // would attach a child from another school.
                Rule::exists('students', 'admission_number')
                    ->where('school_id', ActiveSchool::id())
                    ->whereNull('deleted_at'),
            ],
            'student_links.*.relationship' => [
                // `required`, not `nullable`: the modal always sends this key, as
                // `''`, so the controller's old `?? 'other'` fallback could never
                // fire and an empty string was written into the pivot's
                // `relationship` column with no enum check.
                'required', 'string', Rule::in(GuardianRelationshipEnum::values()),
            ],
            'student_links.*.is_primary' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Reject the same admission number twice in one submission.
     *
     * The pivot write downstream is keyed on (guardian_id, student_id) and would
     * silently collapse the pair — last write wins on relationship and is_primary —
     * so a genuine operator mistake (two rows, two different relationships, same
     * child) would be accepted and half-applied.
     *
     * The mutator is deliberately NOT NAMED in this comment. GuardianLoginInvariantTest's
     * cardinality pin greps app/ for the pivot-mutator tokens beside a `'can_login'`
     * literal, and this file carries `'can_login'` as a validation rule — so writing
     * the method name here in prose makes this request class look like a third pivot
     * writer and turns a real guard red on a comment. It caught exactly that during
     * this change, which is the guard working; the fix is to reword, never to widen
     * the guard's regex.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $links = $this->input('student_links');

            if (! \is_array($links)) {
                return;
            }

            $seen = [];

            foreach ($links as $i => $link) {
                $number = \is_array($link) ? ($link['admission_number'] ?? null) : null;

                if (! \is_string($number) || $number === '') {
                    continue;
                }

                // Compared case-insensitively to match the column's own
                // utf8mb4_unicode_ci collation — otherwise `gfa/2026/001` and
                // `GFA/2026/001` pass this check and then resolve to one student.
                $key = mb_strtolower($number);

                if (isset($seen[$key])) {
                    $validator->errors()->add(
                        "student_links.{$i}.admission_number",
                        "Admission number {$number} is listed more than once (rows "
                            .($seen[$key] + 1).' and '.($i + 1).'). Remove the duplicate row.'
                    );

                    continue;
                }

                $seen[$key] = $i;
            }
        });
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422));
    }
}
