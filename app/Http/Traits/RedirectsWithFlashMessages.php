<?php

namespace App\Http\Traits;

trait RedirectsWithFlashMessages
{
    /**
     * Redirect to route with flash message
     */
    protected function redirectWithFlash(string $route, string $message, string $type = 'success', array $errors = [])
    {
        $redirect = redirect()->route($route)->with('message', $message)->with('type', $type);

        if (!empty($errors)) {
            $redirect->withErrors($errors);
        }

        return $redirect;
    }

    /**
     * Redirect back with flash message
     */
    protected function backWithFlash(string $message, string $type = 'success', array $errors = [])
    {
        $redirect = redirect()->back()->with('message', $message)->with('type', $type);

        if (!empty($errors)) {
            $redirect->withErrors($errors);
        }

        return $redirect;
    }
}
