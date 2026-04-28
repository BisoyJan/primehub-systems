import React, { useEffect, useMemo, useState } from "react";
import { router, usePage, Head } from "@inertiajs/react";
import type { RequestPayload } from "@inertiajs/core";
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
import { Textarea } from "@/components/ui/textarea";
import { Label } from "@/components/ui/label";
import PaginationNav, { PaginationLink } from "@/components/pagination-nav";
import { toast } from "sonner";
import { Eye, AlertTriangle, Plus, CheckSquare, RefreshCw, Download, Play, Pause, ChevronsUpDown, Check, X } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from "@/components/ui/popover";
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from "@/components/ui/command";
import { transferPage } from '@/routes/pc-transfers';
import {
    index as stationsIndexRoute,
    create as stationsCreateRoute,
    edit as stationsEditRoute,
    destroy as stationsDestroyRoute,
} from "@/routes/stations";
import { index as sitesIndexRoute } from "@/routes/sites";
import { index as campaignsIndexRoute } from "@/routes/campaigns";

// New reusable components and hooks
import { usePageMeta, useFlashMessage, usePageLoading } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { DeleteConfirmDialog } from "@/components/DeleteConfirmDialog";
import { LoadingOverlay } from "@/components/LoadingOverlay";
import { TableSkeleton } from '@/components/TableSkeleton';
import { Can } from "@/components/authorization";

interface ProcessorSpec {
    id: number;
    manufacturer: string;
    model: string;
    core_count: number;
    thread_count: number;
    base_clock_ghz: number;
    boost_clock_ghz: number;
}

interface Station {
    id: number;
    site: string;
    station_number: string;
    campaign: string;
    campaign_id: number | null;
    status: string;
    monitor_type: string;
    pc_spec: string;
    processor_label?: string | null;
    processor_cores?: number | null;
    processor_threads?: number | null;
    pc_spec_details?: {
        id: number;
        pc_number?: string | null;
        manufacturer?: string | null;
        model: string;
        memory_type?: string | null;
        ram_gb: number;
        disk_gb: number;
        available_ports?: string | null;
        bios_release_date?: string | null;
        issue?: string | null;
        processorSpecs: ProcessorSpec[];
    };
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
interface ProcessorOption { id: number; label: string; core_count?: number | null; thread_count?: number | null; }
interface Filters {
    sites: Site[];
    campaigns: Campaign[];
    statuses: string[];
    processors: ProcessorOption[];
}

interface StationOption {
    id: number;
    label: string;
}

interface PageProps extends Record<string, unknown> {
    stations: StationsPayload;
    flash?: Flash;
    filters: Filters;
    allStations: StationOption[];
    allMatchingIds: number[];
}

export default function StationIndex() {
    const { stations, filters, allStations = [], allMatchingIds } = usePage<PageProps>().props;

    // Current pagination page (preserved across CRUD operations)
    const currentPage = stations.meta?.current_page ?? 1;
    const editLinkSuffix = currentPage > 1 ? `?page=${currentPage}` : '';
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

    // QR Code download state
    const [bulkProgress, setBulkProgress] = useState<{ running: boolean; percent: number; status: string }>({ running: false, percent: 0, status: '' });
    const [selectedZipProgress, setSelectedZipProgress] = useState<{ running: boolean; percent: number; status: string }>({ running: false, percent: 0, status: '' });

    // Track if all records are selected (across all pages)
    const [isAllRecordsSelected, setIsAllRecordsSelected] = useState(false);

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

    // When "select all records" is active, sync selectedStationIds with allMatchingIds
    useEffect(() => {
        if (isAllRecordsSelected) {
            setSelectedStationIds(allMatchingIds);
        }
    }, [isAllRecordsSelected, allMatchingIds]);

    // Current page IDs
    const currentPageIds = stations.data.map((s) => s.id);

    // Selection state helpers
    const isCurrentPageAllSelected = currentPageIds.length > 0 && currentPageIds.every((id) => selectedStationIds.includes(id));
    const isCurrentPagePartiallySelected = currentPageIds.some((id) => selectedStationIds.includes(id)) && !isCurrentPageAllSelected;

    // Handle header checkbox - selects current page items
    const handleSelectAllStations = (checked: boolean) => {
        if (checked) {
            // Add current page items to selection
            setSelectedStationIds((prev) => [...new Set([...prev, ...currentPageIds])]);
        } else {
            // Clear all selections
            setSelectedStationIds([]);
            setIsAllRecordsSelected(false);
        }
    };

    // Handle "Select all X records" button click
    const handleSelectAllRecords = () => {
        setIsAllRecordsSelected(true);
        setSelectedStationIds(allMatchingIds);
    };

    // Handle individual checkbox
    const handleSelectStation = (id: number, checked: boolean) => {
        if (checked) {
            setSelectedStationIds((prev) => [...prev, id]);
        } else {
            setSelectedStationIds((prev) => prev.filter((sid) => sid !== id));
            // If user unchecks one while "all records" is selected, turn off all-records mode
            if (isAllRecordsSelected) {
                setIsAllRecordsSelected(false);
            }
        }
    };

    // Clear all selections (including localStorage)
    const handleClearStationSelection = () => {
        setSelectedStationIds([]);
        setIsAllRecordsSelected(false);
        try {
            localStorage.removeItem(LOCAL_STORAGE_KEY);
            localStorage.removeItem(LOCAL_STORAGE_TIMESTAMP_KEY);
        } catch {
            // Ignore localStorage errors
        }
    };

    // Bulk QR ZIP - streaming
    const handleBulkDownloadAllQRCodes = () => {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (!csrfToken) return toast.error('CSRF token not found');

        setBulkProgress({ running: true, percent: 0, status: 'Generating...' });
        toast.info('Generating QR codes...');

        fetch('/stations/qrcode/bulk-all-stream', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ format: 'png', size: 245, metadata: 0 }),
        })
            .then(res => {
                if (!res.ok) throw new Error('Failed to generate QR codes');
                const filename = res.headers.get('Content-Disposition')?.match(/filename="?(.+?)"?$/)?.[1] || 'station-qrcodes-all.zip';
                return res.blob().then(blob => ({ blob, filename }));
            })
            .then(({ blob, filename }) => {
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
                toast.success('Download started');
                setBulkProgress({ running: false, percent: 100, status: 'Done' });
            })
            .catch(() => {
                toast.error('Failed to download QR codes');
                setBulkProgress({ running: false, percent: 0, status: '' });
            });
    };

    // Selected QR ZIP - streaming
    const handleDownloadSelectedQRCodes = () => {
        if (selectedStationIds.length === 0) return toast.error('No stations selected');
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (!csrfToken) return toast.error('CSRF token not found');

        setSelectedZipProgress({ running: true, percent: 0, status: 'Generating...' });
        toast.info('Generating selected QR codes...');

        fetch('/stations/qrcode/zip-selected-stream', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ station_ids: selectedStationIds, format: 'png', size: 245, metadata: 0 }),
        })
            .then(res => {
                if (!res.ok) throw new Error('Failed to generate QR codes');
                const filename = res.headers.get('Content-Disposition')?.match(/filename="?(.+?)"?$/)?.[1] || 'station-qrcodes-selected.zip';
                return res.blob().then(blob => ({ blob, filename }));
            })
            .then(({ blob, filename }) => {
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
                toast.success('Download started');
                setSelectedZipProgress({ running: false, percent: 100, status: 'Done' });
                setSelectedStationIds([]);
                setIsAllRecordsSelected(false);
                try {
                    localStorage.removeItem(LOCAL_STORAGE_KEY);
                    localStorage.removeItem(LOCAL_STORAGE_TIMESTAMP_KEY);
                } catch {
                    // Ignore localStorage errors
                }
            })
            .catch(() => {
                toast.error('Failed to download QR codes');
                setSelectedZipProgress({ running: false, percent: 0, status: '' });
            });
    };

    // Use new hooks for cleaner code
    const { title, breadcrumbs } = usePageMeta({
        title: "Stations",
        breadcrumbs: [{ title: "Stations", href: stationsIndexRoute().url }]
    });

    useFlashMessage(); // Automatically handles flash messages
    const isPageLoading = usePageLoading(); // Track page loading state

    // Initialize filters from URL params
    const urlParams = new URLSearchParams(window.location.search);
    const [loading, setLoading] = useState(false);

    // Multi-select station search state
    const [stationSearchQuery, setStationSearchQuery] = useState('');
    const [isStationPopoverOpen, setIsStationPopoverOpen] = useState(false);
    const initialStationIds = urlParams.getAll('station_ids[]').map(Number).filter(Boolean);
    const [selectedFilterStationIds, setSelectedFilterStationIds] = useState<number[]>(initialStationIds);

    const [siteFilter, setSiteFilter] = useState(urlParams.get('site') || "all");
    const [campaignFilter, setCampaignFilter] = useState(urlParams.get('campaign') || "all");
    const [statusFilter, setStatusFilter] = useState(urlParams.get('status') || "all");

    // Processor multi-select state
    const initialProcessorIds = urlParams.getAll('processor_ids[]').map(Number).filter(Boolean);
    const [selectedFilterProcessorIds, setSelectedFilterProcessorIds] = useState<number[]>(initialProcessorIds);
    const [processorSearchQuery, setProcessorSearchQuery] = useState('');
    const [isProcessorPopoverOpen, setIsProcessorPopoverOpen] = useState(false);
    const filteredProcessorOptions = useMemo(() => {
        const opts = filters.processors ?? [];
        if (!processorSearchQuery) return opts;
        const lower = processorSearchQuery.toLowerCase();
        return opts.filter(p => p.label.toLowerCase().includes(lower));
    }, [filters.processors, processorSearchQuery]);
    const handleToggleProcessorSelect = (procId: number) => {
        setSelectedFilterProcessorIds(prev =>
            prev.includes(procId) ? prev.filter(id => id !== procId) : [...prev, procId]
        );
    };
    const [pcSpecDialogOpen, setPcSpecDialogOpen] = useState(false);
    const [selectedPcSpec, setSelectedPcSpec] = useState<Station['pc_spec_details'] | null>(null);

    const [issueDialogOpen, setIssueDialogOpen] = useState(false);
    const [issueText, setIssueText] = useState("");
    const [selectedEmptyStations, setSelectedEmptyStations] = useState<number[]>([]);
    const [lastRefresh, setLastRefresh] = useState<Date>(new Date());
    const [autoRefreshEnabled, setAutoRefreshEnabled] = useState(false);

    // Bulk Assign Dialog state
    type AssignGroup = { station_ids: number[]; campaign_id: string; status: string; monitor_type: string; search: string; popoverOpen: boolean };
    const emptyGroup = (): AssignGroup => ({ station_ids: [], campaign_id: 'none', status: 'none', monitor_type: 'none', search: '', popoverOpen: false });
    const [bulkAssignOpen, setBulkAssignOpen] = useState(false);
    const [assignGroups, setAssignGroups] = useState<AssignGroup[]>([emptyGroup()]);
    const [bulkAssignSubmitting, setBulkAssignSubmitting] = useState(false);
    const [bulkUnassignConfirmOpen, setBulkUnassignConfirmOpen] = useState(false);
    const [bulkUnassignSubmitting, setBulkUnassignSubmitting] = useState(false);

    const updateAssignGroup = (idx: number, patch: Partial<AssignGroup>) => {
        setAssignGroups(prev => prev.map((g, i) => i === idx ? { ...g, ...patch } : g));
    };
    const removeAssignGroup = (idx: number) => {
        setAssignGroups(prev => prev.length === 1 ? [emptyGroup()] : prev.filter((_, i) => i !== idx));
    };
    const toggleGroupStation = (idx: number, stationId: number) => {
        setAssignGroups(prev => prev.map((g, i) => {
            if (i !== idx) return g;
            const exists = g.station_ids.includes(stationId);
            return { ...g, station_ids: exists ? g.station_ids.filter(id => id !== stationId) : [...g.station_ids, stationId] };
        }));
    };

    const handleSubmitBulkAssign = () => {
        // Build payload, omitting groups with no stations or no changes
        const payloadGroups = assignGroups
            .filter(g => g.station_ids.length > 0 && (g.campaign_id !== 'none' || g.status !== 'none' || g.monitor_type !== 'none'))
            .map(g => {
                const out: { station_ids: number[]; campaign_id?: number | null; status?: string; monitor_type?: string } = {
                    station_ids: g.station_ids,
                };
                if (g.campaign_id !== 'none') {
                    out.campaign_id = g.campaign_id === 'clear' ? null : parseInt(g.campaign_id, 10);
                }
                if (g.status !== 'none') {
                    out.status = g.status;
                }
                if (g.monitor_type !== 'none') {
                    out.monitor_type = g.monitor_type === 'none-monitor' ? 'none' : g.monitor_type;
                }
                return out;
            });

        if (payloadGroups.length === 0) {
            toast.error('Add at least one group with stations and a campaign, status, or monitor type to apply.');
            return;
        }

        setBulkAssignSubmitting(true);
        router.post('/stations/bulk-assign', { groups: payloadGroups } as unknown as RequestPayload, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Bulk assignment applied');
                setBulkAssignOpen(false);
                setAssignGroups([emptyGroup()]);
            },
            onError: (errors) => {
                const first = Object.values(errors)[0] as string;
                toast.error(first || 'Failed to apply bulk assignment');
            },
            onFinish: () => setBulkAssignSubmitting(false),
        });
    };

    const handleSubmitBulkUnassign = () => {
        if (selectedStationIds.length === 0) {
            toast.error('No stations selected');
            return;
        }
        setBulkUnassignSubmitting(true);
        router.post('/stations/bulk-unassign', { station_ids: selectedStationIds } as unknown as RequestPayload, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Stations unassigned');
                setBulkUnassignConfirmOpen(false);
                setSelectedStationIds([]);
                setIsAllRecordsSelected(false);
            },
            onError: () => toast.error('Failed to unassign stations'),
            onFinish: () => setBulkUnassignSubmitting(false),
        });
    };

    // Filter station options by search query
    const filteredStationOptions = useMemo(() => {
        if (!stationSearchQuery) return allStations;
        const lower = stationSearchQuery.toLowerCase();
        return allStations.filter(s => s.label.toLowerCase().includes(lower));
    }, [allStations, stationSearchQuery]);

    const handleToggleStationSelect = (stationId: number) => {
        setSelectedFilterStationIds(prev =>
            prev.includes(stationId)
                ? prev.filter(id => id !== stationId)
                : [...prev, stationId]
        );
    };

    const handleRemoveStationFilter = (stationId: number) => {
        setSelectedFilterStationIds(prev => prev.filter(id => id !== stationId));
    };

    const applyFilters = () => {
        const params: RequestPayload = {};
        if (selectedFilterStationIds.length > 0) params.station_ids = selectedFilterStationIds;
        if (siteFilter && siteFilter !== "all") params.site = siteFilter;
        if (campaignFilter && campaignFilter !== "all") params.campaign = campaignFilter;
        if (statusFilter && statusFilter !== "all") params.status = statusFilter;
        if (selectedFilterProcessorIds.length > 0) params.processor_ids = selectedFilterProcessorIds;

        setLoading(true);
        router.get(stationsIndexRoute().url, params, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => setLastRefresh(new Date()),
            onFinish: () => setLoading(false),
        });
    };

    const resetFilters = () => {
        setSelectedFilterStationIds([]);
        setStationSearchQuery('');
        setSiteFilter("all");
        setCampaignFilter("all");
        setStatusFilter("all");
        setSelectedFilterProcessorIds([]);
        setProcessorSearchQuery('');
        setLoading(true);
        router.get(stationsIndexRoute().url, {}, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => setLastRefresh(new Date()),
            onFinish: () => setLoading(false),
        });
    };

    const handleManualRefresh = () => {
        setLoading(true);
        router.reload({
            only: ['stations'],
            onSuccess: () => setLastRefresh(new Date()),
            onFinish: () => setLoading(false),
        });
    };

    // Auto-refresh every 30 seconds (only when enabled)
    useEffect(() => {
        if (!autoRefreshEnabled) return;

        const interval = setInterval(() => {
            const params: RequestPayload = {};
            if (selectedFilterStationIds.length > 0) params.station_ids = selectedFilterStationIds;
            if (siteFilter && siteFilter !== "all") params.site = siteFilter;
            if (campaignFilter && campaignFilter !== "all") params.campaign = campaignFilter;
            if (statusFilter && statusFilter !== "all") params.status = statusFilter;

            router.get(stationsIndexRoute().url, params, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['stations'],
                onSuccess: () => setLastRefresh(new Date()),
            });
        }, 30000);

        return () => clearInterval(interval);
    }, [autoRefreshEnabled, selectedFilterStationIds, siteFilter, campaignFilter, statusFilter]);

    const handleDelete = (stationId: number) => {
        setLoading(true);
        router.delete(stationsDestroyRoute(stationId).url, {
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

    const handleBulkAssign = () => {
        if (selectedEmptyStations.length === 0) {
            toast.error('Please select at least one empty station');
            return;
        }

        // Navigate to transfer page with multiple stations
        const stationIds = selectedEmptyStations.join(',');
        router.visit(transferPage(undefined, { query: { stations: stationIds } }).url);
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
                <div className="flex flex-col gap-4">
                    {/* Action bar */}
                    <div className="flex flex-wrap items-center justify-end gap-2">
                        <div className="flex gap-1 mr-auto">
                            <Button variant="ghost" onClick={handleManualRefresh} size="icon" title="Refresh">
                                <RefreshCw className="h-4 w-4" />
                            </Button>
                            <Button
                                variant={autoRefreshEnabled ? "default" : "ghost"}
                                onClick={() => setAutoRefreshEnabled(!autoRefreshEnabled)}
                                size="icon"
                                title={autoRefreshEnabled ? "Disable auto-refresh" : "Enable auto-refresh (30s)"}
                            >
                                {autoRefreshEnabled ? <Pause className="h-4 w-4" /> : <Play className="h-4 w-4" />}
                            </Button>
                        </div>

                        <Can permission="stations.create">
                            <Button onClick={() => router.get(stationsCreateRoute().url + editLinkSuffix)} size="sm">
                                <Plus className="mr-2 h-4 w-4" />
                                Add Station
                            </Button>
                        </Can>
                        <Can permission="stations.edit">
                            <Button variant="secondary" onClick={() => setBulkAssignOpen(true)} size="sm">
                                <CheckSquare className="mr-2 h-4 w-4" />
                                Bulk Assign
                            </Button>
                        </Can>
                        <Can permission="sites.view">
                            <Button variant="outline" size="sm" onClick={() => router.get(sitesIndexRoute().url)}>
                                Sites
                            </Button>
                        </Can>
                        <Can permission="campaigns.view">
                            <Button variant="outline" size="sm" onClick={() => router.get(campaignsIndexRoute().url)}>
                                Campaigns
                            </Button>
                        </Can>
                    </div>

                    {/* Filter card */}
                    <div className="rounded-lg border bg-card p-4 space-y-4">
                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4">
                            <div className="space-y-1.5">
                                <Label className="text-xs text-muted-foreground">Stations</Label>
                                <Popover open={isStationPopoverOpen} onOpenChange={setIsStationPopoverOpen}>
                                    <PopoverTrigger asChild>
                                        <Button
                                            variant="outline"
                                            role="combobox"
                                            aria-expanded={isStationPopoverOpen}
                                            className="w-full justify-between font-normal"
                                        >
                                            <span className="truncate">
                                                {selectedFilterStationIds.length > 0
                                                    ? `${selectedFilterStationIds.length} selected`
                                                    : "All stations"}
                                            </span>
                                            <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                        </Button>
                                    </PopoverTrigger>
                                    <PopoverContent className="w-full min-w-75 p-0" align="start">
                                        <Command shouldFilter={false}>
                                            <CommandInput
                                                placeholder="Search station..."
                                                value={stationSearchQuery}
                                                onValueChange={setStationSearchQuery}
                                            />
                                            <CommandList>
                                                <CommandEmpty>No station found.</CommandEmpty>
                                                <CommandGroup>
                                                    {filteredStationOptions.map((station) => (
                                                        <CommandItem
                                                            key={station.id}
                                                            value={String(station.id)}
                                                            onSelect={() => handleToggleStationSelect(station.id)}
                                                        >
                                                            <Check
                                                                className={`mr-2 h-4 w-4 ${selectedFilterStationIds.includes(station.id) ? "opacity-100" : "opacity-0"}`}
                                                            />
                                                            {station.label}
                                                        </CommandItem>
                                                    ))}
                                                </CommandGroup>
                                            </CommandList>
                                        </Command>
                                    </PopoverContent>
                                </Popover>
                            </div>

                            <div className="space-y-1.5">
                                <Label className="text-xs text-muted-foreground">Site</Label>
                                <Select value={siteFilter} onValueChange={setSiteFilter}>
                                    <SelectTrigger className="w-full">
                                        <SelectValue placeholder="All sites" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All sites</SelectItem>
                                        {filters.sites.map((site) => (
                                            <SelectItem key={site.id} value={String(site.id)}>{site.name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-1.5">
                                <Label className="text-xs text-muted-foreground">Campaign</Label>
                                <Select value={campaignFilter} onValueChange={setCampaignFilter}>
                                    <SelectTrigger className="w-full">
                                        <SelectValue placeholder="All campaigns" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All campaigns</SelectItem>
                                        {filters.campaigns.map((campaign) => (
                                            <SelectItem key={campaign.id} value={String(campaign.id)}>{campaign.name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-1.5">
                                <Label className="text-xs text-muted-foreground">Status</Label>
                                <Select value={statusFilter} onValueChange={setStatusFilter}>
                                    <SelectTrigger className="w-full">
                                        <SelectValue placeholder="All statuses" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All statuses</SelectItem>
                                        {filters.statuses.map((status) => (
                                            <SelectItem key={status} value={status}>{status}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-1.5">
                                <Label className="text-xs text-muted-foreground">Processor</Label>
                                <Popover open={isProcessorPopoverOpen} onOpenChange={setIsProcessorPopoverOpen}>
                                    <PopoverTrigger asChild>
                                        <Button
                                            variant="outline"
                                            role="combobox"
                                            aria-expanded={isProcessorPopoverOpen}
                                            className="w-full justify-between font-normal"
                                        >
                                            <span className="truncate">
                                                {selectedFilterProcessorIds.length > 0
                                                    ? `${selectedFilterProcessorIds.length} selected`
                                                    : 'All processors'}
                                            </span>
                                            <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                        </Button>
                                    </PopoverTrigger>
                                    <PopoverContent className="w-full min-w-75 p-0" align="start">
                                        <Command shouldFilter={false}>
                                            <CommandInput
                                                placeholder="Search processor..."
                                                value={processorSearchQuery}
                                                onValueChange={setProcessorSearchQuery}
                                            />
                                            <CommandList>
                                                <CommandEmpty>No processor found.</CommandEmpty>
                                                <CommandGroup>
                                                    {filteredProcessorOptions.map((proc) => (
                                                        <CommandItem
                                                            key={proc.id}
                                                            value={proc.label}
                                                            onSelect={() => handleToggleProcessorSelect(proc.id)}
                                                            className="cursor-pointer"
                                                        >
                                                            <Check
                                                                className={`mr-2 h-4 w-4 ${selectedFilterProcessorIds.includes(proc.id) ? "opacity-100" : "opacity-0"}`}
                                                            />
                                                            {proc.label}
                                                            {proc.core_count != null && proc.thread_count != null && (
                                                                <span className="ml-auto text-xs text-muted-foreground">{proc.core_count}C / {proc.thread_count}T</span>
                                                            )}
                                                        </CommandItem>
                                                    ))}
                                                </CommandGroup>
                                            </CommandList>
                                        </Command>
                                    </PopoverContent>
                                </Popover>
                            </div>
                        </div>

                        <div className="flex items-center justify-end gap-2 pt-1 border-t">
                            {(siteFilter !== "all" || campaignFilter !== "all" || statusFilter !== "all" || selectedFilterStationIds.length > 0 || selectedFilterProcessorIds.length > 0) && (
                                <Button variant="ghost" size="sm" onClick={resetFilters}>
                                    Reset
                                </Button>
                            )}
                            <Button onClick={applyFilters} size="sm">
                                <Check className="mr-2 h-4 w-4" />
                                Apply Filters
                            </Button>
                        </div>
                    </div>

                    {/* Selected station filter badges */}
                    {selectedFilterStationIds.length > 0 && (
                        <div className="flex flex-wrap gap-2">
                            {selectedFilterStationIds.map((id) => {
                                const station = allStations.find(s => s.id === id);
                                return station ? (
                                    <Badge key={id} variant="secondary" className="flex items-center gap-1">
                                        {station.label}
                                        <button
                                            type="button"
                                            onClick={() => handleRemoveStationFilter(id)}
                                            className="ml-1 rounded-full outline-none hover:bg-muted"
                                            title="Remove filter"
                                        >
                                            <X className="h-3 w-3" />
                                        </button>
                                    </Badge>
                                ) : null;
                            })}
                        </div>
                    )}

                    {/* Bulk Actions Section */}
                    {(selectedEmptyStations.length > 0 || selectedStationIds.length > 0) && (
                        <div className="flex flex-col sm:flex-row gap-4 p-4 bg-muted/50 rounded-lg border">
                            {/* Bulk Selection Controls */}
                            {selectedEmptyStations.length > 0 && (
                                <div className="flex flex-col sm:flex-row sm:flex-wrap items-start sm:items-center gap-2 min-w-0 flex-1">
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
                                            Assign PCs
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
                                <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between min-w-0 flex-1">
                                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center min-w-0">
                                        <span className="font-medium text-blue-900 dark:text-blue-100">
                                            {isAllRecordsSelected
                                                ? `All ${allMatchingIds.length} station${allMatchingIds.length !== 1 ? 's' : ''} selected`
                                                : `${selectedStationIds.length} station${selectedStationIds.length !== 1 ? 's' : ''} selected`}
                                        </span>
                                        {!isAllRecordsSelected && selectedStationIds.length < allMatchingIds.length && allMatchingIds.length > currentPageIds.length && (
                                            <Button variant="link" size="sm" className="h-auto p-0 text-primary" onClick={handleSelectAllRecords}>
                                                Select all {allMatchingIds.length} stations
                                            </Button>
                                        )}
                                        <Button variant="outline" size="sm" onClick={handleClearStationSelection} className="w-full sm:w-auto min-w-0">
                                            Clear
                                        </Button>
                                    </div>
                                    <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:gap-2 min-w-0">
                                        <Can permission="stations.edit">
                                            <Button
                                                onClick={() => setBulkUnassignConfirmOpen(true)}
                                                variant="outline"
                                                className="border-orange-600 text-orange-600 hover:bg-orange-600 hover:text-white dark:hover:text-white w-full sm:w-auto min-w-0"
                                                size="sm"
                                            >
                                                Unassign Selected
                                            </Button>
                                        </Can>
                                        <Button
                                            onClick={handleDownloadSelectedQRCodes}
                                            variant="outline"
                                            className="border-green-600 text-green-600 hover:bg-green-600 hover:text-white dark:hover:text-white w-full sm:w-auto min-w-0"
                                            disabled={selectedZipProgress.running}
                                            size="sm"
                                        >
                                            {selectedZipProgress.running ? 'Processing...' : 'Download Selected QR'}
                                        </Button>
                                        <Button
                                            onClick={handleBulkDownloadAllQRCodes}
                                            className="bg-blue-700 hover:bg-blue-800 text-white w-full sm:w-auto min-w-0"
                                            disabled={bulkProgress.running}
                                            size="sm"
                                        >
                                            {bulkProgress.running ? 'Processing...' : 'Download All QR'}
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </div>
                    )}
                </div>

                {/* Results Count with Empty Stations Info */}
                <div className="flex justify-between items-center text-sm">
                    <div className="text-muted-foreground">
                        Showing {stations.data.length} of {stations.meta.total} station{stations.meta.total !== 1 ? 's' : ''}
                        {(siteFilter !== "all" || campaignFilter !== "all" || statusFilter !== "all" || selectedFilterStationIds.length > 0) && ' (filtered)'}
                    </div>
                    <div className="flex items-center gap-4">
                        {emptyStationsCount > 0 && (
                            <div className="text-orange-600 font-medium">
                                {emptyStationsCount} station{emptyStationsCount > 1 ? 's' : ''} without PC
                            </div>
                        )}
                        <div className="text-xs text-muted-foreground">Last updated: {lastRefresh.toLocaleTimeString()}</div>
                    </div>
                </div>

                {/* Desktop Table View - hidden on mobile */}
                <div className="hidden md:block shadow rounded-md overflow-hidden">
                    {isPageLoading ? (
                        <TableSkeleton columns={13} rows={8} />
                    ) : (
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow className="bg-muted/50">
                                        <TableHead className="w-12">
                                            <Checkbox
                                                checked={isAllRecordsSelected || isCurrentPageAllSelected}
                                                ref={(el) => {
                                                    if (el) {
                                                        (el as HTMLButtonElement).dataset.state = isCurrentPagePartiallySelected ? 'indeterminate' : ((isAllRecordsSelected || isCurrentPageAllSelected) ? 'checked' : 'unchecked');
                                                    }
                                                }}
                                                onCheckedChange={(checked) => handleSelectAllStations(checked === true)}
                                                aria-label="Select all on this page"
                                            />
                                        </TableHead>
                                        <TableHead>Site</TableHead>
                                        <TableHead>Station #</TableHead>
                                        <TableHead>Campaign</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Monitor Type</TableHead>
                                        <TableHead>PC Spec</TableHead>
                                        <TableHead className="hidden lg:table-cell">Processor</TableHead>
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
                                                    <Checkbox
                                                        checked={selectedStationIds.includes(station.id)}
                                                        onCheckedChange={(checked) => handleSelectStation(station.id, checked === true)}
                                                    />
                                                </TableCell>

                                                <TableCell>{station.site}</TableCell>
                                                <TableCell>{station.station_number}</TableCell>
                                                <TableCell>
                                                    <Can permission="stations.edit" fallback={<span>{station.campaign ?? '—'}</span>}>
                                                        <Select
                                                            value={station.campaign_id ? String(station.campaign_id) : 'none'}
                                                            onValueChange={(v) => {
                                                                router.patch(`/stations/${station.id}/quick-update`, {
                                                                    campaign_id: v === 'none' ? null : parseInt(v, 10),
                                                                }, {
                                                                    preserveScroll: true,
                                                                    preserveState: true,
                                                                    only: ['stations', 'flash'],
                                                                });
                                                            }}
                                                        >
                                                            <SelectTrigger className="h-8 w-37.5">
                                                                <SelectValue placeholder="—" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                <SelectItem value="none">— None —</SelectItem>
                                                                {filters.campaigns.map((campaign) => (
                                                                    <SelectItem key={campaign.id} value={String(campaign.id)}>{campaign.name}</SelectItem>
                                                                ))}
                                                            </SelectContent>
                                                        </Select>
                                                    </Can>
                                                </TableCell>
                                                <TableCell>
                                                    <Can
                                                        permission="stations.edit"
                                                        fallback={
                                                            <Badge
                                                                className={
                                                                    (station.status ?? '').toLowerCase() === 'occupied'
                                                                        ? 'bg-green-500 hover:bg-green-600 text-white'
                                                                        : (station.status ?? '').toLowerCase() === 'vacant'
                                                                            ? 'bg-yellow-500 hover:bg-yellow-600 text-white'
                                                                            : (station.status ?? '').toLowerCase() === 'no pc'
                                                                                ? 'bg-red-500 hover:bg-red-600 text-white'
                                                                                : (station.status ?? '').toLowerCase() === 'admin'
                                                                                    ? 'bg-blue-500 hover:bg-blue-600 text-white'
                                                                                    : 'bg-gray-500 hover:bg-gray-600 text-white'
                                                                }
                                                            >
                                                                {station.status || 'N/A'}
                                                            </Badge>
                                                        }
                                                    >
                                                        <Select
                                                            value={station.status || 'none'}
                                                            onValueChange={(v) => {
                                                                router.patch(`/stations/${station.id}/quick-update`, {
                                                                    status: v === 'none' ? null : v,
                                                                }, {
                                                                    preserveScroll: true,
                                                                    preserveState: true,
                                                                    only: ['stations', 'flash'],
                                                                });
                                                            }}
                                                        >
                                                            <SelectTrigger className="h-8 w-32.5">
                                                                <SelectValue placeholder="—" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                <SelectItem value="none">— None —</SelectItem>
                                                                {['Admin', 'Occupied', 'Vacant', 'No PC'].map((status) => (
                                                                    <SelectItem key={status} value={status}>{status}</SelectItem>
                                                                ))}
                                                            </SelectContent>
                                                        </Select>
                                                    </Can>
                                                </TableCell>
                                                <TableCell>
                                                    <Can
                                                        permission="stations.edit"
                                                        fallback={
                                                            <span className={station.monitor_type === 'dual' ? 'text-blue-600 font-medium' : ''}>
                                                                {station.monitor_type === 'dual' ? 'Dual' : station.monitor_type === 'none' ? 'No Monitor' : 'Single'}
                                                            </span>
                                                        }
                                                    >
                                                        <Select
                                                            value={station.monitor_type || 'single'}
                                                            onValueChange={(v) => {
                                                                router.patch(`/stations/${station.id}/quick-update`, {
                                                                    monitor_type: v,
                                                                }, {
                                                                    preserveScroll: true,
                                                                    preserveState: true,
                                                                    only: ['stations', 'flash'],
                                                                });
                                                            }}
                                                        >
                                                            <SelectTrigger className="h-8 w-32.5">
                                                                <SelectValue placeholder="—" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                <SelectItem value="single">Single</SelectItem>
                                                                <SelectItem value="dual">Dual</SelectItem>
                                                                <SelectItem value="none">No Monitor</SelectItem>
                                                            </SelectContent>
                                                        </Select>
                                                    </Can>
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
                                                                onClick={() => router.visit(transferPage(station.id, { query: { filter: 'available' } }).url)}
                                                                className="gap-2"
                                                                title="Assign PC to this station"
                                                            >
                                                                <Plus className="h-4 w-4" />
                                                                Assign PC
                                                            </Button>
                                                        )}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="hidden lg:table-cell">
                                                    {station.processor_label ? (
                                                        <div className="flex flex-col leading-tight">
                                                            <span className="truncate max-w-50" title={station.processor_label}>{station.processor_label}</span>
                                                            {(station.processor_cores != null || station.processor_threads != null) && (
                                                                <span className="text-xs text-muted-foreground">
                                                                    {station.processor_cores ?? '?'}C/{station.processor_threads ?? '?'}T
                                                                </span>
                                                            )}
                                                        </div>
                                                    ) : (
                                                        <span className="text-gray-400">—</span>
                                                    )}
                                                </TableCell>
                                                <TableCell className="hidden xl:table-cell">
                                                    {station.pc_spec_details ? (
                                                        <div className="flex items-center gap-2">
                                                            {station.pc_spec_details.issue ? (
                                                                <>
                                                                    <AlertTriangle className="h-4 w-4 text-red-600 shrink-0" />
                                                                    <span className="text-xs text-red-600 font-medium truncate max-w-37.5" title={station.pc_spec_details.issue}>
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
                                                        <Can permission="stations.edit">
                                                            <Button variant="outline" size="sm" onClick={() => router.get(stationsEditRoute(station.id).url + editLinkSuffix)} disabled={loading}>
                                                                Edit
                                                            </Button>
                                                        </Can>

                                                        {/* Reusable delete confirmation dialog */}
                                                        <Can permission="stations.delete">
                                                            <DeleteConfirmDialog
                                                                onConfirm={() => handleDelete(station.id)}
                                                                title="Delete Station"
                                                                description={`Are you sure you want to delete station "${station.station_number}"?`}
                                                                disabled={loading}
                                                            />
                                                        </Can>
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                    {stations.data.length === 0 && !loading && (
                                        <TableRow>
                                            <TableCell colSpan={10} className="py-8 text-center text-gray-500">
                                                No stations found
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </div>

                {/* Mobile Card View - visible only on mobile */}
                <div className="md:hidden space-y-4">
                    {stations.data.map((station) => {
                        const isSelected = selectedEmptyStations.includes(station.id);
                        const isQRSelected = selectedStationIds.includes(station.id);

                        return (
                            <div
                                key={station.id}
                                className={`bg-card border rounded-lg p-4 shadow-sm space-y-3 ${isSelected ? 'bg-blue-50 dark:bg-blue-900/20 border-blue-500' : ''} ${isQRSelected ? 'ring-2 ring-primary' : ''}`}
                            >
                                {/* Header with Checkbox, Station Number and Status */}
                                <div className="flex justify-between items-start">
                                    <div className="flex items-start gap-3">
                                        {/* QR Code Selection Checkbox */}
                                        <Checkbox
                                            checked={isQRSelected}
                                            onCheckedChange={(checked) => handleSelectStation(station.id, checked === true)}
                                            aria-label={`Select station ${station.station_number} for QR download`}
                                            className="mt-1"
                                        />
                                        <div>
                                            <div className="text-xs text-muted-foreground">Station</div>
                                            <div className="font-semibold text-lg">{station.station_number}</div>
                                        </div>
                                    </div>
                                    <div className="text-right">
                                        <div className="text-xs text-muted-foreground">Status</div>
                                        <Badge
                                            className={
                                                (station.status ?? '').toLowerCase() === 'occupied'
                                                    ? 'bg-green-500 hover:bg-green-600 text-white'
                                                    : (station.status ?? '').toLowerCase() === 'vacant'
                                                        ? 'bg-yellow-500 hover:bg-yellow-600 text-white'
                                                        : (station.status ?? '').toLowerCase() === 'no pc'
                                                            ? 'bg-red-500 hover:bg-red-600 text-white'
                                                            : (station.status ?? '').toLowerCase() === 'admin'
                                                                ? 'bg-blue-500 hover:bg-blue-600 text-white'
                                                                : 'bg-gray-500 hover:bg-gray-600 text-white'
                                            }
                                        >
                                            {station.status || 'N/A'}
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
                                            {station.monitor_type === 'dual' ? 'Dual' : station.monitor_type === 'none' ? 'No Monitor' : 'Single'}
                                        </span>
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
                                                    onClick={() => router.visit(transferPage(station.id, { query: { filter: 'available' } }).url)}
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
                                                            <AlertTriangle className="h-4 w-4 text-red-600 shrink-0" />
                                                            <span className="text-xs text-red-600 font-medium truncate max-w-30" title={station.pc_spec_details.issue}>
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
                                    <Can permission="stations.edit">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => router.get(stationsEditRoute(station.id).url + editLinkSuffix)}
                                            disabled={loading}
                                            className="flex-1"
                                        >
                                            Edit
                                        </Button>
                                    </Can>

                                    {/* Reusable delete confirmation dialog */}
                                    <Can permission="stations.delete">
                                        <div className="flex-1">
                                            <DeleteConfirmDialog
                                                onConfirm={() => handleDelete(station.id)}
                                                title="Delete Station"
                                                description={`Are you sure you want to delete station "${station.station_number}"?`}
                                                disabled={loading}
                                            />
                                        </div>
                                    </Can>
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
                    <DialogContent className="max-w-[95vw] sm:max-w-lg w-full">
                        <DialogHeader>
                            <DialogTitle className="text-lg font-semibold">
                                {selectedPcSpec?.pc_number || (selectedPcSpec ? `PC #${selectedPcSpec.id}` : 'PC Details')}
                            </DialogTitle>
                            <DialogDescription>
                                {selectedPcSpec ? `${selectedPcSpec.manufacturer ?? ''} ${selectedPcSpec.model}`.trim() : 'No PC selected'}
                            </DialogDescription>
                        </DialogHeader>
                        {selectedPcSpec ? (
                            <div className="space-y-4 text-sm">
                                <div className="grid grid-cols-2 gap-x-6 gap-y-3 rounded-lg border p-4">
                                    <div>
                                        <span className="text-xs text-muted-foreground">Memory Type</span>
                                        <p className="font-medium">{selectedPcSpec.memory_type || 'N/A'}</p>
                                    </div>
                                    <div>
                                        <span className="text-xs text-muted-foreground">RAM</span>
                                        <p className="font-medium">{selectedPcSpec.ram_gb ? `${selectedPcSpec.ram_gb} GB` : 'N/A'}</p>
                                    </div>
                                    <div>
                                        <span className="text-xs text-muted-foreground">Disk</span>
                                        <p className="font-medium">{selectedPcSpec.disk_gb ? `${selectedPcSpec.disk_gb} GB` : 'N/A'}</p>
                                    </div>
                                    <div>
                                        <span className="text-xs text-muted-foreground">Ports</span>
                                        <p className="font-medium">{selectedPcSpec.available_ports || 'N/A'}</p>
                                    </div>
                                    <div>
                                        <span className="text-xs text-muted-foreground">Bios Release Date</span>
                                        <p className="font-medium">{selectedPcSpec.bios_release_date || 'N/A'}</p>
                                    </div>
                                </div>

                                {selectedPcSpec.processorSpecs?.length ? (
                                    <div className="space-y-2">
                                        <h4 className="text-sm font-semibold">Processor</h4>
                                        {selectedPcSpec.processorSpecs.map((p) => (
                                            <div key={p.id} className="grid grid-cols-2 gap-x-6 gap-y-3 rounded-lg border p-4">
                                                <div className="col-span-2">
                                                    <span className="text-xs text-muted-foreground">Processor</span>
                                                    <p className="font-medium">{p.manufacturer} {p.model}</p>
                                                </div>
                                                <div>
                                                    <span className="text-xs text-muted-foreground">Cores / Threads</span>
                                                    <p className="font-medium">{p.core_count} / {p.thread_count}</p>
                                                </div>
                                                <div>
                                                    <span className="text-xs text-muted-foreground">Clock (Base / Boost)</span>
                                                    <p className="font-medium">{p.base_clock_ghz} / {p.boost_clock_ghz} GHz</p>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-muted-foreground text-sm">No processor specs available.</p>
                                )}

                                {selectedPcSpec.issue && (
                                    <div className="rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-4">
                                        <span className="text-xs text-muted-foreground">Issue</span>
                                        <p className="font-medium text-red-600 dark:text-red-400">{selectedPcSpec.issue}</p>
                                    </div>
                                )}
                            </div>
                        ) : (
                            <p className="text-muted-foreground">No PC spec details available.</p>
                        )}
                    </DialogContent>
                </Dialog>

                {/* Issue Management Dialog */}
                <Dialog open={issueDialogOpen} onOpenChange={setIssueDialogOpen}>
                    <DialogContent className="max-w-[90vw] sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>Manage PC Spec Issue</DialogTitle>
                            <DialogDescription>
                                {selectedPcSpec && (
                                    <span className="text-sm wrap-break-word">
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

                {/* Bulk Assign Dialog */}
                <Dialog open={bulkAssignOpen} onOpenChange={setBulkAssignOpen}>
                    <DialogContent className="max-w-[95vw] sm:max-w-3xl max-h-[90vh] overflow-y-auto">
                        <DialogHeader>
                            <DialogTitle>Bulk Assign Stations</DialogTitle>
                            <DialogDescription>
                                Define one or more groups. Each group applies a campaign and/or status to its selected stations.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-4">
                            {assignGroups.map((group, idx) => {
                                const filteredStations = group.search
                                    ? allStations.filter(s => s.label.toLowerCase().includes(group.search.toLowerCase()))
                                    : allStations;
                                return (
                                    <div key={idx} className="border rounded-lg p-3 sm:p-4 space-y-3 bg-muted/30">
                                        <div className="flex justify-between items-start gap-2">
                                            <h4 className="font-semibold text-sm">Group {idx + 1}</h4>
                                            {assignGroups.length > 1 && (
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => removeAssignGroup(idx)}
                                                >
                                                    <X className="h-4 w-4" />
                                                </Button>
                                            )}
                                        </div>

                                        <div>
                                            <Label className="text-xs mb-1 block">Stations ({group.station_ids.length} selected)</Label>
                                            <Popover
                                                open={group.popoverOpen}
                                                onOpenChange={(open) => updateAssignGroup(idx, { popoverOpen: open })}
                                            >
                                                <PopoverTrigger asChild>
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        role="combobox"
                                                        className="w-full justify-between font-normal"
                                                    >
                                                        <span className="truncate">
                                                            {group.station_ids.length > 0
                                                                ? `${group.station_ids.length} station${group.station_ids.length !== 1 ? 's' : ''} selected`
                                                                : 'Select stations...'}
                                                        </span>
                                                        <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                                    </Button>
                                                </PopoverTrigger>
                                                <PopoverContent className="w-full min-w-75 p-0" align="start">
                                                    <Command shouldFilter={false}>
                                                        <CommandInput
                                                            placeholder="Search station..."
                                                            value={group.search}
                                                            onValueChange={(v) => updateAssignGroup(idx, { search: v })}
                                                        />
                                                        <CommandList>
                                                            <CommandEmpty>No station found.</CommandEmpty>
                                                            <CommandGroup>
                                                                {filteredStations.map((s) => (
                                                                    <CommandItem
                                                                        key={s.id}
                                                                        value={String(s.id)}
                                                                        onSelect={() => toggleGroupStation(idx, s.id)}
                                                                    >
                                                                        <Check className={`mr-2 h-4 w-4 ${group.station_ids.includes(s.id) ? 'opacity-100' : 'opacity-0'}`} />
                                                                        {s.label}
                                                                    </CommandItem>
                                                                ))}
                                                            </CommandGroup>
                                                        </CommandList>
                                                    </Command>
                                                </PopoverContent>
                                            </Popover>
                                            {group.station_ids.length > 0 && (
                                                <div className="flex flex-wrap gap-1 mt-2">
                                                    {group.station_ids.map((id) => {
                                                        const s = allStations.find(s => s.id === id);
                                                        return s ? (
                                                            <Badge key={id} variant="secondary" className="text-xs">
                                                                {s.label}
                                                                <button
                                                                    type="button"
                                                                    onClick={() => toggleGroupStation(idx, id)}
                                                                    className="ml-1"
                                                                    title="Remove station"
                                                                >
                                                                    <X className="h-3 w-3" />
                                                                </button>
                                                            </Badge>
                                                        ) : null;
                                                    })}
                                                </div>
                                            )}
                                        </div>

                                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                            <div>
                                                <Label className="text-xs mb-1 block">Campaign</Label>
                                                <Select
                                                    value={group.campaign_id}
                                                    onValueChange={(v) => updateAssignGroup(idx, { campaign_id: v })}
                                                >
                                                    <SelectTrigger className="w-full">
                                                        <SelectValue placeholder="No change" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="none">— No change —</SelectItem>
                                                        <SelectItem value="clear">Clear (no campaign)</SelectItem>
                                                        {filters.campaigns.map((c) => (
                                                            <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>

                                            <div>
                                                <Label className="text-xs mb-1 block">Status</Label>
                                                <Select
                                                    value={group.status}
                                                    onValueChange={(v) => updateAssignGroup(idx, { status: v })}
                                                >
                                                    <SelectTrigger className="w-full">
                                                        <SelectValue placeholder="No change" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="none">— No change —</SelectItem>
                                                        {['Occupied', 'Vacant', 'No PC', 'Admin'].map((s) => (
                                                            <SelectItem key={s} value={s}>{s}</SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>

                                            <div>
                                                <Label className="text-xs mb-1 block">Monitor Type</Label>
                                                <Select
                                                    value={group.monitor_type}
                                                    onValueChange={(v) => updateAssignGroup(idx, { monitor_type: v })}
                                                >
                                                    <SelectTrigger className="w-full">
                                                        <SelectValue placeholder="No change" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="none">— No change —</SelectItem>
                                                        <SelectItem value="single">Single</SelectItem>
                                                        <SelectItem value="dual">Dual</SelectItem>
                                                        <SelectItem value="none-monitor">No Monitor</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}

                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setAssignGroups(prev => [...prev, emptyGroup()])}
                                className="w-full"
                            >
                                <Plus className="mr-2 h-4 w-4" />
                                Add Group
                            </Button>
                        </div>

                        <div className="flex flex-col sm:flex-row gap-2 justify-end pt-2">
                            <Button
                                variant="outline"
                                onClick={() => setBulkAssignOpen(false)}
                                disabled={bulkAssignSubmitting}
                            >
                                Cancel
                            </Button>
                            <Button
                                onClick={handleSubmitBulkAssign}
                                disabled={bulkAssignSubmitting}
                            >
                                {bulkAssignSubmitting ? 'Applying...' : 'Apply Bulk Assignment'}
                            </Button>
                        </div>
                    </DialogContent>
                </Dialog>

                {/* Bulk Unassign Confirmation */}
                <Dialog open={bulkUnassignConfirmOpen} onOpenChange={setBulkUnassignConfirmOpen}>
                    <DialogContent className="max-w-[90vw] sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>Unassign {selectedStationIds.length} Station{selectedStationIds.length !== 1 ? 's' : ''}?</DialogTitle>
                            <DialogDescription>
                                This will clear the campaign and set status to <strong>Vacant</strong> for all selected stations.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="flex flex-col sm:flex-row gap-2 justify-end pt-2">
                            <Button variant="outline" onClick={() => setBulkUnassignConfirmOpen(false)} disabled={bulkUnassignSubmitting}>
                                Cancel
                            </Button>
                            <Button
                                variant="destructive"
                                onClick={handleSubmitBulkUnassign}
                                disabled={bulkUnassignSubmitting}
                            >
                                {bulkUnassignSubmitting ? 'Unassigning...' : 'Unassign'}
                            </Button>
                        </div>
                    </DialogContent>
                </Dialog>

                {/* Floating progress indicators for QR ZIP */}
                {bulkProgress.running && (
                    <Card className="fixed bottom-6 right-6 z-50 w-80 shadow-lg border-blue-400">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm flex items-center gap-2">
                                <Download className="h-4 w-4" />
                                Generating All QR Codes...
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <span className="text-sm text-muted-foreground">{bulkProgress.status}</span>
                        </CardContent>
                    </Card>
                )}
                {selectedZipProgress.running && (
                    <Card className={`fixed z-50 w-80 shadow-lg border-green-400 ${bulkProgress.running ? 'bottom-40' : 'bottom-6'} right-6`}>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm flex items-center gap-2">
                                <Download className="h-4 w-4" />
                                Generating Selected QR Codes...
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <span className="text-sm text-muted-foreground">{selectedZipProgress.status}</span>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
