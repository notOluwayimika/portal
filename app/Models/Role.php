<?php

namespace App\Models;

use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    // Role rows are security facts: creating/renaming/deleting one must leave
    // a durable trace (v10 §7.5). Grant/revoke mutations are logged by the
    // LogRbacChange listener; this covers the model rows themselves.
    use LogsActivity;

    protected static function booted(): void
    {
        static::creating(function ($model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('rbac')
            ->logOnly(['name', 'guard_name', 'school_id'])
            ->logOnlyDirty();
    }
}
