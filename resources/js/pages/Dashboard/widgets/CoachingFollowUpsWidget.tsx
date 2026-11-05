import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Link } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { CalendarClock, ChevronRight, UserX } from 'lucide-react';
import React from 'react';
import type { CoachingFollowUps } from '../types';

export interface CoachingFollowUpsWidgetProps {
    coachingFollowUps?: CoachingFollowUps;
}

const STATUS_COLOR_MAP: Record<
    string,
    { bg: string; text: string; border: string }
> = {
    green: {
        bg: 'bg-green-500/10',
        text: 'text-green-700 dark:text-green-400',
        border: 'border-green-500/30',
    },
    yellow: {
        bg: 'bg-yellow-500/10',
        text: 'text-yellow-700 dark:text-yellow-400',
        border: 'border-yellow-500/30',
    },
    orange: {
        bg: 'bg-orange-500/10',
        text: 'text-orange-700 dark:text-orange-400',
        border: 'border-orange-500/30',
    },
    red: {
        bg: 'bg-red-500/10',
        text: 'text-red-700 dark:text-red-400',
        border: 'border-red-500/30',
    },
    gray: {
        bg: 'bg-gray-500/10',
        text: 'text-gray-700 dark:text-gray-400',
        border: 'border-gray-500/30',
    },
};

function formatDate(dateStr: string): string {
    const date = new Date(dateStr + 'T00:00:00');
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

function daysUntil(dateStr: string): number {
    const now = new Date();
    now.setHours(0, 0, 0, 0);
    const target = new Date(dateStr + 'T00:00:00');
    return Math.ceil((target.getTime() - now.getTime()) / 86400000);
}

export const CoachingFollowUpsWidget: React.FC<
    CoachingFollowUpsWidgetProps
> = ({ coachingFollowUps }) => {
    if (!coachingFollowUps) return null;

    const { follow_ups, not_coached_this_week, not_coached_count } =
        coachingFollowUps;

    const hasContent =
        follow_ups.length > 0 || not_coached_this_week.length > 0;

    return (
        <motion.div
            initial={{ opacity: 0, x: 20 }}
            animate={{ opacity: 1, x: 0 }}
            transition={{ duration: 0.3, delay: 0.15 }}
        >
            <Card
                className={not_coached_count > 0 ? 'border-amber-500/30' : ''}
            >
                <CardHeader className="pb-3">
                    <CardTitle className="flex items-center justify-between text-sm">
                        <span className="flex items-center gap-2">
                            <CalendarClock className="h-4 w-4" />
                            Coaching Follow-ups
                        </span>
                        {follow_ups.length > 0 && (
                            <Badge
                                variant="outline"
                                className="border-blue-500/30 bg-blue-500/10 px-1.5 text-[10px] text-blue-700 dark:text-blue-400"
                            >
                                {follow_ups.length} upcoming
                            </Badge>
                        )}
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                    {!hasContent ? (
                        <p className="py-2 text-center text-xs text-muted-foreground">
                            No follow-ups or uncoached agents this week.
                        </p>
                    ) : (
                        <>
                            {/* Upcoming follow-ups */}
                            {follow_ups.length > 0 && (
                                <div className="space-y-1.5">
                                    <p className="text-[11px] font-medium tracking-wider text-muted-foreground uppercase">
                                        Upcoming Follow-ups
                                    </p>
                                    <div className="max-h-[160px] scrollbar-thin space-y-1.5 overflow-y-auto pr-1">
                                        {follow_ups.map((item) => {
                                            const days = daysUntil(
                                                item.follow_up_date,
                                            );
                                            const urgencyClass =
                                                days <= 0
                                                    ? 'text-red-600'
                                                    : days <= 1
                                                      ? 'text-orange-600'
                                                      : 'text-yellow-600';

                                            return (
                                                <Link
                                                    key={item.id}
                                                    href={`/coaching/sessions/${item.id}`}
                                                    className="block cursor-pointer space-y-0.5 rounded-lg border p-2 transition-colors hover:bg-muted/50"
                                                >
                                                    <div className="flex items-start justify-between gap-2">
                                                        <p className="line-clamp-1 text-xs leading-tight font-medium">
                                                            {item.agent_name}
                                                        </p>
                                                        <span
                                                            className={`shrink-0 text-[10px] font-medium ${urgencyClass}`}
                                                        >
                                                            {days <= 0
                                                                ? 'Today'
                                                                : days === 1
                                                                  ? 'Tomorrow'
                                                                  : `${days}d away`}
                                                        </span>
                                                    </div>
                                                    <div className="flex items-center justify-between text-[10px] text-muted-foreground">
                                                        <span className="truncate">
                                                            {item.purpose_label}
                                                        </span>
                                                        <span>
                                                            {formatDate(
                                                                item.follow_up_date,
                                                            )}
                                                        </span>
                                                    </div>
                                                </Link>
                                            );
                                        })}
                                    </div>
                                </div>
                            )}

                            {/* Not coached this week */}
                            {not_coached_this_week.length > 0 && (
                                <div className="space-y-1.5">
                                    <div className="flex items-center justify-between">
                                        <p className="flex items-center gap-1 text-[11px] font-medium tracking-wider text-muted-foreground uppercase">
                                            <UserX className="h-3 w-3" />
                                            Not Coached This Week
                                        </p>
                                        {not_coached_count >
                                            not_coached_this_week.length && (
                                            <span className="text-[10px] text-muted-foreground">
                                                +
                                                {not_coached_count -
                                                    not_coached_this_week.length}{' '}
                                                more
                                            </span>
                                        )}
                                    </div>
                                    <div className="max-h-[160px] scrollbar-thin space-y-1.5 overflow-y-auto pr-1">
                                        {not_coached_this_week.map((agent) => {
                                            const colorStyle =
                                                STATUS_COLOR_MAP[
                                                    agent.status_color
                                                ] ?? STATUS_COLOR_MAP.gray;
                                            return (
                                                <Link
                                                    key={agent.id}
                                                    href={`/coaching/sessions/create?coachee_id=${agent.id}`}
                                                    className="block cursor-pointer space-y-0.5 rounded-lg border p-2 transition-colors hover:bg-muted/50"
                                                >
                                                    <div className="flex items-start justify-between gap-2">
                                                        <p className="line-clamp-1 text-xs leading-tight font-medium">
                                                            {agent.name}
                                                        </p>
                                                        <Badge
                                                            variant="outline"
                                                            className={`${colorStyle.bg} ${colorStyle.text} ${colorStyle.border} shrink-0 px-1 py-0 text-[9px]`}
                                                        >
                                                            {
                                                                agent.coaching_status
                                                            }
                                                        </Badge>
                                                    </div>
                                                    <div className="flex items-center justify-between text-[10px] text-muted-foreground">
                                                        <span className="truncate">
                                                            {agent.campaign}
                                                        </span>
                                                        <span>
                                                            {agent.last_coached_date
                                                                ? `Last: ${formatDate(agent.last_coached_date)}`
                                                                : 'Never coached'}
                                                        </span>
                                                    </div>
                                                </Link>
                                            );
                                        })}
                                    </div>
                                </div>
                            )}

                            {/* View all link */}
                            <Link
                                href="/coaching/sessions"
                                className="flex items-center justify-center gap-1 pt-1 text-xs text-primary hover:underline"
                            >
                                View All Sessions
                                <ChevronRight className="h-3 w-3" />
                            </Link>
                        </>
                    )}
                </CardContent>
            </Card>
        </motion.div>
    );
};
