import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';
import type { RollupLog, LoggingFunction } from 'rollup';
import { execSync } from 'child_process';

// Check if PHP is available (synchronously for vite config)
function isPhpAvailable(): boolean {
    try {
        execSync('php --version', { stdio: 'ignore' });
        return true;
    } catch {
        return false;
    }
}

const phpAvailable = isPhpAvailable();

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            ssr: 'resources/js/ssr.tsx',
            refresh: true,
        }),
        react(),
        tailwindcss(),
        // Only add wayfinder plugin if PHP is available
        ...(phpAvailable
            ? [wayfinder({
                formVariants: true,
            })]
            : (() => {
                console.warn('⚠️  PHP not available - skipping wayfinder plugin. Run "php artisan wayfinder:generate --with-form" manually.');
                return [];
            })()
        ),
    ],
    esbuild: {
        jsx: 'automatic',
    },
    build: {
        rollupOptions: {
            onwarn(warning: RollupLog, warn: LoggingFunction) {
                // Suppress eval warnings from lottie-web (used by react-useanimations)
                if (warning.code === 'EVAL' && warning.id?.includes('lottie')) {
                    return;
                }
                warn(warning);
            },
        },
    },
    server: {
        host: '0.0.0.0', // Listen on all network interfaces
        port: 5173,
        strictPort: true,
        hmr: {
            host: 'localhost', // Will use current hostname
            protocol: 'ws'
        },
    }
});
