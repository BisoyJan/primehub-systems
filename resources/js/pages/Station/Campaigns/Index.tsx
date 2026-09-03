import { useState, useEffect, useCallback, useRef, useMemo } from 'react';
import { Head, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Switch } from '@/components/ui/switch';
import { Input } from '@/components/ui/input';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Label } from '@/components/ui/label';
import PaginationNav, { PaginationLink } from '@/components/pagination-nav';
import { Can } from '@/components/authorization';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { useFlashMessage, usePageLoading, usePageMeta } from '@/hooks';
import { Search, RefreshCw, Plus, Filter, Play, Pause } from 'lucide-react';
import {
    index as campaignsIndexRoute,
    store as campaignsStoreRoute,
    update as campaignsUpdateRoute,
    destroy as campaignsDestroyRoute,
} from '@/routes/campaigns';
import { index as stationsIndexRoute } from '@/routes/stations';

interface Campaign {
    id: number;
    name: string;
    allows_weekend_leave: boolean;
}

interface TeamLeadOption {
    id: number;
    name: string;
}

interface AgentOption {
    id: number;
    name: string;
}

interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface PaginatedCampaigns {
    data: Campaign[];
    links?: PaginationLink[];
    meta?: PaginationMeta;
}

interface Filters {
    search?: string;
}

interface CampaignPageProps {
    campaigns: PaginatedCampaigns;
    filters?: Filters;
}

export default function CampaignManagement({ campaigns, filters = {} }: CampaignPageProps) {
    const { title, breadcrumbs } = usePageMeta({
        title: 'Campaign Management',
        breadcrumbs: [
            { title: 'Stations', href: stationsIndexRoute().url },
            { title: 'Campaigns', href: campaignsIndexRoute().url },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    const [search, setSearch] = useState(filters.search || '');
    const [isFilterLoading, setIsFilterLoading] = useState(false);
    const [lastRefresh, setLastRefresh] = useState<Date>(new Date());
    const [autoRefreshEnabled, setAutoRefreshEnabled] = useState(false);

    const [isDialogOpen, setIsDialogOpen] = useState(false);
    const [editingCampaign, setEditingCampaign] = useState<Campaign | null>(null);
    const [formName, setFormName] = useState('');
    const [formAllowsWeekendLeave, setFormAllowsWeekendLeave] = useState(false);
    const [formError, setFormError] = useState<string | null>(null);
    const [isSubmitting, setIsSubmitting] = useState(false);

    const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
    const [campaignPendingDelete, setCampaignPendingDelete] = useState<Campaign | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);

    const [isAssignDialogOpen, setIsAssignDialogOpen] = useState(false);
    const [assignmentCampaign, setAssignmentCampaign] = useState<Campaign | null>(null);
    const [assignmentTeamLeads, setAssignmentTeamLeads] = useState<TeamLeadOption[]>([]);
    const [assignmentAgents, setAssignmentAgents] = useState<AgentOption[]>([]);
    const [assignmentMap, setAssignmentMap] = useState<Record<number, number[]>>({});
    const [initialAssignmentMap, setInitialAssignmentMap] = useState<Record<number, number[]>>({});
    const [selectedLeadId, setSelectedLeadId] = useState<number | null>(null);
    const [leadSearch, setLeadSearch] = useState('');
    const [agentSearch, setAgentSearch] = useState('');
    const [agentFilter, setAgentFilter] = useState<'all' | 'assigned' | 'unassigned'>('all');
    const [assignmentError, setAssignmentError] = useState<string | null>(null);
    const [isAssignmentLoading, setIsAssignmentLoading] = useState(false);
    const [isAssignmentSaving, setIsAssignmentSaving] = useState(false);

    const showClearFilters = Boolean(search.trim());

    const buildFilterParams = useCallback(() => {
        const params: Record<string, string> = {};
        if (search.trim()) {
            params.search = search.trim();
        }
        return params;
    }, [search]);

    const requestWithFilters = (params: Record<string, string>) => {
        setIsFilterLoading(true);
        router.get(campaignsIndexRoute().url, params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: () => setLastRefresh(new Date()),
            onFinish: () => setIsFilterLoading(false),
        });
    };

    const handleApplyFilters = () => {
        requestWithFilters(buildFilterParams());
    };

    const handleClearFilters = () => {
        setSearch('');
        requestWithFilters({});
    };

    const handleManualRefresh = () => {
        requestWithFilters(buildFilterParams());
    };

    // Auto-refresh every 30 seconds
    const isPollingRef = useRef(false);
    useEffect(() => {
        if (!autoRefreshEnabled) return;
        const interval = setInterval(() => {
            if (isPollingRef.current) return;
            isPollingRef.current = true;
            router.get(campaignsIndexRoute().url, buildFilterParams(), {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['campaigns'],
                onSuccess: () => setLastRefresh(new Date()),
                onFinish: () => { isPollingRef.current = false; },
            });
        }, 30000);

        return () => clearInterval(interval);
    }, [autoRefreshEnabled, buildFilterParams]);

    const openCreateDialog = () => {
        setEditingCampaign(null);
        setFormName('');
        setFormAllowsWeekendLeave(false);
        setFormError(null);
        setIsDialogOpen(true);
    };

    const openEditDialog = (campaign: Campaign) => {
        setEditingCampaign(campaign);
        setFormName(campaign.name);
        setFormAllowsWeekendLeave(campaign.allows_weekend_leave);
        setFormError(null);
        setIsDialogOpen(true);
    };

    const closeDialog = () => {
        setIsDialogOpen(false);
        setEditingCampaign(null);
        setFormName('');
        setFormAllowsWeekendLeave(false);
        setFormError(null);
    };

    const handleDialogOpenChange = (open: boolean) => {
        if (!open) {
            closeDialog();
        } else {
            setIsDialogOpen(true);
        }
    };

    const openDeleteDialog = (campaign: Campaign) => {
        setCampaignPendingDelete(campaign);
        setIsDeleteDialogOpen(true);
    };

    const closeDeleteDialog = () => {
        setIsDeleteDialogOpen(false);
        setCampaignPendingDelete(null);
    };

    const handleDeleteOpenChange = (open: boolean) => {
        if (!open) {
            closeDeleteDialog();
        } else {
            setIsDeleteDialogOpen(true);
        }
    };

    const handleSave = (event: React.FormEvent) => {
        event.preventDefault();
        setFormError(null);

        const trimmedName = formName.trim();
        if (!trimmedName) {
            setFormError('Campaign name is required.');
            return;
        }

        const isDuplicate = campaigns.data.some((campaign) =>
            campaign.name.toLowerCase() === trimmedName.toLowerCase() && campaign.id !== editingCampaign?.id
        );

        if (isDuplicate) {
            setFormError(`A campaign with the name "${trimmedName}" already exists.`);
            return;
        }

        setIsSubmitting(true);

        const payload = { name: trimmedName, allows_weekend_leave: formAllowsWeekendLeave };
        const requestOptions = {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: () => {
                closeDialog();
                setLastRefresh(new Date());
            },
            onError: (errors: Record<string, string | string[]>) => {
                if (errors.name) {
                    setFormError(Array.isArray(errors.name) ? errors.name[0] : errors.name);
                }
            },
            onFinish: () => setIsSubmitting(false),
        } as const;

        if (editingCampaign) {
            router.put(campaignsUpdateRoute(editingCampaign.id).url, payload, requestOptions);
        } else {
            router.post(campaignsStoreRoute().url, payload, requestOptions);
        }
    };

    const handleDeleteConfirm = () => {
        if (!campaignPendingDelete) return;
        setIsDeleting(true);

        router.delete(campaignsDestroyRoute(campaignPendingDelete.id).url, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: () => setLastRefresh(new Date()),
            onFinish: () => {
                setIsDeleting(false);
                closeDeleteDialog();
            },
        });
    };

    const normalizeAssignmentMap = useCallback((map: Record<number, number[]>) => {
        const normalized: Record<number, number[]> = {};

        Object.entries(map ?? {}).forEach(([teamLeadId, agentIds]) => {
            const ids = [...new Set((agentIds ?? []).map((id) => Number(id)).filter((id) => Number.isFinite(id)))].sort((a, b) => a - b);
            if (ids.length > 0) {
                normalized[Number(teamLeadId)] = ids;
            }
        });

        return normalized;
    }, []);

    const assignmentHasChanges = useMemo(() => {
        const normalizedCurrent = normalizeAssignmentMap(assignmentMap);
        const normalizedInitial = normalizeAssignmentMap(initialAssignmentMap);

        return JSON.stringify(normalizedCurrent) !== JSON.stringify(normalizedInitial);
    }, [assignmentMap, initialAssignmentMap, normalizeAssignmentMap]);

    const filteredTeamLeads = useMemo(() => {
        const query = leadSearch.trim().toLowerCase();

        return assignmentTeamLeads.filter((teamLead) => !query || teamLead.name.toLowerCase().includes(query));
    }, [assignmentTeamLeads, leadSearch]);

    const filteredAgents = useMemo(() => {
        const query = agentSearch.trim().toLowerCase();

        return assignmentAgents.filter((agent) => {
            const matchesQuery = !query || agent.name.toLowerCase().includes(query);
            const currentLeadAssignments = selectedLeadId ? assignmentMap[selectedLeadId] ?? [] : [];
            const isAssigned = currentLeadAssignments.includes(agent.id);

            if (agentFilter === 'assigned' && !isAssigned) {
                return false;
            }

            if (agentFilter === 'unassigned' && isAssigned) {
                return false;
            }

            return matchesQuery;
        });
    }, [agentFilter, agentSearch, assignmentAgents, assignmentMap, selectedLeadId]);

    const selectedLead = assignmentTeamLeads.find((teamLead) => teamLead.id === selectedLeadId) ?? null;
    const selectedLeadAssignments = selectedLead ? assignmentMap[selectedLead.id] ?? [] : [];
    const totalAgentsCount = assignmentAgents.length;
    const agentLeadNameMap = useMemo(() => {
        const map: Record<number, string[]> = {};

        Object.entries(assignmentMap).forEach(([teamLeadId, agentIds]) => {
            const teamLead = assignmentTeamLeads.find((item) => item.id === Number(teamLeadId));
            if (!teamLead) {
                return;
            }

            agentIds.forEach((agentId) => {
                if (!map[agentId]) {
                    map[agentId] = [];
                }

                map[agentId].push(teamLead.name);
            });
        });

        return map;
    }, [assignmentMap, assignmentTeamLeads]);
    const totalAssignedCount = useMemo(
        () => Object.values(assignmentMap).reduce((total, agentIds) => total + agentIds.length, 0),
        [assignmentMap],
    );

    const openAssignmentDialog = async (campaign: Campaign) => {
        setAssignmentCampaign(campaign);
        setAssignmentError(null);
        setLeadSearch('');
        setAgentSearch('');
        setAgentFilter('all');
        setIsAssignDialogOpen(true);
        setIsAssignmentLoading(true);

        try {
            const response = await fetch(`/campaigns/${campaign.id}/team-assignments`, {
                headers: {
                    Accept: 'application/json',
                },
            });

            if (!response.ok) {
                throw new Error('Failed to load team assignments');
            }

            const payload = await response.json() as {
                teamLeads: TeamLeadOption[];
                agents: AgentOption[];
                assignments: Record<string, number[]>;
            };

            const normalized = normalizeAssignmentMap(
                Object.fromEntries(
                    Object.entries(payload.assignments ?? {}).map(([teamLeadId, agentIds]) => [Number(teamLeadId), agentIds ?? []]),
                ) as Record<number, number[]>,
            );

            setAssignmentTeamLeads(payload.teamLeads ?? []);
            setAssignmentAgents(payload.agents ?? []);
            setAssignmentMap(normalized);
            setInitialAssignmentMap(normalized);
            setSelectedLeadId((payload.teamLeads?.[0]?.id) ?? null);
            setAssignmentError(null);
        } catch {
            setAssignmentTeamLeads([]);
            setAssignmentAgents([]);
            setAssignmentMap({});
            setInitialAssignmentMap({});
            setSelectedLeadId(null);
            setAssignmentError('Unable to load team assignments. Please try again.');
        } finally {
            setIsAssignmentLoading(false);
        }
    };

    const closeAssignmentDialog = () => {
        setIsAssignDialogOpen(false);
        setAssignmentCampaign(null);
        setAssignmentTeamLeads([]);
        setAssignmentAgents([]);
        setAssignmentMap({});
        setInitialAssignmentMap({});
        setSelectedLeadId(null);
        setLeadSearch('');
        setAgentSearch('');
        setAgentFilter('all');
        setAssignmentError(null);
    };

    const toggleAssignment = (teamLeadId: number, agentId: number) => {
        setAssignmentMap((prev) => {
            const current = prev[teamLeadId] ?? [];
            const exists = current.includes(agentId);

            return {
                ...prev,
                [teamLeadId]: exists ? current.filter((id) => id !== agentId) : [...current, agentId].sort((a, b) => a - b),
            };
        });
    };

    const toggleVisibleAgentsForLead = (teamLeadId: number, shouldSelect: boolean) => {
        const visibleAgentIds = filteredAgents.map((agent) => agent.id);

        setAssignmentMap((prev) => {
            const current = prev[teamLeadId] ?? [];
            const next = shouldSelect
                ? [...new Set([...current, ...visibleAgentIds])].sort((a, b) => a - b)
                : current.filter((agentId) => !visibleAgentIds.includes(agentId));

            return {
                ...prev,
                [teamLeadId]: next,
            };
        });
    };

    const toggleAllAgentsForLead = (teamLeadId: number, shouldSelect: boolean) => {
        setAssignmentMap((prev) => {
            const current = prev[teamLeadId] ?? [];
            const allAgentIds = assignmentAgents.map((agent) => agent.id);
            const next = shouldSelect
                ? [...new Set([...current, ...allAgentIds])].sort((a, b) => a - b)
                : [];

            return {
                ...prev,
                [teamLeadId]: next,
            };
        });
    };

    const saveAssignments = () => {
        if (!assignmentCampaign) {
            return;
        }

        setAssignmentError(null);
        setIsAssignmentSaving(true);
        router.put(
            `/campaigns/${assignmentCampaign.id}/team-assignments`,
            { assignments: normalizeAssignmentMap(assignmentMap) },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    closeAssignmentDialog();
                    setLastRefresh(new Date());
                },
                onError: () => {
                    setAssignmentError('Unable to save team assignments. Please try again.');
                },
                onFinish: () => setIsAssignmentSaving(false),
            },
        );
    };

    const paginationMeta: PaginationMeta = campaigns.meta || {
        current_page: 1,
        last_page: 1,
        per_page: campaigns.data.length || 1,
        total: campaigns.data.length,
    };

    const paginationLinks = campaigns.links || [];
    const hasResults = campaigns.data.length > 0;
    const showingStart = hasResults ? paginationMeta.per_page * (paginationMeta.current_page - 1) + 1 : 0;
    const showingEnd = hasResults ? showingStart + campaigns.data.length - 1 : 0;
    const summaryText = hasResults
        ? `Showing ${showingStart}-${showingEnd} of ${paginationMeta.total} campaigns`
        : 'No campaigns to display';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                <LoadingOverlay isLoading={isPageLoading || isFilterLoading} />

                <PageHeader
                    title="Campaign Management"
                    description="Create and maintain campaign labels for stations"
                />

                <div className="flex flex-col gap-4">
                    <div className="flex flex-col sm:flex-row gap-4 justify-between items-start sm:items-center">
                        <div className="w-full sm:w-auto flex-1 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                            <div className="relative">
                                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search campaigns by name..."
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                    onKeyDown={(event) => event.key === 'Enter' && handleApplyFilters()}
                                    className="pl-8"
                                />
                            </div>
                        </div>

                        <div className="flex flex-wrap gap-2 w-full sm:w-auto">
                            <Button variant="outline" onClick={handleApplyFilters} disabled={isFilterLoading} className="flex-1 sm:flex-none">
                                <Filter className="mr-2 h-4 w-4" />
                                Filter
                            </Button>

                            {showClearFilters && (
                                <Button variant="outline" onClick={handleClearFilters} disabled={isFilterLoading} className="flex-1 sm:flex-none">
                                    Reset
                                </Button>
                            )}

                            <div className="flex gap-2">
                                <Button variant="ghost" size="icon" onClick={handleManualRefresh} disabled={isFilterLoading} title="Refresh">
                                    <RefreshCw className="h-4 w-4" />
                                </Button>
                                <Button
                                    variant={autoRefreshEnabled ? "default" : "ghost"}
                                    size="icon"
                                    onClick={() => setAutoRefreshEnabled(!autoRefreshEnabled)}
                                    title={autoRefreshEnabled ? "Disable auto-refresh" : "Enable auto-refresh (30s)"}
                                >
                                    {autoRefreshEnabled ? <Pause className="h-4 w-4" /> : <Play className="h-4 w-4" />}
                                </Button>
                            </div>

                            <Can permission="campaigns.create">
                                <Button onClick={openCreateDialog} className="flex-1 sm:flex-none">
                                    <Plus className="mr-2 h-4 w-4" />
                                    Add Campaign
                                </Button>
                            </Can>
                        </div>
                    </div>

                    <div className="flex justify-between items-center text-sm">
                        <div className="text-muted-foreground">
                            {summaryText}
                            {showClearFilters && hasResults ? ' (filtered)' : ''}
                        </div>
                        <div className="text-xs text-muted-foreground">Last updated: {lastRefresh.toLocaleTimeString()}</div>
                    </div>
                </div>

                <div className="overflow-hidden rounded-md border bg-card">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow className="bg-muted/50">
                                    <TableHead>ID</TableHead>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Weekend Leave</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {!hasResults ? (
                                    <TableRow>
                                        <TableCell colSpan={4} className="py-8 text-center text-muted-foreground">
                                            No campaigns found
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    campaigns.data.map((campaign) => (
                                        <TableRow key={campaign.id}>
                                            <TableCell>{campaign.id}</TableCell>
                                            <TableCell className="font-medium">{campaign.name}</TableCell>
                                            <TableCell>
                                                {campaign.allows_weekend_leave ? (
                                                    <span className="text-xs font-medium text-emerald-600">Yes</span>
                                                ) : (
                                                    <span className="text-xs text-muted-foreground">No</span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <div className="flex justify-end gap-2">
                                                    <Can permission="campaigns.edit">
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() => openEditDialog(campaign)}
                                                        >
                                                            Edit
                                                        </Button>
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() => openAssignmentDialog(campaign)}
                                                        >
                                                            Manage Team
                                                        </Button>
                                                    </Can>
                                                    <Can permission="campaigns.delete">
                                                        <Button
                                                            variant="destructive"
                                                            size="sm"
                                                            onClick={() => openDeleteDialog(campaign)}
                                                        >
                                                            Delete
                                                        </Button>
                                                    </Can>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </div>

                    {paginationLinks.length > 0 && (
                        <div className="border-t px-4 py-3 flex justify-center">
                            <PaginationNav links={paginationLinks} />
                        </div>
                    )}
                </div>
            </div>

            <Dialog open={isDialogOpen} onOpenChange={handleDialogOpenChange}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{editingCampaign ? 'Edit Campaign' : 'Add Campaign'}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleSave} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="campaign-name">Name</Label>
                            <Input
                                id="campaign-name"
                                value={formName}
                                onChange={(event) => setFormName(event.target.value)}
                                placeholder="Campaign name"
                                disabled={isSubmitting}
                                required
                            />
                        </div>
                        <div className="flex items-center justify-between rounded-md border p-3">
                            <div className="space-y-0.5">
                                <Label htmlFor="campaign-weekend-leave">Allow Weekend Leave</Label>
                                <p className="text-xs text-muted-foreground">
                                    Employees in this campaign can file leave requests covering Saturdays and Sundays.
                                </p>
                            </div>
                            <Switch
                                id="campaign-weekend-leave"
                                checked={formAllowsWeekendLeave}
                                onCheckedChange={setFormAllowsWeekendLeave}
                                disabled={isSubmitting}
                            />
                        </div>
                        {formError && <p className="text-sm text-destructive">{formError}</p>}
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={closeDialog} disabled={isSubmitting}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={isSubmitting}>
                                {isSubmitting ? 'Saving...' : 'Save'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={isDeleteDialogOpen} onOpenChange={handleDeleteOpenChange}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete Campaign</DialogTitle>
                    </DialogHeader>
                    <div className="py-2 text-sm text-muted-foreground">
                        {campaignPendingDelete ? (
                            <>Are you sure you want to delete the campaign <b>{campaignPendingDelete.name}</b>? This action cannot be undone.</>
                        ) : (
                            'Select a campaign to delete.'
                        )}
                    </div>
                    <DialogFooter className="flex gap-2">
                        <Button variant="outline" onClick={closeDeleteDialog} disabled={isDeleting}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={handleDeleteConfirm} disabled={isDeleting || !campaignPendingDelete}>
                            {isDeleting ? 'Deleting...' : 'Delete'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={isAssignDialogOpen} onOpenChange={(open) => !open && closeAssignmentDialog()}>
                <DialogContent className="max-h-[85vh] overflow-hidden sm:max-w-5xl">
                    <DialogHeader>
                        <DialogTitle>
                            {assignmentCampaign ? `Team Assignments: ${assignmentCampaign.name}` : 'Team Assignments'}
                        </DialogTitle>
                    </DialogHeader>

                    <div className="space-y-4">
                        <div className="flex items-center justify-between gap-3 text-sm">
                            <span className="text-muted-foreground">
                                {selectedLead
                                    ? `${selectedLeadAssignments.length} assigned / ${totalAgentsCount} agents`
                                    : `${totalAssignedCount} assignment${totalAssignedCount === 1 ? '' : 's'} across ${totalAgentsCount} agents`}
                            </span>
                            {assignmentHasChanges && (
                                <span className="rounded-full bg-amber-100 px-2 py-1 text-xs font-medium text-amber-800 dark:bg-amber-950/70 dark:text-amber-200">
                                    Unsaved changes
                                </span>
                            )}
                        </div>

                        {assignmentError && (
                            <p className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900/80 dark:bg-red-950/40 dark:text-red-300">
                                {assignmentError}
                            </p>
                        )}

                        {isAssignmentLoading ? (
                            <p className="text-sm text-muted-foreground">Loading assignments...</p>
                        ) : assignmentTeamLeads.length === 0 ? (
                            <p className="text-sm text-muted-foreground">No active team leads assigned to this campaign.</p>
                        ) : assignmentAgents.length === 0 ? (
                            <p className="text-sm text-muted-foreground">No active agents in this campaign.</p>
                        ) : (
                            <div className="grid grid-cols-1 gap-4 lg:grid-cols-[260px_minmax(0,1fr)]">
                                <div className="rounded-md border bg-muted/20 p-3">
                                    <div className="mb-3">
                                        <Input
                                            value={leadSearch}
                                            onChange={(event) => setLeadSearch(event.target.value)}
                                            placeholder="Search team leads..."
                                        />
                                    </div>

                                    <div className="max-h-[52vh] space-y-2 overflow-y-auto pr-1">
                                        {filteredTeamLeads.length === 0 ? (
                                            <p className="text-sm text-muted-foreground">No team leads match your search.</p>
                                        ) : (
                                            filteredTeamLeads.map((teamLead) => {
                                                const isSelected = selectedLeadId === teamLead.id;
                                                const leadAssignmentCount = assignmentMap[teamLead.id]?.length ?? 0;

                                                return (
                                                    <button
                                                        key={teamLead.id}
                                                        type="button"
                                                        onClick={() => setSelectedLeadId(teamLead.id)}
                                                        className={`w-full rounded-md border p-3 text-left transition-colors ${isSelected
                                                                ? 'border-primary bg-primary/5'
                                                                : 'border-transparent bg-background hover:border-muted-foreground/30'
                                                            }`}
                                                    >
                                                        <div className="flex items-center justify-between gap-2">
                                                            <span className="font-medium">{teamLead.name}</span>
                                                            <span className="rounded-full bg-muted px-2 py-0.5 text-xs">
                                                                {leadAssignmentCount}
                                                            </span>
                                                        </div>
                                                    </button>
                                                );
                                            })
                                        )}
                                    </div>
                                </div>

                                <div className="rounded-md border p-3">
                                    {selectedLead ? (
                                        <div className="space-y-4">
                                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                                <div>
                                                    <p className="font-semibold">{selectedLead.name}</p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {selectedLeadAssignments.length} assigned agent{selectedLeadAssignments.length === 1 ? '' : 's'}
                                                    </p>
                                                </div>

                                                <div className="flex flex-wrap gap-2">
                                                    <Button type="button" variant="outline" size="sm" onClick={() => toggleAllAgentsForLead(selectedLead.id, true)}>
                                                        Assign all
                                                    </Button>
                                                    <Button type="button" variant="outline" size="sm" onClick={() => toggleVisibleAgentsForLead(selectedLead.id, true)}>
                                                        Select visible
                                                    </Button>
                                                    <Button type="button" variant="outline" size="sm" onClick={() => toggleVisibleAgentsForLead(selectedLead.id, false)}>
                                                        Clear visible
                                                    </Button>
                                                    <Button type="button" variant="outline" size="sm" onClick={() => toggleAllAgentsForLead(selectedLead.id, false)}>
                                                        Clear all
                                                    </Button>
                                                </div>
                                            </div>

                                            <div className="space-y-3">
                                                <Input
                                                    value={agentSearch}
                                                    onChange={(event) => setAgentSearch(event.target.value)}
                                                    placeholder="Search agents..."
                                                />

                                                <div className="flex flex-wrap gap-2">
                                                    {(['all', 'assigned', 'unassigned'] as const).map((filter) => (
                                                        <Button
                                                            key={filter}
                                                            type="button"
                                                            variant={agentFilter === filter ? 'default' : 'outline'}
                                                            size="sm"
                                                            onClick={() => setAgentFilter(filter)}
                                                        >
                                                            {filter === 'all' ? 'All' : filter === 'assigned' ? 'Assigned' : 'Unassigned'}
                                                        </Button>
                                                    ))}
                                                </div>
                                            </div>

                                            <div className="max-h-[52vh] space-y-2 overflow-y-auto pr-1">
                                                {filteredAgents.length === 0 ? (
                                                    <p className="text-sm text-muted-foreground">No agents match your current filter.</p>
                                                ) : (
                                                    filteredAgents.map((agent) => {
                                                        const checked = selectedLeadAssignments.includes(agent.id);
                                                        const existingLeadNames = (agentLeadNameMap[agent.id] ?? []).filter((name) => name !== selectedLead.name);

                                                        return (
                                                            <label
                                                                key={`${selectedLead.id}-${agent.id}`}
                                                                className="flex cursor-pointer items-center gap-2 rounded-md border p-2 text-sm hover:bg-muted/40"
                                                            >
                                                                <Checkbox
                                                                    checked={checked}
                                                                    onCheckedChange={() => toggleAssignment(selectedLead.id, agent.id)}
                                                                />
                                                                <div className="flex min-w-0 flex-1 items-center justify-between gap-2">
                                                                    <span className="truncate">{agent.name}</span>
                                                                    {existingLeadNames.length > 0 && (
                                                                        <div className="flex shrink-0 flex-wrap justify-end gap-1">
                                                                            {existingLeadNames.map((leadName) => (
                                                                                <span
                                                                                    key={`${agent.id}-${leadName}`}
                                                                                    className="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[10px] font-medium text-amber-800 dark:border-amber-900/70 dark:bg-amber-950/60 dark:text-amber-200"
                                                                                >
                                                                                    {leadName}
                                                                                </span>
                                                                            ))}
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            </label>
                                                        );
                                                    })
                                                )}
                                            </div>
                                        </div>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">Select a team lead to manage assignments.</p>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={closeAssignmentDialog} disabled={isAssignmentSaving}>
                            Cancel
                        </Button>
                        <Button type="button" onClick={saveAssignments} disabled={isAssignmentSaving || isAssignmentLoading || !assignmentHasChanges}>
                            {isAssignmentSaving ? 'Saving...' : 'Save Assignments'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
