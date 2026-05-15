<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuardianResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->uuid,
            'first_name'        => $this->first_name,
            'middle_name'       => $this->middle_name,
            'last_name'         => $this->last_name,
            'full_name'         => $this->full_name,
            'gender'            => $this->gender,
            'phone'             => $this->phone,
            'whatsapp_number'   => $this->whatsapp_number,
            'city'              => $this->city,
            'state'             => $this->state,
            'country'           => $this->country,
            'postal_code'       => $this->postal_code,
            'occupation'        => $this->occupation,
            'employer_name'     => $this->employer_name,
            'marital_status'    => $this->marital_status,
            'emergency_contact' => $this->emergency_contact,
            'id_type'           => $this->id_type,
            'id_number'         => $this->id_number,
            'id_expiry_date'    => $this->id_expiry_date,
            'status'            => $this->status,
            'students_count'    => $this->whenCounted('students'),
            'deleted_at'        => $this->deleted_at?->toIso8601String(),
            'email'             => $this->whenLoaded('user', fn() => $this->user?->email),
            'has_login'         => $this->whenLoaded('user', fn() => $this->user !== null && $this->user->disabled_at === null),
            'user_disabled_at'  => $this->whenLoaded('user', fn() => $this->user?->disabled_at?->toIso8601String()),
            'email_verified_at' => $this->whenLoaded('user', fn() => $this->user?->email_verified_at?->toIso8601String()),
            'never_activated'   => $this->whenLoaded('user', fn() => $this->user !== null && $this->user->email_verified_at === null),
            'photo'             => $this->photoFile?->url,
            'pivot'             => $this->whenPivotLoaded('guardian_student', fn() => [
                'relationship' => $this->pivot->relationship,
                'is_primary'   => (bool) $this->pivot->is_primary,
                'can_login'    => (bool) $this->pivot->can_login,
            ]),
            'students'          => $this->whenLoaded('students', fn() => $this->students->map(fn($s) => [
                'id'               => $s->uuid,
                'full_name'        => $s->full_name,
                'first_name'       => $s->first_name,
                'last_name'        => $s->last_name,
                'admission_number' => $s->admission_number,
                'photo'            => $s->photoFile?->url,
                'status'           => $s->currentCurriculum?->status,
                'class_details'    => [
                    'level'      => $s->currentCurriculum?->curriculum?->classLevelArm?->classLevel?->name,
                    'arm'        => $s->currentCurriculum?->curriculum?->classLevelArm?->arm?->label,
                    'full_class' => $s->student_class ?? 'N/A',
                ],
                'pivot'            => [
                    'relationship' => $s->pivot->relationship,
                    'is_primary'   => (bool) $s->pivot->is_primary,
                    'can_login'    => (bool) $s->pivot->can_login,
                ],
            ])),
        ];
    }
}
