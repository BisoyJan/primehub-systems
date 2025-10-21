import { useEffect, useState, useCallback } from 'react';
import { Head, usePage, router, Link } from '@inertiajs/react';
import { toast } from 'sonner';
import { ArrowRight, Search, Trash2, History, CheckSquare, List } from 'lucide-react';

import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui/select';
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
import { index as pcTransfersIndex, transferPage } from '@/routes/pc-transfers';

// New reusable components and hooks
import { usePageMeta, useFlashMessage, usePageLoading } from '@/hooks';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';

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

type PcSpec = {
    id: number;
    label: string;
    details: PcSpecDetails;
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
    links: PaginationLink[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
};

type PageProps = {
    stations: StationsPayload;
    pcSpecs: PcSpec[];
    filters: {
        sites: Site[];
        campaigns: Campaign[];
    };
    flash?: { message?: string; type?: string };
};

export default function Index() {
    const page = usePage<PageProps>();
    const props = page.props;

    // Use new hooks for cleaner code
    const { title, breadcrumbs } = usePageMeta({
        title: 'PC Transfer',
        breadcrumbs: [{ title: 'PC Transfer', href: pcTransfersIndex().url }]
    });

    useFlashMessage(); // Automatically handles flash messages
    const isPageLoading = usePageLoading(); // Track page loading state

    const [stations, setStations] = useState<Station[]>(props.stations.data);
    const [links, setLinks] = useState<PaginationLink[]>(props.stations.links);

    const [search, setSearch] = useState('');
    const [debouncedSearch, setDebouncedSearch] = useState('');
    const [siteFilter, setSiteFilter] = useState('all');
    const [campaignFilter, setCampaignFilter] = useState('all');



    // Bulk transfer states
    const [bulkMode, setBulkMode] = useState(false);
    const [selectedStations, setSelectedStations] = useState<Set<number>>(new Set());

    useEffect(() => {
        const timer = setTimeout(() => setDebouncedSearch(search), 500);
        return () => clearTimeout(timer);
    }, [search]);

    const fetchStations = useCallback((pageUrl?: string) => {
        // If pageUrl is provided (from pagination), use it directly
        // as it already contains the query string from backend
        if (pageUrl) {
            router.get(pageUrl, {}, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['stations'],
            });
            return;
        }

        // For filter changes, build params manually
        const params: Record<string, string> = {};
        if (debouncedSearch) params.search = debouncedSearch;
        if (siteFilter !== 'all') params.site = siteFilter;
        if (campaignFilter !== 'all') params.campaign = campaignFilter;

        router.get('/pc-transfers', params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['stations'],
        });
    }, [debouncedSearch, siteFilter, campaignFilter]);

    useEffect(() => {
        setStations(props.stations.data);
        setLinks(props.stations.links);
    }, [props.stations.data, props.stations.links]);

    useEffect(() => {
        fetchStations();
    }, [fetchStations]);

    function handleRemovePC(station: Station) {
        if (!station.pc_spec_id) {
            toast.error('No PC assigned to this station');
            return;
        }

        if (!confirm(`Remove PC from ${station.station_number}?`)) return;

        router.delete('/pc-transfers/remove', {
            data: { station_id: station.id },
            preserveScroll: true,
            onSuccess: () => {
                toast.success('PC removed successfully');
                fetchStations(window.location.href);
            },
            onError: () => toast.error('Removal failed'),
        });
    }

    // Bulk transfer functions
    function toggleBulkMode() {
        setBulkMode(!bulkMode);
        setSelectedStations(new Set());
    }

    function toggleStationSelection(stationId: number) {
        const newSelection = new Set(selectedStations);
        if (newSelection.has(stationId)) {
            newSelection.delete(stationId);
        } else {
            newSelection.add(stationId);
        }
        setSelectedStations(newSelection);
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                {/* Loading overlay for page transitions */}
                <LoadingOverlay isLoading={isPageLoading} />

                {/* Page header with actions */}
                <PageHeader
                    title="PC Transfer Management"
                    description="Transfer PCs between stations and manage configurations"
                >
                    <div className="flex items-center gap-2">
                        {bulkMode && selectedStations.size > 0 && (
                            <Badge variant="secondary" className="px-3 py-1">
                                {selectedStations.size} selected
                            </Badge>
                        )}
                        <Button
                            variant={bulkMode ? 'default' : 'outline'}
                            onClick={toggleBulkMode}
                        >
                            <CheckSquare size={16} className="mr-2" />
                            {bulkMode ? 'Cancel Bulk Mode' : 'Bulk Transfer'}
                        </Button>
                        {bulkMode && selectedStations.size > 0 && (
                            <Button onClick={() => {
                                const stationIds = Array.from(selectedStations).join(',');
                                router.visit(`/pc-transfers/transfer?stations=${stationIds}`);
                            }}>
                                <List size={16} className="mr-2" />
                                Configure Transfers
                            </Button>
                        )}
                        <Button
                            variant="outline"
                            onClick={() => router.visit('/pc-transfers/history')}
                        >
                            <History size={16} className="mr-2" />
                            View History
                        </Button>
                    </div>
                </PageHeader>

                {/* Filters */}
                <Card>
                    <CardHeader>
                        <CardTitle>Filters</CardTitle>
                        <CardDescription>Search and filter stations</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div>
                                <Label>Search</Label>
                                <div className="relative">
                                    <Search className="absolute left-2.5 top-3 h-4 w-4 text-muted-foreground" />
                                    <Input
                                        type="search"
                                        placeholder="Station, site, campaign..."
                                        className="pl-8"
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
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
                                        {props.filters.sites.map((site) => (
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
                                        {props.filters.campaigns.map((campaign) => (
                                            <SelectItem key={campaign.id} value={String(campaign.id)}>
                                                {campaign.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="flex items-end">
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        setSearch('');
                                        setSiteFilter('all');
                                        setCampaignFilter('all');
                                    }}
                                    className="w-full"
                                >
                                    Clear Filters
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Stations Table */}
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
                        <div className="overflow-x-auto">
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
                                    {stations.map((station) => (
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
                                                    />
                                                </TableCell>
                                            )}
                                            <TableCell className="font-medium">
                                                {station.station_number}
                                            </TableCell>
                                            <TableCell>{station.site}</TableCell>
                                            <TableCell>{station.campaign}</TableCell>
                                            <TableCell>
                                                <Badge variant={station.status === 'active' ? 'default' : 'secondary'}>
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
                                                        <Link href={transferPage(station.id).url}>
                                                            <Button
                                                                size="sm"
                                                            >
                                                                <ArrowRight size={14} className="mr-1" />
                                                                {station.pc_spec_id ? 'Transfer PC' : 'Assign PC'}
                                                            </Button>
                                                        </Link>
                                                        {station.pc_spec_id && (
                                                            <Button
                                                                size="sm"
                                                                variant="destructive"
                                                                onClick={() => handleRemovePC(station)}
                                                            >
                                                                <Trash2 size={14} />
                                                            </Button>
                                                        )}
                                                    </div>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}

                                    {stations.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={7} className="text-center py-8 text-gray-500">
                                                No stations found
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>

                {/* Pagination */}
                <div className="flex justify-center mt-4">
                    {links && links.length > 0 && (
                        <PaginationNav
                            links={links}
                            onPageChange={(page) => {
                                const params: Record<string, string> = { page: String(page) };
                                if (debouncedSearch) params.search = debouncedSearch;
                                if (siteFilter !== 'all') params.site = siteFilter;
                                if (campaignFilter !== 'all') params.campaign = campaignFilter;

                                router.get('/pc-transfers', params, {
                                    preserveState: true,
                                    preserveScroll: true,
                                    replace: true,
                                    only: ['stations'],
                                });
                            }}
                        />
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
