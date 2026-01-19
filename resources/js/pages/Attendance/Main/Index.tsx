import React, { useEffect, useState } from "react";
import { Head, router, usePage } from "@inertiajs/react";
import AppLayout from "@/layouts/app-layout";
import { useFlashMessage, usePageLoading, usePageMeta } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { LoadingOverlay } from "@/components/LoadingOverlay";
import { type SharedData, type UserRole } from "@/types";
import { formatTime, formatDate, formatDateTime } from "@/lib/utils";
import { Can } from "@/components/authorization";
import { usePermission } from "@/hooks/useAuthorization";
import { Button } from "@/components/ui/button";
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from "@/components/ui/command";
import { DatePicker } from "@/components/ui/date-picker"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue
} from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@/components/ui/tooltip";
import PaginationNav, { PaginationLink } from "@/components/pagination-nav";
import { CheckCircle, AlertCircle, Trash2, Check, ChevronsUpDown, RefreshCw, Search, Play, Pause, Edit, Upload } from "lucide-react";
import { Calendar as CalendarIcon } from "lucide-react";
import { Checkbox } from "@/components/ui/checkbox";
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import {
    index as attendanceIndex,
    calendar as attendanceCalendar,
    create as attendanceCreate,
    importMethod as attendanceImport,
    review as attendanceReview,
    dailyRoster as attendanceDailyRoster,
    bulkDelete as attendanceBulkDelete,
    bulkQuickApprove as attendanceBulkQuickApprove,
    quickApprove as attendanceQuickApprove,
} from "@/routes/attendance";

interface User {
    id: number;
    first_name: string;
    last_name: string;
    name: string;
    active_schedule?: EmployeeSchedule; // Fallback for campaign/site
}

interface Campaign {
    id: number;
    name: string;
}

interface Site {
    id: number;
    name: string;
}

interface EmployeeSchedule {
    id: number;
    shift_type: string;
    scheduled_time_in: string;
    scheduled_time_out: string;
    site?: Site;
    campaign?: Campaign;
}

interface AttendanceRecord {
    id: number;
    user: User;
    employee_schedule?: EmployeeSchedule;
    shift_date: string;
    actual_time_in?: string;
    actual_time_out?: string;
    status: string;
    secondary_status?: string;
    tardy_minutes?: number;
    undertime_minutes?: number;
    overtime_minutes?: number;
    overtime_approved?: boolean;
    is_advised: boolean;
    is_cross_site_bio?: boolean;
    bio_in_site?: Site;
    bio_out_site?: Site;
    admin_verified: boolean;
    verification_notes?: string;
    notes?: string;
    warnings?: string[];
}

interface Meta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface AttendancePayload {
    data: AttendanceRecord[];
    links: PaginationLink[];
    meta: Meta;
}

interface PageProps extends SharedData {
    attendances?: AttendancePayload;
    users?: User[];
    sites?: Site[];
    campaigns?: Campaign[];
    teamLeadCampaignId?: number;
    filters?: {
        search?: string;
        status?: string;
        start_date?: string;
        end_date?: string;
        user_id?: string;
        site_id?: string;
        campaign_id?: string;
        needs_verification?: boolean;
    };
    [key: string]: unknown;
}

const DEFAULT_META: Meta = {
    current_page: 1,
    last_page: 1,
    per_page: 50,
    total: 0,
};

// formatDateTime, formatDate, formatTime are now imported from @/lib/utils

const getStatusBadge = (status: string) => {
    const statusConfig: Record<string, { label: string; className: string }> = {
        on_time: { label: "On Time", className: "bg-green-500" },
        tardy: { label: "Tardy", className: "bg-yellow-500" },
        half_day_absence: { label: "Half Day", className: "bg-orange-500" },
        advised_absence: { label: "Advised Absence", className: "bg-blue-500" },
        on_leave: { label: "On Leave", className: "bg-blue-600" },
        ncns: { label: "NCNS", className: "bg-red-500" },
        undertime: { label: "Undertime", className: "bg-orange-400" },
        undertime_more_than_hour: { label: "UT >1hr", className: "bg-orange-600" },
        failed_bio_in: { label: "No Bio In", className: "bg-purple-500" },
        failed_bio_out: { label: "No Bio Out", className: "bg-purple-400" },
        needs_manual_review: { label: "Needs Review", className: "bg-amber-500" },
        present_no_bio: { label: "Present (No Bio)", className: "bg-gray-500" },
        non_work_day: { label: "Non-Work Day", className: "bg-slate-500" },
    };

    const config = statusConfig[status] || { label: status, className: "bg-gray-500" };
    return <Badge className={config.className}>{config.label}</Badge>;
};

const getStatusBadges = (record: AttendanceRecord) => {
    return (
        <div className="flex gap-1 flex-wrap items-center">
            {getStatusBadge(record.status)}
            {record.secondary_status && getStatusBadge(record.secondary_status)}
            {record.overtime_minutes && record.overtime_minutes > 0 && (
                <Badge className={record.overtime_approved ? "bg-green-500" : "bg-blue-500"}>
                    Overtime{record.overtime_approved && " ✓"}
                </Badge>
            )}
            {record.warnings && record.warnings.length > 0 && (
                <span title="Has warnings - needs review">
                    <AlertCircle className="h-4 w-4 text-amber-500" />
                </span>
            )}
        </div>
    );
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

export default function AttendanceIndex() {
    const { attendances, users = [], sites = [], campaigns = [], teamLeadCampaignId, filters, auth } = usePage<PageProps>().props;

    // Ensure we have proper data structure
    const attendanceData = {
        data: Array.isArray(attendances?.data) ? attendances.data : [],
        links: Array.isArray(attendances?.links) ? attendances.links : [],
        meta: attendances?.meta ?? DEFAULT_META,
    };
    const appliedFilters = filters ?? {};
    const userRole = auth.user?.role;
    const isTeamLead = userRole === 'Team Lead';

    const { title, breadcrumbs } = usePageMeta({
        title: "Attendance",
        breadcrumbs: [{ title: "Attendance", href: attendanceIndex().url }],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();
    const { can } = usePermission();

    const [loading, setLoading] = useState(false);
    const [selectedUserId, setSelectedUserId] = useState(appliedFilters.user_id || "");
    const [isUserPopoverOpen, setIsUserPopoverOpen] = useState(false);
    const [userSearchQuery, setUserSearchQuery] = useState("");
    const [selectedSiteId, setSelectedSiteId] = useState(appliedFilters.site_id || "");
    // Auto-select Team Lead's campaign if no filter is applied
    const [selectedCampaignId, setSelectedCampaignId] = useState(() => {
        if (appliedFilters.campaign_id) return appliedFilters.campaign_id;
        if (isTeamLead && teamLeadCampaignId) return teamLeadCampaignId.toString();
        return "";
    });
    const [statusFilter, setStatusFilter] = useState(appliedFilters.status || "all");
    const [startDate, setStartDate] = useState(appliedFilters.start_date || "");
    const [endDate, setEndDate] = useState(appliedFilters.end_date || "");
    const [needsVerification, setNeedsVerification] = useState(appliedFilters.needs_verification || false);

    // LocalStorage keys for persisting selection
    const LOCAL_STORAGE_KEY = 'attendance_selected_ids';
    const LOCAL_STORAGE_TIMESTAMP_KEY = 'attendance_selected_ids_timestamp';
    const EXPIRY_TIME_MS = 15 * 60 * 1000; // 15 minutes

    const [selectedRecords, setSelectedRecords] = useState<number[]>(() => {
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
            return [];
        }
    });
    const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
    const [lastRefresh, setLastRefresh] = useState<Date>(new Date());
    const [autoRefreshEnabled, setAutoRefreshEnabled] = useState(false);
    const [noteDialogOpen, setNoteDialogOpen] = useState(false);
    const [selectedNoteRecord, setSelectedNoteRecord] = useState<AttendanceRecord | null>(null);

    // Helper to display notes - show dialog with both notes and verification notes
    const NotesDisplay = ({ record }: { record: AttendanceRecord }) => {
        const hasNotes = record.notes || record.verification_notes;

        if (!hasNotes) return <span className="text-muted-foreground">-</span>;

        // Combine for preview
        const preview = record.notes || record.verification_notes || '';

        return (
            <button
                onClick={() => {
                    setSelectedNoteRecord(record);
                    setNoteDialogOpen(true);
                }}
                className="text-sm text-primary hover:underline cursor-pointer text-left"
            >
                {preview.length > 10 ? `${preview.substring(0, 10)}...` : preview}
            </button>
        );
    };

    // Save selectedRecords to localStorage on change
    useEffect(() => {
        try {
            if (selectedRecords.length > 0) {
                localStorage.setItem(LOCAL_STORAGE_KEY, JSON.stringify(selectedRecords));
                localStorage.setItem(LOCAL_STORAGE_TIMESTAMP_KEY, Date.now().toString());
            } else {
                localStorage.removeItem(LOCAL_STORAGE_KEY);
                localStorage.removeItem(LOCAL_STORAGE_TIMESTAMP_KEY);
            }
        } catch {
            // Ignore localStorage errors
        }
    }, [selectedRecords]);

    // Update local state when filters prop changes (e.g., when navigating back or pagination)
    useEffect(() => {
        setSelectedUserId(appliedFilters.user_id || "");
        setSelectedSiteId(appliedFilters.site_id || "");
        // For Team Leads, default to their campaign if no filter is applied
        if (appliedFilters.campaign_id) {
            setSelectedCampaignId(appliedFilters.campaign_id);
        } else if (isTeamLead && teamLeadCampaignId) {
            setSelectedCampaignId(teamLeadCampaignId.toString());
        } else {
            setSelectedCampaignId("");
        }
        setStatusFilter(appliedFilters.status || "all");
        setStartDate(appliedFilters.start_date || "");
        setEndDate(appliedFilters.end_date || "");
        setNeedsVerification(appliedFilters.needs_verification || false);
        // Don't clear selections when filters change
    }, [appliedFilters.user_id, appliedFilters.site_id, appliedFilters.campaign_id, appliedFilters.status, appliedFilters.start_date, appliedFilters.end_date, appliedFilters.needs_verification, isTeamLead, teamLeadCampaignId]);

    const userId = auth.user?.id;
    // Roles that should only see their own attendance records
    const restrictedRoles: UserRole[] = ['Agent', 'IT', 'Utility'];
    const isRestrictedUser = userRole && restrictedRoles.includes(userRole);

    // Filter users based on search query
    const filteredUsers = React.useMemo(() => {
        if (!userSearchQuery) return users;
        return users.filter(user =>
            user.name.toLowerCase().includes(userSearchQuery.toLowerCase())
        );
    }, [users, userSearchQuery]);

    const handleSearch = () => {
        const params: Record<string, string> = {};

        // For Agent, IT, and Utility roles, automatically filter to their own records
        if (isRestrictedUser && userId) {
            params.user_id = userId.toString();
        } else if (selectedUserId) {
            // Only allow user filter for users with higher permissions
            params.user_id = selectedUserId;
        }

        if (selectedSiteId) params.site_id = selectedSiteId;
        if (selectedCampaignId) params.campaign_id = selectedCampaignId;
        if (statusFilter !== "all") params.status = statusFilter;
        if (startDate) params.start_date = startDate;
        if (endDate) params.end_date = endDate;
        if (needsVerification) params.needs_verification = "1";

        setLoading(true);
        router.get(attendanceIndex().url, params, {
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

            if (isRestrictedUser && userId) {
                params.user_id = userId.toString();
            } else if (selectedUserId) {
                params.user_id = selectedUserId;
            }

            if (selectedSiteId) params.site_id = selectedSiteId;
            if (selectedCampaignId) params.campaign_id = selectedCampaignId;
            if (statusFilter !== "all") params.status = statusFilter;
            if (startDate) params.start_date = startDate;
            if (endDate) params.end_date = endDate;
            if (needsVerification) params.needs_verification = "1";

            router.get(attendanceIndex().url, params, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['attendances'],
                onSuccess: () => setLastRefresh(new Date()),
            });
        }, 30000);

        return () => clearInterval(interval);
    }, [autoRefreshEnabled, selectedUserId, selectedSiteId, selectedCampaignId, statusFilter, startDate, endDate, needsVerification, isRestrictedUser, userId]);

    const showClearFilters =
        statusFilter !== "all" ||
        Boolean(startDate) ||
        Boolean(endDate) ||
        needsVerification ||
        Boolean(selectedUserId) ||
        Boolean(selectedSiteId) ||
        Boolean(selectedCampaignId);

    const clearFilters = () => {
        setSelectedUserId("");
        setUserSearchQuery("");
        setSelectedSiteId("");
        setSelectedCampaignId("");
        setStatusFilter("all");
        setStartDate("");
        setEndDate("");
        setNeedsVerification(false);

        // Trigger reload with cleared filters
        setLoading(true);
        router.get(attendanceIndex().url, {}, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: () => setLastRefresh(new Date()),
            onFinish: () => setLoading(false),
        });
    };

    const toggleSelectAll = () => {
        const currentPageIds = attendanceData.data.map(record => record.id);
        const allCurrentSelected = currentPageIds.every(id => selectedRecords.includes(id));

        if (allCurrentSelected) {
            // Deselect all on current page
            setSelectedRecords(prev => prev.filter(id => !currentPageIds.includes(id)));
        } else {
            // Select all on current page
            setSelectedRecords(prev => {
                const newIds = currentPageIds.filter(id => !prev.includes(id));
                return [...prev, ...newIds];
            });
        }
    };

    const toggleSelectRecord = (recordId: number) => {
        setSelectedRecords(prev =>
            prev.includes(recordId)
                ? prev.filter(id => id !== recordId)
                : [...prev, recordId]
        );
    };

    const handleBulkDelete = () => {
        if (selectedRecords.length === 0) return;
        setShowDeleteConfirm(true);
    };

    const confirmBulkDelete = () => {
        router.delete(attendanceBulkDelete().url, {
            data: { ids: selectedRecords },
            onSuccess: () => {
                setSelectedRecords([]);
                setShowDeleteConfirm(false);
            },
        });
    };

    const handleBulkQuickApprove = () => {
        if (selectedRecords.length === 0) {
            return;
        }

        // Filter only eligible records
        const eligibleRecords = attendanceData.data
            .filter(record => selectedRecords.includes(record.id))
            .filter(canQuickApprove);

        if (eligibleRecords.length === 0) {
            return;
        }

        router.post(attendanceBulkQuickApprove().url, {
            ids: eligibleRecords.map(r => r.id),
        }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setSelectedRecords([]);
            },
        });
    };

    const getEligibleQuickApproveCount = () => {
        return attendanceData.data
            .filter(record => selectedRecords.includes(record.id))
            .filter(canQuickApprove)
            .length;
    };

    const handleQuickApprove = (recordId: number) => {
        router.post(attendanceQuickApprove({ attendance: recordId }).url, {}, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const canQuickApprove = (record: AttendanceRecord) => {
        // Can quick approve if:
        // 1. Status is "on_time"
        // 2. Not already verified
        // 3. No overtime OR overtime is already approved
        return (
            record.status === 'on_time' &&
            !record.admin_verified &&
            (!record.overtime_minutes || record.overtime_minutes === 0 || record.overtime_approved)
        );
    };

    const needsReview = (record: AttendanceRecord) => {
        // Needs review if on_time with unapproved overtime
        return (
            record.status === 'on_time' &&
            record.overtime_minutes &&
            record.overtime_minutes > 0 &&
            !record.overtime_approved
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                <LoadingOverlay isLoading={isPageLoading || loading} />

                <PageHeader
                    title="Attendance Management"
                    description="Review attendance records and manage daily logs"
                />

                <div className="flex flex-col gap-3">
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:flex lg:flex-row lg:flex-wrap lg:items-center gap-3">
                        {!isRestrictedUser && (
                            <Popover open={isUserPopoverOpen} onOpenChange={setIsUserPopoverOpen}>
                                <PopoverTrigger asChild>
                                    <Button
                                        variant="outline"
                                        role="combobox"
                                        aria-expanded={isUserPopoverOpen}
                                        className="w-full justify-between font-normal lg:w-auto lg:flex-1"
                                    >
                                        <span className="truncate">
                                            {selectedUserId
                                                ? users.find(u => u.id.toString() === selectedUserId)?.name || "Select employee..."
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
                                                        setSelectedUserId("");
                                                        setIsUserPopoverOpen(false);
                                                        setUserSearchQuery("");
                                                    }}
                                                    className="cursor-pointer"
                                                >
                                                    <Check
                                                        className={`mr-2 h-4 w-4 ${!selectedUserId ? "opacity-100" : "opacity-0"}`}
                                                    />
                                                    All Employees
                                                </CommandItem>
                                                {filteredUsers.map((user) => (
                                                    <CommandItem
                                                        key={user.id}
                                                        value={user.name}
                                                        onSelect={() => {
                                                            setSelectedUserId(user.id.toString());
                                                            setIsUserPopoverOpen(false);
                                                            setUserSearchQuery("");
                                                        }}
                                                        className="cursor-pointer"
                                                    >
                                                        <Check
                                                            className={`mr-2 h-4 w-4 ${selectedUserId === user.id.toString()
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
                        )}

                        <Select value={statusFilter} onValueChange={setStatusFilter}>
                            <SelectTrigger className="w-full lg:w-[180px]">
                                <SelectValue placeholder="Filter by Status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Statuses</SelectItem>
                                <SelectItem value="on_time">On Time</SelectItem>
                                <SelectItem value="tardy">Tardy</SelectItem>
                                <SelectItem value="half_day_absence">Half Day Absence</SelectItem>
                                <SelectItem value="advised_absence">Advised Absence</SelectItem>
                                <SelectItem value="ncns">NCNS</SelectItem>
                                <SelectItem value="undertime">Undertime</SelectItem>
                                <SelectItem value="failed_bio_in">Failed Bio In</SelectItem>
                                <SelectItem value="failed_bio_out">Failed Bio Out</SelectItem>
                                <SelectItem value="needs_manual_review">Needs Review</SelectItem>
                                <SelectItem value="on_leave">On Leave</SelectItem>
                            </SelectContent>
                        </Select>

                        <Select value={selectedSiteId || "all"} onValueChange={(value) => setSelectedSiteId(value === "all" ? "" : value)}>
                            <SelectTrigger className="w-full lg:w-[180px]">
                                <SelectValue placeholder="All Sites" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Sites</SelectItem>
                                {sites.map((site) => (
                                    <SelectItem key={site.id} value={site.id.toString()}>
                                        {site.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        {/* Campaign Filter - Team Leads default to their campaign */}
                        <Select
                            value={selectedCampaignId || "all"}
                            onValueChange={(value) => setSelectedCampaignId(value === "all" ? "" : value)}
                        >
                            <SelectTrigger className="w-full lg:w-auto lg:flex-1">
                                <SelectValue placeholder="All Campaigns" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Campaigns</SelectItem>
                                {campaigns.map((campaign) => (
                                    <SelectItem key={campaign.id} value={campaign.id.toString()}>
                                        {campaign.name}{isTeamLead && teamLeadCampaignId === campaign.id ? ' (Your Campaign)' : ''}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <div className="flex items-center gap-2 text-sm lg:w-auto lg:flex-1">
                            <DatePicker
                                value={startDate}
                                onChange={(value) => setStartDate(value)}
                                placeholder="Start date"
                                className="w-full"
                            />
                        </div>

                        <div className="flex items-center gap-2 text-sm lg:w-auto lg:flex-1">
                            <DatePicker
                                value={endDate}
                                onChange={(value) => setEndDate(value)}
                                placeholder="End date"
                                className="w-full"
                            />
                        </div>

                        <div className="flex items-center gap-2 lg:w-auto">
                            <TooltipProvider>
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <Button
                                            variant={needsVerification ? "default" : "outline"}
                                            onClick={() => setNeedsVerification(!needsVerification)}
                                            size="icon"
                                        >
                                            <AlertCircle className="h-4 w-4" />
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        <p>Needs Verification</p>
                                    </TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                            <Button variant="default" onClick={handleSearch} className="whitespace-nowrap px-6">
                                <Search className="mr-2 h-4 w-4" />
                                Apply
                            </Button>
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

                    {showClearFilters && (
                        <div className="flex justify-end">
                            <Button variant="outline" onClick={clearFilters} className="w-full sm:w-auto">
                                Clear Filters
                            </Button>
                        </div>
                    )}

                    {/* Actions Row */}
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end border-t pt-3">
                        {selectedRecords.length > 0 && getEligibleQuickApproveCount() > 0 && (
                            <Can permission="attendance.approve">
                                <Button
                                    onClick={handleBulkQuickApprove}
                                    variant="outline"
                                    className="w-full sm:w-auto"
                                >
                                    <Check className="mr-2 h-4 w-4" />
                                    Quick Approve ({getEligibleQuickApproveCount()})
                                </Button>
                            </Can>
                        )}
                        {selectedRecords.length > 0 && (
                            <Can permission="attendance.delete">
                                <Button
                                    onClick={handleBulkDelete}
                                    variant="destructive"
                                    className="w-full sm:w-auto"
                                >
                                    <Trash2 className="mr-2 h-4 w-4" />
                                    Delete Selected ({selectedRecords.length})
                                </Button>
                            </Can>
                        )}
                        <Button
                            onClick={() => router.get(attendanceCalendar().url)}
                            className="w-full sm:w-auto"
                            variant="default"
                        >
                            <CalendarIcon className="mr-2 h-4 w-4" />
                            Calendar View
                        </Button>
                        <Can permission="attendance.create">
                            <Button
                                onClick={() => router.get(attendanceCreate().url)}
                                className="w-full sm:w-auto"
                                variant="outline"
                            >
                                <Edit className="mr-2 h-4 w-4" />
                                Manual Attendance
                            </Button>
                        </Can>
                        <Can permission="attendance.import">
                            <Button
                                onClick={() => router.get(attendanceImport().url)}
                                className="w-full sm:w-auto"
                                variant="outline"
                            >
                                <Upload className="mr-2 h-4 w-4" />
                                Import Biometric
                            </Button>
                        </Can>
                        <Can permission="attendance.create">
                            <Button
                                onClick={() => router.get(attendanceDailyRoster().url)}
                                className="w-full sm:w-auto"
                                variant="outline"
                            >
                                <CalendarIcon className="mr-2 h-4 w-4" />
                                Daily Roster
                            </Button>
                        </Can>
                        <Can permission="attendance.review">
                            <Button
                                onClick={() => router.get(attendanceReview().url)}
                                className="w-full sm:w-auto"
                                variant="outline"
                            >
                                <AlertCircle className="mr-2 h-4 w-4" />
                                Review Flagged
                            </Button>
                        </Can>
                    </div>
                </div>

                <div className="flex justify-between items-center text-sm">
                    <div className="text-muted-foreground">
                        Showing {attendanceData.meta.total === 0 ? '0' : `${attendanceData.data.length}`} of {attendanceData.meta.total} record
                        {attendanceData.meta.total === 1 ? "" : "s"}
                        {showClearFilters ? " (filtered)" : ""}
                    </div>
                    <div className="flex items-center gap-4">
                        {selectedRecords.length > 0 && (
                            <Badge variant="secondary" className="font-normal">
                                {selectedRecords.length} record{selectedRecords.length === 1 ? "" : "s"} selected
                            </Badge>
                        )}
                        <div className="text-xs text-muted-foreground">
                            Last updated: {lastRefresh.toLocaleTimeString()}
                        </div>
                    </div>
                </div>

                <div className="hidden md:block shadow rounded-md overflow-hidden">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow className="bg-muted/50">
                                    <TableHead className="w-12">
                                        <Checkbox
                                            checked={
                                                attendanceData.data.length > 0 &&
                                                attendanceData.data.every(record => selectedRecords.includes(record.id))
                                            }
                                            onCheckedChange={toggleSelectAll}
                                        />
                                    </TableHead>
                                    <TableHead>Employee</TableHead>
                                    <TableHead>Campaign</TableHead>
                                    <TableHead>Site</TableHead>
                                    <TableHead>Shift Date</TableHead>
                                    <TableHead>Shift Type</TableHead>
                                    <TableHead>Schedule</TableHead>
                                    <TableHead>Time In</TableHead>
                                    <TableHead>Time Out</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Tardy/UT/OT</TableHead>
                                    <TableHead>Notes</TableHead>
                                    <TableHead>Verified</TableHead>
                                    {(can('attendance.approve') || can('attendance.verify') || can('attendance.delete')) && <TableHead>Actions</TableHead>}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {attendanceData.data.map(record => (
                                    <TableRow key={record.id} className={record.is_cross_site_bio ? "bg-orange-50/30 dark:bg-orange-950/10" : ""}>
                                        <TableCell>
                                            <Checkbox
                                                checked={selectedRecords.includes(record.id)}
                                                onCheckedChange={() => toggleSelectRecord(record.id)}
                                            />
                                        </TableCell>
                                        <TableCell className="font-medium">
                                            {record.user.name}
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {record.employee_schedule?.campaign?.name || record.user.active_schedule?.campaign?.name || <span className="text-muted-foreground">-</span>}
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {record.employee_schedule?.site?.name || record.user.active_schedule?.site?.name || <span className="text-muted-foreground">-</span>}
                                            {record.is_cross_site_bio && (
                                                <Badge variant="outline" className="ml-2 text-orange-600 border-orange-600 text-xs">
                                                    Cross-Site
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell>{formatDate(record.shift_date)}</TableCell>
                                        <TableCell className="text-sm">
                                            {record.employee_schedule?.shift_type ? (
                                                getShiftTypeBadge(record.employee_schedule.shift_type)
                                            ) : (
                                                "-"
                                            )}
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {record.employee_schedule ? (
                                                <div className="whitespace-nowrap">
                                                    {formatTime(record.employee_schedule.scheduled_time_in)} - {formatTime(record.employee_schedule.scheduled_time_out)}
                                                </div>
                                            ) : (
                                                "-"
                                            )}
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {formatDateTime(record.actual_time_in)}
                                            {record.bio_in_site && record.is_cross_site_bio && (
                                                <div className="text-xs text-muted-foreground">
                                                    @ {record.bio_in_site.name}
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {formatDateTime(record.actual_time_out)}
                                            {record.bio_out_site && record.is_cross_site_bio && (
                                                <div className="text-xs text-muted-foreground">
                                                    @ {record.bio_out_site.name}
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell>{getStatusBadges(record)}</TableCell>
                                        <TableCell className="text-sm">
                                            <div className="space-y-1">
                                                {record.tardy_minutes && record.tardy_minutes > 0 && (
                                                    <div className="text-orange-600">
                                                        +{record.tardy_minutes >= 60 ? `${Math.floor(record.tardy_minutes / 60)}h` : `${record.tardy_minutes}m`} T
                                                    </div>
                                                )}
                                                {record.undertime_minutes && record.undertime_minutes > 0 && (
                                                    <div className="text-orange-600">
                                                        {record.undertime_minutes >= 60 ? `${Math.floor(record.undertime_minutes / 60)}h` : `${record.undertime_minutes}m`} UT
                                                    </div>
                                                )}
                                                {(!record.tardy_minutes || record.tardy_minutes === 0) &&
                                                    (!record.undertime_minutes || record.undertime_minutes === 0) &&
                                                    (!record.overtime_minutes || record.overtime_minutes === 0) && (
                                                        <div>-</div>
                                                    )}
                                                {record.overtime_minutes && record.overtime_minutes > 0 && (
                                                    <div className={`text-xs ${record.overtime_approved ? 'text-green-600' : 'text-blue-600'}`}>
                                                        +{record.overtime_minutes >= 60 ? `${Math.floor(record.overtime_minutes / 60)}h` : `${record.overtime_minutes}m`} OT
                                                        {record.overtime_approved && ' ✓'}
                                                    </div>
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <NotesDisplay record={record} />
                                        </TableCell>
                                        <TableCell>
                                            {record.admin_verified ? (
                                                <CheckCircle className="h-4 w-4 text-green-500" />
                                            ) : (
                                                <span className="text-muted-foreground text-xs">Pending</span>
                                            )}
                                        </TableCell>
                                        {(can('attendance.approve') || can('attendance.verify') || can('attendance.delete')) && (
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    <Can permission="attendance.approve">
                                                        {canQuickApprove(record) ? (
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={() => handleQuickApprove(record.id)}
                                                                className="h-8"
                                                            >
                                                                <Check className="h-3 w-3 mr-1" />
                                                                Approve
                                                            </Button>
                                                        ) : needsReview(record) ? (
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={() => window.open(attendanceReview({ query: { verify: record.id } }).url, '_blank')}
                                                                className="h-8 text-amber-600 border-amber-600"
                                                            >
                                                                <AlertCircle className="h-3 w-3 mr-1" />
                                                                Review
                                                            </Button>
                                                        ) : null}
                                                    </Can>
                                                    <Can permission="attendance.verify">
                                                        {!record.admin_verified && (
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={() => window.open(attendanceReview({ query: { verify: record.id } }).url, '_blank')}
                                                                className="h-8"
                                                            >
                                                                <Edit className="h-3 w-3 mr-1" />
                                                                Verify
                                                            </Button>
                                                        )}
                                                    </Can>
                                                </div>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                                {attendanceData.data.length === 0 && !loading && (
                                    <TableRow>
                                        <TableCell colSpan={14} className="h-24 text-center text-muted-foreground">
                                            No attendance records found
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </div>

                <div className="md:hidden space-y-4">
                    {attendanceData.data.map(record => (
                        <div key={record.id} className={`bg-card border rounded-lg p-4 shadow-sm space-y-3 ${record.is_cross_site_bio ? "border-orange-300" : ""}`}>
                            <div className="flex items-start gap-3">
                                <Checkbox
                                    checked={selectedRecords.includes(record.id)}
                                    onCheckedChange={() => toggleSelectRecord(record.id)}
                                    className="mt-1"
                                />
                                <div className="flex-1">
                                    <div className="flex justify-between items-start">
                                        <div>
                                            <div className="text-lg font-semibold">
                                                {record.user.name}
                                                {record.is_cross_site_bio && (
                                                    <Badge variant="outline" className="ml-2 text-orange-600 border-orange-600 text-xs">
                                                        Cross-Site
                                                    </Badge>
                                                )}
                                            </div>
                                            <div className="text-sm text-muted-foreground">{formatDate(record.shift_date)}</div>
                                        </div>
                                        {getStatusBadges(record)}
                                    </div>

                                    <div className="space-y-2 text-sm mt-3">
                                        <div>
                                            <span className="font-medium">Campaign:</span>{" "}
                                            {record.employee_schedule?.campaign?.name || record.user.active_schedule?.campaign?.name || "-"}
                                        </div>
                                        <div>
                                            <span className="font-medium">Site:</span>{" "}
                                            {record.employee_schedule?.site?.name || record.user.active_schedule?.site?.name || "-"}
                                            {record.is_cross_site_bio && (
                                                <Badge variant="outline" className="ml-2 text-orange-600 border-orange-600 text-xs">
                                                    Cross-Site
                                                </Badge>
                                            )}
                                        </div>
                                        <div>
                                            <span className="font-medium">Shift Type:</span>{" "}
                                            {record.employee_schedule?.shift_type ? (
                                                getShiftTypeBadge(record.employee_schedule.shift_type)
                                            ) : (
                                                "-"
                                            )}
                                        </div>
                                        <div>
                                            <span className="font-medium">Schedule:</span>{" "}
                                            {record.employee_schedule ? (
                                                <span>{formatTime(record.employee_schedule.scheduled_time_in)} - {formatTime(record.employee_schedule.scheduled_time_out)}</span>
                                            ) : (
                                                "-"
                                            )}
                                        </div>
                                        <div>
                                            <span className="font-medium">Time In:</span> {formatDateTime(record.actual_time_in)}
                                            {record.bio_in_site && record.is_cross_site_bio && (
                                                <span className="text-muted-foreground"> @ {record.bio_in_site.name}</span>
                                            )}
                                        </div>
                                        <div>
                                            <span className="font-medium">Time Out:</span> {formatDateTime(record.actual_time_out)}
                                            {record.bio_out_site && record.is_cross_site_bio && (
                                                <span className="text-muted-foreground"> @ {record.bio_out_site.name}</span>
                                            )}
                                        </div>
                                        {record.tardy_minutes && record.tardy_minutes > 0 && (
                                            <div>
                                                <span className="font-medium">Tardy:</span>{" "}
                                                <span className="text-orange-600">
                                                    +{record.tardy_minutes >= 60 ? `${Math.floor(record.tardy_minutes / 60)}h` : `${record.tardy_minutes}m`} T
                                                </span>
                                            </div>
                                        )}
                                        {record.undertime_minutes && record.undertime_minutes > 0 && (
                                            <div>
                                                <span className="font-medium">Undertime:</span>{" "}
                                                <span className="text-orange-600">
                                                    {record.undertime_minutes >= 60 ? `${Math.floor(record.undertime_minutes / 60)}h` : `${record.undertime_minutes}m`} UT
                                                </span>
                                            </div>
                                        )}
                                        {record.overtime_minutes && record.overtime_minutes > 0 && (
                                            <div>
                                                <span className="font-medium">Overtime:</span>{" "}
                                                <span className={record.overtime_approved ? 'text-green-600' : 'text-blue-600'}>
                                                    +{record.overtime_minutes >= 60 ? `${Math.floor(record.overtime_minutes / 60)}h` : `${record.overtime_minutes}m`} OT
                                                    {record.overtime_approved && ' (Approved)'}
                                                </span>
                                            </div>
                                        )}
                                        <div>
                                            <span className="font-medium">Verified:</span>{" "}
                                            {record.admin_verified ? (
                                                <span className="text-green-600">Yes</span>
                                            ) : (
                                                <span className="text-muted-foreground">Pending</span>
                                            )}
                                        </div>
                                        {(record.notes || record.verification_notes) && (
                                            <div>
                                                <span className="font-medium">Notes:</span>{" "}
                                                <NotesDisplay record={record} />
                                            </div>
                                        )}
                                    </div>

                                    <Can permission="attendance.approve">
                                        {(canQuickApprove(record) || needsReview(record)) && (
                                            <div className="mt-3 pt-3 border-t">
                                                {canQuickApprove(record) ? (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => handleQuickApprove(record.id)}
                                                        className="w-full"
                                                    >
                                                        <Check className="h-3 w-3 mr-1" />
                                                        Quick Approve
                                                    </Button>
                                                ) : (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => window.open(attendanceReview({ query: { verify: record.id } }).url, '_blank')}
                                                        className="w-full text-amber-600 border-amber-600"
                                                    >
                                                        <AlertCircle className="h-3 w-3 mr-1" />
                                                        Needs Review
                                                    </Button>
                                                )}
                                            </div>
                                        )}
                                    </Can>
                                </div>
                            </div>
                        </div>
                    ))}

                    {attendanceData.data.length === 0 && !loading && (
                        <div className="py-12 text-center text-gray-500 border rounded-lg bg-card">
                            No attendance records found
                        </div>
                    )}
                </div>

                <div className="flex justify-center mt-4">
                    {attendanceData.links && attendanceData.links.length > 0 && (
                        <PaginationNav links={attendanceData.links} only={["attendances"]} />
                    )}
                </div>
            </div>

            <AlertDialog open={showDeleteConfirm} onOpenChange={setShowDeleteConfirm}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete Selected Records</AlertDialogTitle>
                        <AlertDialogDescription>
                            Are you sure you want to delete {selectedRecords.length} attendance record
                            {selectedRecords.length === 1 ? "" : "s"}? This action cannot be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={confirmBulkDelete} className="bg-destructive text-destructive-foreground hover:bg-destructive/90">
                            Delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <Dialog open={noteDialogOpen} onOpenChange={setNoteDialogOpen}>
                <DialogContent className="max-w-[90vw] sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Attendance Notes</DialogTitle>
                        <DialogDescription>
                            {selectedNoteRecord && (
                                <span>{selectedNoteRecord.user.name} - {formatDate(selectedNoteRecord.shift_date)}</span>
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    {selectedNoteRecord && (
                        <div className="space-y-4">
                            {/* Employee Notes */}
                            <div>
                                <h4 className="text-sm font-semibold mb-2">Employee Notes</h4>
                                <div className="p-3 bg-muted rounded-md">
                                    <p className="text-sm whitespace-pre-wrap">
                                        {selectedNoteRecord.notes || <span className="text-muted-foreground italic">No notes</span>}
                                    </p>
                                </div>
                            </div>
                            {/* Admin Verification Notes */}
                            <div>
                                <h4 className="text-sm font-semibold mb-2">Admin Verification Notes</h4>
                                <div className="p-3 bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-800 rounded-md">
                                    <p className="text-sm whitespace-pre-wrap">
                                        {selectedNoteRecord.verification_notes || <span className="text-muted-foreground italic">Not verified yet</span>}
                                    </p>
                                </div>
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
