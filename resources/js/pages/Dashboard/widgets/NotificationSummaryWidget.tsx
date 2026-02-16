import React from 'react';
import { motion } from 'framer-motion';
import { Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Bell, ChevronRight } from 'lucide-react';
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

export const NotificationSummaryWidget: React.FC<NotificationSummaryWidgetProps> = ({
    notificationSummary,
}) => {
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
                            <Badge variant="destructive" className="text-[10px] px-1.5 py-0.5">
                                {unread_count}
                            </Badge>
                        )}
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-2">
                    {recent.length > 0 ? (
                        <>
                            <div className="max-h-[220px] overflow-y-auto space-y-2 pr-1 scrollbar-thin">
                                {recent.map((notification) => (
                                    <div
                                        key={notification.id}
                                        className="rounded-lg border p-2.5 space-y-0.5 hover:bg-muted/50 transition-colors"
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <p className="text-xs font-medium leading-tight line-clamp-1">
                                                {notification.title}
                                            </p>
                                            <span className="text-[10px] text-muted-foreground whitespace-nowrap shrink-0">
                                                {formatTimeAgo(notification.created_at)}
                                            </span>
                                        </div>
                                        <p className="text-[11px] text-muted-foreground line-clamp-2">
                                            {notification.message}
                                        </p>
                                    </div>
                                ))}
                            </div>
                            <Link
                                href="/notifications"
                                className="flex items-center justify-center gap-1 text-xs text-primary hover:underline pt-1"
                            >
                                View All
                                <ChevronRight className="h-3 w-3" />
                            </Link>
                        </>
                    ) : (
                        <p className="text-xs text-muted-foreground text-center py-2">
                            No new notifications.
                        </p>
                    )}
                </CardContent>
            </Card>
        </motion.div>
    );
};
