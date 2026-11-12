import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { usePermission } from '@/hooks/use-permission';
import { cn } from '@/lib/utils';
import { live as liveBreaksRoute } from '@/routes/break-timer';
import { AlertTriangle, Pause, Play } from 'lucide-react';
import {
    useCallback,
    useEffect,
    useRef,
    useState,
    type ReactNode,
} from 'react';
import {
    LiveDot,
    TONE_STYLES,
    useLiveBreakEntries,
    type LiveBreak,
    type Tone,
} from './live-breaks-core';
import { LiveBreaksDialog, TAB_ORDER } from './live-breaks-dialog';

const POLL_INTERVAL_MS = 15000;

const SEGMENTS: Record<Tone, { label: string; icon: ReactNode }> = {
    active: {
        label: 'On break',
        icon: <Play className="h-3 w-3 fill-current" />,
    },
    overbreak: {
        label: 'Overbreak',
        icon: <AlertTriangle className="h-3 w-3" />,
    },
    paused: {
        label: 'Paused',
        icon: <Pause className="h-3 w-3 fill-current" />,
    },
};

function isValidJsonResponse(response: Response): boolean {
    if (response.status === 401 || response.status === 419) {
        return false;
    }
    const contentType = response.headers.get('content-type');
    return contentType !== null && contentType.includes('application/json');
}

/**
 * Compact live-break counters for the global header: on break / overbreak /
 * paused. Each count opens the shared modal on its matching tab. Polls the
 * live feed while the tab is visible. Rendered only for users who can view
 * the break dashboard; the server scopes the feed to the viewer's campaigns
 * (Team Leads) or everyone (admins).
 */
export function HeaderLiveBreaks() {
    const { can } = usePermission();
    const canView = can('break_timer.dashboard');

    const [breaks, setBreaks] = useState<LiveBreak[]>([]);
    const [loaded, setLoaded] = useState(false);
    const [loading, setLoading] = useState(false);
    const [open, setOpen] = useState(false);
    const [initialTab, setInitialTab] = useState<Tone>('active');
    const abortRef = useRef<AbortController | null>(null);

    const fetchLive = useCallback(async () => {
        abortRef.current?.abort();
        abortRef.current = new AbortController();
        setLoading(true);

        try {
            const response = await fetch(liveBreaksRoute.url(), {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: abortRef.current.signal,
            });

            if (!isValidJsonResponse(response)) {
                return;
            }

            const data = (await response.json()) as { breaks: LiveBreak[] };
            setBreaks(Array.isArray(data.breaks) ? data.breaks : []);
            setLoaded(true);
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError')
                return;
            console.error('Failed to fetch live breaks:', error);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        if (!canView) return;

        fetchLive();

        const tick = () => {
            if (document.visibilityState === 'visible') {
                fetchLive();
            }
        };
        const interval = setInterval(tick, POLL_INTERVAL_MS);
        document.addEventListener('visibilitychange', tick);

        return () => {
            clearInterval(interval);
            document.removeEventListener('visibilitychange', tick);
            abortRef.current?.abort();
        };
    }, [canView, fetchLive]);

    const { entries, counts, total } = useLiveBreakEntries(breaks);

    if (!canView) {
        return null;
    }

    const isLive = total > 0;
    const accent: Tone = counts.overbreak > 0 ? 'overbreak' : 'active';

    const summary = isLive
        ? TAB_ORDER.map(
              (tone) => `${counts[tone]} ${SEGMENTS[tone].label.toLowerCase()}`,
          ).join(' · ')
        : loaded
          ? 'Nobody is on break right now'
          : 'Loading live breaks…';

    function openTab(tone: Tone) {
        setInitialTab(tone);
        setOpen(true);
        fetchLive();
    }

    return (
        <>
            <TooltipProvider delayDuration={200}>
                <Tooltip>
                    <TooltipTrigger asChild>
                        <div
                            role="group"
                            aria-label={`Live breaks: ${summary}`}
                            className={cn(
                                'flex h-9 shrink-0 items-stretch overflow-hidden rounded-full border bg-card text-xs shadow-sm',
                                'transition-all duration-200 hover:shadow-md',
                                isLive
                                    ? TONE_STYLES[accent].border
                                    : 'border-border text-muted-foreground',
                            )}
                        >
                            <span className="hidden items-center pr-0.5 pl-2.5 @3xl/header:flex">
                                <LiveDot
                                    live={isLive}
                                    tone={accent}
                                    className="h-2 w-2"
                                />
                            </span>

                            {TAB_ORDER.map((tone, index) => {
                                const count = counts[tone];
                                const styles = TONE_STYLES[tone];
                                const segment = SEGMENTS[tone];
                                const isActiveSegment = count > 0;

                                return (
                                    <button
                                        key={tone}
                                        type="button"
                                        onClick={() => openTab(tone)}
                                        aria-label={`${count} ${segment.label}. Open ${segment.label} list`}
                                        className={cn(
                                            'group relative flex min-w-10 items-center justify-center gap-1 px-2 @3xl/header:min-w-11 @3xl/header:px-2.5',
                                            'transition-colors duration-200 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset',
                                            index > 0 &&
                                                'border-l border-border/60',
                                            isActiveSegment
                                                ? cn(
                                                      'hover:bg-muted/60',
                                                      styles.text,
                                                  )
                                                : 'text-muted-foreground hover:bg-muted/40',
                                        )}
                                    >
                                        <span
                                            className={cn(
                                                'transition-transform duration-200 group-hover:scale-110',
                                                isActiveSegment &&
                                                    tone === 'overbreak' &&
                                                    'motion-safe:animate-pulse',
                                            )}
                                        >
                                            {segment.icon}
                                        </span>
                                        <span
                                            key={count}
                                            className="font-bold tabular-nums motion-safe:duration-300 motion-safe:animate-in motion-safe:zoom-in-50"
                                        >
                                            {count}
                                        </span>
                                        <span className="hidden font-medium @5xl/header:inline">
                                            {segment.label}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    </TooltipTrigger>
                    <TooltipContent side="bottom" className="text-xs">
                        <p className="font-semibold">Live Breaks</p>
                        <p className="opacity-80">{summary}</p>
                        <p className="mt-0.5 opacity-60">
                            Click a count to see who
                        </p>
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>

            <LiveBreaksDialog
                open={open}
                onOpenChange={setOpen}
                entries={entries}
                counts={counts}
                initialTab={initialTab}
                loading={loading}
                showDashboardLink
            />
        </>
    );
}
