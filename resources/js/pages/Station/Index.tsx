import React, { useEffect, useState } from "react";
import { router, usePage } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription
} from "@/components/ui/dialog";
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from "@/components/ui/alert-dialog";
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from "@/components/ui/select";
import AppLayout from "@/layouts/app-layout";
import { Input } from "@/components/ui/input";
import PaginationNav, { PaginationLink } from "@/components/pagination-nav";
import { toast } from "sonner";
import { Eye } from "lucide-react";

const breadcrumbs = [{ title: "Stations", href: "/stations" }];

interface Station {
    id: number;
    site: string;
    station_number: string;
    campaign: string;
    status: string;
    pc_spec: string;
    pc_spec_details?: {
        id: number;
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
    const { stations, flash, filters } = usePage<{ stations: StationsPayload; flash?: Flash; filters: Filters }>().props;
    const [loading, setLoading] = useState(false);
    const [search, setSearch] = useState("");
    const [debouncedSearch, setDebouncedSearch] = useState("");
    const [siteFilter, setSiteFilter] = useState("all");
    const [campaignFilter, setCampaignFilter] = useState("all");
    const [statusFilter, setStatusFilter] = useState("all");
    const [pcSpecDialogOpen, setPcSpecDialogOpen] = useState(false);
    const [selectedPcSpec, setSelectedPcSpec] = useState<Station['pc_spec_details'] | null>(null);

    useEffect(() => {
        if (flash?.message) {
            if (flash.type === "error") toast.error(flash.message);
            else toast.success(flash.message);
        }
    }, [flash?.message, flash?.type]);

    useEffect(() => {
        const timer = setTimeout(() => setDebouncedSearch(search), 500);
        return () => clearTimeout(timer);
    }, [search]);

    useEffect(() => {
        const params: Record<string, string> = {};
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


    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-3">
                <div className="flex items-center gap-3 mb-4">
                    <h2 className="text-xl font-semibold">Station Management</h2>
                    <div className="ml-auto flex items-center gap-2">
                        <Button onClick={() => router.get('/stations/create')}>
                            Add Station
                        </Button>
                        <Button onClick={() => router.get('/sites')}>
                            Site Management
                        </Button>
                        <Button onClick={() => router.get('/campaigns')}>
                            Campaign Management
                        </Button>
                    </div>
                </div>

                {/* Filters */}
                <div className="flex items-center gap-3 mb-2">
                    <Input
                        type="search"
                        placeholder="Search site, station #, campaign..."
                        value={search}
                        onChange={e => setSearch(e.target.value)}
                        className="w-64"
                    />
                    <Select value={siteFilter} onValueChange={setSiteFilter}>
                        <SelectTrigger className="w-48">
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
                        <SelectTrigger className="w-48">
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
                        <SelectTrigger className="w-48">
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
                        >
                            Clear Filters
                        </Button>
                    )}
                </div>

                {/* Results Count */}
                <div className="text-sm text-muted-foreground mb-2">
                    Showing {stations.data.length} of {stations.meta.total} station{stations.meta.total !== 1 ? 's' : ''}
                    {(siteFilter !== "all" || campaignFilter !== "all" || statusFilter !== "all" || search) && ' (filtered)'}
                </div>

                <div className="shadow rounded-md overflow-hidden">
                    <div className="overflow-x-auto ">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>ID</TableHead>
                                    <TableHead>Site</TableHead>
                                    <TableHead>Station #</TableHead>
                                    <TableHead>Campaign</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>PC Spec</TableHead>
                                    <TableHead>Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {stations.data.map((station) => (
                                    <TableRow key={station.id}>
                                        <TableCell>{station.id}</TableCell>
                                        <TableCell>{station.site}</TableCell>
                                        <TableCell>{station.station_number}</TableCell>
                                        <TableCell>{station.campaign}</TableCell>
                                        <TableCell>{station.status}</TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-2">
                                                <span>{station.pc_spec}</span>
                                                {station.pc_spec_details && (
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
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-2">
                                                <Button variant="outline" size="sm" onClick={() => router.get(`/stations/${station.id}/edit`)} disabled={loading}>
                                                    Edit
                                                </Button>
                                                <AlertDialog>
                                                    <AlertDialogTrigger asChild>
                                                        <Button variant="destructive" size="sm" disabled={loading}>
                                                            Delete
                                                        </Button>
                                                    </AlertDialogTrigger>
                                                    <AlertDialogContent>
                                                        <AlertDialogHeader>
                                                            <AlertDialogTitle>Confirm Deletion</AlertDialogTitle>
                                                            <AlertDialogDescription>
                                                                Are you sure you want to delete station{" "}
                                                                <strong>"{station.station_number}"</strong>?
                                                                This action cannot be undone.
                                                            </AlertDialogDescription>
                                                        </AlertDialogHeader>
                                                        <AlertDialogFooter>
                                                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                            <AlertDialogAction
                                                                onClick={() => handleDelete(station.id)}
                                                                className="bg-red-600 hover:bg-red-700"
                                                            >
                                                                Yes, Delete
                                                            </AlertDialogAction>
                                                        </AlertDialogFooter>
                                                    </AlertDialogContent>
                                                </AlertDialog>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {stations.data.length === 0 && !loading && (
                                    <TableRow>
                                        <TableCell colSpan={7} className="py-8 text-center text-gray-500">
                                            No stations found
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </div>
                <div className="flex justify-center mt-4">
                    {stations.links && stations.links.length > 0 && (
                        <PaginationNav links={stations.links} />
                    )}
                </div>

                {/* PC Spec Details Dialog */}
                <Dialog open={pcSpecDialogOpen} onOpenChange={setPcSpecDialogOpen}>
                    <DialogContent className="max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>PC Specification Details</DialogTitle>
                            <DialogDescription>
                                {selectedPcSpec ? (
                                    <div className="space-y-4 text-left mt-4">
                                        <div className="space-y-3">
                                            <div>
                                                <div className="font-semibold text-foreground mb-1">Model:</div>
                                                <div className="text-foreground pl-2">{selectedPcSpec.model}</div>
                                            </div>

                                            <div>
                                                <div className="font-semibold text-foreground mb-1">Processor:</div>
                                                <div className="text-foreground pl-2">{selectedPcSpec.processor}</div>
                                            </div>

                                            <div>
                                                <div className="font-semibold text-foreground mb-1">RAM ({selectedPcSpec.ram_ddr}):</div>
                                                <div className="text-foreground pl-2">
                                                    {selectedPcSpec.ram} ({selectedPcSpec.ram_capacities})
                                                </div>
                                            </div>

                                            <div>
                                                <div className="font-semibold text-foreground mb-1">Disk ({selectedPcSpec.disk_type}):</div>
                                                <div className="text-foreground pl-2">
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
            </div>
        </AppLayout>
    );
}
