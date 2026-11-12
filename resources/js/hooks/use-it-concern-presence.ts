import { getEcho } from '@/echo';
import { usePermission } from '@/hooks/use-permission';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

export interface PresenceUser {
    id: number;
    name: string;
    avatar_url: string | null;
}

export type ConcernPresenceAction = 'viewing' | 'editing' | 'resolving';

export interface ConcernViewer extends PresenceUser {
    action: ConcernPresenceAction;
}

/** Map of concern id -> users currently viewing / resolving that concern. */
export type ConcernViewersMap = Record<number, ConcernViewer[]>;

interface ConcernFocusPayload {
    user: PresenceUser;
    /** Random per-tab id so several tabs of one user do not clobber each other. */
    client_id: string;
    concern_id: number | null;
    action: ConcernPresenceAction;
}

type PresenceChannel = ReturnType<
    NonNullable<ReturnType<typeof getEcho>>['join']
>;

/** Internal state: concern id -> client id -> viewer. */
type RawFocusMap = Record<number, Record<string, ConcernViewer>>;

const CHANNEL = 'it-concerns.presence';
const FOCUS_EVENT = 'concern-focus';

export interface ItConcernPresence {
    /** False when the current user cannot resolve concerns (no channel access). */
    enabled: boolean;
    selfId: number | null;
    /** Everyone currently connected to the IT concerns presence channel. */
    activeUsers: PresenceUser[];
    /** Other users (never self) grouped by the concern they are focused on. */
    viewersByConcern: ConcernViewersMap;
    /** Announce which concern the current user is viewing/resolving. */
    setFocus: (
        concernId: number | null,
        action?: ConcernPresenceAction,
    ) => void;
}

const newClientId = (): string =>
    typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : Math.random().toString(36).slice(2);

function withoutClient(map: RawFocusMap, clientId: string): RawFocusMap {
    const next: RawFocusMap = {};
    for (const [key, clients] of Object.entries(map)) {
        const rest = Object.fromEntries(
            Object.entries(clients).filter(([id]) => id !== clientId),
        );
        if (Object.keys(rest).length) next[Number(key)] = rest;
    }
    return next;
}

function withoutUser(map: RawFocusMap, userId: number): RawFocusMap {
    const next: RawFocusMap = {};
    for (const [key, clients] of Object.entries(map)) {
        const rest = Object.fromEntries(
            Object.entries(clients).filter(([, v]) => v.id !== userId),
        );
        if (Object.keys(rest).length) next[Number(key)] = rest;
    }
    return next;
}

const ACTION_RANK: Record<ConcernPresenceAction, number> = {
    viewing: 0,
    editing: 1,
    resolving: 2,
};

/** Collapse per-tab entries to one row per user, keeping the strongest action. */
function collapseViewers(map: RawFocusMap): ConcernViewersMap {
    const out: ConcernViewersMap = {};
    for (const [key, clients] of Object.entries(map)) {
        const byUser = new Map<number, ConcernViewer>();
        for (const viewer of Object.values(clients)) {
            const existing = byUser.get(viewer.id);
            if (
                !existing ||
                ACTION_RANK[viewer.action] > ACTION_RANK[existing.action]
            ) {
                byUser.set(viewer.id, viewer);
            }
        }
        out[Number(key)] = [...byUser.values()].sort((a, b) =>
            a.name.localeCompare(b.name),
        );
    }
    return out;
}

/**
 * Joins the `it-concerns.presence` channel and tracks which IT staff are
 * viewing or resolving which concern via client-side whispers (no DB writes).
 * Each member re-announces its own focus whenever someone joins so newcomers
 * immediately see the current state.
 */
export function useItConcernPresence(): ItConcernPresence {
    const { auth } = usePage<SharedData>().props;
    const { can } = usePermission();
    const enabled = can('it_concerns.resolve');

    const selfId = auth?.user?.id ?? null;
    const self = useMemo<PresenceUser | null>(() => {
        const u = auth?.user as
            | {
                  id?: number;
                  name?: string;
                  first_name?: string;
                  last_name?: string;
                  avatar_url?: string | null;
                  avatar?: string | null;
              }
            | undefined;
        if (!selfId || !u) return null;
        return {
            id: selfId,
            name:
                u.name ??
                ([u.first_name, u.last_name].filter(Boolean).join(' ') ||
                    'You'),
            avatar_url: u.avatar_url ?? u.avatar ?? null,
        };
    }, [auth?.user, selfId]);
    const selfRef = useRef<PresenceUser | null>(self);
    selfRef.current = self;

    const clientIdRef = useRef<string>(newClientId());
    const [activeUsers, setActiveUsers] = useState<PresenceUser[]>([]);
    const [rawFocus, setRawFocus] = useState<RawFocusMap>({});

    const channelRef = useRef<PresenceChannel | null>(null);
    const focusRef = useRef<{
        concernId: number | null;
        action: ConcernPresenceAction;
    }>({ concernId: null, action: 'viewing' });

    const whisper = useCallback(
        (concernId: number | null, action: ConcernPresenceAction) => {
            const channel = channelRef.current;
            const me = selfRef.current;
            if (!channel || !me) return;
            const payload: ConcernFocusPayload = {
                user: me,
                client_id: clientIdRef.current,
                concern_id: concernId,
                action,
            };
            channel.whisper(FOCUS_EVENT, payload);
        },
        [],
    );

    const announceFocus = useCallback(() => {
        whisper(focusRef.current.concernId, focusRef.current.action);
    }, [whisper]);

    const setFocus = useCallback(
        (
            concernId: number | null,
            action: ConcernPresenceAction = 'viewing',
        ) => {
            const prev = focusRef.current;
            if (prev.concernId === concernId && prev.action === action) {
                return;
            }
            focusRef.current = { concernId, action };
            announceFocus();
        },
        [announceFocus],
    );

    useEffect(() => {
        if (!enabled) return;

        const echo = getEcho();
        if (!echo) return;

        const channel = echo
            .join(CHANNEL)
            .here((users: PresenceUser[]) => {
                setActiveUsers(users);
                announceFocus();
            })
            .joining((user: PresenceUser) => {
                setActiveUsers((prev) => [
                    ...prev.filter((u) => u.id !== user.id),
                    user,
                ]);
                // Re-announce so the newcomer learns what we are focused on.
                announceFocus();
            })
            .leaving((user: PresenceUser) => {
                setActiveUsers((prev) => prev.filter((u) => u.id !== user.id));
                setRawFocus((prev) => withoutUser(prev, user.id));
            })
            .listenForWhisper(FOCUS_EVENT, (payload: ConcernFocusPayload) => {
                if (payload.user.id === selfRef.current?.id) return;
                setRawFocus((prev) => {
                    const next = withoutClient(prev, payload.client_id);
                    if (payload.concern_id !== null) {
                        next[payload.concern_id] = {
                            ...(next[payload.concern_id] ?? {}),
                            [payload.client_id]: {
                                ...payload.user,
                                action: payload.action,
                            },
                        };
                    }
                    return next;
                });
            });

        channelRef.current = channel;

        // Best-effort clear when the tab closes; `leaving` only fires once the
        // user's last connection drops, so a second open tab would otherwise
        // leave a stale "viewing" marker behind.
        const clearFocus = () => whisper(null, 'viewing');
        window.addEventListener('pagehide', clearFocus);

        return () => {
            window.removeEventListener('pagehide', clearFocus);
            clearFocus();
            channelRef.current = null;
            echo.leave(CHANNEL);
            setActiveUsers([]);
            setRawFocus({});
        };
    }, [enabled, announceFocus, whisper]);

    const viewersByConcern = useMemo(
        () => collapseViewers(rawFocus),
        [rawFocus],
    );

    return useMemo(
        () => ({ enabled, selfId, activeUsers, viewersByConcern, setFocus }),
        [enabled, selfId, activeUsers, viewersByConcern, setFocus],
    );
}
