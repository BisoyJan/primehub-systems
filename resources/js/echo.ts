import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo: Echo<'reverb'>;
    }
}

window.Pusher = Pusher;

const key = import.meta.env.VITE_REVERB_APP_KEY as string | undefined;

// VITE_REVERB_HOST/PORT/SCHEME are tied to the server-side Reverb daemon config
// (localhost:8080, http) via ${REVERB_*} expansion in .env.  In production nginx
// terminates TLS on 443 and proxies /app/* to the local daemon, so the browser
// must connect to the public domain over WSS:443 — derive all three from
// window.location at runtime when on HTTPS instead of trusting the baked-in vars.
const isHttps = window.location.protocol === 'https:';
const host = isHttps
    ? window.location.hostname
    : ((import.meta.env.VITE_REVERB_HOST as string | undefined) ??
      window.location.hostname);
const scheme = isHttps
    ? 'https'
    : ((import.meta.env.VITE_REVERB_SCHEME as string | undefined) ?? 'http');
const port = isHttps ? 443 : Number(import.meta.env.VITE_REVERB_PORT ?? 8080);

let echo: Echo<'reverb'> | null = null;

/**
 * Resolve the CSRF header at the moment a channel is authorized. The token in
 * the <meta> tag is rendered once per full page load, but logging in (an
 * Inertia XHR visit) regenerates the session token, so a snapshot would make
 * every `/broadcasting/auth` call fail with 419 until a hard refresh. Laravel
 * refreshes the XSRF-TOKEN cookie on every response, so prefer it. Only one
 * header is sent because Laravel checks X-CSRF-TOKEN before X-XSRF-TOKEN.
 */
function csrfHeaders(): Record<string, string> {
    const xsrfCookie = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='));

    if (xsrfCookie) {
        return {
            'X-XSRF-TOKEN': decodeURIComponent(
                xsrfCookie.slice('XSRF-TOKEN='.length),
            ),
        };
    }

    const metaToken = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;

    return metaToken ? { 'X-CSRF-TOKEN': metaToken } : {};
}

export function getEcho(): Echo<'reverb'> | null {
    if (!key) {
        return null;
    }

    if (!echo) {
        echo = new Echo({
            broadcaster: 'reverb',
            key,
            wsHost: host,
            wsPort: port,
            wssPort: port,
            forceTLS: scheme === 'https',
            enabledTransports: ['ws', 'wss'],
            // pusher-js uses `channelAuthorization` instead of Echo's static
            // `auth.headers` (which would pin the boot-time CSRF token).
            channelAuthorization: {
                endpoint: '/broadcasting/auth',
                transport: 'ajax',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                headersProvider: csrfHeaders,
            },
        });
        window.Echo = echo;
    }

    return echo;
}
