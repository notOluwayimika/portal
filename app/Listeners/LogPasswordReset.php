<?php

namespace App\Listeners;

use App\Http\Resources\UserResource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Auth\Events\PasswordReset;

class LogPasswordReset
{
    public function handle(PasswordReset $event): void
    {
        $user = new UserResource($event->user);
        activity('authentication')
            ->causedBy($event->user)
            ->withProperties([
                'ip' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ])
            ->event('password_reset')
            ->log($user->full_name . ' reset their password');
    }
}
