<?php

namespace App\Http\Traits;

use Illuminate\Http\RedirectResponse;

trait RedirectsWithFlashMessages
{
    /**
     * Redirect to route (or validated `_return_to` URL) with flash message
     */
    protected function redirectWithFlash(string $route, string $message, string $type = 'success', array $errors = [])
    {
        $redirect = $this->redirectToIndexOrReturnUrl($route)->with('message', $message)->with('type', $type);

        if (! empty($errors)) {
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

        if (! empty($errors)) {
            $redirect->withErrors($errors);
        }

        return $redirect;
    }

    /**
     * Redirect to the `_return_to` URL submitted with the request when it is a safe
     * relative path (preserves list filters), otherwise to the given named route.
     *
     * @param  array<string, mixed>  $params
     */
    protected function redirectToIndexOrReturnUrl(string $route, array $params = []): RedirectResponse
    {
        $returnTo = $this->safeReturnTo(request()->input('_return_to'));

        return $returnTo !== null
            ? redirect()->to($returnTo)
            : redirect()->route($route, $params);
    }

    /**
     * Accept only same-origin relative paths (e.g. "/stations?page=2&site_id=1").
     * Rejects absolute URLs, protocol-relative URLs, backslashes and control characters.
     */
    protected function safeReturnTo(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);

        if ($url === '' || $url[0] !== '/' || str_starts_with($url, '//') || strlen($url) > 2048) {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $url) || str_contains($url, '://')) {
            return null;
        }

        return $url;
    }
}
