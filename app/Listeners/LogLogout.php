<?php

namespace App\Listeners;

use App\Http\Resources\UserResource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

use Illuminate\Auth\Events\Logout;

class LogLogout
{
    public function handle(Logout $event): void
    {
        $user = new UserResource($event->user);
        activity('auth')
            ->causedBy($event->user)
            ->event('logout')
            ->log($user->full_name . ' logged out');
    }
}
