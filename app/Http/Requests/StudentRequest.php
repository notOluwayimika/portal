<?php

namespace App\Http\Requests;

use App\Enums\GuardianIdTypeEnum;
use App\Enums\GuardianRelationshipEnum;
use App\Enums\MaritalStatusEnum;
use App\Models\User;
use App\Rules\ExactlyOnePrimaryGuardian;
use App\Support\ActiveSchool;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StudentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * When the form is submitted as multipart (because of the photo file), nested arrays
     * are encoded as a JSON string under `guardians`. Decode it back so the rules below
     * can walk the array.
     */
    protected function prepareForValidation(): void
    {
        $guardians = $this->input('guardians');

        if (is_string($guardians)) {
            $decoded = json_decode($guardians, true);
            if (is_array($decoded)) {
                $this->merge(['guardians' => $decoded]);
            }
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isUpdate = $this->isMethod('PATCH') || $this->isMethod('PUT');

        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'gender' => ['required', 'string', 'in:male,female,other'],
            'date_of_birth' => ['nullable', 'date'],
            'admission_number' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('students', 'admission_number')
                    ->ignore($this->student?->id, 'id'),
            ],
            'photo' => ['nullable', 'image', 'mimes:jpeg,jpg,png', 'max:2048'],
            // School-SCOPED existence (slice (i)). An unscoped `exists:curricula,id`
            // lets another School's curriculum through, which the new composite FK
            // on student_curricula (curriculum_id, school_id) then rejects as a raw
            // QueryException instead of a clean validation error.
            'curriculum_id' => [
                $isUpdate ? 'sometimes' : 'required',
                'integer',
                Rule::exists('curricula', 'id')->where('school_id', ActiveSchool::id()),
            ],
            // NOT scoped here on purpose: `promoted_to_id`'s FK actually targets
            // `student_curricula` (self-referencing), so this rule names the wrong
            // TABLE, not merely the wrong scope. Fixing the table is Option-B's
            // promotion-chain slice — see the defect list. Scoping it to `curricula`
            // now would only entrench the wrong target.
            'promoted_to_id' => ['nullable', 'integer', 'exists:curricula,id'],

            'admission_date' => ['nullable', 'date'],
            'address' => ['nullable', 'string'],
            'nationality' => ['nullable', 'string', 'max:255'],
            'other_nationality' => ['nullable', 'string', 'max:255'],
            'state_of_origin' => ['nullable', 'string', 'max:255'],
            'religion' => ['nullable', 'string', 'max:255'],
            'previous_school' => ['nullable', 'string', 'max:255'],
            'sport_house_id' => ['nullable', 'integer', 'exists:sport_houses,id'],
            'scholarship_id' => ['nullable', 'integer', 'exists:scholarships,id'],

            // Guardians array — only required on creation. On update, guardians are managed
            // via the dedicated attach/detach endpoints; the array is optional here.
            'guardians' => [$isUpdate ? 'sometimes' : 'required', 'array', 'min:1', new ExactlyOnePrimaryGuardian],
            'guardians.*.mode' => ['required_with:guardians', 'in:new,existing'],
            'guardians.*.relationship' => ['required_with:guardians', 'string', Rule::in(GuardianRelationshipEnum::values())],
            'guardians.*.is_primary' => ['required_with:guardians', 'boolean'],
            'guardians.*.can_login' => ['required_with:guardians', 'boolean'],

            // Case B (existing) — at least one of guardian_id (uuid) or identifier (email/phone).
            // The "at least one" rule is enforced in withValidator() below.
            'guardians.*.guardian_id' => ['nullable', 'uuid'],
            'guardians.*.identifier' => ['nullable', 'string', 'max:255'],

            // Case A (new) — full nested guardian payload.
            'guardians.*.first_name' => ['required_if:guardians.*.mode,new', 'string', 'max:255'],
            'guardians.*.last_name' => ['required_if:guardians.*.mode,new', 'string', 'max:255'],
            'guardians.*.middle_name' => ['nullable', 'string', 'max:255'],
            'guardians.*.phone' => ['required_if:guardians.*.mode,new', 'string', 'max:50'],
            'guardians.*.whatsapp_number' => ['nullable', 'string', 'max:50'],
            'guardians.*.gender' => ['nullable', 'string', 'in:male,female,other'],
            'guardians.*.email' => ['nullable', 'email', 'max:255'],
            'guardians.*.city' => ['nullable', 'string', 'max:255'],
            'guardians.*.state' => ['nullable', 'string', 'max:255'],
            'guardians.*.country' => ['nullable', 'string', 'max:255'],
            'guardians.*.postal_code' => ['nullable', 'string', 'max:50'],
            'guardians.*.occupation' => ['nullable', 'string', 'max:255'],
            'guardians.*.employer_name' => ['nullable', 'string', 'max:255'],
            'guardians.*.marital_status' => ['nullable', 'string', Rule::in(MaritalStatusEnum::values())],
            'guardians.*.emergency_contact' => ['nullable', 'string', 'max:255'],
            'guardians.*.id_type' => ['nullable', 'string', Rule::in(GuardianIdTypeEnum::values())],
            'guardians.*.id_number' => ['nullable', 'string', 'max:255'],
            'guardians.*.id_expiry_date' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        $fields = [
            'mode' => 'mode',
            'relationship' => 'relationship',
            'is_primary' => 'primary flag',
            'can_login' => 'login access',
            'guardian_id' => 'guardian',
            'identifier' => 'identifier',
            'first_name' => 'first name',
            'last_name' => 'last name',
            'middle_name' => 'middle name',
            'phone' => 'phone',
            'whatsapp_number' => 'WhatsApp number',
            'gender' => 'gender',
            'email' => 'email',
            'city' => 'city',
            'state' => 'state',
            'country' => 'country',
            'postal_code' => 'postal code',
            'occupation' => 'occupation',
            'employer_name' => 'employer name',
            'marital_status' => 'marital status',
            'emergency_contact' => 'emergency contact',
            'id_type' => 'ID type',
            'id_number' => 'ID number',
            'id_expiry_date' => 'ID expiry date',
        ];

        $messages = [];
        foreach ($fields as $key => $label) {
            $messages["guardians.*.{$key}.required"] = "The {$label} field is required.";
            $messages["guardians.*.{$key}.required_with"] = "The {$label} field is required when guardians is present.";
            $messages["guardians.*.{$key}.required_if"] = "The {$label} field is required.";
            $messages["guardians.*.{$key}.in"] = "The selected {$label} is invalid.";
            $messages["guardians.*.{$key}.boolean"] = "The {$label} field must be true or false.";
            $messages["guardians.*.{$key}.string"] = "The {$label} field must be a string.";
            $messages["guardians.*.{$key}.email"] = "The {$label} must be a valid email address.";
            $messages["guardians.*.{$key}.max"] = "The {$label} field must not exceed :max characters.";
            $messages["guardians.*.{$key}.uuid"] = "The {$label} field must be a valid UUID.";
            $messages["guardians.*.{$key}.date"] = "The {$label} field must be a valid date.";
        }

        return $messages;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $guardians = (array) $this->input('guardians', []);

            foreach ($guardians as $i => $entry) {
                $mode = $entry['mode'] ?? null;
                $canLogin = filter_var($entry['can_login'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $email = $entry['email'] ?? null;

                if ($mode === 'new' && $canLogin && empty($email)) {
                    $v->errors()->add(
                        "guardians.{$i}.email",
                        'An email is required when can_login is true for a new guardian.'
                    );
                }

                if ($mode === 'existing' && empty($entry['guardian_id']) && empty($entry['identifier'])) {
                    $v->errors()->add(
                        "guardians.{$i}.identifier",
                        'Provide an existing guardian (look up by email or phone) or switch to creating a new one.'
                    );
                }

                if ($mode === 'new' && ! empty($email)) {
                    $exists = User::query()->where('email', $email)->exists();
                    if ($exists) {
                        $v->errors()->add(
                            "guardians.{$i}.email",
                            "The email '{$email}' is already registered to another user. Switch to an existing guardian instead."
                        );
                    }
                }
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
