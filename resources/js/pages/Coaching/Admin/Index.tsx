import { Fragment, useMemo, useState } from 'react';
import { Head, Link, usePage, router, useForm } from '@inertiajs/react';
import type { PageProps as InertiaPageProps } from '@inertiajs/core';
import {
    AlertTriangle,
    BarChart3,
    Calendar as CalendarIcon,
    CalendarClock,
    CheckCircle,
    Eye,
    Filter,
    Plus,
    ShieldCheck,
    ShieldX,
    Users,
    Clock,
    ClipboardCheck,
    ChevronDown,
} from 'lucide-react';

import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { Calendar } from '@/components/ui/calendar';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
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
    create as sessionsCreate,
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
    teamLeadCoachingData: DashboardData;
    queueData: QueueData;
    upcomingFollowUps: FollowUp[];
    overdueFollowUps: FollowUp[];
    followUpComplianceRate?: { rate: number; completed: number; total: number };
    campaigns: Campaign[];
    teamLeads: User[];
    filters: Filters;
    statusColors: CoachingStatusColors;
    purposes: CoachingPurposeLabels;
}

type TabKey = 'overview' | 'unacknowledged' | 'for_review' | 'at_risk' | 'upcoming';

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

const getAgingLabel = (dateStr: string): { text: string; className: string } => {
    const days = Math.floor((Date.now() - new Date(dateStr).getTime()) / (1000 * 60 * 60 * 24));
    if (days <= 2) return { text: `${days}d ago`, className: 'text-green-600' };
    if (days <= 5) return { text: `${days}d ago`, className: 'text-amber-600' };
    return { text: `${days}d ago`, className: 'text-red-600 font-semibold' };
};

const getStatusRowClass = (status: string): string => {
    switch (status) {
        case 'Please Coach ASAP': return 'bg-red-50/50 dark:bg-red-950/20';
        case 'Badly Needs Coaching': return 'bg-orange-50/50 dark:bg-orange-950/20';
        case 'Needs Coaching': return 'bg-yellow-50/50 dark:bg-yellow-950/20';
        case 'Coaching Done': return 'bg-green-50/50 dark:bg-green-950/20';
        case 'Draft': return 'bg-blue-50/50 dark:bg-blue-950/20';
        default: return '';
    }
};

export default function CoachingAdminIndex() {
    const { dashboardData, teamLeadCoachingData, queueData, upcomingFollowUps, overdueFollowUps, followUpComplianceRate, campaigns, teamLeads, filters: initialFilters, purposes } = usePage<Props>().props;

    const { title, breadcrumbs } = usePageMeta({
        title: 'Coaching Compliance',
        breadcrumbs: [{ title: 'Coaching Compliance', href: coachingDashboard().url }],
    });
    useFlashMessage();
    const isLoading = usePageLoading();

    const [activeTab, setActiveTab] = useState<TabKey>('overview');
    const [campaignCompletionOpen, setCampaignCompletionOpen] = useState(false);
    const [selectedForReview, setSelectedForReview] = useState<number[]>([]);
    const [campaignId, setCampaignId] = useState(initialFilters.campaign_id || '');
    const [coachId, setCoachId] = useState(initialFilters.coach_id || '');
    const [coachingStatus, setCoachingStatus] = useState(initialFilters.coaching_status || '');
    const [coacheeRole, setCoacheeRole] = useState(initialFilters.coachee_role || 'Agent');
    const [dateFrom, setDateFrom] = useState(initialFilters.date_from || '');
    const [dateTo, setDateTo] = useState(initialFilters.date_to || '');

    const reviewForm = useForm({ compliance_status: '' as string, compliance_notes: '' });

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

    const handleCoacheeRoleChange = (value: string) => {
        setCoacheeRole(value);
        const newCoachId = value === 'Team Lead' ? '' : coachId;
        if (value === 'Team Lead') {
            setCoachId('');
        }
        router.get(
            coachingDashboard().url,
            {
                campaign_id: campaignId || undefined,
                coach_id: newCoachId || undefined,
                coaching_status: coachingStatus || undefined,
                coachee_role: value || undefined,
                date_from: dateFrom || undefined,
                date_to: dateTo || undefined,
            },
            { preserveState: true, preserveScroll: true },
        );
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

    const teamLeadSummary = useMemo(() => {
        if (coacheeRole === 'Team Lead') return [];
        return groupedAgents.map(([account, agents]) => {
            const total = agents.length;
            const coached = agents.filter(a => a.coaching_status === 'Coaching Done').length;
            const atRisk = agents.filter(a => ['Please Coach ASAP', 'Badly Needs Coaching', 'No Record'].includes(a.coaching_status)).length;
            const rate = total > 0 ? Math.round((coached / total) * 100) : 0;
            return { account, total, coached, atRisk, rate };
        }).sort((a, b) => b.rate - a.rate);
    }, [groupedAgents, coacheeRole]);

    const handleBulkVerify = () => {
        if (!confirm(`Verify ${selectedForReview.length} sessions?`)) return;

        selectedForReview.forEach((sessionId) => {
            router.patch(sessionsReview(sessionId).url, {
                compliance_status: 'Verified',
                compliance_notes: 'Bulk verified by admin',
            }, { preserveState: true, preserveScroll: true });
        });
        setSelectedForReview([]);
    };

    const tabs: { key: TabKey; label: string; icon: React.ComponentType<{ className?: string }>; count?: number; overdueCount?: number }[] = [
        { key: 'overview', label: 'Overview', icon: Users, count: activeData.agents.length },
        { key: 'unacknowledged', label: 'Unacknowledged', icon: Clock, count: queueData.unacknowledged.length },
        { key: 'for_review', label: 'For Review', icon: ClipboardCheck, count: queueData.for_review.length },
        { key: 'at_risk', label: 'At Risk', icon: AlertTriangle, count: queueData.at_risk_agents.length },
        { key: 'upcoming', label: 'Upcoming', icon: CalendarClock, count: allFollowUps.length, overdueCount: overdueFollowUps.length },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="relative flex h-full flex-1 flex-col gap-4 rounded-xl p-3">
                <LoadingOverlay isLoading={isLoading} />

                <PageHeader title="Coaching Compliance Dashboard" />

                {/* Summary Cards */}
                {isLoading ? (
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                        {Array.from({ length: 6 }).map((_, i) => (
                            <div key={i} className="h-20 animate-pulse rounded-lg bg-muted" />
                        ))}
                    </div>
                ) : (
                    <CoachingSummaryCards totalAgents={activeData.total_agents} statusCounts={activeData.status_counts} totalLabel={coacheeRole === 'Team Lead' ? 'Total Team Leads' : 'Total Agents'} />
                )}

                {/* Follow-up Compliance Rate */}
                {followUpComplianceRate && followUpComplianceRate.total > 0 && (
                    <div className="rounded-lg border bg-card p-4 shadow-sm">
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="text-sm font-medium text-muted-foreground">Follow-up Compliance</p>
                                <p className="text-2xl font-bold">{followUpComplianceRate.rate}%</p>
                            </div>
                            <div className="text-right text-xs text-muted-foreground">
                                <p>{followUpComplianceRate.completed} of {followUpComplianceRate.total}</p>
                                <p>follow-ups completed</p>
                            </div>
                        </div>
                        <div className="mt-2 h-2 w-full overflow-hidden rounded-full bg-muted">
                            <div
                                className={`h-full rounded-full transition-all ${followUpComplianceRate.rate >= 80 ? 'bg-green-500' :
                                    followUpComplianceRate.rate >= 50 ? 'bg-amber-500' : 'bg-red-500'
                                    }`}
                                style={{ width: `${followUpComplianceRate.rate}%` }}
                            />
                        </div>
                    </div>
                )}

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
                            <SelectItem value="Draft">Draft</SelectItem>
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
                    {tabs.map(({ key, label, icon: Icon, count, overdueCount }) => (
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
                            {(overdueCount ?? 0) > 0 && (
                                <Badge variant="destructive" className="ml-0.5 px-1 py-0 text-[10px]">
                                    {overdueCount}
                                </Badge>
                            )}
                        </button>
                    ))}
                </div>

                {/* Tab Content */}
                {activeTab === 'overview' && (
                    <div className="space-y-2">
                        {coacheeRole !== 'Team Lead' && teamLeadSummary.length > 1 && (
                            <div className="rounded-lg border bg-card p-4 shadow-sm">
                                <button
                                    type="button"
                                    onClick={() => setCampaignCompletionOpen((v) => !v)}
                                    className="flex w-full items-center justify-between text-sm font-semibold"
                                >
                                    <span className="flex items-center gap-2">
                                        <BarChart3 className="h-4 w-4" /> Campaign Coaching Completion
                                    </span>
                                    <ChevronDown className={`h-4 w-4 transition-transform ${campaignCompletionOpen ? 'rotate-180' : ''}`} />
                                </button>
                                {campaignCompletionOpen && <div className="mt-3 space-y-2">
                                    {teamLeadSummary.map((tl) => (
                                        <div key={tl.account} className="flex items-center gap-3">
                                            <span className="w-32 truncate text-sm font-medium">{tl.account}</span>
                                            <div className="flex-1">
                                                <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                                                    <div
                                                        className="h-full rounded-full bg-primary transition-all"
                                                        style={{ width: `${tl.rate}%` }}
                                                    />
                                                </div>
                                            </div>
                                            <span className="w-12 text-right text-xs font-medium">{tl.rate}%</span>
                                            {tl.atRisk > 0 && (
                                                <Badge variant="destructive" className="text-[10px]">{tl.atRisk} at risk</Badge>
                                            )}
                                        </div>
                                    ))}
                                </div>}
                            </div>
                        )}

                        <h3 className="flex items-center gap-2 text-sm font-semibold">
                            <Users className="h-4 w-4" /> All {coacheeRole === 'Team Lead' ? 'Team Leads' : 'Agents'} ({activeData.agents.length})
                        </h3>

                        {/* Desktop Table */}
                        <div className="hidden overflow-hidden rounded-md shadow md:block">
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader className="sticky top-0 z-10">
                                        <TableRow className="bg-muted/50">
                                            <TableHead>{coacheeRole === 'Team Lead' ? 'Team Lead' : 'Agent'}</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Last Coached</TableHead>
                                            <TableHead>Sessions</TableHead>
                                            <TableHead>Pending Ack</TableHead>
                                            {coacheeRole === 'Team Lead' && (
                                                <TableHead className="text-center">Actions</TableHead>
                                            )}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {activeData.agents.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={coacheeRole === 'Team Lead' ? 6 : 5} className="py-8 text-center text-muted-foreground">
                                                    No {coacheeRole === 'Team Lead' ? 'team leads' : 'agents'} found.
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            groupedAgents.map(([account, agents]) => (
                                                <Fragment key={account}>
                                                    <TableRow className="bg-muted/30">
                                                        <TableCell colSpan={coacheeRole === 'Team Lead' ? 6 : 5} className="py-2">
                                                            <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                                                {account} ({agents.length})
                                                            </span>
                                                        </TableCell>
                                                    </TableRow>
                                                    {agents.map((agent) => (
                                                        <TableRow key={agent.id} className={getStatusRowClass(agent.coaching_status)}>
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
                                                            {coacheeRole === 'Team Lead' && (
                                                                <TableCell>
                                                                    <div className="flex items-center justify-center">
                                                                        <Link href={sessionsCreate().url + `?agent_id=${agent.id}&coaching_mode=direct`}>
                                                                            <Button variant="ghost" size="icon" title={`Coach ${agent.name}`}>
                                                                                <Plus className="h-4 w-4" />
                                                                            </Button>
                                                                        </Link>
                                                                    </div>
                                                                </TableCell>
                                                            )}
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
                                            <div key={agent.id} className={`rounded-lg border bg-card p-4 shadow-sm space-y-2 ${getStatusRowClass(agent.coaching_status)}`}>
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
                                                {coacheeRole === 'Team Lead' && (
                                                    <Link href={sessionsCreate().url + `?agent_id=${agent.id}&coaching_mode=direct`}>
                                                        <Button size="sm" variant="outline" className="w-full">
                                                            <Plus className="mr-1.5 h-3.5 w-3.5" /> Coach
                                                        </Button>
                                                    </Link>
                                                )}
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

                        {selectedForReview.length > 0 && (
                            <div className="flex items-center gap-3 rounded-lg border bg-primary/5 p-3">
                                <span className="text-sm font-medium">{selectedForReview.length} session(s) selected</span>
                                <Button size="sm" onClick={handleBulkVerify}>
                                    <CheckCircle className="mr-1.5 h-4 w-4" /> Bulk Verify
                                </Button>
                                <Button size="sm" variant="outline" onClick={() => setSelectedForReview([])}>
                                    Clear
                                </Button>
                            </div>
                        )}

                        {/* Desktop Table */}
                        <div className="hidden overflow-hidden rounded-md shadow md:block">
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader className="sticky top-0 z-10">
                                        <TableRow className="bg-muted/50">
                                            <TableHead className="w-10">
                                                <input
                                                    type="checkbox"
                                                    aria-label="Select all sessions"
                                                    className="rounded border-gray-300"
                                                    checked={selectedForReview.length === queueData.for_review.length && queueData.for_review.length > 0}
                                                    onChange={(e) => {
                                                        if (e.target.checked) {
                                                            setSelectedForReview(queueData.for_review.map(s => s.id));
                                                        } else {
                                                            setSelectedForReview([]);
                                                        }
                                                    }}
                                                />
                                            </TableHead>
                                            <TableHead>Date</TableHead>
                                            <TableHead>Coachee</TableHead>
                                            <TableHead>Coach</TableHead>
                                            <TableHead>Purpose</TableHead>
                                            <TableHead>Severity</TableHead>
                                            <TableHead>Age</TableHead>
                                            <TableHead className="text-center">Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {queueData.for_review.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={8} className="py-8 text-center text-muted-foreground">
                                                    No sessions pending review.
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            queueData.for_review.map((session) => {
                                                const aging = getAgingLabel(session.session_date);
                                                return (
                                                    <TableRow key={session.id}>
                                                        <TableCell>
                                                            <input
                                                                type="checkbox"
                                                                aria-label={`Select session ${session.id}`}
                                                                className="rounded border-gray-300"
                                                                checked={selectedForReview.includes(session.id)}
                                                                onChange={(e) => {
                                                                    if (e.target.checked) {
                                                                        setSelectedForReview(prev => [...prev, session.id]);
                                                                    } else {
                                                                        setSelectedForReview(prev => prev.filter(id => id !== session.id));
                                                                    }
                                                                }}
                                                            />
                                                        </TableCell>
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
                                                            <span className={`text-xs font-medium ${aging.className}`}>{aging.text}</span>
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
                                                );
                                            })
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
                                queueData.for_review.map((session) => {
                                    const aging = getAgingLabel(session.session_date);
                                    return (
                                        <div key={session.id} className="rounded-lg border bg-card p-4 shadow-sm space-y-3">
                                            <div className="flex items-start justify-between gap-2">
                                                <div>
                                                    <p className="text-xs text-muted-foreground">{new Date(session.session_date).toLocaleDateString()} <span className={`ml-1 ${aging.className}`}>{aging.text}</span></p>
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
                                    );
                                })
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
                                    <TableHeader className="sticky top-0 z-10">
                                        <TableRow className="bg-muted/50">
                                            <TableHead>{coacheeRole === 'Team Lead' ? 'Team Lead' : 'Agent'}</TableHead>
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
                                                <TableRow key={agent.id} className={getStatusRowClass(agent.coaching_status)}>
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
                                    <div key={agent.id} className={`flex items-center justify-between rounded-lg border bg-card p-4 shadow-sm ${getStatusRowClass(agent.coaching_status)}`}>
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

                {activeTab === 'upcoming' && (
                    <Tabs defaultValue="upcoming" className="space-y-4" onValueChange={(v) => { if (v === 'calendar') setCalendarSelectedDate(undefined); }}>
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
                            <FollowUpTable items={upcomingFollowUps} emptyMessage="No upcoming follow-ups scheduled." emptyDescription="Follow-up dates are set when creating coaching sessions." />
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
                                        modifiers={{
                                            upcoming: upcomingFollowUps.map(f => new Date(f.follow_up_date + 'T00:00:00')),
                                            overdue: overdueFollowUps.map(f => new Date(f.follow_up_date + 'T00:00:00')),
                                        }}
                                        modifiersClassNames={{
                                            upcoming: 'ring-2 ring-green-400/60 ring-offset-1',
                                            overdue: 'ring-2 ring-red-400/60 ring-offset-1',
                                        }}
                                    />
                                    <div className="flex items-center gap-4 border-t px-3 pt-2 text-[10px] text-muted-foreground">
                                        <span className="flex items-center gap-1"><span className="inline-block h-2 w-2 rounded-full ring-2 ring-green-400/60" /> Upcoming</span>
                                        <span className="flex items-center gap-1"><span className="inline-block h-2 w-2 rounded-full ring-2 ring-red-400/60" /> Overdue</span>
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
                        <TableHeader className="sticky top-0 z-10">
                            <TableRow className="bg-muted/50">
                                <TableHead>Date</TableHead>
                                <TableHead>Coachee</TableHead>
                                <TableHead>Coach</TableHead>
                                <TableHead>Purpose</TableHead>
                                <TableHead>Severity</TableHead>
                                <TableHead>Age</TableHead>
                                <TableHead className="text-center">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {sessions.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={7} className="py-8 text-center text-muted-foreground">
                                        {emptyMessage}
                                    </TableCell>
                                </TableRow>
                            ) : (
                                sessions.map((session) => {
                                    const aging = getAgingLabel(session.session_date);
                                    return (
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
                                                <span className={`text-xs font-medium ${aging.className}`}>{aging.text}</span>
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
                                    );
                                })
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
                    sessions.map((session) => {
                        const aging = getAgingLabel(session.session_date);
                        return (
                            <div key={session.id} className="rounded-lg border bg-card p-3 shadow-sm">
                                <div className="flex items-start justify-between gap-2">
                                    <div>
                                        <p className="text-xs text-muted-foreground">{new Date(session.session_date).toLocaleDateString()} <span className={`ml-1 ${aging.className}`}>{aging.text}</span></p>
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
                        );
                    })
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
                        <TableHeader className="sticky top-0 z-10">
                            <TableRow className="bg-muted/50">
                                <TableHead>Follow-up Date</TableHead>
                                <TableHead>Coachee</TableHead>
                                <TableHead>Coach</TableHead>
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
                                        <TableCell>{item.team_lead_name}</TableCell>
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
                            <p className="text-xs text-muted-foreground truncate">Coach: {item.team_lead_name}</p>
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
