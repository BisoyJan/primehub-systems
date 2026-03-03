import React from 'react';
import { motion } from 'framer-motion';
import { Link } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ChartContainer, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';
import { Pie, PieChart, Cell, Label } from 'recharts';
import {
    ClipboardCheck,
    Clock,
    ExternalLink,
    FileCheck,
    ShieldAlert,
    TrendingUp,
    UserCheck,
    Users,
} from 'lucide-react';
import { StatCard } from '../components/StatCard';
import type { CoachingSummary, CoachingFollowUps } from '../types';

export interface CoachingTabProps {
    coachingSummary?: CoachingSummary;
    coachingFollowUps?: CoachingFollowUps;
    isAgent?: boolean;
}

const STATUS_CONFIG: Record<string, { color: string; fill: string; label: string }> = {
    'Coaching Done': { color: 'text-green-700 dark:text-green-400', fill: 'hsl(142, 71%, 45%)', label: 'Coaching Done' },
    'Needs Coaching': { color: 'text-yellow-700 dark:text-yellow-400', fill: 'hsl(45, 93%, 47%)', label: 'Needs Coaching' },
    'Badly Needs Coaching': { color: 'text-orange-700 dark:text-orange-400', fill: 'hsl(25, 95%, 53%)', label: 'Badly Needs Coaching' },
    'Please Coach ASAP': { color: 'text-red-700 dark:text-red-400', fill: 'hsl(0, 84%, 60%)', label: 'Coach ASAP' },
    'No Record': { color: 'text-gray-700 dark:text-gray-400', fill: 'hsl(220, 10%, 60%)', label: 'No Record' },
};

const STATUS_BADGE_STYLES: Record<string, string> = {
    'Coaching Done': 'bg-green-500/10 text-green-700 dark:text-green-400 border-green-500/30',
    'Needs Coaching': 'bg-yellow-500/10 text-yellow-700 dark:text-yellow-400 border-yellow-500/30',
    'Badly Needs Coaching': 'bg-orange-500/10 text-orange-700 dark:text-orange-400 border-orange-500/30',
    'Please Coach ASAP': 'bg-red-500/10 text-red-700 dark:text-red-400 border-red-500/30',
    'No Record': 'bg-gray-500/10 text-gray-700 dark:text-gray-400 border-gray-500/30',
};

const chartConfig: Record<string, { label: string; color: string }> = {
    'Coaching Done': { label: 'Coaching Done', color: 'hsl(142, 71%, 45%)' },
    'Needs Coaching': { label: 'Needs Coaching', color: 'hsl(45, 93%, 47%)' },
    'Badly Needs Coaching': { label: 'Badly Needs', color: 'hsl(25, 95%, 53%)' },
    'Please Coach ASAP': { label: 'Coach ASAP', color: 'hsl(0, 84%, 60%)' },
    'No Record': { label: 'No Record', color: 'hsl(220, 10%, 60%)' },
};

function getStatVariant(status: string): 'default' | 'success' | 'warning' | 'danger' {
    if (status === 'Coaching Done') return 'success';
    if (status === 'Needs Coaching') return 'warning';
    if (status === 'Badly Needs Coaching' || status === 'Please Coach ASAP') return 'danger';
    return 'default';
}

function getStatIcon(status: string) {
    if (status === 'Coaching Done') return UserCheck;
    if (status === 'Please Coach ASAP') return ShieldAlert;
    return ClipboardCheck;
}

export const CoachingTab: React.FC<CoachingTabProps> = ({
    coachingSummary,
    coachingFollowUps,
    isAgent = false,
}) => {
    if (!coachingSummary) {
        return (
            <motion.div
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                className="flex items-center justify-center py-12"
            >
                <p className="text-muted-foreground">No coaching data available.</p>
            </motion.div>
        );
    }

    const { status_counts, total_agents, pending_acks, pending_reviews, sessions_this_month } = coachingSummary;

    const urgentCount = (status_counts['Please Coach ASAP'] ?? 0) + (status_counts['Badly Needs Coaching'] ?? 0);
    const healthyCount = status_counts['Coaching Done'] ?? 0;
    const healthyPercentage = total_agents > 0 ? Math.round((healthyCount / total_agents) * 100) : 0;

    // Chart data for pie chart
    const pieData = Object.entries(status_counts)
        .filter(([, count]) => count > 0)
        .map(([status, count]) => ({
            name: STATUS_CONFIG[status]?.label ?? status,
            value: count,
            fill: STATUS_CONFIG[status]?.fill ?? 'hsl(220, 10%, 60%)',
        }));

    return (
        <div className="space-y-6">
            {/* Status Stat Cards */}
            <div className="grid gap-4 grid-cols-2 sm:grid-cols-3 lg:grid-cols-5">
                {Object.entries(status_counts).map(([status, count], index) => (
                    <StatCard
                        key={status}
                        title={STATUS_CONFIG[status]?.label ?? status}
                        value={count}
                        icon={getStatIcon(status)}
                        description={
                            total_agents > 0
                                ? `${Math.round((count / total_agents) * 100)}% of ${isAgent ? 'sessions' : 'agents'}`
                                : undefined
                        }
                        onClick={() => { }}
                        variant={getStatVariant(status)}
                        delay={index * 0.05}
                    />
                ))}
            </div>

            {/* Main Content Grid */}
            <div className="grid gap-6 grid-cols-1 lg:grid-cols-2">
                {/* Coaching Status Chart */}
                <motion.div
                    initial={{ opacity: 0, y: 20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.4, delay: 0.25 }}
                >
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <TrendingUp className="h-5 w-5" />
                                {isAgent ? 'My Coaching Status' : 'Coaching Status Distribution'}
                            </CardTitle>
                            <CardDescription>
                                {isAgent
                                    ? 'Your current coaching standing'
                                    : `${total_agents} total agents tracked`
                                }
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {pieData.length > 0 ? (
                                <ChartContainer config={chartConfig} className="mx-auto aspect-square max-h-[280px]">
                                    <PieChart>
                                        <ChartTooltip
                                            cursor={false}
                                            content={<ChartTooltipContent hideLabel />}
                                        />
                                        <Pie
                                            data={pieData}
                                            dataKey="value"
                                            nameKey="name"
                                            innerRadius={60}
                                            strokeWidth={5}
                                        >
                                            {pieData.map((entry, index) => (
                                                <Cell key={`cell-${index}`} fill={entry.fill} />
                                            ))}
                                            <Label
                                                content={({ viewBox }) => {
                                                    if (viewBox && 'cx' in viewBox && 'cy' in viewBox) {
                                                        return (
                                                            <text
                                                                x={viewBox.cx}
                                                                y={viewBox.cy}
                                                                textAnchor="middle"
                                                                dominantBaseline="middle"
                                                            >
                                                                <tspan
                                                                    x={viewBox.cx}
                                                                    y={viewBox.cy}
                                                                    className="fill-foreground text-3xl font-bold"
                                                                >
                                                                    {healthyPercentage}%
                                                                </tspan>
                                                                <tspan
                                                                    x={viewBox.cx}
                                                                    y={(viewBox.cy || 0) + 24}
                                                                    className="fill-muted-foreground text-sm"
                                                                >
                                                                    {isAgent ? 'Status' : 'Healthy'}
                                                                </tspan>
                                                            </text>
                                                        );
                                                    }
                                                }}
                                            />
                                        </Pie>
                                    </PieChart>
                                </ChartContainer>
                            ) : (
                                <div className="flex items-center justify-center py-12 text-muted-foreground">
                                    No coaching sessions recorded yet.
                                </div>
                            )}

                            {/* Legend */}
                            <div className="mt-4 flex flex-wrap justify-center gap-3">
                                {Object.entries(status_counts).map(([status, count]) => {
                                    const cfg = STATUS_CONFIG[status];
                                    if (!cfg) return null;
                                    return (
                                        <div key={status} className="flex items-center gap-1.5 text-xs">
                                            <span
                                                className="h-2.5 w-2.5 rounded-full shrink-0"
                                                style={{ backgroundColor: cfg.fill }}
                                            />
                                            <span className="text-muted-foreground">{cfg.label}</span>
                                            <span className="font-medium">{count}</span>
                                        </div>
                                    );
                                })}
                            </div>
                        </CardContent>
                    </Card>
                </motion.div>

                {/* Quick Stats & Actions */}
                <motion.div
                    initial={{ opacity: 0, y: 20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.4, delay: 0.3 }}
                    className="space-y-4"
                >
                    {/* Summary Card */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <ClipboardCheck className="h-5 w-5" />
                                {isAgent ? 'My Coaching Summary' : 'Coaching Summary'}
                            </CardTitle>
                            <CardDescription>
                                {isAgent ? 'Your coaching activity this month' : 'Overview of coaching activity and pending actions'}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-2 gap-4">
                                <div className="flex flex-col items-center rounded-lg border p-4">
                                    <Users className="h-5 w-5 text-muted-foreground mb-1" />
                                    <span className="text-2xl font-bold">{total_agents}</span>
                                    <span className="text-xs text-muted-foreground">{isAgent ? 'Total Sessions' : 'Total Agents'}</span>
                                </div>
                                <div className="flex flex-col items-center rounded-lg border p-4">
                                    <TrendingUp className="h-5 w-5 text-muted-foreground mb-1" />
                                    <span className="text-2xl font-bold">{sessions_this_month}</span>
                                    <span className="text-xs text-muted-foreground">Sessions This Month</span>
                                </div>
                            </div>

                            {/* Pending Actions */}
                            <div className="space-y-2">
                                <p className="text-sm font-medium text-muted-foreground">Pending Actions</p>
                                <div className="space-y-2">
                                    <div className="flex items-center justify-between rounded-lg border p-3">
                                        <span className="flex items-center gap-2 text-sm">
                                            <Clock className="h-4 w-4 text-yellow-600" />
                                            {isAgent ? 'Pending Acknowledgements' : 'Awaiting Acknowledgement'}
                                        </span>
                                        <Badge variant="outline" className={`${pending_acks > 0 ? 'bg-yellow-500/10 text-yellow-700 dark:text-yellow-400 border-yellow-500/30' : ''}`}>
                                            {pending_acks}
                                        </Badge>
                                    </div>
                                    <div className="flex items-center justify-between rounded-lg border p-3">
                                        <span className="flex items-center gap-2 text-sm">
                                            <FileCheck className="h-4 w-4 text-blue-600" />
                                            Pending Reviews
                                        </span>
                                        <Badge variant="outline" className={`${pending_reviews > 0 ? 'bg-blue-500/10 text-blue-700 dark:text-blue-400 border-blue-500/30' : ''}`}>
                                            {pending_reviews}
                                        </Badge>
                                    </div>
                                </div>
                            </div>

                            {/* Urgency Alert */}
                            {urgentCount > 0 && !isAgent && (
                                <div className="rounded-lg border border-red-500/30 bg-red-500/5 p-3">
                                    <div className="flex items-center gap-2">
                                        <ShieldAlert className="h-4 w-4 text-red-600" />
                                        <span className="text-sm font-medium text-red-700 dark:text-red-400">
                                            {urgentCount} {urgentCount === 1 ? 'agent needs' : 'agents need'} urgent coaching
                                        </span>
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Not Coached This Week - Only for non-agents */}
                    {!isAgent && coachingFollowUps && coachingFollowUps.not_coached_count > 0 && (
                        <Card className="border-amber-500/30">
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center justify-between text-base">
                                    <span className="flex items-center gap-2">
                                        <ShieldAlert className="h-4 w-4 text-amber-600" />
                                        Not Coached This Week
                                    </span>
                                    <Badge variant="outline" className="bg-amber-500/10 text-amber-700 dark:text-amber-400 border-amber-500/30 text-xs">
                                        {coachingFollowUps.not_coached_count} agents
                                    </Badge>
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="max-h-[200px] overflow-y-auto space-y-2 pr-1 scrollbar-thin">
                                    {coachingFollowUps.not_coached_this_week.map((agent) => {
                                        const badgeClass = STATUS_BADGE_STYLES[agent.coaching_status] ?? STATUS_BADGE_STYLES['No Record'];
                                        return (
                                            <Link
                                                key={agent.id}
                                                href={`/coaching/sessions/create?agent_id=${agent.id}`}
                                                className="flex items-center justify-between rounded-lg border p-2.5 hover:bg-muted/50 transition-colors"
                                            >
                                                <div className="min-w-0">
                                                    <p className="text-sm font-medium truncate">{agent.name}</p>
                                                    <p className="text-xs text-muted-foreground">{agent.campaign}</p>
                                                </div>
                                                <Badge variant="outline" className={`${badgeClass} text-[10px] px-1.5 shrink-0`}>
                                                    {agent.coaching_status}
                                                </Badge>
                                            </Link>
                                        );
                                    })}
                                </div>
                                {coachingFollowUps.not_coached_count > coachingFollowUps.not_coached_this_week.length && (
                                    <p className="text-xs text-muted-foreground text-center mt-2">
                                        +{coachingFollowUps.not_coached_count - coachingFollowUps.not_coached_this_week.length} more agents
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    {/* Quick Links */}
                    <div className="flex flex-col sm:flex-row gap-2">
                        <Button variant="outline" asChild className="flex-1">
                            <Link href="/coaching" className="flex items-center gap-2">
                                <ClipboardCheck className="h-4 w-4" />
                                Coaching Dashboard
                                <ExternalLink className="h-3 w-3 ml-auto" />
                            </Link>
                        </Button>
                        <Button variant="outline" asChild className="flex-1">
                            <Link href="/coaching/sessions" className="flex items-center gap-2">
                                <FileCheck className="h-4 w-4" />
                                All Sessions
                                <ExternalLink className="h-3 w-3 ml-auto" />
                            </Link>
                        </Button>
                    </div>
                </motion.div>
            </div>
        </div>
    );
};
