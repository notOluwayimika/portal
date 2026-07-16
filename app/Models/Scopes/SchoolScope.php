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
                } elseif ($this->shouldFailClosed()) {
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
     * Fail closed only when the flag is on and the actor is a non-super-admin
     * user (super admins act globally until they select a School). Off by
     * default, so the legacy fail-open behaviour is unchanged.
     */
    private function shouldFailClosed(): bool
    {
        if (! config('rbac.scope_fail_closed')) {
            return false;
        }

        $user = auth()->user();

        return $user !== null && ! $user->isSuperAdmin();
    }
}
