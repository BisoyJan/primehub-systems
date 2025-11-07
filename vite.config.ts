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
                manualChunks: {
                    // Split vendor chunks to reduce memory pressure
                    'react-vendor': ['react', 'react-dom'],
                    'radix-ui': [
                        '@radix-ui/react-alert-dialog',
                        '@radix-ui/react-avatar',
                        '@radix-ui/react-checkbox',
                        '@radix-ui/react-collapsible',
                        '@radix-ui/react-dialog',
                        '@radix-ui/react-dropdown-menu',
                        '@radix-ui/react-label',
                        '@radix-ui/react-navigation-menu',
                        '@radix-ui/react-popover',
                        '@radix-ui/react-select',
                        '@radix-ui/react-separator',
                        '@radix-ui/react-slot',
                        '@radix-ui/react-toggle',
                        '@radix-ui/react-toggle-group',
                        '@radix-ui/react-tooltip',
                    ],
                    'animations': ['framer-motion', 'gsap', 'react-useanimations'],
                    'utils': ['clsx', 'tailwind-merge', 'class-variance-authority', 'date-fns'],
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
        host: 'localhost', // Use localhost instead of 0.0.0.0 for ngrok
        port: 5174,
        strictPort: true,
        hmr: {
            host: 'localhost',
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
        ],
        exclude: [
            // Exclude large dependencies from pre-bundling if causing issues
        ],
    },
});
