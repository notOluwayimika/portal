<?php

namespace App\Models;

use App\Concerns\BelongsToSchool;
use App\Enums\TermStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Term extends Model
{
    use BelongsToSchool, LogsActivity;

    protected $fillable = [
        'academic_session_id',
        'school_id',
        'name',
        'slug',
        'order',
        'status',
        'start_date',
        'end_date',
        'registration_deadline',
        'result_visible_at',
    ];

    protected $casts = [
        'order' => 'integer',
        'status' => TermStatusEnum::class,
        'start_date' => 'date',
        'end_date' => 'date',
        'result_visible_at' => 'date',
        'registration_deadline' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function curricula(): HasMany
    {
        return $this->hasMany(Curriculum::class);
    }

    protected static $logName = 'academics';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'name', 'slug', 'order', 'start_date', 'end_date', 'registration_deadline', 'result_visible_at'])
            ->logOnlyDirty();
    }
}
