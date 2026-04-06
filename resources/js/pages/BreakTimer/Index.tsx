import { useState, useEffect, useCallback, useRef, useMemo } from 'react';
import { Head, usePage, router } from '@inertiajs/react';
import { motion, AnimatePresence } from 'framer-motion';
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
import { Play, Pause, Square, Coffee, UtensilsCrossed, RotateCcw, Merge, Layers, ChevronDown, ChevronUp, Palette, Maximize, Minimize, Volume2 } from 'lucide-react';
import { ThemeDecor } from './ThemeDecor';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useTimerTheme, useIsDark, btnStyle, getRingColor, getStatusColor, getTimerColor, getPageBackground, getGlassStyle } from './themes';
import { useAlarmSound, ALARM_OPTIONS } from './useAlarmSound';

interface BreakPolicy {
    id: number;
    name: string;
    max_breaks: number;
    break_duration_minutes: number;
    max_lunch: number;
    lunch_duration_minutes: number;
    grace_period_seconds: number;
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

    const { theme, themeId, setTheme, themes } = useTimerTheme();
    const isDark = useIsDark();
    const { alarmId, setAlarmId, volume, setVolume, preview, checkOverage, stopAlarm } = useAlarmSound();

    const [remainingSeconds, setRemainingSeconds] = useState<number>(0);
    const [isPauseDialogOpen, setIsPauseDialogOpen] = useState(false);
    const [isResetDialogOpen, setIsResetDialogOpen] = useState(false);
    const [isStartDialogOpen, setIsStartDialogOpen] = useState(false);
    const [pendingStartType, setPendingStartType] = useState<'break' | 'lunch' | { combined: number } | { combinedBreak: number } | null>(null);
    const [pauseReason, setPauseReason] = useState('');
    const [resetApproval, setResetApproval] = useState('');
    const [station, setStation] = useState<string>('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const [expandedSession, setExpandedSession] = useState<number | null>(null);
    const [isFullscreen, setIsFullscreen] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);

    // Snapshot: when props arrive, record the server remaining_seconds and the client timestamp.
    // This avoids server-vs-client clock skew by only using Date.now() deltas.
    const propsReceivedAtRef = useRef<number>(Date.now());
    const serverRemainingRef = useRef<number>(activeSession?.remaining_seconds ?? 0);

    useEffect(() => {
        propsReceivedAtRef.current = Date.now();
        serverRemainingRef.current = activeSession?.remaining_seconds ?? 0;
    }, [activeSession?.id, activeSession?.remaining_seconds, activeSession?.status]);

    const toggleFullscreen = useCallback(() => {
        if (!document.fullscreenElement) {
            containerRef.current?.requestFullscreen().then(() => setIsFullscreen(true)).catch(() => { });
        } else {
            document.exitFullscreen().then(() => setIsFullscreen(false)).catch(() => { });
        }
    }, []);

    useEffect(() => {
        const onFsChange = () => setIsFullscreen(!!document.fullscreenElement);
        document.addEventListener('fullscreenchange', onFsChange);
        return () => document.removeEventListener('fullscreenchange', onFsChange);
    }, []);

    const maxBreaks = policy?.max_breaks ?? 2;
    const remainingBreaks = Math.max(0, maxBreaks - breaksUsed);

    // Calculate real-time remaining seconds using client-only time delta.
    // Uses server remaining_seconds as baseline, then subtracts client-side elapsed
    // since props were received. This avoids server-vs-client clock skew.
    const calculateRemaining = useCallback(() => {
        if (!activeSession) return 0;
        if (activeSession.status === 'paused') {
            return activeSession.remaining_seconds ?? 0;
        }

        const clientElapsed = Math.floor((Date.now() - propsReceivedAtRef.current) / 1000);
        return serverRemainingRef.current - clientElapsed;
    }, [activeSession]);

    // Timer tick
    useEffect(() => {
        if (activeSession && activeSession.status === 'active') {
            setRemainingSeconds(calculateRemaining());
            timerRef.current = setInterval(() => {
                const r = calculateRemaining();
                setRemainingSeconds(r);
                checkOverage(r, true);
            }, 1000);

            return () => {
                if (timerRef.current) clearInterval(timerRef.current);
            };
        } else if (activeSession && activeSession.status === 'paused') {
            setRemainingSeconds(activeSession.remaining_seconds ?? 0);
            stopAlarm();
        } else {
            setRemainingSeconds(0);
            stopAlarm();
        }

        return () => {
            if (timerRef.current) clearInterval(timerRef.current);
        };
    }, [activeSession, calculateRemaining]);

    // Periodic server sync every 60s to correct client-side drift
    const isSyncingRef = useRef(false);
    useEffect(() => {
        if (!activeSession || activeSession.status !== 'active') return;
        const syncInterval = setInterval(() => {
            if (isSyncingRef.current) return;
            isSyncingRef.current = true;
            router.reload({
                only: ['activeSession', 'todaySessions', 'breaksUsed', 'lunchUsed'],
                onFinish: () => { isSyncingRef.current = false; },
            });
        }, 60000);
        return () => clearInterval(syncInterval);
    }, [activeSession?.id, activeSession?.status]);


    function handleStartBreak() {
        setIsSubmitting(true);
        setIsStartDialogOpen(false);
        setPendingStartType(null);
        router.post(
            startRoute().url,
            { type: 'break', station: station.trim() || null },
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

    function handleStartCombined(breakCount: number) {
        setIsSubmitting(true);
        setIsStartDialogOpen(false);
        setPendingStartType(null);
        router.post(
            startRoute().url,
            { type: 'combined', combined_break_count: breakCount, station: station.trim() || null },
            {
                preserveScroll: true,
                onFinish: () => setIsSubmitting(false),
            },
        );
    }

    function handleStartCombinedBreak(breakCount: number) {
        setIsSubmitting(true);
        setIsStartDialogOpen(false);
        setPendingStartType(null);
        router.post(
            startRoute().url,
            { type: 'combined_break', combined_break_count: breakCount, station: station.trim() || null },
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

    // SVG ring params (viewBox coordinate system – display size set via CSS)
    const ringSize = 500;
    const strokeWidth = 8;
    const radius = (ringSize - strokeWidth) / 2;
    const circumference = 2 * Math.PI * radius;
    const strokeDashoffset = circumference * (1 - progress);

    const overageSeconds = hasSession && remainingSeconds < 0 ? Math.abs(remainingSeconds) : 0;

    // Theme-derived ring + text colors
    const currentRingColor = getRingColor(theme, hasSession, isOverage, overageSeconds, isPaused);
    const currentTrackColor = isDark ? theme.ringTrackDark : theme.ringTrack;
    const statusColor = getStatusColor(theme, isDark, hasSession, isOverage, overageSeconds, isPaused);
    const timerColor = getTimerColor(theme, isDark, isOverage, overageSeconds);

    const glowFilter = useMemo(() => {
        if (!theme.ringGlow || !hasSession) return 'none';
        return `drop-shadow(0 0 10px ${currentRingColor}80) drop-shadow(0 0 24px ${currentRingColor}40)`;
    }, [theme.ringGlow, hasSession, currentRingColor]);

    const statusText = !hasSession
        ? 'Ready'
        : isPaused
            ? 'Paused'
            : isOverage && overageSeconds >= 60
                ? 'Overage!'
                : isOverage
                    ? 'Over Time'
                    : formatBreakType(activeSession.type);

    // Scale down font when time includes hours (HH:MM:SS is too wide for the ring)
    const displayedSeconds = hasSession ? remainingSeconds : 0;
    const hasHours = Math.abs(displayedSeconds) >= 3600;
    const timerFontClass = hasHours ? 'text-[2.625rem] md:text-[6rem]' : 'text-[4.2rem] md:text-[9rem]';

    // ─── Tick Pulse: bump a key every second so framer-motion re-animates ───
    const [tickKey, setTickKey] = useState(0);
    useEffect(() => {
        if (!hasSession || isPaused) return;
        const id = setInterval(() => setTickKey((k) => k + 1), 1000);
        return () => clearInterval(id);
    }, [hasSession, isPaused]);

    // ─── Track previous status for AnimatePresence transitions ───
    const prevStatusRef = useRef(statusText);
    useEffect(() => { prevStatusRef.current = statusText; }, [statusText]);

    // ─── Page background from theme ───
    const pageBg = useMemo(() => getPageBackground(theme, isDark), [theme, isDark]);
    // Always-dark themes force light text even in system light mode
    const forceDarkText = !isDark && theme.alwaysDark;
    const glassStyle = useMemo(() => getGlassStyle(theme, isDark || theme.alwaysDark), [theme, isDark]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <LoadingOverlay isLoading={isLoading} />

            {/* ─── Themed Background ─── */}
            <div
                ref={containerRef}
                className={`relative -mx-4 -mb-4 mt-0 min-h-[calc(100vh-4rem)] overflow-x-clip p-4 ${isFullscreen ? 'overflow-y-auto' : ''} ${forceDarkText ? 'text-white' : ''}`}
                style={pageBg}
            >
                <ThemeDecor theme={theme} isDark={isDark || theme.alwaysDark} />
                <div className="relative mx-auto flex max-w-lg flex-col items-center gap-4 px-4 py-3 md:max-w-2xl md:py-4">

                    {/* ─── Theme Selector, Alarm & Fullscreen ─── */}
                    <div className="flex w-full items-center justify-end gap-2">
                        <button
                            onClick={toggleFullscreen}
                            className="flex h-8 w-8 items-center justify-center rounded-full border border-white/20 backdrop-blur-md transition-opacity hover:opacity-80 dark:border-white/10"
                            style={glassStyle}
                            title={isFullscreen ? 'Exit fullscreen' : 'Fullscreen'}
                        >
                            {isFullscreen ? <Minimize className="h-3.5 w-3.5 opacity-70" /> : <Maximize className="h-3.5 w-3.5 opacity-70" />}
                        </button>
                        <div className="flex items-center gap-1.5 rounded-full border border-white/20 px-3 py-1 backdrop-blur-md dark:border-white/10" style={glassStyle}>
                            <Volume2 className="h-3.5 w-3.5 opacity-60" />
                            <Select value={alarmId} onValueChange={(v) => { setAlarmId(v); if (v !== 'none') preview(v); }}>
                                <SelectTrigger className="h-7 w-28 border-none bg-transparent px-1 text-xs shadow-none">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent align="end">
                                    {ALARM_OPTIONS.map((a) => (
                                        <SelectItem key={a.id} value={a.id}>
                                            <span className="flex items-center gap-2">
                                                <span>{a.icon}</span>
                                                <span>{a.name}</span>
                                            </span>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="flex items-center gap-1.5 rounded-full border border-white/20 px-3 py-1 backdrop-blur-md dark:border-white/10" style={glassStyle}>
                            <Palette className="h-3.5 w-3.5 opacity-60" />
                            <Select value={themeId} onValueChange={setTheme}>
                                <SelectTrigger className="h-7 w-32 border-none bg-transparent px-1 text-xs shadow-none">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent align="end">
                                    {themes.map((t) => (
                                        <SelectItem key={t.id} value={t.id}>
                                            <span className="flex items-center gap-2">
                                                <span>{t.icon}</span>
                                                <span>{t.name}</span>
                                            </span>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    {/* ─── Glass Card ─── */}
                    <div
                        className="flex w-full flex-col items-center gap-4 rounded-3xl border border-white/25 p-6 shadow-2xl backdrop-blur-xl dark:border-white/10 md:gap-6 md:p-10"
                        style={glassStyle}
                    >

                        {/* ─── Circular Timer ─── */}
                        <motion.div
                            className="relative flex items-center justify-center"
                            animate={
                                isOverage
                                    ? { x: [0, -4, 4, -3, 3, -1, 1, 0] }
                                    : isPaused
                                        ? { scale: [1, 1.02, 1] }
                                        : {}
                            }
                            transition={
                                isOverage
                                    ? { duration: 0.5, repeat: Infinity, repeatDelay: 2 }
                                    : isPaused
                                        ? { duration: 2.5, repeat: Infinity, ease: 'easeInOut' }
                                        : {}
                            }
                        >
                            {/* Aurora animated gradient keyframe */}
                            {theme.ringAnimated && (
                                <style>{`@keyframes aurora-hue{0%{filter:hue-rotate(0deg) saturate(1.5)}50%{filter:hue-rotate(180deg) saturate(1.8)}100%{filter:hue-rotate(360deg) saturate(1.5)}}`}</style>
                            )}
                            {/* SVG Ring */}
                            <svg
                                viewBox={`0 0 ${ringSize} ${ringSize}`}
                                className="h-65 w-65 -rotate-90 md:h-125 md:w-125"
                                style={{ filter: glowFilter, transition: 'filter 0.5s ease' }}
                                role="img"
                                aria-label={`Break timer: ${hasSession ? formatTime(remainingSeconds) : 'Ready'}`}
                            >
                                {/* Background track */}
                                <circle
                                    cx={ringSize / 2}
                                    cy={ringSize / 2}
                                    r={radius}
                                    fill="none"
                                    strokeWidth={strokeWidth}
                                    style={{ stroke: currentTrackColor, transition: 'stroke 0.4s ease' }}
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
                                    className="transition-[stroke-dashoffset] duration-1000 ease-linear"
                                    style={{
                                        stroke: currentRingColor,
                                        transition: 'stroke 0.4s ease, stroke-dashoffset 1s linear',
                                        ...(theme.ringAnimated && hasSession && !isPaused && !isOverage
                                            ? { animation: 'aurora-hue 6s linear infinite' }
                                            : {}),
                                    }}
                                />
                            </svg>

                            {/* Center content */}
                            <div className="absolute inset-0 flex flex-col items-center justify-center">
                                {/* ─── Status label with AnimatePresence transition ─── */}
                                <div className="relative h-5 overflow-hidden md:h-9">
                                    <AnimatePresence mode="wait">
                                        <motion.span
                                            key={statusText}
                                            initial={{ y: 12, opacity: 0, filter: 'blur(4px)' }}
                                            animate={{ y: 0, opacity: 1, filter: 'blur(0px)' }}
                                            exit={{ y: -12, opacity: 0, filter: 'blur(4px)' }}
                                            transition={{ duration: 0.3, ease: 'easeOut' }}
                                            className="block text-xs font-semibold uppercase tracking-widest md:text-xl"
                                            style={{ color: statusColor, transition: 'color 0.4s ease' }}
                                        >
                                            {statusText}
                                        </motion.span>
                                    </AnimatePresence>
                                </div>

                                {/* ─── Countdown with tick pulse ─── */}
                                <motion.span
                                    key={isActive ? tickKey : 'static'}
                                    initial={isActive ? { scale: 1.04 } : false}
                                    animate={{ scale: 1 }}
                                    transition={{ duration: 0.35, ease: [0.25, 0.46, 0.45, 0.94] }}
                                    className={`mt-1 font-mono font-medium tabular-nums leading-none tracking-tight ${timerFontClass}`}
                                    style={{ color: timerColor, transition: 'color 0.4s ease' }}
                                >
                                    {hasSession ? formatTime(remainingSeconds) : '00:00'}
                                </motion.span>
                                {hasSession && (
                                    <span className="text-muted-foreground mt-2 text-xs md:mt-3 md:text-lg">
                                        of {Math.floor(totalDuration / 60)} min
                                    </span>
                                )}
                                {activeSession?.status === 'paused' && activeSession?.last_pause_reason && (
                                    <span className="text-muted-foreground mt-1 max-w-45 truncate text-[11px] italic md:max-w-75 md:text-sm">
                                        Paused: {activeSession.last_pause_reason}
                                    </span>
                                )}
                            </div>
                        </motion.div>

                        {/* ─── Theme Quote ─── */}
                        {theme.quote && (
                            <p className="max-w-xs text-center text-xs italic leading-relaxed opacity-50">
                                "{theme.quote}"
                            </p>
                        )}

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
                                            className="h-12 gap-2 rounded-full px-7 text-sm font-semibold text-white shadow-lg transition-[filter] hover:brightness-110"
                                            style={btnStyle(theme.btnBreak)}
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
                                            className="h-12 gap-2 rounded-full px-7 text-sm font-semibold text-white shadow-lg transition-[filter] hover:brightness-110"
                                            style={btnStyle(theme.btnLunch)}
                                        >
                                            <UtensilsCrossed className="h-4 w-4" />
                                            Start Lunch
                                        </Button>
                                    </Can>
                                    {remainingBreaks > 0 && !lunchUsed && (
                                        <Can permission="break_timer.use">
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button
                                                        size="lg"
                                                        disabled={isSubmitting || (stationRequired && !station.trim())}
                                                        className="h-12 gap-2 rounded-full px-7 text-sm font-semibold text-white shadow-lg transition-[filter] hover:brightness-110"
                                                        style={btnStyle(theme.btnCombined)}
                                                    >
                                                        <Merge className="h-4 w-4" />
                                                        Break + Lunch
                                                        <ChevronDown className="ml-1 h-3.5 w-3.5" />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="center">
                                                    {Array.from({ length: maxBreaks }, (_, i) => i + 1).map((n) => (
                                                        <DropdownMenuItem
                                                            key={n}
                                                            onClick={() => {
                                                                setPendingStartType({ combined: n });
                                                                setIsStartDialogOpen(true);
                                                            }}
                                                            disabled={remainingBreaks < n}
                                                        >
                                                            <Coffee className="mr-2 h-4 w-4 text-amber-500" />
                                                            {n} Break{n > 1 ? 's' : ''} + Lunch
                                                        </DropdownMenuItem>
                                                    ))}
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </Can>
                                    )}
                                    {remainingBreaks >= 2 && (
                                        <Can permission="break_timer.use">
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button
                                                        size="lg"
                                                        disabled={isSubmitting || (stationRequired && !station.trim())}
                                                        className="h-12 gap-2 rounded-full px-7 text-sm font-semibold text-white shadow-lg transition-[filter] hover:brightness-110"
                                                        style={btnStyle(theme.btnCombinedBreak)}
                                                    >
                                                        <Layers className="h-4 w-4" />
                                                        Combine Breaks
                                                        <ChevronDown className="ml-1 h-3.5 w-3.5" />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="center">
                                                    {Array.from({ length: maxBreaks - 1 }, (_, i) => i + 2).map((n) => (
                                                        <DropdownMenuItem
                                                            key={n}
                                                            onClick={() => {
                                                                setPendingStartType({ combinedBreak: n });
                                                                setIsStartDialogOpen(true);
                                                            }}
                                                            disabled={remainingBreaks < n}
                                                        >
                                                            <Coffee className="mr-2 h-4 w-4 text-amber-500" />
                                                            {n} Breaks Combined
                                                        </DropdownMenuItem>
                                                    ))}
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </Can>
                                    )}
                                </>
                            )}

                            {isActive && (
                                <Button
                                    size="lg"
                                    onClick={() => setIsPauseDialogOpen(true)}
                                    disabled={isSubmitting}
                                    className="h-12 gap-2 rounded-full px-7 text-sm font-semibold text-white shadow-lg transition-[filter] hover:brightness-110"
                                    style={btnStyle(theme.btnPause)}
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
                                    className="h-12 gap-2 rounded-full px-7 text-sm font-semibold text-white shadow-lg transition-[filter] hover:brightness-110"
                                    style={btnStyle(theme.btnResume)}
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
                                    className="h-12 gap-2 rounded-full px-7 text-sm font-semibold text-white shadow-lg transition-[filter] hover:brightness-110"
                                    style={btnStyle(theme.btnEnd)}
                                >
                                    <Square className="h-4 w-4" />
                                    End
                                </Button>
                            )}

                        </div>

                    </div>{/* end glass card */}

                    {/* ─── Info Pills ─── */}
                    <div className="flex flex-wrap justify-center gap-3">
                        {!hasSession && (
                            <div className="w-56">
                                <Label className="mb-1 block text-[11px] uppercase tracking-wider opacity-60">
                                    Station {stationRequired ? '' : '(optional)'}
                                </Label>
                                <Input
                                    value={station}
                                    onChange={(e) => setStation(e.target.value)}
                                    placeholder="e.g. ST-01, PC-05"
                                    className="h-9 rounded-full border-white/20 bg-white/30 text-xs backdrop-blur-sm dark:border-white/10 dark:bg-white/5"
                                    maxLength={100}
                                    required={stationRequired}
                                />
                            </div>
                        )}

                        <div className="flex gap-2">
                            <div className="inline-flex items-center gap-2 rounded-full border border-white/20 px-4 py-2 text-xs font-medium backdrop-blur-md dark:border-white/10" style={glassStyle}>
                                <Coffee className="text-amber-500 h-3.5 w-3.5" />
                                <span className="opacity-60">Breaks left</span>
                                <span className="font-bold">{remainingBreaks}/{maxBreaks}</span>
                            </div>
                            <div className="inline-flex items-center gap-2 rounded-full border border-white/20 px-4 py-2 text-xs font-medium backdrop-blur-md dark:border-white/10" style={glassStyle}>
                                <UtensilsCrossed className="h-3.5 w-3.5 text-rose-500" />
                                <span className="opacity-60">Lunch</span>
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
                                className="h-9 gap-1.5 rounded-full text-xs opacity-60 hover:opacity-100"
                            >
                                <RotateCcw className="h-3.5 w-3.5" />
                                Reset Shift
                            </Button>
                        </Can>
                    </div>

                    {/* ─── Today's Sessions ─── */}
                    {todaySessions.length > 0 && (
                        <div className="w-full space-y-3">
                            <h3 className="text-xs font-semibold uppercase tracking-wider opacity-50">
                                Today's Sessions
                            </h3>
                            <div className="divide-y rounded-2xl border border-white/20 backdrop-blur-xl dark:border-white/10" style={glassStyle}>
                                {todaySessions.map((session) => (
                                    <div key={session.id}>
                                        <div
                                            className="flex cursor-pointer items-center gap-3 px-4 py-3 transition-colors first:rounded-t-2xl last:rounded-b-2xl hover:bg-white/20 dark:hover:bg-white/5"
                                            onClick={() => setExpandedSession(expandedSession === session.id ? null : session.id)}
                                        >
                                            <div
                                                className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${session.type === 'combined'
                                                    ? 'bg-violet-100 text-violet-600 dark:bg-violet-500/10 dark:text-violet-400'
                                                    : session.type === 'combined_break'
                                                        ? 'bg-sky-100 text-sky-600 dark:bg-sky-500/10 dark:text-sky-400'
                                                        : session.type === 'lunch'
                                                            ? 'bg-rose-100 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400'
                                                            : 'bg-amber-100 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400'
                                                    }`}
                                            >
                                                {session.type === 'combined' ? (
                                                    <Merge className="h-4 w-4" />
                                                ) : session.type === 'combined_break' ? (
                                                    <Layers className="h-4 w-4" />
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
                                                    <span className={`rounded-md px-2 py-0.5 text-[11px] font-semibold ${session.overage_seconds >= 60
                                                        ? 'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400'
                                                        : session.overage_seconds >= 30
                                                            ? 'bg-orange-50 text-orange-600 dark:bg-orange-500/10 dark:text-orange-400'
                                                            : 'bg-yellow-50 text-yellow-600 dark:bg-yellow-500/10 dark:text-yellow-400'
                                                        }`}>
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
                                            <div className="border-t border-white/15 px-4 py-3 dark:border-white/5">
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
                            {pendingStartType === 'break' ? 'Start Break'
                                : pendingStartType === 'lunch' ? 'Start Lunch'
                                    : typeof pendingStartType === 'object' && pendingStartType !== null && 'combinedBreak' in pendingStartType
                                        ? `Start ${pendingStartType.combinedBreak} Combined Breaks`
                                        : typeof pendingStartType === 'object' && pendingStartType !== null && 'combined' in pendingStartType
                                            ? `Start ${pendingStartType.combined} Break${pendingStartType.combined > 1 ? 's' : ''} + Lunch`
                                            : 'Start Break'}
                        </DialogTitle>
                        <DialogDescription>
                            {pendingStartType === 'break'
                                ? `This will start a ${policy?.break_duration_minutes ?? 15} minute break. You have ${remainingBreaks} break(s) remaining.`
                                : pendingStartType === 'lunch'
                                    ? `This will start a ${policy?.lunch_duration_minutes ?? 60} minute lunch break.`
                                    : typeof pendingStartType === 'object' && pendingStartType !== null && 'combinedBreak' in pendingStartType
                                        ? `This will combine ${pendingStartType.combinedBreak} breaks into one session (${(policy?.break_duration_minutes ?? 15) * pendingStartType.combinedBreak} minutes). Uses ${pendingStartType.combinedBreak} break slots. No lunch included.`
                                        : typeof pendingStartType === 'object' && pendingStartType !== null && 'combined' in pendingStartType
                                            ? `This will start a combined ${pendingStartType.combined} break${pendingStartType.combined > 1 ? 's' : ''} + lunch (${(policy?.break_duration_minutes ?? 15) * pendingStartType.combined + (policy?.lunch_duration_minutes ?? 60)} minutes). Uses ${pendingStartType.combined} break slot${pendingStartType.combined > 1 ? 's' : ''} and your lunch.`
                                            : ''}
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
                                else if (typeof pendingStartType === 'object' && pendingStartType !== null && 'combinedBreak' in pendingStartType) handleStartCombinedBreak(pendingStartType.combinedBreak);
                                else if (typeof pendingStartType === 'object' && pendingStartType !== null && 'combined' in pendingStartType) handleStartCombined(pendingStartType.combined);
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
