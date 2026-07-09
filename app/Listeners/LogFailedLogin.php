<?php

namespace App\Listeners;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Auth\Events\Failed;

class LogFailedLogin
{
    public function handle(Failed $event): void
    {
        $user = User::where('email', request('email'))->first();
        if ($user) {
            $userResource = new UserResource($user);
            $name = $userResource->full_name;
        } else {
            $name = 'User';
        }
        activity('auth')
            ->withProperties([
                'email' => request('email'),
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ])
            ->event('failed_login')
            ->log($name . ' failed to log in');
    }
}
