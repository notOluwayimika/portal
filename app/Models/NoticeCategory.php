<?php

namespace App\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NoticeCategory extends Model
{
    use AddUuid, BelongsToSchool;

    protected $fillable = [
        'school_id',
        'name',
        'slug',
        'color',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function notices(): HasMany
    {
        return $this->hasMany(Notice::class);
    }
}
