<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedActivityFilter extends Model
{
    protected $fillable = ['user_id', 'school_id', 'name', 'filters', 'is_default'];

    protected $casts = [
        'filters'    => 'array',
        'is_default' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
