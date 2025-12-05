import React, { useEffect, useState, useMemo } from "react";
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
import { Check, ChevronsUpDown, Users } from "lucide-react";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import { Switch } from "@/components/ui/switch";
import PaginationNav, { PaginationLink } from "@/components/pagination-nav";
import { Plus, Edit, Trash2, CheckCircle, RefreshCw, Search, Play, Pause } from "lucide-react";
import {
    index as employeeSchedulesIndex,
    create as employeeSchedulesCreate,
    edit as employeeSchedulesEdit,
    destroy as employeeSchedulesDestroy,
    toggleActive as employeeSchedulesToggleActive,
} from "@/routes/employee-schedules";
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

// Group schedules by user and count schedules per user
const groupSchedulesByUser = (schedules: Schedule[]) => {
    const userScheduleCount: Record<number, number> = {};
    const userGroupIndex: Record<number, number> = {};
    let groupCounter = 0;

    // First pass: count schedules per user
    schedules.forEach(schedule => {
        userScheduleCount[schedule.user.id] = (userScheduleCount[schedule.user.id] || 0) + 1;
    });

    // Second pass: assign group index to each user (for alternating colors)
    let lastUserId: number | null = null;
    schedules.forEach(schedule => {
        if (schedule.user.id !== lastUserId) {
            userGroupIndex[schedule.user.id] = groupCounter++;
            lastUserId = schedule.user.id;
        }
    });

    return { userScheduleCount, userGroupIndex };
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

    // Group schedules by user for visual grouping
    const { userScheduleCount, userGroupIndex } = useMemo(
        () => groupSchedulesByUser(scheduleData.data),
        [scheduleData.data]
    );

    const { title, breadcrumbs } = usePageMeta({
        title: "Employee Schedules",
        breadcrumbs: [{ title: "Employee Schedules", href: employeeSchedulesIndex().url }],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    const [loading, setLoading] = useState(false);
    const [search, setSearch] = useState(appliedFilters.search || "");
    const [userFilter, setUserFilter] = useState(appliedFilters.user_id || "all");
    const [isUserPopoverOpen, setIsUserPopoverOpen] = useState(false);
    const [userSearchQuery, setUserSearchQuery] = useState("");
    const [campaignFilter, setCampaignFilter] = useState(appliedFilters.campaign_id || "all");
    const [statusFilter, setStatusFilter] = useState(appliedFilters.is_active || "all");
    const [activeOnly, setActiveOnly] = useState(appliedFilters.active_only || false);
    const [lastRefresh, setLastRefresh] = useState<Date>(new Date());
    const [autoRefreshEnabled, setAutoRefreshEnabled] = useState(false);

    // Update local state when filters prop changes (e.g., when navigating back)
    useEffect(() => {
        setSearch(appliedFilters.search || "");
        setUserFilter(appliedFilters.user_id || "all");
        setCampaignFilter(appliedFilters.campaign_id || "all");
        setStatusFilter(appliedFilters.is_active || "all");
        setActiveOnly(appliedFilters.active_only || false);
    }, [appliedFilters.search, appliedFilters.user_id, appliedFilters.campaign_id, appliedFilters.is_active, appliedFilters.active_only]);

    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [scheduleToDelete, setScheduleToDelete] = useState<number | null>(null);
    const [toggleDialogOpen, setToggleDialogOpen] = useState(false);
    const [scheduleToToggle, setScheduleToToggle] = useState<Schedule | null>(null);

    const handleSearch = () => {
        const params: Record<string, string> = {};
        if (search) params.search = search;
        if (userFilter !== "all") params.user_id = userFilter;
        if (campaignFilter !== "all") params.campaign_id = campaignFilter;
        if (statusFilter !== "all") params.is_active = statusFilter;
        if (activeOnly) params.active_only = "1";

        setLoading(true);
        router.get(employeeSchedulesIndex().url, params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: () => setLastRefresh(new Date()),
            onFinish: () => setLoading(false),
        });
    };

    const handleManualRefresh = () => {
        handleSearch();
    };

    // Auto-refresh every 30 seconds
    useEffect(() => {
        if (!autoRefreshEnabled) return;
        const interval = setInterval(() => {
            const params: Record<string, string> = {};
            if (search) params.search = search;
            if (userFilter !== "all") params.user_id = userFilter;
            if (campaignFilter !== "all") params.campaign_id = campaignFilter;
            if (statusFilter !== "all") params.is_active = statusFilter;
            if (activeOnly) params.active_only = "1";

            router.get(employeeSchedulesIndex().url, params, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['schedules'],
                onSuccess: () => setLastRefresh(new Date()),
            });
        }, 30000);

        return () => clearInterval(interval);
    }, [autoRefreshEnabled, search, userFilter, campaignFilter, statusFilter, activeOnly]);

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

        // Trigger reload with cleared filters
        setLoading(true);
        router.get(employeeSchedulesIndex().url, {}, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: () => setLastRefresh(new Date()),
            onFinish: () => setLoading(false),
        });
    };

    const handleToggleActive = (schedule: Schedule) => {
        // If activating, show confirmation dialog (will deactivate other schedules)
        if (!schedule.is_active) {
            setScheduleToToggle(schedule);
            setToggleDialogOpen(true);
        } else {
            // Deactivating - no confirmation needed
            confirmToggleActive(schedule.id);
        }
    };

    const confirmToggleActive = (scheduleId: number) => {
        router.post(employeeSchedulesToggleActive({ employeeSchedule: scheduleId }).url, {}, {
            preserveScroll: true,
            onSuccess: () => {
                setToggleDialogOpen(false);
                setScheduleToToggle(null);
            },
        });
    };

    const handleDelete = (scheduleId: number) => {
        setScheduleToDelete(scheduleId);
        setDeleteDialogOpen(true);
    };

    const confirmDelete = () => {
        if (scheduleToDelete) {
            router.delete(employeeSchedulesDestroy({ employee_schedule: scheduleToDelete }).url, {
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
                    <div className="grid grid-cols-1 gap-3 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)]">
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
                    </div>

                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <Can permission="schedules.create">
                            <Button onClick={() => router.get(employeeSchedulesCreate().url)} className="w-full sm:w-auto">
                                <Plus className="mr-2 h-4 w-4" />
                                Add Employee Schedule
                            </Button>
                        </Can>

                        <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-end sm:flex-1">
                            <Button variant="default" onClick={handleSearch} className="w-full sm:w-auto">
                                <Search className="mr-2 h-4 w-4" />
                                Apply Filters
                            </Button>

                            {showClearFilters && (
                                <Button variant="outline" onClick={clearFilters} className="w-full sm:w-auto">
                                    Clear Filters
                                </Button>
                            )}

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
                        </div>
                    </div>
                </div>

                <div className="flex flex-col gap-2 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        Showing {scheduleData.data.length} of {scheduleData.meta.total} schedule
                        {scheduleData.meta.total === 1 ? "" : "s"}
                        {showClearFilters ? " (filtered)" : ""}
                    </div>
                    <div className="text-xs">
                        Last updated: {lastRefresh.toLocaleTimeString()}
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
                                {scheduleData.data.map(schedule => {
                                    const scheduleCount = userScheduleCount[schedule.user.id] || 1;
                                    const groupIndex = userGroupIndex[schedule.user.id] || 0;
                                    const hasMultipleSchedules = scheduleCount > 1;
                                    const isEvenGroup = groupIndex % 2 === 0;

                                    return (
                                        <TableRow
                                            key={schedule.id}
                                            className={hasMultipleSchedules ? (isEvenGroup ? "bg-blue-50/50 dark:bg-blue-950/20" : "bg-amber-50/50 dark:bg-amber-950/20") : ""}
                                        >
                                            <TableCell className="font-medium">
                                                <div className="flex items-center gap-2">
                                                    {schedule.user.name}
                                                    {hasMultipleSchedules && (
                                                        <Badge variant="outline" className="text-xs px-1.5 py-0 h-5 gap-1">
                                                            <Users className="h-3 w-3" />
                                                            {scheduleCount}
                                                        </Badge>
                                                    )}
                                                </div>
                                            </TableCell>
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
                                                <div className="flex items-center gap-2">
                                                    <Can permission="schedules.toggle">
                                                        <Switch
                                                            checked={schedule.is_active}
                                                            onCheckedChange={() => handleToggleActive(schedule)}
                                                            aria-label="Toggle schedule active status"
                                                        />
                                                    </Can>
                                                    {schedule.is_active ? (
                                                        <Badge className="bg-green-500">
                                                            Active
                                                        </Badge>
                                                    ) : (
                                                        <Badge variant="secondary">
                                                            Inactive
                                                        </Badge>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex gap-2">
                                                    <Can permission="schedules.edit">
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() => router.get(employeeSchedulesEdit({ employee_schedule: schedule.id }).url)}
                                                        >
                                                            <Edit className="h-4 w-4" />
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
                                    );
                                })}
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
                    {scheduleData.data.map(schedule => {
                        const scheduleCount = userScheduleCount[schedule.user.id] || 1;
                        const groupIndex = userGroupIndex[schedule.user.id] || 0;
                        const hasMultipleSchedules = scheduleCount > 1;
                        const isEvenGroup = groupIndex % 2 === 0;

                        return (
                            <div
                                key={schedule.id}
                                className={`border rounded-lg p-4 shadow-sm space-y-3 ${hasMultipleSchedules
                                    ? isEvenGroup
                                        ? "bg-blue-50 dark:bg-blue-950/30 border-blue-200 dark:border-blue-800"
                                        : "bg-amber-50 dark:bg-amber-950/30 border-amber-200 dark:border-amber-800"
                                    : "bg-card"
                                    }`}
                            >
                                <div className="flex justify-between items-start">
                                    <div>
                                        <div className="flex items-center gap-2">
                                            <span className="text-lg font-semibold">{schedule.user.name}</span>
                                            {hasMultipleSchedules && (
                                                <Badge variant="outline" className="text-xs px-1.5 py-0 h-5 gap-1">
                                                    <Users className="h-3 w-3" />
                                                    {scheduleCount}
                                                </Badge>
                                            )}
                                        </div>
                                        <div className="text-sm text-muted-foreground">
                                            {schedule.campaign?.name || "No Campaign"}
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Can permission="schedules.toggle">
                                            <Switch
                                                checked={schedule.is_active}
                                                onCheckedChange={() => handleToggleActive(schedule)}
                                                aria-label="Toggle schedule active status"
                                            />
                                        </Can>
                                        {schedule.is_active ? (
                                            <Badge className="bg-green-500">Active</Badge>
                                        ) : (
                                            <Badge variant="secondary">Inactive</Badge>
                                        )}
                                    </div>
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
                                    <Can permission="schedules.edit">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="flex-1"
                                            onClick={() => router.get(employeeSchedulesEdit({ employee_schedule: schedule.id }).url)}
                                        >
                                            <Edit className="mr-2 h-4 w-4" />
                                            Edit
                                        </Button>
                                    </Can>
                                    <Can permission="schedules.delete">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => handleDelete(schedule.id)}
                                        >
                                            <Trash2 className="h-4 w-4 text-red-500" />
                                        </Button>
                                    </Can>
                                </div>
                            </div>
                        );
                    })}

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

                <AlertDialog open={toggleDialogOpen} onOpenChange={setToggleDialogOpen}>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Activate Schedule?</AlertDialogTitle>
                            <AlertDialogDescription>
                                {scheduleToToggle && (
                                    <>
                                        Are you sure you want to activate this schedule for <strong>{scheduleToToggle.user.name}</strong>?
                                        <br /><br />
                                        <span className="text-amber-600 dark:text-amber-400">
                                            Note: This will automatically deactivate any other active schedules for this employee.
                                        </span>
                                    </>
                                )}
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                            <AlertDialogAction onClick={() => scheduleToToggle && confirmToggleActive(scheduleToToggle.id)}>
                                Activate
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>

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
