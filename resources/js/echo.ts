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
const host = (import.meta.env.VITE_REVERB_HOST as string | undefined) ?? window.location.hostname;
const port = Number(import.meta.env.VITE_REVERB_PORT ?? 8080);
const scheme = (import.meta.env.VITE_REVERB_SCHEME as string | undefined) ?? 'http';

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
