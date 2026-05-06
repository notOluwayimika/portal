<?php

namespace App\Models;

use App\Enums\TermStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Term extends Model
{
    protected $fillable = [
        'academic_session_id',
        'name',
        'slug',
        'order',
        'status',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'order' => 'integer',
        'status' => TermStatusEnum::class,
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(fn($model) => $model->uuid ??= (string) Str::uuid());
    }

    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function curricula(): HasMany
    {
        return $this->hasMany(Curriculum::class);
    }
}
