import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useInitials } from '@/hooks/use-initials';
import type {
    ConcernViewer,
    PresenceUser,
} from '@/hooks/use-it-concern-presence';
import { cn } from '@/lib/utils';
import { Eye, Wrench } from 'lucide-react';

const ACTION_META = {
    viewing: {
        label: 'is viewing this ticket',
        ring: 'ring-sky-500',
        badge: 'bg-sky-500',
        Icon: Eye,
    },
    resolving: {
        label: 'is resolving this ticket',
        ring: 'ring-amber-500',
        badge: 'bg-amber-500',
        Icon: Wrench,
    },
} as const;

interface ConcernViewerAvatarsProps {
    viewers: ConcernViewer[];
    /** Avatar diameter class, e.g. `h-6 w-6`. */
    sizeClassName?: string;
    max?: number;
    className?: string;
}

/**
 * Overlapping avatar stack for the IT staff currently viewing / resolving a
 * concern. Renders nothing when the list is empty so it can be dropped inline
 * next to a status badge without reserving space.
 */
export function ConcernViewerAvatars({
    viewers,
    sizeClassName = 'h-6 w-6',
    max = 4,
    className,
}: ConcernViewerAvatarsProps) {
    const getInitials = useInitials();

    if (viewers.length === 0) return null;

    const shown = viewers.slice(0, max);
    const overflow = viewers.length - shown.length;

    return (
        <TooltipProvider delayDuration={150}>
            <div
                className={cn('flex items-center -space-x-1.5', className)}
                aria-label={`${viewers.length} IT staff on this ticket`}
            >
                {shown.map((viewer) => {
                    const meta = ACTION_META[viewer.action];
                    return (
                        <Tooltip key={viewer.id}>
                            <TooltipTrigger asChild>
                                <span className="relative inline-flex">
                                    <Avatar
                                        className={cn(
                                            sizeClassName,
                                            'border-2 border-background ring-2',
                                            meta.ring,
                                        )}
                                    >
                                        {viewer.avatar_url && (
                                            <AvatarImage
                                                src={viewer.avatar_url}
                                                alt={viewer.name}
                                            />
                                        )}
                                        <AvatarFallback className="text-[10px] font-medium">
                                            {getInitials(viewer.name)}
                                        </AvatarFallback>
                                    </Avatar>
                                    <span
                                        className={cn(
                                            'absolute -right-0.5 -bottom-0.5 flex h-3 w-3 items-center justify-center rounded-full border border-background text-white',
                                            meta.badge,
                                        )}
                                    >
                                        <meta.Icon className="h-2 w-2" />
                                    </span>
                                </span>
                            </TooltipTrigger>
                            <TooltipContent side="top">
                                <span className="font-medium">
                                    {viewer.name}
                                </span>{' '}
                                {meta.label}
                            </TooltipContent>
                        </Tooltip>
                    );
                })}
                {overflow > 0 && (
                    <span
                        className={cn(
                            sizeClassName,
                            'flex items-center justify-center rounded-full border-2 border-background bg-muted text-[10px] font-medium',
                        )}
                    >
                        +{overflow}
                    </span>
                )}
            </div>
        </TooltipProvider>
    );
}

interface ConcernViewerBannerProps {
    viewers: ConcernViewer[];
    className?: string;
}

/**
 * Inline notice for the concern detail page: lists who else is on the ticket
 * and highlights anyone already in the resolve dialog so two IT staff do not
 * work the same ticket at once.
 */
export function ConcernViewerBanner({
    viewers,
    className,
}: ConcernViewerBannerProps) {
    if (viewers.length === 0) return null;

    const resolving = viewers.filter((v) => v.action === 'resolving');
    const others = viewers.filter((v) => v.action !== 'resolving');

    const names = (list: ConcernViewer[]) => list.map((v) => v.name).join(', ');

    const message = resolving.length
        ? `${names(resolving)} ${resolving.length === 1 ? 'is' : 'are'} resolving this ticket right now.`
        : `${names(others)} ${others.length === 1 ? 'is' : 'are'} also viewing this ticket.`;

    return (
        <div
            role="status"
            className={cn(
                'flex items-center gap-3 rounded-md border px-3 py-2 text-sm',
                resolving.length
                    ? 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100'
                    : 'border-sky-300 bg-sky-50 text-sky-900 dark:border-sky-700 dark:bg-sky-950/40 dark:text-sky-100',
                className,
            )}
        >
            <ConcernViewerAvatars viewers={viewers} sizeClassName="h-7 w-7" />
            <span>{message}</span>
        </div>
    );
}

interface ActiveItStaffPanelProps {
    users: PresenceUser[];
    selfId: number | null;
    className?: string;
}

/**
 * Header pill listing every IT staff member currently on the IT concerns pages.
 * Mirrors the attendance spreadsheet's active-editors panel.
 */
export function ActiveItStaffPanel({
    users,
    selfId,
    className,
}: ActiveItStaffPanelProps) {
    const getInitials = useInitials();

    if (users.length === 0) {
        return (
            <div
                className={cn(
                    'flex items-center gap-2 rounded-md border bg-muted/40 px-3 py-1.5 text-xs text-muted-foreground',
                    className,
                )}
            >
                <span className="inline-flex h-2 w-2 rounded-full bg-slate-400 opacity-60" />
                Connecting…
            </div>
        );
    }

    const sorted = [...users].sort((a, b) => {
        if (a.id === selfId) return -1;
        if (b.id === selfId) return 1;
        return a.name.localeCompare(b.name);
    });

    return (
        <TooltipProvider delayDuration={150}>
            <div
                className={cn(
                    'flex items-center gap-2 rounded-md border bg-card px-3 py-1.5 shadow-sm',
                    className,
                )}
            >
                <span className="relative flex h-2 w-2">
                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                    <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-500" />
                </span>
                <span className="text-xs font-medium text-foreground">
                    {users.length} IT online
                </span>
                <div className="flex -space-x-2">
                    {sorted.slice(0, 6).map((u) => (
                        <Tooltip key={u.id}>
                            <TooltipTrigger asChild>
                                <span>
                                    <Avatar className="h-6 w-6 border-2 border-background">
                                        {u.avatar_url && (
                                            <AvatarImage
                                                src={u.avatar_url}
                                                alt={u.name}
                                            />
                                        )}
                                        <AvatarFallback className="text-[10px] font-medium">
                                            {getInitials(u.name)}
                                        </AvatarFallback>
                                    </Avatar>
                                </span>
                            </TooltipTrigger>
                            <TooltipContent side="bottom">
                                {u.name}
                                {u.id === selfId ? ' (you)' : ''}
                            </TooltipContent>
                        </Tooltip>
                    ))}
                    {sorted.length > 6 && (
                        <span className="flex h-6 min-w-6 items-center justify-center rounded-full border-2 border-background bg-muted px-1 text-[10px] font-medium">
                            +{sorted.length - 6}
                        </span>
                    )}
                </div>
            </div>
        </TooltipProvider>
    );
}
