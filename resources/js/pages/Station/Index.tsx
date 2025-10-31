import React, { useEffect, useState } from "react";
import { router, usePage, Head } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription
} from "@/components/ui/dialog";
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from "@/components/ui/select";
import { Checkbox } from "@/components/ui/checkbox";
import { Badge } from "@/components/ui/badge";
import AppLayout from "@/layouts/app-layout";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Label } from "@/components/ui/label";
import PaginationNav, { PaginationLink } from "@/components/pagination-nav";
import { toast } from "sonner";
import { Eye, AlertTriangle, Plus, CheckSquare } from "lucide-react";
import { transferPage } from '@/routes/pc-transfers';
import { index as stationsIndex } from "@/routes/stations";

// New reusable components and hooks
import { usePageMeta, useFlashMessage, usePageLoading } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { DeleteConfirmDialog } from "@/components/DeleteConfirmDialog";
import { LoadingOverlay } from "@/components/LoadingOverlay";

interface Station {
    id: number;
    site: string;
    station_number: string;
    campaign: string;
    status: string;
    monitor_type: string;
    pc_spec: string;
    pc_spec_details?: {
        id: number;
        pc_number?: string | null;
        model: string;
        processor: string;
        ram: string;
        ram_gb: number;
        ram_capacities: string;
        ram_ddr: string;
        disk: string;
        disk_gb: number;
        disk_capacities: string;
        disk_type: string;
        issue?: string | null;
    };
    monitors?: Array<{
        id: number;
        brand: string;
        model: string;
        screen_size: number;
        resolution: string;
        panel_type: string;
        quantity: number;
    }>;
}
interface Flash { message?: string; type?: string; }
interface Meta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}
interface StationsPayload {
    data: Station[];
    links: PaginationLink[];
    meta: Meta;
}

interface Site { id: number; name: string; }
interface Campaign { id: number; name: string; }
interface Filters {
    sites: Site[];
    campaigns: Campaign[];
    statuses: string[];
}

export default function StationIndex() {
    // QR Code ZIP state
    // Persist selectedStationIds in localStorage
    const LOCAL_STORAGE_KEY = 'station_selected_ids';
    const LOCAL_STORAGE_TIMESTAMP_KEY = 'station_selected_ids_timestamp';
    const EXPIRY_TIME_MS = 15 * 60 * 1000; // 15 minutes

    const [selectedStationIds, setSelectedStationIds] = useState<number[]>(() => {
        try {
            const stored = localStorage.getItem(LOCAL_STORAGE_KEY);
            const timestamp = localStorage.getItem(LOCAL_STORAGE_TIMESTAMP_KEY);

            if (stored && timestamp) {
                const age = Date.now() - parseInt(timestamp, 10);
                if (age < EXPIRY_TIME_MS) {
                    return JSON.parse(stored);
                } else {
                    // Clear expired data
                    localStorage.removeItem(LOCAL_STORAGE_KEY);
                    localStorage.removeItem(LOCAL_STORAGE_TIMESTAMP_KEY);
                }
            }
            return [];
        } catch {
            // Ignore localStorage errors
            return [];
        }
    });
    const [bulkProgress, setBulkProgress] = useState<{ running: boolean; percent: number; status: string; downloadUrl?: string; jobId?: string }>({ running: false, percent: 0, status: '' });
    const [selectedZipProgress, setSelectedZipProgress] = useState<{ running: boolean; percent: number; status: string; downloadUrl?: string; jobId?: string }>({ running: false, percent: 0, status: '' });
    const bulkIntervalRef = React.useRef<NodeJS.Timeout | null>(null);
    const selectedZipIntervalRef = React.useRef<NodeJS.Timeout | null>(null);

    // Selection logic
    // Save selectedStationIds to localStorage on change
    useEffect(() => {
        try {
            if (selectedStationIds.length > 0) {
                localStorage.setItem(LOCAL_STORAGE_KEY, JSON.stringify(selectedStationIds));
                localStorage.setItem(LOCAL_STORAGE_TIMESTAMP_KEY, Date.now().toString());
            } else {
                localStorage.removeItem(LOCAL_STORAGE_KEY);
                localStorage.removeItem(LOCAL_STORAGE_TIMESTAMP_KEY);
            }
        } catch {
            // Ignore localStorage errors
        }
    }, [selectedStationIds]);

    // Auto-clear selection after 15 minutes of inactivity
    useEffect(() => {
        const expiryTime = 15 * 60 * 1000; // 15 minutes
        const clearTimer = setTimeout(() => {
            if (selectedStationIds.length > 0) {
                setSelectedStationIds([]);
                toast.info('QR code selection cleared after 15 minutes of inactivity');
            }
        }, expiryTime);

        return () => clearTimeout(clearTimer);
    }, [selectedStationIds]);

    const handleSelectAllStations = (checked: boolean) => {
        setSelectedStationIds(checked ? stations.data.map(s => s.id) : []);
    };
    const handleSelectStation = (id: number, checked: boolean) => {
        setSelectedStationIds(prev => checked ? [...prev, id] : prev.filter(sid => sid !== id));
    };

    // Bulk QR ZIP
    const handleBulkDownloadAllQRCodes = () => {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (!csrfToken) return toast.error('CSRF token not found');
        toast.info('Preparing bulk QR code ZIP...');
        fetch('/stations/qrcode/bulk-all', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ format: 'png', size: 256, metadata: 0 }),
        })
            .then(res => res.json())
            .then(data => {
                if (data.jobId) setBulkProgress({ running: true, percent: 0, status: 'Starting...', jobId: data.jobId });
                else toast.error('Failed to start bulk download');
            });
    };

    useEffect(() => {
        if (bulkProgress.running && bulkProgress.jobId) {
            if (bulkIntervalRef.current) {
                clearInterval(bulkIntervalRef.current);
                bulkIntervalRef.current = null;
            }

            // Poll immediately first, then every 500ms
            const pollProgress = () => {
                fetch(`/stations/qrcode/bulk-progress/${bulkProgress.jobId}`)
                    .then(res => {
                        if (!res.ok) throw new Error('Failed to fetch progress');
                        return res.json();
                    })
                    .then(data => {
                        setBulkProgress(prev => ({
                            ...prev,
                            percent: data.percent || 0,
                            status: data.status || 'Processing...',
                            downloadUrl: data.downloadUrl,
                            running: !data.finished,
                        }));
                        if (data.finished) {
                            if (bulkIntervalRef.current) {
                                clearInterval(bulkIntervalRef.current);
                                bulkIntervalRef.current = null;
                            }
                            if (data.downloadUrl) {
                                window.location.href = data.downloadUrl;
                                toast.success('Download started');
                            }
                        }
                    })
                    .catch(err => {
                        console.error('Progress fetch error:', err);
                        toast.error('Failed to fetch progress');
                    });
            };

            // Poll immediately
            pollProgress();

            // Then poll every 500ms for faster updates
            bulkIntervalRef.current = setInterval(pollProgress, 500);
        }
        return () => {
            if (bulkIntervalRef.current) {
                clearInterval(bulkIntervalRef.current);
                bulkIntervalRef.current = null;
            }
        };
    }, [bulkProgress.running, bulkProgress.jobId]);

    // Selected QR ZIP
    const handleDownloadSelectedQRCodes = () => {
        if (selectedStationIds.length === 0) return toast.error('No stations selected');
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (!csrfToken) return toast.error('CSRF token not found');
        toast.info('Preparing selected QR code ZIP...');
        fetch('/stations/qrcode/zip-selected', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ station_ids: selectedStationIds, format: 'png', size: 256, metadata: 0 }),
        })
            .then(res => res.json())
            .then(data => {
                if (data.jobId) setSelectedZipProgress({ running: true, percent: 0, status: 'Starting...', jobId: data.jobId });
                else toast.error('Failed to start selected ZIP download');
            });
    };

    useEffect(() => {
        if (selectedZipProgress.running && selectedZipProgress.jobId) {
            if (selectedZipIntervalRef.current) {
                clearInterval(selectedZipIntervalRef.current);
                selectedZipIntervalRef.current = null;
            }

            // Poll immediately first, then every 500ms
            const pollProgress = () => {
                fetch(`/stations/qrcode/selected-progress/${selectedZipProgress.jobId}`)
                    .then(res => {
                        if (!res.ok) throw new Error('Failed to fetch progress');
                        return res.json();
                    })
                    .then(data => {
                        setSelectedZipProgress(prev => ({
                            ...prev,
                            percent: data.percent || 0,
                            status: data.status || 'Processing...',
                            downloadUrl: data.downloadUrl,
                            running: !data.finished,
                        }));
                        if (data.finished) {
                            if (selectedZipIntervalRef.current) {
                                clearInterval(selectedZipIntervalRef.current);
                                selectedZipIntervalRef.current = null;
                            }
                            if (data.downloadUrl) {
                                window.location.href = data.downloadUrl;
                                toast.success('Download started');
                            }
                        }
                    })
                    .catch(err => {
                        console.error('Progress fetch error:', err);
                        toast.error('Failed to fetch progress');
                    });
            };

            // Poll immediately
            pollProgress();

            // Then poll every 500ms for faster updates
            selectedZipIntervalRef.current = setInterval(pollProgress, 500);
        }
        return () => {
            if (selectedZipIntervalRef.current) {
                clearInterval(selectedZipIntervalRef.current);
                selectedZipIntervalRef.current = null;
            }
        };
    }, [selectedZipProgress.running, selectedZipProgress.jobId]);

    const { stations, filters } = usePage<{ stations: StationsPayload; flash?: Flash; filters: Filters }>().props;

    // Use new hooks for cleaner code
    const { title, breadcrumbs } = usePageMeta({
        title: "Stations",
        breadcrumbs: [{ title: "Stations", href: stationsIndex().url }]
    });

    useFlashMessage(); // Automatically handles flash messages
    const isPageLoading = usePageLoading(); // Track page loading state

    // Initialize filters from URL params
    const urlParams = new URLSearchParams(window.location.search);
    const [loading, setLoading] = useState(false);
    const [search, setSearch] = useState(urlParams.get('search') || "");
    const [debouncedSearch, setDebouncedSearch] = useState(urlParams.get('search') || "");
    const [siteFilter, setSiteFilter] = useState(urlParams.get('site') || "all");
    const [campaignFilter, setCampaignFilter] = useState(urlParams.get('campaign') || "all");
    const [statusFilter, setStatusFilter] = useState(urlParams.get('status') || "all");
    const [pcSpecDialogOpen, setPcSpecDialogOpen] = useState(false);
    const [selectedPcSpec, setSelectedPcSpec] = useState<Station['pc_spec_details'] | null>(null);
    const [monitorDialogOpen, setMonitorDialogOpen] = useState(false);
    const [selectedMonitors, setSelectedMonitors] = useState<Station['monitors']>([]);
    const [issueDialogOpen, setIssueDialogOpen] = useState(false);
    const [issueText, setIssueText] = useState("");
    const [selectedEmptyStations, setSelectedEmptyStations] = useState<number[]>([]);

    useEffect(() => {
        const timer = setTimeout(() => setDebouncedSearch(search), 500);
        return () => clearTimeout(timer);
    }, [search]);

    // Track if this is the initial mount
    const isInitialMount = React.useRef(true);

    useEffect(() => {
        // Skip on initial mount (data already loaded by Inertia)
        if (isInitialMount.current) {
            isInitialMount.current = false;
            return;
        }

        const params: Record<string, string | number> = {};
        if (debouncedSearch) params.search = debouncedSearch;
        if (siteFilter && siteFilter !== "all") params.site = siteFilter;
        if (campaignFilter && campaignFilter !== "all") params.campaign = campaignFilter;
        if (statusFilter && statusFilter !== "all") params.status = statusFilter;

        // When filters change, reset to page 1 (don't preserve page from URL)
        setLoading(true);
        router.get("/stations", params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => setLoading(false),
        });
    }, [debouncedSearch, siteFilter, campaignFilter, statusFilter]);

    const handleDelete = (stationId: number) => {
        setLoading(true);
        router.delete(`/stations/${stationId}`, {
            preserveScroll: true,
            onFinish: () => setLoading(false),
            onSuccess: () => toast.success("Station deleted successfully"),
            onError: () => toast.error("Failed to delete station"),
        });
    };

    const handleOpenIssueDialog = (pcSpecDetails: Station['pc_spec_details']) => {
        if (!pcSpecDetails) return;
        setSelectedPcSpec(pcSpecDetails);
        setIssueText(pcSpecDetails.issue || '');
        setIssueDialogOpen(true);
    };

    const handleSaveIssue = () => {
        if (!selectedPcSpec) return;

        router.patch(`/pcspecs/${selectedPcSpec.id}/issue`, {
            issue: issueText || null,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Issue updated successfully');
                setIssueDialogOpen(false);
            },
            onError: () => {
                toast.error('Failed to update issue');
            },
        });
    };

    const toggleStationSelection = (stationId: number, hasPC: boolean) => {
        if (hasPC) return; // Can't select stations that already have PCs

        setSelectedEmptyStations(prev =>
            prev.includes(stationId)
                ? prev.filter(id => id !== stationId)
                : [...prev, stationId]
        );
    };

    const handleBulkAssign = () => {
        if (selectedEmptyStations.length === 0) {
            toast.error('Please select at least one empty station');
            return;
        }

        // Navigate to transfer page with multiple stations
        const stationIds = selectedEmptyStations.join(',');
        router.visit(`/pc-transfers/transfer?stations=${stationIds}`);
    };

    const clearSelection = () => {
        setSelectedEmptyStations([]);
    };

    const emptyStationsCount = stations.data.filter(s => !s.pc_spec_details).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                {/* Loading overlay for page transitions */}
                <LoadingOverlay isLoading={isPageLoading} />

                {/* Page header */}
                <PageHeader
                    title="Station Management"
                    description="Manage workstation assignments and configurations"
                />

                {/* Filters */}
                <div className="flex flex-col gap-3">
                    {/* Search Input - full width on mobile */}
                    <div className="w-full">
                        <Input
                            type="search"
                            placeholder="Search site, station #, campaign..."
                            value={search}
                            onChange={e => setSearch(e.target.value)}
                            className="w-full"
                        />
                    </div>

                    {/* Select Filters - stacked on mobile, row on desktop */}
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                        <Select value={siteFilter} onValueChange={setSiteFilter}>
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Filter by Site" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Sites</SelectItem>
                                {filters.sites.map((site) => (
                                    <SelectItem key={site.id} value={String(site.id)}>{site.name}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select value={campaignFilter} onValueChange={setCampaignFilter}>
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Filter by Campaign" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Campaigns</SelectItem>
                                {filters.campaigns.map((campaign) => (
                                    <SelectItem key={campaign.id} value={String(campaign.id)}>{campaign.name}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select value={statusFilter} onValueChange={setStatusFilter}>
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Filter by Status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Statuses</SelectItem>
                                {filters.statuses.map((status) => (
                                    <SelectItem key={status} value={status}>{status}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        {(siteFilter !== "all" || campaignFilter !== "all" || statusFilter !== "all" || search) && (
                            <Button
                                variant="outline"
                                onClick={() => {
                                    setSearch("");
                                    setSiteFilter("all");
                                    setCampaignFilter("all");
                                    setStatusFilter("all");
                                }}
                                className="w-full sm:w-auto"
                            >
                                Clear Filters
                            </Button>
                        )}
                    </div>

                    {/* Action Buttons - stacked on mobile, row on desktop */}
                    <div className="flex flex-col sm:flex-row sm:flex-wrap gap-3 sm:justify-between min-w-0">
                        {/* Bulk Selection Controls */}
                        {selectedEmptyStations.length > 0 && (
                            <div className="flex flex-col sm:flex-row sm:flex-wrap items-start sm:items-center gap-2 min-w-0">
                                <span className="text-sm font-medium">
                                    {selectedEmptyStations.length} empty station{selectedEmptyStations.length > 1 ? 's' : ''} selected
                                </span>
                                <div className="flex flex-col sm:flex-row flex-wrap gap-2 w-full sm:w-auto min-w-0">
                                    <Button
                                        onClick={handleBulkAssign}
                                        className="flex items-center gap-2 flex-1 sm:flex-initial min-w-0"
                                        size="sm"
                                    >
                                        <CheckSquare className="h-4 w-4" />
                                        Assign PCs to Selected
                                    </Button>
                                    <Button
                                        variant="outline"
                                        onClick={clearSelection}
                                        size="sm"
                                        className="flex-1 sm:flex-initial min-w-0"
                                    >
                                        Clear
                                    </Button>
                                </div>
                            </div>
                        )}

                        {/* QR Code ZIP Actions */}
                        {selectedStationIds.length > 0 && (
                            <div className="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between min-w-0">
                                <div className="flex flex-col gap-2 sm:flex-row sm:items-center min-w-0">
                                    <span className="font-medium text-blue-900 dark:text-blue-100">
                                        {selectedStationIds.length} Station{selectedStationIds.length !== 1 ? 's' : ''} selected
                                    </span>
                                    <Button variant="outline" size="sm" onClick={() => setSelectedStationIds([])} className="w-full sm:w-auto min-w-0">
                                        Clear Selection
                                    </Button>
                                </div>
                                <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:gap-2 min-w-0">
                                    <Button
                                        onClick={handleDownloadSelectedQRCodes}
                                        variant="outline"
                                        className="border-green-600 text-green-600 hover:bg-green-600 hover:text-white dark:hover:text-white w-full sm:w-auto min-w-0"
                                        disabled={selectedZipProgress.running}
                                    >
                                        Download Selected QR Codes as ZIP
                                    </Button>
                                    <Button
                                        onClick={handleBulkDownloadAllQRCodes}
                                        className="bg-blue-700 hover:bg-blue-800 text-white w-full sm:w-auto min-w-0"
                                        disabled={bulkProgress.running}
                                    >
                                        Download All QR Codes as ZIP
                                    </Button>
                                </div>
                            </div>
                        )}

                        {/* Regular Action Buttons */}
                        <div className="flex flex-col sm:flex-row gap-3 sm:ml-auto">
                            <Button onClick={() => router.get('/stations/create')} className="w-full sm:w-auto">
                                Add Station
                            </Button>
                            <Button onClick={() => router.get('/sites')} className="w-full sm:w-auto">
                                Site Management
                            </Button>
                            <Button onClick={() => router.get('/campaigns')} className="w-full sm:w-auto">
                                Campaign Management
                            </Button>
                        </div>
                    </div>
                </div>

                {/* Results Count with Empty Stations Info */}
                <div className="flex justify-between items-center text-sm">
                    <div className="text-muted-foreground">
                        Showing {stations.data.length} of {stations.meta.total} station{stations.meta.total !== 1 ? 's' : ''}
                        {(siteFilter !== "all" || campaignFilter !== "all" || statusFilter !== "all" || search) && ' (filtered)'}
                    </div>
                    {emptyStationsCount > 0 && (
                        <div className="text-orange-600 font-medium">
                            {emptyStationsCount} station{emptyStationsCount > 1 ? 's' : ''} without PC
                        </div>
                    )}
                </div>

                {/* Desktop Table View - hidden on mobile */}
                <div className="hidden md:block shadow rounded-md overflow-hidden">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-12">
                                        <input
                                            type="checkbox"
                                            checked={selectedStationIds.length === stations.data.length && stations.data.length > 0}
                                            onChange={e => handleSelectAllStations(e.target.checked)}
                                            className="cursor-pointer"
                                        />
                                    </TableHead>
                                    <TableHead className="hidden lg:table-cell">ID</TableHead>
                                    <TableHead>Site</TableHead>
                                    <TableHead>Station #</TableHead>
                                    <TableHead>Campaign</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="hidden xl:table-cell">Monitor Type</TableHead>
                                    <TableHead>Monitors</TableHead>
                                    <TableHead>PC Spec</TableHead>
                                    <TableHead className="hidden xl:table-cell">PC Issue</TableHead>
                                    <TableHead>Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {stations.data.map((station) => {
                                    const isSelected = selectedEmptyStations.includes(station.id);

                                    return (
                                        <TableRow
                                            key={station.id}
                                            className={isSelected ? 'bg-blue-50 dark:bg-blue-900/20' : ''}
                                        >
                                            <TableCell>
                                                <input
                                                    type="checkbox"
                                                    checked={selectedStationIds.includes(station.id)}
                                                    onChange={e => handleSelectStation(station.id, e.target.checked)}
                                                    className="cursor-pointer"
                                                />
                                            </TableCell>
                                            {/* Floating progress indicators for QR ZIP */}
                                            {bulkProgress.running && (
                                                <div className="fixed bottom-6 right-6 z-50 bg-white dark:bg-gray-900 border border-blue-400 shadow-lg rounded-lg px-6 py-4 flex flex-col gap-2 items-center">
                                                    <div className="font-semibold text-blue-700 dark:text-blue-200">
                                                        Bulk QR Code ZIP Progress
                                                    </div>
                                                    <div className="w-full bg-gray-200 rounded h-3 mb-2">
                                                        <div className="bg-blue-600 h-3 rounded" style={{ width: `${bulkProgress.percent}%` }} />
                                                    </div>
                                                    <div className="text-xs text-gray-700 dark:text-gray-300">
                                                        {bulkProgress.status} ({bulkProgress.percent}%)
                                                    </div>
                                                </div>
                                            )}
                                            {selectedZipProgress.running && (
                                                <div className="fixed bottom-24 right-6 z-50 bg-white dark:bg-gray-900 border border-green-400 shadow-lg rounded-lg px-6 py-4 flex flex-col gap-2 items-center">
                                                    <div className="font-semibold text-green-700 dark:text-green-200">
                                                        Selected QR Code ZIP Progress
                                                    </div>
                                                    <div className="w-full bg-gray-200 rounded h-3 mb-2">
                                                        <div className="bg-green-600 h-3 rounded" style={{ width: `${selectedZipProgress.percent}%` }} />
                                                    </div>
                                                    <div className="text-xs text-gray-700 dark:text-gray-300">
                                                        {selectedZipProgress.status} ({selectedZipProgress.percent}%)
                                                    </div>
                                                </div>
                                            )}
                                            <TableCell className="hidden lg:table-cell">{station.id}</TableCell>
                                            <TableCell>{station.site}</TableCell>
                                            <TableCell>{station.station_number}</TableCell>
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
                                            <TableCell className="hidden xl:table-cell">
                                                <span className={station.monitor_type === 'dual' ? 'text-blue-600 font-medium' : ''}>
                                                    {station.monitor_type === 'dual' ? 'Dual' : 'Single'}
                                                </span>
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    {station.monitors && station.monitors.length > 0 ? (
                                                        <>
                                                            <div className="text-sm">
                                                                {station.monitors.map((monitor, idx) => (
                                                                    <div key={monitor.id} className="flex items-center gap-1">
                                                                        <span>{monitor.brand} {monitor.model}</span>
                                                                        {monitor.quantity > 1 && (
                                                                            <span className="text-xs text-blue-600 font-medium">×{monitor.quantity}</span>
                                                                        )}
                                                                        {idx < station.monitors!.length - 1 && <span className="text-gray-400">,</span>}
                                                                    </div>
                                                                ))}
                                                            </div>
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => {
                                                                    setSelectedMonitors(station.monitors || []);
                                                                    setMonitorDialogOpen(true);
                                                                }}
                                                                className="h-7 w-7 p-0"
                                                                title="View Monitor Details"
                                                            >
                                                                <Eye className="h-4 w-4" />
                                                            </Button>
                                                        </>
                                                    ) : (
                                                        <span className="text-xs text-gray-400">No monitors</span>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    {station.pc_spec_details ? (
                                                        <>
                                                            <div>
                                                                <span>{station.pc_spec}</span>
                                                                {station.pc_spec_details.pc_number && (
                                                                    <div className="text-xs text-blue-600 mt-0.5">
                                                                        PC: {station.pc_spec_details.pc_number}
                                                                    </div>
                                                                )}
                                                            </div>
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => {
                                                                    setSelectedPcSpec(station.pc_spec_details || null);
                                                                    setPcSpecDialogOpen(true);
                                                                }}
                                                                className="h-7 w-7 p-0"
                                                                title="View PC Spec Details"
                                                            >
                                                                <Eye className="h-4 w-4" />
                                                            </Button>
                                                        </>
                                                    ) : (
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() => router.visit(transferPage(station.id).url)}
                                                            className="gap-2"
                                                            title="Assign PC to this station"
                                                        >
                                                            <Plus className="h-4 w-4" />
                                                            Assign PC
                                                        </Button>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell className="hidden xl:table-cell">
                                                {station.pc_spec_details ? (
                                                    <div className="flex items-center gap-2">
                                                        {station.pc_spec_details.issue ? (
                                                            <>
                                                                <AlertTriangle className="h-4 w-4 text-red-600 flex-shrink-0" />
                                                                <span className="text-xs text-red-600 font-medium truncate max-w-[150px]" title={station.pc_spec_details.issue}>
                                                                    {station.pc_spec_details.issue}
                                                                </span>
                                                            </>
                                                        ) : (
                                                            <span className="text-xs text-gray-400">No issue</span>
                                                        )}
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() => handleOpenIssueDialog(station.pc_spec_details)}
                                                            className="h-7 px-2 text-xs"
                                                        >
                                                            {station.pc_spec_details.issue ? 'Edit' : 'Add'}
                                                        </Button>
                                                    </div>
                                                ) : (
                                                    <span className="text-xs text-gray-400">—</span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    <Button variant="outline" size="sm" onClick={() => router.get(`/stations/${station.id}/edit`)} disabled={loading}>
                                                        Edit
                                                    </Button>

                                                    {/* Reusable delete confirmation dialog */}
                                                    <DeleteConfirmDialog
                                                        onConfirm={() => handleDelete(station.id)}
                                                        title="Delete Station"
                                                        description={`Are you sure you want to delete station "${station.station_number}"?`}
                                                        disabled={loading}
                                                    />
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                                {stations.data.length === 0 && !loading && (
                                    <TableRow>
                                        <TableCell colSpan={11} className="py-8 text-center text-gray-500">
                                            No stations found
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </div>

                {/* Mobile Card View - visible only on mobile */}
                <div className="md:hidden space-y-4">
                    {stations.data.map((station) => {
                        const hasPC = !!station.pc_spec_details;
                        const isSelected = selectedEmptyStations.includes(station.id);

                        return (
                            <div
                                key={station.id}
                                className={`bg-card border rounded-lg p-4 shadow-sm space-y-3 ${isSelected ? 'bg-blue-50 dark:bg-blue-900/20 border-blue-500' : ''}`}
                            >
                                {/* Header with Checkbox, Station Number and Status */}
                                <div className="flex justify-between items-start">
                                    <div className="flex items-start gap-3">
                                        {!hasPC && (
                                            <Checkbox
                                                checked={isSelected}
                                                onCheckedChange={() => toggleStationSelection(station.id, hasPC)}
                                                aria-label={`Select station ${station.station_number}`}
                                                className="mt-1"
                                            />
                                        )}
                                        <div>
                                            <div className="text-xs text-muted-foreground">Station</div>
                                            <div className="font-semibold text-lg">{station.station_number}</div>
                                        </div>
                                    </div>
                                    <div className="text-right">
                                        <div className="text-xs text-muted-foreground">Status</div>
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
                                </div>

                                {/* Station Details */}
                                <div className="space-y-2 text-sm">
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Site:</span>
                                        <span className="font-medium">{station.site}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Campaign:</span>
                                        <span className="font-medium">{station.campaign}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Monitor Type:</span>
                                        <span className={station.monitor_type === 'dual' ? 'text-blue-600 font-medium' : 'font-medium'}>
                                            {station.monitor_type === 'dual' ? 'Dual' : 'Single'}
                                        </span>
                                    </div>
                                    <div className="flex justify-between items-center">
                                        <span className="text-muted-foreground">Monitors:</span>
                                        <div className="flex items-center gap-2">
                                            {station.monitors && station.monitors.length > 0 ? (
                                                <>
                                                    <div className="text-right text-sm">
                                                        {station.monitors.map((monitor) => (
                                                            <div key={monitor.id}>
                                                                {monitor.brand} {monitor.model}
                                                                {monitor.quantity > 1 && (
                                                                    <span className="text-xs text-blue-600 ml-1">×{monitor.quantity}</span>
                                                                )}
                                                            </div>
                                                        ))}
                                                    </div>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => {
                                                            setSelectedMonitors(station.monitors || []);
                                                            setMonitorDialogOpen(true);
                                                        }}
                                                        className="h-7 w-7 p-0"
                                                        title="View Monitor Details"
                                                    >
                                                        <Eye className="h-4 w-4" />
                                                    </Button>
                                                </>
                                            ) : (
                                                <span className="text-xs text-gray-400">No monitors</span>
                                            )}
                                        </div>
                                    </div>
                                    <div className="flex justify-between items-center">
                                        <span className="text-muted-foreground">PC Spec:</span>
                                        <div className="flex items-center gap-2">
                                            {station.pc_spec_details ? (
                                                <>
                                                    <div className="text-right">
                                                        <span className="font-medium">{station.pc_spec}</span>
                                                        {station.pc_spec_details.pc_number && (
                                                            <div className="text-xs text-blue-600">
                                                                PC: {station.pc_spec_details.pc_number}
                                                            </div>
                                                        )}
                                                    </div>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => {
                                                            setSelectedPcSpec(station.pc_spec_details || null);
                                                            setPcSpecDialogOpen(true);
                                                        }}
                                                        className="h-7 w-7 p-0"
                                                        title="View PC Spec Details"
                                                    >
                                                        <Eye className="h-4 w-4" />
                                                    </Button>
                                                </>
                                            ) : (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() => router.visit(transferPage(station.id).url)}
                                                    className="gap-2"
                                                    title="Assign PC to this station"
                                                >
                                                    <Plus className="h-4 w-4" />
                                                    Assign PC
                                                </Button>
                                            )}
                                        </div>
                                    </div>

                                    {/* PC Issue Section */}
                                    {station.pc_spec_details && (
                                        <div className="pt-2 border-t">
                                            <div className="flex justify-between items-start gap-2">
                                                <span className="text-muted-foreground">PC Issue:</span>
                                                <div className="flex items-center gap-2 flex-1 justify-end">
                                                    {station.pc_spec_details.issue ? (
                                                        <>
                                                            <AlertTriangle className="h-4 w-4 text-red-600 flex-shrink-0" />
                                                            <span className="text-xs text-red-600 font-medium truncate max-w-[120px]" title={station.pc_spec_details.issue}>
                                                                {station.pc_spec_details.issue}
                                                            </span>
                                                        </>
                                                    ) : (
                                                        <span className="text-xs text-gray-400">No issue</span>
                                                    )}
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => handleOpenIssueDialog(station.pc_spec_details)}
                                                        className="h-7 px-2 text-xs"
                                                    >
                                                        {station.pc_spec_details.issue ? 'Edit' : 'Add'}
                                                    </Button>
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </div>

                                {/* Action Buttons */}
                                <div className="flex gap-2 pt-2 border-t">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => router.get(`/stations/${station.id}/edit`)}
                                        disabled={loading}
                                        className="flex-1"
                                    >
                                        Edit
                                    </Button>

                                    {/* Reusable delete confirmation dialog */}
                                    <div className="flex-1">
                                        <DeleteConfirmDialog
                                            onConfirm={() => handleDelete(station.id)}
                                            title="Delete Station"
                                            description={`Are you sure you want to delete station "${station.station_number}"?`}
                                            disabled={loading}
                                        />
                                    </div>
                                </div>
                            </div>
                        );
                    })}
                    {stations.data.length === 0 && !loading && (
                        <div className="py-12 text-center text-gray-500 border rounded-lg bg-card">
                            No stations found
                        </div>
                    )}
                </div>
                <div className="flex justify-center mt-4">
                    {stations.links && stations.links.length > 0 && (
                        <PaginationNav links={stations.links} only={['stations']} />
                    )}
                </div>

                {/* PC Spec Details Dialog */}
                <Dialog open={pcSpecDialogOpen} onOpenChange={setPcSpecDialogOpen}>
                    <DialogContent className="max-w-[90vw] sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>PC Specification Details</DialogTitle>
                            <DialogDescription>
                                {selectedPcSpec ? (
                                    <div className="space-y-4 text-left mt-4">
                                        <div className="space-y-3">
                                            {selectedPcSpec.pc_number && (
                                                <div>
                                                    <div className="font-semibold text-foreground mb-1">PC Number:</div>
                                                    <div className="text-blue-600 font-medium pl-2 break-words">{selectedPcSpec.pc_number}</div>
                                                </div>
                                            )}

                                            <div>
                                                <div className="font-semibold text-foreground mb-1">Model:</div>
                                                <div className="text-foreground pl-2 break-words">{selectedPcSpec.model}</div>
                                            </div>

                                            <div>
                                                <div className="font-semibold text-foreground mb-1">Processor:</div>
                                                <div className="text-foreground pl-2 break-words">{selectedPcSpec.processor}</div>
                                            </div>

                                            <div>
                                                <div className="font-semibold text-foreground mb-1">RAM ({selectedPcSpec.ram_ddr}):</div>
                                                <div className="text-foreground pl-2 break-words">
                                                    {selectedPcSpec.ram} ({selectedPcSpec.ram_capacities})
                                                </div>
                                            </div>

                                            <div>
                                                <div className="font-semibold text-foreground mb-1">Disk ({selectedPcSpec.disk_type}):</div>
                                                <div className="text-foreground pl-2 break-words">
                                                    {selectedPcSpec.disk} ({selectedPcSpec.disk_capacities})
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                ) : (
                                    <p className="text-muted-foreground">No PC spec details available.</p>
                                )}
                            </DialogDescription>
                        </DialogHeader>
                    </DialogContent>
                </Dialog>

                {/* Monitor Details Dialog */}
                <Dialog open={monitorDialogOpen} onOpenChange={setMonitorDialogOpen}>
                    <DialogContent className="max-w-[90vw] sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>Monitor Details</DialogTitle>
                            <DialogDescription>
                                {selectedMonitors && selectedMonitors.length > 0 ? (
                                    <div className="space-y-3 text-left mt-4">
                                        {selectedMonitors.map((monitor, idx) => (
                                            <div key={monitor.id} className="border-b pb-3 last:border-b-0 last:pb-0">
                                                <div className="flex items-center justify-between mb-2">
                                                    <h3 className="font-semibold text-foreground text-base">
                                                        Monitor {idx + 1}
                                                        {monitor.quantity > 1 && (
                                                            <span className="ml-2 text-sm text-blue-600 font-medium">
                                                                (Qty: {monitor.quantity})
                                                            </span>
                                                        )}
                                                    </h3>
                                                </div>
                                                <div className="flex flex-wrap gap-x-6 gap-y-2 text-sm">
                                                    <div className="flex gap-1 items-center">
                                                        <span className="font-semibold text-foreground">Brand:</span>
                                                        <span className="text-foreground">{monitor.brand}</span>
                                                    </div>
                                                    <div className="flex gap-1 items-center">
                                                        <span className="font-semibold text-foreground">Model:</span>
                                                        <span className="text-foreground">{monitor.model}</span>
                                                    </div>
                                                    <div className="flex gap-1 items-center">
                                                        <span className="font-semibold text-foreground">Size:</span>
                                                        <span className="text-foreground">{monitor.screen_size}"</span>
                                                    </div>
                                                    <div className="flex gap-1 items-center">
                                                        <span className="font-semibold text-foreground">Res:</span>
                                                        <span className="text-foreground">{monitor.resolution}</span>
                                                    </div>
                                                    <div className="flex gap-1 items-center">
                                                        <span className="font-semibold text-foreground">Panel:</span>
                                                        <span className="text-foreground">{monitor.panel_type}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-muted-foreground">No monitor details available.</p>
                                )}
                            </DialogDescription>
                        </DialogHeader>
                    </DialogContent>
                </Dialog>

                {/* Issue Management Dialog */}
                <Dialog open={issueDialogOpen} onOpenChange={setIssueDialogOpen}>
                    <DialogContent className="max-w-[90vw] sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>Manage PC Spec Issue</DialogTitle>
                            <DialogDescription>
                                {selectedPcSpec && (
                                    <span className="text-sm break-words">
                                        {selectedPcSpec.model}
                                    </span>
                                )}
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-4 py-4">
                            <div className="space-y-2">
                                <Label htmlFor="issue">Issue Details</Label>
                                <Textarea
                                    id="issue"
                                    placeholder="Describe any issues with this PC spec..."
                                    value={issueText}
                                    onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => setIssueText(e.target.value)}
                                    rows={5}
                                    className="resize-none"
                                />
                                <p className="text-xs text-gray-500">
                                    Leave empty to remove the issue note.
                                </p>
                            </div>
                        </div>
                        <div className="flex flex-col sm:flex-row justify-end gap-2">
                            <Button variant="outline" onClick={() => setIssueDialogOpen(false)} className="w-full sm:w-auto">
                                Cancel
                            </Button>
                            <Button onClick={handleSaveIssue} className="w-full sm:w-auto">
                                Save Issue
                            </Button>
                        </div>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
