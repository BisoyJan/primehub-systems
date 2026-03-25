import { useMemo, useState } from 'react';
import { Head, Link, usePage, router } from '@inertiajs/react';
import type { PageProps as InertiaPageProps } from '@inertiajs/core';
import { Calendar as CalendarIcon, CalendarClock, Eye, Plus, Filter, Users, AlertTriangle } from 'lucide-react';
import PaginationNav, { type PaginationLink } from '@/components/pagination-nav';

import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { usePageMeta, useFlashMessage, usePageLoading } from '@/hooks';
import { CoachingStatusBadge, AckStatusBadge, SeverityBadge } from '@/components/coaching/CoachingStatusBadge';
import { CoachingSummaryCards } from '@/components/coaching/CoachingSummaryCards';
import { Badge } from '@/components/ui/badge';
import { Calendar } from '@/components/ui/calendar';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

import { dashboard as coachingDashboard } from '@/routes/coaching';
import { create as sessionsCreate, show as sessionsShow } from '@/routes/coaching/sessions';

import type { CoachingSession, CoachingPurposeLabels, CoachingStatusColors, CoachingStatusLabel } from '@/types';

interface AgentRow {
    id: number;
    name: string;
    account: string;
    campaign_id: number | null;
    coaching_status: CoachingStatusLabel;
    status_color: string;
    last_coached_date: string | null;
    previous_coached_date: string | null;
    older_coached_date: string | null;
    pending_acknowledgements: number;
    total_sessions: number;
}

interface DashboardData {
    total_agents: number;
    status_counts: Record<string, number>;
    agents: AgentRow[];
}

interface Filters {
    coaching_status?: string;
    date_from?: string;
    date_to?: string;
}

interface PaginatedSessions {
    data: CoachingSession[];
    links: PaginationLink[];
}

interface FollowUp {
    id: number;
    agent_name: string;
    team_lead_name: string;
    follow_up_date: string;
    purpose_label: string;
    session_date: string;
}

interface Props extends InertiaPageProps {
    dashboardData: DashboardData;
    recentSessions: PaginatedSessions;
    campaignName: string;
    upcomingFollowUps: FollowUp[];
    overdueFollowUps: FollowUp[];
    filters: Filters;
    statusColors: CoachingStatusColors;
    purposes: CoachingPurposeLabels;
}

function daysUntil(dateStr: string): number {
    const now = new Date();
    now.setHours(0, 0, 0, 0);
    const target = new Date(dateStr + 'T00:00:00');
    return Math.ceil((target.getTime() - now.getTime()) / 86400000);
}

function getUrgencyStyles(days: number) {
    if (days < 0) return { border: 'border-red-500/50 bg-red-500/5', text: 'text-red-600 dark:text-red-400', label: `${Math.abs(days)}d overdue` };
    if (days === 0) return { border: 'border-red-500/50 bg-red-500/5', text: 'text-red-600 dark:text-red-400', label: 'Today' };
    if (days === 1) return { border: 'border-orange-500/50 bg-orange-500/5', text: 'text-orange-600 dark:text-orange-400', label: 'Tomorrow' };
    return { border: 'border-yellow-500/50 bg-yellow-500/5', text: 'text-yellow-600 dark:text-yellow-400', label: `${days}d away` };
}

export default function CoachingDashboardIndex() {
    const { dashboardData, recentSessions, campaignName, upcomingFollowUps, overdueFollowUps, filters: initialFilters, purposes } = usePage<Props>().props;

    const { title, breadcrumbs } = usePageMeta({
        title: 'Coaching Dashboard',
        breadcrumbs: [{ title: 'Coaching Dashboard', href: coachingDashboard().url }],
    });
    useFlashMessage();
    const isLoading = usePageLoading();

    const [coachingStatus, setCoachingStatus] = useState(initialFilters.coaching_status || '');
    const [dateFrom, setDateFrom] = useState(initialFilters.date_from || '');
    const [dateTo, setDateTo] = useState(initialFilters.date_to || '');
    const [calendarSelectedDate, setCalendarSelectedDate] = useState<Date | undefined>(undefined);

    const allFollowUps = useMemo(() => [...overdueFollowUps, ...upcomingFollowUps], [overdueFollowUps, upcomingFollowUps]);

    const followUpDates = useMemo(() => {
        return allFollowUps.map((item) => new Date(item.follow_up_date + 'T00:00:00'));
    }, [allFollowUps]);

    const selectedDateFollowUps = useMemo(() => {
        if (!calendarSelectedDate) return allFollowUps;
        const dateStr = calendarSelectedDate.toISOString().split('T')[0];
        return allFollowUps.filter((item) => item.follow_up_date === dateStr);
    }, [calendarSelectedDate, allFollowUps]);

    const handleFilter = () => {
        router.get(
            coachingDashboard().url,
            {
                coaching_status: coachingStatus || undefined,
                date_from: dateFrom || undefined,
                date_to: dateTo || undefined,
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    const handleReset = () => {
        setCoachingStatus('');
        setDateFrom('');
        setDateTo('');
        router.get(coachingDashboard().url);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="relative flex h-full flex-1 flex-col gap-4 rounded-xl p-3">
                <LoadingOverlay isLoading={isLoading} />

                <PageHeader
                    title="Team Coaching Dashboard"
                    description={`Campaign: ${campaignName}`}
                    createLink={sessionsCreate().url}
                    createLabel="New Session"
                />

                {/* Summary Cards */}
                <CoachingSummaryCards totalAgents={dashboardData.total_agents} statusCounts={dashboardData.status_counts} />

                {/* Main Tabs */}
                <Tabs defaultValue="agents" className="space-y-2">
                    <TabsList className="grid w-full grid-cols-3">
                        <TabsTrigger value="agents" className="flex items-center gap-2">
                            <Users className="h-4 w-4" />
                            <span className="hidden sm:inline">Agent Overview</span>
                            <span className="sm:hidden">Agents</span>
                            {dashboardData.agents.length > 0 && (
                                <Badge variant="secondary" className="ml-0.5 px-1.5 py-0 text-[10px]">
                                    {dashboardData.agents.length}
                                </Badge>
                            )}
                        </TabsTrigger>
                        <TabsTrigger value="followups" className="flex items-center gap-2">
                            <CalendarClock className="h-4 w-4" />
                            <span className="hidden sm:inline">Follow-ups</span>
                            <span className="sm:hidden">Follow-ups</span>
                            {allFollowUps.length > 0 && (
                                <Badge variant="secondary" className="ml-0.5 px-1.5 py-0 text-[10px]">
                                    {allFollowUps.length}
                                </Badge>
                            )}
                            {overdueFollowUps.length > 0 && (
                                <Badge variant="destructive" className="ml-0.5 px-1.5 py-0 text-[10px]">
                                    {overdueFollowUps.length}
                                </Badge>
                            )}
                        </TabsTrigger>
                        <TabsTrigger value="sessions" className="flex items-center gap-2">
                            <CalendarIcon className="h-4 w-4" />
                            <span className="hidden sm:inline">Recent Sessions</span>
                            <span className="sm:hidden">Sessions</span>
                            {recentSessions.data.length > 0 && (
                                <Badge variant="secondary" className="ml-0.5 px-1.5 py-0 text-[10px]">
                                    {recentSessions.data.length}
                                </Badge>
                            )}
                        </TabsTrigger>
                    </TabsList>

                    {/* Agent Overview Tab */}
                    <TabsContent value="agents" className="space-y-4">
                        {/* Filters */}
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                            <Select value={coachingStatus} onValueChange={setCoachingStatus}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Coaching Status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Coaching Done">Coaching Done</SelectItem>
                                    <SelectItem value="Needs Coaching">Needs Coaching</SelectItem>
                                    <SelectItem value="Badly Needs Coaching">Badly Needs Coaching</SelectItem>
                                    <SelectItem value="Please Coach ASAP">Please Coach ASAP</SelectItem>
                                    <SelectItem value="No Record">No Record</SelectItem>
                                </SelectContent>
                            </Select>
                            <Input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} placeholder="Date from" />
                            <Input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} placeholder="Date to" />
                            <Button onClick={handleFilter}>
                                <Filter className="mr-2 h-4 w-4" /> Filter
                            </Button>
                            <Button variant="outline" onClick={handleReset}>
                                Reset
                            </Button>
                        </div>

                        {/* Desktop Table */}
                        <div className="hidden overflow-hidden rounded-md shadow md:block">
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow className="bg-muted/50">
                                            <TableHead>Agent</TableHead>
                                            <TableHead>Account</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Last Coached</TableHead>
                                            <TableHead>Sessions</TableHead>
                                            <TableHead>Pending Ack</TableHead>
                                            <TableHead className="text-center">Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {dashboardData.agents.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={7} className="py-8 text-center text-muted-foreground">
                                                    No agents found matching filters.
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            dashboardData.agents.map((agent) => (
                                                <TableRow key={agent.id}>
                                                    <TableCell className="font-medium">{agent.name}</TableCell>
                                                    <TableCell>{agent.account}</TableCell>
                                                    <TableCell>
                                                        <CoachingStatusBadge status={agent.coaching_status} />
                                                    </TableCell>
                                                    <TableCell className="whitespace-nowrap">
                                                        {agent.last_coached_date ? new Date(agent.last_coached_date).toLocaleDateString() : 'Never'}
                                                    </TableCell>
                                                    <TableCell>{agent.total_sessions}</TableCell>
                                                    <TableCell>
                                                        {agent.pending_acknowledgements > 0 ? (
                                                            <span className="font-medium text-amber-600">{agent.pending_acknowledgements}</span>
                                                        ) : (
                                                            <span className="text-muted-foreground">0</span>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="flex items-center justify-center">
                                                            <Link href={sessionsCreate().url + `?coachee_id=${agent.id}`}>
                                                                <Button variant="ghost" size="icon" title="Coach Agent">
                                                                    <Plus className="h-4 w-4" />
                                                                </Button>
                                                            </Link>
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </div>
                        </div>

                        {/* Mobile Card View */}
                        <div className="space-y-3 md:hidden">
                            {dashboardData.agents.length === 0 ? (
                                <div className="py-8 text-center text-muted-foreground">No agents found.</div>
                            ) : (
                                dashboardData.agents.map((agent) => (
                                    <div key={agent.id} className="rounded-lg border bg-card p-4 shadow-sm space-y-2">
                                        <div className="flex items-start justify-between">
                                            <div>
                                                <p className="font-medium">{agent.name}</p>
                                                <p className="text-xs text-muted-foreground">{agent.account}</p>
                                            </div>
                                            <CoachingStatusBadge status={agent.coaching_status} />
                                        </div>
                                        <div className="flex flex-wrap gap-3 text-xs text-muted-foreground">
                                            <span>Last: {agent.last_coached_date ? new Date(agent.last_coached_date).toLocaleDateString() : 'Never'}</span>
                                            <span>Sessions: {agent.total_sessions}</span>
                                            {agent.pending_acknowledgements > 0 && (
                                                <span className="font-medium text-amber-600">{agent.pending_acknowledgements} Pending</span>
                                            )}
                                        </div>
                                        <Link href={sessionsCreate().url + `?coachee_id=${agent.id}`}>
                                            <Button variant="outline" size="sm" className="mt-1 w-full">
                                                <Plus className="mr-1.5 h-3.5 w-3.5" /> Coach Agent
                                            </Button>
                                        </Link>
                                    </div>
                                ))
                            )}
                        </div>
                    </TabsContent>

                    {/* Follow-ups Tab */}
                    <TabsContent value="followups">
                        <Tabs defaultValue="upcoming" className="space-y-2" onValueChange={(v) => { if (v === 'calendar') setCalendarSelectedDate(undefined); }}>
                            <TabsList className="grid w-full grid-cols-3">
                                <TabsTrigger value="upcoming" className="flex items-center gap-2">
                                    <CalendarClock className="h-4 w-4" />
                                    <span className="hidden sm:inline">Upcoming</span>
                                    {upcomingFollowUps.length > 0 && (
                                        <Badge variant="secondary" className="ml-0.5 px-1.5 py-0 text-[10px]">
                                            {upcomingFollowUps.length}
                                        </Badge>
                                    )}
                                </TabsTrigger>
                                <TabsTrigger value="overdue" className="flex items-center gap-2">
                                    <AlertTriangle className="h-4 w-4" />
                                    <span className="hidden sm:inline">Overdue</span>
                                    {overdueFollowUps.length > 0 && (
                                        <Badge variant="destructive" className="ml-0.5 px-1.5 py-0 text-[10px]">
                                            {overdueFollowUps.length}
                                        </Badge>
                                    )}
                                </TabsTrigger>
                                <TabsTrigger value="calendar" className="flex items-center gap-2">
                                    <CalendarIcon className="h-4 w-4" />
                                    <span className="hidden sm:inline">Calendar</span>
                                </TabsTrigger>
                            </TabsList>

                            <TabsContent value="upcoming">
                                <FollowUpTable items={upcomingFollowUps} emptyMessage="No upcoming follow-ups scheduled." emptyDescription="Set follow-up dates when creating coaching sessions." />
                            </TabsContent>

                            <TabsContent value="overdue">
                                <FollowUpTable items={overdueFollowUps} emptyMessage="No overdue follow-ups." emptyDescription="All follow-ups are on track." />
                            </TabsContent>

                            <TabsContent value="calendar">
                                <div className="flex flex-col gap-4 lg:flex-row">
                                    <div className="rounded-lg border bg-card p-2 shadow-sm">
                                        <Calendar
                                            mode="single"
                                            selected={calendarSelectedDate}
                                            onSelect={setCalendarSelectedDate}
                                            modifiers={{ hasFollowUp: followUpDates }}
                                            modifiersClassNames={{ hasFollowUp: 'ring-2 ring-primary/40 ring-offset-1' }}
                                        />
                                        <div className="flex items-center gap-4 border-t px-3 pt-2 text-[10px] text-muted-foreground">
                                            <span className="flex items-center gap-1"><span className="inline-block h-2 w-2 rounded-full ring-2 ring-primary/40" /> Has follow-up</span>
                                            {calendarSelectedDate && (
                                                <button onClick={() => setCalendarSelectedDate(undefined)} className="text-primary hover:underline">Clear selection</button>
                                            )}
                                        </div>
                                    </div>
                                    <div className="flex-1">
                                        <FollowUpTable
                                            items={selectedDateFollowUps}
                                            emptyMessage={calendarSelectedDate ? 'No follow-ups on this date.' : 'No follow-ups scheduled.'}
                                            emptyDescription={calendarSelectedDate ? `${calendarSelectedDate.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' })}` : undefined}
                                            compact
                                        />
                                    </div>
                                </div>
                            </TabsContent>
                        </Tabs>
                    </TabsContent>

                    {/* Recent Sessions Tab */}
                    <TabsContent value="sessions" className="space-y-2">
                        {recentSessions.data.length === 0 ? (
                            <div className="rounded-lg border border-dashed p-6 text-center">
                                <CalendarIcon className="mx-auto h-8 w-8 text-muted-foreground/50" />
                                <p className="mt-2 text-sm text-muted-foreground">No recent sessions.</p>
                            </div>
                        ) : (
                            <>
                                <div className="hidden overflow-hidden rounded-md shadow md:block">
                                    <Table>
                                        <TableHeader>
                                            <TableRow className="bg-muted/50">
                                                <TableHead>Date</TableHead>
                                                <TableHead>Coachee</TableHead>
                                                <TableHead>Purpose</TableHead>
                                                <TableHead>Ack</TableHead>
                                                <TableHead>Severity</TableHead>
                                                <TableHead className="text-center">Actions</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {recentSessions.data.map((session) => (
                                                <TableRow key={session.id}>
                                                    <TableCell className="whitespace-nowrap">
                                                        {new Date(session.session_date).toLocaleDateString()}
                                                    </TableCell>
                                                    <TableCell className="font-medium">
                                                        {session.coachee ? `${session.coachee.first_name} ${session.coachee.last_name}` : 'N/A'}
                                                    </TableCell>
                                                    <TableCell>{purposes[session.purpose] ?? session.purpose}</TableCell>
                                                    <TableCell>
                                                        <AckStatusBadge status={session.ack_status} />
                                                    </TableCell>
                                                    <TableCell>
                                                        <SeverityBadge flag={session.severity_flag} />
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="flex items-center justify-center">
                                                            <Link href={sessionsShow.url(session.id)}>
                                                                <Button variant="ghost" size="icon" title="View">
                                                                    <Eye className="h-4 w-4" />
                                                                </Button>
                                                            </Link>
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>

                                {/* Mobile recent sessions */}
                                <div className="space-y-3 md:hidden">
                                    {recentSessions.data.map((session) => (
                                        <div key={session.id} className="rounded-lg border bg-card p-3 shadow-sm">
                                            <div className="flex items-start justify-between gap-2">
                                                <div>
                                                    <p className="text-xs text-muted-foreground">
                                                        {new Date(session.session_date).toLocaleDateString()}
                                                    </p>
                                                    <p className="text-sm font-medium">
                                                        {session.coachee ? `${session.coachee.first_name} ${session.coachee.last_name}` : 'N/A'}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">{purposes[session.purpose] ?? session.purpose}</p>
                                                </div>
                                                <div className="flex flex-col items-end gap-1">
                                                    <AckStatusBadge status={session.ack_status} />
                                                    <SeverityBadge flag={session.severity_flag} />
                                                </div>
                                            </div>
                                            <Link href={sessionsShow.url(session.id)}>
                                                <Button variant="outline" size="sm" className="mt-2 w-full">
                                                    <Eye className="mr-1.5 h-3.5 w-3.5" /> View
                                                </Button>
                                            </Link>
                                        </div>
                                    ))}
                                </div>

                                {/* Pagination */}
                                {recentSessions.links && recentSessions.links.length > 3 && (
                                    <PaginationNav links={recentSessions.links} />
                                )}
                            </>
                        )}
                    </TabsContent>
                </Tabs>
            </div>
        </AppLayout>
    );
}

// ─── Sub-components ─────────────────────────────────────────────

function FollowUpTable({
    items,
    emptyMessage,
    emptyDescription,
    compact = false,
}: {
    items: FollowUp[];
    emptyMessage: string;
    emptyDescription?: string;
    compact?: boolean;
}) {
    const perPage = 25;
    const [page, setPage] = useState(1);
    const totalPages = Math.ceil(items.length / perPage);
    const paginated = items.slice((page - 1) * perPage, page * perPage);

    if (items.length === 0) {
        return (
            <div className="rounded-lg border border-dashed p-6 text-center">
                <CalendarClock className="mx-auto h-8 w-8 text-muted-foreground/50" />
                <p className="mt-2 text-sm text-muted-foreground">{emptyMessage}</p>
                {emptyDescription && <p className="text-xs text-muted-foreground">{emptyDescription}</p>}
            </div>
        );
    }

    return (
        <div className="space-y-2">
            {/* Desktop Table */}
            <div className="hidden overflow-hidden rounded-md shadow md:block">
                <div className="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow className="bg-muted/50">
                                <TableHead>Follow-up Date</TableHead>
                                <TableHead>Coachee</TableHead>
                                {!compact && <TableHead>Coach</TableHead>}
                                <TableHead>Purpose</TableHead>
                                {!compact && <TableHead>Session Date</TableHead>}
                                <TableHead className="text-center">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {paginated.map((item) => {
                                const days = daysUntil(item.follow_up_date);
                                const s = getUrgencyStyles(days);
                                return (
                                    <TableRow key={item.id}>
                                        <TableCell className="whitespace-nowrap">
                                            <span>{new Date(item.follow_up_date + 'T00:00:00').toLocaleDateString()}</span>
                                            <span className={`ml-2 text-xs font-semibold ${s.text}`}>{s.label}</span>
                                        </TableCell>
                                        <TableCell className="font-medium">{item.agent_name}</TableCell>
                                        {!compact && <TableCell>{item.team_lead_name}</TableCell>}
                                        <TableCell>{item.purpose_label}</TableCell>
                                        {!compact && (
                                            <TableCell className="whitespace-nowrap">
                                                {new Date(item.session_date).toLocaleDateString()}
                                            </TableCell>
                                        )}
                                        <TableCell>
                                            <div className="flex items-center justify-center">
                                                <Link href={sessionsShow.url(item.id)}>
                                                    <Button variant="ghost" size="icon" title="View Session">
                                                        <Eye className="h-4 w-4" />
                                                    </Button>
                                                </Link>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>
            </div>

            {/* Mobile Cards */}
            <div className="space-y-2 md:hidden">
                {paginated.map((item) => {
                    const days = daysUntil(item.follow_up_date);
                    const s = getUrgencyStyles(days);
                    return (
                        <Link key={item.id} href={sessionsShow.url(item.id)} className={`block rounded-lg border p-3 space-y-1 transition-colors hover:bg-muted/50 ${s.border}`}>
                            <div className="flex items-start justify-between gap-2">
                                <p className="text-sm font-medium leading-tight truncate">{item.agent_name}</p>
                                <span className={`text-xs font-semibold shrink-0 ${s.text}`}>{s.label}</span>
                            </div>
                            <p className="text-xs text-muted-foreground truncate">{item.purpose_label}</p>
                            <div className="flex items-center justify-between text-[11px] text-muted-foreground">
                                <span>Follow-up: {new Date(item.follow_up_date + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}</span>
                                {!compact && <span>Session: {new Date(item.session_date).toLocaleDateString()}</span>}
                            </div>
                        </Link>
                    );
                })}
            </div>

            {/* Pagination */}
            {totalPages > 1 && (
                <div className="flex items-center justify-between border-t pt-3">
                    <p className="text-xs text-muted-foreground">
                        {(page - 1) * perPage + 1}–{Math.min(page * perPage, items.length)} of {items.length}
                    </p>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                            Previous
                        </Button>
                        <Button variant="outline" size="sm" disabled={page >= totalPages} onClick={() => setPage(page + 1)}>
                            Next
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}
