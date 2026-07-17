<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class GradingSchemeItem extends Model
{
    protected $fillable = ['grading_scheme_id', 'code', 'label', 'display_order'];

    protected static function booted(): void
    {
        static::creating(function (self $item) {
            $item->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function gradingScheme(): BelongsTo
    {
        return $this->belongsTo(GradingScheme::class);
    }
}
