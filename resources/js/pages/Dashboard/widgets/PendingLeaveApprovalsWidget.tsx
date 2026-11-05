import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Link } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { CalendarClock, ChevronRight } from 'lucide-react';
import React from 'react';
import type { PendingLeaveApprovals } from '../types';

export interface PendingLeaveApprovalsWidgetProps {
    pendingLeaveApprovals?: PendingLeaveApprovals;
}

const LEAVE_TYPE_LABELS: Record<string, string> = {
    VL: 'Vacation',
    SL: 'Sick',
    BL: 'Bereavement',
    SPL: 'Special',
    LOA: 'LOA',
    LDV: 'LDV',
    UPTO: 'UPTO',
    ML: 'Maternity',
    IW: 'Incomplete',
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

export const PendingLeaveApprovalsWidget: React.FC<
    PendingLeaveApprovalsWidgetProps
> = ({ pendingLeaveApprovals }) => {
    if (!pendingLeaveApprovals) return null;

    const { count, requests } = pendingLeaveApprovals;

    return (
        <motion.div
            initial={{ opacity: 0, x: 20 }}
            animate={{ opacity: 1, x: 0 }}
            transition={{ duration: 0.3, delay: 0.05 }}
        >
            <Card className={count > 0 ? 'border-orange-500/30' : ''}>
                <CardHeader className="pb-3">
                    <CardTitle className="flex items-center justify-between text-sm">
                        <span className="flex items-center gap-2">
                            <CalendarClock className="h-4 w-4" />
                            Pending Leave Approvals
                        </span>
                        {count > 0 ? (
                            <Badge
                                variant="outline"
                                className="border-orange-500/30 bg-orange-500/10 px-1.5 text-[10px] text-orange-700"
                            >
                                {count}
                            </Badge>
                        ) : (
                            <Badge
                                variant="outline"
                                className="border-green-500/30 bg-green-500/10 px-1.5 text-[10px] text-green-700"
                            >
                                Clear
                            </Badge>
                        )}
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-2">
                    {requests.length > 0 ? (
                        <>
                            <div className="max-h-[220px] scrollbar-thin space-y-2 overflow-y-auto pr-1">
                                {requests.map((leave) => {
                                    const days = daysUntil(leave.start_date);
                                    const urgencyClass =
                                        days <= 1
                                            ? 'text-red-600'
                                            : days <= 3
                                              ? 'text-orange-600'
                                              : 'text-yellow-600';

                                    return (
                                        <Link
                                            key={leave.id}
                                            href={`/form-requests/leave-requests/${leave.id}`}
                                            className="block cursor-pointer space-y-1 rounded-lg border p-2.5 transition-colors hover:bg-muted/50"
                                        >
                                            <div className="flex items-start justify-between gap-2">
                                                <p className="line-clamp-1 text-xs leading-tight font-medium">
                                                    {leave.user_name}
                                                </p>
                                                <Badge
                                                    variant="outline"
                                                    className="shrink-0 px-1 py-0 text-[9px]"
                                                >
                                                    {LEAVE_TYPE_LABELS[
                                                        leave.leave_type
                                                    ] || leave.leave_type}
                                                </Badge>
                                            </div>
                                            <div className="flex items-center justify-between text-[11px] text-muted-foreground">
                                                <span>
                                                    {formatDate(
                                                        leave.start_date,
                                                    )}
                                                    {leave.start_date !==
                                                        leave.end_date &&
                                                        ` – ${formatDate(leave.end_date)}`}
                                                </span>
                                                <span
                                                    className={`font-medium ${urgencyClass}`}
                                                >
                                                    {days === 0
                                                        ? 'Today'
                                                        : days === 1
                                                          ? 'Tomorrow'
                                                          : `${days}d away`}
                                                </span>
                                            </div>
                                            {leave.campaign_department && (
                                                <p className="truncate text-[10px] text-muted-foreground">
                                                    {leave.campaign_department}
                                                </p>
                                            )}
                                        </Link>
                                    );
                                })}
                            </div>
                            <Link
                                href="/form-requests/leave-requests?status=pending"
                                className="flex items-center justify-center gap-1 pt-1 text-xs text-primary hover:underline"
                            >
                                View All Pending
                                <ChevronRight className="h-3 w-3" />
                            </Link>
                        </>
                    ) : (
                        <p className="py-2 text-center text-xs text-muted-foreground">
                            No pending leave approvals.
                        </p>
                    )}
                </CardContent>
            </Card>
        </motion.div>
    );
};
