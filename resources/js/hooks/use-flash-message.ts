import { useEffect } from 'react';
import { usePage } from '@inertiajs/react';
import { toast } from 'sonner';

interface FlashMessage {
    message?: string;
    type?: 'success' | 'error' | 'info' | 'warning';
}

/**
 * Custom hook to handle flash messages from server
 * Automatically displays toast notifications based on flash message type
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

    useEffect(() => {
        if (!flash?.message) return;

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
