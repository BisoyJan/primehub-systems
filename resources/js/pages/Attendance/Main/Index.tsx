import React, { useEffect, useState } from "react";
import { Head, router, usePage } from "@inertiajs/react";
import AppLayout from "@/layouts/app-layout";
import { useFlashMessage, usePageLoading, usePageMeta } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { LoadingOverlay } from "@/components/LoadingOverlay";
import { type SharedData, type UserRole } from "@/types";
import { formatTime, formatDate, formatDateTime, formatWorkDuration, formatTimeAdjustment } from "@/lib/utils";
import { Can } from "@/components/authorization";
import { usePermission } from "@/hooks/useAuthorization";
import { Button } from "@/components/ui/button";
import { DatePicker } from "@/components/ui/date-picker";
import { MultiSelectFilter, parseMultiSelectParam, multiSelectToParam } from "@/components/multi-select-filter";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue
} from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import { getStatusBadges, getShiftTypeBadge } from "@/components/attendance";
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@/components/ui/tooltip";
import PaginationNav, { PaginationLink } from "@/components/pagination-nav";
import { AlertCircle, Trash2, RefreshCw, Search, Play, Pause, Edit, Upload, Check, X } from "lucide-react";
import { Calendar as CalendarIcon } from "lucide-react";
import { Checkbox } from "@/components/ui/checkbox";
import { Card, CardContent } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
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

interface LeaveRequest {
    id: number;
    leave_type: string;
    start_date: string;
    end_date: string;
    status: string;
    days_requested: number;
    admin_review_notes?: string;
    hr_review_notes?: string;
}

interface AttendanceRecord {
    id: number;
    user: User;
    employee_schedule?: EmployeeSchedule;
    leave_request?: LeaveRequest;
    shift_date: string;
    actual_time_in?: string;
    actual_time_out?: string;
    total_minutes_worked?: number;
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
        verified_status?: string;
    };
    [key: string]: unknown;
}

const DEFAULT_META: Meta = {
    current_page: 1,
    last_page: 1,
    per_page: 70,
    total: 0,
};

// formatDateTime, formatDate, formatTime are now imported from @/lib/utils

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
    // Multi-select state for employees
    const [selectedUserIds, setSelectedUserIds] = useState<string[]>(() =>
        parseMultiSelectParam(appliedFilters.user_id)
    );
    const [selectedSiteId, setSelectedSiteId] = useState(appliedFilters.site_id || "");
    // Multi-select state for campaigns - auto-select Team Lead's campaign if no filter is applied
    const [selectedCampaignIds, setSelectedCampaignIds] = useState<string[]>(() => {
        const fromFilter = parseMultiSelectParam(appliedFilters.campaign_id);
        if (fromFilter.length > 0) return fromFilter;
        if (isTeamLead && teamLeadCampaignId) return [teamLeadCampaignId.toString()];
        return [];
    });
    // Multi-select state for status
    const [selectedStatuses, setSelectedStatuses] = useState<string[]>(() =>
        parseMultiSelectParam(appliedFilters.status)
    );
    const [startDate, setStartDate] = useState(appliedFilters.start_date || "");
    const [endDate, setEndDate] = useState(appliedFilters.end_date || "");
    const [needsVerification, setNeedsVerification] = useState(appliedFilters.needs_verification || false);
    const [verifiedFilter, setVerifiedFilter] = useState(appliedFilters.verified_status || "all");

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
        setSelectedUserIds(parseMultiSelectParam(appliedFilters.user_id));
        setSelectedSiteId(appliedFilters.site_id || "");
        // For Team Leads, default to their campaign if no filter is applied
        const campaignFromFilter = parseMultiSelectParam(appliedFilters.campaign_id);
        if (campaignFromFilter.length > 0) {
            setSelectedCampaignIds(campaignFromFilter);
        } else if (isTeamLead && teamLeadCampaignId) {
            setSelectedCampaignIds([teamLeadCampaignId.toString()]);
        } else {
            setSelectedCampaignIds([]);
        }
        setSelectedStatuses(parseMultiSelectParam(appliedFilters.status));
        setStartDate(appliedFilters.start_date || "");
        setEndDate(appliedFilters.end_date || "");
        setNeedsVerification(appliedFilters.needs_verification || false);
        setVerifiedFilter(appliedFilters.verified_status || "all");
        // Don't clear selections when filters change
    }, [appliedFilters.user_id, appliedFilters.site_id, appliedFilters.campaign_id, appliedFilters.status, appliedFilters.start_date, appliedFilters.end_date, appliedFilters.needs_verification, appliedFilters.verified_status, isTeamLead, teamLeadCampaignId]);

    const userId = auth.user?.id;
    // Roles that should only see their own attendance records
    const restrictedRoles: UserRole[] = ['Agent', 'IT', 'Utility'];
    const isRestrictedUser = userRole && restrictedRoles.includes(userRole);

    const handleSearch = () => {
        const params: Record<string, string> = {};

        // For Agent, IT, and Utility roles, automatically filter to their own records
        if (isRestrictedUser && userId) {
            params.user_id = userId.toString();
        } else if (selectedUserIds.length > 0) {
            // Only allow user filter for users with higher permissions
            params.user_id = multiSelectToParam(selectedUserIds);
        }

        if (selectedSiteId) params.site_id = selectedSiteId;
        if (selectedCampaignIds.length > 0) params.campaign_id = multiSelectToParam(selectedCampaignIds);
        if (selectedStatuses.length > 0) params.status = multiSelectToParam(selectedStatuses);
        if (startDate) params.start_date = startDate;
        if (endDate) params.end_date = endDate;
        if (needsVerification) params.needs_verification = "1";
        if (verifiedFilter && verifiedFilter !== "all") params.verified_status = verifiedFilter;

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
            } else if (selectedUserIds.length > 0) {
                params.user_id = multiSelectToParam(selectedUserIds);
            }

            if (selectedSiteId) params.site_id = selectedSiteId;
            if (selectedCampaignIds.length > 0) params.campaign_id = multiSelectToParam(selectedCampaignIds);
            if (selectedStatuses.length > 0) params.status = multiSelectToParam(selectedStatuses);
            if (startDate) params.start_date = startDate;
            if (endDate) params.end_date = endDate;
            if (needsVerification) params.needs_verification = "1";
            if (verifiedFilter && verifiedFilter !== "all") params.verified_status = verifiedFilter;

            router.get(attendanceIndex().url, params, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['attendances'],
                onSuccess: () => setLastRefresh(new Date()),
            });
        }, 30000);

        return () => clearInterval(interval);
    }, [autoRefreshEnabled, selectedUserIds, selectedSiteId, selectedCampaignIds, selectedStatuses, startDate, endDate, needsVerification, verifiedFilter, isRestrictedUser, userId]);

    const showClearFilters =
        selectedStatuses.length > 0 ||
        Boolean(startDate) ||
        Boolean(endDate) ||
        needsVerification ||
        selectedUserIds.length > 0 ||
        Boolean(selectedSiteId) ||
        selectedCampaignIds.length > 0 ||
        (verifiedFilter && verifiedFilter !== "all");

    const clearFilters = () => {
        setSelectedUserIds([]);
        setSelectedSiteId("");
        setSelectedCampaignIds([]);
        setSelectedStatuses([]);
        setStartDate("");
        setEndDate("");
        setNeedsVerification(false);
        setVerifiedFilter("all");

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
        // 3. No overtime OR overtime is already approved (threshold: 30 minutes)
        return (
            record.status === 'on_time' &&
            !record.admin_verified &&
            (!record.overtime_minutes || record.overtime_minutes <= 30 || record.overtime_approved)
        );
    };

    const needsReview = (record: AttendanceRecord) => {
        // Needs review if on_time with unapproved overtime (threshold: 30 minutes)
        return (
            record.status === 'on_time' &&
            record.overtime_minutes &&
            record.overtime_minutes > 30 &&
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

                {/* Search and Filters */}
                <Card>
                    <CardContent className="pt-6">
                        <div className="space-y-4">
                            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                                {/* Employee Multi-Select */}
                                {!isRestrictedUser && (
                                    <div className="space-y-2">
                                        <Label>Employee</Label>
                                        <MultiSelectFilter
                                            options={users.map(u => ({ label: u.name, value: u.id.toString() }))}
                                            value={selectedUserIds}
                                            onChange={setSelectedUserIds}
                                            placeholder="Select employees..."
                                            emptyMessage="No employee found."
                                            className="w-full min-h-9"
                                        />
                                    </div>
                                )}

                                {/* Status Multi-Select */}
                                <div className="space-y-2">
                                    <Label>Status</Label>
                                    <MultiSelectFilter
                                        options={[
                                            { label: "On Time", value: "on_time" },
                                            { label: "Tardy", value: "tardy" },
                                            { label: "Half Day Absence", value: "half_day_absence" },
                                            { label: "Advised Absence", value: "advised_absence" },
                                            { label: "NCNS", value: "ncns" },
                                            { label: "Undertime", value: "undertime" },
                                            { label: "Undertime (>1hr)", value: "undertime_more_than_hour" },
                                            { label: "Failed Bio In", value: "failed_bio_in" },
                                            { label: "Failed Bio Out", value: "failed_bio_out" },
                                            { label: "Needs Review", value: "needs_manual_review" },
                                            { label: "On Leave", value: "on_leave" },
                                        ]}
                                        value={selectedStatuses}
                                        onChange={setSelectedStatuses}
                                        placeholder="Select status..."
                                        emptyMessage="No status found."
                                        className="w-full min-h-9"
                                    />
                                </div>

                                {/* Site Filter - Single Select */}
                                <div className="space-y-2">
                                    <Label>Site</Label>
                                    <Select value={selectedSiteId || "all"} onValueChange={(value) => setSelectedSiteId(value === "all" ? "" : value)}>
                                        <SelectTrigger>
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
                                </div>

                                {/* Campaign Multi-Select */}
                                <div className="space-y-2">
                                    <Label>Campaign</Label>
                                    <MultiSelectFilter
                                        options={campaigns.map(c => ({
                                            label: c.name + (isTeamLead && teamLeadCampaignId === c.id ? ' (Your Campaign)' : ''),
                                            value: c.id.toString()
                                        }))}
                                        value={selectedCampaignIds}
                                        onChange={setSelectedCampaignIds}
                                        placeholder="Select campaigns..."
                                        emptyMessage="No campaign found."
                                        className="w-full min-h-9"
                                    />
                                </div>

                                {/* Verification Status Filter */}
                                <div className="space-y-2">
                                    <Label>Verification Status</Label>
                                    <Select value={verifiedFilter} onValueChange={setVerifiedFilter}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="All Records" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">All Records</SelectItem>
                                            <SelectItem value="pending">Pending Verification</SelectItem>
                                            <SelectItem value="verified">Verified</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                {/* Date From */}
                                <div className="space-y-2">
                                    <Label>Date From</Label>
                                    <DatePicker
                                        value={startDate}
                                        onChange={(value) => {
                                            setStartDate(value);
                                            if (!endDate || value > endDate) {
                                                setEndDate(value);
                                            }
                                        }}
                                        placeholder="Select date"
                                    />
                                </div>

                                {/* Date To */}
                                <div className="space-y-2">
                                    <Label>Date To</Label>
                                    <DatePicker
                                        value={endDate}
                                        onChange={(value) => setEndDate(value)}
                                        placeholder="Select date"
                                        minDate={startDate || undefined}
                                        defaultMonth={startDate || undefined}
                                    />
                                </div>

                                {/* Needs Verification Toggle */}
                                <div className="space-y-2">
                                    <Label>Needs Review</Label>
                                    <Button
                                        variant={needsVerification ? "default" : "outline"}
                                        onClick={() => setNeedsVerification(!needsVerification)}
                                        className="w-full justify-start"
                                    >
                                        <AlertCircle className="h-4 w-4 mr-2" />
                                        {needsVerification ? "Showing Flagged" : "Show Flagged Only"}
                                    </Button>
                                </div>
                            </div>

                            {/* Action Buttons */}
                            <div className="flex flex-wrap gap-2">
                                <Button onClick={handleSearch}>
                                    <Search className="h-4 w-4 mr-2" />
                                    Search
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
                                {showClearFilters && (
                                    <Button variant="outline" onClick={clearFilters}>
                                        <X className="h-4 w-4 mr-2" />
                                        Clear Filters
                                    </Button>
                                )}
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Actions Row */}
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
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
                    <div className="flex flex-wrap gap-2 justify-end">
                        {selectedRecords.length > 0 && getEligibleQuickApproveCount() > 0 && (
                            <Can permission="attendance.approve">
                                <Button
                                    onClick={handleBulkQuickApprove}
                                    variant="outline"
                                    size="sm"
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
                                    size="sm"
                                >
                                    <Trash2 className="mr-2 h-4 w-4" />
                                    Delete ({selectedRecords.length})
                                </Button>
                            </Can>
                        )}
                        <Button
                            onClick={() => router.get(attendanceCalendar().url)}
                            size="sm"
                            variant="default"
                        >
                            <CalendarIcon className="mr-2 h-4 w-4" />
                            Calendar View
                        </Button>
                        <Can permission="attendance.create">
                            <Button
                                onClick={() => router.get(attendanceCreate().url)}
                                size="sm"
                                variant="outline"
                            >
                                <Edit className="mr-2 h-4 w-4" />
                                Manual Attendance
                            </Button>
                        </Can>
                        <Can permission="attendance.import">
                            <Button
                                onClick={() => router.get(attendanceImport().url)}
                                size="sm"
                                variant="outline"
                            >
                                <Upload className="mr-2 h-4 w-4" />
                                Import Biometric
                            </Button>
                        </Can>
                        <Can permission="attendance.create">
                            <Button
                                onClick={() => router.get(attendanceDailyRoster().url)}
                                size="sm"
                                variant="outline"
                            >
                                <CalendarIcon className="mr-2 h-4 w-4" />
                                Daily Roster
                            </Button>
                        </Can>
                        <Can permission="attendance.review">
                            <Button
                                onClick={() => router.get(attendanceReview().url)}
                                size="sm"
                                variant="outline"
                            >
                                <AlertCircle className="mr-2 h-4 w-4" />
                                Review Flagged
                            </Button>
                        </Can>
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
                                    <TableHead>Site / Campaign</TableHead>
                                    <TableHead>Shift Date / Type</TableHead>
                                    <TableHead>Schedule</TableHead>
                                    <TableHead>Time In / Out</TableHead>
                                    <TableHead>Total Hours</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Tardy/UT/OT</TableHead>
                                    <TableHead>Notes</TableHead>
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
                                        <TableCell>
                                            <div>
                                                <div className="font-medium truncate">
                                                    {record.employee_schedule?.site?.name || record.user.active_schedule?.site?.name || 'No site'}
                                                    {record.is_cross_site_bio && (
                                                        <Badge variant="outline" className="ml-2 text-orange-600 border-orange-600 text-xs">
                                                            Cross-Site {record.bio_in_site?.name && `@ ${record.bio_in_site.name}`}
                                                        </Badge>
                                                    )}
                                                </div>
                                                {(record.employee_schedule?.campaign?.name || record.user.active_schedule?.campaign?.name) && (
                                                    <div className="text-xs text-muted-foreground">
                                                        {record.employee_schedule?.campaign?.name || record.user.active_schedule?.campaign?.name}
                                                    </div>
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <div>
                                                <div>{formatDate(record.shift_date)}</div>
                                                <div className="text-xs text-muted-foreground">
                                                    {record.employee_schedule?.shift_type ? (
                                                        getShiftTypeBadge(record.employee_schedule.shift_type)
                                                    ) : (
                                                        "-"
                                                    )}
                                                </div>
                                            </div>
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
                                            <div>
                                                <div>
                                                    {formatDateTime(record.actual_time_in) || '-'}
                                                </div>
                                                <div className="text-muted-foreground">
                                                    {formatDateTime(record.actual_time_out) || '-'}
                                                </div>
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            <span className="font-medium">{formatWorkDuration(record.total_minutes_worked)}</span>
                                        </TableCell>
                                        <TableCell>{getStatusBadges(record)}</TableCell>
                                        <TableCell className="text-sm">
                                            <div className="space-y-1">
                                                {record.tardy_minutes !== null && record.tardy_minutes !== undefined && record.tardy_minutes > 0 && (
                                                    <div className="text-xs text-orange-600">
                                                        +{formatTimeAdjustment(record.tardy_minutes)} T
                                                    </div>
                                                )}
                                                {record.undertime_minutes !== null && record.undertime_minutes !== undefined && record.undertime_minutes > 0 && (
                                                    <div className="text-xs text-orange-600">
                                                        {formatTimeAdjustment(record.undertime_minutes)} UT
                                                    </div>
                                                )}
                                                {(!record.tardy_minutes || record.tardy_minutes === 0) &&
                                                    (!record.undertime_minutes || record.undertime_minutes === 0) &&
                                                    (!record.overtime_minutes || record.overtime_minutes <= 30) && (
                                                        <div>-</div>
                                                    )}
                                                {record.overtime_minutes !== null && record.overtime_minutes !== undefined && record.overtime_minutes > 30 && (
                                                    <div className={`text-xs ${record.overtime_approved ? 'text-green-600' : 'text-blue-600'}`}>
                                                        +{formatTimeAdjustment(record.overtime_minutes)} OT
                                                        {record.overtime_approved && ' ✓'}
                                                    </div>
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <NotesDisplay record={record} />
                                        </TableCell>
                                        {(can('attendance.approve') || can('attendance.verify') || can('attendance.delete')) && (
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    <Can permission="attendance.approve">
                                                        {canQuickApprove(record) ? (
                                                            <TooltipProvider>
                                                                <Tooltip>
                                                                    <TooltipTrigger asChild>
                                                                        <Button
                                                                            size="icon"
                                                                            variant="outline"
                                                                            onClick={() => handleQuickApprove(record.id)}
                                                                            className="h-8 w-8"
                                                                        >
                                                                            <Check className="h-4 w-4 text-green-600" />
                                                                        </Button>
                                                                    </TooltipTrigger>
                                                                    <TooltipContent>Approve</TooltipContent>
                                                                </Tooltip>
                                                            </TooltipProvider>
                                                        ) : needsReview(record) ? (
                                                            <TooltipProvider>
                                                                <Tooltip>
                                                                    <TooltipTrigger asChild>
                                                                        <Button
                                                                            size="icon"
                                                                            variant="outline"
                                                                            onClick={() => window.open(attendanceReview({ query: { verify: record.id } }).url, '_blank')}
                                                                            className="h-8 w-8 border-amber-600 text-amber-600"
                                                                        >
                                                                            <AlertCircle className="h-4 w-4" />
                                                                        </Button>
                                                                    </TooltipTrigger>
                                                                    <TooltipContent>Review</TooltipContent>
                                                                </Tooltip>
                                                            </TooltipProvider>
                                                        ) : null}
                                                    </Can>
                                                    <Can permission="attendance.verify">
                                                        {!record.admin_verified && (
                                                            <TooltipProvider>
                                                                <Tooltip>
                                                                    <TooltipTrigger asChild>
                                                                        <Button
                                                                            size="icon"
                                                                            variant="outline"
                                                                            onClick={() => window.open(attendanceReview({ query: { verify: record.id } }).url, '_blank')}
                                                                            className="h-8 w-8"
                                                                        >
                                                                            <Edit className="h-4 w-4" />
                                                                        </Button>
                                                                    </TooltipTrigger>
                                                                    <TooltipContent>Verify</TooltipContent>
                                                                </Tooltip>
                                                            </TooltipProvider>
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
                                                        Cross-Site {record.bio_in_site?.name && `@ ${record.bio_in_site.name}`}
                                                    </Badge>
                                                )}
                                            </div>
                                            <div className="text-sm text-muted-foreground flex items-center gap-2">
                                                {formatDate(record.shift_date)}
                                                {record.employee_schedule?.shift_type && (
                                                    <span>{getShiftTypeBadge(record.employee_schedule.shift_type)}</span>
                                                )}
                                            </div>
                                        </div>
                                        {getStatusBadges(record)}
                                    </div>

                                    <div className="space-y-2 text-sm mt-3">
                                        <div>
                                            <span className="font-medium">Site / Campaign:</span>{" "}
                                            {record.employee_schedule?.site?.name || record.user.active_schedule?.site?.name || 'No site'}
                                            {record.is_cross_site_bio && (
                                                <Badge variant="outline" className="ml-2 text-orange-600 border-orange-600 text-xs">
                                                    Cross-Site {record.bio_in_site?.name && `@ ${record.bio_in_site.name}`}
                                                </Badge>
                                            )}
                                            {(record.employee_schedule?.campaign?.name || record.user.active_schedule?.campaign?.name) && (
                                                <span className="text-muted-foreground">
                                                    {" / "}{record.employee_schedule?.campaign?.name || record.user.active_schedule?.campaign?.name}
                                                </span>
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
                                            <span className="font-medium">Time In / Out:</span>{" "}
                                            {formatDateTime(record.actual_time_in) || '-'}
                                            {" → "}
                                            {formatDateTime(record.actual_time_out) || '-'}
                                        </div>
                                        <div>
                                            <span className="font-medium">Total Hours:</span>{" "}
                                            <span className="font-medium">{formatWorkDuration(record.total_minutes_worked)}</span>
                                        </div>
                                        {record.tardy_minutes !== null && record.tardy_minutes !== undefined && record.tardy_minutes > 0 && (
                                            <div>
                                                <span className="font-medium">Tardy:</span>{" "}
                                                <span className="text-orange-600">
                                                    +{formatTimeAdjustment(record.tardy_minutes)} T
                                                </span>
                                            </div>
                                        )}
                                        {record.undertime_minutes !== null && record.undertime_minutes !== undefined && record.undertime_minutes > 0 && (
                                            <div>
                                                <span className="font-medium">Undertime:</span>{" "}
                                                <span className="text-orange-600">
                                                    {formatTimeAdjustment(record.undertime_minutes)} UT
                                                </span>
                                            </div>
                                        )}
                                        {record.overtime_minutes !== null && record.overtime_minutes !== undefined && record.overtime_minutes > 30 && (
                                            <div>
                                                <span className="font-medium">Overtime:</span>{" "}
                                                <span className={record.overtime_approved ? 'text-green-600' : 'text-blue-600'}>
                                                    +{formatTimeAdjustment(record.overtime_minutes)} OT
                                                    {record.overtime_approved && ' (Approved)'}
                                                </span>
                                            </div>
                                        )}
                                        {(record.notes || record.verification_notes) && (
                                            <div>
                                                <span className="font-medium">Notes:</span>{" "}
                                                <NotesDisplay record={record} />
                                            </div>
                                        )}
                                    </div>

                                    {/* Mobile Action Buttons */}
                                    {(can('attendance.approve') || can('attendance.verify')) && (
                                        <div className="mt-3 pt-3 border-t flex flex-wrap gap-2">
                                            <Can permission="attendance.approve">
                                                {canQuickApprove(record) && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => handleQuickApprove(record.id)}
                                                        className="flex-1"
                                                    >
                                                        <Check className="h-3 w-3 mr-1" />
                                                        Approve
                                                    </Button>
                                                )}
                                                {needsReview(record) && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => window.open(attendanceReview({ query: { verify: record.id } }).url, '_blank')}
                                                        className="flex-1 text-amber-600 border-amber-600"
                                                    >
                                                        <AlertCircle className="h-3 w-3 mr-1" />
                                                        Review
                                                    </Button>
                                                )}
                                            </Can>
                                            <Can permission="attendance.verify">
                                                {!record.admin_verified && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => window.open(attendanceReview({ query: { verify: record.id } }).url, '_blank')}
                                                        className="flex-1"
                                                    >
                                                        <Edit className="h-3 w-3 mr-1" />
                                                        Verify
                                                    </Button>
                                                )}
                                            </Can>
                                        </div>
                                    )}
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
                                        {selectedNoteRecord.verification_notes ||
                                            selectedNoteRecord.leave_request?.admin_review_notes ||
                                            selectedNoteRecord.leave_request?.hr_review_notes ||
                                            <span className="text-muted-foreground italic">Not verified yet</span>}
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
