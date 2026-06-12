import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { RotateCcw, AlertTriangle } from 'lucide-react';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { timeline } from '@/routes/break-timer';
import timelineActions from '@/routes/break-timer/timeline';

interface TimelineEvent {
    id: number;
    action: string;
    remaining_seconds: number | null;
    overage_seconds: number | null;
    reason: string | null;
    occurred_at: string | null;
    can_rewind: boolean;
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

function formatActionLabel(action: string): string {
    return action.replace(/_/g, ' ');
}

export function SessionTimelineDialog({ open, onOpenChange, sessionId }: Props) {
    const [data, setData] = useState<TimelinePayload | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [reloadTick, setReloadTick] = useState(0);

    const [rewindTarget, setRewindTarget] = useState<TimelineEvent | null>(null);
    const [rewindReason, setRewindReason] = useState('');
    const [isSubmittingRewind, setIsSubmittingRewind] = useState(false);

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
    }, [open, sessionId, reloadTick]);

    function openRewind(ev: TimelineEvent) {
        setRewindReason('');
        setRewindTarget(ev);
    }

    function closeRewind() {
        if (isSubmittingRewind) return;
        setRewindTarget(null);
        setRewindReason('');
    }

    function submitRewind() {
        if (!rewindTarget || !sessionId) return;
        const reason = rewindReason.trim();
        if (reason.length < 3) return;

        setIsSubmittingRewind(true);
        router.post(
            timelineActions.rewind({ breakSession: sessionId, breakEvent: rewindTarget.id }).url,
            { reason },
            {
                preserveScroll: true,
                onFinish: () => {
                    setIsSubmittingRewind(false);
                    setRewindTarget(null);
                    setRewindReason('');
                    // Refetch the timeline so the dialog reflects the new state.
                    setReloadTick((t) => t + 1);
                },
            },
        );
    }

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
                                    <Badge variant={ACTION_VARIANT[e.action] ?? 'outline'}>{formatActionLabel(e.action)}</Badge>
                                    <span className="text-xs text-muted-foreground">{e.occurred_at}</span>
                                    {e.can_rewind && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() => openRewind(e)}
                                            className="ml-auto h-7 gap-1 px-2 text-xs"
                                            title="Undo this event and everything after it"
                                        >
                                            <RotateCcw className="h-3 w-3" />
                                            Undo from here
                                        </Button>
                                    )}
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

            <Dialog open={!!rewindTarget} onOpenChange={(o) => { if (!o) closeRewind(); }}>
                <DialogContent className="max-w-[90vw] sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Undo timeline event</DialogTitle>
                        <DialogDescription>
                            Rewind the session to the state it had right before this event. The selected event and every event after it will be removed.
                        </DialogDescription>
                    </DialogHeader>

                    {rewindTarget && (
                        <div className="space-y-3 text-sm">
                            <div className="rounded-md bg-muted p-3 space-y-1">
                                <div className="flex items-center gap-2">
                                    <Badge variant={ACTION_VARIANT[rewindTarget.action] ?? 'outline'}>
                                        {formatActionLabel(rewindTarget.action)}
                                    </Badge>
                                    <span className="text-xs text-muted-foreground">{rewindTarget.occurred_at}</span>
                                </div>
                                {rewindTarget.reason && (
                                    <p className="text-xs text-muted-foreground wrap-break-word">{rewindTarget.reason}</p>
                                )}
                            </div>

                            <div className="flex items-start gap-2 rounded-md border border-orange-300 bg-orange-50 px-3 py-2 text-xs text-orange-700 dark:border-orange-700 dark:bg-orange-950/40 dark:text-orange-400">
                                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                                <span>
                                    This is destructive — the event and every event after it will be deleted from the timeline.
                                    {rewindTarget.action === 'reimburse' && ' Any minutes added by removed reimburse events will be subtracted back.'}
                                </span>
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="rewind-reason">
                                    Reason <span className="text-red-500">*</span>
                                </Label>
                                <Textarea
                                    id="rewind-reason"
                                    value={rewindReason}
                                    onChange={(e) => setRewindReason(e.target.value)}
                                    placeholder="e.g., Reimburses were entered with typo reasons; rolling back to the original end."
                                    maxLength={500}
                                    rows={3}
                                    disabled={isSubmittingRewind}
                                />
                                <p className="text-xs text-muted-foreground">{rewindReason.length}/500</p>
                            </div>
                        </div>
                    )}

                    <DialogFooter className="gap-2">
                        <Button variant="outline" onClick={closeRewind} disabled={isSubmittingRewind}>
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={submitRewind}
                            disabled={isSubmittingRewind || rewindReason.trim().length < 3}
                        >
                            {isSubmittingRewind ? 'Rewinding…' : 'Rewind timeline'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Dialog>
    );
}
