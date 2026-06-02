<?php

namespace App\Listeners;

use App\Http\Resources\UserResource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

use Illuminate\Auth\Events\Login;

class LogLogin
{
    public function handle(Login $event): void
    {
        $user = new UserResource($event->user);
        activity('auth')
            ->causedBy($event->user)
            ->withProperties([
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ])
            ->event('login')
            ->log($user->full_name . ' logged in successfully');
    }
}
