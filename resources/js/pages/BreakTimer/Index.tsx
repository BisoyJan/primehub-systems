import { useState, useEffect, useCallback, useRef, useMemo } from 'react';
import { Head, usePage, router } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { usePageMeta, useFlashMessage, usePageLoading } from '@/hooks';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { Can } from '@/components/authorization';
import {
    start as startRoute,
    pause as pauseRoute,
    resume as resumeRoute,
    end as endRoute,
    reset as resetRoute,
} from '@/routes/break-timer';
import { Play, Pause, Square, Coffee, UtensilsCrossed, RotateCcw, Merge, ChevronDown, ChevronUp } from 'lucide-react';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';

interface BreakPolicy {
    id: number;
    name: string;
    max_breaks: number;
    break_duration_minutes: number;
    max_lunch: number;
    lunch_duration_minutes: number;
    grace_period_minutes: number;
    allowed_pause_reasons: string[] | null;
}

interface BreakEvent {
    id: number;
    action: string;
    remaining_seconds: number;
    overage_seconds: number;
    reason: string | null;
    occurred_at: string;
}

interface BreakSessionData {
    id: number;
    session_id: string;
    type: string;
    status: string;
    duration_seconds: number;
    started_at: string;
    ended_at: string | null;
    remaining_seconds: number | null;
    overage_seconds: number;
    total_paused_seconds: number;
    last_pause_reason: string | null;
    break_events: BreakEvent[];
}

interface PageProps extends Record<string, unknown> {
    policy: BreakPolicy | null;
    activeSession: BreakSessionData | null;
    todaySessions: BreakSessionData[];
    breaksUsed: number;
    lunchUsed: boolean;
    auth: { user: { role: string } };
}

function formatTime(totalSeconds: number): string {
    const isNegative = totalSeconds < 0;
    const safe = Math.abs(Math.floor(totalSeconds));
    const hours = Math.floor(safe / 3600);
    const mins = Math.floor((safe % 3600) / 60);
    const secs = safe % 60;

    let formatted = '';
    if (hours > 0) {
        formatted = `${String(hours).padStart(2, '0')}:${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
    } else {
        formatted = `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
    }

    return isNegative ? `-${formatted}` : formatted;
}

function formatBreakType(type: string): string {
    return type.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export default function BreakTimerIndex() {
    const { policy, activeSession, todaySessions, breaksUsed, lunchUsed, auth } =
        usePage<PageProps>().props;

    const stationRequired = ['Agent', 'Team Lead'].includes(auth.user.role);

    const { title, breadcrumbs } = usePageMeta({
        title: 'Break Timer',
        breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Break Timer' }],
    });
    useFlashMessage();
    const isLoading = usePageLoading();

    const [remainingSeconds, setRemainingSeconds] = useState<number>(0);
    const [isPauseDialogOpen, setIsPauseDialogOpen] = useState(false);
    const [isResetDialogOpen, setIsResetDialogOpen] = useState(false);
    const [isStartDialogOpen, setIsStartDialogOpen] = useState(false);
    const [pendingStartType, setPendingStartType] = useState<'break' | 'lunch' | 'combined' | null>(null);
    const [pauseReason, setPauseReason] = useState('');
    const [resetApproval, setResetApproval] = useState('');
    const [station, setStation] = useState<string>('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const [expandedSession, setExpandedSession] = useState<number | null>(null);

    const maxBreaks = policy?.max_breaks ?? 2;
    const remainingBreaks = Math.max(0, maxBreaks - breaksUsed);

    // Calculate real-time remaining seconds
    const calculateRemaining = useCallback(() => {
        if (!activeSession) return 0;
        if (activeSession.status === 'paused') {
            return activeSession.remaining_seconds ?? 0;
        }

        const startedAt = new Date(activeSession.started_at).getTime();
        const now = Date.now();
        const elapsed = Math.floor((now - startedAt) / 1000) - activeSession.total_paused_seconds;
        return activeSession.duration_seconds - elapsed;
    }, [activeSession]);

    // Timer tick
    useEffect(() => {
        if (activeSession && activeSession.status === 'active') {
            setRemainingSeconds(calculateRemaining());
            timerRef.current = setInterval(() => {
                setRemainingSeconds(calculateRemaining());
            }, 1000);

            return () => {
                if (timerRef.current) clearInterval(timerRef.current);
            };
        } else if (activeSession && activeSession.status === 'paused') {
            setRemainingSeconds(activeSession.remaining_seconds ?? 0);
        } else {
            setRemainingSeconds(0);
        }

        return () => {
            if (timerRef.current) clearInterval(timerRef.current);
        };
    }, [activeSession, calculateRemaining]);

    // Periodic server sync every 60s to correct client-side drift
    useEffect(() => {
        if (!activeSession || activeSession.status !== 'active') return;
        const syncInterval = setInterval(() => {
            router.reload({ only: ['activeSession', 'todaySessions', 'breaksUsed', 'lunchUsed'] });
        }, 60000);
        return () => clearInterval(syncInterval);
    }, [activeSession?.id, activeSession?.status]);


    function handleStartBreak() {
        setIsSubmitting(true);
        setIsStartDialogOpen(false);
        setPendingStartType(null);
        const nextType = breaksUsed === 0 ? '1st_break' : '2nd_break';
        router.post(
            startRoute().url,
            { type: nextType, station: station.trim() || null },
            {
                preserveScroll: true,
                onFinish: () => setIsSubmitting(false),
            },
        );
    }

    function handleStartLunch() {
        setIsSubmitting(true);
        setIsStartDialogOpen(false);
        setPendingStartType(null);
        router.post(
            startRoute().url,
            { type: 'lunch', station: station.trim() || null },
            {
                preserveScroll: true,
                onFinish: () => setIsSubmitting(false),
            },
        );
    }

    function handleStartCombined() {
        setIsSubmitting(true);
        setIsStartDialogOpen(false);
        setPendingStartType(null);
        router.post(
            startRoute().url,
            { type: 'combined', station: station.trim() || null },
            {
                preserveScroll: true,
                onFinish: () => setIsSubmitting(false),
            },
        );
    }

    function handlePause() {
        if (!activeSession || !pauseReason.trim()) return;
        setIsSubmitting(true);
        router.post(
            pauseRoute(activeSession.id).url,
            { reason: pauseReason.trim() },
            {
                preserveScroll: true,
                onFinish: () => {
                    setIsSubmitting(false);
                    setIsPauseDialogOpen(false);
                    setPauseReason('');
                },
            },
        );
    }

    function handleResume() {
        if (!activeSession) return;
        setIsSubmitting(true);
        router.post(resumeRoute(activeSession.id).url, {}, {
            preserveScroll: true,
            onFinish: () => setIsSubmitting(false),
        });
    }

    function handleEnd() {
        if (!activeSession) return;
        setIsSubmitting(true);
        router.post(endRoute(activeSession.id).url, {}, {
            preserveScroll: true,
            onFinish: () => setIsSubmitting(false),
        });
    }

    function handleReset() {
        if (!resetApproval.trim()) return;
        setIsSubmitting(true);
        router.post(
            resetRoute().url,
            { approval: resetApproval.trim() },
            {
                preserveScroll: true,
                onFinish: () => {
                    setIsSubmitting(false);
                    setIsResetDialogOpen(false);
                    setResetApproval('');
                },
            },
        );
    }

    const isActive = activeSession?.status === 'active';
    const isPaused = activeSession?.status === 'paused';
    const hasSession = !!activeSession;
    const isOverage = remainingSeconds < 0 && isActive;

    // Progress for circular ring (0 to 1)
    const totalDuration = activeSession?.duration_seconds ?? 1;
    const progress = useMemo(() => {
        if (!hasSession) return 1;
        if (isOverage) return 0;
        return Math.max(0, Math.min(1, remainingSeconds / totalDuration));
    }, [hasSession, isOverage, remainingSeconds, totalDuration]);

    // SVG ring params
    const ringSize = 280;
    const strokeWidth = 10;
    const radius = (ringSize - strokeWidth) / 2;
    const circumference = 2 * Math.PI * radius;
    const strokeDashoffset = circumference * (1 - progress);

    const ringColor = !hasSession
        ? 'stroke-zinc-200 dark:stroke-zinc-700'
        : isOverage
            ? 'stroke-red-500'
            : isPaused
                ? 'stroke-amber-400'
                : 'stroke-emerald-500';

    const statusText = !hasSession
        ? 'Ready'
        : isPaused
            ? 'Paused'
            : isOverage
                ? 'Overage!'
                : formatBreakType(activeSession.type);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <LoadingOverlay isLoading={isLoading} />

            <div className="mx-auto flex max-w-xl flex-col items-center gap-8 px-4 py-6 md:py-10">

                {/* ─── Circular Timer ─── */}
                <div className="relative flex items-center justify-center">
                    {/* SVG Ring */}
                    <svg width={ringSize} height={ringSize} className="-rotate-90" role="img" aria-label={`Break timer: ${hasSession ? formatTime(remainingSeconds) : 'Ready'}`}>
                        {/* Background track */}
                        <circle
                            cx={ringSize / 2}
                            cy={ringSize / 2}
                            r={radius}
                            fill="none"
                            strokeWidth={strokeWidth}
                            className="stroke-zinc-100 dark:stroke-zinc-800"
                        />
                        {/* Progress arc */}
                        <circle
                            cx={ringSize / 2}
                            cy={ringSize / 2}
                            r={radius}
                            fill="none"
                            strokeWidth={strokeWidth}
                            strokeLinecap="round"
                            strokeDasharray={circumference}
                            strokeDashoffset={strokeDashoffset}
                            className={`${ringColor} transition-[stroke-dashoffset] duration-1000 ease-linear`}
                        />
                    </svg>

                    {/* Center content */}
                    <div className="absolute inset-0 flex flex-col items-center justify-center">
                        <span
                            className={`text-xs font-semibold uppercase tracking-widest ${isOverage ? 'text-red-500' : isPaused ? 'text-amber-500' : hasSession ? 'text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground'
                                }`}
                        >
                            {statusText}
                        </span>
                        <span
                            className={`mt-1 font-mono text-6xl font-bold tabular-nums leading-none tracking-tight md:text-7xl ${isOverage ? 'text-red-500' : ''
                                }`}
                        >
                            {hasSession ? formatTime(remainingSeconds) : '00:00'}
                        </span>
                        {hasSession && (
                            <span className="text-muted-foreground mt-2 text-xs">
                                of {Math.floor(totalDuration / 60)} min
                            </span>
                        )}
                        {activeSession?.last_pause_reason && (
                            <span className="text-muted-foreground mt-1 max-w-[180px] truncate text-[11px] italic">
                                Paused: {activeSession.last_pause_reason}
                            </span>
                        )}
                    </div>
                </div>

                {/* ─── Action Buttons ─── */}
                <div className="flex flex-wrap items-center justify-center gap-3">
                    {!hasSession && (
                        <>
                            <Can permission="break_timer.use">
                                <Button
                                    size="lg"
                                    onClick={() => {
                                        setPendingStartType('break');
                                        setIsStartDialogOpen(true);
                                    }}
                                    disabled={isSubmitting || remainingBreaks <= 0 || (stationRequired && !station.trim())}
                                    className="h-12 gap-2 rounded-full bg-gradient-to-r from-amber-500 to-orange-500 px-7 text-sm font-semibold text-white shadow-lg shadow-amber-500/25 hover:from-amber-600 hover:to-orange-600"
                                >
                                    <Coffee className="h-4 w-4" />
                                    Start Break
                                </Button>
                            </Can>
                            <Can permission="break_timer.use">
                                <Button
                                    size="lg"
                                    onClick={() => {
                                        setPendingStartType('lunch');
                                        setIsStartDialogOpen(true);
                                    }}
                                    disabled={isSubmitting || lunchUsed || (stationRequired && !station.trim())}
                                    className="h-12 gap-2 rounded-full bg-gradient-to-r from-rose-500 to-pink-500 px-7 text-sm font-semibold text-white shadow-lg shadow-rose-500/25 hover:from-rose-600 hover:to-pink-600"
                                >
                                    <UtensilsCrossed className="h-4 w-4" />
                                    Start Lunch
                                </Button>
                            </Can>
                            {remainingBreaks > 0 && !lunchUsed && (
                                <Can permission="break_timer.use">
                                    <Button
                                        size="lg"
                                        onClick={() => {
                                            setPendingStartType('combined');
                                            setIsStartDialogOpen(true);
                                        }}
                                        disabled={isSubmitting || (stationRequired && !station.trim())}
                                        className="h-12 gap-2 rounded-full bg-gradient-to-r from-violet-500 to-purple-500 px-7 text-sm font-semibold text-white shadow-lg shadow-violet-500/25 hover:from-violet-600 hover:to-purple-600"
                                    >
                                        <Merge className="h-4 w-4" />
                                        Break + Lunch
                                    </Button>
                                </Can>
                            )}
                        </>
                    )}

                    {isActive && (
                        <Button
                            size="lg"
                            onClick={() => setIsPauseDialogOpen(true)}
                            disabled={isSubmitting}
                            className="h-12 gap-2 rounded-full bg-gradient-to-r from-amber-400 to-yellow-500 px-7 text-sm font-semibold text-white shadow-lg shadow-amber-400/25 hover:from-amber-500 hover:to-yellow-600"
                        >
                            <Pause className="h-4 w-4" />
                            Pause
                        </Button>
                    )}

                    {isPaused && (
                        <Button
                            size="lg"
                            onClick={handleResume}
                            disabled={isSubmitting}
                            className="h-12 gap-2 rounded-full bg-gradient-to-r from-indigo-500 to-violet-500 px-7 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 hover:from-indigo-600 hover:to-violet-600"
                        >
                            <Play className="h-4 w-4" />
                            Resume
                        </Button>
                    )}

                    {hasSession && (
                        <Button
                            size="lg"
                            onClick={handleEnd}
                            disabled={isSubmitting}
                            className="h-12 gap-2 rounded-full bg-gradient-to-r from-emerald-500 to-teal-500 px-7 text-sm font-semibold text-white shadow-lg shadow-emerald-500/25 hover:from-emerald-600 hover:to-teal-600"
                        >
                            <Square className="h-4 w-4" />
                            End
                        </Button>
                    )}

                </div>

                {/* ─── Info Pills ─── */}
                <div className="flex flex-wrap justify-center gap-3">
                    {!hasSession && (
                        <div className="w-56">
                            <Label className="text-muted-foreground mb-1 block text-[11px] uppercase tracking-wider">
                                Station {stationRequired ? '' : '(optional)'}
                            </Label>
                            <Input
                                value={station}
                                onChange={(e) => setStation(e.target.value)}
                                placeholder="e.g. ST-01, PC-05"
                                className="h-9 rounded-full text-xs"
                                maxLength={100}
                                required={stationRequired}
                            />
                        </div>
                    )}

                    <div className="flex gap-2">
                        <div className="bg-muted/60 inline-flex items-center gap-2 rounded-full px-4 py-2 text-xs font-medium">
                            <Coffee className="text-amber-500 h-3.5 w-3.5" />
                            <span className="text-muted-foreground">Breaks left</span>
                            <span className="font-bold">{remainingBreaks}/{maxBreaks}</span>
                        </div>
                        <div className="bg-muted/60 inline-flex items-center gap-2 rounded-full px-4 py-2 text-xs font-medium">
                            <UtensilsCrossed className="h-3.5 w-3.5 text-rose-500" />
                            <span className="text-muted-foreground">Lunch</span>
                            <Badge variant={lunchUsed ? 'secondary' : 'default'} className="h-5 text-[10px]">
                                {lunchUsed ? 'Used' : 'Available'}
                            </Badge>
                        </div>
                    </div>

                    <Can permission="break_timer.reset">
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => setIsResetDialogOpen(true)}
                            disabled={isSubmitting}
                            className="text-muted-foreground hover:text-foreground h-9 gap-1.5 rounded-full text-xs"
                        >
                            <RotateCcw className="h-3.5 w-3.5" />
                            Reset Shift
                        </Button>
                    </Can>
                </div>

                {/* ─── Today's Sessions ─── */}
                {todaySessions.length > 0 && (
                    <div className="w-full space-y-3">
                        <h3 className="text-muted-foreground text-xs font-semibold uppercase tracking-wider">
                            Today's Sessions
                        </h3>
                        <div className="divide-y rounded-xl border">
                            {todaySessions.map((session) => (
                                <div key={session.id}>
                                    <div
                                        className="flex cursor-pointer items-center gap-3 px-4 py-3 transition-colors first:rounded-t-xl last:rounded-b-xl hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                                        onClick={() => setExpandedSession(expandedSession === session.id ? null : session.id)}
                                    >
                                        <div
                                            className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${session.type === 'combined'
                                                    ? 'bg-violet-100 text-violet-600 dark:bg-violet-500/10 dark:text-violet-400'
                                                    : session.type === 'lunch'
                                                        ? 'bg-rose-100 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400'
                                                        : 'bg-amber-100 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400'
                                                }`}
                                        >
                                            {session.type === 'combined' ? (
                                                <Merge className="h-4 w-4" />
                                            ) : session.type === 'lunch' ? (
                                                <UtensilsCrossed className="h-4 w-4" />
                                            ) : (
                                                <Coffee className="h-4 w-4" />
                                            )}
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-medium">{formatBreakType(session.type)}</p>
                                            <p className="text-muted-foreground text-xs">
                                                {new Date(session.started_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                                {session.ended_at &&
                                                    ` → ${new Date(session.ended_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`}
                                            </p>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-2">
                                            {session.overage_seconds > 0 && (
                                                <span className="rounded-md bg-red-50 px-2 py-0.5 text-[11px] font-semibold text-red-600 dark:bg-red-500/10 dark:text-red-400">
                                                    +{formatTime(session.overage_seconds)}
                                                </span>
                                            )}
                                            <Badge
                                                variant={
                                                    session.status === 'completed'
                                                        ? 'default'
                                                        : session.status === 'overage'
                                                            ? 'destructive'
                                                            : 'secondary'
                                                }
                                                className="text-[10px]"
                                            >
                                                {session.status}
                                            </Badge>
                                            {session.break_events.length > 0 && (
                                                expandedSession === session.id
                                                    ? <ChevronUp className="text-muted-foreground h-3.5 w-3.5" />
                                                    : <ChevronDown className="text-muted-foreground h-3.5 w-3.5" />
                                            )}
                                        </div>
                                    </div>
                                    {/* Break Event Timeline */}
                                    {expandedSession === session.id && session.break_events.length > 0 && (
                                        <div className="border-t bg-zinc-50/50 px-4 py-3 dark:bg-zinc-800/30">
                                            <p className="text-muted-foreground mb-2 text-[10px] font-semibold uppercase tracking-wider">Timeline</p>
                                            <div className="relative ml-2 space-y-2 border-l border-zinc-200 pl-4 dark:border-zinc-700">
                                                {session.break_events.map((event) => (
                                                    <div key={event.id} className="relative">
                                                        <div className="bg-background absolute -left-[21px] top-1 h-2.5 w-2.5 rounded-full border-2 border-zinc-300 dark:border-zinc-600" />
                                                        <div className="flex items-baseline gap-2">
                                                            <span className="text-xs font-medium capitalize">{event.action}</span>
                                                            <span className="text-muted-foreground text-[10px]">
                                                                {new Date(event.occurred_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}
                                                            </span>
                                                        </div>
                                                        {event.reason && (
                                                            <p className="text-muted-foreground text-[11px] italic">{event.reason}</p>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>

            {/* Pause Reason Dialog */}
            <Dialog open={isPauseDialogOpen} onOpenChange={setIsPauseDialogOpen}>
                <DialogContent className="max-w-[90vw] sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Pause Timer</DialogTitle>
                        <DialogDescription>Enter the reason for pausing.</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4">
                        {policy?.allowed_pause_reasons && policy.allowed_pause_reasons.length > 0 ? (
                            <Select value={pauseReason} onValueChange={setPauseReason}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Select reason..." />
                                </SelectTrigger>
                                <SelectContent>
                                    {policy.allowed_pause_reasons.map((reason) => (
                                        <SelectItem key={reason} value={reason}>
                                            {reason}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        ) : (
                            <Input
                                value={pauseReason}
                                onChange={(e) => setPauseReason(e.target.value)}
                                placeholder="Enter pause reason..."
                            />
                        )}
                        <div className="flex justify-end gap-2">
                            <Button variant="outline" onClick={() => setIsPauseDialogOpen(false)}>
                                Cancel
                            </Button>
                            <Button onClick={handlePause} disabled={!pauseReason.trim() || isSubmitting}>
                                Pause
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>

            {/* Reset Approval Dialog */}
            <Dialog open={isResetDialogOpen} onOpenChange={setIsResetDialogOpen}>
                <DialogContent className="max-w-[90vw] sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Reset Shift</DialogTitle>
                        <DialogDescription>
                            This will clear all break data for today. Enter approval details.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4">
                        <Input
                            value={resetApproval}
                            onChange={(e) => setResetApproval(e.target.value)}
                            placeholder="Enter approval details..."
                        />
                        <div className="flex justify-end gap-2">
                            <Button variant="outline" onClick={() => setIsResetDialogOpen(false)}>
                                Cancel
                            </Button>
                            <Button
                                onClick={handleReset}
                                disabled={!resetApproval.trim() || isSubmitting}
                                variant="destructive"
                            >
                                Reset Shift
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>

            {/* Start Confirmation Dialog */}
            <Dialog open={isStartDialogOpen} onOpenChange={(open) => { setIsStartDialogOpen(open); if (!open) setPendingStartType(null); }}>
                <DialogContent className="max-w-[90vw] sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {pendingStartType === 'break' ? 'Start Break' : pendingStartType === 'lunch' ? 'Start Lunch' : 'Start Break + Lunch'}
                        </DialogTitle>
                        <DialogDescription>
                            {pendingStartType === 'break'
                                ? `This will start a ${policy?.break_duration_minutes ?? 15} minute break. You have ${remainingBreaks} break(s) remaining.`
                                : pendingStartType === 'lunch'
                                    ? `This will start a ${policy?.lunch_duration_minutes ?? 60} minute lunch break.`
                                    : `This will start a combined break + lunch (${(policy?.break_duration_minutes ?? 15) + (policy?.lunch_duration_minutes ?? 60)} minutes).`}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="flex justify-end gap-2">
                        <Button variant="outline" onClick={() => { setIsStartDialogOpen(false); setPendingStartType(null); }}>
                            Cancel
                        </Button>
                        <Button
                            onClick={() => {
                                if (pendingStartType === 'break') handleStartBreak();
                                else if (pendingStartType === 'lunch') handleStartLunch();
                                else if (pendingStartType === 'combined') handleStartCombined();
                            }}
                            disabled={isSubmitting}
                        >
                            Confirm
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
