import { useMemo } from 'react';

const RETURN_TO_PARAM = 'return_to';

/**
 * Accept only same-origin relative paths (e.g. "/stations?page=2").
 * Mirrors the backend validation in RedirectsWithFlashMessages::safeReturnTo().
 */
export function sanitizeReturnTo(value: string | null | undefined): string | null {
    if (!value) {
        return null;
    }

    const url = value.trim();

    if (!url.startsWith('/') || url.startsWith('//') || url.includes('\\') || url.includes('://')) {
        return null;
    }

    return url;
}

/**
 * Current page path + query string, used as the `return_to` value when leaving a filtered list.
 */
export function currentLocationPath(): string | null {
    if (typeof window === 'undefined') {
        return null;
    }

    return `${window.location.pathname}${window.location.search}`;
}

/**
 * Append `return_to=<current path+query>` to a URL so the destination page can come back
 * to the same filtered list. Safe to call with URLs that already contain a query string.
 */
export function withReturnTo(url: string, returnTo: string | null = currentLocationPath()): string {
    const safe = sanitizeReturnTo(returnTo);

    if (!safe) {
        return url;
    }

    const separator = url.includes('?') ? '&' : '?';

    return `${url}${separator}${RETURN_TO_PARAM}=${encodeURIComponent(safe)}`;
}

/**
 * Read the `return_to` query param from the current URL.
 */
export function readReturnTo(): string | null {
    if (typeof window === 'undefined') {
        return null;
    }

    return sanitizeReturnTo(new URLSearchParams(window.location.search).get(RETURN_TO_PARAM));
}

/**
 * Resolve where a Create/Edit page should go back to.
 *
 * - `returnTo`: validated `return_to` from the URL, or null. Send as `_return_to` with the form.
 * - `backUrl`: `returnTo` when present, otherwise the given fallback (usually the index route).
 */
export function useReturnTo(fallbackUrl: string): { returnTo: string | null; backUrl: string } {
    return useMemo(() => {
        const returnTo = readReturnTo();

        return { returnTo, backUrl: returnTo ?? fallbackUrl };
    }, [fallbackUrl]);
}
