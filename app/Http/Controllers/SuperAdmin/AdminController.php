<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

class AdminController extends Controller
{
    public function index()
    {
        $adminRoleIds = Role::where('name', 'admin')->pluck('id');

        $adminUserIds = DB::table('model_has_roles')
            ->whereIn('role_id', $adminRoleIds)
            ->where('model_type', User::class)
            ->pluck('model_id')
            ->unique();

        $admins = User::withoutGlobalScope(SchoolScope::class)
            ->whereIn('id', $adminUserIds)
            ->with('schools')
            ->orderBy('first_name')
            ->get();

        return Inertia::render('super-admin/admins/index', [
            'admins' => $admins->map(fn ($u) => [
                'uuid' => $u->uuid,
                'name' => $u->full_name,
                'email' => $u->email,
                'disabled' => $u->isDisabled(),
                'schools' => $u->schools->map(fn ($s) => ['uuid' => $s->uuid, 'name' => $s->name])->values(),
            ])->values(),
            'schools' => School::orderBy('name')->get()
                ->map(fn ($s) => ['uuid' => $s->uuid, 'name' => $s->name])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'schools' => ['required', 'array', 'min:1'],
            'schools.*' => ['uuid', 'exists:schools,uuid'],
        ]);

        $schools = School::whereIn('uuid', $data['schools'])->get();

        $user = User::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            // primary school; login access is governed by the pivot
            'school_id' => $schools->first()->id,
        ]);

        foreach ($schools as $school) {
            $user->grantSchoolAccess($school);
        }

        return back()->with('success', 'Admin created.');
    }

    /**
     * Replace an admin's school access with the given set.
     */
    public function syncSchools(Request $request, string $uuid)
    {
        $data = $request->validate([
            'schools' => ['array'],
            'schools.*' => ['uuid', 'exists:schools,uuid'],
        ]);

        /** @var \App\Models\User $user */
        $user = User::withoutGlobalScope(SchoolScope::class)->where('uuid', $uuid)->firstOrFail();

        $target = School::whereIn('uuid', $data['schools'] ?? [])->get();
        $current = $user->schools()->get();

        foreach ($target as $school) {
            if (!$current->contains('id', $school->id)) {
                $user->grantSchoolAccess($school);
            }
        }

        foreach ($current as $school) {
            if (!$target->contains('id', $school->id)) {
                $user->revokeSchoolAccess($school);
            }
        }

        // Keep the fallback school_id pointing at a school they can access.
        if (!$target->contains('id', $user->school_id)) {
            $user->forceFill(['school_id' => $target->first()?->id])->save();
        }

        return back()->with('success', 'School access updated.');
    }
}
