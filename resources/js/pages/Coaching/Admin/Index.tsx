import { useState } from 'react';
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
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
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
    team_lead_id?: string;
    coaching_status?: string;
    date_from?: string;
    date_to?: string;
}

interface Props extends InertiaPageProps {
    dashboardData: DashboardData;
    queueData: QueueData;
    campaigns: Campaign[];
    teamLeads: User[];
    filters: Filters;
    statusColors: CoachingStatusColors;
    purposes: CoachingPurposeLabels;
}

type TabKey = 'overview' | 'unacknowledged' | 'for_review' | 'at_risk';

export default function CoachingAdminIndex() {
    const { dashboardData, queueData, campaigns, teamLeads, filters: initialFilters, purposes } = usePage<Props>().props;

    const { title, breadcrumbs } = usePageMeta({
        title: 'Coaching Compliance',
        breadcrumbs: [{ title: 'Coaching Compliance', href: coachingDashboard().url }],
    });
    useFlashMessage();
    const isLoading = usePageLoading();

    const [activeTab, setActiveTab] = useState<TabKey>('overview');
    const [campaignId, setCampaignId] = useState(initialFilters.campaign_id || '');
    const [teamLeadId, setTeamLeadId] = useState(initialFilters.team_lead_id || '');
    const [coachingStatus, setCoachingStatus] = useState(initialFilters.coaching_status || '');
    const [dateFrom, setDateFrom] = useState(initialFilters.date_from || '');
    const [dateTo, setDateTo] = useState(initialFilters.date_to || '');

    const reviewForm = useForm({ compliance_status: '' as string, compliance_notes: '' });

    const handleFilter = () => {
        router.get(
            coachingDashboard().url,
            {
                campaign_id: campaignId || undefined,
                team_lead_id: teamLeadId || undefined,
                coaching_status: coachingStatus || undefined,
                date_from: dateFrom || undefined,
                date_to: dateTo || undefined,
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    const handleReset = () => {
        setCampaignId('');
        setTeamLeadId('');
        setCoachingStatus('');
        setDateFrom('');
        setDateTo('');
        router.get(coachingDashboard().url);
    };

    const handleReview = (sessionId: number, e?: React.MouseEvent) => {
        e?.preventDefault();
        reviewForm.patch(sessionsReview(sessionId).url, {
            onSuccess: () => reviewForm.reset(),
        });
    };

    const formatName = (user?: { first_name: string; last_name: string } | null) => {
        if (!user) return 'N/A';
        return `${user.first_name} ${user.last_name}`;
    };

    const tabs: { key: TabKey; label: string; icon: React.ComponentType<{ className?: string }>; count?: number }[] = [
        { key: 'overview', label: 'Overview', icon: Users, count: dashboardData.agents.length },
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
                <CoachingSummaryCards totalAgents={dashboardData.total_agents} statusCounts={dashboardData.status_counts} />

                {/* Filters */}
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-7">
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
                    <Select value={teamLeadId} onValueChange={setTeamLeadId}>
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
                            <Users className="h-4 w-4" /> All Agents ({dashboardData.agents.length})
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
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {dashboardData.agents.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={6} className="py-8 text-center text-muted-foreground">
                                                    No agents found.
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
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </div>
                        </div>

                        {/* Mobile Cards */}
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
                                            <TableHead>Agent</TableHead>
                                            <TableHead>Team Lead</TableHead>
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
                                                    <TableCell className="font-medium">{formatName(session.agent)}</TableCell>
                                                    <TableCell>{formatName(session.team_lead)}</TableCell>
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
                                                <p className="text-sm font-medium">{formatName(session.agent)}</p>
                                                <p className="text-xs text-muted-foreground">TL: {formatName(session.team_lead)}</p>
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
                            <AlertTriangle className="h-4 w-4" /> At-Risk Agents ({queueData.at_risk_agents.length})
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
                                <TableHead>Agent</TableHead>
                                <TableHead>Team Lead</TableHead>
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
                                        <TableCell className="font-medium">{formatName(session.agent)}</TableCell>
                                        <TableCell>{formatName(session.team_lead)}</TableCell>
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
                                    <p className="text-sm font-medium">{formatName(session.agent)}</p>
                                    <p className="text-xs text-muted-foreground">TL: {formatName(session.team_lead)}</p>
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
    onReview: (sessionId: number, e?: React.MouseEvent) => void;
    mobile?: boolean;
}) {
    return (
        <AlertDialog>
            <AlertDialogTrigger asChild>
                {mobile ? (
                    <Button size="sm" className="flex-1 bg-blue-600 hover:bg-blue-700 text-white">
                        <ShieldCheck className="mr-1.5 h-3.5 w-3.5" /> Review
                    </Button>
                ) : (
                    <Button variant="ghost" size="icon" className="text-blue-600 hover:text-blue-700" title="Review">
                        <ShieldCheck className="h-4 w-4" />
                    </Button>
                )}
            </AlertDialogTrigger>
            <AlertDialogContent className="max-w-[90vw] sm:max-w-md">
                <AlertDialogHeader>
                    <AlertDialogTitle>Review Coaching Session</AlertDialogTitle>
                    <AlertDialogDescription>
                        Verify or reject this coaching session for compliance.
                    </AlertDialogDescription>
                </AlertDialogHeader>
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
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <AlertDialogAction
                        onClick={(e) => onReview(sessionId, e)}
                        disabled={reviewForm.processing || !reviewForm.data.compliance_status}
                        className="bg-blue-600 hover:bg-blue-700"
                    >
                        {reviewForm.processing ? 'Submitting...' : 'Submit Review'}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
