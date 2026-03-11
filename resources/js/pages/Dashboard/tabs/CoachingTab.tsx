import React, { useState, useMemo } from 'react';
import { motion } from 'framer-motion';
import { Link } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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
import type { CoachingSummary, CoachingFollowUps, NotCoachedAgent } from '../types';

export interface CoachingTabProps {
    coachingSummary?: CoachingSummary;
    coachingFollowUps?: CoachingFollowUps;
    isAgent?: boolean;
    isTeamLead?: boolean;
    isAdmin?: boolean;
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
    isTeamLead = false,
    isAdmin = false,
}) => {
    // Hooks must be called before any early returns
    const [roleFilter, setRoleFilter] = useState<'all' | 'Team Lead' | 'Agent'>('all');

    const allCoachedThisWeek = useMemo(() => {
        if (!coachingFollowUps) return [];
        const agents: (NotCoachedAgent & { role: string })[] = (coachingFollowUps.coached_this_week ?? []).map(a => ({ ...a, role: 'Agent' }));
        const tls: (NotCoachedAgent & { role: string })[] = (coachingFollowUps.coached_tls_this_week ?? []).map(t => ({ ...t, role: 'Team Lead' }));
        const combined = [...tls, ...agents];
        if (roleFilter === 'all') return combined;
        return combined.filter(item => item.role === roleFilter);
    }, [coachingFollowUps, roleFilter]);

    const allNotCoachedThisWeek = useMemo(() => {
        if (!coachingFollowUps) return [];
        const agents: (NotCoachedAgent & { role: string })[] = (coachingFollowUps.not_coached_this_week ?? []).map(a => ({ ...a, role: 'Agent' }));
        const tls: (NotCoachedAgent & { role: string })[] = (coachingFollowUps.not_coached_tls_this_week ?? []).map(t => ({ ...t, role: 'Team Lead' }));
        const combined = [...tls, ...agents];
        if (roleFilter === 'all') return combined;
        return combined.filter(item => item.role === roleFilter);
    }, [coachingFollowUps, roleFilter]);

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

    // Agent-specific: derive their current status from status_counts
    const agentStatus = isAgent
        ? (coachingSummary.coaching_status ?? Object.entries(status_counts).find(([, count]) => count > 0)?.[0] ?? 'No Record')
        : null;
    const agentTotalSessions = coachingSummary.total_sessions ?? 0;

    const urgentCount = (status_counts['Please Coach ASAP'] ?? 0) + (status_counts['Badly Needs Coaching'] ?? 0);

    // ─── Agent Layout ────────────────────────────────────────────────────────
    if (isAgent && agentStatus) {
        const statusConfig = STATUS_CONFIG[agentStatus];
        const badgeClass = STATUS_BADGE_STYLES[agentStatus] ?? STATUS_BADGE_STYLES['No Record'];

        return (
            <div className="space-y-6">
                {/* Status Banner */}
                <motion.div
                    initial={{ opacity: 0, y: 10 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.3 }}
                >
                    <Card>
                        <CardContent className="flex flex-col items-center gap-4 py-8 sm:flex-row sm:justify-between">
                            <div className="flex flex-col items-center gap-2 sm:flex-row sm:gap-4">
                                <div className="flex h-14 w-14 items-center justify-center rounded-full bg-muted">
                                    {agentStatus === 'Coaching Done' ? (
                                        <UserCheck className="h-7 w-7 text-green-600" />
                                    ) : agentStatus === 'Please Coach ASAP' ? (
                                        <ShieldAlert className="h-7 w-7 text-red-600" />
                                    ) : (
                                        <ClipboardCheck className="h-7 w-7 text-muted-foreground" />
                                    )}
                                </div>
                                <div className="text-center sm:text-left">
                                    <p className="text-sm text-muted-foreground">My Coaching Status</p>
                                    <Badge variant="outline" className={`${badgeClass} mt-1 text-sm px-3 py-1`}>
                                        {statusConfig?.label ?? agentStatus}
                                    </Badge>
                                </div>
                            </div>
                            <div className="flex gap-6 text-center">
                                <div>
                                    <p className="text-2xl font-bold">{agentTotalSessions}</p>
                                    <p className="text-xs text-muted-foreground">Total Sessions</p>
                                </div>
                                <div className="border-l pl-6">
                                    <p className="text-2xl font-bold">{sessions_this_month}</p>
                                    <p className="text-xs text-muted-foreground">This Month</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </motion.div>

                {/* Stat Cards */}
                <div className="grid gap-4 grid-cols-1 sm:grid-cols-3">
                    <StatCard
                        title="Sessions This Month"
                        value={sessions_this_month}
                        icon={TrendingUp}
                        description={agentTotalSessions > 0 ? `${agentTotalSessions} total all time` : undefined}
                        onClick={() => { }}
                        variant="default"
                        delay={0}
                    />
                    <StatCard
                        title="Pending Acks"
                        value={pending_acks}
                        icon={Clock}
                        description={pending_acks > 0 ? 'Action needed' : 'All acknowledged'}
                        onClick={() => { }}
                        variant={pending_acks > 0 ? 'warning' : 'success'}
                        delay={0.05}
                    />
                    <StatCard
                        title="Pending Reviews"
                        value={pending_reviews}
                        icon={FileCheck}
                        description={pending_reviews > 0 ? 'Under review' : 'All reviewed'}
                        onClick={() => { }}
                        variant={pending_reviews > 0 ? 'warning' : 'success'}
                        delay={0.1}
                    />
                </div>

                {/* Follow-ups & Quick Links */}
                <div className="grid gap-6 grid-cols-1 lg:grid-cols-2">
                    {/* Upcoming Follow-ups */}
                    <motion.div
                        initial={{ opacity: 0, y: 20 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.4, delay: 0.2 }}
                    >
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Clock className="h-5 w-5" />
                                    Upcoming Follow-ups
                                </CardTitle>
                                <CardDescription>Your scheduled follow-up sessions this week</CardDescription>
                            </CardHeader>
                            <CardContent>
                                {coachingFollowUps && coachingFollowUps.follow_ups.length > 0 ? (
                                    <div className="space-y-2">
                                        {coachingFollowUps.follow_ups.map((fu) => (
                                            <Link
                                                key={fu.id}
                                                href={`/coaching/sessions/${fu.id}`}
                                                className="flex items-center justify-between rounded-lg border p-3 hover:bg-muted/50 transition-colors"
                                            >
                                                <div className="min-w-0">
                                                    <p className="text-sm font-medium truncate">{fu.purpose_label}</p>
                                                    <p className="text-xs text-muted-foreground">
                                                        with {fu.team_lead_name}
                                                    </p>
                                                </div>
                                                <Badge variant="outline" className="text-xs shrink-0">
                                                    {new Date(fu.follow_up_date + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}
                                                </Badge>
                                            </Link>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground text-center py-6">
                                        No upcoming follow-ups this week.
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                    </motion.div>

                    {/* Quick Actions */}
                    <motion.div
                        initial={{ opacity: 0, y: 20 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.4, delay: 0.25 }}
                        className="space-y-4"
                    >
                        {/* Pending Acks Alert */}
                        {pending_acks > 0 && (
                            <Card className="border-yellow-500/30">
                                <CardContent className="flex items-center gap-3 py-4">
                                    <Clock className="h-5 w-5 text-yellow-600 shrink-0" />
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-medium">
                                            You have {pending_acks} pending acknowledgement{pending_acks > 1 ? 's' : ''}
                                        </p>
                                        <p className="text-xs text-muted-foreground">Please review and acknowledge your coaching sessions</p>
                                    </div>
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href="/coaching/sessions">View</Link>
                                    </Button>
                                </CardContent>
                            </Card>
                        )}

                        {/* Quick Links */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <ExternalLink className="h-5 w-5" />
                                    Quick Links
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                <Button variant="outline" asChild className="w-full justify-start">
                                    <Link href="/coaching/dashboard" className="flex items-center gap-2">
                                        <ClipboardCheck className="h-4 w-4" />
                                        Coaching Dashboard
                                        <ExternalLink className="h-3 w-3 ml-auto" />
                                    </Link>
                                </Button>
                                <Button variant="outline" asChild className="w-full justify-start">
                                    <Link href="/coaching/sessions" className="flex items-center gap-2">
                                        <FileCheck className="h-4 w-4" />
                                        My Sessions
                                        <ExternalLink className="h-3 w-3 ml-auto" />
                                    </Link>
                                </Button>
                            </CardContent>
                        </Card>
                    </motion.div>
                </div>
            </div>
        );
    }

    // ─── Non-Agent Layout (Team Lead / Admin / HR / Super Admin) ─────────────
    const tlStatus = coachingSummary.tl_coaching_status ?? null;
    const tlStatusCounts = coachingSummary.tl_status_counts ?? null;
    const tlTotal = coachingSummary.tl_total ?? 0;
    const tlSessionsThisMonth = coachingSummary.tl_sessions_this_month ?? 0;
    const tlPendingAcks = coachingSummary.tl_pending_acks ?? 0;
    const tlPendingReviews = coachingSummary.tl_pending_reviews ?? 0;
    const tlUrgentCount = tlStatusCounts
        ? (tlStatusCounts['Please Coach ASAP'] ?? 0) + (tlStatusCounts['Badly Needs Coaching'] ?? 0)
        : 0;

    return (
        <div className="space-y-6">
            {/* TL Personal Coaching Status Banner (Team Lead view) */}
            {isTeamLead && tlStatus && (
                <motion.div
                    initial={{ opacity: 0, y: 10 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.3 }}
                >
                    <Card>
                        <CardContent className="flex flex-col items-center gap-4 py-6 sm:flex-row sm:justify-between">
                            <div className="flex flex-col items-center gap-2 sm:flex-row sm:gap-4">
                                <div className="flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                                    {tlStatus === 'Coaching Done' ? (
                                        <UserCheck className="h-6 w-6 text-green-600" />
                                    ) : tlStatus === 'Please Coach ASAP' ? (
                                        <ShieldAlert className="h-6 w-6 text-red-600" />
                                    ) : (
                                        <ClipboardCheck className="h-6 w-6 text-muted-foreground" />
                                    )}
                                </div>
                                <div className="text-center sm:text-left">
                                    <p className="text-sm text-muted-foreground">My Coaching Status (as Coachee)</p>
                                    <Badge variant="outline" className={`${STATUS_BADGE_STYLES[tlStatus] ?? STATUS_BADGE_STYLES['No Record']} mt-1 text-sm px-3 py-1`}>
                                        {STATUS_CONFIG[tlStatus]?.label ?? tlStatus}
                                    </Badge>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </motion.div>
            )}

            {/* Admin: TL Coaching Overview */}
            {isAdmin && tlStatusCounts && tlTotal > 0 && (
                <motion.div
                    initial={{ opacity: 0, y: 10 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.3, delay: 0.05 }}
                >
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Users className="h-5 w-5" />
                                Team Lead Coaching Overview
                            </CardTitle>
                            <CardDescription>
                                Coaching status for {tlTotal} team lead{tlTotal !== 1 ? 's' : ''}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-3 grid-cols-2 sm:grid-cols-3 lg:grid-cols-5">
                                {Object.entries(tlStatusCounts).map(([status, count]) => {
                                    const badgeClass = STATUS_BADGE_STYLES[status] ?? STATUS_BADGE_STYLES['No Record'];
                                    return (
                                        <div key={status} className="flex flex-col items-center rounded-lg border p-3">
                                            <span className="text-xl font-bold">{count}</span>
                                            <Badge variant="outline" className={`${badgeClass} text-[10px] px-1.5 mt-1`}>
                                                {STATUS_CONFIG[status]?.label ?? status}
                                            </Badge>
                                        </div>
                                    );
                                })}
                            </div>
                        </CardContent>
                    </Card>
                </motion.div>
            )}

            {/* Agent Status Stat Cards */}
            <div className="grid gap-4 grid-cols-2 sm:grid-cols-3 lg:grid-cols-5">
                {Object.entries(status_counts).map(([status, count], index) => (
                    <StatCard
                        key={status}
                        title={STATUS_CONFIG[status]?.label ?? status}
                        value={count}
                        icon={getStatIcon(status)}
                        description={
                            total_agents > 0
                                ? `${Math.round((count / total_agents) * 100)}% of agents`
                                : undefined
                        }
                        onClick={() => { }}
                        variant={getStatVariant(status)}
                        delay={index * 0.05}
                    />
                ))}
            </div>

            {/* Role Filter (Admin only) */}
            {isAdmin && (
                <motion.div
                    initial={{ opacity: 0, y: 10 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.3, delay: 0.15 }}
                    className="flex items-center gap-3"
                >
                    <span className="text-sm font-medium text-muted-foreground">Show:</span>
                    <Select value={roleFilter} onValueChange={(v) => setRoleFilter(v as 'all' | 'Team Lead' | 'Agent')}>
                        <SelectTrigger className="w-[180px]">
                            <SelectValue placeholder="All" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All</SelectItem>
                            <SelectItem value="Team Lead">Team Leads</SelectItem>
                            <SelectItem value="Agent">Agents</SelectItem>
                        </SelectContent>
                    </Select>
                </motion.div>
            )}

            {/* Main Content Grid */}
            <div className="grid gap-6 grid-cols-1 lg:grid-cols-2">
                {/* Not Coached This Week */}
                <motion.div
                    initial={{ opacity: 0, y: 20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.4, delay: 0.25 }}
                >
                    <Card className="h-full">
                        <CardHeader>
                            <CardTitle className="flex items-center justify-between">
                                <span className="flex items-center gap-2">
                                    <ShieldAlert className="h-5 w-5 text-amber-600" />
                                    Not Coached This Week
                                </span>
                                {allNotCoachedThisWeek.length > 0 && (
                                    <Badge variant="outline" className="bg-amber-500/10 text-amber-700 dark:text-amber-400 border-amber-500/30 text-xs">
                                        {allNotCoachedThisWeek.length}
                                    </Badge>
                                )}
                            </CardTitle>
                            <CardDescription>
                                {roleFilter === 'all' ? 'Team leads & agents' : roleFilter === 'Team Lead' ? 'Team leads' : 'Agents'} who haven't been coached this week
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {allNotCoachedThisWeek.length > 0 ? (
                                <div className="max-h-[320px] overflow-y-auto space-y-2 pr-1 scrollbar-thin">
                                    {allNotCoachedThisWeek.map((person) => {
                                        const badgeClass = STATUS_BADGE_STYLES[person.coaching_status] ?? STATUS_BADGE_STYLES['No Record'];
                                        const createUrl = person.role === 'Team Lead'
                                            ? `/coaching/sessions/create?coaching_mode=direct&coachee_id=${person.id}`
                                            : `/coaching/sessions/create?coachee_id=${person.id}`;
                                        return (
                                            <Link
                                                key={`${person.role}-${person.id}`}
                                                href={createUrl}
                                                className="flex items-center justify-between rounded-lg border p-3 hover:bg-muted/50 transition-colors"
                                            >
                                                <div className="min-w-0">
                                                    <p className="text-sm font-medium truncate">{person.name}</p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {person.campaign}
                                                        {isAdmin && <span className="ml-1 opacity-60">({person.role})</span>}
                                                    </p>
                                                </div>
                                                <Badge variant="outline" className={`${badgeClass} text-[10px] px-1.5 shrink-0`}>
                                                    {STATUS_CONFIG[person.coaching_status]?.label ?? person.coaching_status}
                                                </Badge>
                                            </Link>
                                        );
                                    })}
                                </div>
                            ) : (
                                <div className="flex flex-col items-center justify-center py-12 text-center">
                                    <UserCheck className="h-10 w-10 text-green-500 mb-3" />
                                    <p className="text-sm font-medium text-green-700 dark:text-green-400">All coached this week!</p>
                                    <p className="text-xs text-muted-foreground mt-1">Great job keeping the team on track.</p>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </motion.div>

                {/* Coached This Week */}
                <motion.div
                    initial={{ opacity: 0, y: 20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.4, delay: 0.28 }}
                >
                    <Card className="h-full">
                        <CardHeader>
                            <CardTitle className="flex items-center justify-between">
                                <span className="flex items-center gap-2">
                                    <UserCheck className="h-5 w-5 text-green-600" />
                                    Coached This Week
                                </span>
                                {allCoachedThisWeek.length > 0 && (
                                    <Badge variant="outline" className="bg-green-500/10 text-green-700 dark:text-green-400 border-green-500/30 text-xs">
                                        {allCoachedThisWeek.length}
                                    </Badge>
                                )}
                            </CardTitle>
                            <CardDescription>
                                {roleFilter === 'all' ? 'Team leads & agents' : roleFilter === 'Team Lead' ? 'Team leads' : 'Agents'} who have been coached this week
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {allCoachedThisWeek.length > 0 ? (
                                <div className="max-h-[320px] overflow-y-auto space-y-2 pr-1 scrollbar-thin">
                                    {allCoachedThisWeek.map((person) => {
                                        const badgeClass = STATUS_BADGE_STYLES[person.coaching_status] ?? STATUS_BADGE_STYLES['No Record'];
                                        return (
                                            <div
                                                key={`${person.role}-${person.id}`}
                                                className="flex items-center justify-between rounded-lg border p-3"
                                            >
                                                <div className="min-w-0">
                                                    <p className="text-sm font-medium truncate">{person.name}</p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {person.campaign}
                                                        {isAdmin && <span className="ml-1 opacity-60">({person.role})</span>}
                                                    </p>
                                                </div>
                                                <div className="flex items-center gap-2 shrink-0">
                                                    <Badge variant="outline" className={`${badgeClass} text-[10px] px-1.5`}>
                                                        {STATUS_CONFIG[person.coaching_status]?.label ?? person.coaching_status}
                                                    </Badge>
                                                    {person.last_coached_date && (
                                                        <span className="text-[10px] text-muted-foreground">
                                                            {new Date(person.last_coached_date + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            ) : (
                                <div className="flex flex-col items-center justify-center py-12 text-center">
                                    <ClipboardCheck className="h-10 w-10 text-muted-foreground mb-3" />
                                    <p className="text-sm font-medium text-muted-foreground">No one coached this week yet.</p>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </motion.div>

                {/* Quick Stats & Actions */}
                <motion.div
                    initial={{ opacity: 0, y: 20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.4, delay: 0.3 }}
                    className={`space-y-4 ${isAdmin && tlTotal > 0 ? 'lg:col-span-2' : ''}`}
                >
                    {/* Summary Cards */}
                    <div className={`grid gap-4 grid-cols-1 ${isAdmin && tlTotal > 0 ? 'lg:grid-cols-2' : ''}`}>
                        {/* Coaching Summary */}
                        <Card className="h-full">
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <ClipboardCheck className="h-5 w-5" />
                                    Coaching Summary
                                </CardTitle>
                                <CardDescription>
                                    Overview of coaching activity and pending actions
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid grid-cols-2 gap-4">
                                    <div className="flex flex-col items-center rounded-lg border p-4">
                                        <Users className="h-5 w-5 text-muted-foreground mb-1" />
                                        <span className="text-2xl font-bold">{total_agents}</span>
                                        <span className="text-xs text-muted-foreground">Total Agents</span>
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
                                                Awaiting Acknowledgement
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
                                {urgentCount > 0 && (
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

                        {/* TL Coaching Summary (Admin only) */}
                        {isAdmin && tlTotal > 0 && (
                            <Card className="h-full">
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <Users className="h-5 w-5" />
                                        TL Coaching Summary
                                    </CardTitle>
                                    <CardDescription>
                                        Overview of team lead coaching activity
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="flex flex-col items-center rounded-lg border p-4">
                                            <Users className="h-5 w-5 text-muted-foreground mb-1" />
                                            <span className="text-2xl font-bold">{tlTotal}</span>
                                            <span className="text-xs text-muted-foreground">Total Team Leads</span>
                                        </div>
                                        <div className="flex flex-col items-center rounded-lg border p-4">
                                            <TrendingUp className="h-5 w-5 text-muted-foreground mb-1" />
                                            <span className="text-2xl font-bold">{tlSessionsThisMonth}</span>
                                            <span className="text-xs text-muted-foreground">TL Sessions This Month</span>
                                        </div>
                                    </div>

                                    <div className="space-y-2">
                                        <p className="text-sm font-medium text-muted-foreground">TL Pending Actions</p>
                                        <div className="space-y-2">
                                            <div className="flex items-center justify-between rounded-lg border p-3">
                                                <span className="flex items-center gap-2 text-sm">
                                                    <Clock className="h-4 w-4 text-yellow-600" />
                                                    Awaiting Acknowledgement
                                                </span>
                                                <Badge variant="outline" className={`${tlPendingAcks > 0 ? 'bg-yellow-500/10 text-yellow-700 dark:text-yellow-400 border-yellow-500/30' : ''}`}>
                                                    {tlPendingAcks}
                                                </Badge>
                                            </div>
                                            <div className="flex items-center justify-between rounded-lg border p-3">
                                                <span className="flex items-center gap-2 text-sm">
                                                    <FileCheck className="h-4 w-4 text-blue-600" />
                                                    Pending Reviews
                                                </span>
                                                <Badge variant="outline" className={`${tlPendingReviews > 0 ? 'bg-blue-500/10 text-blue-700 dark:text-blue-400 border-blue-500/30' : ''}`}>
                                                    {tlPendingReviews}
                                                </Badge>
                                            </div>
                                        </div>
                                    </div>

                                    {tlUrgentCount > 0 && (
                                        <div className="rounded-lg border border-red-500/30 bg-red-500/5 p-3">
                                            <div className="flex items-center gap-2">
                                                <ShieldAlert className="h-4 w-4 text-red-600" />
                                                <span className="text-sm font-medium text-red-700 dark:text-red-400">
                                                    {tlUrgentCount} {tlUrgentCount === 1 ? 'TL needs' : 'TLs need'} urgent coaching
                                                </span>
                                            </div>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}
                    </div>

                    {/* Quick Links */}
                    <div className="flex flex-col sm:flex-row gap-2">
                        <Button variant="outline" asChild className="flex-1">
                            <Link href="/coaching/dashboard" className="flex items-center gap-2">
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
