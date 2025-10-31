import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            ssr: 'resources/js/ssr.tsx',
            refresh: false, // Disable auto-refresh to prevent cursor-triggered reloads
        }),
        react(),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ],
    esbuild: {
        jsx: 'automatic',
    },
    build: {
        rollupOptions: {
            onwarn(warning, warn) {
                // Suppress eval warnings from lottie-web (used by react-useanimations)
                if (warning.code === 'EVAL' && warning.id?.includes('lottie')) {
                    return;
                }
                warn(warning);
            },
        },
    },
    server: {
        host: process.env.VITE_HOST || '0.0.0.0',
        port: parseInt(process.env.VITE_PORT || '5173'),
        strictPort: true,
        hmr: {
            host: process.env.VITE_HMR_HOST || 'localhost',
            protocol: 'ws'
        },
        watch: {
            usePolling: true, // Enable polling for Docker
        }
    }
});
