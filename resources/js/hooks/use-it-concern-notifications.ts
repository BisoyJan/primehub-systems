import { getEcho } from '@/echo';
import { usePermission } from '@/hooks/use-permission';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef } from 'react';

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

const CHANNEL = 'it-concerns';
const EVENT = '.concern.created';

// Every page renders its own AppLayout, so this hook unmounts and remounts on
// each Inertia visit. Leaving the channel right away would unsubscribe and
// re-authorize on every navigation, dropping any concern broadcast in between.
// The leave is deferred so the next page can reuse the live subscription; it
// still runs once no page needs it (e.g. after logout).
const LEAVE_DELAY_MS = 2000;
let pendingLeave: ReturnType<typeof setTimeout> | null = null;

/**
 * Subscribes to the `it-concerns` private channel and shows a browser desktop
 * notification whenever a new IT concern is created. Because it reacts to a
 * WebSocket push (not a throttled timer), it fires even when the tab is not
 * focused. Mounted globally so IT staff are notified regardless of the page
 * they are on. Gated by the same permission that authorizes the channel.
 */
export function useItConcernNotifications({
    onConcern,
}: UseItConcernNotificationsOptions = {}): void {
    const { can } = usePermission();
    const enabled = can('it_concerns.resolve');
    const onConcernRef = useRef(onConcern);
    onConcernRef.current = onConcern;

    const showNotification = useCallback((concern: ItConcernBroadcast) => {
        if (
            typeof window === 'undefined' ||
            !('Notification' in window) ||
            Notification.permission !== 'granted'
        ) {
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

        if (pendingLeave) {
            clearTimeout(pendingLeave);
            pendingLeave = null;
        }

        const listener = (concern: ItConcernBroadcast) => {
            showNotification(concern);
            onConcernRef.current?.();
        };
        const channel = echoInstance.private(CHANNEL).listen(EVENT, listener);

        return () => {
            channel.stopListening(EVENT, listener);
            pendingLeave = setTimeout(() => {
                pendingLeave = null;
                echoInstance.leave(CHANNEL);
            }, LEAVE_DELAY_MS);
        };
    }, [enabled, showNotification]);
}
