import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Link } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { Activity, ChevronRight } from 'lucide-react';
import React from 'react';
import type { ActivityLogEntry } from '../types';

export interface RecentActivityWidgetProps {
    recentActivityLogs?: ActivityLogEntry[];
}

const EVENT_COLORS: Record<string, string> = {
    created: 'bg-green-500/10 text-green-700 border-green-500/30',
    updated: 'bg-blue-500/10 text-blue-700 border-blue-500/30',
    deleted: 'bg-red-500/10 text-red-700 border-red-500/30',
    login: 'bg-purple-500/10 text-purple-700 border-purple-500/30',
    logout: 'bg-gray-500/10 text-gray-700 border-gray-500/30',
};

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

function formatSubjectType(subjectType: string): string {
    const parts = subjectType.split('\\');
    return parts[parts.length - 1] ?? subjectType;
}

export const RecentActivityWidget: React.FC<RecentActivityWidgetProps> = ({
    recentActivityLogs,
}) => {
    if (!recentActivityLogs || recentActivityLogs.length === 0) return null;

    return (
        <motion.div
            initial={{ opacity: 0, x: 20 }}
            animate={{ opacity: 1, x: 0 }}
            transition={{ duration: 0.3, delay: 0.2 }}
        >
            <Card>
                <CardHeader className="pb-3">
                    <CardTitle className="flex items-center gap-2 text-sm">
                        <Activity className="h-4 w-4" />
                        Recent Activity
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="max-h-[320px] space-y-2 overflow-y-auto pr-1">
                        {recentActivityLogs.slice(0, 10).map((log) => (
                            <div
                                key={log.id}
                                className="space-y-1 rounded-lg border p-2.5 transition-colors hover:bg-muted/50"
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <div className="flex min-w-0 items-center gap-1.5">
                                        <Badge
                                            variant="outline"
                                            className={`shrink-0 px-1.5 py-0 text-[10px] ${EVENT_COLORS[log.event.toLowerCase()] ?? 'bg-muted'}`}
                                        >
                                            {log.event}
                                        </Badge>
                                        <span className="truncate text-[10px] text-muted-foreground">
                                            {formatSubjectType(
                                                log.subject_type,
                                            )}
                                        </span>
                                    </div>
                                    <span className="shrink-0 text-[10px] whitespace-nowrap text-muted-foreground">
                                        {formatTimeAgo(log.created_at)}
                                    </span>
                                </div>
                                <p className="line-clamp-2 text-[11px] text-foreground">
                                    {log.description}
                                </p>
                                {log.causer_name && (
                                    <p className="text-[10px] text-muted-foreground">
                                        by {log.causer_name}
                                    </p>
                                )}
                            </div>
                        ))}
                    </div>
                    <Link
                        href="/activity-logs"
                        className="flex items-center justify-center gap-1 pt-3 text-xs text-primary hover:underline"
                    >
                        View All
                        <ChevronRight className="h-3 w-3" />
                    </Link>
                </CardContent>
            </Card>
        </motion.div>
    );
};
