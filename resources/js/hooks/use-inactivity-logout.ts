import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef } from 'react';
import { toast } from 'sonner';

interface UseInactivityLogoutOptions {
    /**
     * Time in minutes before user is logged out due to inactivity
     * @default 15
     */
    timeout?: number;
    /**
     * Whether to enable inactivity logout
     * @default true
     */
    enabled?: boolean;
    /**
     * Whether to show a warning toast before logout
     * @default true
     */
    showWarning?: boolean;
}

/**
 * Hook to automatically log out users after a period of inactivity.
 * Tracks mouse movements, clicks, key presses, and touch events.
 */
export function useInactivityLogout(options: UseInactivityLogoutOptions = {}) {
    const { timeout = 15, enabled = true, showWarning = true } = options;
    const timeoutRef = useRef<NodeJS.Timeout | null>(null);
    const warningTimeoutRef = useRef<NodeJS.Timeout | null>(null);

    const resetTimer = useCallback(() => {
        // Clear existing timers
        if (timeoutRef.current) {
            clearTimeout(timeoutRef.current);
        }
        if (warningTimeoutRef.current) {
            clearTimeout(warningTimeoutRef.current);
        }

        if (!enabled) return;

        // Set warning timer (1 minute before logout)
        const warningTime = Math.max((timeout - 1) * 60 * 1000, 0);
        if (warningTime > 0 && showWarning) {
            warningTimeoutRef.current = setTimeout(() => {
                toast.warning('Inactivity Warning', {
                    description: 'You will be logged out in 1 minute due to inactivity.',
                    duration: 60000, // Show for 1 minute
                });
            }, warningTime);
        }

        // Set logout timer
        timeoutRef.current = setTimeout(() => {
            // Logout the user
            router.post(
                '/logout',
                {},
                {
                    onSuccess: () => {
                        toast.info('Session Expired', {
                            description: 'You have been logged out due to inactivity.',
                        });
                    },
                }
            );
        }, timeout * 60 * 1000); // Convert minutes to milliseconds
    }, [timeout, enabled, showWarning]);

    useEffect(() => {
        if (!enabled) return;

        // List of events to track user activity
        const events = [
            'mousedown',
            'mousemove',
            'keypress',
            'scroll',
            'touchstart',
            'click',
        ];

        // Reset timer on any activity
        events.forEach((event) => {
            window.addEventListener(event, resetTimer);
        });

        // Initialize timer
        resetTimer();

        // Cleanup
        return () => {
            events.forEach((event) => {
                window.removeEventListener(event, resetTimer);
            });
            if (timeoutRef.current) {
                clearTimeout(timeoutRef.current);
            }
            if (warningTimeoutRef.current) {
                clearTimeout(warningTimeoutRef.current);
            }
        };
    }, [resetTimer, enabled]);
}
