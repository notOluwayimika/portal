<?php
// app/Models/MarkingComponent.php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarkingComponent extends Model
{
    use HasUuids;

    protected $fillable = ['curriculum_subject_id', 'name', 'weight'];

    protected $casts = ['weight' => 'decimal:3'];

    public function curriculumSubject(): BelongsTo
    {
        return $this->belongsTo(CurriculumSubject::class);
    }
    public function scores(): HasMany
    {
        return $this->hasMany(Score::class);
    }
}
