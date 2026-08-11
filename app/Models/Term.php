<?php

namespace App\Models;

use App\Concerns\BelongsToSchool;
use App\Enums\TermStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Term extends Model
{
    use BelongsToSchool, LogsActivity;

    protected $fillable = [
        'academic_session_id',
        'school_id',
        'name',
        'slug',
        'order',
        'status',
        'start_date',
        'end_date',
        'registration_deadline',
        'result_visible_at',
    ];

    protected $casts = [
        'order' => 'integer',
        'status' => TermStatusEnum::class,
        'start_date' => 'date',
        'end_date' => 'date',
        'result_visible_at' => 'date',
        'registration_deadline' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    /** @return BelongsTo<AcademicSession, $this> */
    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    /**
     * HOW A TERM IS NAMED TO A HUMAN ON THE THREE FINANCE-ADJACENT SCREENS — not platform-wide.
     *
     * `name` alone is ambiguous — every session has a "First Term" — and this string is read where the
     * ambiguity decides something: the opening-balance operator picks the term being closed out
     * (routes/web.php), the fee-schedules list picks the term being priced (FeeScheduleResource), and the
     * approvals queue is where the ED decides whether that pricing becomes billable
     * (FeeScheduleChangeResource). Three screens naming one term differently is how an operator picks the
     * wrong one, and until U1's remediation there were three separate expressions, one of which — the
     * approvals queue, the one screen where the decision is made — printed the bare name.
     *
     * THREE OTHER SITES DELIBERATELY DO NOT USE THIS, and the claim is narrowed to say so rather than
     * left reading as "in one place":
     *
     *   app/Http/Resources/TermResource.php:20   $this->name.' - '.$this->academicSession->name
     *   app/Services/BroadsheetService.php:65    $term->name.' - '.$term->academicSession->name
     *   app/Services/BroadsheetService.php:163   same
     *
     * They differ in BOTH word order and separator, so they are not interchangeable with this: one term
     * reads "2026/2027 — First Term" here and "First Term - 2026/2027" on a broadsheet. Pointing
     * TermResource::full_name or a broadsheet header at this method would silently change what renders on
     * result screens and in exported broadsheets, which is a product decision, not a cleanup. See
     * docs/handoff/tickets/term-label-two-formats-across-the-platform.md.
     *
     * `?? ''` on the session hop, and what it does NOT do: it does not make the hop cheap. On an UNLOADED
     * `academicSession` the property access LAZY-LOADS the relation and returns the real name — nothing
     * in app/ or bootstrap/ calls preventLazyLoading or shouldBeStrict, so there is no violation
     * exception either. The `??` degrades only when the relation resolves to NULL, which is the
     * out-of-scope case. Callers must therefore keep eager-loading `academicSession`
     * (FeeScheduleController::index, FeeScheduleChangeController::pending) or pay one query per row.
     */
    public function displayLabel(): string
    {
        return trim(($this->academicSession->name ?? '').' — '.$this->name);
    }

    /** @return HasMany<Curriculum, $this> */
    public function curricula(): HasMany
    {
        return $this->hasMany(Curriculum::class);
    }

    protected static $logName = 'academics';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'name', 'slug', 'order', 'start_date', 'end_date', 'registration_deadline', 'result_visible_at'])
            ->logOnlyDirty();
    }
}
