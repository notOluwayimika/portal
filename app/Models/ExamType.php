<?php

// app/Models/ExamType.php

namespace App\Models;

use App\Models\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property string $uuid set in the creating hook below; annotated so Larastan can see it
 * @property string $name
 */
class ExamType extends Model
{
    use LogsActivity;

    protected $fillable = ['school_id', 'name', 'slug'];

    protected static function booted(): void
    {
        static::addGlobalScope(new SchoolScope);
        static::creating(function ($model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return HasMany<Curriculum, $this> */
    public function curricula(): HasMany
    {
        return $this->hasMany(Curriculum::class);
    }

    /** @return HasMany<GradeBoundary, $this> */
    public function gradeBoundaries(): HasMany
    {
        return $this->hasMany(GradeBoundary::class);
    }

    protected static $logName = 'academics';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'slug'])
            ->logOnlyDirty();
    }
}
