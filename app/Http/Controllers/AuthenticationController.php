<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\School;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Http\Responses\RegisterResponse;

class AuthenticationController extends Controller
{
    protected $guard;

    public function __construct(StatefulGuard $guard)
    {
        $this->guard = $guard;
    }
    public function store(Request $request, CreatesNewUsers $creator): RegisterResponse
    {

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => ['required', 'string', 'confirmed', Password::default()],
        ]);
        $school = School::firstOrCreate(['slug' => 'secondary-school'], ["name" => "Secondary School", "slug" => Str::slug("Secondary School")]);
        $user = $school->users()->create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password)
        ]);
        event(new Registered($user));
        if (!Role::where('name', 'admin')->where('guard_name', 'web')->exists()) {
            Role::create(['name' => 'admin', 'guard_name' => 'web']);
        }
        if (!Role::where('name', 'admin')->where('guard_name', 'api')->exists()) {
            Role::create(['name' => 'admin', 'guard_name' => 'api']);
        }
        $user->assignRole('admin');

        $this->guard->login($user, $request->boolean('remember'));


        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return app(RegisterResponse::class);
    }
}
