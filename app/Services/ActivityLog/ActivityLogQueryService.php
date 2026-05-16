<?php

namespace App\Services\ActivityLog;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Builds the tenant- and permission-scoped activity_log query and applies
 * request filters. This module is read-only and never emits activities.
 *
 * Scoping rules (see implementation_plan.md §10):
 *  - view_cross_school  → no school filter at all
 *  - otherwise          → school_id = current school
 *      (+ school_id IS NULL when view_system AND include_system requested)
 *  - without view_all   → restricted to the current user's own activity
 *  - without view_sensitive → sensitive entries hidden entirely
 */
class ActivityLogQueryService
{
    public function __construct(
        private readonly ActivitySensitiveService $sensitive,
    ) {
    }

    public function currentSchoolId(User $user): int|string|null
    {
        return session('school_id') ?? $user->school_id;
    }

    public function baseQuery(User $user, bool $includeSystem = false): Builder
    {
        $query = Activity::query()->with(['causer', 'subject']);

        if (! $user->can('activity_log.view_cross_school')) {
            $schoolId = $this->currentSchoolId($user);

            $query->where(function (Builder $q) use ($user, $schoolId, $includeSystem) {
                $q->where('activity_log.school_id', $schoolId);

                if ($includeSystem && $user->can('activity_log.view_system')) {
                    $q->orWhereNull('activity_log.school_id');
                }
            });
        }

        // No view_all → users only see activity they themselves caused.
        if (! $user->can('activity_log.view_all')) {
            $query->where('causer_type', User::class)
                ->where('causer_id', $user->id);
        }

        // No view_sensitive → sensitive entries are hidden entirely.
        if (! $user->can('activity_log.view_sensitive')) {
            $this->excludeSensitive($query);
        }

        return $query;
    }

    public function applyFilters(Builder $query, array $f): Builder
    {
        if (! empty($f['search'])) {
            $term = '%' . $f['search'] . '%';
            $query->where(function (Builder $q) use ($term) {
                $q->where('activity_log.description', 'like', $term)
                    ->orWhereHasMorph('causer', [User::class], function (Builder $cq) use ($term) {
                        $cq->where('first_name', 'like', $term)
                            ->orWhere('last_name', 'like', $term)
                            ->orWhere('email', 'like', $term);
                    });
            });
        }

        $this->whereInIfPresent($query, 'causer_id', $f['causer_id'] ?? null);
        $this->whereInIfPresent($query, 'event', $f['event'] ?? null);
        $this->whereInIfPresent($query, 'log_name', $f['log_name'] ?? null);

        if (! empty($f['subject_type'])) {
            $query->where('subject_type', $this->resolveSubjectType($f['subject_type']));
        }

        if (! empty($f['subject_id'])) {
            $query->where('subject_id', $f['subject_id']);
        }

        if (! empty($f['batch_uuid'])) {
            $query->where('batch_uuid', $f['batch_uuid']);
        }

        if (! empty($f['date_from'])) {
            $query->where('activity_log.created_at', '>=', $f['date_from'] . ' 00:00:00');
        }

        if (! empty($f['date_to'])) {
            $query->where('activity_log.created_at', '<=', $f['date_to'] . ' 23:59:59');
        }

        if (! empty($f['severity'])) {
            $this->applySeverityFilter($query, (array) $f['severity']);
        }

        return $query;
    }

    private function whereInIfPresent(Builder $query, string $column, mixed $value): void
    {
        if ($value === null || $value === '' || $value === []) {
            return;
        }

        $query->whereIn($column, (array) $value);
    }

    /** Accepts a model basename ("Guardian") or FQCN ("App\Models\Guardian"). */
    public function resolveSubjectType(string $type): string
    {
        if (str_contains($type, '\\')) {
            return $type;
        }

        $candidate = 'App\\Models\\' . Str::studly($type);

        return class_exists($candidate) ? $candidate : $type;
    }

    /**
     * Severity is derived, not stored, so filtering translates the configured
     * severity patterns into SQL LIKE conditions on log_name/event.
     * 'info' = matches none of the critical/warning/notice patterns.
     */
    private function applySeverityFilter(Builder $query, array $severities): void
    {
        $map = config('activity_log_severity', []);
        $explicitTiers = ['critical', 'warning', 'notice'];

        $query->where(function (Builder $outer) use ($severities, $map, $explicitTiers) {
            foreach ($severities as $severity) {
                if (in_array($severity, $explicitTiers, true)) {
                    $outer->orWhere(fn (Builder $q) => $this->matchPatternGroup($q, (array) ($map[$severity] ?? [])));
                } elseif ($severity === 'info') {
                    $allExplicit = collect($explicitTiers)
                        ->flatMap(fn ($t) => (array) ($map[$t] ?? []))
                        ->all();
                    $outer->orWhere(fn (Builder $q) => $this->notMatchPatternGroup($q, $allExplicit));
                }
            }
        });
    }

    private function matchPatternGroup(Builder $query, array $patterns): void
    {
        $query->where(function (Builder $q) use ($patterns) {
            foreach ($patterns as $pattern) {
                [$logLike, $eventLike] = $this->patternToLike($pattern);
                $q->orWhere(function (Builder $sub) use ($logLike, $eventLike) {
                    $sub->where('log_name', 'like', $logLike)
                        ->where('event', 'like', $eventLike);
                });
            }
        });
    }

    private function notMatchPatternGroup(Builder $query, array $patterns): void
    {
        foreach ($patterns as $pattern) {
            [$logLike, $eventLike] = $this->patternToLike($pattern);
            $query->where(function (Builder $sub) use ($logLike, $eventLike) {
                $sub->where('log_name', 'not like', $logLike)
                    ->orWhere('event', 'not like', $eventLike);
            });
        }
    }

    /** "permissions.*" → ['permissions', '%']; "*.bulk_*" → ['%', 'bulk_%']. */
    private function patternToLike(string $pattern): array
    {
        $parts = explode('.', $pattern, 2);
        $log = str_replace('*', '%', $parts[0] ?? '*');
        $event = str_replace('*', '%', $parts[1] ?? '*');

        return [$log === '' ? '%' : $log, $event === '' ? '%' : $event];
    }

    private function excludeSensitive(Builder $query): void
    {
        $patterns = config('activity_log_sensitive.entries', []);

        foreach ($patterns as $pattern) {
            [$logLike, $eventLike] = $this->patternToLike($pattern);
            $query->where(function (Builder $sub) use ($logLike, $eventLike) {
                $sub->where('log_name', 'not like', $logLike)
                    ->orWhere('event', 'not like', $eventLike);
            });
        }
    }
}
