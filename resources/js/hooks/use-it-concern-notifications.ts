import { useCallback, useEffect, useRef } from 'react';
import { router } from '@inertiajs/react';
import { getEcho } from '@/echo';
import { usePermission } from '@/hooks/use-permission';

interface ItConcernBroadcast {
    id: number;
    priority: string;
    category: string;
    station_number: string;
    site_name?: string | null;
    reporter_name?: string | null;
    status?: string;
    link: string;
    at: string;
}

interface UseItConcernNotificationsOptions {
    onConcern?: () => void;
}

/**
 * Subscribes to the `it-concerns` private channel and shows a browser desktop
 * notification whenever a new IT concern is created. Because it reacts to a
 * WebSocket push (not a throttled timer), it fires even when the tab is not
 * focused. Mounted globally so IT staff are notified regardless of the page
 * they are on. Gated by the same permission that authorizes the channel.
 */
export function useItConcernNotifications({ onConcern }: UseItConcernNotificationsOptions = {}): void {
    const { can } = usePermission();
    const enabled = can('it_concerns.resolve');
    const onConcernRef = useRef(onConcern);
    onConcernRef.current = onConcern;

    const showNotification = useCallback((concern: ItConcernBroadcast) => {
        if (typeof window === 'undefined' || !('Notification' in window) || Notification.permission !== 'granted') {
            return;
        }

        const reporter = concern.reporter_name ?? 'A user';
        const title = `🔧 New IT Concern (${concern.priority.toUpperCase()})`;
        const body = `${reporter} reported a ${concern.category} issue at Station ${concern.station_number}${concern.site_name ? ` — ${concern.site_name}` : ''}`;

        // Reuse the same tag the Index page uses so the browser de-duplicates
        // when the IT user happens to also be on the concerns list page.
        const n = new Notification(title, {
            body,
            icon: '/favicon.ico',
            tag: `it-concern-${concern.id}`,
            requireInteraction: true,
        });
        n.onclick = () => {
            window.focus();
            router.visit(concern.link);
            n.close();
        };
    }, []);

    useEffect(() => {
        if (!enabled) return;

        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission();
        }

        const echoInstance = getEcho();
        if (!echoInstance) return;

        echoInstance
            .private('it-concerns')
            .listen('.concern.created', (concern: ItConcernBroadcast) => {
                showNotification(concern);
                onConcernRef.current?.();
            });

        return () => {
            echoInstance.leave('it-concerns');
        };
    }, [enabled, showNotification]);
}
