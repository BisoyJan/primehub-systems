import { useState, useMemo } from 'react';
import { Head, Link, useForm, usePage, router } from '@inertiajs/react';
import type { PageProps as InertiaPageProps } from '@inertiajs/core';

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
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import PaginationNav, { type PaginationLink } from '@/components/pagination-nav';
import { Check, ChevronsUpDown, Filter, Eye, Pencil, Trash2 } from 'lucide-react';

import { usePageMeta, useFlashMessage, usePageLoading, usePermission } from '@/hooks';
import { PageHeader } from '@/components/PageHeader';
import { DeleteConfirmDialog } from '@/components/DeleteConfirmDialog';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import {
    CoachingStatusBadge,
    AckStatusBadge,
    ComplianceStatusBadge,
    SeverityBadge,
} from '@/components/coaching/CoachingStatusBadge';
import { CoachingSessionCard } from '@/components/coaching/CoachingSessionCard';

import {
    index as sessionsIndex,
    create as sessionsCreate,
    show as sessionsShow,
    edit as sessionsEdit,
    destroy as sessionsDestroy,
} from '@/routes/coaching/sessions';

import type {
    CoachingSession,
    CoachingSummary,
    CoachingPurposeLabels,
    Campaign,
    User,
} from '@/types';

interface PaginatedSessions {
    data: CoachingSession[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    total: number;
}

interface Filters {
    search?: string;
    ack_status?: string;
    compliance_status?: string;
    purpose?: string;
    campaign_id?: string;
    date_from?: string;
    date_to?: string;
    coachee_role?: string;
}

interface Props extends InertiaPageProps {
    sessions: PaginatedSessions;
    agentSummary: CoachingSummary | null;
    campaigns: Campaign[];
    allAgents: User[];
    filters: Filters;
    isAdmin: boolean;
    isTeamLead: boolean;
    isAgent: boolean;
    teamLeadCampaignId: number | null;
    activeTab: string | null;
    pendingAckCount: number | null;
    pendingReviewCount: number | null;
}

const purposes: CoachingPurposeLabels = {
    performance_behavior_issue: 'Performance/Behavior Issue',
    regular_checkin_progress_review: 'Regular Check-in',
    reinforce_positive_behavior_growth: 'Positive Behavior/Growth',
    recognition_appreciation: 'Recognition/Appreciation',
};

export default function CoachingSessionsIndex() {
    const {
        sessions,
        agentSummary,
        campaigns,
        allAgents,
        filters: initialFilters,
        isAdmin,
        isTeamLead,
        isAgent,
        activeTab,
        pendingAckCount,
        pendingReviewCount,
    } = usePage<Props>().props;

    const { title, breadcrumbs } = usePageMeta({
        title: 'Coaching Sessions',
        breadcrumbs: [{ title: 'Coaching Sessions', href: sessionsIndex().url }],
    });
    useFlashMessage();
    const isLoading = usePageLoading();
    const { can } = usePermission();

    const [search, setSearch] = useState(initialFilters.search || '');
    const [agentSearchOpen, setAgentSearchOpen] = useState(false);
    const [agentSearchQuery, setAgentSearchQuery] = useState('');
    const [ackStatus, setAckStatus] = useState(initialFilters.ack_status || '');
    const [complianceStatus, setComplianceStatus] = useState(initialFilters.compliance_status || '');
    const [purpose, setPurpose] = useState(initialFilters.purpose || '');
    const [campaignId, setCampaignId] = useState(initialFilters.campaign_id || '');
    const [dateFrom, setDateFrom] = useState(initialFilters.date_from || '');
    const [dateTo, setDateTo] = useState(initialFilters.date_to || '');
    const [coacheeRole, setCoacheeRole] = useState(initialFilters.coachee_role || '');

    const deleteForm = useForm({});

    const getAgentCampaign = (agent: User): string | null => {
        const schedule = (agent as Record<string, unknown>).active_schedule as { campaign?: { name?: string } } | null;
        return schedule?.campaign?.name ?? null;
    };

    const filteredAgents = useMemo(() => {
        if (!agentSearchQuery) return allAgents.slice(0, 50);
        const q = agentSearchQuery.toLowerCase();
        return allAgents
            .filter((a) => {
                const name = `${a.first_name} ${a.last_name}`.toLowerCase();
                const campaign = getAgentCampaign(a)?.toLowerCase() ?? '';
                return name.includes(q) || campaign.includes(q);
            })
            .slice(0, 50);
    }, [allAgents, agentSearchQuery]);

    const handleFilter = () => {
        router.get(
            sessionsIndex().url,
            {
                ...((isTeamLead || isAdmin) && activeTab ? { tab: activeTab } : {}),
                search: search || undefined,
                ack_status: ackStatus || undefined,
                compliance_status: complianceStatus || undefined,
                purpose: purpose || undefined,
                campaign_id: campaignId || undefined,
                date_from: dateFrom || undefined,
                date_to: dateTo || undefined,
                coachee_role: coacheeRole || undefined,
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    const handleReset = () => {
        setSearch('');
        setAckStatus('');
        setComplianceStatus('');
        setPurpose('');
        setCampaignId('');
        setDateFrom('');
        setDateTo('');
        setCoacheeRole('');
        router.get(sessionsIndex().url, (isTeamLead || isAdmin) && activeTab ? { tab: activeTab } : {});
    };

    const handleTabChange = (tab: string) => {
        router.get(sessionsIndex().url, { tab }, { preserveState: false });
    };

    const handleDelete = (id: number) => {
        deleteForm.delete(sessionsDestroy(id).url, { preserveScroll: true });
    };

    const formatName = (user?: { first_name: string; last_name: string } | null) => {
        if (!user) return 'N/A';
        return `${user.first_name} ${user.last_name}`;
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="relative flex h-full flex-1 flex-col gap-4 rounded-xl p-3">
                <LoadingOverlay isLoading={isLoading} />

                <PageHeader
                    title="Coaching Sessions"
                    description={isAgent ? 'Your coaching session history' : 'Manage coaching sessions'}
                    createLink={can('coaching.create') ? sessionsCreate().url : undefined}
                    createLabel="New Session"
                />

                {/* Team Lead Tabs */}
                {isTeamLead && (
                    <Tabs value={activeTab || 'team'} onValueChange={handleTabChange}>
                        <TabsList>
                            <TabsTrigger value="team">Team Sessions</TabsTrigger>
                            <TabsTrigger value="my">
                                My Sessions
                                {(pendingAckCount ?? 0) > 0 && (
                                    <span className="ml-1.5 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold leading-none text-white">
                                        {pendingAckCount}
                                    </span>
                                )}
                            </TabsTrigger>
                        </TabsList>
                    </Tabs>
                )}

                {/* Admin Tabs */}
                {isAdmin && (
                    <Tabs value={activeTab || 'all'} onValueChange={handleTabChange}>
                        <TabsList>
                            <TabsTrigger value="all">All Sessions</TabsTrigger>
                            <TabsTrigger value="needs_review">
                                Needs Review
                                {(pendingReviewCount ?? 0) > 0 && (
                                    <span className="ml-1.5 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold leading-none text-white">
                                        {pendingReviewCount}
                                    </span>
                                )}
                            </TabsTrigger>
                        </TabsList>
                    </Tabs>
                )}

                {/* TL Summary Panel (My Sessions tab) */}
                {isTeamLead && activeTab === 'my' && agentSummary && (
                    <div className="rounded-lg border bg-card p-4 shadow-sm">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex items-center gap-3">
                                <span className="text-sm text-muted-foreground">My Coaching Status:</span>
                                <CoachingStatusBadge status={agentSummary.coaching_status} size="md" />
                            </div>
                            <div className="flex flex-wrap gap-4 text-sm text-muted-foreground">
                                <span>
                                    Last: <strong>{agentSummary.last_coached_date ? new Date(agentSummary.last_coached_date).toLocaleDateString('en-US', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' }) : 'N/A'}</strong>
                                </span>
                                <span>
                                    Sessions: <strong>{agentSummary.total_sessions}</strong>
                                </span>
                                {agentSummary.pending_acknowledgements > 0 && (
                                    <span className="font-medium text-amber-600">
                                        {agentSummary.pending_acknowledgements} Pending Ack
                                    </span>
                                )}
                            </div>
                        </div>
                    </div>
                )}

                {/* Agent Summary Panel */}
                {isAgent && agentSummary && (
                    <div className="rounded-lg border bg-card p-4 shadow-sm">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex items-center gap-3">
                                <span className="text-sm text-muted-foreground">Coaching Status:</span>
                                <CoachingStatusBadge status={agentSummary.coaching_status} size="md" />
                            </div>
                            <div className="flex flex-wrap gap-4 text-sm text-muted-foreground">
                                <span>
                                    Last: <strong>{agentSummary.last_coached_date ? new Date(agentSummary.last_coached_date).toLocaleDateString('en-US', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' }) : 'N/A'}</strong>
                                </span>
                                <span>
                                    Sessions: <strong>{agentSummary.total_sessions}</strong>
                                </span>
                                {agentSummary.pending_acknowledgements > 0 && (
                                    <span className="font-medium text-amber-600">
                                        {agentSummary.pending_acknowledgements} Pending Ack
                                    </span>
                                )}
                            </div>
                        </div>
                    </div>
                )}

                {/* Filters */}
                <div className="flex flex-col gap-3">
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        {!isAgent && activeTab !== 'my' && (
                            <Popover open={agentSearchOpen} onOpenChange={setAgentSearchOpen}>
                                <PopoverTrigger asChild>
                                    <Button
                                        variant="outline"
                                        role="combobox"
                                        aria-expanded={agentSearchOpen}
                                        className="w-full justify-between font-normal"
                                    >
                                        <span className="truncate">
                                            {search || 'All Agents'}
                                        </span>
                                        <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                    </Button>
                                </PopoverTrigger>
                                <PopoverContent className="w-full p-0" align="start">
                                    <Command shouldFilter={false}>
                                        <CommandInput
                                            placeholder="Search agent..."
                                            value={agentSearchQuery}
                                            onValueChange={setAgentSearchQuery}
                                        />
                                        <CommandList>
                                            <CommandEmpty>No agent found.</CommandEmpty>
                                            <CommandGroup>
                                                <CommandItem
                                                    value="all"
                                                    onSelect={() => {
                                                        setSearch('');
                                                        setAgentSearchOpen(false);
                                                        setAgentSearchQuery('');
                                                    }}
                                                    className="cursor-pointer"
                                                >
                                                    <Check
                                                        className={`mr-2 h-4 w-4 ${!search ? 'opacity-100' : 'opacity-0'}`}
                                                    />
                                                    All Agents
                                                </CommandItem>
                                                {filteredAgents.map((agent) => {
                                                    const name = `${agent.first_name} ${agent.last_name}`;
                                                    const campaign = getAgentCampaign(agent);
                                                    return (
                                                        <CommandItem
                                                            key={agent.id}
                                                            value={String(agent.id)}
                                                            onSelect={() => {
                                                                setSearch(name);
                                                                setAgentSearchOpen(false);
                                                                setAgentSearchQuery('');
                                                            }}
                                                            className="cursor-pointer"
                                                        >
                                                            <Check
                                                                className={`mr-2 h-4 w-4 ${search === name ? 'opacity-100' : 'opacity-0'}`}
                                                            />
                                                            <div className="flex flex-col">
                                                                <span>{name}</span>
                                                                {campaign && (
                                                                    <span className="text-xs text-muted-foreground">{campaign}</span>
                                                                )}
                                                            </div>
                                                        </CommandItem>
                                                    );
                                                })}
                                            </CommandGroup>
                                        </CommandList>
                                    </Command>
                                </PopoverContent>
                            </Popover>
                        )}
                        <Select value={ackStatus} onValueChange={setAckStatus}>
                            <SelectTrigger>
                                <SelectValue placeholder="Ack Status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="Pending">Pending</SelectItem>
                                <SelectItem value="Acknowledged">Acknowledged</SelectItem>
                                <SelectItem value="Disputed">Disputed</SelectItem>
                            </SelectContent>
                        </Select>
                        <Select value={complianceStatus} onValueChange={setComplianceStatus}>
                            <SelectTrigger>
                                <SelectValue placeholder="Compliance Status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="Awaiting_Agent_Ack">Awaiting Ack</SelectItem>
                                <SelectItem value="For_Review">For Review</SelectItem>
                                <SelectItem value="Verified">Verified</SelectItem>
                                <SelectItem value="Rejected">Rejected</SelectItem>
                            </SelectContent>
                        </Select>
                        <Select value={purpose} onValueChange={setPurpose}>
                            <SelectTrigger>
                                <SelectValue placeholder="Purpose" />
                            </SelectTrigger>
                            <SelectContent>
                                {Object.entries(purposes).map(([val, label]) => (
                                    <SelectItem key={val} value={val}>
                                        {label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        {(isAdmin || isTeamLead) && activeTab !== 'my' && (
                            <Select value={campaignId} onValueChange={setCampaignId}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Campaign" />
                                </SelectTrigger>
                                <SelectContent>
                                    {campaigns.map((c) => (
                                        <SelectItem key={c.id} value={String(c.id)}>
                                            {c.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                        {isAdmin && (
                            <Select value={coacheeRole} onValueChange={setCoacheeRole}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Coachee Role" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Team Lead">Team Lead</SelectItem>
                                    <SelectItem value="Agent">Agent</SelectItem>
                                </SelectContent>
                            </Select>
                        )}
                        <Input
                            type="date"
                            value={dateFrom}
                            onChange={(e) => setDateFrom(e.target.value)}
                            placeholder="Date from"
                        />
                        <Input
                            type="date"
                            value={dateTo}
                            onChange={(e) => setDateTo(e.target.value)}
                            placeholder="Date to"
                        />
                        <div className="flex gap-2">
                            <Button onClick={handleFilter} className="flex-1">
                                <Filter className="mr-2 h-4 w-4" /> Filter
                            </Button>
                            <Button variant="outline" onClick={handleReset} className="flex-1">
                                Reset
                            </Button>
                        </div>
                    </div>
                </div>

                <div className="text-sm text-muted-foreground">
                    Showing {sessions.data.length} of {sessions.total} sessions
                </div>

                {/* Desktop Table */}
                <div className="hidden overflow-hidden rounded-md shadow md:block">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow className="bg-muted/50">
                                    <TableHead>Date</TableHead>
                                    {!isAgent && activeTab !== 'my' && <TableHead>Coachee</TableHead>}
                                    {(isAdmin || activeTab === 'my') && <TableHead>Coach</TableHead>}
                                    <TableHead>Purpose</TableHead>
                                    <TableHead>Severity</TableHead>
                                    <TableHead>Ack Status</TableHead>
                                    <TableHead>Compliance</TableHead>
                                    <TableHead className="text-center">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {sessions.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={8} className="py-8 text-center text-muted-foreground">
                                            No coaching sessions found.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    sessions.data.map((session) => (
                                        <TableRow key={session.id}>
                                            <TableCell className="whitespace-nowrap">
                                                {new Date(session.session_date).toLocaleDateString('en-US', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' })}
                                            </TableCell>
                                            {!isAgent && activeTab !== 'my' && (
                                                <TableCell className="font-medium">
                                                    {formatName(session.coachee)}
                                                </TableCell>
                                            )}
                                            {(isAdmin || activeTab === 'my') && <TableCell>{formatName(session.coach)}</TableCell>}
                                            <TableCell className="max-w-[200px] truncate">
                                                {purposes[session.purpose] ?? session.purpose}
                                            </TableCell>
                                            <TableCell>
                                                <SeverityBadge flag={session.severity_flag} />
                                            </TableCell>
                                            <TableCell>
                                                <AckStatusBadge status={session.ack_status} />
                                            </TableCell>
                                            <TableCell>
                                                <ComplianceStatusBadge status={session.compliance_status} />
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center justify-center gap-1">
                                                    <Link href={sessionsShow.url(session.id)}>
                                                        <Button variant="ghost" size="icon" title="View">
                                                            <Eye className="h-4 w-4" />
                                                        </Button>
                                                    </Link>
                                                    {can('coaching.edit') && (
                                                        <Link href={sessionsEdit.url(session.id)}>
                                                            <Button variant="ghost" size="icon" title="Edit">
                                                                <Pencil className="h-4 w-4" />
                                                            </Button>
                                                        </Link>
                                                    )}
                                                    {can('coaching.delete') && (
                                                        <DeleteConfirmDialog
                                                            title="Delete Coaching Session"
                                                            description="Are you sure you want to delete this coaching session? This action cannot be undone."
                                                            onConfirm={() => handleDelete(session.id)}
                                                            trigger={
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    title="Delete"
                                                                    className="text-red-600 hover:text-red-700"
                                                                >
                                                                    <Trash2 className="h-4 w-4" />
                                                                </Button>
                                                            }
                                                        />
                                                    )}
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
                <div className="space-y-4 md:hidden">
                    {sessions.data.length === 0 ? (
                        <div className="py-8 text-center text-muted-foreground">No coaching sessions found.</div>
                    ) : (
                        sessions.data.map((session) => (
                            <CoachingSessionCard
                                key={session.id}
                                session={session}
                                purposes={purposes}
                                showCoachee={!isAgent && activeTab !== 'my'}
                                showCoach={isAdmin || activeTab === 'my'}
                            />
                        ))
                    )}
                </div>

                {/* Pagination */}
                {sessions.links && sessions.links.length > 3 && <PaginationNav links={sessions.links} />}
            </div>
        </AppLayout>
    );
}
