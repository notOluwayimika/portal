<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stream extends Model
{
    protected $fillable = [
        'uuid',
        'class_level_id',
        'name',
        'code',
        'sort_order',
    ];

    public function classLevel(): BelongsTo
    {
        return $this->belongsTo(ClassLevel::class);
    }
}
