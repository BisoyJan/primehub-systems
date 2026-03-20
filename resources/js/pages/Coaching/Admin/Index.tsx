import { Fragment, useMemo, useState } from 'react';
import { Head, Link, usePage, router, useForm } from '@inertiajs/react';
import type { PageProps as InertiaPageProps } from '@inertiajs/core';
import {
    AlertTriangle,
    Eye,
    Filter,
    ShieldCheck,
    ShieldX,
    Users,
    Clock,
    ClipboardCheck,
} from 'lucide-react';

import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
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
import { CoachingStatusBadge, SeverityBadge } from '@/components/coaching/CoachingStatusBadge';
import { CoachingSummaryCards } from '@/components/coaching/CoachingSummaryCards';

import { dashboard as coachingDashboard } from '@/routes/coaching';
import {
    show as sessionsShow,
    review as sessionsReview,
} from '@/routes/coaching/sessions';

import type {
    CoachingSession,
    CoachingPurposeLabels,
    CoachingStatusColors,
    CoachingStatusLabel,
    Campaign,
    User,
} from '@/types';

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

interface QueueData {
    unacknowledged: CoachingSession[];
    for_review: CoachingSession[];
    at_risk_agents: { id: number; name: string; account: string; coaching_status: CoachingStatusLabel; status_color: string }[];
}

interface Filters {
    campaign_id?: string;
    coach_id?: string;
    coaching_status?: string;
    coachee_role?: string;
    date_from?: string;
    date_to?: string;
}

interface Props extends InertiaPageProps {
    dashboardData: DashboardData;
    teamLeadCoachingData: DashboardData;
    queueData: QueueData;
    campaigns: Campaign[];
    teamLeads: User[];
    filters: Filters;
    statusColors: CoachingStatusColors;
    purposes: CoachingPurposeLabels;
}

type TabKey = 'overview' | 'unacknowledged' | 'for_review' | 'at_risk';

export default function CoachingAdminIndex() {
    const { dashboardData, teamLeadCoachingData, queueData, campaigns, teamLeads, filters: initialFilters, purposes } = usePage<Props>().props;

    const { title, breadcrumbs } = usePageMeta({
        title: 'Coaching Compliance',
        breadcrumbs: [{ title: 'Coaching Compliance', href: coachingDashboard().url }],
    });
    useFlashMessage();
    const isLoading = usePageLoading();

    const [activeTab, setActiveTab] = useState<TabKey>('overview');
    const [campaignId, setCampaignId] = useState(initialFilters.campaign_id || '');
    const [coachId, setCoachId] = useState(initialFilters.coach_id || '');
    const [coachingStatus, setCoachingStatus] = useState(initialFilters.coaching_status || '');
    const [coacheeRole, setCoacheeRole] = useState(initialFilters.coachee_role || 'Agent');
    const [dateFrom, setDateFrom] = useState(initialFilters.date_from || '');
    const [dateTo, setDateTo] = useState(initialFilters.date_to || '');

    const reviewForm = useForm({ compliance_status: '' as string, compliance_notes: '' });

    const handleCoacheeRoleChange = (value: string) => {
        setCoacheeRole(value);
        if (value === 'Team Lead') {
            setCoachId('');
        }
    };

    const activeData = coacheeRole === 'Team Lead' ? teamLeadCoachingData : dashboardData;

    const handleFilter = () => {
        router.get(
            coachingDashboard().url,
            {
                campaign_id: campaignId || undefined,
                coach_id: coachId || undefined,
                coaching_status: coachingStatus || undefined,
                coachee_role: coacheeRole || undefined,
                date_from: dateFrom || undefined,
                date_to: dateTo || undefined,
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    const handleReset = () => {
        setCampaignId('');
        setCoachId('');
        setCoachingStatus('');
        setCoacheeRole('Agent');
        setDateFrom('');
        setDateTo('');
        router.get(coachingDashboard().url, { coachee_role: 'Agent' });
    };

    const handleReview = (sessionId: number) => {
        reviewForm.patch(sessionsReview(sessionId).url, {
            onSuccess: () => reviewForm.reset(),
        });
    };

    const formatName = (user?: { first_name: string; last_name: string } | null) => {
        if (!user) return 'N/A';
        return `${user.first_name} ${user.last_name}`;
    };

    const groupedAgents = useMemo(() => {
        const groups: Record<string, AgentRow[]> = {};
        for (const agent of activeData.agents) {
            const key = agent.account || 'Unassigned';
            if (!groups[key]) groups[key] = [];
            groups[key].push(agent);
        }
        return Object.entries(groups).sort(([a], [b]) => a.localeCompare(b));
    }, [activeData.agents]);

    const tabs: { key: TabKey; label: string; icon: React.ComponentType<{ className?: string }>; count?: number }[] = [
        { key: 'overview', label: 'Overview', icon: Users, count: activeData.agents.length },
        { key: 'unacknowledged', label: 'Unacknowledged', icon: Clock, count: queueData.unacknowledged.length },
        { key: 'for_review', label: 'For Review', icon: ClipboardCheck, count: queueData.for_review.length },
        { key: 'at_risk', label: 'At Risk', icon: AlertTriangle, count: queueData.at_risk_agents.length },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="relative flex h-full flex-1 flex-col gap-4 rounded-xl p-3">
                <LoadingOverlay isLoading={isLoading} />

                <PageHeader title="Coaching Compliance Dashboard" />

                {/* Summary Cards */}
                <CoachingSummaryCards totalAgents={activeData.total_agents} statusCounts={activeData.status_counts} />

                {/* Filters */}
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-8">
                    <Select value={campaignId} onValueChange={setCampaignId}>
                        <SelectTrigger>
                            <SelectValue placeholder="Campaign" />
                        </SelectTrigger>
                        <SelectContent>
                            {campaigns.map((c) => (
                                <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {coacheeRole !== 'Team Lead' && (
                        <Select value={coachId} onValueChange={setCoachId}>
                            <SelectTrigger>
                                <SelectValue placeholder="Team Lead" />
                            </SelectTrigger>
                            <SelectContent>
                                {teamLeads.map((tl) => (
                                    <SelectItem key={tl.id} value={String(tl.id)}>
                                        {tl.first_name} {tl.last_name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
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
                    <Select value={coacheeRole} onValueChange={handleCoacheeRoleChange}>
                        <SelectTrigger>
                            <SelectValue placeholder="Coachee Role" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="Team Lead">Team Lead</SelectItem>
                            <SelectItem value="Agent">Agent</SelectItem>
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

                {/* Tabs */}
                <div className="flex flex-wrap gap-1 rounded-lg border bg-muted/30 p-1">
                    {tabs.map(({ key, label, icon: Icon, count }) => (
                        <button
                            key={key}
                            onClick={() => setActiveTab(key)}
                            className={`flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${activeTab === key
                                ? 'bg-background text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground'
                                }`}
                        >
                            <Icon className="h-3.5 w-3.5" />
                            <span className="hidden sm:inline">{label}</span>
                            {(count ?? 0) > 0 && (
                                <span className={`ml-0.5 rounded-full px-1.5 py-0.5 text-[10px] font-semibold ${activeTab === key
                                    ? 'bg-primary/10 text-primary'
                                    : 'bg-muted text-muted-foreground'
                                    }`}>
                                    {count}
                                </span>
                            )}
                        </button>
                    ))}
                </div>

                {/* Tab Content */}
                {activeTab === 'overview' && (
                    <div className="space-y-2">
                        <h3 className="flex items-center gap-2 text-sm font-semibold">
                            <Users className="h-4 w-4" /> All {coacheeRole === 'Team Lead' ? 'Team Leads' : 'Agents'} ({activeData.agents.length})
                        </h3>

                        {/* Desktop Table */}
                        <div className="hidden overflow-hidden rounded-md shadow md:block">
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow className="bg-muted/50">
                                            <TableHead>Agent</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Last Coached</TableHead>
                                            <TableHead>Sessions</TableHead>
                                            <TableHead>Pending Ack</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {activeData.agents.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={5} className="py-8 text-center text-muted-foreground">
                                                    No {coacheeRole === 'Team Lead' ? 'team leads' : 'agents'} found.
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            groupedAgents.map(([account, agents]) => (
                                                <Fragment key={account}>
                                                    <TableRow className="bg-muted/30">
                                                        <TableCell colSpan={5} className="py-2">
                                                            <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                                                {account} ({agents.length})
                                                            </span>
                                                        </TableCell>
                                                    </TableRow>
                                                    {agents.map((agent) => (
                                                        <TableRow key={agent.id}>
                                                            <TableCell className="pl-6 font-medium">{agent.name}</TableCell>
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
                                                        </TableRow>
                                                    ))}
                                                </Fragment>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </div>
                        </div>

                        {/* Mobile Cards */}
                        <div className="space-y-4 md:hidden">
                            {activeData.agents.length === 0 ? (
                                <div className="py-8 text-center text-muted-foreground">No {coacheeRole === 'Team Lead' ? 'team leads' : 'agents'} found.</div>
                            ) : (
                                groupedAgents.map(([account, agents]) => (
                                    <div key={account} className="space-y-2">
                                        <h4 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                            {account} ({agents.length})
                                        </h4>
                                        {agents.map((agent) => (
                                            <div key={agent.id} className="rounded-lg border bg-card p-4 shadow-sm space-y-2">
                                                <div className="flex items-start justify-between">
                                                    <p className="font-medium">{agent.name}</p>
                                                    <CoachingStatusBadge status={agent.coaching_status} />
                                                </div>
                                                <div className="flex flex-wrap gap-3 text-xs text-muted-foreground">
                                                    <span>Last: {agent.last_coached_date ? new Date(agent.last_coached_date).toLocaleDateString() : 'Never'}</span>
                                                    <span>Sessions: {agent.total_sessions}</span>
                                                    {agent.pending_acknowledgements > 0 && (
                                                        <span className="font-medium text-amber-600">{agent.pending_acknowledgements} Pending</span>
                                                    )}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                ))
                            )}
                        </div>
                    </div>
                )}

                {activeTab === 'unacknowledged' && (
                    <SessionQueueTable
                        title="Unacknowledged Sessions"
                        icon={<Clock className="h-4 w-4" />}
                        sessions={queueData.unacknowledged}
                        purposes={purposes}
                        formatName={formatName}
                        emptyMessage="No unacknowledged sessions."
                    />
                )}

                {activeTab === 'for_review' && (
                    <div className="space-y-2">
                        <h3 className="flex items-center gap-2 text-sm font-semibold">
                            <ClipboardCheck className="h-4 w-4" /> Sessions For Review ({queueData.for_review.length})
                        </h3>

                        {/* Desktop Table */}
                        <div className="hidden overflow-hidden rounded-md shadow md:block">
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow className="bg-muted/50">
                                            <TableHead>Date</TableHead>
                                            <TableHead>Coachee</TableHead>
                                            <TableHead>Coach</TableHead>
                                            <TableHead>Purpose</TableHead>
                                            <TableHead>Severity</TableHead>
                                            <TableHead className="text-center">Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {queueData.for_review.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={6} className="py-8 text-center text-muted-foreground">
                                                    No sessions pending review.
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            queueData.for_review.map((session) => (
                                                <TableRow key={session.id}>
                                                    <TableCell className="whitespace-nowrap">
                                                        {new Date(session.session_date).toLocaleDateString()}
                                                    </TableCell>
                                                    <TableCell className="font-medium">{formatName(session.coachee)}</TableCell>
                                                    <TableCell>{formatName(session.coach)}</TableCell>
                                                    <TableCell>{purposes[session.purpose] ?? session.purpose}</TableCell>
                                                    <TableCell>
                                                        <SeverityBadge flag={session.severity_flag} />
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="flex items-center justify-center gap-1">
                                                            <Link href={sessionsShow.url(session.id)}>
                                                                <Button variant="ghost" size="icon" title="View">
                                                                    <Eye className="h-4 w-4" />
                                                                </Button>
                                                            </Link>
                                                            <ReviewDialog
                                                                sessionId={session.id}
                                                                reviewForm={reviewForm}
                                                                onReview={handleReview}
                                                            />
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </div>
                        </div>

                        {/* Mobile Cards */}
                        <div className="space-y-3 md:hidden">
                            {queueData.for_review.length === 0 ? (
                                <div className="py-8 text-center text-muted-foreground">No sessions pending review.</div>
                            ) : (
                                queueData.for_review.map((session) => (
                                    <div key={session.id} className="rounded-lg border bg-card p-4 shadow-sm space-y-3">
                                        <div className="flex items-start justify-between gap-2">
                                            <div>
                                                <p className="text-xs text-muted-foreground">{new Date(session.session_date).toLocaleDateString()}</p>
                                                <p className="text-sm font-medium">{formatName(session.coachee)}</p>
                                                <p className="text-xs text-muted-foreground">Coach: {formatName(session.coach)}</p>
                                            </div>
                                            <SeverityBadge flag={session.severity_flag} />
                                        </div>
                                        <div className="flex flex-col gap-2 sm:flex-row">
                                            <Link href={sessionsShow.url(session.id)} className="flex-1">
                                                <Button variant="outline" size="sm" className="w-full">
                                                    <Eye className="mr-1.5 h-3.5 w-3.5" /> View
                                                </Button>
                                            </Link>
                                            <ReviewDialog
                                                sessionId={session.id}
                                                reviewForm={reviewForm}
                                                onReview={handleReview}
                                                mobile
                                            />
                                        </div>
                                    </div>
                                ))
                            )}
                        </div>
                    </div>
                )}

                {activeTab === 'at_risk' && (
                    <div className="space-y-2">
                        <h3 className="flex items-center gap-2 text-sm font-semibold">
                            <AlertTriangle className="h-4 w-4" /> At-Risk ({queueData.at_risk_agents.length})
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
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {queueData.at_risk_agents.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={3} className="py-8 text-center text-muted-foreground">
                                                    No at-risk agents found.
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            queueData.at_risk_agents.map((agent) => (
                                                <TableRow key={agent.id}>
                                                    <TableCell className="font-medium">{agent.name}</TableCell>
                                                    <TableCell>{agent.account}</TableCell>
                                                    <TableCell>
                                                        <CoachingStatusBadge status={agent.coaching_status} />
                                                    </TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </div>
                        </div>

                        {/* Mobile Cards */}
                        <div className="space-y-3 md:hidden">
                            {queueData.at_risk_agents.length === 0 ? (
                                <div className="py-8 text-center text-muted-foreground">No at-risk agents.</div>
                            ) : (
                                queueData.at_risk_agents.map((agent) => (
                                    <div key={agent.id} className="flex items-center justify-between rounded-lg border bg-card p-4 shadow-sm">
                                        <div>
                                            <p className="font-medium">{agent.name}</p>
                                            <p className="text-xs text-muted-foreground">{agent.account}</p>
                                        </div>
                                        <CoachingStatusBadge status={agent.coaching_status} />
                                    </div>
                                ))
                            )}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

// ─── Sub-components ─────────────────────────────────────────────

function SessionQueueTable({
    title,
    icon,
    sessions,
    purposes,
    formatName,
    emptyMessage,
}: {
    title: string;
    icon: React.ReactNode;
    sessions: CoachingSession[];
    purposes: CoachingPurposeLabels;
    formatName: (user?: { first_name: string; last_name: string } | null) => string;
    emptyMessage: string;
}) {
    return (
        <div className="space-y-2">
            <h3 className="flex items-center gap-2 text-sm font-semibold">
                {icon} {title} ({sessions.length})
            </h3>

            {/* Desktop Table */}
            <div className="hidden overflow-hidden rounded-md shadow md:block">
                <div className="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow className="bg-muted/50">
                                <TableHead>Date</TableHead>
                                <TableHead>Coachee</TableHead>
                                <TableHead>Coach</TableHead>
                                <TableHead>Purpose</TableHead>
                                <TableHead>Severity</TableHead>
                                <TableHead className="text-center">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {sessions.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={6} className="py-8 text-center text-muted-foreground">
                                        {emptyMessage}
                                    </TableCell>
                                </TableRow>
                            ) : (
                                sessions.map((session) => (
                                    <TableRow key={session.id}>
                                        <TableCell className="whitespace-nowrap">
                                            {new Date(session.session_date).toLocaleDateString()}
                                        </TableCell>
                                        <TableCell className="font-medium">{formatName(session.coachee)}</TableCell>
                                        <TableCell>{formatName(session.coach)}</TableCell>
                                        <TableCell>{purposes[session.purpose] ?? session.purpose}</TableCell>
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
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>
            </div>

            {/* Mobile Cards */}
            <div className="space-y-3 md:hidden">
                {sessions.length === 0 ? (
                    <div className="py-8 text-center text-muted-foreground">{emptyMessage}</div>
                ) : (
                    sessions.map((session) => (
                        <div key={session.id} className="rounded-lg border bg-card p-3 shadow-sm">
                            <div className="flex items-start justify-between gap-2">
                                <div>
                                    <p className="text-xs text-muted-foreground">{new Date(session.session_date).toLocaleDateString()}</p>
                                    <p className="text-sm font-medium">{formatName(session.coachee)}</p>
                                    <p className="text-xs text-muted-foreground">Coach: {formatName(session.coach)}</p>
                                </div>
                                <SeverityBadge flag={session.severity_flag} />
                            </div>
                            <Link href={sessionsShow.url(session.id)}>
                                <Button variant="outline" size="sm" className="mt-2 w-full">
                                    <Eye className="mr-1.5 h-3.5 w-3.5" /> View
                                </Button>
                            </Link>
                        </div>
                    ))
                )}
            </div>
        </div>
    );
}

function ReviewDialog({
    sessionId,
    reviewForm,
    onReview,
    mobile = false,
}: {
    sessionId: number;
    reviewForm: ReturnType<typeof useForm<{ compliance_status: string; compliance_notes: string }>>;
    onReview: (sessionId: number) => void;
    mobile?: boolean;
}) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                {mobile ? (
                    <Button size="sm" className="flex-1 bg-blue-600 hover:bg-blue-700 text-white">
                        <ShieldCheck className="mr-1.5 h-3.5 w-3.5" /> Review
                    </Button>
                ) : (
                    <Button variant="ghost" size="icon" className="text-blue-600 hover:text-blue-700" title="Review">
                        <ShieldCheck className="h-4 w-4" />
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent className="max-w-[90vw] sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Review Coaching Session</DialogTitle>
                    <DialogDescription>
                        Verify or reject this coaching session for compliance.
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-3 py-2">
                    <div>
                        <Label htmlFor={`review-status-${sessionId}`}>Decision</Label>
                        <Select
                            value={reviewForm.data.compliance_status}
                            onValueChange={(val) => reviewForm.setData('compliance_status', val)}
                        >
                            <SelectTrigger id={`review-status-${sessionId}`}>
                                <SelectValue placeholder="Select decision" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="Verified">
                                    <span className="flex items-center gap-2">
                                        <ShieldCheck className="h-4 w-4 text-green-600" /> Verify
                                    </span>
                                </SelectItem>
                                <SelectItem value="Rejected">
                                    <span className="flex items-center gap-2">
                                        <ShieldX className="h-4 w-4 text-red-600" /> Reject
                                    </span>
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        {reviewForm.errors.compliance_status && (
                            <p className="text-sm text-red-600">{reviewForm.errors.compliance_status}</p>
                        )}
                    </div>
                    <div>
                        <Label htmlFor={`review-notes-${sessionId}`}>Notes (optional)</Label>
                        <Textarea
                            id={`review-notes-${sessionId}`}
                            value={reviewForm.data.compliance_notes}
                            onChange={(e) => reviewForm.setData('compliance_notes', e.target.value)}
                            placeholder="Add compliance review notes..."
                            rows={3}
                        />
                    </div>
                </div>
                <DialogFooter>
                    <Button
                        onClick={() => onReview(sessionId)}
                        disabled={reviewForm.processing || !reviewForm.data.compliance_status}
                        className="bg-blue-600 hover:bg-blue-700"
                    >
                        {reviewForm.processing ? 'Submitting...' : 'Submit Review'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
