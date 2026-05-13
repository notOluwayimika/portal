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
            'email'             => $this->whenLoaded('user', fn() => $this->user?->email),
            'photo'             => $this->photoFile?->url,
            'pivot'             => $this->whenPivotLoaded('guardian_student', fn() => [
                'relationship' => $this->pivot->relationship,
                'is_primary'   => (bool) $this->pivot->is_primary,
                'can_login'    => (bool) $this->pivot->can_login,
            ]),
        ];
    }
}
