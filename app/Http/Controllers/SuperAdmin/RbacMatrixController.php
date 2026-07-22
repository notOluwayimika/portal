<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Enums\Permission as PermissionEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\SyncRolePermissionsRequest;
use App\Models\Role;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * The super-admin role × permission matrix (C6): site-wide editing of what
 * each role is GRANTED. Definitions are code (the Permission enum + the nine
 * seeded roles) — nothing is created or deleted here; the seeder remains the
 * canonical default and runtime edits survive rbac:sync (its non-destructive
 * contract, pinned end-to-end by the C6 tests).
 */
class RbacMatrixController extends Controller
{
    public function index()
    {
        $roles = Role::with('permissions')
            ->where('guard_name', RbacSeeder::GUARD)
            ->whereNull('school_id')
            ->orderBy('name')
            ->get();

        return Inertia::render('super-admin/rbac/index', [
            'permissions' => PermissionEnum::values(),
            'roles' => $roles->map(fn (Role $role) => [
                'name' => $role->getAttribute('name'),
                // The immutable row renders read-only; the guard is D1.
                'editable' => $role->getAttribute('name') !== 'super_admin',
                'permissions' => $role->permissions->pluck('name')->sort()->values(),
            ])->values(),
        ]);
    }

    public function syncPermissions(SyncRolePermissionsRequest $request, string $roleName)
    {
        $role = Role::where('name', $roleName)
            ->where('guard_name', RbacSeeder::GUARD)
            ->whereNull('school_id')
            ->firstOrFail();

        $current = $role->permissions->pluck('name');
        $wanted = collect($request->validated('permissions'));

        $toRevoke = $current->diff($wanted)->values();
        $toGrant = $wanted->diff($current)->values();

        // D3 — diff-based revoke+give, NEVER spatie's syncPermissions, inside
        // one transaction. Three vendor-read reasons (c6-brief step 0):
        //  1. syncPermissions detaches RAW — no PermissionDetachedEvent — so
        //     its removals would write NO audit row: untraceable privilege
        //     revocation. revokePermissionTo fires the event the C1 listener
        //     records.
        //  2. Its detach-all strips the ENTIRE role between halves; the diff
        //     touches only what actually changes.
        //  3. The trait holds no transaction of its own, so an un-wrapped
        //     failure between revoke and give would persist the revocations
        //     and never apply the grants — at ROLE scope, stripping every
        //     holder in every School at once. Bite-proven at the true
        //     between-halves point (PermissionDetachedEvent fires post-
        //     revoke-write, pre-give).
        DB::transaction(function () use ($role, $toRevoke, $toGrant) {
            if ($toRevoke->isNotEmpty()) {
                $role->revokePermissionTo($toRevoke->all());
            }

            if ($toGrant->isNotEmpty()) {
                $role->givePermissionTo($toGrant->all());
            }
        });

        return back()->with('success', "Permissions updated for {$roleName}.");
    }
}
