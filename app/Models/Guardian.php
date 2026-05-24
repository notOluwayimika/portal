<?php

namespace App\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Guardian extends Model
{
    use AddUuid, BelongsToSchool, SoftDeletes, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'first_name',
                'middle_name',
                'last_name',
                'gender',
                'phone',
                'whatsapp_number',
                'city',
                'state',
                'country',
                'postal_code',
                'occupation',
                'employer_name',
                'marital_status',
                'emergency_contact',
                'id_type',
                'id_number',
                'id_expiry_date',
                'status',
                'photo_id',
            ])
            ->logOnlyDirty()
            ->useLogName('guardian');
    }

    protected $fillable = [
        'school_id',
        'user_id',
        'first_name',
        'middle_name',
        'last_name',
        'gender',
        'phone',
        'whatsapp_number',
        'city',
        'state',
        'country',
        'postal_code',
        'occupation',
        'employer_name',
        'marital_status',
        'emergency_contact',
        'photo_id',
        'id_type',
        'id_number',
        'id_expiry_date',
        'status',
    ];

    protected $casts = [
        'id_expiry_date' => 'date',
    ];

    public $appends = ['full_name', 'name'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function getNameAttribute(): string
    {
        return $this->full_name;
    }

    public function getPhotoAttribute(): ?string
    {
        return $this->photoFile?->url;
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function photoFile(): BelongsTo
    {
        return $this->belongsTo(FileUpload::class, 'photo_id');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'guardian_student')
            ->withPivot(['relationship', 'is_primary', 'can_login'])
            ->withTimestamps();
    }
}
