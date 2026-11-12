import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import { dashboard as breakDashboard } from '@/routes/break-timer';
import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowUpRight,
    Clock,
    Coffee,
    Hourglass,
    Loader2,
    MapPin,
    MessageSquareText,
    Pause,
    Play,
} from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import {
    BreakTypeIcon,
    formatBreakType,
    formatClockTime,
    formatHumanDuration,
    formatTime,
    fullName,
    LiveDot,
    PulseHalo,
    TONE_STYLES,
    type LiveBreak,
    type LiveBreakEntry,
    type LiveTiming,
    type Tone,
    type ToneCounts,
} from './live-breaks-core';

export const TAB_ORDER: Tone[] = ['active', 'overbreak', 'paused'];

/**
 * Row order within each tab, most urgent first: least time left for running
 * and paused breaks, largest overage for overbreaks. Ties fall back to the
 * earliest start so the order stays stable between ticks.
 */
const TAB_SORT: Record<Tone, (a: LiveBreakEntry, b: LiveBreakEntry) => number> =
    {
        active: (a, b) => a.timing.remaining - b.timing.remaining,
        paused: (a, b) => a.timing.remaining - b.timing.remaining,
        overbreak: (a, b) => b.timing.overage - a.timing.overage,
    };

function byStartedAt(a: LiveBreakEntry, b: LiveBreakEntry): number {
    return (a.item.started_at ?? '').localeCompare(b.item.started_at ?? '');
}

export function sortEntriesForTab(
    entries: LiveBreakEntry[],
    tone: Tone,
): LiveBreakEntry[] {
    return entries
        .filter(({ timing }) => timing.tone === tone)
        .sort((a, b) => TAB_SORT[tone](a, b) || byStartedAt(a, b));
}

const TAB_META: Record<
    Tone,
    { label: string; empty: string; icon: ReactNode; activeText: string }
> = {
    active: {
        label: 'On Break',
        empty: 'No one is on a running break right now.',
        icon: <Play className="h-3.5 w-3.5 fill-current" />,
        activeText:
            'data-[state=active]:text-emerald-600 dark:data-[state=active]:text-emerald-400',
    },
    overbreak: {
        label: 'Overbreak',
        empty: 'Nobody is over their break time. Nice.',
        icon: <AlertTriangle className="h-3.5 w-3.5" />,
        activeText:
            'data-[state=active]:text-red-600 dark:data-[state=active]:text-red-400',
    },
    paused: {
        label: 'Paused',
        empty: 'No paused breaks right now.',
        icon: <Pause className="h-3.5 w-3.5 fill-current" />,
        activeText:
            'data-[state=active]:text-amber-600 dark:data-[state=active]:text-amber-400',
    },
};

function ProgressRing({
    progress,
    tone,
    size = 72,
    strokeWidth = 6,
    children,
}: {
    progress: number;
    tone: Tone;
    size?: number;
    strokeWidth?: number;
    children: ReactNode;
}) {
    const radius = (size - strokeWidth) / 2;
    const circumference = 2 * Math.PI * radius;
    const clamped = Math.min(1, Math.max(0, progress));
    const offset = circumference * (1 - clamped);

    return (
        <div
            className="relative shrink-0"
            style={{ width: size, height: size }}
        >
            <svg
                width={size}
                height={size}
                viewBox={`0 0 ${size} ${size}`}
                className="-rotate-90"
                aria-hidden
            >
                <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={radius}
                    fill="none"
                    strokeWidth={strokeWidth}
                    className="stroke-muted"
                />
                <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={radius}
                    fill="none"
                    strokeWidth={strokeWidth}
                    strokeLinecap="round"
                    strokeDasharray={circumference}
                    strokeDashoffset={offset}
                    className={cn(
                        'transition-[stroke-dashoffset] duration-1000 ease-linear',
                        TONE_STYLES[tone].stroke,
                        tone === 'overbreak' && 'motion-safe:animate-pulse',
                    )}
                />
            </svg>
            <div className="absolute inset-0 flex flex-col items-center justify-center">
                {children}
            </div>
        </div>
    );
}

function MetaItem({
    icon,
    children,
}: {
    icon: ReactNode;
    children: ReactNode;
}) {
    return (
        <span className="inline-flex items-center gap-1 whitespace-nowrap [&_svg]:h-3 [&_svg]:w-3">
            {icon}
            {children}
        </span>
    );
}

export function BreakListRow({
    item,
    timing,
    index,
}: {
    item: LiveBreak;
    timing: LiveTiming;
    index: number;
}) {
    const getInitials = useInitials();
    const styles = TONE_STYLES[timing.tone];
    const name = fullName(item.user);
    const isPaused = timing.tone === 'paused';
    const isOver = timing.tone === 'overbreak';

    return (
        <li
            style={{ animationDelay: `${Math.min(index, 10) * 45}ms` }}
            className={cn(
                'flex items-center gap-3 rounded-xl border bg-card p-3 shadow-sm transition-[colors,box-shadow]',
                'motion-safe:animate-in motion-safe:fade-in motion-safe:fill-mode-both motion-safe:slide-in-from-bottom-1',
                styles.border,
            )}
        >
            <span className="relative shrink-0">
                <PulseHalo tone={timing.tone} />
                <Avatar
                    className={cn(
                        'relative h-10 w-10 ring-2 ring-offset-2 ring-offset-card',
                        styles.ring,
                    )}
                >
                    <AvatarFallback className="text-[11px] font-semibold">
                        {getInitials(name)}
                    </AvatarFallback>
                </Avatar>
            </span>

            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <p className="truncate text-sm font-semibold">{name}</p>
                    <Badge
                        variant="secondary"
                        className="h-5 gap-1 px-1.5 text-[10px]"
                    >
                        <BreakTypeIcon type={item.type} className="h-3 w-3" />
                        {formatBreakType(item.type)}
                    </Badge>
                </div>
                <p className="truncate text-[11px] text-muted-foreground">
                    {item.campaign ?? 'No campaign'}
                    {item.station && (
                        <>
                            {' · '}
                            <MapPin className="inline h-3 w-3 align-[-2px]" />{' '}
                            {item.station}
                        </>
                    )}
                </p>
                <p className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[11px] text-muted-foreground">
                    <MetaItem icon={<Play />}>
                        {formatClockTime(item.started_at)}
                    </MetaItem>
                    <MetaItem icon={<Hourglass />}>
                        ends {formatClockTime(item.expected_end_at)}
                    </MetaItem>
                    {timing.totalPaused > 0 && (
                        <MetaItem icon={<Clock />}>
                            <span className="font-medium text-foreground/80">
                                {formatHumanDuration(timing.totalPaused)}
                            </span>{' '}
                            paused total
                        </MetaItem>
                    )}
                </p>
                {isPaused && (
                    <div
                        className={cn(
                            'mt-1.5 flex items-start gap-1.5 rounded-md border border-amber-500/20 px-2 py-1 text-[11px]',
                            styles.soft,
                        )}
                    >
                        <MessageSquareText className="mt-0.5 h-3 w-3 shrink-0 text-amber-600 dark:text-amber-400" />
                        <p className="min-w-0 break-words text-foreground">
                            <span className="font-semibold text-amber-700 dark:text-amber-300">
                                {item.last_pause_reason ?? 'No reason provided'}
                            </span>
                            <span className="text-muted-foreground">
                                {' · paused for '}
                                <span
                                    className={cn(
                                        'font-mono font-semibold tabular-nums',
                                        styles.text,
                                    )}
                                >
                                    {formatTime(timing.pausedFor)}
                                </span>
                            </span>
                        </p>
                    </div>
                )}
            </div>

            <ProgressRing
                progress={timing.progress}
                tone={timing.tone}
                size={56}
                strokeWidth={5}
            >
                <span
                    className={cn(
                        'font-mono text-[11px] font-bold tabular-nums',
                        styles.text,
                    )}
                >
                    {isOver
                        ? `+${formatTime(timing.overage)}`
                        : formatTime(timing.remaining)}
                </span>
                <span className="text-[8px] tracking-wide text-muted-foreground uppercase">
                    {isOver ? 'over' : isPaused ? 'frozen' : 'left'}
                </span>
            </ProgressRing>
        </li>
    );
}

interface LiveBreaksDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    entries: LiveBreakEntry[];
    counts: ToneCounts;
    initialTab?: Tone;
    loading?: boolean;
    showDashboardLink?: boolean;
}

export function LiveBreaksDialog({
    open,
    onOpenChange,
    entries,
    counts,
    initialTab = 'active',
    loading = false,
    showDashboardLink = false,
}: LiveBreaksDialogProps) {
    const total = entries.length;
    const [tab, setTab] = useState<Tone>(initialTab);

    // Re-sync the selected tab to the requested one each time the dialog opens.
    useEffect(() => {
        if (!open) return;
        setTab(initialTab);
    }, [open, initialTab]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[88vh] max-w-[94vw] flex-col gap-0 overflow-hidden p-0 sm:max-w-xl">
                <DialogHeader className="border-b px-4 pt-4 pb-3 text-left sm:px-6 sm:pt-6">
                    <DialogTitle className="flex items-center gap-2 pr-6">
                        <LiveDot live={total > 0} />
                        Live Breaks
                        {loading && (
                            <Loader2 className="h-3.5 w-3.5 animate-spin text-muted-foreground" />
                        )}
                    </DialogTitle>
                    <DialogDescription>
                        {total === 0
                            ? 'No active or paused breaks right now.'
                            : `${total} agent${total === 1 ? '' : 's'} on break · countdown updates live`}
                    </DialogDescription>
                </DialogHeader>

                <Tabs
                    value={tab}
                    onValueChange={(value) => setTab(value as Tone)}
                    className="flex min-h-0 flex-1 flex-col gap-0"
                >
                    <div className="border-b px-4 py-2.5 sm:px-6">
                        <TabsList className="grid h-auto w-full grid-cols-3 p-1">
                            {TAB_ORDER.map((tone) => {
                                const count = counts[tone];
                                const styles = TONE_STYLES[tone];
                                const meta = TAB_META[tone];
                                return (
                                    <TabsTrigger
                                        key={tone}
                                        value={tone}
                                        aria-label={`${meta.label} (${count})`}
                                        className={cn(
                                            'h-auto min-h-11 flex-wrap gap-x-1.5 gap-y-0.5 py-1.5 text-xs sm:h-8 sm:min-h-0 sm:flex-nowrap sm:py-0',
                                            meta.activeText,
                                        )}
                                    >
                                        <span
                                            className={cn(
                                                count > 0 && styles.text,
                                            )}
                                        >
                                            {meta.icon}
                                        </span>
                                        <span className="order-last basis-full text-[10px] leading-none font-medium sm:order-none sm:basis-auto sm:text-xs">
                                            {meta.label}
                                        </span>
                                        <span
                                            key={count}
                                            className={cn(
                                                'inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-[10px] font-bold tabular-nums sm:ml-0.5',
                                                'motion-safe:duration-300 motion-safe:animate-in motion-safe:zoom-in-50',
                                                count > 0
                                                    ? cn(
                                                          styles.soft,
                                                          styles.text,
                                                      )
                                                    : 'bg-muted text-muted-foreground',
                                            )}
                                        >
                                            {count}
                                        </span>
                                    </TabsTrigger>
                                );
                            })}
                        </TabsList>
                    </div>

                    {TAB_ORDER.map((tone) => {
                        const rows = sortEntriesForTab(entries, tone);
                        return (
                            <TabsContent
                                key={tone}
                                value={tone}
                                className="min-h-0 overflow-y-auto px-4 py-4 sm:px-6"
                            >
                                {rows.length === 0 ? (
                                    <div className="flex flex-col items-center gap-2 py-10 text-center text-sm text-muted-foreground motion-safe:animate-in motion-safe:fade-in">
                                        <Coffee className="h-8 w-8 opacity-50" />
                                        {TAB_META[tone].empty}
                                    </div>
                                ) : (
                                    <ul className="flex flex-col gap-2.5">
                                        {rows.map(({ item, timing }, index) => (
                                            <BreakListRow
                                                key={item.id}
                                                item={item}
                                                timing={timing}
                                                index={index}
                                            />
                                        ))}
                                    </ul>
                                )}
                            </TabsContent>
                        );
                    })}
                </Tabs>

                {showDashboardLink && (
                    <div className="flex items-center justify-between gap-3 border-t bg-muted/30 px-4 py-2.5 sm:px-6">
                        <p className="hidden text-[11px] text-muted-foreground sm:block">
                            Need to force-end, restore or reimburse?
                        </p>
                        <Button
                            asChild
                            variant="ghost"
                            size="sm"
                            className="h-9 w-full gap-1 text-xs sm:h-7 sm:w-auto"
                        >
                            <Link
                                href={breakDashboard().url}
                                onClick={() => onOpenChange(false)}
                            >
                                Open Break Dashboard
                                <ArrowUpRight className="h-3.5 w-3.5" />
                            </Link>
                        </Button>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
