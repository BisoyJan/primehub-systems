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
    : ((import.meta.env.VITE_REVERB_HOST as string | undefined) ?? window.location.hostname);
const scheme = isHttps ? 'https' : ((import.meta.env.VITE_REVERB_SCHEME as string | undefined) ?? 'http');
const port = isHttps ? 443 : Number(import.meta.env.VITE_REVERB_PORT ?? 8080);

let echo: Echo<'reverb'> | null = null;

export function getEcho(): Echo<'reverb'> | null {
    if (!key) {
        return null;
    }

    if (!echo) {
        const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '';
        echo = new Echo({
            broadcaster: 'reverb',
            key,
            wsHost: host,
            wsPort: port,
            wssPort: port,
            forceTLS: scheme === 'https',
            enabledTransports: ['ws', 'wss'],
            authEndpoint: '/broadcasting/auth',
            auth: {
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
            },
        });
        window.Echo = echo;
    }

    return echo;
}
