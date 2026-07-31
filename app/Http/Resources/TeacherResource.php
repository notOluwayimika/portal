<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeacherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->last_name.' '.$this->first_name,
            'staff_number' => $this->staff_number,
            'gender' => $this->gender,
            'date_of_birth' => $this->date_of_birth,
            'phone' => $this->phone,
            'address' => $this->address,
            'qualification' => $this->qualification,
            'hire_date' => $this->hire_date,
            'status' => $this->status,
            'email' => $this->whenLoaded('user', fn () => $this->user?->email),
            'has_user' => (bool) $this->user_id,
            'schools' => $this->when(
                $this->relationLoaded('school') && ($this->user === null || $this->user->relationLoaded('schools')),
                fn () => collect([[
                    'uuid' => $this->school?->uuid,
                    'name' => $this->school?->name,
                    'is_home' => true,
                ]])->concat(
                    ($this->user?->schools ?? collect())
                        ->reject(fn ($school) => $school->id === $this->school_id)
                        ->map(fn ($school) => [
                            'uuid' => $school->uuid,
                            'name' => $school->name,
                            'is_home' => false,
                        ])
                )->values()
            ),
            // The stored `url` IS the final URL — the same shape StudentResource
            // and GuardianResource emit, and the only shape any writer produces
            // (FileUploadService::storeFile sets url to Storage::url($path)).
            //
            // This used to hand that value to temporaryUrl(), which expects an
            // object KEY and signs whatever it is given. The full
            // "https://bucket.s3.../teachers/photos/x.jpg" therefore became the
            // key, and S3 answered 404 NoSuchKey with the whole URL echoed back
            // inside <Key>. Student and Guardian never broke because they never
            // re-derived anything.
            //
            // Presigning was wrong here regardless of the key bug: storeFile()
            // uploads with public visibility, so these objects need no signature,
            // and a 15-minute expiry only rots any cached page holding one.
            'photo' => $this->photo,
            'user' => $this->whenLoaded('user', fn () => new UserResource($this->user)),
        ];
    }
}
