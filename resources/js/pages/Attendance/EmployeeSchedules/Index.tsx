import React, { useEffect, useState } from "react";
import { Head, router, usePage } from "@inertiajs/react";
import AppLayout from "@/layouts/app-layout";
import { useFlashMessage, usePageLoading, usePageMeta } from "@/hooks";
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
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from "@/components/ui/command";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { Check, ChevronsUpDown } from "lucide-react";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import PaginationNav, { PaginationLink } from "@/components/pagination-nav";
import { Plus, Edit, Trash2, CheckCircle, XCircle } from "lucide-react";
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

interface User {
    id: number;
    first_name: string;
    last_name: string;
    name: string;
}

interface Campaign {
    id: number;
    name: string;
}

interface Site {
    id: number;
    name: string;
}

interface Schedule {
    id: number;
    user: User;
    campaign?: Campaign;
    site?: Site;
    shift_type: string;
    scheduled_time_in: string;
    scheduled_time_out: string;
    work_days: string[];
    grace_period_minutes: number;
    is_active: boolean;
    effective_date: string;
    end_date?: string;
}

interface SchedulePayload {
    data: Schedule[];
    links: PaginationLink[];
    // Laravel pagination properties at root level
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number;
    to: number;
    first_page_url: string;
    last_page_url: string;
    next_page_url: string | null;
    prev_page_url: string | null;
    path: string;
}

interface PageProps extends SharedData {
    schedules: SchedulePayload;
    users: Array<{ id: number; name: string }>;
    sites: Array<{ id: number; name: string }>;
    campaigns: Array<{ id: number; name: string }>;
    filters?: {
        search?: string;
        user_id?: string;
        campaign_id?: string;
        site_id?: string;
        shift_type?: string;
        is_active?: string;
        active_only?: boolean;
    };
    [key: string]: unknown;
}

const formatTime = (time: string, timeFormat: '12' | '24' = '24') => {
    if (timeFormat === '24') {
        return time; // Return as-is in 24-hour format (HH:MM)
    }

    // Convert to 12-hour format
    const [hours, minutes] = time.split(':');
    const hour = parseInt(hours);
    const ampm = hour >= 12 ? 'PM' : 'AM';
    const hour12 = hour % 12 || 12;
    return `${hour12}:${minutes} ${ampm}`;
};

const getShiftTypeBadge = (shiftType: string) => {
    const config: Record<string, { label: string; className: string }> = {
        graveyard_shift: { label: "Graveyard Shift", className: "bg-indigo-500" },
        night_shift: { label: "Night Shift", className: "bg-blue-500" },
        morning_shift: { label: "Morning Shift", className: "bg-yellow-500" },
        afternoon_shift: { label: "Afternoon Shift", className: "bg-orange-500" },
        utility_24h: { label: "24H Utility", className: "bg-purple-500" },
    };

    const { label, className } = config[shiftType] || { label: shiftType, className: "bg-gray-500" };
    return <Badge className={className}>{label}</Badge>;
};

export default function EmployeeSchedulesIndex() {
    const { schedules, users, campaigns = [], filters, auth } = usePage<PageProps>().props;
    const timeFormat = (auth.user as { time_format?: '12' | '24' })?.time_format || '24';
    const scheduleData = {
        data: schedules?.data ?? [],
        links: schedules?.links ?? [],
        meta: {
            current_page: schedules?.current_page ?? 1,
            last_page: schedules?.last_page ?? 1,
            per_page: schedules?.per_page ?? 50,
            total: schedules?.total ?? 0,
        },
    };
    const appliedFilters = filters ?? {};

    const { title, breadcrumbs } = usePageMeta({
        title: "Employee Schedules",
        breadcrumbs: [{ title: "Employee Schedules", href: "/employee-schedules" }],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    const [loading, setLoading] = useState(false);
    const [search, setSearch] = useState(appliedFilters.search || "");
    const [debouncedSearch, setDebouncedSearch] = useState(appliedFilters.search || "");
    const [userFilter, setUserFilter] = useState(appliedFilters.user_id || "all");
    const [isUserPopoverOpen, setIsUserPopoverOpen] = useState(false);
    const [userSearchQuery, setUserSearchQuery] = useState("");
    const [campaignFilter, setCampaignFilter] = useState(appliedFilters.campaign_id || "all");
    const [statusFilter, setStatusFilter] = useState(appliedFilters.is_active || "all");
    const [activeOnly, setActiveOnly] = useState(appliedFilters.active_only || false);

    // Update local state when filters prop changes (e.g., when navigating back)
    useEffect(() => {
        setSearch(appliedFilters.search || "");
        setDebouncedSearch(appliedFilters.search || "");
        setUserFilter(appliedFilters.user_id || "all");
        setCampaignFilter(appliedFilters.campaign_id || "all");
        setStatusFilter(appliedFilters.is_active || "all");
        setActiveOnly(appliedFilters.active_only || false);
    }, [appliedFilters.search, appliedFilters.user_id, appliedFilters.campaign_id, appliedFilters.is_active, appliedFilters.active_only]);

    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [scheduleToDelete, setScheduleToDelete] = useState<number | null>(null);

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
        if (userFilter !== "all") params.user_id = userFilter;
        if (campaignFilter !== "all") params.campaign_id = campaignFilter;
        if (statusFilter !== "all") params.is_active = statusFilter;
        if (activeOnly) params.active_only = "1";

        setLoading(true);
        router.get("/employee-schedules", params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => setLoading(false),
        });
    }, [debouncedSearch, userFilter, campaignFilter, statusFilter, activeOnly]);

    const showClearFilters =
        userFilter !== "all" ||
        campaignFilter !== "all" ||
        statusFilter !== "all" ||
        activeOnly ||
        Boolean(search);

    const clearFilters = () => {
        setSearch("");
        setUserFilter("all");
        setCampaignFilter("all");
        setStatusFilter("all");
        setActiveOnly(false);
    };

    const handleToggleActive = (scheduleId: number) => {
        router.post(`/employee-schedules/${scheduleId}/toggle-active`, {}, {
            preserveScroll: true,
        });
    };

    const handleDelete = (scheduleId: number) => {
        setScheduleToDelete(scheduleId);
        setDeleteDialogOpen(true);
    };

    const confirmDelete = () => {
        if (scheduleToDelete) {
            router.delete(`/employee-schedules/${scheduleToDelete}`, {
                preserveScroll: true,
                onSuccess: () => {
                    setDeleteDialogOpen(false);
                    setScheduleToDelete(null);
                },
            });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                <LoadingOverlay isLoading={isPageLoading || loading} />

                <PageHeader
                    title="Employee Schedules"
                    description="Manage employee work schedules, shift times, and assignments"
                />

                <div className="flex flex-col gap-3">
                    <div className="w-full">
                        <Popover open={isUserPopoverOpen} onOpenChange={setIsUserPopoverOpen}>
                            <PopoverTrigger asChild>
                                <Button
                                    variant="outline"
                                    role="combobox"
                                    aria-expanded={isUserPopoverOpen}
                                    className="w-full justify-between font-normal"
                                >
                                    <span className="truncate">
                                        {userFilter !== "all"
                                            ? users.find(u => String(u.id) === userFilter)?.name || "Select employee..."
                                            : "All Employees"}
                                    </span>
                                    <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                </Button>
                            </PopoverTrigger>
                            <PopoverContent className="w-full p-0" align="start">
                                <Command shouldFilter={false}>
                                    <CommandInput
                                        placeholder="Search employee..."
                                        value={userSearchQuery}
                                        onValueChange={setUserSearchQuery}
                                    />
                                    <CommandList>
                                        <CommandEmpty>No employee found.</CommandEmpty>
                                        <CommandGroup>
                                            <CommandItem
                                                value="all"
                                                onSelect={() => {
                                                    setUserFilter("all");
                                                    setIsUserPopoverOpen(false);
                                                    setUserSearchQuery("");
                                                }}
                                                className="cursor-pointer"
                                            >
                                                <Check
                                                    className={`mr-2 h-4 w-4 ${userFilter === "all"
                                                        ? "opacity-100"
                                                        : "opacity-0"
                                                        }`}
                                                />
                                                All Employees
                                            </CommandItem>
                                            {users
                                                .filter(user =>
                                                    !userSearchQuery ||
                                                    user.name.toLowerCase().includes(userSearchQuery.toLowerCase())
                                                )
                                                .map((user) => (
                                                    <CommandItem
                                                        key={user.id}
                                                        value={user.name}
                                                        onSelect={() => {
                                                            setUserFilter(String(user.id));
                                                            setIsUserPopoverOpen(false);
                                                            setUserSearchQuery("");
                                                        }}
                                                        className="cursor-pointer"
                                                    >
                                                        <Check
                                                            className={`mr-2 h-4 w-4 ${userFilter === String(user.id)
                                                                ? "opacity-100"
                                                                : "opacity-0"
                                                                }`}
                                                        />
                                                        {user.name}
                                                    </CommandItem>
                                                ))}
                                        </CommandGroup>
                                    </CommandList>
                                </Command>
                            </PopoverContent>
                        </Popover>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">

                        <Select value={campaignFilter} onValueChange={setCampaignFilter}>
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Filter by Campaign" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Campaigns</SelectItem>
                                {campaigns.map(campaign => (
                                    <SelectItem key={campaign.id} value={String(campaign.id)}>
                                        {campaign.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select value={statusFilter} onValueChange={setStatusFilter}>
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Filter by Status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Status</SelectItem>
                                <SelectItem value="1">Active</SelectItem>
                                <SelectItem value="0">Inactive</SelectItem>
                            </SelectContent>
                        </Select>

                        <Button
                            variant={activeOnly ? "default" : "outline"}
                            onClick={() => setActiveOnly(!activeOnly)}
                            className="w-full"
                        >
                            <CheckCircle className="mr-2 h-4 w-4" />
                            Active Only
                        </Button>

                        {showClearFilters && (
                            <Button variant="outline" onClick={clearFilters} className="w-full">
                                Clear Filters
                            </Button>
                        )}
                    </div>

                    <div className="flex flex-col sm:flex-row gap-3">
                        <Can permission="schedules.create">
                            <Button onClick={() => router.get("/employee-schedules/create")}>
                                <Plus className="mr-2 h-4 w-4" />
                                Add Employee Schedule
                            </Button>
                        </Can>
                    </div>
                </div>

                <div className="flex justify-between items-center text-sm">
                    <div className="text-muted-foreground">
                        Showing {scheduleData.data.length} of {scheduleData.meta.total} schedule
                        {scheduleData.meta.total === 1 ? "" : "s"}
                        {showClearFilters ? " (filtered)" : ""}
                    </div>
                </div>

                <div className="hidden md:block shadow rounded-md overflow-hidden">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Employee</TableHead>
                                    <TableHead>Campaign</TableHead>
                                    <TableHead>Site</TableHead>
                                    <TableHead>Shift Type</TableHead>
                                    <TableHead>Time In</TableHead>
                                    <TableHead>Time Out</TableHead>
                                    <TableHead>Work Days</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {scheduleData.data.map(schedule => (
                                    <TableRow key={schedule.id}>
                                        <TableCell className="font-medium">{schedule.user.name}</TableCell>
                                        <TableCell>{schedule.campaign?.name || "-"}</TableCell>
                                        <TableCell>{schedule.site?.name || "-"}</TableCell>
                                        <TableCell>{getShiftTypeBadge(schedule.shift_type)}</TableCell>
                                        <TableCell>{formatTime(schedule.scheduled_time_in, timeFormat)}</TableCell>
                                        <TableCell>{formatTime(schedule.scheduled_time_out, timeFormat)}</TableCell>
                                        <TableCell className="text-xs">
                                            {schedule.work_days.slice(0, 3).map(day => day.substring(0, 3)).join(", ")}
                                            {schedule.work_days.length > 3 && ` +${schedule.work_days.length - 3}`}
                                        </TableCell>
                                        <TableCell>
                                            {schedule.is_active ? (
                                                <Badge className="bg-green-500">
                                                    <CheckCircle className="mr-1 h-3 w-3" />
                                                    Active
                                                </Badge>
                                            ) : (
                                                <Badge variant="secondary">
                                                    <XCircle className="mr-1 h-3 w-3" />
                                                    Inactive
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex gap-2">
                                                <Can permission="schedules.edit">
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => router.get(`/employee-schedules/${schedule.id}/edit`)}
                                                    >
                                                        <Edit className="h-4 w-4" />
                                                    </Button>
                                                </Can>
                                                <Can permission="schedules.toggle_active">
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => handleToggleActive(schedule.id)}
                                                    >
                                                        {schedule.is_active ? (
                                                            <XCircle className="h-4 w-4 text-orange-500" />
                                                        ) : (
                                                            <CheckCircle className="h-4 w-4 text-green-500" />
                                                        )}
                                                    </Button>
                                                </Can>
                                                <Can permission="schedules.delete">
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => handleDelete(schedule.id)}
                                                    >
                                                        <Trash2 className="h-4 w-4 text-red-500" />
                                                    </Button>
                                                </Can>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {scheduleData.data.length === 0 && !loading && (
                                    <TableRow>
                                        <TableCell colSpan={9} className="h-24 text-center text-muted-foreground">
                                            No employee schedules found
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </div>

                <div className="md:hidden space-y-4">
                    {scheduleData.data.map(schedule => (
                        <div key={schedule.id} className="bg-card border rounded-lg p-4 shadow-sm space-y-3">
                            <div className="flex justify-between items-start">
                                <div>
                                    <div className="text-lg font-semibold">{schedule.user.name}</div>
                                    <div className="text-sm text-muted-foreground">
                                        {schedule.campaign?.name || "No Campaign"}
                                    </div>
                                </div>
                                {schedule.is_active ? (
                                    <Badge className="bg-green-500">Active</Badge>
                                ) : (
                                    <Badge variant="secondary">Inactive</Badge>
                                )}
                            </div>

                            <div className="space-y-2 text-sm">
                                <div className="flex items-center gap-2">
                                    <span className="font-medium">Shift:</span>
                                    {getShiftTypeBadge(schedule.shift_type)}
                                </div>
                                <div>
                                    <span className="font-medium">Time:</span>{" "}
                                    {formatTime(schedule.scheduled_time_in, timeFormat)} - {formatTime(schedule.scheduled_time_out, timeFormat)}
                                </div>
                                <div>
                                    <span className="font-medium">Work Days:</span>{" "}
                                    {schedule.work_days.map(day => day.substring(0, 3)).join(", ")}
                                </div>
                                <div>
                                    <span className="font-medium">Site:</span> {schedule.site?.name || "-"}
                                </div>
                            </div>

                            <div className="flex gap-2 pt-2 border-t">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="flex-1"
                                    onClick={() => router.get(`/employee-schedules/${schedule.id}/edit`)}
                                >
                                    <Edit className="mr-2 h-4 w-4" />
                                    Edit
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => handleToggleActive(schedule.id)}
                                >
                                    {schedule.is_active ? <XCircle className="h-4 w-4" /> : <CheckCircle className="h-4 w-4" />}
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => handleDelete(schedule.id)}
                                >
                                    <Trash2 className="h-4 w-4 text-red-500" />
                                </Button>
                            </div>
                        </div>
                    ))}

                    {scheduleData.data.length === 0 && !loading && (
                        <div className="py-12 text-center text-gray-500 border rounded-lg bg-card">
                            No employee schedules found
                        </div>
                    )}
                </div>

                <div className="flex justify-center mt-4">
                    {scheduleData.links && scheduleData.links.length > 0 && (
                        <PaginationNav links={scheduleData.links} only={["schedules"]} />
                    )}
                </div>

                <AlertDialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Are you sure?</AlertDialogTitle>
                            <AlertDialogDescription>
                                This action cannot be undone. This will permanently delete the employee schedule.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                            <AlertDialogAction onClick={confirmDelete}>Delete</AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </div>
        </AppLayout>
    );
}
