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
    const { stations, filters } = usePage<{ stations: StationsPayload; flash?: Flash; filters: Filters }>().props;

    // Use new hooks for cleaner code
    const { title, breadcrumbs } = usePageMeta({
        title: "Stations",
        breadcrumbs: [{ title: "Stations", href: stationsIndex().url }]
    });

    useFlashMessage(); // Automatically handles flash messages
    const isPageLoading = usePageLoading(); // Track page loading state

    const [loading, setLoading] = useState(false);
    const [search, setSearch] = useState("");
    const [debouncedSearch, setDebouncedSearch] = useState("");
    const [siteFilter, setSiteFilter] = useState("all");
    const [campaignFilter, setCampaignFilter] = useState("all");
    const [statusFilter, setStatusFilter] = useState("all");
    const [pcSpecDialogOpen, setPcSpecDialogOpen] = useState(false);
    const [selectedPcSpec, setSelectedPcSpec] = useState<Station['pc_spec_details'] | null>(null);
    const [issueDialogOpen, setIssueDialogOpen] = useState(false);
    const [issueText, setIssueText] = useState("");
    const [selectedEmptyStations, setSelectedEmptyStations] = useState<number[]>([]);

    useEffect(() => {
        const timer = setTimeout(() => setDebouncedSearch(search), 500);
        return () => clearTimeout(timer);
    }, [search]);

    useEffect(() => {
        const params: Record<string, string | number> = {};
        if (debouncedSearch) params.search = debouncedSearch;
        if (siteFilter && siteFilter !== "all") params.site = siteFilter;
        if (campaignFilter && campaignFilter !== "all") params.campaign = campaignFilter;
        if (statusFilter && statusFilter !== "all") params.status = statusFilter;

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
                    <div className="flex flex-col sm:flex-row gap-3 sm:justify-between">
                        {/* Bulk Selection Controls */}
                        {selectedEmptyStations.length > 0 && (
                            <div className="flex flex-col sm:flex-row items-start sm:items-center gap-2">
                                <span className="text-sm font-medium">
                                    {selectedEmptyStations.length} empty station{selectedEmptyStations.length > 1 ? 's' : ''} selected
                                </span>
                                <div className="flex gap-2 w-full sm:w-auto">
                                    <Button
                                        onClick={handleBulkAssign}
                                        className="flex items-center gap-2 flex-1 sm:flex-initial"
                                        size="sm"
                                    >
                                        <CheckSquare className="h-4 w-4" />
                                        Assign PCs to Selected
                                    </Button>
                                    <Button
                                        variant="outline"
                                        onClick={clearSelection}
                                        size="sm"
                                        className="flex-1 sm:flex-initial"
                                    >
                                        Clear
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
                                        {/* Checkbox column for bulk selection */}
                                    </TableHead>
                                    <TableHead className="hidden lg:table-cell">ID</TableHead>
                                    <TableHead>Site</TableHead>
                                    <TableHead>Station #</TableHead>
                                    <TableHead>Campaign</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="hidden xl:table-cell">Monitor</TableHead>
                                    <TableHead>PC Spec</TableHead>
                                    <TableHead className="hidden xl:table-cell">PC Issue</TableHead>
                                    <TableHead>Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {stations.data.map((station) => {
                                    const hasPC = !!station.pc_spec_details;
                                    const isSelected = selectedEmptyStations.includes(station.id);

                                    return (
                                        <TableRow
                                            key={station.id}
                                            className={isSelected ? 'bg-blue-50 dark:bg-blue-900/20' : ''}
                                        >
                                            <TableCell>
                                                {!hasPC && (
                                                    <Checkbox
                                                        checked={isSelected}
                                                        onCheckedChange={() => toggleStationSelection(station.id, hasPC)}
                                                        aria-label={`Select station ${station.station_number}`}
                                                    />
                                                )}
                                            </TableCell>
                                            <TableCell className="hidden lg:table-cell">{station.id}</TableCell>
                                            <TableCell>{station.site}</TableCell>
                                            <TableCell>{station.station_number}</TableCell>
                                            <TableCell>{station.campaign}</TableCell>
                                            <TableCell>{station.status}</TableCell>
                                            <TableCell className="hidden xl:table-cell">
                                                <span className={station.monitor_type === 'dual' ? 'text-blue-600 font-medium' : ''}>
                                                    {station.monitor_type === 'dual' ? 'Dual' : 'Single'}
                                                </span>
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
                                        <TableCell colSpan={10} className="py-8 text-center text-gray-500">
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
                                        <div className="font-medium">{station.status}</div>
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
                                        <span className="text-muted-foreground">Monitor:</span>
                                        <span className={station.monitor_type === 'dual' ? 'text-blue-600 font-medium' : 'font-medium'}>
                                            {station.monitor_type === 'dual' ? 'Dual' : 'Single'}
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
                        <PaginationNav links={stations.links} />
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
