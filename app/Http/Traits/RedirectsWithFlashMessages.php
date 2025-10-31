<?php

namespace App\Http\Traits;

trait RedirectsWithFlashMessages
{
    /**
     * Redirect to route with flash message
     */
    protected function redirectWithFlash(string $route, string $message, string $type = 'success')
    {
        return redirect()
            ->route($route)
            ->with('message', $message)
            ->with('type', $type);
    }

    /**
     * Redirect back with flash message
     */
    protected function backWithFlash(string $message, string $type = 'success')
    {
        return redirect()
            ->back()
            ->with('message', $message)
            ->with('type', $type);
    }
}
