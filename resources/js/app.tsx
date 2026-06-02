import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { getEcho } from './echo';
import { initializeTheme } from './hooks/use-appearance';

// Eagerly initialize the Echo singleton so any page can call window.Echo or
// import { getEcho } and get the same instance.
getEcho();

// Attach the current Echo socket id to every Inertia visit. The server uses
// broadcast()->toOthers() to exclude this socket, so the originating tab
// doesn't re-receive its own broadcast even when multiple tabs share the
// same user account.
router.on('before', (event) => {
    const socketId = window.Echo?.socketId();
    if (socketId) {
        event.detail.visit.headers = {
            ...event.detail.visit.headers,
            'X-Socket-Id': socketId,
        };
    }
});

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.tsx`,
            import.meta.glob('./pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(
            <>
                <App {...props} />
            </>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
