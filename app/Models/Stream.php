<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Stream extends Model
{
    use LogsActivity;
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

    protected static $logName = 'academics';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'code', 'sort_order'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
