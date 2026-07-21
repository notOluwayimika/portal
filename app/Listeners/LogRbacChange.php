<?php

namespace App\Listeners;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\ActiveSchool;
use Illuminate\Support\Collection;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;

/**
 * Privilege escalation must leave a trace (v10 §7.5, ADR 0032): every role
 * assignment/removal and every role→permission grant/revoke is written to the
 * durable activity_log — never to authz_observations, which is temporary
 * rollout evidence pruned at 30 days and dropped at teardown (ADR 0043 §4).
 *
 * Fired by Spatie's permission events (config/permission.php events_enabled).
 * School attribution rides ActivitySchoolResolver → ActiveSchool::id(), which
 * is correct on-request and under ActiveSchool::runFor() off-request.
 *
 * Role mutations also flush the affected user's accessibleSchoolIds memo —
 * closing the gap where assignRole/removeRole (unlike grant/revokeSchoolAccess)
 * left a stale within-request cache.
 */
class LogRbacChange
{
    public function handle(
        RoleAttachedEvent|RoleDetachedEvent|PermissionAttachedEvent|PermissionDetachedEvent $event,
    ): void {
        [$action, $subjectKind, $names] = match (true) {
            $event instanceof RoleAttachedEvent => ['role_attached', 'role', $this->roleNames($event->rolesOrIds)],
            $event instanceof RoleDetachedEvent => ['role_detached', 'role', $this->roleNames($event->rolesOrIds)],
            $event instanceof PermissionAttachedEvent => ['permission_attached', 'permission', $this->permissionNames($event->permissionsOrIds)],
            $event instanceof PermissionDetachedEvent => ['permission_detached', 'permission', $this->permissionNames($event->permissionsOrIds)],
        };

        activity('rbac')
            ->performedOn($event->model)
            ->event($action)
            ->withProperties([
                $subjectKind.'s' => $names,
                'team_school_id' => getPermissionsTeamId(),
                'active_school_id' => ActiveSchool::id(),
            ])
            ->log($action.': '.implode(', ', $names));

        if ($event->model instanceof User
            && ($event instanceof RoleAttachedEvent || $event instanceof RoleDetachedEvent)) {
            $event->model->flushSchoolAccessCache();
        }
    }

    /** @return list<string> */
    private function roleNames(mixed $rolesOrIds): array
    {
        return $this->names($rolesOrIds, fn (array $ids) => Role::whereIn('id', $ids)->pluck('name')->all());
    }

    /** @return list<string> */
    private function permissionNames(mixed $permissionsOrIds): array
    {
        return $this->names(
            $permissionsOrIds,
            fn (array $ids) => Permission::whereIn('id', $ids)->pluck('name')->all(),
        );
    }

    /**
     * Spatie ships the attached set as models, names, ids, or arrays thereof.
     *
     * @param  callable(array<int>): list<string>  $lookup
     * @return list<string>
     */
    private function names(mixed $given, callable $lookup): array
    {
        $items = Collection::wrap($given)->flatten();

        $ids = $items->filter(fn ($i) => is_numeric($i))->map(fn ($i) => (int) $i)->all();

        return $items
            ->map(fn ($i) => match (true) {
                is_object($i) && isset($i->name) => (string) $i->name,
                is_string($i) && ! is_numeric($i) => $i,
                default => null,
            })
            ->filter()
            ->merge($ids !== [] ? $lookup($ids) : [])
            ->unique()
            ->values()
            ->all();
    }
}
