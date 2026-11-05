import { LoadingOverlay } from '@/components/LoadingOverlay';
import { PageHeader } from '@/components/PageHeader';
import { TableSkeleton } from '@/components/TableSkeleton';
import { Can } from '@/components/authorization';
import PaginationNav, { PaginationLink } from '@/components/pagination-nav';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
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
import { useFlashMessage, usePageLoading, usePageMeta } from '@/hooks';
import AppLayout from '@/layouts/app-layout';
import {
    create as medicationCreateRoute,
    destroy as medicationDestroyRoute,
    index as medicationIndexRoute,
    show as medicationShowRoute,
} from '@/routes/medication-requests';
import { Head, Link, router } from '@inertiajs/react';
import {
    Eye,
    Filter,
    Pause,
    Play,
    Plus,
    RefreshCw,
    Search,
    Trash2,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

interface MedicationRequest {
    id: number;
    name: string;
    medication_type: string;
    reason: string;
    onset_of_symptoms: string;
    status: 'pending' | 'approved' | 'dispensed' | 'rejected';
    created_at: string;
    user?: {
        name: string;
        email: string;
        active_schedule?: {
            campaign?: { name: string };
            site?: { name: string };
        };
    };
}

interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface Campaign {
    id: number;
    name: string;
}

interface Props {
    medicationRequests: {
        data: MedicationRequest[];
        links?: PaginationLink[];
        meta?: PaginationMeta;
    };
    filters: {
        search?: string;
        status?: string;
        medication_type?: string;
        campaign_id?: string;
    };
    medicationTypes: string[];
    campaigns?: Campaign[];
    teamLeadCampaignIds?: number[];
}

export default function Index({
    medicationRequests,
    filters,
    medicationTypes = [],
    campaigns = [],
    teamLeadCampaignIds,
}: Props) {
    const { title, breadcrumbs } = usePageMeta({
        title: 'Medication Requests',
        breadcrumbs: [
            { title: 'Form Requests', href: '/form-requests' },
            { title: 'Medication Requests', href: medicationIndexRoute().url },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || 'all');
    const [medicationType, setMedicationType] = useState(
        filters.medication_type || 'all',
    );
    const [campaignFilter, setCampaignFilter] = useState(() => {
        if (filters.campaign_id) return filters.campaign_id;
        return 'all';
    });
    const [localLoading, setLocalLoading] = useState(false);
    const [lastRefresh, setLastRefresh] = useState<Date>(new Date());
    const [autoRefreshEnabled, setAutoRefreshEnabled] = useState(false);
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [requestToDelete, setRequestToDelete] = useState<number | null>(null);

    const showClearFilters =
        Boolean(search) ||
        status !== 'all' ||
        medicationType !== 'all' ||
        campaignFilter !== 'all';

    const buildFilterParams = useCallback(() => {
        const params: Record<string, string> = {};
        if (search) {
            params.search = search;
        }
        if (status !== 'all') {
            params.status = status;
        }
        if (medicationType !== 'all') {
            params.medication_type = medicationType;
        }
        if (campaignFilter !== 'all') {
            params.campaign_id = campaignFilter;
        }
        return params;
    }, [search, status, medicationType, campaignFilter]);

    const requestWithFilters = (params: Record<string, string>) => {
        setLocalLoading(true);
        router.get(medicationIndexRoute().url, params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: () => setLastRefresh(new Date()),
            onFinish: () => setLocalLoading(false),
        });
    };

    const handleSearch = () => {
        requestWithFilters(buildFilterParams());
    };

    const handleDelete = (id: number) => {
        setRequestToDelete(id);
        setDeleteDialogOpen(true);
    };

    const confirmDelete = () => {
        if (requestToDelete) {
            setLocalLoading(true);
            router.delete(medicationDestroyRoute(requestToDelete).url, {
                preserveScroll: true,
                onSuccess: () => {
                    setLastRefresh(new Date());
                    setDeleteDialogOpen(false);
                    setRequestToDelete(null);
                },
                onFinish: () => setLocalLoading(false),
            });
        }
    };

    const getStatusBadge = (status: string) => {
        const colors = {
            pending: 'bg-yellow-100 text-yellow-800 border-yellow-300',
            approved: 'bg-blue-100 text-blue-800 border-blue-300',
            dispensed: 'bg-green-100 text-green-800 border-green-300',
            rejected: 'bg-red-100 text-red-800 border-red-300',
        };

        return (
            <Badge
                variant="outline"
                className={colors[status as keyof typeof colors]}
            >
                {status.charAt(0).toUpperCase() + status.slice(1)}
            </Badge>
        );
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
            router.get(medicationIndexRoute().url, buildFilterParams(), {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['medicationRequests'],
                onSuccess: () => setLastRefresh(new Date()),
                onFinish: () => {
                    isPollingRef.current = false;
                },
            });
        }, 30000);

        return () => clearInterval(interval);
    }, [autoRefreshEnabled, buildFilterParams]);

    const clearFilters = () => {
        setSearch('');
        setStatus('all');
        setMedicationType('all');
        setCampaignFilter('all');
        requestWithFilters({});
    };

    const paginationMeta: PaginationMeta = medicationRequests.meta || {
        current_page: 1,
        last_page: 1,
        per_page: medicationRequests.data.length || 1,
        total: medicationRequests.data.length,
    };
    const paginationLinks = medicationRequests.links || [];

    const hasResults = medicationRequests.data.length > 0;
    const showingStart = hasResults
        ? paginationMeta.per_page * (paginationMeta.current_page - 1) + 1
        : 0;
    const showingEnd = hasResults
        ? showingStart + medicationRequests.data.length - 1
        : 0;
    const summaryText = hasResults
        ? `Showing ${showingStart}-${showingEnd} of ${paginationMeta.total} requests`
        : 'No medication requests to display';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="relative flex h-full flex-1 flex-col gap-4 rounded-xl p-3">
                <LoadingOverlay isLoading={isPageLoading || localLoading} />

                <PageHeader
                    title="Medication Requests"
                    description="Manage medication requests from employees"
                />

                <div className="flex flex-col gap-4">
                    <div className="flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center">
                        <div className="grid w-full flex-1 grid-cols-1 gap-3 sm:w-auto sm:grid-cols-2 lg:grid-cols-4">
                            <div className="relative">
                                <Search className="absolute top-2.5 left-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search..."
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    onKeyDown={(e) =>
                                        e.key === 'Enter' && handleSearch()
                                    }
                                    className="pl-8"
                                />
                            </div>

                            <Select value={status} onValueChange={setStatus}>
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="All statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All statuses
                                    </SelectItem>
                                    <SelectItem value="pending">
                                        Pending
                                    </SelectItem>
                                    <SelectItem value="approved">
                                        Approved
                                    </SelectItem>
                                    <SelectItem value="dispensed">
                                        Dispensed
                                    </SelectItem>
                                    <SelectItem value="rejected">
                                        Rejected
                                    </SelectItem>
                                </SelectContent>
                            </Select>

                            <Select
                                value={medicationType}
                                onValueChange={setMedicationType}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="All medication types" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All medication types
                                    </SelectItem>
                                    {medicationTypes.map((type) => (
                                        <SelectItem key={type} value={type}>
                                            {type}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            <Select
                                value={campaignFilter}
                                onValueChange={setCampaignFilter}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="All Campaigns" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All Campaigns
                                    </SelectItem>
                                    {(teamLeadCampaignIds?.length
                                        ? campaigns.filter((c) =>
                                              teamLeadCampaignIds.includes(
                                                  c.id,
                                              ),
                                          )
                                        : campaigns
                                    ).map((campaign) => (
                                        <SelectItem
                                            key={campaign.id}
                                            value={String(campaign.id)}
                                        >
                                            {campaign.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="flex w-full flex-wrap gap-2 sm:w-auto">
                            <Button
                                variant="outline"
                                onClick={handleSearch}
                                className="flex-1 sm:flex-none"
                            >
                                <Filter className="mr-2 h-4 w-4" />
                                Filter
                            </Button>

                            {showClearFilters && (
                                <Button
                                    variant="outline"
                                    onClick={clearFilters}
                                    className="flex-1 sm:flex-none"
                                >
                                    Reset
                                </Button>
                            )}

                            <div className="flex gap-2">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    onClick={handleManualRefresh}
                                    title="Refresh"
                                >
                                    <RefreshCw className="h-4 w-4" />
                                </Button>
                                <Button
                                    variant={
                                        autoRefreshEnabled ? 'default' : 'ghost'
                                    }
                                    size="icon"
                                    onClick={() =>
                                        setAutoRefreshEnabled(
                                            !autoRefreshEnabled,
                                        )
                                    }
                                    title={
                                        autoRefreshEnabled
                                            ? 'Disable auto-refresh'
                                            : 'Enable auto-refresh (30s)'
                                    }
                                >
                                    {autoRefreshEnabled ? (
                                        <Pause className="h-4 w-4" />
                                    ) : (
                                        <Play className="h-4 w-4" />
                                    )}
                                </Button>
                            </div>

                            <Can permission="medication_requests.create">
                                <Link
                                    href={medicationCreateRoute().url}
                                    className="flex-1 sm:flex-none"
                                >
                                    <Button className="w-full sm:w-auto">
                                        <Plus className="mr-2 h-4 w-4" />
                                        New Request
                                    </Button>
                                </Link>
                            </Can>
                        </div>
                    </div>

                    <div className="flex items-center justify-between text-sm">
                        <div className="text-muted-foreground">
                            {summaryText}
                            {showClearFilters && hasResults && ' (filtered)'}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            Last updated: {lastRefresh.toLocaleTimeString()}
                        </div>
                    </div>
                </div>

                {/* Desktop Table View */}
                <div className="hidden overflow-hidden rounded-md border bg-card md:block">
                    {isPageLoading ? (
                        <TableSkeleton columns={9} rows={8} />
                    ) : (
                        <>
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow className="bg-muted/50">
                                            <TableHead>Employee Name</TableHead>
                                            <TableHead>Email</TableHead>
                                            <TableHead>Campaign</TableHead>
                                            <TableHead>Site</TableHead>
                                            <TableHead>
                                                Medication Type
                                            </TableHead>
                                            <TableHead>
                                                Onset of Symptoms
                                            </TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>
                                                Requested Date
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Actions
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {medicationRequests.data.length ===
                                        0 ? (
                                            <TableRow>
                                                <TableCell
                                                    colSpan={9}
                                                    className="py-8 text-center text-muted-foreground"
                                                >
                                                    No medication requests found
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            medicationRequests.data.map(
                                                (request) => (
                                                    <TableRow key={request.id}>
                                                        <TableCell className="font-medium">
                                                            {request.name}
                                                        </TableCell>
                                                        <TableCell>
                                                            {request.user
                                                                ?.email || '-'}
                                                        </TableCell>
                                                        <TableCell>
                                                            {request.user
                                                                ?.active_schedule
                                                                ?.campaign
                                                                ?.name || '-'}
                                                        </TableCell>
                                                        <TableCell>
                                                            {request.user
                                                                ?.active_schedule
                                                                ?.site?.name ||
                                                                '-'}
                                                        </TableCell>
                                                        <TableCell>
                                                            {
                                                                request.medication_type
                                                            }
                                                        </TableCell>
                                                        <TableCell className="capitalize">
                                                            {request.onset_of_symptoms.replace(
                                                                /_/g,
                                                                ' ',
                                                            )}
                                                        </TableCell>
                                                        <TableCell>
                                                            {getStatusBadge(
                                                                request.status,
                                                            )}
                                                        </TableCell>
                                                        <TableCell>
                                                            {new Date(
                                                                request.created_at,
                                                            ).toLocaleDateString()}
                                                        </TableCell>
                                                        <TableCell className="text-right">
                                                            <div className="flex justify-end gap-2">
                                                                <Link
                                                                    href={
                                                                        medicationShowRoute(
                                                                            request.id,
                                                                        ).url
                                                                    }
                                                                >
                                                                    <Button
                                                                        variant="outline"
                                                                        size="icon"
                                                                        title="View Details"
                                                                    >
                                                                        <Eye className="h-4 w-4" />
                                                                    </Button>
                                                                </Link>
                                                                <Can permission="medication_requests.delete">
                                                                    <Button
                                                                        variant="outline"
                                                                        size="icon"
                                                                        onClick={() =>
                                                                            handleDelete(
                                                                                request.id,
                                                                            )
                                                                        }
                                                                        title="Delete Request"
                                                                        className="border-red-300 text-red-600 hover:text-red-700"
                                                                    >
                                                                        <Trash2 className="h-4 w-4" />
                                                                    </Button>
                                                                </Can>
                                                            </div>
                                                        </TableCell>
                                                    </TableRow>
                                                ),
                                            )
                                        )}
                                    </TableBody>
                                </Table>
                            </div>

                            {paginationLinks.length > 0 && (
                                <div className="flex justify-center border-t px-4 py-3">
                                    <PaginationNav links={paginationLinks} />
                                </div>
                            )}
                        </>
                    )}
                </div>

                {/* Mobile Card View */}
                <div className="space-y-4 md:hidden">
                    {medicationRequests.data.length === 0 ? (
                        <div className="rounded-lg border bg-card py-12 text-center text-muted-foreground">
                            No medication requests found
                        </div>
                    ) : (
                        medicationRequests.data.map((request) => (
                            <div
                                key={request.id}
                                className="space-y-3 rounded-lg border bg-card p-4 shadow-sm"
                            >
                                <div className="flex items-start justify-between">
                                    <div>
                                        <div className="text-lg font-semibold">
                                            {request.name}
                                        </div>
                                        <div className="text-sm text-muted-foreground">
                                            {request.user?.email || '-'}
                                        </div>
                                    </div>
                                    {getStatusBadge(request.status)}
                                </div>

                                <div className="space-y-2 text-sm">
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">
                                            Campaign:
                                        </span>
                                        <span className="font-medium">
                                            {request.user?.active_schedule
                                                ?.campaign?.name || '-'}
                                        </span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">
                                            Site:
                                        </span>
                                        <span className="font-medium">
                                            {request.user?.active_schedule?.site
                                                ?.name || '-'}
                                        </span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">
                                            Medication Type:
                                        </span>
                                        <span className="font-medium">
                                            {request.medication_type}
                                        </span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">
                                            Onset of Symptoms:
                                        </span>
                                        <span className="font-medium capitalize">
                                            {request.onset_of_symptoms.replace(
                                                /_/g,
                                                ' ',
                                            )}
                                        </span>
                                    </div>
                                    <div className="pt-1 text-xs text-muted-foreground">
                                        Requested:{' '}
                                        {new Date(
                                            request.created_at,
                                        ).toLocaleDateString()}
                                    </div>
                                </div>

                                <div className="flex gap-2 border-t pt-2">
                                    <Link
                                        href={
                                            medicationShowRoute(request.id).url
                                        }
                                        className="flex-1"
                                    >
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="w-full"
                                        >
                                            <Eye className="mr-2 h-4 w-4" />
                                            View
                                        </Button>
                                    </Link>
                                    <Can permission="medication_requests.delete">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                handleDelete(request.id)
                                            }
                                            className="flex-1 border-red-300 text-red-600 hover:text-red-700"
                                        >
                                            <Trash2 className="mr-2 h-4 w-4" />
                                            Delete
                                        </Button>
                                    </Can>
                                </div>
                            </div>
                        ))
                    )}

                    {paginationLinks.length > 0 && (
                        <div className="flex justify-center pt-4">
                            <PaginationNav links={paginationLinks} />
                        </div>
                    )}
                </div>

                {/* Delete Confirmation Dialog */}
                <AlertDialog
                    open={deleteDialogOpen}
                    onOpenChange={setDeleteDialogOpen}
                >
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Are you sure?</AlertDialogTitle>
                            <AlertDialogDescription>
                                This action cannot be undone. This will
                                permanently delete the medication request.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                            <AlertDialogAction onClick={confirmDelete}>
                                Delete
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </div>
        </AppLayout>
    );
}
