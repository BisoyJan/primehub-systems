import { useEffect, useRef } from 'react';
import { usePage } from '@inertiajs/react';
import { toast } from 'sonner';

interface FlashMessage {
    message?: string;
    type?: 'success' | 'error' | 'info' | 'warning';
}

/**
 * Custom hook to handle flash messages from server
 * Automatically displays toast notifications based on flash message type
 * Tracks shown messages to prevent duplicate toasts on page navigation
 *
 * @example
 * ```tsx
 * export default function MyPage() {
 *   useFlashMessage();
 *
 *   return <div>...</div>;
 * }
 * ```
 */
export function useFlashMessage() {
    const { flash } = usePage().props as { flash?: FlashMessage };
    const shownMessageRef = useRef<string | null>(null);

    useEffect(() => {
        if (!flash?.message) {
            // Reset when there's no message (allows showing same message again after it's cleared)
            shownMessageRef.current = null;
            return;
        }

        // Create a unique key for this message
        const messageKey = `${flash.message}-${flash.type}`;

        // Skip if we've already shown this exact message
        if (shownMessageRef.current === messageKey) {
            return;
        }

        // Mark as shown
        shownMessageRef.current = messageKey;

        switch (flash.type) {
            case 'error':
                toast.error(flash.message);
                break;
            case 'warning':
                toast.warning(flash.message);
                break;
            case 'info':
                toast.info(flash.message);
                break;
            case 'success':
            default:
                toast.success(flash.message);
                break;
        }
    }, [flash?.message, flash?.type]);
}
