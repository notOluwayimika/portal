<?php

namespace App\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Notice extends Model
{
    use AddUuid, BelongsToSchool;

    protected $fillable = [
        'school_id',
        'notice_category_id',
        'title',
        'body',
        'target_gender',
        'starts_at',
        'ends_at',
        'created_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(NoticeCategory::class, 'notice_category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function classLevels(): BelongsToMany
    {
        return $this->belongsToMany(ClassLevel::class, 'notice_class_level');
    }

    public function classLevelArms(): BelongsToMany
    {
        return $this->belongsToMany(ClassLevelArm::class, 'notice_class_level_arm');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'notice_student');
    }

    public function isActive(): bool
    {
        $now = now();

        if ($this->starts_at && $now->lt($this->starts_at)) {
            return false;
        }

        if ($this->ends_at && $now->gt($this->ends_at)) {
            return false;
        }

        return true;
    }

    public function scopeActive($query)
    {
        $now = now();

        return $query
            ->where('starts_at', '<=', $now)
            ->where(function ($q) use ($now) {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', $now);
            });
    }
}
