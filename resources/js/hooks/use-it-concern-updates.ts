import { getEcho } from '@/echo';
import { useEffect, useRef } from 'react';

export type ItConcernUpdateAction =
    'updated' | 'resolved' | 'cancelled' | 'deleted';

export interface ItConcernUpdatedPayload {
    id: number;
    action: ItConcernUpdateAction;
    status: string;
    priority: string;
    updated_by: { id: number; name: string };
    at: string;
}

const EVENT = '.concern.updated';

/**
 * Listens for live changes to a single IT concern on its private
 * `it-concern.{id}` channel (edits, status changes, resolution, deletion).
 * The handler is kept in a ref so callers can pass inline closures.
 */
export function useItConcernUpdates(
    concernId: number | null | undefined,
    onUpdate: (payload: ItConcernUpdatedPayload) => void,
): void {
    const handlerRef = useRef(onUpdate);
    handlerRef.current = onUpdate;

    useEffect(() => {
        if (!concernId) return;

        const echo = getEcho();
        if (!echo) return;

        const channelName = `it-concern.${concernId}`;
        echo.private(channelName).listen(
            EVENT,
            (payload: ItConcernUpdatedPayload) => handlerRef.current(payload),
        );

        return () => {
            echo.leave(channelName);
        };
    }, [concernId]);
}

/**
 * Listens for `concern.updated` on the IT-wide `it-concerns` channel so the
 * concerns list can refresh immediately. That channel is owned by the global
 * notification hook, so this only adds/removes its own listener instead of
 * leaving the channel.
 */
export function useItConcernListUpdates(
    enabled: boolean,
    onUpdate: (payload: ItConcernUpdatedPayload) => void,
): void {
    const handlerRef = useRef(onUpdate);
    handlerRef.current = onUpdate;

    useEffect(() => {
        if (!enabled) return;

        const echo = getEcho();
        if (!echo) return;

        const listener = (payload: ItConcernUpdatedPayload) =>
            handlerRef.current(payload);
        const channel = echo.private('it-concerns').listen(EVENT, listener);

        return () => {
            channel.stopListening(EVENT, listener);
        };
    }, [enabled]);
}
