<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\ActiveSchool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PrincipalController extends Controller
{
    public function index()
    {
        return Inertia::render('admin/principals/index', [
            'principals' => User::role('principal')
                ->orderBy('first_name')
                ->get()
                ->map(fn (User $user) => [
                    'id' => $user->uuid,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                ]),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'school_id' => ActiveSchool::id(),
        ]);
        $user->assignRole('principal');

        return back()->with('success', 'Principal created.');
    }

    public function destroy(User $principal)
    {
        // NOT a §7.2 role-gate, and deliberately left as hasRole (C3 re-audit):
        // this asks whether the TARGET is a principal, not whether the CALLER
        // may act. Role identity is the actual subject of the question — the
        // route's permission gate authorizes the caller. Rewriting it as a
        // permission would change its meaning, not modernise it.
        abort_unless($principal->hasRole('principal'), 404);
        $principal->removeRole('principal');

        return back()->with('success', 'Principal role removed.');
    }
}
