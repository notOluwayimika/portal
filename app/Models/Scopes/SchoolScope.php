<?php

namespace App\Models\Scopes;

use App\Exceptions\MissingSchoolContextException;
use App\Models\User;
use App\Support\ActiveSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Log;

class SchoolScope implements Scope
{
    private static $isApplying = false;

    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * Scopes every query to the active school. Super admins are only
     * unscoped while they have NOT selected a school; once they enter a
     * school context they see that school's data like any other user.
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (static::$isApplying) {
            return;
        }

        // Users are identities, not tenant data: they can span multiple
        // schools, and the auth layer (session guard retrieveById, login
        // email lookups) must always be able to find them. Scoping User
        // caused "credentials do not match" / forced logouts whenever the
        // session's school_id didn't match the user's own school_id.
        // Per-school access is enforced by SetSchoolContext instead.
        if ($model instanceof User) {
            return;
        }

        static::$isApplying = true;

        try {
            if (auth()->check()) {
                $schoolId = ActiveSchool::id();

                if ($schoolId) {
                    // Models can override how they scope to a school (e.g.
                    // Teacher, which is also visible in schools granted via
                    // the school_user pivot).
                    if (method_exists($model, 'applySchoolScope')) {
                        $model->applySchoolScope($builder, (int) $schoolId);
                    } else {
                        $builder->where($model->getTable().'.school_id', $schoolId);
                    }
                } elseif ($this->shouldFailClosed($model)) {
                    // §5.5: no context is an exception, never a silent unscoped
                    // read. Rethrown past the catch below so it is never swallowed.
                    throw new MissingSchoolContextException($model::class);
                }
            }
        } catch (MissingSchoolContextException $e) {
            throw $e;
        } catch (\Throwable $th) {
            Log::error('SchoolScope error: '.$th->getMessage());
        } finally {
            static::$isApplying = false;
        }
    }

    /**
     * Whether this specific model is fail-closed. Rollout is PER-MODEL (roadmap
     * Rollout Flags table, Risk #14): a model throws on missing context only once
     * it is added to the rbac.fail_closed_models allowlist. Empty by default, so
     * un-opted-in models keep the legacy fail-open behaviour and each model can
     * be enabled/reverted independently.
     *
     * There is deliberately NO super-admin exemption: authority and isolation
     * are separate axes. The team-less super_admin bypasses *authorization*
     * (Gate::before) but not *School isolation* — access to School-owned data
     * still requires an active School (or an explicit withoutSchoolScope()
     * declared read model, §14). Platform models (User, School, Role) are not
     * School-scoped, so this scope never applies to them and they stay globally
     * reachable.
     */
    private function shouldFailClosed(Model $model): bool
    {
        $class = ltrim($model::class, '\\');

        foreach ((array) config('rbac.fail_closed_models', []) as $enabled) {
            if (ltrim((string) $enabled, '\\') === $class) {
                return true;
            }
        }

        return false;
    }
}
