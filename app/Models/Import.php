<?php

namespace App\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Import extends Model
{
    use AddUuid, BelongsToSchool;

    protected $fillable = [
        'school_id',
        'user_id',
        'type',
        'file_name',
        'file_path',
        'status',
        'total_rows',
        'processed_rows',
        'succeeded',
        'failed',
        'skipped',
        'report_path',
        'error',
        'update_existing_links',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'update_existing_links' => 'boolean',
        'started_at'            => 'datetime',
        'completed_at'          => 'datetime',
        'total_rows'            => 'integer',
        'processed_rows'        => 'integer',
        'succeeded'             => 'integer',
        'failed'                => 'integer',
        'skipped'               => 'integer',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
