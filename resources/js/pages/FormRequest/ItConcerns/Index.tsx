import React, { useEffect, useState } from "react";
import { Head, router, usePage } from "@inertiajs/react";
import AppLayout from "@/layouts/app-layout";
import { useFlashMessage, usePageLoading, usePageMeta } from "@/hooks";
import { usePermission } from "@/hooks/useAuthorization";
import type { SharedData } from "@/types";
import { PageHeader } from "@/components/PageHeader";
import { LoadingOverlay } from "@/components/LoadingOverlay";
import { Can } from "@/components/authorization";

import { Button } from "@/components/ui/button";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue
} from "@/components/ui/select";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import PaginationNav, { PaginationLink } from "@/components/pagination-nav";
import { Plus, Edit, Trash2, CheckCircle, Clock, XCircle, AlertCircle, RefreshCw, Search, Play, Pause } from "lucide-react";
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { Textarea } from "@/components/ui/textarea";
import { Label } from "@/components/ui/label";

interface User {
    id: number;
    first_name: string;
    last_name: string;
    name: string;
}

interface Site {
    id: number;
    name: string;
}

interface ItConcern {
    id: number;
    user?: User;
    site: Site;
    station_number: string;
    category: "Hardware" | "Software" | "Network/Connectivity" | "Other";
    description: string;
    status: "pending" | "in_progress" | "resolved" | "cancelled";
    priority: "low" | "medium" | "high" | "urgent";
    assigned_to?: User;
    resolved_by?: User;
    resolution_notes?: string;
    resolved_at?: string;
    created_at: string;
    updated_at: string;
}

interface ConcernPayload {
    data: ItConcern[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number;
    to: number;
}

interface PageProps extends SharedData {
    concerns: ConcernPayload;
    sites: Site[];
    filters?: {
        search?: string;
        site_id?: string;
        category?: string;
        status?: string;
        priority?: string;
    };
    [key: string]: unknown;
}

const getCategoryBadge = (category: string) => {
    const config: Record<string, { label: string; className: string }> = {
        Hardware: { label: "Hardware", className: "bg-blue-500" },
        Software: { label: "Software", className: "bg-purple-500" },
        "Network/Connectivity": { label: "Network/Connectivity", className: "bg-orange-500" },
        Other: { label: "Other", className: "bg-gray-500" },
    };

    const { label, className } = config[category] || { label: category, className: "bg-gray-500" };
    return <Badge className={className}>{label}</Badge>;
};

const getStatusBadge = (status: string) => {
    const config: Record<string, { label: string; className: string; icon: React.ReactNode }> = {
        pending: {
            label: "Pending",
            className: "bg-yellow-500",
            icon: <Clock className="mr-1 h-3 w-3" />,
        },
        in_progress: {
            label: "In Progress",
            className: "bg-blue-500",
            icon: <AlertCircle className="mr-1 h-3 w-3" />,
        },
        resolved: {
            label: "Resolved",
            className: "bg-green-500",
            icon: <CheckCircle className="mr-1 h-3 w-3" />,
        },
        cancelled: {
            label: "Cancelled",
            className: "bg-gray-500",
            icon: <XCircle className="mr-1 h-3 w-3" />,
        },
    };

    const { label, className, icon } = config[status] || {
        label: status,
        className: "bg-gray-500",
        icon: null,
    };

    return (
        <Badge className={className}>
            {icon}
            {label}
        </Badge>
    );
};

const getPriorityBadge = (priority: string) => {
    const config: Record<string, { label: string; className: string }> = {
        low: { label: "Low", className: "bg-gray-500" },
        medium: { label: "Medium", className: "bg-blue-500" },
        high: { label: "High", className: "bg-orange-500" },
        urgent: { label: "Urgent", className: "bg-red-500 animate-pulse" },
    };

    const { label, className } = config[priority] || { label: priority, className: "bg-gray-500" };
    return <Badge className={className}>{label}</Badge>;
};

export default function ItConcernsIndex() {
    const { concerns, sites, filters, auth } = usePage<PageProps>().props;
    const concernData = {
        data: concerns?.data ?? [],
        links: concerns?.links ?? [],
        meta: {
            current_page: concerns?.current_page ?? 1,
            last_page: concerns?.last_page ?? 1,
            per_page: concerns?.per_page ?? 25,
            total: concerns?.total ?? 0,
        },
    };
    const appliedFilters = filters ?? {};
    const sitesList = sites ?? [];

    const { title, breadcrumbs } = usePageMeta({
        title: "IT Concerns",
        breadcrumbs: [{ title: "IT Concerns", href: "/form-requests/it-concerns" }],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();
    const { can } = usePermission();

    const [loading, setLoading] = useState(false);
    const [search, setSearch] = useState(appliedFilters.search || "");
    const [debouncedSearch, setDebouncedSearch] = useState(appliedFilters.search || "");
    const [siteFilter, setSiteFilter] = useState(appliedFilters.site_id || "all");
    const [categoryFilter, setCategoryFilter] = useState(appliedFilters.category || "all");
    const [statusFilter, setStatusFilter] = useState(appliedFilters.status || "all");
    const [priorityFilter, setPriorityFilter] = useState(appliedFilters.priority || "all");
    const [lastRefresh, setLastRefresh] = useState<Date>(new Date());
    const [autoRefreshEnabled, setAutoRefreshEnabled] = useState(false);

    useEffect(() => {
        setSearch(appliedFilters.search || "");
        setDebouncedSearch(appliedFilters.search || "");
        setSiteFilter(appliedFilters.site_id || "all");
        setCategoryFilter(appliedFilters.category || "all");
        setStatusFilter(appliedFilters.status || "all");
        setPriorityFilter(appliedFilters.priority || "all");
    }, [
        appliedFilters.search,
        appliedFilters.site_id,
        appliedFilters.category,
        appliedFilters.status,
        appliedFilters.priority,
    ]);

    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [concernToDelete, setConcernToDelete] = useState<number | null>(null);
    const [resolveDialogOpen, setResolveDialogOpen] = useState(false);
    const [concernToResolve, setConcernToResolve] = useState<ItConcern | null>(null);
    const [resolutionNotes, setResolutionNotes] = useState("");
    const [resolveStatus, setResolveStatus] = useState("resolved");
    const [resolvePriority, setResolvePriority] = useState("medium");
    const [cancelDialogOpen, setCancelDialogOpen] = useState(false);
    const [concernToCancel, setConcernToCancel] = useState<ItConcern | null>(null);

    // Auto-refresh every 30 seconds to check for new concerns
    useEffect(() => {
        if (!autoRefreshEnabled) return;
        const interval = setInterval(() => {
            const params: Record<string, string> = {};
            if (debouncedSearch) params.search = debouncedSearch;
            if (siteFilter !== "all") params.site_id = siteFilter;
            if (categoryFilter !== "all") params.category = categoryFilter;
            if (statusFilter !== "all") params.status = statusFilter;
            if (priorityFilter !== "all") params.priority = priorityFilter;

            router.get("/form-requests/it-concerns", params, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ["concerns"],
                onSuccess: () => setLastRefresh(new Date()),
                onError: (errors) => {
                    console.error('Auto-refresh failed:', errors);
                },
            });
        }, 30000); // 30 seconds

        return () => clearInterval(interval);
    }, [autoRefreshEnabled, debouncedSearch, siteFilter, categoryFilter, statusFilter, priorityFilter]);

    useEffect(() => {
        const timer = setTimeout(() => setDebouncedSearch(search), 500);
        return () => clearTimeout(timer);
    }, [search]);

    const isInitialMount = React.useRef(true);

    useEffect(() => {
        if (isInitialMount.current) {
            isInitialMount.current = false;
            return;
        }

        const params: Record<string, string> = {};
        if (debouncedSearch) params.search = debouncedSearch;
        if (siteFilter !== "all") params.site_id = siteFilter;
        if (categoryFilter !== "all") params.category = categoryFilter;
        if (statusFilter !== "all") params.status = statusFilter;
        if (priorityFilter !== "all") params.priority = priorityFilter;

        setLoading(true);
        router.get("/form-requests/it-concerns", params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => setLoading(false),
        });
    }, [debouncedSearch, siteFilter, categoryFilter, statusFilter, priorityFilter]);

    const showClearFilters =
        siteFilter !== "all" ||
        categoryFilter !== "all" ||
        statusFilter !== "all" ||
        priorityFilter !== "all" ||
        Boolean(search);

    const clearFilters = () => {
        setSearch("");
        setSiteFilter("all");
        setCategoryFilter("all");
        setStatusFilter("all");
        setPriorityFilter("all");
    };

    const handleManualRefresh = () => {
        const params: Record<string, string> = {};
        if (debouncedSearch) params.search = debouncedSearch;
        if (siteFilter !== "all") params.site_id = siteFilter;
        if (categoryFilter !== "all") params.category = categoryFilter;
        if (statusFilter !== "all") params.status = statusFilter;
        if (priorityFilter !== "all") params.priority = priorityFilter;

        setLoading(true);
        router.get("/form-requests/it-concerns", params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ["concerns"],
            onSuccess: () => setLastRefresh(new Date()),
            onFinish: () => setLoading(false),
        });
    };

    const handleDelete = (concernId: number) => {
        setConcernToDelete(concernId);
        setDeleteDialogOpen(true);
    };

    const handleResolve = (concern: ItConcern) => {
        setConcernToResolve(concern);
        setResolutionNotes(concern.resolution_notes || "");
        setResolveStatus(concern.status);
        setResolvePriority(concern.priority);
        setResolveDialogOpen(true);
    };

    const confirmResolve = () => {
        if (concernToResolve) {
            router.post(`/form-requests/it-concerns/${concernToResolve.id}/resolve`, {
                resolution_notes: resolutionNotes,
                status: resolveStatus,
                priority: resolvePriority,
            }, {
                preserveScroll: true,
                onSuccess: () => {
                    setResolveDialogOpen(false);
                    setConcernToResolve(null);
                    setResolutionNotes("");
                    setResolveStatus("resolved");
                    setResolvePriority("medium");
                },
            });
        }
    };

    const confirmDelete = () => {
        if (concernToDelete) {
            router.delete(`/form-requests/it-concerns/${concernToDelete}`, {
                preserveScroll: true,
                onSuccess: () => {
                    setDeleteDialogOpen(false);
                    setConcernToDelete(null);
                },
            });
        }
    };

    const handleCancel = (concern: ItConcern) => {
        setConcernToCancel(concern);
        setCancelDialogOpen(true);
    };

    const confirmCancel = () => {
        if (concernToCancel) {
            router.post(`/form-requests/it-concerns/${concernToCancel.id}/cancel`, {}, {
                preserveScroll: true,
                onSuccess: () => {
                    setCancelDialogOpen(false);
                    setConcernToCancel(null);
                },
            });
        }
    };

    const formatDate = (dateString: string) => {
        return new Date(dateString).toLocaleDateString("en-US", {
            year: "numeric",
            month: "short",
            day: "numeric",
            hour: "2-digit",
            minute: "2-digit",
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                <LoadingOverlay isLoading={isPageLoading || loading} />

                <PageHeader
                    title="IT Concerns"
                    description="Submit and track IT issues reported by agents"
                />

                <div className="flex flex-col gap-4">
                    <div className="flex flex-col sm:flex-row gap-4 justify-between items-start sm:items-center">
                        <div className="w-full sm:w-auto flex-1 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                            <div className="relative">
                                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search..."
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    className="pl-8"
                                />
                            </div>

                            <Select value={siteFilter} onValueChange={setSiteFilter}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Site" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Sites</SelectItem>
                                    {sitesList.map((site) => (
                                        <SelectItem key={site.id} value={String(site.id)}>
                                            {site.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            <Select value={categoryFilter} onValueChange={setCategoryFilter}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Category" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Categories</SelectItem>
                                    <SelectItem value="Hardware">Hardware</SelectItem>
                                    <SelectItem value="Software">Software</SelectItem>
                                    <SelectItem value="Network/Connectivity">Network</SelectItem>
                                    <SelectItem value="Other">Other</SelectItem>
                                </SelectContent>
                            </Select>

                            <Select value={statusFilter} onValueChange={setStatusFilter}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Status</SelectItem>
                                    <SelectItem value="pending">Pending</SelectItem>
                                    <SelectItem value="in_progress">In Progress</SelectItem>
                                    <SelectItem value="resolved">Resolved</SelectItem>
                                    <SelectItem value="cancelled">Cancelled</SelectItem>
                                </SelectContent>
                            </Select>

                            <Select value={priorityFilter} onValueChange={setPriorityFilter}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Priority" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Priorities</SelectItem>
                                    <SelectItem value="low">Low</SelectItem>
                                    <SelectItem value="medium">Medium</SelectItem>
                                    <SelectItem value="high">High</SelectItem>
                                    <SelectItem value="urgent">Urgent</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="flex flex-wrap gap-2 w-full sm:w-auto">
                            <div className="flex gap-2">
                                <Button variant="ghost" size="icon" onClick={handleManualRefresh} disabled={loading} title="Refresh">
                                    <RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
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
                            {showClearFilters && (
                                <Button variant="outline" onClick={clearFilters} className="flex-1 sm:flex-none">
                                    Reset
                                </Button>
                            )}
                            <Can permission="it_concerns.create">
                                <Button onClick={() => router.get("/form-requests/it-concerns/create")} className="flex-1 sm:flex-none">
                                    <Plus className="mr-2 h-4 w-4" />
                                    Submit
                                </Button>
                            </Can>
                        </div>
                    </div>

                    <div className="flex justify-between items-center text-sm">
                        <div className="text-muted-foreground">
                            Showing {concernData.data.length} of {concernData.meta.total} concern
                            {concernData.meta.total === 1 ? "" : "s"}
                            {showClearFilters ? " (filtered)" : ""}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            Last updated: {lastRefresh.toLocaleTimeString()}
                        </div>
                    </div>
                </div>

                <div className="hidden md:block shadow rounded-md overflow-hidden">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Date</TableHead>
                                    <TableHead>Priority</TableHead>
                                    <TableHead>Submitted By</TableHead>
                                    <TableHead>Site</TableHead>
                                    <TableHead>Station</TableHead>
                                    <TableHead>Category</TableHead>
                                    <TableHead>Description</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Resolved By</TableHead>
                                    <TableHead>Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {concernData.data.map((concern) => (
                                    <TableRow key={concern.id}>
                                        <TableCell className="text-xs">{formatDate(concern.created_at)}</TableCell>
                                        <TableCell>{getPriorityBadge(concern.priority)}</TableCell>
                                        <TableCell className="font-medium">{concern.user?.name || 'N/A'}</TableCell>
                                        <TableCell>{concern.site.name}</TableCell>
                                        <TableCell>{concern.station_number}</TableCell>
                                        <TableCell>{getCategoryBadge(concern.category)}</TableCell>
                                        <TableCell className="max-w-xs truncate" title={concern.description}>
                                            {concern.description}
                                        </TableCell>
                                        <TableCell>{getStatusBadge(concern.status)}</TableCell>
                                        <TableCell className="text-sm">{concern.resolved_by?.name || '-'}</TableCell>
                                        <TableCell>
                                            <div className="flex gap-2">
                                                {/* Resolve Button - IT/Admin */}
                                                <Can permission="it_concerns.resolve">
                                                    {concern.status !== 'resolved' && (
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() => handleResolve(concern)}
                                                            title="Quick Resolve"
                                                        >
                                                            <CheckCircle className="h-4 w-4 text-green-500" />
                                                        </Button>
                                                    )}
                                                </Can>

                                                {/* Edit Button - Global permission OR Owner (pending/in_progress) */}
                                                {(can('it_concerns.edit') || (concern.user?.id === auth.user.id && ['pending', 'in_progress'].includes(concern.status))) && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => router.get(`/form-requests/it-concerns/${concern.id}/edit`)}
                                                        title="Edit"
                                                    >
                                                        <Edit className="h-4 w-4" />
                                                    </Button>
                                                )}

                                                {/* Cancel Button - Owner (pending/in_progress) */}
                                                {concern.user?.id === auth.user.id && ['pending', 'in_progress'].includes(concern.status) && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => handleCancel(concern)}
                                                        title="Cancel Request"
                                                    >
                                                        <XCircle className="h-4 w-4 text-orange-500" />
                                                    </Button>
                                                )}

                                                {/* Delete Button - Global permission OR Owner (pending) */}
                                                {(can('it_concerns.delete') || (concern.user?.id === auth.user.id && concern.status === 'pending')) && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => handleDelete(concern.id)}
                                                        title="Delete"
                                                    >
                                                        <Trash2 className="h-4 w-4 text-red-500" />
                                                    </Button>
                                                )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {concernData.data.length === 0 && !loading && (
                                    <TableRow>
                                        <TableCell colSpan={10} className="h-24 text-center text-muted-foreground">
                                            No IT concerns found
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </div>

                <div className="md:hidden space-y-4">
                    {concernData.data.map((concern) => (
                        <div key={concern.id} className="bg-card border rounded-lg p-4 shadow-sm space-y-3">
                            <div className="flex justify-between items-start">
                                <div>
                                    <div className="text-lg font-semibold">{concern.user?.name || 'Anonymous'}</div>
                                    <div className="text-sm text-muted-foreground">{concern.site.name}</div>
                                </div>
                                <div className="flex flex-col gap-1">
                                    {getPriorityBadge(concern.priority)}
                                    {getStatusBadge(concern.status)}
                                </div>
                            </div>

                            <div className="space-y-2 text-sm">
                                <div className="flex items-center gap-2">
                                    <span className="font-medium">Category:</span>
                                    {getCategoryBadge(concern.category)}
                                </div>
                                <div>
                                    <span className="font-medium">Station:</span> {concern.station_number}
                                </div>
                                <div>
                                    <span className="font-medium">Description:</span> {concern.description}
                                </div>
                                {concern.resolved_by && (
                                    <div>
                                        <span className="font-medium">Resolved By:</span> {concern.resolved_by.name}
                                    </div>
                                )}
                                <div className="text-xs text-muted-foreground">
                                    {formatDate(concern.created_at)}
                                </div>
                            </div>

                            <div className="flex gap-2 pt-2 border-t">
                                <Can permission="it_concerns.resolve">
                                    {concern.status !== 'resolved' && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => handleResolve(concern)}
                                        >
                                            <CheckCircle className="mr-2 h-4 w-4 text-green-500" />
                                            Resolve
                                        </Button>
                                    )}
                                </Can>

                                {(can('it_concerns.edit') || (concern.user?.id === auth.user.id && ['pending', 'in_progress'].includes(concern.status))) && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="flex-1"
                                        onClick={() => router.get(`/form-requests/it-concerns/${concern.id}/edit`)}
                                    >
                                        <Edit className="mr-2 h-4 w-4" />
                                        Edit
                                    </Button>
                                )}

                                {/* Cancel Button - Owner (pending/in_progress) */}
                                {concern.user?.id === auth.user.id && ['pending', 'in_progress'].includes(concern.status) && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => handleCancel(concern)}
                                    >
                                        <XCircle className="mr-2 h-4 w-4 text-orange-500" />
                                        Cancel
                                    </Button>
                                )}

                                {(can('it_concerns.delete') || (concern.user?.id === auth.user.id && concern.status === 'pending')) && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => handleDelete(concern.id)}
                                    >
                                        <Trash2 className="h-4 w-4 text-red-500" />
                                    </Button>
                                )}
                            </div>
                        </div>
                    ))}

                    {concernData.data.length === 0 && !loading && (
                        <div className="py-12 text-center text-gray-500 border rounded-lg bg-card">
                            No IT concerns found
                        </div>
                    )}
                </div>

                <div className="flex justify-center mt-4">
                    {concernData.links && concernData.links.length > 0 && (
                        <PaginationNav links={concernData.links} only={["concerns"]} />
                    )}
                </div>

                <AlertDialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Are you sure?</AlertDialogTitle>
                            <AlertDialogDescription>
                                This action cannot be undone. This will permanently delete the IT concern.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                            <AlertDialogAction onClick={confirmDelete}>Delete</AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>

                <AlertDialog open={resolveDialogOpen} onOpenChange={setResolveDialogOpen}>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Resolve IT Concern</AlertDialogTitle>
                            <AlertDialogDescription>
                                {concernToResolve && (
                                    <div className="space-y-3 mt-4">
                                        <div>
                                            <span className="font-medium">Station:</span> {concernToResolve.station_number}
                                        </div>
                                        <div>
                                            <span className="font-medium">Category:</span> {concernToResolve.category}
                                        </div>
                                        <div>
                                            <span className="font-medium">Description:</span> {concernToResolve.description}
                                        </div>

                                        <div className="grid grid-cols-2 gap-3 mt-4">
                                            <div className="space-y-2">
                                                <Label htmlFor="resolve_status" className="text-foreground">
                                                    Status <span className="text-red-500">*</span>
                                                </Label>
                                                <Select value={resolveStatus} onValueChange={setResolveStatus}>
                                                    <SelectTrigger id="resolve_status">
                                                        <SelectValue placeholder="Select status" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="pending">Pending</SelectItem>
                                                        <SelectItem value="in_progress">In Progress</SelectItem>
                                                        <SelectItem value="resolved">Resolved</SelectItem>
                                                        <SelectItem value="cancelled">Cancelled</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </div>

                                            <div className="space-y-2">
                                                <Label htmlFor="resolve_priority" className="text-foreground">
                                                    Priority <span className="text-red-500">*</span>
                                                </Label>
                                                <Select value={resolvePriority} onValueChange={setResolvePriority}>
                                                    <SelectTrigger id="resolve_priority">
                                                        <SelectValue placeholder="Select priority" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="low">Low</SelectItem>
                                                        <SelectItem value="medium">Medium</SelectItem>
                                                        <SelectItem value="high">High</SelectItem>
                                                        <SelectItem value="urgent">Urgent</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        </div>

                                        <div className="mt-4">
                                            <Label htmlFor="resolution_notes" className="text-foreground">
                                                Resolution Notes <span className="text-red-500">*</span>
                                            </Label>
                                            <Textarea
                                                id="resolution_notes"
                                                placeholder="Describe how this issue was resolved..."
                                                value={resolutionNotes}
                                                onChange={(e) => setResolutionNotes(e.target.value)}
                                                rows={4}
                                                className="mt-2"
                                            />
                                        </div>
                                    </div>
                                )}
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                            <AlertDialogAction
                                onClick={confirmResolve}
                                disabled={!resolutionNotes.trim()}
                            >
                                Update IT Concern
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>

                <AlertDialog open={cancelDialogOpen} onOpenChange={setCancelDialogOpen}>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Cancel IT Concern?</AlertDialogTitle>
                            <AlertDialogDescription>
                                {concernToCancel && (
                                    <div className="space-y-2 mt-2">
                                        <p>Are you sure you want to cancel this IT concern?</p>
                                        <div className="bg-muted p-3 rounded-md space-y-1 text-sm">
                                            <div><span className="font-medium">Station:</span> {concernToCancel.station_number}</div>
                                            <div><span className="font-medium">Category:</span> {concernToCancel.category}</div>
                                            <div><span className="font-medium">Description:</span> {concernToCancel.description}</div>
                                        </div>
                                        <p className="text-muted-foreground text-sm">This will mark the request as cancelled and IT will no longer work on it.</p>
                                    </div>
                                )}
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Keep Request</AlertDialogCancel>
                            <AlertDialogAction onClick={confirmCancel} className="bg-orange-500 hover:bg-orange-600">
                                Cancel Request
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </div>
        </AppLayout>
    );
}
