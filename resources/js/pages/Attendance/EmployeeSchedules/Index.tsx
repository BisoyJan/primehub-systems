import React, { useEffect, useState, useMemo } from "react";
import { Head, router, usePage } from "@inertiajs/react";
import AppLayout from "@/layouts/app-layout";
import { useFlashMessage, usePageLoading, usePageMeta } from "@/hooks";
import type { SharedData } from "@/types";
import { PageHeader } from "@/components/PageHeader";
import { LoadingOverlay } from "@/components/LoadingOverlay";
import { Can } from "@/components/authorization";
import { formatTime, formatDate } from "@/lib/utils";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
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
    roles: string[];
    usersWithoutSchedules: Array<{ id: number; first_name: string; last_name: string }>;
    usersWithInactiveSchedules: Array<{ id: number; first_name: string; last_name: string }>;
    usersWithMultipleSchedules: Array<{ id: number; first_name: string; last_name: string; schedule_count: number }>;
    teamLeadCampaignId?: number;
    filters?: {
        search?: string;
        user_id?: string;
        role?: string;
        campaign_id?: string;
        site_id?: string;
        shift_type?: string;
        is_active?: string;
        active_only?: boolean;
        show_resigned?: boolean;
    };
    [key: string]: unknown;
}

// formatTime, formatDate are now imported from @/lib/utils

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
    const { schedules, users, campaigns = [], roles = [], filters, usersWithoutSchedules = [], usersWithInactiveSchedules = [], usersWithMultipleSchedules = [], teamLeadCampaignId } = usePage<PageProps>().props;
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
    const [roleFilter, setRoleFilter] = useState(appliedFilters.role || "all");
    const [campaignFilter, setCampaignFilter] = useState(() => {
        if (appliedFilters.campaign_id) return appliedFilters.campaign_id;
        if (teamLeadCampaignId) return teamLeadCampaignId.toString();
        return "all";
    });
    const [statusFilter, setStatusFilter] = useState(appliedFilters.is_active || "all");
    const [activeOnly, setActiveOnly] = useState(appliedFilters.active_only || false);
    const [showResigned, setShowResigned] = useState(appliedFilters.show_resigned || false);
    const [lastRefresh, setLastRefresh] = useState<Date>(new Date());
    const [autoRefreshEnabled, setAutoRefreshEnabled] = useState(false);

    // Update local state when filters prop changes (e.g., when navigating back)
    useEffect(() => {
        setSearch(appliedFilters.search || "");
        setUserFilter(appliedFilters.user_id || "all");
        setRoleFilter(appliedFilters.role || "all");
        setCampaignFilter(appliedFilters.campaign_id || "all");
        setStatusFilter(appliedFilters.is_active || "all");
        setActiveOnly(appliedFilters.active_only || false);
        setShowResigned(appliedFilters.show_resigned || false);
    }, [appliedFilters.search, appliedFilters.user_id, appliedFilters.role, appliedFilters.campaign_id, appliedFilters.is_active, appliedFilters.active_only, appliedFilters.show_resigned]);

    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [scheduleToDelete, setScheduleToDelete] = useState<number | null>(null);
    const [toggleDialogOpen, setToggleDialogOpen] = useState(false);
    const [scheduleToToggle, setScheduleToToggle] = useState<Schedule | null>(null);
    const [noScheduleDialogOpen, setNoScheduleDialogOpen] = useState(false);
    const [noScheduleSearch, setNoScheduleSearch] = useState("");
    const [scheduleDialogTab, setScheduleDialogTab] = useState<'no-schedule' | 'inactive' | 'multiple'>('no-schedule');
    const [scheduleDetailsDialogOpen, setScheduleDetailsDialogOpen] = useState(false);
    const [selectedUserSchedules, setSelectedUserSchedules] = useState<Schedule[]>([]);

    const handleSearch = () => {
        const params: Record<string, string> = {};
        if (search) params.search = search;
        if (userFilter !== "all") params.user_id = userFilter;
        if (roleFilter !== "all") params.role = roleFilter;
        if (campaignFilter !== "all") params.campaign_id = campaignFilter;
        if (statusFilter !== "all") params.is_active = statusFilter;
        if (activeOnly) params.active_only = "1";
        if (showResigned) params.show_resigned = "1";

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
            if (roleFilter !== "all") params.role = roleFilter;
            if (campaignFilter !== "all") params.campaign_id = campaignFilter;
            if (statusFilter !== "all") params.is_active = statusFilter;
            if (activeOnly) params.active_only = "1";
            if (showResigned) params.show_resigned = "1";

            router.get(employeeSchedulesIndex().url, params, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['schedules'],
                onSuccess: () => setLastRefresh(new Date()),
            });
        }, 30000);

        return () => clearInterval(interval);
    }, [autoRefreshEnabled, search, userFilter, roleFilter, campaignFilter, statusFilter, activeOnly]);

    const showClearFilters =
        userFilter !== "all" ||
        roleFilter !== "all" ||
        campaignFilter !== "all" ||
        statusFilter !== "all" ||
        activeOnly ||
        showResigned ||
        Boolean(search);

    const clearFilters = () => {
        setSearch("");
        setUserFilter("all");
        setRoleFilter("all");
        setCampaignFilter("all");
        setStatusFilter("all");
        setActiveOnly(false);
        setShowResigned(false);

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

    // Get employees without schedules from backend and format consistently
    const employeesWithoutSchedules = useMemo(() => {
        return usersWithoutSchedules.map(user => ({
            id: user.id,
            name: `${user.first_name} ${user.last_name}`
        }));
    }, [usersWithoutSchedules]);

    // Get employees with inactive schedules
    const employeesWithInactiveSchedules = useMemo(() => {
        return usersWithInactiveSchedules.map(user => ({
            id: user.id,
            name: `${user.first_name} ${user.last_name}`
        }));
    }, [usersWithInactiveSchedules]);

    // Filter employees without schedules based on search
    const filteredEmployeesWithoutSchedules = useMemo(() => {
        if (!noScheduleSearch) return employeesWithoutSchedules;
        const query = noScheduleSearch.toLowerCase();
        return employeesWithoutSchedules.filter(user =>
            user.name.toLowerCase().includes(query)
        );
    }, [employeesWithoutSchedules, noScheduleSearch]);

    // Filter employees with inactive schedules based on search
    const filteredEmployeesWithInactiveSchedules = useMemo(() => {
        if (!noScheduleSearch) return employeesWithInactiveSchedules;
        const query = noScheduleSearch.toLowerCase();
        return employeesWithInactiveSchedules.filter(user =>
            user.name.toLowerCase().includes(query)
        );
    }, [employeesWithInactiveSchedules, noScheduleSearch]);

    // Get employees with multiple schedules
    const employeesWithMultipleSchedules = useMemo(() => {
        return usersWithMultipleSchedules.map(user => ({
            id: user.id,
            name: `${user.first_name} ${user.last_name}`,
            schedule_count: user.schedule_count
        }));
    }, [usersWithMultipleSchedules]);

    // Filter employees with multiple schedules based on search
    const filteredEmployeesWithMultipleSchedules = useMemo(() => {
        if (!noScheduleSearch) return employeesWithMultipleSchedules;
        const query = noScheduleSearch.toLowerCase();
        return employeesWithMultipleSchedules.filter(user =>
            user.name.toLowerCase().includes(query)
        );
    }, [employeesWithMultipleSchedules, noScheduleSearch]);

    const handleViewEmployeeSchedules = async (userId: number) => {
        // Check if user has schedules in current page data
        const userSchedules = scheduleData.data.filter(s => s.user.id === userId);

        if (userSchedules.length > 0) {
            // User schedules found in current page
            setSelectedUserSchedules(userSchedules);
            setScheduleDetailsDialogOpen(true);
        } else {
            // User schedules not in current page, need to fetch from API
            setLoading(true);
            try {
                const response = await fetch(`/employee-schedules/user/${userId}/schedules`, {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (response.ok) {
                    const schedules = await response.json();
                    setSelectedUserSchedules(schedules);
                    setScheduleDetailsDialogOpen(true);
                } else {
                    console.error('Failed to fetch user schedules');
                }
            } catch (error) {
                console.error('Error fetching user schedules:', error);
            } finally {
                setLoading(false);
            }
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
                    <div className="grid grid-cols-1 gap-3 lg:grid-cols-[minmax(0,3fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)]">
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

                        <Select value={roleFilter} onValueChange={setRoleFilter}>
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Filter by Role" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Roles</SelectItem>
                                {roles.map(role => (
                                    <SelectItem key={role} value={role}>
                                        {role}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select value={campaignFilter} onValueChange={setCampaignFilter}>
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Filter by Campaign" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Campaigns</SelectItem>
                                {campaigns.map(campaign => (
                                    <SelectItem key={campaign.id} value={String(campaign.id)}>
                                        {campaign.name}{teamLeadCampaignId === campaign.id ? " (Your Campaign)" : ""}
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

                        <Button
                            variant={showResigned ? "default" : "outline"}
                            onClick={() => setShowResigned(!showResigned)}
                            className="w-full"
                        >
                            <Users className="mr-2 h-4 w-4" />
                            Show Resigned
                        </Button>
                    </div>

                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex flex-col gap-2 sm:flex-row sm:gap-3">
                            <Can permission="schedules.create">
                                <Button onClick={() => router.get(employeeSchedulesCreate().url)} className="w-full sm:w-auto">
                                    <Plus className="mr-2 h-4 w-4" />
                                    Add Employee Schedule
                                </Button>
                            </Can>
                            <Button
                                variant="outline"
                                onClick={() => setNoScheduleDialogOpen(true)}
                                className="w-full sm:w-auto"
                            >
                                <Users className="mr-2 h-4 w-4" />
                                Employees No Schedules
                                {employeesWithoutSchedules.length > 0 && (
                                    <Badge className="ml-2 bg-red-500">
                                        {employeesWithoutSchedules.length}
                                    </Badge>
                                )}
                            </Button>
                        </div>

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
                                <TableRow className="bg-muted/50">
                                    <TableHead>Employee</TableHead>
                                    <TableHead>Campaign</TableHead>
                                    <TableHead>Site</TableHead>
                                    <TableHead>Shift Type</TableHead>
                                    <TableHead>Time IN/OUT</TableHead>
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
                                                    {hasMultipleSchedules ? (
                                                        <button
                                                            onClick={() => handleViewEmployeeSchedules(schedule.user.id)}
                                                            className="text-blue-600 dark:text-blue-400 hover:underline cursor-pointer text-left"
                                                        >
                                                            {schedule.user.name}
                                                        </button>
                                                    ) : (
                                                        schedule.user.name
                                                    )}
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
                                            <TableCell className="whitespace-nowrap">{formatTime(schedule.scheduled_time_in)} - {formatTime(schedule.scheduled_time_out)}</TableCell>
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
                                                            variant="outline"
                                                            size="icon"
                                                            onClick={() => router.get(employeeSchedulesEdit({ employee_schedule: schedule.id }).url)}
                                                            title="Edit Schedule"
                                                        >
                                                            <Edit className="h-4 w-4" />
                                                        </Button>
                                                    </Can>
                                                    <Can permission="schedules.delete">
                                                        <Button
                                                            variant="outline"
                                                            size="icon"
                                                            onClick={() => handleDelete(schedule.id)}
                                                            title="Delete Schedule"
                                                            className="text-red-600 hover:text-red-700 border-red-300"
                                                        >
                                                            <Trash2 className="h-4 w-4" />
                                                        </Button>
                                                    </Can>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                                {scheduleData.data.length === 0 && !loading && (
                                    <TableRow>
                                        <TableCell colSpan={8} className="h-24 text-center text-muted-foreground">
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
                                            {hasMultipleSchedules ? (
                                                <button
                                                    onClick={() => handleViewEmployeeSchedules(schedule.user.id)}
                                                    className="text-lg font-semibold text-blue-600 dark:text-blue-400 hover:underline cursor-pointer text-left"
                                                >
                                                    {schedule.user.name}
                                                </button>
                                            ) : (
                                                <span className="text-lg font-semibold">{schedule.user.name}</span>
                                            )}
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
                                        {formatTime(schedule.scheduled_time_in)} - {formatTime(schedule.scheduled_time_out)}
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
                                            className="text-red-600 hover:text-red-700 border-red-300"
                                        >
                                            <Trash2 className="mr-2 h-4 w-4" />
                                            Delete
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

                {/* Employees Without Schedules Dialog */}
                <AlertDialog open={noScheduleDialogOpen} onOpenChange={(open) => {
                    setNoScheduleDialogOpen(open);
                    if (!open) {
                        setScheduleDialogTab('no-schedule');
                        setNoScheduleSearch("");
                    }
                }}>
                    <AlertDialogContent className="max-w-[95vw] sm:max-w-2xl max-h-[85vh] sm:max-h-[80vh] overflow-hidden flex flex-col">
                        <AlertDialogHeader>
                            <AlertDialogTitle className="flex flex-wrap items-center gap-2 text-base sm:text-lg">
                                <Users className="h-4 w-4 sm:h-5 sm:w-5" />
                                <span>Employee Schedule Status</span>
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                View employees without schedules, with only inactive schedules, or with multiple schedules.
                            </AlertDialogDescription>
                        </AlertDialogHeader>

                        {/* Tabs */}
                        {/* Tabs */}
                        <div className="grid grid-cols-3 border-b">
                            <button
                                onClick={() => {
                                    setScheduleDialogTab('no-schedule');
                                    setNoScheduleSearch("");
                                }}
                                className={`flex items-center justify-center gap-1.5 px-2 py-2.5 text-xs sm:text-sm font-medium border-b-2 transition-colors ${scheduleDialogTab === 'no-schedule'
                                    ? 'border-primary text-primary bg-primary/5'
                                    : 'border-transparent text-muted-foreground hover:text-foreground hover:bg-muted/50'
                                    }`}
                            >
                                <span>No Schedule</span>
                                <Badge variant={scheduleDialogTab === 'no-schedule' ? 'default' : 'secondary'} className="h-5 min-w-5 px-1.5 text-xs">
                                    {employeesWithoutSchedules.length}
                                </Badge>
                            </button>
                            <button
                                onClick={() => {
                                    setScheduleDialogTab('inactive');
                                    setNoScheduleSearch("");
                                }}
                                className={`flex items-center justify-center gap-1.5 px-2 py-2.5 text-xs sm:text-sm font-medium border-b-2 transition-colors ${scheduleDialogTab === 'inactive'
                                    ? 'border-primary text-primary bg-primary/5'
                                    : 'border-transparent text-muted-foreground hover:text-foreground hover:bg-muted/50'
                                    }`}
                            >
                                <span>Inactive</span>
                                <Badge variant={scheduleDialogTab === 'inactive' ? 'default' : 'secondary'} className="h-5 min-w-5 px-1.5 text-xs">
                                    {employeesWithInactiveSchedules.length}
                                </Badge>
                            </button>
                            <button
                                onClick={() => {
                                    setScheduleDialogTab('multiple');
                                    setNoScheduleSearch("");
                                }}
                                className={`flex items-center justify-center gap-1.5 px-2 py-2.5 text-xs sm:text-sm font-medium border-b-2 transition-colors ${scheduleDialogTab === 'multiple'
                                    ? 'border-primary text-primary bg-primary/5'
                                    : 'border-transparent text-muted-foreground hover:text-foreground hover:bg-muted/50'
                                    }`}
                            >
                                <span>Multiple</span>
                                <Badge variant={scheduleDialogTab === 'multiple' ? 'default' : 'secondary'} className="h-5 min-w-5 px-1.5 text-xs">
                                    {employeesWithMultipleSchedules.length}
                                </Badge>
                            </button>
                        </div>

                        {scheduleDialogTab === 'no-schedule' && employeesWithoutSchedules.length > 0 && (
                            <>
                                {employeesWithoutSchedules.length > 15 && (
                                    <div className="px-3 sm:px-6 mt-3">
                                        <div className="relative">
                                            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                                            <Input
                                                type="text"
                                                placeholder="Search employees..."
                                                value={noScheduleSearch}
                                                onChange={(e) => setNoScheduleSearch(e.target.value)}
                                                className="pl-9"
                                            />
                                        </div>
                                    </div>
                                )}

                                <div className="px-3 sm:px-6 pb-4 overflow-y-auto flex-1 mt-3">
                                    <div className="space-y-2">
                                        {filteredEmployeesWithoutSchedules.length > 0 ? (
                                            filteredEmployeesWithoutSchedules.map((user, index) => (
                                                <div
                                                    key={user.id}
                                                    className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 sm:gap-0 p-3 border rounded-lg hover:bg-muted/50 transition-colors"
                                                >
                                                    <div className="flex items-center gap-3">
                                                        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-medium">
                                                            {index + 1}
                                                        </div>
                                                        <span className="font-medium text-sm sm:text-base truncate">{user.name}</span>
                                                    </div>
                                                    <Can permission="schedules.create">
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            className="w-full sm:w-auto"
                                                            onClick={() => {
                                                                setNoScheduleDialogOpen(false);
                                                                router.get(employeeSchedulesCreate().url + `?user_id=${user.id}`);
                                                            }}
                                                        >
                                                            <Plus className="h-4 w-4 mr-1" />
                                                            Add Schedule
                                                        </Button>
                                                    </Can>
                                                </div>
                                            ))
                                        ) : (
                                            <div className="text-center py-8 text-muted-foreground">
                                                No employees found matching "{noScheduleSearch}"
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </>
                        )}

                        {scheduleDialogTab === 'no-schedule' && employeesWithoutSchedules.length === 0 && (
                            <div className="px-3 sm:px-6 pb-4 text-center py-8 text-muted-foreground">
                                All employees have at least one schedule assigned.
                            </div>
                        )}

                        {scheduleDialogTab === 'inactive' && employeesWithInactiveSchedules.length > 0 && (
                            <>
                                {employeesWithInactiveSchedules.length > 15 && (
                                    <div className="px-3 sm:px-6 mt-3">
                                        <div className="relative">
                                            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                                            <Input
                                                type="text"
                                                placeholder="Search employees..."
                                                value={noScheduleSearch}
                                                onChange={(e) => setNoScheduleSearch(e.target.value)}
                                                className="pl-9"
                                            />
                                        </div>
                                    </div>
                                )}

                                <div className="px-3 sm:px-6 pb-4 overflow-y-auto flex-1 mt-3">
                                    <div className="space-y-2">
                                        {filteredEmployeesWithInactiveSchedules.length > 0 ? (
                                            filteredEmployeesWithInactiveSchedules.map((user, index) => (
                                                <div
                                                    key={user.id}
                                                    className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 sm:gap-0 p-3 border rounded-lg hover:bg-muted/50 transition-colors"
                                                >
                                                    <div className="flex items-center gap-3">
                                                        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-amber-500/10 text-sm font-medium text-amber-700 dark:text-amber-300">
                                                            {index + 1}
                                                        </div>
                                                        <div className="flex-1">
                                                            <span className="font-medium text-sm sm:text-base">{user.name}</span>
                                                            <p className="text-xs text-muted-foreground mt-0.5">All schedules are inactive</p>
                                                        </div>
                                                    </div>
                                                    <div className="flex gap-2 w-full sm:w-auto">
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            className="flex-1 sm:flex-none"
                                                            onClick={() => {
                                                                setNoScheduleDialogOpen(false);
                                                                handleViewEmployeeSchedules(user.id);
                                                            }}
                                                        >
                                                            View Schedules
                                                        </Button>
                                                        <Can permission="schedules.create">
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                className="flex-1 sm:flex-none"
                                                                onClick={() => {
                                                                    setNoScheduleDialogOpen(false);
                                                                    router.get(employeeSchedulesCreate().url + `?user_id=${user.id}`);
                                                                }}
                                                            >
                                                                <Plus className="h-4 w-4 mr-1" />
                                                                Add New
                                                            </Button>
                                                        </Can>
                                                    </div>
                                                </div>
                                            ))
                                        ) : (
                                            <div className="text-center py-8 text-muted-foreground">
                                                No employees found matching "{noScheduleSearch}"
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </>
                        )}

                        {scheduleDialogTab === 'inactive' && employeesWithInactiveSchedules.length === 0 && (
                            <div className="px-3 sm:px-6 pb-4 text-center py-8 text-muted-foreground">
                                All employees with schedules have at least one active schedule.
                            </div>
                        )}

                        {scheduleDialogTab === 'multiple' && employeesWithMultipleSchedules.length > 0 && (
                            <>
                                {employeesWithMultipleSchedules.length > 15 && (
                                    <div className="px-3 sm:px-6 mt-3">
                                        <div className="relative">
                                            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                                            <Input
                                                type="text"
                                                placeholder="Search employees..."
                                                value={noScheduleSearch}
                                                onChange={(e) => setNoScheduleSearch(e.target.value)}
                                                className="pl-9"
                                            />
                                        </div>
                                    </div>
                                )}

                                <div className="px-3 sm:px-6 pb-4 overflow-y-auto flex-1 mt-3">
                                    <div className="space-y-2">
                                        {filteredEmployeesWithMultipleSchedules.length > 0 ? (
                                            filteredEmployeesWithMultipleSchedules.map((user, index) => (
                                                <div
                                                    key={user.id}
                                                    className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 sm:gap-0 p-3 border rounded-lg hover:bg-muted/50 transition-colors"
                                                >
                                                    <div className="flex items-center gap-3">
                                                        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-blue-500/10 text-sm font-medium text-blue-700 dark:text-blue-300">
                                                            {index + 1}
                                                        </div>
                                                        <div className="flex-1">
                                                            <span className="font-medium text-sm sm:text-base">{user.name}</span>
                                                            <p className="text-xs text-muted-foreground mt-0.5">
                                                                {user.schedule_count} schedules assigned
                                                            </p>
                                                        </div>
                                                    </div>
                                                    <div className="flex gap-2 w-full sm:w-auto">
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            className="flex-1 sm:flex-none"
                                                            onClick={() => {
                                                                setNoScheduleDialogOpen(false);
                                                                handleViewEmployeeSchedules(user.id);
                                                            }}
                                                        >
                                                            <Users className="h-4 w-4 mr-1" />
                                                            View All
                                                        </Button>
                                                        <Can permission="schedules.create">
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                className="flex-1 sm:flex-none"
                                                                onClick={() => {
                                                                    setNoScheduleDialogOpen(false);
                                                                    router.get(employeeSchedulesCreate().url + `?user_id=${user.id}`);
                                                                }}
                                                            >
                                                                <Plus className="h-4 w-4 mr-1" />
                                                                Add New
                                                            </Button>
                                                        </Can>
                                                    </div>
                                                </div>
                                            ))
                                        ) : (
                                            <div className="text-center py-8 text-muted-foreground">
                                                No employees found matching "{noScheduleSearch}"
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </>
                        )}

                        {scheduleDialogTab === 'multiple' && employeesWithMultipleSchedules.length === 0 && (
                            <div className="px-3 sm:px-6 pb-4 text-center py-8 text-muted-foreground">
                                No employees have multiple schedules assigned.
                            </div>
                        )}

                        <AlertDialogFooter>
                            <AlertDialogCancel onClick={() => {
                                setNoScheduleSearch("");
                                setScheduleDialogTab('no-schedule');
                            }}>Close</AlertDialogCancel>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>

                {/* Schedule Details Dialog */}
                <AlertDialog open={scheduleDetailsDialogOpen} onOpenChange={setScheduleDetailsDialogOpen}>
                    <AlertDialogContent className="max-w-[95vw] sm:max-w-4xl max-h-[85vh] sm:max-h-[80vh] overflow-hidden flex flex-col">
                        <AlertDialogHeader>
                            <AlertDialogTitle className="flex flex-wrap items-center gap-2 text-base sm:text-lg">
                                <Users className="h-4 w-4 sm:h-5 sm:w-5" />
                                <span className="truncate">{selectedUserSchedules[0]?.user.name} - All Schedules</span>
                                <Badge className="ml-0 sm:ml-2">{selectedUserSchedules.length}</Badge>
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                View all schedules for this employee. Active schedules are highlighted.
                            </AlertDialogDescription>
                        </AlertDialogHeader>

                        <div className="px-3 sm:px-6 pb-4 overflow-y-auto flex-1">
                            <div className="space-y-3 sm:space-y-4">
                                {selectedUserSchedules.map((schedule, index) => (
                                    <div
                                        key={schedule.id}
                                        className={`border rounded-lg p-3 sm:p-4 space-y-3 ${schedule.is_active
                                            ? "border-green-500 bg-green-50 dark:bg-green-950/20"
                                            : "border-border"
                                            }`}
                                    >
                                        <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                                            <div className="flex items-center gap-2 flex-wrap">
                                                <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-medium">
                                                    {index + 1}
                                                </div>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    {getShiftTypeBadge(schedule.shift_type)}
                                                    <div className="flex items-center gap-2">
                                                        <Can permission="schedules.toggle">
                                                            <Switch
                                                                checked={schedule.is_active}
                                                                onCheckedChange={() => {
                                                                    // Close dialog and trigger toggle
                                                                    setScheduleDetailsDialogOpen(false);
                                                                    handleToggleActive(schedule);
                                                                }}
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
                                            </div>
                                            <div className="flex gap-2 justify-end">
                                                <Can permission="schedules.edit">
                                                    <Button
                                                        size="icon"
                                                        variant="outline"
                                                        onClick={() => {
                                                            setScheduleDetailsDialogOpen(false);
                                                            router.get(employeeSchedulesEdit({ employee_schedule: schedule.id }).url);
                                                        }}
                                                        title="Edit Schedule"
                                                    >
                                                        <Edit className="h-4 w-4" />
                                                    </Button>
                                                </Can>
                                                <Can permission="schedules.delete">
                                                    <Button
                                                        size="icon"
                                                        variant="outline"
                                                        onClick={() => {
                                                            setScheduleDetailsDialogOpen(false);
                                                            handleDelete(schedule.id);
                                                        }}
                                                        title="Delete Schedule"
                                                        className="text-red-600 hover:text-red-700 border-red-300"
                                                    >
                                                        <Trash2 className="h-4 w-4" />
                                                    </Button>
                                                </Can>
                                            </div>
                                        </div>

                                        <div className="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                                            <div>
                                                <span className="font-medium text-muted-foreground">Campaign:</span>
                                                <p className="mt-1">{schedule.campaign?.name || "-"}</p>
                                            </div>
                                            <div>
                                                <span className="font-medium text-muted-foreground">Site:</span>
                                                <p className="mt-1">{schedule.site?.name || "-"}</p>
                                            </div>
                                            <div>
                                                <span className="font-medium text-muted-foreground">Time In:</span>
                                                <p className="mt-1">{formatTime(schedule.scheduled_time_in)}</p>
                                            </div>
                                            <div>
                                                <span className="font-medium text-muted-foreground">Time Out:</span>
                                                <p className="mt-1">{formatTime(schedule.scheduled_time_out)}</p>
                                            </div>
                                            <div>
                                                <span className="font-medium text-muted-foreground">Work Days:</span>
                                                <p className="mt-1">{schedule.work_days.map(day => day.charAt(0).toUpperCase() + day.slice(1)).join(", ")}</p>
                                            </div>
                                            <div>
                                                <span className="font-medium text-muted-foreground">Grace Period:</span>
                                                <p className="mt-1">{schedule.grace_period_minutes} minutes</p>
                                            </div>
                                            <div>
                                                <span className="font-medium text-muted-foreground">Effective Date:</span>
                                                <p className="mt-1">{formatDate(schedule.effective_date)}</p>
                                            </div>
                                            <div>
                                                <span className="font-medium text-muted-foreground">End Date:</span>
                                                <p className="mt-1">{schedule.end_date ? formatDate(schedule.end_date) : "Indefinite"}</p>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>

                        <AlertDialogFooter>
                            <AlertDialogCancel>Close</AlertDialogCancel>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </div>
        </AppLayout>
    );
}
