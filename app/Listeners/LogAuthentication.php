<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;

class LogAuthentication
{
    public function onLogin(Login $event)
    {
        if ($event->user) {
            activity()
                ->performedOn($event->user)
                ->causedBy($event->user)
                ->withProperties(['ip' => request()->ip(), 'user_agent' => request()->userAgent()])
                ->log('login');
        }
    }

    public function onLogout(Logout $event)
    {
        if ($event->user) {
            activity()
                ->performedOn($event->user)
                ->causedBy($event->user)
                ->withProperties(['ip' => request()->ip(), 'user_agent' => request()->userAgent()])
                ->log('logout');
        }
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Logout::class => 'onLogout',
        ];
    }
}
