import React from 'react';
import { motion } from 'framer-motion';
import { Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { ClipboardCheck, ChevronRight, Clock, FileCheck } from 'lucide-react';
import type { CoachingSummary } from '../types';

export interface CoachingOverviewWidgetProps {
    coachingSummary?: CoachingSummary;
}

const STATUS_STYLES: Record<string, { bg: string; text: string; border: string }> = {
    'Coaching Done': { bg: 'bg-green-500/10', text: 'text-green-700 dark:text-green-400', border: 'border-green-500/30' },
    'Needs Coaching': { bg: 'bg-yellow-500/10', text: 'text-yellow-700 dark:text-yellow-400', border: 'border-yellow-500/30' },
    'Badly Needs Coaching': { bg: 'bg-orange-500/10', text: 'text-orange-700 dark:text-orange-400', border: 'border-orange-500/30' },
    'Please Coach ASAP': { bg: 'bg-red-500/10', text: 'text-red-700 dark:text-red-400', border: 'border-red-500/30' },
    'No Record': { bg: 'bg-gray-500/10', text: 'text-gray-700 dark:text-gray-400', border: 'border-gray-500/30' },
};

const STATUS_SHORT_LABELS: Record<string, string> = {
    'Coaching Done': 'Done',
    'Needs Coaching': 'Needs',
    'Badly Needs Coaching': 'Badly Needs',
    'Please Coach ASAP': 'ASAP',
    'No Record': 'No Record',
};

export const CoachingOverviewWidget: React.FC<CoachingOverviewWidgetProps> = ({
    coachingSummary,
}) => {
    if (!coachingSummary) return null;

    const { status_counts, total_agents, pending_acks, pending_reviews, sessions_this_month } = coachingSummary;

    const urgentCount = (status_counts['Please Coach ASAP'] ?? 0) + (status_counts['Badly Needs Coaching'] ?? 0);

    return (
        <motion.div
            initial={{ opacity: 0, x: 20 }}
            animate={{ opacity: 1, x: 0 }}
            transition={{ duration: 0.3, delay: 0.1 }}
        >
            <Card className={urgentCount > 0 ? 'border-orange-500/30' : ''}>
                <CardHeader className="pb-3">
                    <CardTitle className="flex items-center justify-between text-sm">
                        <span className="flex items-center gap-2">
                            <ClipboardCheck className="h-4 w-4" />
                            Coaching Overview
                        </span>
                        <Badge variant="outline" className="text-[10px] px-1.5">
                            {total_agents} agents
                        </Badge>
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                    {/* Status counts grid */}
                    <div className="grid grid-cols-3 gap-1.5">
                        {Object.entries(status_counts).map(([status, count]) => {
                            const style = STATUS_STYLES[status] ?? STATUS_STYLES['No Record'];
                            return (
                                <div
                                    key={status}
                                    className={`flex flex-col items-center rounded-md border px-1.5 py-1.5 ${style.bg} ${style.border}`}
                                >
                                    <span className={`text-base font-bold leading-none ${style.text}`}>
                                        {count}
                                    </span>
                                    <span className="mt-0.5 text-[9px] text-muted-foreground leading-tight text-center">
                                        {STATUS_SHORT_LABELS[status] ?? status}
                                    </span>
                                </div>
                            );
                        })}
                    </div>

                    {/* Quick stats */}
                    <div className="space-y-1.5">
                        {pending_acks > 0 && (
                            <div className="flex items-center justify-between text-xs">
                                <span className="flex items-center gap-1.5 text-muted-foreground">
                                    <Clock className="h-3 w-3" />
                                    Pending Acknowledgements
                                </span>
                                <Badge variant="outline" className="bg-yellow-500/10 text-yellow-700 dark:text-yellow-400 border-yellow-500/30 text-[10px] px-1.5">
                                    {pending_acks}
                                </Badge>
                            </div>
                        )}
                        {pending_reviews > 0 && (
                            <div className="flex items-center justify-between text-xs">
                                <span className="flex items-center gap-1.5 text-muted-foreground">
                                    <FileCheck className="h-3 w-3" />
                                    Pending Reviews
                                </span>
                                <Badge variant="outline" className="bg-blue-500/10 text-blue-700 dark:text-blue-400 border-blue-500/30 text-[10px] px-1.5">
                                    {pending_reviews}
                                </Badge>
                            </div>
                        )}
                        <div className="flex items-center justify-between text-xs">
                            <span className="text-muted-foreground">Sessions this month</span>
                            <span className="font-medium">{sessions_this_month}</span>
                        </div>
                    </div>

                    {/* Link to coaching dashboard */}
                    <Link
                        href="/coaching"
                        className="flex items-center justify-center gap-1 text-xs text-primary hover:underline pt-1"
                    >
                        View Coaching Dashboard
                        <ChevronRight className="h-3 w-3" />
                    </Link>
                </CardContent>
            </Card>
        </motion.div>
    );
};
