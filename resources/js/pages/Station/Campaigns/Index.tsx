import { useState, useEffect, useCallback } from 'react';
import { Head, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
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
    const [formError, setFormError] = useState<string | null>(null);
    const [isSubmitting, setIsSubmitting] = useState(false);

    const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
    const [campaignPendingDelete, setCampaignPendingDelete] = useState<Campaign | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);

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
    useEffect(() => {
        if (!autoRefreshEnabled) return;
        const interval = setInterval(() => {
            router.get(campaignsIndexRoute().url, buildFilterParams(), {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['campaigns'],
                onSuccess: () => setLastRefresh(new Date()),
            });
        }, 30000);

        return () => clearInterval(interval);
    }, [autoRefreshEnabled, buildFilterParams]);

    const openCreateDialog = () => {
        setEditingCampaign(null);
        setFormName('');
        setFormError(null);
        setIsDialogOpen(true);
    };

    const openEditDialog = (campaign: Campaign) => {
        setEditingCampaign(campaign);
        setFormName(campaign.name);
        setFormError(null);
        setIsDialogOpen(true);
    };

    const closeDialog = () => {
        setIsDialogOpen(false);
        setEditingCampaign(null);
        setFormName('');
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

        const payload = { name: trimmedName };
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
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {!hasResults ? (
                                    <TableRow>
                                        <TableCell colSpan={3} className="py-8 text-center text-muted-foreground">
                                            No campaigns found
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    campaigns.data.map((campaign) => (
                                        <TableRow key={campaign.id}>
                                            <TableCell>{campaign.id}</TableCell>
                                            <TableCell className="font-medium">{campaign.name}</TableCell>
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
        </AppLayout>
    );
}
