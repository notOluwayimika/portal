<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class TeacherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->uuid,
            'first_name'    => $this->first_name,
            'last_name'     => $this->last_name,
            'full_name'     => $this->last_name . ' ' . $this->first_name,
            'staff_number'  => $this->staff_number,
            'gender'        => $this->gender,
            'date_of_birth' => $this->date_of_birth,
            'phone'         => $this->phone,
            'address'       => $this->address,
            'qualification' => $this->qualification,
            'hire_date'     => $this->hire_date,
            'status'        => $this->status,
            'email'         => $this->whenLoaded('user', fn() => $this->user?->email),
            'has_user'      => (bool) $this->user_id,
            'schools'       => $this->when(
                $this->relationLoaded('school') && ($this->user === null || $this->user->relationLoaded('schools')),
                fn() => collect([[
                    'uuid'    => $this->school?->uuid,
                    'name'    => $this->school?->name,
                    'is_home' => true,
                ]])->concat(
                    ($this->user?->schools ?? collect())
                        ->reject(fn($school) => $school->id === $this->school_id)
                        ->map(fn($school) => [
                            'uuid'    => $school->uuid,
                            'name'    => $school->name,
                            'is_home' => false,
                        ])
                )->values()
            ),
            'photo'         => $this->photo
                ? Storage::disk('s3')->temporaryUrl($this->photo, now()->addMinutes(15))
                : null,
            'user'          => $this->whenLoaded('user', fn() => new UserResource($this->user)),
        ];
    }
}
