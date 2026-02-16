import React from 'react';
import { motion } from 'framer-motion';
import { Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { CalendarClock, ChevronRight } from 'lucide-react';
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

export const PendingLeaveApprovalsWidget: React.FC<PendingLeaveApprovalsWidgetProps> = ({
    pendingLeaveApprovals,
}) => {
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
                            <Badge variant="outline" className="bg-orange-500/10 text-orange-700 border-orange-500/30 text-[10px] px-1.5">
                                {count}
                            </Badge>
                        ) : (
                            <Badge variant="outline" className="bg-green-500/10 text-green-700 border-green-500/30 text-[10px] px-1.5">
                                Clear
                            </Badge>
                        )}
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-2">
                    {requests.length > 0 ? (
                        <>
                            <div className="max-h-[220px] overflow-y-auto space-y-2 pr-1 scrollbar-thin">
                                {requests.slice(0, 3).map((leave) => {
                                    const days = daysUntil(leave.start_date);
                                    const urgencyClass = days <= 1 ? 'text-red-600' : days <= 3 ? 'text-orange-600' : 'text-yellow-600';

                                    return (
                                        <Link
                                            key={leave.id}
                                            href={`/form-requests/leave-requests/${leave.id}`}
                                            className="block rounded-lg border p-2.5 space-y-1 hover:bg-muted/50 transition-colors cursor-pointer"
                                        >
                                            <div className="flex items-start justify-between gap-2">
                                                <p className="text-xs font-medium leading-tight line-clamp-1">
                                                    {leave.user_name}
                                                </p>
                                                <Badge variant="outline" className="text-[9px] px-1 py-0 shrink-0">
                                                    {LEAVE_TYPE_LABELS[leave.leave_type] || leave.leave_type}
                                                </Badge>
                                            </div>
                                            <div className="flex items-center justify-between text-[11px] text-muted-foreground">
                                                <span>
                                                    {formatDate(leave.start_date)}
                                                    {leave.start_date !== leave.end_date && ` – ${formatDate(leave.end_date)}`}
                                                </span>
                                                <span className={`font-medium ${urgencyClass}`}>
                                                    {days === 0 ? 'Today' : days === 1 ? 'Tomorrow' : `${days}d away`}
                                                </span>
                                            </div>
                                            {leave.campaign_department && (
                                                <p className="text-[10px] text-muted-foreground truncate">
                                                    {leave.campaign_department}
                                                </p>
                                            )}
                                        </Link>
                                    );
                                })}
                            </div>
                            <Link
                                href="/form-requests/leave-requests?status=pending"
                                className="flex items-center justify-center gap-1 text-xs text-primary hover:underline pt-1"
                            >
                                View All Pending
                                <ChevronRight className="h-3 w-3" />
                            </Link>
                        </>
                    ) : (
                        <p className="text-xs text-muted-foreground text-center py-2">
                            No pending leave approvals.
                        </p>
                    )}
                </CardContent>
            </Card>
        </motion.div>
    );
};
