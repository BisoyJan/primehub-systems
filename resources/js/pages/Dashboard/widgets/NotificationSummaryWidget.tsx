import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Link } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { Bell, ChevronRight } from 'lucide-react';
import React from 'react';
import type { NotificationSummary } from '../types';

export interface NotificationSummaryWidgetProps {
    notificationSummary?: NotificationSummary;
}

function formatTimeAgo(dateString: string): string {
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

export const NotificationSummaryWidget: React.FC<
    NotificationSummaryWidgetProps
> = ({ notificationSummary }) => {
    if (!notificationSummary) return null;

    const { unread_count, recent } = notificationSummary;

    return (
        <motion.div
            initial={{ opacity: 0, x: 20 }}
            animate={{ opacity: 1, x: 0 }}
            transition={{ duration: 0.3 }}
        >
            <Card>
                <CardHeader className="pb-3">
                    <CardTitle className="flex items-center justify-between text-sm">
                        <span className="flex items-center gap-2">
                            <Bell className="h-4 w-4" />
                            Notifications
                        </span>
                        {unread_count > 0 && (
                            <Badge
                                variant="destructive"
                                className="px-1.5 py-0.5 text-[10px]"
                            >
                                {unread_count}
                            </Badge>
                        )}
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-2">
                    {recent.length > 0 ? (
                        <>
                            <div className="max-h-[220px] scrollbar-thin space-y-2 overflow-y-auto pr-1">
                                {recent.map((notification) => (
                                    <div
                                        key={notification.id}
                                        className="space-y-0.5 rounded-lg border p-2.5 transition-colors hover:bg-muted/50"
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <p className="line-clamp-1 text-xs leading-tight font-medium">
                                                {notification.title}
                                            </p>
                                            <span className="shrink-0 text-[10px] whitespace-nowrap text-muted-foreground">
                                                {formatTimeAgo(
                                                    notification.created_at,
                                                )}
                                            </span>
                                        </div>
                                        <p className="line-clamp-2 text-[11px] text-muted-foreground">
                                            {notification.message}
                                        </p>
                                    </div>
                                ))}
                            </div>
                            <Link
                                href="/notifications"
                                className="flex items-center justify-center gap-1 pt-1 text-xs text-primary hover:underline"
                            >
                                View All
                                <ChevronRight className="h-3 w-3" />
                            </Link>
                        </>
                    ) : (
                        <p className="py-2 text-center text-xs text-muted-foreground">
                            No new notifications.
                        </p>
                    )}
                </CardContent>
            </Card>
        </motion.div>
    );
};
