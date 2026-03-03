import { useState } from 'react';
import { Head, Link, usePage, router } from '@inertiajs/react';
import type { PageProps as InertiaPageProps } from '@inertiajs/core';
import { Calendar, Eye, Plus, Filter, Users } from 'lucide-react';
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

interface Props extends InertiaPageProps {
    dashboardData: DashboardData;
    recentSessions: PaginatedSessions;
    campaignName: string;
    filters: Filters;
    statusColors: CoachingStatusColors;
    purposes: CoachingPurposeLabels;
}

export default function CoachingDashboardIndex() {
    const { dashboardData, recentSessions, campaignName, filters: initialFilters, purposes } = usePage<Props>().props;

    const { title, breadcrumbs } = usePageMeta({
        title: 'Coaching Dashboard',
        breadcrumbs: [{ title: 'Coaching Dashboard', href: coachingDashboard().url }],
    });
    useFlashMessage();
    const isLoading = usePageLoading();

    const [coachingStatus, setCoachingStatus] = useState(initialFilters.coaching_status || '');
    const [dateFrom, setDateFrom] = useState(initialFilters.date_from || '');
    const [dateTo, setDateTo] = useState(initialFilters.date_to || '');

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

                {/* Agent Status Table */}
                <div className="space-y-2">
                    <h3 className="flex items-center gap-2 text-sm font-semibold">
                        <Users className="h-4 w-4" /> Agent Overview ({dashboardData.agents.length})
                    </h3>

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
                                                        <Link href={sessionsCreate().url + `?agent_id=${agent.id}`}>
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
                                    <Link href={sessionsCreate().url + `?agent_id=${agent.id}`}>
                                        <Button variant="outline" size="sm" className="mt-1 w-full">
                                            <Plus className="mr-1.5 h-3.5 w-3.5" /> Coach Agent
                                        </Button>
                                    </Link>
                                </div>
                            ))
                        )}
                    </div>
                </div>

                {/* Recent Sessions */}
                {recentSessions.data.length > 0 && (
                    <div className="space-y-2">
                        <h3 className="flex items-center gap-2 text-sm font-semibold">
                            <Calendar className="h-4 w-4" /> Recent Sessions
                        </h3>

                        <div className="hidden overflow-hidden rounded-md shadow md:block">
                            <Table>
                                <TableHeader>
                                    <TableRow className="bg-muted/50">
                                        <TableHead>Date</TableHead>
                                        <TableHead>Agent</TableHead>
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
                                                {session.agent ? `${session.agent.first_name} ${session.agent.last_name}` : 'N/A'}
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
                                                {session.agent ? `${session.agent.first_name} ${session.agent.last_name}` : 'N/A'}
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
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
