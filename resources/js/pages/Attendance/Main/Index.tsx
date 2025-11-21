import React, { useEffect, useState } from "react";
import { Head, router, usePage } from "@inertiajs/react";
import AppLayout from "@/layouts/app-layout";
import { useFlashMessage, usePageLoading, usePageMeta } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { LoadingOverlay } from "@/components/LoadingOverlay";
import { type SharedData, type UserRole } from "@/types";
import { Can } from "@/components/authorization";
import { usePermission } from "@/hooks/useAuthorization";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue
} from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import PaginationNav, { PaginationLink } from "@/components/pagination-nav";
import { CheckCircle, AlertCircle, Trash2, Check } from "lucide-react";
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

interface EmployeeSchedule {
    id: number;
    shift_type: string;
    scheduled_time_in: string;
    scheduled_time_out: string;
    site?: Site;
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
    filters?: {
        search?: string;
        status?: string;
        start_date?: string;
        end_date?: string;
        user_id?: string;
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

const formatDateTime = (value: string | undefined, timeFormat: '12' | '24' = '24') => {
    if (!value) return "-";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return `${date.toLocaleDateString()} ${date.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit", hour12: timeFormat === '12' })}`;
};

const formatDate = (dateString: string) => {
    if (!dateString) return "-";

    // For date-only strings (YYYY-MM-DD), split and create date in local timezone
    // to avoid timezone conversion issues
    const dateParts = dateString.split('T')[0].split('-'); // Get YYYY-MM-DD part
    if (dateParts.length === 3) {
        const year = parseInt(dateParts[0]);
        const month = parseInt(dateParts[1]) - 1; // Month is 0-indexed
        const day = parseInt(dateParts[2]);

        const date = new Date(year, month, day);

        if (Number.isNaN(date.getTime())) {
            return dateString; // Return original if parsing fails
        }

        return date.toLocaleDateString('en-US', {
            weekday: 'short',
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }

    // Fallback for other date formats
    const date = new Date(dateString);
    if (Number.isNaN(date.getTime())) {
        return dateString;
    }

    return date.toLocaleDateString('en-US', {
        weekday: 'short',
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
};

const formatTime = (timeString: string, timeFormat: '12' | '24' = '24') => {
    if (!timeString) return "-";

    // Parse time string (HH:MM:SS format)
    const [hours, minutes] = timeString.split(':');

    if (!hours || !minutes) return timeString;

    if (timeFormat === '12') {
        const hour = parseInt(hours);
        const period = hour >= 12 ? 'PM' : 'AM';
        const hour12 = hour % 12 || 12;
        return `${hour12}:${minutes} ${period}`;
    }

    return `${hours}:${minutes}`;
};

const getStatusBadge = (status: string) => {
    const statusConfig: Record<string, { label: string; className: string }> = {
        on_time: { label: "On Time", className: "bg-green-500" },
        tardy: { label: "Tardy", className: "bg-yellow-500" },
        half_day_absence: { label: "Half Day", className: "bg-orange-500" },
        advised_absence: { label: "Advised Absence", className: "bg-blue-500" },
        on_leave: { label: "On Leave", className: "bg-blue-600" },
        ncns: { label: "NCNS", className: "bg-red-500" },
        undertime: { label: "Undertime", className: "bg-orange-400" },
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
    const { attendances, filters, auth } = usePage<PageProps>().props;
    const timeFormat = auth.user.time_format || '24';

    // Ensure we have proper data structure
    const attendanceData = {
        data: Array.isArray(attendances?.data) ? attendances.data : [],
        links: Array.isArray(attendances?.links) ? attendances.links : [],
        meta: attendances?.meta ?? DEFAULT_META,
    };
    const appliedFilters = filters ?? {};

    const { title, breadcrumbs } = usePageMeta({
        title: "Attendance",
        breadcrumbs: [{ title: "Attendance", href: "/attendance" }],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();
    const { can } = usePermission();

    const [loading, setLoading] = useState(false);
    const [search, setSearch] = useState(appliedFilters.search || "");
    const [debouncedSearch, setDebouncedSearch] = useState(appliedFilters.search || "");
    const [statusFilter, setStatusFilter] = useState(appliedFilters.status || "all");
    const [startDate, setStartDate] = useState(appliedFilters.start_date || "");
    const [endDate, setEndDate] = useState(appliedFilters.end_date || "");
    const [needsVerification, setNeedsVerification] = useState(appliedFilters.needs_verification || false);
    const [selectedRecords, setSelectedRecords] = useState<number[]>([]);
    const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);

    // Update local state when filters prop changes (e.g., when navigating back or pagination)
    useEffect(() => {
        setSearch(appliedFilters.search || "");
        setDebouncedSearch(appliedFilters.search || "");
        setStatusFilter(appliedFilters.status || "all");
        setStartDate(appliedFilters.start_date || "");
        setEndDate(appliedFilters.end_date || "");
        setNeedsVerification(appliedFilters.needs_verification || false);
        // Don't clear selections when filters change
    }, [appliedFilters.search, appliedFilters.status, appliedFilters.start_date, appliedFilters.end_date, appliedFilters.needs_verification]);

    useEffect(() => {
        const timer = setTimeout(() => setDebouncedSearch(search), 500);
        return () => clearTimeout(timer);
    }, [search]);

    const isInitialMount = React.useRef(true);
    const userId = auth.user?.id;
    const userRole = auth.user?.role;
    // Roles that should only see their own attendance records
    const restrictedRoles: UserRole[] = ['Agent', 'IT', 'Utility'];
    const isRestrictedUser = userRole && restrictedRoles.includes(userRole);

    useEffect(() => {
        if (isInitialMount.current) {
            isInitialMount.current = false;
            return;
        }

        const params: Record<string, string> = {};

        // For Agent, IT, and Utility roles, automatically filter to their own records
        if (isRestrictedUser && userId) {
            params.user_id = userId.toString();
        } else if (debouncedSearch) {
            // Only allow search for users with higher permissions
            params.search = debouncedSearch;
        }

        if (statusFilter !== "all") params.status = statusFilter;
        if (startDate) params.start_date = startDate;
        if (endDate) params.end_date = endDate;
        if (needsVerification) params.needs_verification = "1";

        setLoading(true);
        router.get("/attendance", params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => setLoading(false),
        });
    }, [debouncedSearch, statusFilter, startDate, endDate, needsVerification, isRestrictedUser, userId]);

    const showClearFilters =
        statusFilter !== "all" ||
        Boolean(startDate) ||
        Boolean(endDate) ||
        needsVerification ||
        Boolean(search);

    const clearFilters = () => {
        setSearch("");
        setStatusFilter("all");
        setStartDate("");
        setEndDate("");
        setNeedsVerification(false);
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
        router.delete("/attendance/bulk-delete", {
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

        router.post("/attendance/bulk-quick-approve", {
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
        router.post(`/attendance/${recordId}/quick-approve`, {}, {
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
                    {!isRestrictedUser && (
                        <div className="w-full">
                            <Input
                                type="search"
                                placeholder="Search employee name..."
                                value={search}
                                onChange={event => setSearch(event.target.value)}
                                className="w-full"
                            />
                        </div>
                    )}

                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                        <Select value={statusFilter} onValueChange={setStatusFilter}>
                            <SelectTrigger className="w-full">
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
                            </SelectContent>
                        </Select>

                        <div className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                            <span className="text-muted-foreground text-xs">From:</span>
                            <input
                                type="date"
                                value={startDate}
                                onChange={event => setStartDate(event.target.value)}
                                className="w-full bg-transparent outline-none text-sm"
                            />
                        </div>

                        <div className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                            <span className="text-muted-foreground text-xs">To:</span>
                            <input
                                type="date"
                                value={endDate}
                                onChange={event => setEndDate(event.target.value)}
                                className="w-full bg-transparent outline-none text-sm"
                            />
                        </div>

                        <Button
                            variant={needsVerification ? "default" : "outline"}
                            onClick={() => setNeedsVerification(!needsVerification)}
                            className="w-full"
                        >
                            <AlertCircle className="mr-2 h-4 w-4" />
                            Needs Verification
                        </Button>

                        {showClearFilters && (
                            <Button variant="outline" onClick={clearFilters} className="w-full">
                                Clear Filters
                            </Button>
                        )}
                    </div>

                    <div className="flex flex-col sm:flex-row sm:flex-wrap gap-3 sm:justify-between min-w-0">
                        <div className="flex flex-col sm:flex-row gap-3">
                            <Button
                                onClick={() => router.get("/attendance/calendar")}
                                className="w-full sm:w-auto"
                                variant="outline"
                            >
                                <CalendarIcon className="mr-2 h-4 w-4" />
                                Calendar View
                            </Button>
                            <Can permission="attendance.create">
                                <Button
                                    onClick={() => router.get("/attendance/create")}
                                    className="w-full sm:w-auto"
                                    variant="default"
                                >
                                    Add Manual Attendance
                                </Button>
                            </Can>
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
                            <Can permission="attendance.import">
                                <Button
                                    onClick={() => router.get("/attendance/import")}
                                    className="w-full sm:w-auto"
                                >
                                    Import Biometric File (.txt)
                                </Button>
                            </Can>
                            <Can permission="attendance.review">
                                <Button
                                    onClick={() => router.get("/attendance/review")}
                                    className="w-full sm:w-auto"
                                    variant="outline"
                                >
                                    <AlertCircle className="mr-2 h-4 w-4" />
                                    Review Flagged Records
                                </Button>
                            </Can>
                        </div>
                    </div>
                </div>

                <div className="flex justify-between items-center text-sm">
                    <div className="text-muted-foreground">
                        Showing {attendanceData.meta.total === 0 ? '0' : `${attendanceData.data.length}`} of {attendanceData.meta.total} record
                        {attendanceData.meta.total === 1 ? "" : "s"}
                        {showClearFilters ? " (filtered)" : ""}
                    </div>
                    {selectedRecords.length > 0 && (
                        <div className="text-sm">
                            <Badge variant="secondary" className="font-normal">
                                {selectedRecords.length} record{selectedRecords.length === 1 ? "" : "s"} selected
                            </Badge>
                        </div>
                    )}
                </div>

                <div className="hidden md:block shadow rounded-md overflow-hidden">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
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
                                    <TableHead>Shift Date</TableHead>
                                    <TableHead>Shift Type</TableHead>
                                    <TableHead>Schedule</TableHead>
                                    <TableHead>Time In</TableHead>
                                    <TableHead>Time Out</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Tardy/UT/OT</TableHead>
                                    <TableHead>Verified</TableHead>
                                    {can('attendance.approve') && <TableHead>Actions</TableHead>}
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
                                                    {formatTime(record.employee_schedule.scheduled_time_in, timeFormat)} - {formatTime(record.employee_schedule.scheduled_time_out, timeFormat)}
                                                </div>
                                            ) : (
                                                "-"
                                            )}
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {formatDateTime(record.actual_time_in, timeFormat)}
                                            {record.bio_in_site && record.is_cross_site_bio && (
                                                <div className="text-xs text-muted-foreground">
                                                    @ {record.bio_in_site.name}
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {formatDateTime(record.actual_time_out, timeFormat)}
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
                                            {record.admin_verified ? (
                                                <CheckCircle className="h-4 w-4 text-green-500" />
                                            ) : (
                                                <span className="text-muted-foreground text-xs">Pending</span>
                                            )}
                                        </TableCell>
                                        {can('attendance.approve') && (
                                            <TableCell>
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
                                                            onClick={() => window.open(`/attendance/review?verify=${record.id}`, '_blank')}
                                                            className="h-8 text-amber-600 border-amber-600"
                                                        >
                                                            <AlertCircle className="h-3 w-3 mr-1" />
                                                            Review
                                                        </Button>
                                                    ) : null}
                                                </Can>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                                {attendanceData.data.length === 0 && !loading && (
                                    <TableRow>
                                        <TableCell colSpan={11} className="h-24 text-center text-muted-foreground">
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
                                                <span>{formatTime(record.employee_schedule.scheduled_time_in, timeFormat)} - {formatTime(record.employee_schedule.scheduled_time_out, timeFormat)}</span>
                                            ) : (
                                                "-"
                                            )}
                                        </div>
                                        <div>
                                            <span className="font-medium">Time In:</span> {formatDateTime(record.actual_time_in, timeFormat)}
                                            {record.bio_in_site && record.is_cross_site_bio && (
                                                <span className="text-muted-foreground"> @ {record.bio_in_site.name}</span>
                                            )}
                                        </div>
                                        <div>
                                            <span className="font-medium">Time Out:</span> {formatDateTime(record.actual_time_out, timeFormat)}
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
                                                        onClick={() => window.open(`/attendance/review?verify=${record.id}`, '_blank')}
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
        </AppLayout>
    );
}
