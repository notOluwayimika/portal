<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A backfill's completion marker.
 *
 * NOT School-scoped: a data backfill is a deployment-wide operation over every
 * tenant's rows at once, so a per-School marker would let the gated predicate read
 * "done" in one School while another's rows were never touched.
 */
class DataBackfill extends Model
{
    public const CONTACT_POINTS = 'contact_points';

    protected $fillable = ['key', 'started_at', 'completed_at', 'stats'];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'stats' => 'array',
    ];

    /**
     * Has this backfill FINISHED?
     *
     * A started-but-not-completed row answers FALSE, and that is the interlock: an
     * interrupted run must leave every gated reader on its legacy behaviour, because
     * the alternative is a predicate that reports "no contact point" for the rows the
     * run never reached — school-wide partial silent-drop.
     */
    public static function isComplete(string $key): bool
    {
        return static::query()
            ->where('key', $key)
            ->whereNotNull('completed_at')
            ->exists();
    }
}
