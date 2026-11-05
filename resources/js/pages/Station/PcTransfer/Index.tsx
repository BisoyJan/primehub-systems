import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeftRight,
    ArrowRight,
    CheckSquare,
    History,
    LayoutGrid,
    List,
    Pause,
    Play,
    RefreshCw,
    Rows3,
    Search,
    Trash2,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import { DeleteConfirmDialog } from '@/components/DeleteConfirmDialog';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { PageHeader } from '@/components/PageHeader';
import { Can } from '@/components/authorization';
import PaginationNav, { PaginationLink } from '@/components/pagination-nav';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    useDebounce,
    useFlashMessage,
    usePageLoading,
    usePageMeta,
    withReturnTo,
} from '@/hooks';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import {
    history as pcTransfersHistoryRoute,
    index as pcTransfersIndexRoute,
    remove as pcTransfersRemoveRoute,
    transferPage as pcTransfersTransferPageRoute,
} from '@/routes/pc-transfers';
import { index as stationsIndexRoute } from '@/routes/stations';
import {
    QuickTransferDialog,
    type PcSpecOption,
    type StationOption,
} from './components/QuickTransferDialog';

const MUTATION_RELOAD_PROPS = ['stations', 'pcSpecs', 'stationOptions'];
const VIEW_MODE_STORAGE_KEY = 'pc-transfers.view-mode';

type ViewMode = 'table' | 'cards';

const readStoredViewMode = (): ViewMode => {
    if (typeof window === 'undefined') {
        return 'cards';
    }
    return window.localStorage.getItem(VIEW_MODE_STORAGE_KEY) === 'table'
        ? 'table'
        : 'cards';
};

type PcSpecDetails = {
    id: number;
    pc_number?: string | null;
    manufacturer: string | null;
    memory_type: string | null;
    ram_gb: number | null;
    disk_gb: number | null;
    available_ports?: string | null;
    issue?: string | null;
    notes?: string | null;
    processor: string;
};

type Station = {
    id: number;
    station_number: string;
    site: string;
    site_id: number;
    campaign: string;
    campaign_id: number;
    status: string | null;
    monitor_type: string;
    pc_spec_id: number | null;
    pc_spec_details: PcSpecDetails | null;
};

type Site = {
    id: number;
    name: string;
};

type Campaign = {
    id: number;
    name: string;
};

type StationsPayload = {
    data: Station[];
    links?: PaginationLink[];
    meta?: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
};

type FilterOptions = {
    sites: Site[];
    campaigns: Campaign[];
};

type PageProps = {
    stations: StationsPayload;
    pcSpecs: PcSpecOption[];
    stationOptions: StationOption[];
    filters: FilterOptions;
};

const getInitialParam = (key: string, fallback = '') => {
    if (typeof window === 'undefined') {
        return fallback;
    }
    const params = new URLSearchParams(window.location.search);
    return params.get(key) ?? fallback;
};

const toStationOption = (station: Station): StationOption => ({
    id: station.id,
    station_number: station.station_number,
    site: station.site,
    site_id: station.site_id,
    campaign_id: station.campaign_id,
    pc_spec_id: station.pc_spec_id,
    pc_number: station.pc_spec_details?.pc_number ?? null,
});

const statusBadgeClass = (status: string) => {
    switch (status.toLowerCase()) {
        case 'occupied':
            return 'bg-green-500 text-white';
        case 'vacant':
            return 'bg-yellow-500 text-white';
        case 'no pc':
            return 'bg-red-500 text-white';
        case 'admin':
            return 'bg-blue-500 text-white';
        default:
            return 'bg-gray-500 text-white';
    }
};

/** Where: station number, status, and its site · campaign on one compact block. */
function StationCell({
    station,
    align = 'left',
}: {
    station: Station;
    align?: 'left' | 'right';
}) {
    const location = [station.site, station.campaign]
        .filter(Boolean)
        .join(' · ');
    const right = align === 'right';

    return (
        <div className={cn('min-w-0', right && 'text-right')}>
            <div
                className={cn(
                    'flex items-center gap-2',
                    right && 'flex-row-reverse justify-start',
                )}
            >
                <span className="text-base leading-tight font-semibold">
                    {station.station_number}
                </span>
                {station.status && (
                    <Badge
                        className={cn(
                            'h-5 px-1.5 text-[10px] tracking-wide uppercase hover:bg-inherit',
                            statusBadgeClass(station.status),
                        )}
                    >
                        {station.status}
                    </Badge>
                )}
            </div>
            <div className="truncate text-xs text-muted-foreground">
                {location || '—'}
            </div>
        </div>
    );
}

/** What: PC number + make on line one, full spec summary on line two. */
function PcCell({ details }: { details: PcSpecDetails | null }) {
    if (!details) {
        return (
            <span className="inline-flex items-center rounded border border-dashed px-2 py-0.5 text-xs text-muted-foreground">
                No PC assigned
            </span>
        );
    }

    const specs = [
        details.processor,
        details.ram_gb
            ? `${details.ram_gb}GB${details.memory_type ? ` ${details.memory_type}` : ''}`
            : null,
        details.disk_gb ? `${details.disk_gb}GB` : null,
        details.available_ports,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <div className="min-w-0">
            <div className="flex items-center gap-2">
                <span className="leading-tight font-semibold text-blue-600 dark:text-blue-400">
                    {details.pc_number ?? 'Unnumbered'}
                </span>
                {details.manufacturer && (
                    <span className="text-sm text-muted-foreground">
                        {details.manufacturer}
                    </span>
                )}
                {details.issue && (
                    <Badge
                        variant="outline"
                        className="h-5 border-amber-500 px-1.5 text-[10px] text-amber-600"
                        title={details.issue}
                    >
                        Issue
                    </Badge>
                )}
            </div>
            <div
                className="truncate text-xs text-muted-foreground"
                title={specs}
            >
                {specs || '—'}
            </div>
        </div>
    );
}

export default function Index({
    stations: stationsPayload,
    pcSpecs,
    stationOptions,
    filters,
}: PageProps) {
    const { title, breadcrumbs } = usePageMeta({
        title: 'PC Transfer',
        breadcrumbs: [
            { title: 'Stations', href: stationsIndexRoute().url },
            { title: 'PC Transfer', href: pcTransfersIndexRoute().url },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    const [search, setSearch] = useState(() => getInitialParam('search', ''));
    const [siteFilter, setSiteFilter] = useState(() =>
        getInitialParam('site', 'all'),
    );
    const [campaignFilter, setCampaignFilter] = useState(() =>
        getInitialParam('campaign', 'all'),
    );
    const [isFilterLoading, setIsFilterLoading] = useState(false);
    const [isMutating, setIsMutating] = useState(false);
    const [lastRefresh, setLastRefresh] = useState<Date>(new Date());
    const [autoRefreshEnabled, setAutoRefreshEnabled] = useState(false);
    const [bulkMode, setBulkMode] = useState(false);
    const [selectedStations, setSelectedStations] = useState<Set<number>>(
        new Set(),
    );
    const [quickTransferStation, setQuickTransferStation] =
        useState<StationOption | null>(null);
    const [viewMode, setViewMode] = useState<ViewMode>(readStoredViewMode);

    useEffect(() => {
        window.localStorage.setItem(VIEW_MODE_STORAGE_KEY, viewMode);
    }, [viewMode]);

    const debouncedSearch = useDebounce(search, 400);
    const showClearFilters =
        Boolean(search.trim()) ||
        siteFilter !== 'all' ||
        campaignFilter !== 'all';

    const buildFilterParams = useCallback(() => {
        const params: Record<string, string> = {};
        if (search.trim()) {
            params.search = search.trim();
        }
        if (siteFilter !== 'all') {
            params.site = siteFilter;
        }
        if (campaignFilter !== 'all') {
            params.campaign = campaignFilter;
        }
        return params;
    }, [search, siteFilter, campaignFilter]);

    const requestWithFilters = useCallback((params: Record<string, string>) => {
        setIsFilterLoading(true);
        router.get(pcTransfersIndexRoute().url, params, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
            only: ['stations'],
            onSuccess: () => setLastRefresh(new Date()),
            onFinish: () => setIsFilterLoading(false),
        });
    }, []);

    // Auto-apply filters; the ref holds the last applied query so mount and
    // StrictMode re-runs never fire a redundant request.
    const lastAppliedRef = useRef<string | null>(null);
    useEffect(() => {
        const params: Record<string, string> = {};
        if (debouncedSearch.trim()) params.search = debouncedSearch.trim();
        if (siteFilter !== 'all') params.site = siteFilter;
        if (campaignFilter !== 'all') params.campaign = campaignFilter;
        const serialized = new URLSearchParams(params).toString();

        if (lastAppliedRef.current === null) {
            lastAppliedRef.current = serialized;
            return;
        }
        if (lastAppliedRef.current === serialized) {
            return;
        }
        lastAppliedRef.current = serialized;
        requestWithFilters(params);
    }, [debouncedSearch, siteFilter, campaignFilter, requestWithFilters]);

    const handleClearFilters = () => {
        setSearch('');
        setSiteFilter('all');
        setCampaignFilter('all');
    };

    const handleManualRefresh = () => {
        requestWithFilters(buildFilterParams());
    };

    const transferPageUrl = (stationIds?: number[]) => {
        const query: Record<string, string> = { ...buildFilterParams() };
        if (stationIds && stationIds.length > 0) {
            query.stations = stationIds.join(',');
        }
        return withReturnTo(
            pcTransfersTransferPageRoute(undefined, { query }).url,
        );
    };

    // Auto-refresh every 30 seconds
    const isPollingRef = useRef(false);
    useEffect(() => {
        if (!autoRefreshEnabled) return;
        const interval = setInterval(() => {
            if (isPollingRef.current) return;
            isPollingRef.current = true;
            router.get(pcTransfersIndexRoute().url, buildFilterParams(), {
                preserveScroll: true,
                preserveState: true,
                replace: true,
                only: ['stations'],
                onSuccess: () => setLastRefresh(new Date()),
                onFinish: () => {
                    isPollingRef.current = false;
                },
            });
        }, 30000);

        return () => clearInterval(interval);
    }, [autoRefreshEnabled, buildFilterParams]);

    const handleRemovePC = (station: Station) => {
        if (!station.pc_spec_id) {
            toast.error('No PC assigned to this station');
            return;
        }

        setIsMutating(true);
        router.delete(pcTransfersRemoveRoute().url, {
            data: { station_id: station.id },
            preserveScroll: true,
            preserveState: true,
            replace: true,
            only: MUTATION_RELOAD_PROPS,
            onSuccess: () => {
                toast.success(
                    `${station.pc_spec_details?.pc_number ?? 'PC'} unassigned from ${station.station_number}`,
                );
                setLastRefresh(new Date());
            },
            onError: () => toast.error('Removal failed'),
            onFinish: () => setIsMutating(false),
        });
    };

    const toggleBulkMode = () => {
        setBulkMode((prev) => !prev);
        setSelectedStations(new Set());
    };

    const toggleStationSelection = (stationId: number) => {
        setSelectedStations((prev) => {
            const updated = new Set(prev);
            if (updated.has(stationId)) {
                updated.delete(stationId);
            } else {
                updated.add(stationId);
            }
            return updated;
        });
    };

    const stationRows = stationsPayload.data;

    const paginationMeta = stationsPayload.meta || {
        current_page: 1,
        last_page: 1,
        per_page: stationRows.length || 1,
        total: stationRows.length,
    };
    const paginationLinks = stationsPayload.links || [];
    const hasStations = stationRows.length > 0;
    const showingStart = hasStations
        ? paginationMeta.per_page * (paginationMeta.current_page - 1) + 1
        : 0;
    const showingEnd = hasStations ? showingStart + stationRows.length - 1 : 0;
    const summaryText = hasStations
        ? `Showing ${showingStart}-${showingEnd} of ${paginationMeta.total} stations`
        : 'No stations to display';

    const overlayMessage = isMutating
        ? 'Processing request...'
        : 'Loading transfers...';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="relative flex h-full flex-1 flex-col gap-4 rounded-xl p-3">
                <LoadingOverlay
                    isLoading={isPageLoading || isFilterLoading || isMutating}
                    message={overlayMessage}
                />

                <PageHeader
                    title="PC Transfer Management"
                    description="Transfer PCs between stations and manage configurations"
                >
                    <div className="flex flex-wrap items-center gap-2">
                        {bulkMode && selectedStations.size > 0 && (
                            <Badge variant="secondary" className="px-3 py-1">
                                {selectedStations.size} selected
                            </Badge>
                        )}
                        <Button
                            variant={bulkMode ? 'default' : 'outline'}
                            onClick={toggleBulkMode}
                            size="sm"
                            className="sm:size-default"
                        >
                            <CheckSquare size={16} className="mr-2" />
                            <span className="hidden sm:inline">
                                {bulkMode
                                    ? 'Cancel Bulk Mode'
                                    : 'Bulk Transfer'}
                            </span>
                            <span className="sm:hidden">
                                {bulkMode ? 'Cancel' : 'Bulk'}
                            </span>
                        </Button>
                        {bulkMode && selectedStations.size > 0 && (
                            <Button
                                onClick={() =>
                                    router.visit(
                                        transferPageUrl(
                                            Array.from(selectedStations),
                                        ),
                                    )
                                }
                                size="sm"
                            >
                                <List size={16} className="mr-2" />
                                <span className="hidden sm:inline">
                                    Configure Transfers
                                </span>
                                <span className="sm:hidden">Configure</span>
                            </Button>
                        )}
                        <Can permission="pc_transfers.create">
                            <Link href={transferPageUrl()}>
                                <Button variant="outline" size="sm">
                                    <ArrowLeftRight
                                        size={16}
                                        className="mr-2"
                                    />
                                    <span className="hidden sm:inline">
                                        Transfer Workspace
                                    </span>
                                    <span className="sm:hidden">Workspace</span>
                                </Button>
                            </Link>
                        </Can>
                        <Can permission="pc_transfers.history">
                            <Link href={pcTransfersHistoryRoute().url}>
                                <Button variant="outline" size="sm">
                                    <History size={16} className="mr-2" />
                                    <span className="hidden sm:inline">
                                        View History
                                    </span>
                                    <span className="sm:hidden">History</span>
                                </Button>
                            </Link>
                        </Can>
                    </div>
                </PageHeader>

                <div className="space-y-4 rounded-lg border bg-card p-3">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div className="space-y-1.5">
                            <Label className="text-xs text-muted-foreground">
                                Search
                            </Label>
                            <div className="relative">
                                <Search className="absolute top-3 left-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    type="search"
                                    placeholder="Station, site, campaign..."
                                    className="pl-8"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                />
                            </div>
                        </div>

                        <div className="space-y-1.5">
                            <Label className="text-xs text-muted-foreground">
                                Site
                            </Label>
                            <Select
                                value={siteFilter}
                                onValueChange={setSiteFilter}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="All Sites" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All Sites
                                    </SelectItem>
                                    {filters.sites.map((site) => (
                                        <SelectItem
                                            key={site.id}
                                            value={String(site.id)}
                                        >
                                            {site.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1.5">
                            <Label className="text-xs text-muted-foreground">
                                Campaign
                            </Label>
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
                                    {filters.campaigns.map((campaign) => (
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
                    </div>

                    <div className="flex items-center justify-between gap-2 border-t pt-2">
                        <div className="flex items-center gap-2">
                            <Button
                                variant="ghost"
                                size="icon"
                                onClick={handleManualRefresh}
                                disabled={isFilterLoading}
                                title="Refresh"
                            >
                                <RefreshCw className="h-4 w-4" />
                            </Button>
                            <Button
                                variant={
                                    autoRefreshEnabled ? 'secondary' : 'ghost'
                                }
                                size="icon"
                                onClick={() =>
                                    setAutoRefreshEnabled(!autoRefreshEnabled)
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
                        <div className="flex items-center gap-2">
                            {showClearFilters && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={handleClearFilters}
                                    disabled={isFilterLoading}
                                >
                                    Reset
                                </Button>
                            )}
                            <span className="text-xs text-muted-foreground">
                                Filters apply automatically
                            </span>
                        </div>
                    </div>
                </div>

                <div className="flex flex-col gap-2 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
                    <span>
                        {summaryText}
                        {showClearFilters && hasStations ? ' (filtered)' : ''}
                    </span>
                    <span className="text-xs">
                        Last updated: {lastRefresh.toLocaleTimeString()}
                    </span>
                </div>

                <Card>
                    <CardHeader className="flex flex-row items-start justify-between gap-3 space-y-0">
                        <div>
                            <CardTitle>Stations</CardTitle>
                            <CardDescription>
                                {bulkMode
                                    ? 'Select multiple stations, then configure them together in the workspace'
                                    : 'Assign, move, swap or unassign a PC directly from its row'}
                            </CardDescription>
                        </div>
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            size="sm"
                            value={viewMode}
                            onValueChange={(value) =>
                                value && setViewMode(value as ViewMode)
                            }
                            aria-label="View mode"
                            className="hidden md:flex"
                        >
                            <ToggleGroupItem
                                value="cards"
                                aria-label="Card view"
                                title="Card view"
                            >
                                <LayoutGrid className="h-4 w-4" />
                            </ToggleGroupItem>
                            <ToggleGroupItem
                                value="table"
                                aria-label="Table view"
                                title="Table view"
                            >
                                <Rows3 className="h-4 w-4" />
                            </ToggleGroupItem>
                        </ToggleGroup>
                    </CardHeader>
                    <CardContent>
                        {/* Table view (desktop only) */}
                        <div
                            className={cn(
                                'hidden overflow-x-auto',
                                viewMode === 'table' && 'md:block',
                            )}
                        >
                            <Table>
                                <TableHeader>
                                    <TableRow className="bg-muted/50">
                                        {bulkMode && (
                                            <TableHead className="w-12">
                                                <span className="sr-only">
                                                    Select
                                                </span>
                                            </TableHead>
                                        )}
                                        <TableHead className="w-2/5 pr-4 text-right">
                                            Station
                                        </TableHead>
                                        <TableHead className="pl-4">
                                            Current PC
                                        </TableHead>
                                        <TableHead className="w-px text-right whitespace-nowrap">
                                            Actions
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {stationRows.length === 0 ? (
                                        <TableRow>
                                            <TableCell
                                                colSpan={bulkMode ? 4 : 3}
                                                className="py-8 text-center text-muted-foreground"
                                            >
                                                No stations found
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        stationRows.map((station) => (
                                            <TableRow
                                                key={station.id}
                                                onClick={() =>
                                                    bulkMode &&
                                                    toggleStationSelection(
                                                        station.id,
                                                    )
                                                }
                                                className={cn(
                                                    bulkMode &&
                                                        'cursor-pointer',
                                                    bulkMode &&
                                                        selectedStations.has(
                                                            station.id,
                                                        ) &&
                                                        'bg-blue-50 dark:bg-blue-950/40',
                                                )}
                                            >
                                                {bulkMode && (
                                                    <TableCell>
                                                        <input
                                                            type="checkbox"
                                                            checked={selectedStations.has(
                                                                station.id,
                                                            )}
                                                            onChange={() =>
                                                                toggleStationSelection(
                                                                    station.id,
                                                                )
                                                            }
                                                            onClick={(event) =>
                                                                event.stopPropagation()
                                                            }
                                                            className="h-4 w-4 cursor-pointer"
                                                            aria-label={`Select station ${station.station_number}`}
                                                        />
                                                    </TableCell>
                                                )}
                                                <TableCell className="w-2/5 py-2.5 pr-4">
                                                    <StationCell
                                                        station={station}
                                                        align="right"
                                                    />
                                                </TableCell>
                                                <TableCell className="py-2.5 pl-4">
                                                    <PcCell
                                                        details={
                                                            station.pc_spec_details
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell className="py-2.5 text-right">
                                                    {!bulkMode && (
                                                        <div className="flex justify-end gap-1.5">
                                                            <Can permission="pc_transfers.create">
                                                                <Button
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        setQuickTransferStation(
                                                                            toStationOption(
                                                                                station,
                                                                            ),
                                                                        )
                                                                    }
                                                                >
                                                                    <ArrowRight
                                                                        size={
                                                                            14
                                                                        }
                                                                        className="mr-1"
                                                                    />
                                                                    {station.pc_spec_id
                                                                        ? 'Transfer'
                                                                        : 'Assign'}
                                                                </Button>
                                                            </Can>
                                                            {station.pc_spec_id && (
                                                                <Can permission="pc_transfers.remove">
                                                                    <DeleteConfirmDialog
                                                                        onConfirm={() =>
                                                                            handleRemovePC(
                                                                                station,
                                                                            )
                                                                        }
                                                                        title="Unassign PC from Station"
                                                                        description={`Are you sure you want to unassign the PC from station "${station.station_number}"? The PC will become available for assignment to other stations.`}
                                                                        trigger={
                                                                            <Button
                                                                                size="sm"
                                                                                variant="outline"
                                                                                className="px-2 text-destructive hover:text-destructive"
                                                                                disabled={
                                                                                    isMutating
                                                                                }
                                                                                title="Unassign PC"
                                                                            >
                                                                                <Trash2
                                                                                    size={
                                                                                        14
                                                                                    }
                                                                                />
                                                                                <span className="sr-only">
                                                                                    Unassign
                                                                                </span>
                                                                            </Button>
                                                                        }
                                                                    />
                                                                </Can>
                                                            )}
                                                            {station
                                                                .pc_spec_details
                                                                ?.pc_number && (
                                                                <Can permission="pc_transfers.history">
                                                                    <Link
                                                                        href={
                                                                            pcTransfersHistoryRoute(
                                                                                {
                                                                                    query: {
                                                                                        search: station
                                                                                            .pc_spec_details
                                                                                            .pc_number,
                                                                                    },
                                                                                },
                                                                            )
                                                                                .url
                                                                        }
                                                                        title={`History for ${station.pc_spec_details.pc_number}`}
                                                                    >
                                                                        <Button
                                                                            size="sm"
                                                                            variant="ghost"
                                                                            className="px-2"
                                                                        >
                                                                            <History
                                                                                size={
                                                                                    14
                                                                                }
                                                                            />
                                                                            <span className="sr-only">
                                                                                PC
                                                                                history
                                                                            </span>
                                                                        </Button>
                                                                    </Link>
                                                                </Can>
                                                            )}
                                                        </div>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </div>

                        {/* Card view: always on mobile, opt-in grid on desktop */}
                        <div
                            className={cn(
                                'space-y-3',
                                viewMode === 'cards'
                                    ? 'md:grid md:grid-cols-2 md:gap-3 md:space-y-0 xl:grid-cols-3'
                                    : 'md:hidden',
                            )}
                        >
                            {stationRows.length === 0 ? (
                                <div className="py-8 text-center text-muted-foreground md:col-span-full">
                                    No stations found
                                </div>
                            ) : (
                                stationRows.map((station) => (
                                    <div
                                        key={station.id}
                                        onClick={() =>
                                            bulkMode &&
                                            toggleStationSelection(station.id)
                                        }
                                        className={cn(
                                            'flex flex-col gap-3 rounded-lg border bg-card p-3 shadow-sm',
                                            bulkMode && 'cursor-pointer',
                                            bulkMode &&
                                                selectedStations.has(
                                                    station.id,
                                                ) &&
                                                'bg-blue-50 ring-2 ring-blue-500 dark:bg-blue-950/40',
                                        )}
                                    >
                                        <div className="flex items-start gap-3">
                                            {bulkMode && (
                                                <input
                                                    type="checkbox"
                                                    checked={selectedStations.has(
                                                        station.id,
                                                    )}
                                                    onChange={() =>
                                                        toggleStationSelection(
                                                            station.id,
                                                        )
                                                    }
                                                    onClick={(event) =>
                                                        event.stopPropagation()
                                                    }
                                                    className="mt-1 h-5 w-5 cursor-pointer"
                                                    aria-label={`Select station ${station.station_number}`}
                                                />
                                            )}
                                            <StationCell station={station} />
                                        </div>

                                        <div className="flex-1 rounded-md bg-muted/40 p-2">
                                            <PcCell
                                                details={
                                                    station.pc_spec_details
                                                }
                                            />
                                        </div>

                                        {!bulkMode && (
                                            <div className="flex gap-2 pt-1">
                                                <Can permission="pc_transfers.create">
                                                    <Button
                                                        size="sm"
                                                        className="flex-1"
                                                        onClick={() =>
                                                            setQuickTransferStation(
                                                                toStationOption(
                                                                    station,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        <ArrowRight
                                                            size={14}
                                                            className="mr-1"
                                                        />
                                                        {station.pc_spec_id
                                                            ? 'Transfer'
                                                            : 'Assign'}
                                                    </Button>
                                                </Can>
                                                {station.pc_spec_id && (
                                                    <Can permission="pc_transfers.remove">
                                                        <DeleteConfirmDialog
                                                            onConfirm={() =>
                                                                handleRemovePC(
                                                                    station,
                                                                )
                                                            }
                                                            title="Unassign PC from Station"
                                                            description={`Are you sure you want to unassign the PC from station "${station.station_number}"? The PC will become available for assignment to other stations.`}
                                                            trigger={
                                                                <Button
                                                                    size="sm"
                                                                    variant="outline"
                                                                    className="px-2 text-destructive hover:text-destructive"
                                                                    disabled={
                                                                        isMutating
                                                                    }
                                                                    title="Unassign PC"
                                                                >
                                                                    <Trash2
                                                                        size={
                                                                            14
                                                                        }
                                                                    />
                                                                    <span className="sr-only">
                                                                        Unassign
                                                                    </span>
                                                                </Button>
                                                            }
                                                        />
                                                    </Can>
                                                )}
                                                {station.pc_spec_details
                                                    ?.pc_number && (
                                                    <Can permission="pc_transfers.history">
                                                        <Link
                                                            href={
                                                                pcTransfersHistoryRoute(
                                                                    {
                                                                        query: {
                                                                            search: station
                                                                                .pc_spec_details
                                                                                .pc_number,
                                                                        },
                                                                    },
                                                                ).url
                                                            }
                                                        >
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                className="px-2"
                                                            >
                                                                <History
                                                                    size={14}
                                                                />
                                                                <span className="sr-only">
                                                                    PC history
                                                                </span>
                                                            </Button>
                                                        </Link>
                                                    </Can>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                ))
                            )}
                        </div>
                    </CardContent>
                </Card>

                <div className="mt-4 flex justify-center">
                    {paginationLinks.length > 0 && (
                        <PaginationNav
                            links={paginationLinks}
                            only={['stations']}
                        />
                    )}
                </div>

                <QuickTransferDialog
                    open={quickTransferStation !== null}
                    onOpenChange={(open) =>
                        !open && setQuickTransferStation(null)
                    }
                    station={quickTransferStation}
                    pcSpecs={pcSpecs}
                    stationOptions={stationOptions}
                    sites={filters.sites}
                    reloadProps={MUTATION_RELOAD_PROPS}
                    onSuccess={() => setLastRefresh(new Date())}
                />
            </div>
        </AppLayout>
    );
}
