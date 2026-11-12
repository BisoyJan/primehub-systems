import { cn } from '@/lib/utils';
import { AlertTriangle, Coffee, Pause, Play, Utensils } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

export interface LiveBreakUser {
    id: number;
    first_name: string;
    last_name: string;
}

export interface LiveBreak {
    id: number;
    user: LiveBreakUser | null;
    campaign: string | null;
    station: string | null;
    type: string;
    status: string;
    duration_seconds: number;
    started_at: string | null;
    expected_end_at: string | null;
    remaining_seconds: number;
    overage_seconds: number;
    is_overbreak_now: boolean;
    total_paused_seconds: number;
    paused_at: string | null;
    paused_for_seconds: number;
    last_pause_reason: string | null;
}

export type Tone = 'active' | 'paused' | 'overbreak';

export interface LiveTiming {
    tone: Tone;
    remaining: number;
    overage: number;
    pausedFor: number;
    progress: number;
}

export interface LiveBreakEntry {
    item: LiveBreak;
    timing: LiveTiming;
}

export type ToneCounts = Record<Tone, number>;

export const TONE_STYLES: Record<
    Tone,
    {
        label: string;
        dot: string;
        ring: string;
        text: string;
        soft: string;
        border: string;
        stroke: string;
        badge: string;
    }
> = {
    active: {
        label: 'On Break',
        dot: 'bg-emerald-500',
        ring: 'ring-emerald-500',
        text: 'text-emerald-600 dark:text-emerald-400',
        soft: 'bg-emerald-500/10',
        border: 'border-emerald-500/30 hover:border-emerald-500/60',
        stroke: 'stroke-emerald-500',
        badge: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    },
    paused: {
        label: 'Paused',
        dot: 'bg-amber-500',
        ring: 'ring-amber-500',
        text: 'text-amber-600 dark:text-amber-400',
        soft: 'bg-amber-500/10',
        border: 'border-amber-500/30 hover:border-amber-500/60',
        stroke: 'stroke-amber-500',
        badge: 'border-amber-500/40 bg-amber-500/10 text-amber-700 dark:text-amber-300',
    },
    overbreak: {
        label: 'Overbreak',
        dot: 'bg-red-500',
        ring: 'ring-red-500',
        text: 'text-red-600 dark:text-red-400',
        soft: 'bg-red-500/10',
        border: 'border-red-500/40 hover:border-red-500/70',
        stroke: 'stroke-red-500',
        badge: 'border-red-500/40 bg-red-500/10 text-red-700 dark:text-red-300',
    },
};

export function formatTime(totalSeconds: number): string {
    const safe = Math.abs(Math.floor(totalSeconds));
    const mins = Math.floor(safe / 60);
    const secs = safe % 60;
    return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
}

export function formatBreakType(type: string): string {
    return type.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export function formatClockTime(value: string | null): string {
    if (!value) {
        return '—';
    }

    const parsed = new Date(value.replace(' ', 'T'));
    if (Number.isNaN(parsed.getTime())) {
        return '—';
    }

    return parsed.toLocaleTimeString([], {
        hour: 'numeric',
        minute: '2-digit',
    });
}

export function fullName(user: LiveBreakUser | null): string {
    return user ? `${user.first_name} ${user.last_name}`.trim() : 'Unknown';
}

/**
 * Derive live countdown values from the server snapshot plus the seconds
 * elapsed on the client since the snapshot was received.
 */
export function computeTiming(item: LiveBreak, elapsed: number): LiveTiming {
    const duration = Math.max(0, item.duration_seconds);

    if (item.status === 'paused') {
        const remaining = Math.max(0, item.remaining_seconds);
        return {
            tone: 'paused',
            remaining,
            overage: Math.max(0, item.overage_seconds),
            pausedFor: Math.max(0, item.paused_for_seconds + elapsed),
            progress:
                duration > 0
                    ? Math.min(1, (duration - remaining) / duration)
                    : 1,
        };
    }

    const net = item.remaining_seconds - item.overage_seconds - elapsed;
    const remaining = Math.max(0, net);
    const overage = Math.max(0, -net);

    return {
        tone: overage > 0 ? 'overbreak' : 'active',
        remaining,
        overage,
        pausedFor: 0,
        progress:
            duration > 0 ? Math.min(1, (duration - remaining) / duration) : 1,
    };
}

/**
 * Ticks once per second and returns each break paired with its live timing,
 * plus per-tone counts. Timing is derived from when the snapshot arrived so
 * it stays accurate between server refreshes.
 */
export function useLiveBreakEntries(breaks: LiveBreak[]): {
    entries: LiveBreakEntry[];
    counts: ToneCounts;
    total: number;
} {
    const [now, setNow] = useState(() => Date.now());

    const snapshot = useMemo(
        () => ({ items: breaks, receivedAt: Date.now() }),
        [breaks],
    );

    useEffect(() => {
        if (snapshot.items.length === 0) return;
        const id = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(id);
    }, [snapshot.items.length]);

    const elapsed = Math.max(0, Math.floor((now - snapshot.receivedAt) / 1000));

    const entries = useMemo(
        () =>
            snapshot.items.map((item) => ({
                item,
                timing: computeTiming(item, elapsed),
            })),
        [snapshot.items, elapsed],
    );

    const counts = useMemo(
        () =>
            entries.reduce(
                (acc, { timing }) => {
                    acc[timing.tone] += 1;
                    return acc;
                },
                { active: 0, paused: 0, overbreak: 0 } as ToneCounts,
            ),
        [entries],
    );

    return { entries, counts, total: entries.length };
}

export function BreakTypeIcon({
    type,
    className,
}: {
    type: string;
    className?: string;
}) {
    return type.includes('lunch') ? (
        <Utensils className={className} />
    ) : (
        <Coffee className={className} />
    );
}

export function ToneIcon({
    tone,
    className,
}: {
    tone: Tone;
    className?: string;
}) {
    if (tone === 'paused') return <Pause className={className} />;
    if (tone === 'overbreak') return <AlertTriangle className={className} />;
    return <Play className={className} />;
}

export function PulseHalo({ tone }: { tone: Tone }) {
    if (tone === 'paused') return null;

    return (
        <span
            aria-hidden
            className={cn(
                'absolute inset-0 rounded-full opacity-60 motion-safe:animate-live-pulse',
                TONE_STYLES[tone].dot,
            )}
        />
    );
}

export function LiveDot({
    live,
    tone = 'active',
    className,
}: {
    live: boolean;
    tone?: Tone;
    className?: string;
}) {
    const color = live ? TONE_STYLES[tone].dot : 'bg-muted-foreground/40';

    return (
        <span className={cn('relative flex h-2.5 w-2.5 shrink-0', className)}>
            {live && (
                <span
                    className={cn(
                        'absolute inline-flex h-full w-full rounded-full opacity-75 motion-safe:animate-ping',
                        color,
                    )}
                />
            )}
            <span
                className={cn(
                    'relative inline-flex h-full w-full rounded-full',
                    color,
                )}
            />
        </span>
    );
}
