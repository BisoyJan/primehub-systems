import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Badge } from '@/components/ui/badge';
import { useEffect, useState } from 'react';
import { timeline } from '@/routes/break-timer';

interface TimelineEvent {
    id: number;
    action: string;
    remaining_seconds: number | null;
    overage_seconds: number | null;
    reason: string | null;
    occurred_at: string | null;
}

interface TimelinePayload {
    session: {
        id: number;
        session_id: string;
        status: string;
        ended_by: string | null;
        type: string;
    };
    events: TimelineEvent[];
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    sessionId: number | null;
}

const ACTION_VARIANT: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    start: 'default',
    pause: 'secondary',
    resume: 'default',
    end: 'outline',
    time_up: 'destructive',
    auto_end: 'destructive',
    reset: 'destructive',
    force_end: 'destructive',
    restore: 'default',
};

function fmtSecs(s: number | null): string {
    if (s == null) return '—';
    const m = Math.floor(s / 60);
    const r = s % 60;
    return `${m}m ${r}s`;
}

export function SessionTimelineDialog({ open, onOpenChange, sessionId }: Props) {
    const [data, setData] = useState<TimelinePayload | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!open || !sessionId) {
            setData(null);
            setError(null);
            return;
        }
        let cancelled = false;
        setLoading(true);
        setError(null);
        fetch(timeline(sessionId).url, { headers: { Accept: 'application/json' } })
            .then((r) => {
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                return r.json();
            })
            .then((json: TimelinePayload) => {
                if (!cancelled) setData(json);
            })
            .catch((e: Error) => {
                if (!cancelled) setError(e.message);
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });
        return () => {
            cancelled = true;
        };
    }, [open, sessionId]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-[95vw] sm:max-w-2xl max-h-[85vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Session Timeline</DialogTitle>
                    <DialogDescription>
                        {data ? (
                            <span className="flex flex-wrap items-center gap-2">
                                <span className="font-mono text-xs">{data.session.session_id}</span>
                                <Badge variant="outline">{data.session.type}</Badge>
                                <Badge variant="secondary">{data.session.status}</Badge>
                                {data.session.ended_by && (
                                    <Badge variant="outline">ended by {data.session.ended_by}</Badge>
                                )}
                            </span>
                        ) : (
                            'Loading session details…'
                        )}
                    </DialogDescription>
                </DialogHeader>

                {loading && <p className="text-muted-foreground text-sm">Loading timeline…</p>}
                {error && <p className="text-destructive text-sm">Failed to load: {error}</p>}

                {data && data.events.length === 0 && (
                    <p className="text-muted-foreground text-sm">No events recorded for this session.</p>
                )}

                {data && data.events.length > 0 && (
                    <ol className="space-y-3">
                        {data.events.map((e) => (
                            <li key={e.id} className="border-l-2 border-muted pl-4 relative">
                                <div className="absolute -left-[5px] top-1 size-2 rounded-full bg-primary" />
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge variant={ACTION_VARIANT[e.action] ?? 'outline'}>{e.action}</Badge>
                                    <span className="text-xs text-muted-foreground">{e.occurred_at}</span>
                                </div>
                                <div className="mt-1 text-xs text-muted-foreground">
                                    Remaining: {fmtSecs(e.remaining_seconds)} · Overage: {fmtSecs(e.overage_seconds)}
                                </div>
                                {e.reason && (
                                    <p className="mt-1 text-sm whitespace-pre-wrap break-words">{e.reason}</p>
                                )}
                            </li>
                        ))}
                    </ol>
                )}
            </DialogContent>
        </Dialog>
    );
}
