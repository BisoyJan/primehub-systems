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
            refresh: [
                'app/**/*.php',
                'routes/**/*.php',
                'resources/views/**/*.blade.php',
            ],
        }),
        react(),
        tailwindcss(),
        // Only add wayfinder plugin if PHP is available
        ...(phpAvailable
            ? [wayfinder({
                formVariants: true,
            })]
            : (() => {
                return [];
            })()
        ),
    ],
    esbuild: {
        jsx: 'automatic',
    },
    build: {
        // Optimize chunk size to prevent memory issues
        chunkSizeWarningLimit: 1000,
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (id.includes('node_modules/react/') || id.includes('node_modules/react-dom/')) {
                        return 'react-vendor';
                    }
                    if (id.includes('@radix-ui/')) {
                        return 'radix-ui';
                    }
                    if (id.includes('framer-motion') || id.includes('gsap') || id.includes('react-useanimations')) {
                        return 'animations';
                    }
                    if (id.includes('clsx') || id.includes('tailwind-merge') || id.includes('class-variance-authority') || id.includes('date-fns')) {
                        return 'utils';
                    }
                },
            },
            onwarn(warning: RollupLog, warn: LoggingFunction) {
                // Suppress eval warnings from lottie-web (used by react-useanimations)
                if (warning.code === 'EVAL' && warning.id?.includes('lottie')) {
                    return;
                }
                warn(warning);
            },
        },
        // Optimize source maps for development
        sourcemap: false,
    },
    server: {
        host: process.env.VITE_HOST || '0.0.0.0', // Allow external connections for ngrok
        port: 5175,
        strictPort: true,
        hmr: {
            // Use ngrok URL for HMR if provided, otherwise use host
            host: process.env.VITE_HMR_HOST || (process.env.VITE_HOST || 'localhost'),
            protocol: process.env.VITE_HMR_PROTOCOL || 'ws',
            port: process.env.VITE_HMR_PORT ? parseInt(process.env.VITE_HMR_PORT) : 5175,
        },
        // Optimize file watching
        watch: {
            // Ignore node_modules and vendor to reduce memory
            ignored: ['**/node_modules/**', '**/vendor/**', '**/storage/**'],
        },
    },
    // Optimize dependency pre-bundling
    optimizeDeps: {
        include: [
            'react',
            'react-dom',
            '@inertiajs/react',
            'react-useanimations',
            'react-useanimations/lib/help',
            'react-useanimations/lib/alertTriangle',
        ],
        exclude: [
            // Exclude large dependencies from pre-bundling if causing issues
        ],
    },
});
