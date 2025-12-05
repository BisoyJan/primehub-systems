import { useState, useEffect, useCallback } from 'react';
import { Head, router, Link } from '@inertiajs/react';
import { toast } from 'sonner';
import { ArrowRight, Search, History, CheckSquare, List, RefreshCw, Play, Pause } from 'lucide-react';

import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui/select';
import { DeleteConfirmDialog } from '@/components/DeleteConfirmDialog';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import PaginationNav, { PaginationLink } from '@/components/pagination-nav';
import {
    index as pcTransfersIndexRoute,
    transferPage as pcTransfersTransferPageRoute,
    history as pcTransfersHistoryRoute,
    remove as pcTransfersRemoveRoute,
} from '@/routes/pc-transfers';
import { index as stationsIndexRoute } from '@/routes/stations';
import { usePageMeta, useFlashMessage, usePageLoading } from '@/hooks';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { Can } from '@/components/authorization';

type PcSpecDetails = {
    manufacturer: string;
    model: string;
    pc_number?: string | null;
    processor: string;
    ram_ddr: string;
    ram_gb: number;
    ram_capacities: string;
    disk_gb: number;
    disk_capacities: string;
    disk_type: string;
    issue?: string | null;
};

type Station = {
    id: number;
    station_number: string;
    site: string;
    site_id: number;
    campaign: string;
    campaign_id: number;
    status: string;
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
    filters: FilterOptions;
    flash?: { message?: string; type?: string };
};

const getInitialParam = (key: string, fallback = '') => {
    if (typeof window === 'undefined') {
        return fallback;
    }
    const params = new URLSearchParams(window.location.search);
    return params.get(key) ?? fallback;
};

export default function Index({ stations: stationsPayload, filters }: PageProps) {
    const { title, breadcrumbs } = usePageMeta({
        title: 'PC Transfer',
        breadcrumbs: [
            { title: 'Stations', href: stationsIndexRoute().url },
            { title: 'PC Transfer', href: pcTransfersIndexRoute().url },
        ]
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    const [search, setSearch] = useState(() => getInitialParam('search', ''));
    const [siteFilter, setSiteFilter] = useState(() => getInitialParam('site', 'all'));
    const [campaignFilter, setCampaignFilter] = useState(() => getInitialParam('campaign', 'all'));
    const [isFilterLoading, setIsFilterLoading] = useState(false);
    const [isMutating, setIsMutating] = useState(false);
    const [lastRefresh, setLastRefresh] = useState<Date>(new Date());
    const [autoRefreshEnabled, setAutoRefreshEnabled] = useState(false);
    const [bulkMode, setBulkMode] = useState(false);
    const [selectedStations, setSelectedStations] = useState<Set<number>>(new Set());

    const showClearFilters = Boolean(search.trim()) || siteFilter !== 'all' || campaignFilter !== 'all';

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

    const requestWithFilters = (params: Record<string, string>) => {
        setIsFilterLoading(true);
        router.get(pcTransfersIndexRoute().url, params, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
            only: ['stations'],
            onSuccess: () => setLastRefresh(new Date()),
            onFinish: () => setIsFilterLoading(false),
        });
    };

    const handleApplyFilters = () => {
        requestWithFilters(buildFilterParams());
    };

    const handleClearFilters = () => {
        setSearch('');
        setSiteFilter('all');
        setCampaignFilter('all');
        requestWithFilters({});
    };

    const handleManualRefresh = () => {
        requestWithFilters(buildFilterParams());
    };

    // Auto-refresh every 30 seconds
    useEffect(() => {
        if (!autoRefreshEnabled) return;
        const interval = setInterval(() => {
            router.get(pcTransfersIndexRoute().url, buildFilterParams(), {
                preserveScroll: true,
                preserveState: true,
                replace: true,
                only: ['stations'],
                onSuccess: () => setLastRefresh(new Date()),
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
            only: ['stations'],
            onSuccess: () => {
                toast.success('PC removed successfully');
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
    const showingStart = hasStations ? paginationMeta.per_page * (paginationMeta.current_page - 1) + 1 : 0;
    const showingEnd = hasStations ? showingStart + stationRows.length - 1 : 0;
    const summaryText = hasStations
        ? `Showing ${showingStart}-${showingEnd} of ${paginationMeta.total} stations`
        : 'No stations to display';

    const overlayMessage = isMutating ? 'Processing request...' : 'Loading transfers...';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                <LoadingOverlay isLoading={isPageLoading || isFilterLoading || isMutating} message={overlayMessage} />

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
                            <span className="hidden sm:inline">{bulkMode ? 'Cancel Bulk Mode' : 'Bulk Transfer'}</span>
                            <span className="sm:hidden">{bulkMode ? 'Cancel' : 'Bulk'}</span>
                        </Button>
                        {bulkMode && selectedStations.size > 0 && (
                            <Button
                                onClick={() => {
                                    const stationIds = Array.from(selectedStations).join(',');
                                    router.visit(`${pcTransfersTransferPageRoute().url}?stations=${stationIds}`);
                                }}
                                size="sm"
                            >
                                <List size={16} className="mr-2" />
                                <span className="hidden sm:inline">Configure Transfers</span>
                                <span className="sm:hidden">Configure</span>
                            </Button>
                        )}
                        <Link href={pcTransfersTransferPageRoute().url}>
                            <Button variant="outline" size="sm">
                                <ArrowRight size={16} className="mr-2" />
                                Transfer
                            </Button>
                        </Link>
                        <Link href={pcTransfersHistoryRoute().url}>
                            <Button variant="outline" size="sm">
                                <History size={16} className="mr-2" />
                                <span className="hidden sm:inline">View History</span>
                                <span className="sm:hidden">History</span>
                            </Button>
                        </Link>
                    </div>
                </PageHeader>

                <Card>
                    <CardHeader>
                        <CardTitle>Filters</CardTitle>
                        <CardDescription>Search and filter stations</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-4">
                            <div>
                                <Label>Search</Label>
                                <div className="relative">
                                    <Search className="absolute left-2.5 top-3 h-4 w-4 text-muted-foreground" />
                                    <Input
                                        type="search"
                                        placeholder="Station, site, campaign..."
                                        className="pl-8"
                                        value={search}
                                        onChange={(event) => setSearch(event.target.value)}
                                        onKeyDown={(event) => event.key === 'Enter' && handleApplyFilters()}
                                    />
                                </div>
                            </div>

                            <div>
                                <Label>Site</Label>
                                <Select value={siteFilter} onValueChange={setSiteFilter}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Sites</SelectItem>
                                        {filters.sites.map((site) => (
                                            <SelectItem key={site.id} value={String(site.id)}>
                                                {site.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <Label>Campaign</Label>
                                <Select value={campaignFilter} onValueChange={setCampaignFilter}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Campaigns</SelectItem>
                                        {filters.campaigns.map((campaign) => (
                                            <SelectItem key={campaign.id} value={String(campaign.id)}>
                                                {campaign.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-end">
                                <Button onClick={handleApplyFilters} disabled={isFilterLoading} className="w-full md:w-auto">
                                    <Search className="h-4 w-4 mr-2" />
                                    Apply Filters
                                </Button>
                                {showClearFilters && (
                                    <Button
                                        variant="outline"
                                        onClick={handleClearFilters}
                                        disabled={isFilterLoading}
                                        className="w-full md:w-auto"
                                    >
                                        Clear Filters
                                    </Button>
                                )}
                                <div className="flex gap-2 justify-end">
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
                                        variant={autoRefreshEnabled ? "default" : "ghost"}
                                        size="icon"
                                        onClick={() => setAutoRefreshEnabled(!autoRefreshEnabled)}
                                        title={autoRefreshEnabled ? "Disable auto-refresh" : "Enable auto-refresh (30s)"}
                                    >
                                        {autoRefreshEnabled ? <Pause className="h-4 w-4" /> : <Play className="h-4 w-4" />}
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <div className="flex flex-col gap-2 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
                    <span>
                        {summaryText}
                        {showClearFilters && hasStations ? ' (filtered)' : ''}
                    </span>
                    <span className="text-xs">Last updated: {lastRefresh.toLocaleTimeString()}</span>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Stations</CardTitle>
                        <CardDescription>
                            {bulkMode
                                ? 'Select multiple stations for bulk transfer'
                                : 'Click "Transfer PC" to assign or swap PC specs between stations'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {/* Desktop Table View */}
                        <div className="hidden md:block overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        {bulkMode && <TableHead className="w-12">Select</TableHead>}
                                        <TableHead>Station</TableHead>
                                        <TableHead>Site</TableHead>
                                        <TableHead>Campaign</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Current PC</TableHead>
                                        <TableHead>PC Details</TableHead>
                                        <TableHead className="text-right">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {stationRows.map((station) => (
                                        <TableRow
                                            key={station.id}
                                            className={bulkMode && selectedStations.has(station.id) ? 'bg-blue-800' : ''}
                                        >
                                            {bulkMode && (
                                                <TableCell>
                                                    <input
                                                        type="checkbox"
                                                        checked={selectedStations.has(station.id)}
                                                        onChange={() => toggleStationSelection(station.id)}
                                                        className="w-4 h-4 cursor-pointer"
                                                        aria-label={`Select station ${station.station_number}`}
                                                    />
                                                </TableCell>
                                            )}
                                            <TableCell className="font-medium">
                                                {station.station_number}
                                            </TableCell>
                                            <TableCell>{station.site}</TableCell>
                                            <TableCell>{station.campaign}</TableCell>
                                            <TableCell>
                                                <Badge
                                                    className={
                                                        station.status.toLowerCase() === 'occupied'
                                                            ? 'bg-green-500 hover:bg-green-600 text-white'
                                                            : station.status.toLowerCase() === 'vacant'
                                                                ? 'bg-yellow-500 hover:bg-yellow-600 text-white'
                                                                : station.status.toLowerCase() === 'no pc'
                                                                    ? 'bg-red-500 hover:bg-red-600 text-white'
                                                                    : station.status.toLowerCase() === 'admin'
                                                                        ? 'bg-blue-500 hover:bg-blue-600 text-white'
                                                                        : 'bg-gray-500 hover:bg-gray-600 text-white'
                                                    }
                                                >
                                                    {station.status}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                {station.pc_spec_details ? (
                                                    <div>
                                                        <span className="font-medium text-green-600">
                                                            {station.pc_spec_details.model}
                                                        </span>
                                                        {station.pc_spec_details.pc_number && (
                                                            <div className="text-xs text-blue-600 mt-1">
                                                                PC: {station.pc_spec_details.pc_number}
                                                            </div>
                                                        )}
                                                    </div>
                                                ) : (
                                                    <span className="text-gray-400">No PC assigned</span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {station.pc_spec_details && (
                                                    <div className="text-xs text-muted-foreground">
                                                        <div>{station.pc_spec_details.processor}</div>
                                                        <div>
                                                            {station.pc_spec_details.ram_ddr} {station.pc_spec_details.ram_gb}GB RAM
                                                        </div>
                                                        <div>
                                                            {station.pc_spec_details.disk_type} {station.pc_spec_details.disk_gb}GB
                                                        </div>
                                                    </div>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {!bulkMode && (
                                                    <div className="flex justify-end gap-2">
                                                        <Can permission="pc_transfers.create">
                                                            <Link href={pcTransfersTransferPageRoute(station.id).url}>
                                                                <Button size="sm">
                                                                    <ArrowRight size={14} className="mr-1" />
                                                                    {station.pc_spec_id ? 'Transfer' : 'Assign'}
                                                                </Button>
                                                            </Link>
                                                        </Can>
                                                        {station.pc_spec_id && (
                                                            <Can permission="pc_transfers.remove">
                                                                <DeleteConfirmDialog
                                                                    onConfirm={() => handleRemovePC(station)}
                                                                    title="Unassign PC from Station"
                                                                    description={`Are you sure you want to unassign the PC from station "${station.station_number}"? The PC will become available for assignment to other stations.`}
                                                                    triggerLabel="Unassign"
                                                                    disabled={isMutating}
                                                                />
                                                            </Can>
                                                        )}
                                                    </div>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}

                                    {stationRows.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={8} className="text-center py-8 text-gray-500">
                                                No stations found
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </div>

                        {/* Mobile Card View */}
                        <div className="md:hidden space-y-4">
                            {stationRows.length === 0 ? (
                                <div className="text-center py-8 text-gray-500">
                                    No stations found
                                </div>
                            ) : (
                                stationRows.map((station) => (
                                    <div
                                        key={station.id}
                                        className={`bg-card border rounded-lg p-4 shadow-sm space-y-3 ${bulkMode && selectedStations.has(station.id) ? 'bg-blue-100 dark:bg-blue-900/30 ring-2 ring-blue-500' : ''
                                            }`}
                                    >
                                        <div className="flex justify-between items-start">
                                            <div className="flex items-center gap-3">
                                                {bulkMode && (
                                                    <input
                                                        type="checkbox"
                                                        checked={selectedStations.has(station.id)}
                                                        onChange={() => toggleStationSelection(station.id)}
                                                        className="w-5 h-5 cursor-pointer"
                                                        aria-label={`Select station ${station.station_number}`}
                                                    />
                                                )}
                                                <div>
                                                    <div className="font-semibold text-lg">{station.station_number}</div>
                                                    <div className="text-sm text-muted-foreground">
                                                        {station.site} • {station.campaign}
                                                    </div>
                                                </div>
                                            </div>
                                            <Badge
                                                className={
                                                    station.status.toLowerCase() === 'occupied'
                                                        ? 'bg-green-500 hover:bg-green-600 text-white'
                                                        : station.status.toLowerCase() === 'vacant'
                                                            ? 'bg-yellow-500 hover:bg-yellow-600 text-white'
                                                            : station.status.toLowerCase() === 'no pc'
                                                                ? 'bg-red-500 hover:bg-red-600 text-white'
                                                                : station.status.toLowerCase() === 'admin'
                                                                    ? 'bg-blue-500 hover:bg-blue-600 text-white'
                                                                    : 'bg-gray-500 hover:bg-gray-600 text-white'
                                                }
                                            >
                                                {station.status}
                                            </Badge>
                                        </div>

                                        <div className="space-y-2 text-sm">
                                            <div className="flex justify-between">
                                                <span className="text-muted-foreground">Current PC:</span>
                                                <span className="font-medium">
                                                    {station.pc_spec_details ? (
                                                        <span className="text-green-600">
                                                            {station.pc_spec_details.model}
                                                            {station.pc_spec_details.pc_number && (
                                                                <span className="text-blue-600 ml-1">({station.pc_spec_details.pc_number})</span>
                                                            )}
                                                        </span>
                                                    ) : (
                                                        <span className="text-gray-400">No PC assigned</span>
                                                    )}
                                                </span>
                                            </div>
                                            {station.pc_spec_details && (
                                                <div className="text-xs text-muted-foreground bg-muted/50 rounded p-2">
                                                    <div>{station.pc_spec_details.processor}</div>
                                                    <div>{station.pc_spec_details.ram_ddr} {station.pc_spec_details.ram_gb}GB RAM</div>
                                                    <div>{station.pc_spec_details.disk_type} {station.pc_spec_details.disk_gb}GB</div>
                                                </div>
                                            )}
                                        </div>

                                        {!bulkMode && (
                                            <div className="flex gap-2 pt-2 border-t">
                                                <Can permission="pc_transfers.create">
                                                    <Link href={pcTransfersTransferPageRoute(station.id).url} className="flex-1">
                                                        <Button size="sm" className="w-full">
                                                            <ArrowRight size={14} className="mr-1" />
                                                            {station.pc_spec_id ? 'Transfer' : 'Assign'}
                                                        </Button>
                                                    </Link>
                                                </Can>
                                                {station.pc_spec_id && (
                                                    <Can permission="pc_transfers.remove">
                                                        <DeleteConfirmDialog
                                                            onConfirm={() => handleRemovePC(station)}
                                                            title="Unassign PC from Station"
                                                            description={`Are you sure you want to unassign the PC from station "${station.station_number}"? The PC will become available for assignment to other stations.`}
                                                            triggerLabel="Unassign"
                                                            disabled={isMutating}
                                                        />
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

                <div className="flex justify-center mt-4">
                    {paginationLinks.length > 0 && (
                        <PaginationNav links={paginationLinks} only={['stations']} />
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
