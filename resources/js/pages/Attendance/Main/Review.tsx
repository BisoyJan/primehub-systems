import React, { useState, useEffect } from "react";
import { Head, router, useForm, usePage } from "@inertiajs/react";
import AppLayout from "@/layouts/app-layout";
import { useFlashMessage, usePageLoading, usePageMeta } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { LoadingOverlay } from "@/components/LoadingOverlay";
import { type SharedData } from "@/types";
import { formatDateTime, formatDate, formatTime } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue
} from "@/components/ui/select";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogFooter,
} from "@/components/ui/dialog";
import {
    Card,
    CardContent,
} from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import PaginationNav, { PaginationLink } from "@/components/pagination-nav";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from "@/components/ui/command";
import { AlertCircle, Check, CheckCircle, ChevronsUpDown, Edit, Moon, Search, UserCheck, X } from "lucide-react";
import { TimeInput } from "@/components/ui/time-input";

interface Site {
    id: number;
    name: string;
}

interface EmployeeSchedule {
    id: number;
    shift_type: string;
    scheduled_time_in: string;
    scheduled_time_out: string;
    grace_period_minutes?: number;
    site?: Site;
}

interface User {
    id: number;
    first_name: string;
    last_name: string;
    name: string;
    active_schedule?: EmployeeSchedule; // User's active schedule as fallback
}

interface LeaveRequest {
    id: number;
    leave_type: string;
    start_date: string;
    end_date: string;
    status: string;
    days_requested: number;
}

interface AttendanceRecord {
    id: number;
    user: User;
    employee_schedule?: EmployeeSchedule;
    leave_request?: LeaveRequest;
    leave_request_id?: number;
    shift_date: string;
    actual_time_in?: string;
    actual_time_out?: string;
    status: string;
    secondary_status?: string;
    tardy_minutes?: number;
    undertime_minutes?: number;
    overtime_minutes?: number;
    overtime_approved?: boolean;
    overtime_approved_at?: string;
    overtime_approved_by?: number;
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
    employees?: User[];
    sites?: Site[];
    filters?: {
        search?: string;
        user_id?: string;
        status?: string;
        date_from?: string;
        date_to?: string;
        verified?: string;
        site_id?: string;
    };
    [key: string]: unknown;
}

const DEFAULT_META: Meta = {
    current_page: 1,
    last_page: 1,
    per_page: 50,
    total: 0,
};

// formatDateTime, formatDate are now imported from @/lib/utils

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
        failed_bio_in: { label: "Failed Bio In", className: "bg-purple-500" },
        failed_bio_out: { label: "Failed Bio Out", className: "bg-purple-500" },
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
                <span title="Has warnings - see Issue column">
                    <AlertCircle className="h-4 w-4 text-amber-500" />
                </span>
            )}
        </div>
    );
};

/**
 * Calculate the suggested attendance status based on actual times and employee schedule.
 *
 * Status Rules:
 * - on_time: Arrived within grace period
 * - tardy: Arrived 15+ minutes late but within grace period threshold
 * - half_day_absence: Arrived more than grace period minutes late
 * - undertime: Left more than 60 minutes early
 * - failed_bio_in: Has time out but no time in
 * - failed_bio_out: Has time in but no time out
 * - ncns: No time in or time out (no call no show)
 * - present_no_bio: Explicitly marked present without biometric data
 *
 * @returns { status: string, secondaryStatus?: string, tardyMinutes?: number, undertimeMinutes?: number, overtimeMinutes?: number }
 */
const calculateSuggestedStatus = (
    schedule: EmployeeSchedule | undefined,
    shiftDate: string,
    actualTimeIn: string,
    actualTimeOut: string
): {
    status: string;
    secondaryStatus?: string;
    tardyMinutes?: number;
    undertimeMinutes?: number;
    overtimeMinutes?: number;
    reason: string;
} => {
    const hasBioIn = !!actualTimeIn;
    const hasBioOut = !!actualTimeOut;

    // No bio at all
    if (!hasBioIn && !hasBioOut) {
        return { status: 'ncns', reason: 'No time in or time out recorded' };
    }

    // Has time out but no time in
    if (!hasBioIn && hasBioOut) {
        return { status: 'failed_bio_in', reason: 'Missing time in record' };
    }

    // No schedule - can't calculate status accurately
    if (!schedule) {
        if (hasBioIn && !hasBioOut) {
            return { status: 'failed_bio_out', reason: 'No schedule found, missing time out' };
        }
        return { status: 'on_time', reason: 'No schedule found, defaulting to on time' };
    }

    // Parse dates and times
    const timeInDate = hasBioIn ? new Date(actualTimeIn) : null;
    const timeOutDate = hasBioOut ? new Date(actualTimeOut) : null;
    const shiftDateObj = new Date(shiftDate + 'T00:00:00');

    // Build scheduled time in (on shift date)
    const [schedInHour, schedInMin] = schedule.scheduled_time_in.split(':').map(Number);
    const scheduledTimeIn = new Date(shiftDateObj);
    scheduledTimeIn.setHours(schedInHour, schedInMin, 0, 0);

    // Build scheduled time out (may be next day for night shift)
    const [schedOutHour, schedOutMin] = schedule.scheduled_time_out.split(':').map(Number);
    const scheduledTimeOut = new Date(shiftDateObj);
    scheduledTimeOut.setHours(schedOutHour, schedOutMin, 0, 0);

    // Check if it's a night shift (time out is next day)
    const isNightShift = schedule.shift_type === 'night_shift' ||
        (schedOutHour < schedInHour || (schedOutHour === schedInHour && schedOutMin <= schedInMin));
    if (isNightShift) {
        scheduledTimeOut.setDate(scheduledTimeOut.getDate() + 1);
    }

    const gracePeriodMinutes = schedule.grace_period_minutes ?? 15;
    let isTardy = false;
    let isHalfDay = false;
    let hasUndertime = false;
    let tardyMinutes: number | undefined;
    let undertimeMinutes: number | undefined;
    let overtimeMinutes: number | undefined;

    // Calculate tardiness (late arrival)
    if (timeInDate) {
        const scheduledWithGrace = new Date(scheduledTimeIn);
        scheduledWithGrace.setMinutes(scheduledWithGrace.getMinutes() + gracePeriodMinutes);

        // Calculate how many minutes late
        const diffMinutes = Math.floor((timeInDate.getTime() - scheduledTimeIn.getTime()) / (1000 * 60));

        if (diffMinutes > gracePeriodMinutes) {
            // More than grace period = half day absence
            isHalfDay = true;
            tardyMinutes = diffMinutes;
        } else if (diffMinutes >= 1) {
            // 1+ minutes late but within grace period = tardy
            isTardy = true;
            tardyMinutes = diffMinutes;
        }
    }

    // Calculate undertime (early leave) and overtime (late leave)
    if (timeOutDate) {
        const diffFromScheduledOut = Math.floor((timeOutDate.getTime() - scheduledTimeOut.getTime()) / (1000 * 60));

        if (diffFromScheduledOut < -60) {
            // Left more than 60 minutes early = undertime_more_than_hour
            hasUndertime = true;
            undertimeMinutes = Math.abs(diffFromScheduledOut);
        } else if (diffFromScheduledOut < 0) {
            // Left early but less than 60 minutes = undertime
            hasUndertime = true;
            undertimeMinutes = Math.abs(diffFromScheduledOut);
        } else if (diffFromScheduledOut > 60) {
            // Worked more than 60 minutes overtime
            overtimeMinutes = diffFromScheduledOut;
        }
    }

    // Determine status based on violations
    let status: string;
    let secondaryStatus: string | undefined;
    let reason: string;

    if (!hasBioOut) {
        // Has time in but no time out
        if (isHalfDay) {
            status = 'half_day_absence';
            secondaryStatus = 'failed_bio_out';
            reason = `Arrived ${tardyMinutes} minutes late (more than ${gracePeriodMinutes}min grace period), missing time out`;
        } else if (isTardy) {
            status = 'tardy';
            secondaryStatus = 'failed_bio_out';
            reason = `Arrived ${tardyMinutes} minutes late, missing time out`;
        } else {
            status = 'failed_bio_out';
            reason = 'Missing time out record';
        }
    } else if (isHalfDay && hasUndertime) {
        status = 'half_day_absence';
        // Determine undertime status based on minutes
        secondaryStatus = undertimeMinutes && undertimeMinutes > 60 ? 'undertime_more_than_hour' : 'undertime';
        reason = `Arrived ${tardyMinutes} minutes late AND left ${undertimeMinutes} minutes early`;
    } else if (isHalfDay) {
        status = 'half_day_absence';
        reason = `Arrived ${tardyMinutes} minutes late (more than ${gracePeriodMinutes}min grace period)`;
    } else if (isTardy && hasUndertime) {
        status = 'tardy';
        // Determine undertime status based on minutes
        secondaryStatus = undertimeMinutes && undertimeMinutes > 60 ? 'undertime_more_than_hour' : 'undertime';
        reason = `Arrived ${tardyMinutes} minutes late AND left ${undertimeMinutes} minutes early`;
    } else if (isTardy) {
        status = 'tardy';
        reason = `Arrived ${tardyMinutes} minutes late`;
    } else if (hasUndertime) {
        // Determine undertime status based on minutes
        status = undertimeMinutes && undertimeMinutes > 60 ? 'undertime_more_than_hour' : 'undertime';
        reason = `Left ${undertimeMinutes} minutes early`;
    } else {
        status = 'on_time';
        reason = 'Arrived on time';
    }

    return { status, secondaryStatus, tardyMinutes, undertimeMinutes, overtimeMinutes, reason };
};

export default function AttendanceReview() {
    const { attendances, employees, sites = [], filters } = usePage<PageProps>().props;
    const attendanceData = {
        data: attendances?.data ?? [],
        links: attendances?.links ?? [],
        meta: attendances?.meta ?? DEFAULT_META,
    };

    // Employee search popover state
    const [isEmployeePopoverOpen, setIsEmployeePopoverOpen] = useState(false);
    const [employeeSearchQuery, setEmployeeSearchQuery] = useState("");
    const [selectedUserId, setSelectedUserId] = useState(filters?.user_id || "");
    const [selectedSiteId, setSelectedSiteId] = useState(filters?.site_id || "");

    const { title, breadcrumbs } = usePageMeta({
        title: "Review Flagged Records",
        breadcrumbs: [
            { title: "Attendance", href: "/attendance" },
            { title: "Review", href: "/attendance/review" },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    const [selectedRecord, setSelectedRecord] = useState<AttendanceRecord | null>(null);
    const [isDialogOpen, setIsDialogOpen] = useState(false);
    const [isBatchDialogOpen, setIsBatchDialogOpen] = useState(false);
    const [selectedRecords, setSelectedRecords] = useState<Set<number>>(new Set());
    const [selectedStatus, setSelectedStatus] = useState<string | null>(null);
    const [selectedSecondaryStatus, setSelectedSecondaryStatus] = useState<string | null | undefined>(null);
    const [warningsDialogRecord, setWarningsDialogRecord] = useState<AttendanceRecord | null>(null);
    const [isWarningsDialogOpen, setIsWarningsDialogOpen] = useState(false);
    const [highlightedRecordId, setHighlightedRecordId] = useState<number | null>(null);
    const highlightedRowRef = React.useRef<HTMLTableRowElement | HTMLDivElement>(null);
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

    // Auto status suggestion state
    const [suggestedStatus, setSuggestedStatus] = useState<{
        status: string;
        secondaryStatus?: string;
        reason: string;
    } | null>(null);
    const [isStatusManuallyOverridden, setIsStatusManuallyOverridden] = useState(false);

    // Search state
    const [statusFilter, setStatusFilter] = useState(filters?.status || "all");
    const [verifiedFilter, setVerifiedFilter] = useState(filters?.verified || "all");
    const [dateFrom, setDateFrom] = useState(filters?.date_from || "");
    const [dateTo, setDateTo] = useState(filters?.date_to || "");

    // Filter employees based on search query
    const filteredEmployees = (employees ?? []).filter((user) =>
        user.name.toLowerCase().includes(employeeSearchQuery.toLowerCase())
    );

    // Get selected employee name for display
    const selectedEmployeeName = selectedUserId
        ? employees?.find((u) => String(u.id) === selectedUserId)?.name || "Unknown"
        : "All Employees";

    const { data, setData, post, processing, errors, reset } = useForm({
        status: "",
        secondary_status: "",
        actual_time_in: "",
        actual_time_out: "",
        notes: "",
        verification_notes: "",
        overtime_approved: false,
    });

    const { data: batchData, setData: setBatchData, post: postBatch, processing: batchProcessing, errors: batchErrors, reset: resetBatch } = useForm({
        record_ids: [] as number[],
        status: "",
        secondary_status: "",
        verification_notes: "",
        overtime_approved: false,
    });

    // Partial approval dialog state (for night shift completion)
    const [isPartialDialogOpen, setIsPartialDialogOpen] = useState(false);
    const [partialRecord, setPartialRecord] = useState<AttendanceRecord | null>(null);

    const { data: partialData, setData: setPartialData, post: postPartial, processing: partialProcessing, errors: partialErrors, reset: resetPartial } = useForm({
        actual_time_out: "",
        verification_notes: "",
        status: "",
    });

    // Helper to check if a record is eligible for partial approval (night shift with missing time out)
    const isNightShiftMissingTimeOut = (record: AttendanceRecord): boolean => {
        const schedule = record.employee_schedule || record.user?.active_schedule;
        if (!schedule) return false;

        const isNightShift = schedule.shift_type === 'night_shift' ||
            (schedule.scheduled_time_in && schedule.scheduled_time_out &&
             schedule.scheduled_time_out < schedule.scheduled_time_in);

        const hasTimeIn = record.actual_time_in !== undefined && record.actual_time_in !== null;
        const hasNoTimeOut = record.actual_time_out === undefined || record.actual_time_out === null;

        return Boolean(isNightShift) && hasTimeIn && hasNoTimeOut;
    };

    // Open partial approval dialog
    const openPartialDialog = (record: AttendanceRecord) => {
        setPartialRecord(record);
        const schedule = record.employee_schedule || record.user?.active_schedule;

        // Calculate the expected time out date (next day for night shift)
        const shiftDate = new Date(record.shift_date);
        const nextDay = new Date(shiftDate);
        nextDay.setDate(nextDay.getDate() + 1);

        const timeOutDate = nextDay.toISOString().split('T')[0];
        const timeOutTime = schedule?.scheduled_time_out?.slice(0, 5) || "07:00";

        setPartialData({
            actual_time_out: `${timeOutDate}T${timeOutTime}`,
            verification_notes: "",
            status: record.status || "",
        });
        setIsPartialDialogOpen(true);
    };

    // Handle partial approval submit
    const handlePartialApprove = (e: React.FormEvent) => {
        e.preventDefault();
        if (!partialRecord) return;

        postPartial(`/attendance/${partialRecord.id}/partial-approve`, {
            preserveScroll: true,
            onSuccess: () => {
                setIsPartialDialogOpen(false);
                resetPartial();
                setPartialRecord(null);
            },
        });
    };

    const handleSearch = () => {
        router.get(
            "/attendance/review",
            {
                user_id: selectedUserId,
                site_id: selectedSiteId,
                status: statusFilter === "all" ? "" : statusFilter,
                verified: verifiedFilter === "all" ? "" : verifiedFilter,
                date_from: dateFrom,
                date_to: dateTo,
            },
            {
                preserveState: true,
                preserveScroll: true,
            }
        );
    };

    const handleClearFilters = () => {
        setSelectedUserId("");
        setEmployeeSearchQuery("");
        setSelectedSiteId("");
        setStatusFilter("all");
        setVerifiedFilter("all");
        setDateFrom("");
        setDateTo("");
        router.get("/attendance/review", {}, { preserveState: true });
    };

    // Helper to convert UTC datetime to local datetime string for input
    const toLocalDateTimeString = (utcDateString: string | undefined) => {
        if (!utcDateString) return "";
        const date = new Date(utcDateString);
        if (Number.isNaN(date.getTime())) return "";

        // Get local year, month, day, hours, minutes
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');

        return `${year}-${month}-${day}T${hours}:${minutes}`;
    };

    const openVerifyDialog = (record: AttendanceRecord) => {
        setSelectedRecord(record);

        const timeIn = toLocalDateTimeString(record.actual_time_in);
        const timeOut = toLocalDateTimeString(record.actual_time_out);

        // Use attendance's schedule or fallback to user's active schedule
        const schedule = record.employee_schedule || record.user?.active_schedule;

        // Calculate suggested status based on times and schedule
        const suggestion = calculateSuggestedStatus(
            schedule,
            record.shift_date,
            timeIn,
            timeOut
        );

        setSuggestedStatus(suggestion);
        setIsStatusManuallyOverridden(false);

        setData({
            status: suggestion.status, // Start with suggested status
            secondary_status: suggestion.secondaryStatus || "",
            actual_time_in: timeIn,
            actual_time_out: timeOut,
            notes: record.notes || "",
            verification_notes: record.verification_notes || "",
            overtime_approved: record.overtime_approved || false,
        });
        setIsDialogOpen(true);
    };

    // Recalculate suggested status when times change
    const recalculateSuggestedStatus = (timeIn: string, timeOut: string) => {
        if (!selectedRecord) return;

        // Use attendance's schedule or fallback to user's active schedule
        const schedule = selectedRecord.employee_schedule || selectedRecord.user?.active_schedule;

        const suggestion = calculateSuggestedStatus(
            schedule,
            selectedRecord.shift_date,
            timeIn,
            timeOut
        );

        setSuggestedStatus(suggestion);

        // Only auto-update status if user hasn't manually overridden it
        if (!isStatusManuallyOverridden) {
            setData('status', suggestion.status);
            setData('secondary_status', suggestion.secondaryStatus || "");
        }
    };

    // Handle status change - mark as manually overridden if different from suggested
    const handleStatusChange = (newStatus: string) => {
        setData('status', newStatus);
        if (suggestedStatus && newStatus !== suggestedStatus.status) {
            setIsStatusManuallyOverridden(true);
        } else {
            setIsStatusManuallyOverridden(false);
        }
    };

    // Reset to suggested status
    const resetToSuggestedStatus = () => {
        if (suggestedStatus) {
            setData('status', suggestedStatus.status);
            setData('secondary_status', suggestedStatus.secondaryStatus || "");
            setIsStatusManuallyOverridden(false);
        }
    };

    // Recalculate suggested status when times change
    useEffect(() => {
        if (!selectedRecord || !isDialogOpen) return;

        // Debounce the recalculation
        const timer = setTimeout(() => {
            recalculateSuggestedStatus(data.actual_time_in, data.actual_time_out);
        }, 300);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data.actual_time_in, data.actual_time_out, selectedRecord?.id, isDialogOpen]);

    // Clear highlight when dialog closes
    useEffect(() => {
        if (!isDialogOpen && highlightedRecordId) {
            setHighlightedRecordId(null);
        }
    }, [isDialogOpen, highlightedRecordId]);

    // Auto-open dialog if verify parameter is present in URL
    useEffect(() => {
        const urlParams = new URLSearchParams(window.location.search);
        const verifyId = urlParams.get('verify');

        if (verifyId) {
            const recordId = parseInt(verifyId);
            const recordToVerify = attendanceData.data.find(r => r.id === recordId);

            // Set highlighted record (will remain until dialog is closed)
            setHighlightedRecordId(recordId);

            if (recordToVerify) {
                // Wait for loading to complete, then scroll and open dialog
                const checkLoading = setInterval(() => {
                    if (!isPageLoading) {
                        clearInterval(checkLoading);

                        // Scroll to highlighted row
                        setTimeout(() => {
                            highlightedRowRef.current?.scrollIntoView({
                                behavior: 'smooth',
                                block: 'center'
                            });

                            // Open dialog after scroll
                            setTimeout(() => {
                                openVerifyDialog(recordToVerify);
                            }, 600);
                        }, 300);
                    }
                }, 100);

                // Remove the verify parameter from URL without page reload
                window.history.replaceState({}, '', window.location.pathname + window.location.hash);

                // Cleanup
                return () => clearInterval(checkLoading);
            }
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [attendanceData.data, isPageLoading]);

    const handleVerify = (e: React.FormEvent) => {
        e.preventDefault();
        if (!selectedRecord) return;

        post(`/attendance/${selectedRecord.id}/verify`, {
            preserveScroll: true,
            onSuccess: () => {
                setIsDialogOpen(false);
                reset();
                setSelectedRecord(null);
            },
        });
    };

    // Handle "Flag as Reported" - directly submit with on_time status
    const handleFlagAsReported = () => {
        if (!selectedRecord) return;

        const verificationNote = data.verification_notes
            ? `${data.verification_notes}${data.verification_notes.endsWith('.') ? '' : '.'} Employee reported to office during approved leave.`
            : 'Employee reported to office during approved leave.';

        // Use router.post to submit directly with the modified data
        router.post(`/attendance/${selectedRecord.id}/verify`, {
            status: 'on_time',
            actual_time_in: data.actual_time_in,
            actual_time_out: data.actual_time_out,
            notes: data.notes,
            verification_notes: verificationNote,
            overtime_approved: data.overtime_approved,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setIsDialogOpen(false);
                reset();
                setSelectedRecord(null);
            },
        });
    };

    const openWarningsDialog = (record: AttendanceRecord) => {
        setWarningsDialogRecord(record);
        setIsWarningsDialogOpen(true);
    };

    const closeWarningsDialog = () => {
        setIsWarningsDialogOpen(false);
        setWarningsDialogRecord(null);
    };

    const hasOvertimeRecords = () => {
        return attendanceData.data
            .filter(r => selectedRecords.has(r.id))
            .some(record => record.overtime_minutes && record.overtime_minutes > 0);
    };

    const handleSelectAll = () => {
        if (selectedRecords.size === attendanceData.data.length) {
            setSelectedRecords(new Set());
            setSelectedStatus(null);
            setSelectedSecondaryStatus(null);
        } else {
            // Select all records with the exact same primary AND secondary status as the first record
            const firstRecord = attendanceData.data[0];
            if (firstRecord) {
                const recordsWithSameStatus = attendanceData.data.filter(r =>
                    r.status === firstRecord.status &&
                    r.secondary_status === firstRecord.secondary_status
                );
                setSelectedRecords(new Set(recordsWithSameStatus.map(r => r.id)));
                setSelectedStatus(firstRecord.status);
                setSelectedSecondaryStatus(firstRecord.secondary_status);
            }
        }
    };

    const handleSelectRecord = (id: number, status: string, secondaryStatus?: string) => {
        const newSelected = new Set(selectedRecords);

        if (newSelected.has(id)) {
            // Deselecting a record
            newSelected.delete(id);
            // If no records are selected, reset the selected status
            if (newSelected.size === 0) {
                setSelectedStatus(null);
                setSelectedSecondaryStatus(null);
            }
        } else {
            // Selecting a record
            if (selectedStatus === null) {
                // First selection - set both primary and secondary status
                newSelected.add(id);
                setSelectedStatus(status);
                setSelectedSecondaryStatus(secondaryStatus);
            } else if (selectedStatus === status && selectedSecondaryStatus === secondaryStatus) {
                // Only allow selection if BOTH primary AND secondary status match exactly
                newSelected.add(id);
            }
            // If status combination doesn't match, do nothing (don't add the record)
        }
        setSelectedRecords(newSelected);
    };

    const openBatchVerifyDialog = () => {
        setBatchData({
            record_ids: Array.from(selectedRecords),
            status: selectedStatus || "on_time", // Use selectedStatus if available, otherwise default to on_time
            verification_notes: "",
            overtime_approved: false,
        });
        setIsBatchDialogOpen(true);
    };

    const handleBatchVerify = (e: React.FormEvent) => {
        e.preventDefault();
        if (selectedRecords.size === 0) return;

        postBatch('/attendance/batch-verify', {
            preserveScroll: true,
            onSuccess: () => {
                setIsBatchDialogOpen(false);
                resetBatch();
                setSelectedRecords(new Set());
                setSelectedStatus(null);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                <LoadingOverlay isLoading={isPageLoading} />

                <PageHeader
                    title="Review Flagged Records"
                    description="Review and verify attendance records that need attention"
                />

                {/* Search and Filters */}
                <Card>
                    <CardContent className="pt-6">
                        <div className="space-y-4">
                            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
                                {/* Employee Search */}
                                <div className="space-y-2">
                                    <Label>Employee</Label>
                                    <Popover open={isEmployeePopoverOpen} onOpenChange={setIsEmployeePopoverOpen}>
                                        <PopoverTrigger asChild>
                                            <Button
                                                variant="outline"
                                                role="combobox"
                                                aria-expanded={isEmployeePopoverOpen}
                                                className="w-full justify-between font-normal"
                                            >
                                                <span className="truncate">
                                                    {selectedUserId ? selectedEmployeeName : "All Employees"}
                                                </span>
                                                <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                            </Button>
                                        </PopoverTrigger>
                                        <PopoverContent className="w-full p-0" align="start">
                                            <Command shouldFilter={false}>
                                                <CommandInput
                                                    placeholder="Search employee..."
                                                    value={employeeSearchQuery}
                                                    onValueChange={setEmployeeSearchQuery}
                                                />
                                                <CommandList>
                                                    <CommandEmpty>No employee found.</CommandEmpty>
                                                    <CommandGroup>
                                                        <CommandItem
                                                            value="all"
                                                            onSelect={() => {
                                                                setSelectedUserId("");
                                                                setIsEmployeePopoverOpen(false);
                                                                setEmployeeSearchQuery("");
                                                            }}
                                                            className="cursor-pointer"
                                                        >
                                                            <Check
                                                                className={`mr-2 h-4 w-4 ${!selectedUserId ? "opacity-100" : "opacity-0"}`}
                                                            />
                                                            All Employees
                                                        </CommandItem>
                                                        {filteredEmployees.map((user) => (
                                                            <CommandItem
                                                                key={user.id}
                                                                value={user.name}
                                                                onSelect={() => {
                                                                    setSelectedUserId(String(user.id));
                                                                    setIsEmployeePopoverOpen(false);
                                                                    setEmployeeSearchQuery("");
                                                                }}
                                                                className="cursor-pointer"
                                                            >
                                                                <Check
                                                                    className={`mr-2 h-4 w-4 ${selectedUserId === String(user.id) ? "opacity-100" : "opacity-0"}`}
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

                                {/* Status Filter */}
                                <div className="space-y-2">
                                    <Label htmlFor="status-filter">Status</Label>
                                    <Select value={statusFilter} onValueChange={setStatusFilter}>
                                        <SelectTrigger id="status-filter">
                                            <SelectValue placeholder="All statuses" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">All Statuses</SelectItem>
                                            <SelectItem value="ncns">NCNS</SelectItem>
                                            <SelectItem value="failed_bio_in">Failed Bio In</SelectItem>
                                            <SelectItem value="failed_bio_out">Failed Bio Out</SelectItem>
                                            <SelectItem value="half_day_absence">Half Day Absence</SelectItem>
                                            <SelectItem value="tardy">Tardy</SelectItem>
                                            <SelectItem value="undertime">Undertime</SelectItem>
                                            <SelectItem value="on_leave">On Leave</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                {/* Site Filter */}
                                <div className="space-y-2">
                                    <Label htmlFor="site-filter">Site</Label>
                                    <Select value={selectedSiteId || "all"} onValueChange={(value) => setSelectedSiteId(value === "all" ? "" : value)}>
                                        <SelectTrigger id="site-filter">
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

                                {/* Verified Filter */}
                                <div className="space-y-2">
                                    <Label htmlFor="verified-filter">Verification Status</Label>
                                    <Select value={verifiedFilter} onValueChange={setVerifiedFilter}>
                                        <SelectTrigger id="verified-filter">
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
                                    <Label htmlFor="date-from">Date From</Label>
                                    <Input
                                        id="date-from"
                                        type="date"
                                        value={dateFrom}
                                        onChange={(e) => setDateFrom(e.target.value)}
                                    />
                                </div>

                                {/* Date To */}
                                <div className="space-y-2">
                                    <Label htmlFor="date-to">Date To</Label>
                                    <Input
                                        id="date-to"
                                        type="date"
                                        value={dateTo}
                                        onChange={(e) => setDateTo(e.target.value)}
                                    />
                                </div>
                            </div>

                            {/* Action Buttons */}
                            <div className="flex gap-2">
                                <Button onClick={handleSearch}>
                                    <Search className="h-4 w-4 mr-2" />
                                    Search
                                </Button>
                                <Button variant="outline" onClick={handleClearFilters}>
                                    <X className="h-4 w-4 mr-2" />
                                    Clear Filters
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <div className="flex justify-between items-center">
                    <div className="text-sm text-muted-foreground">
                        Showing {attendanceData.data.length} of {attendanceData.meta.total} record
                        {attendanceData.meta.total === 1 ? "" : "s"} needing verification
                        {selectedRecords.size > 0 && (
                            <span className="ml-2 font-semibold text-primary">
                                ({selectedRecords.size} selected)
                            </span>
                        )}
                    </div>
                    <div className="flex gap-2">
                        {selectedRecords.size > 0 && (
                            <Button onClick={openBatchVerifyDialog}>
                                <CheckCircle className="h-4 w-4 mr-2" />
                                Verify {selectedRecords.size} Record{selectedRecords.size === 1 ? "" : "s"}
                            </Button>
                        )}
                        <Button variant="outline" onClick={() => router.get("/attendance")}>
                            Back to Attendance
                        </Button>
                    </div>
                </div>

                {attendanceData.data.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-12 border rounded-lg bg-card">
                        <CheckCircle className="h-12 w-12 text-green-500 mb-4" />
                        <h3 className="text-lg font-semibold mb-2">All Clear!</h3>
                        <p className="text-muted-foreground">No attendance records need verification at this time.</p>
                    </div>
                ) : (
                    <>
                        <div className="hidden md:block shadow rounded-md overflow-hidden">
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="w-12">
                                                <Input
                                                    type="checkbox"
                                                    checked={selectedRecords.size === attendanceData.data.length && attendanceData.data.length > 0}
                                                    onChange={handleSelectAll}
                                                    className="h-4 w-4 rounded border-gray-300"
                                                />
                                            </TableHead>
                                            <TableHead>Employee</TableHead>
                                            <TableHead>Shift Date</TableHead>
                                            <TableHead>Assigned Site</TableHead>
                                            <TableHead>Time In</TableHead>
                                            <TableHead>Time Out</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Tardy/UT/OT</TableHead>
                                            <TableHead>Notes</TableHead>
                                            <TableHead>Issue</TableHead>
                                            <TableHead>Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {attendanceData.data.map(record => (
                                            <TableRow
                                                key={record.id}
                                                ref={highlightedRecordId === record.id ? highlightedRowRef as React.RefObject<HTMLTableRowElement> : null}
                                                className={`transition-colors duration-300 ${highlightedRecordId === record.id
                                                    ? 'bg-blue-100 dark:bg-blue-900/30'
                                                    : ''
                                                    }`}
                                            >
                                                <TableCell>
                                                    <Input
                                                        type="checkbox"
                                                        checked={selectedRecords.has(record.id)}
                                                        onChange={() => handleSelectRecord(record.id, record.status, record.secondary_status)}
                                                        disabled={selectedStatus !== null && (record.status !== selectedStatus || record.secondary_status !== selectedSecondaryStatus)}
                                                        className="h-4 w-4 rounded border-gray-300 disabled:opacity-30 disabled:cursor-not-allowed"
                                                    />
                                                </TableCell>
                                                <TableCell className="font-medium">{record.user.name}</TableCell>
                                                <TableCell>{formatDate(record.shift_date)}</TableCell>
                                                <TableCell>
                                                    {record.employee_schedule?.site?.name || "-"}
                                                    {record.is_cross_site_bio && (
                                                        <Badge variant="outline" className="ml-2 text-orange-600 border-orange-600">
                                                            Cross-Site
                                                        </Badge>
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
                                                    <div className="space-y-1">
                                                        {(record.status === 'failed_bio_in' || record.secondary_status === 'failed_bio_in') && (
                                                            <span className="text-sm text-red-600 block">No Time In</span>
                                                        )}
                                                        {(record.status === 'failed_bio_out' || record.secondary_status === 'failed_bio_out') && (
                                                            <span className="text-sm text-red-600 block">No Time Out</span>
                                                        )}
                                                        {(record.status === 'ncns' || record.secondary_status === 'ncns') && (
                                                            <span className="text-sm text-red-600 block">No Show</span>
                                                        )}
                                                        {(record.status === 'tardy' || record.secondary_status === 'tardy') && (
                                                            <span className="text-sm text-orange-600 block">Late Arrival</span>
                                                        )}
                                                        {(record.status === 'undertime' || record.secondary_status === 'undertime') && (
                                                            <span className="text-sm text-orange-600 block">Early Leave</span>
                                                        )}
                                                        {(record.status === 'half_day_absence' || record.secondary_status === 'half_day_absence') && (
                                                            <span className="text-sm text-orange-600 block">Half Day</span>
                                                        )}
                                                        {record.status === 'needs_manual_review' && (
                                                            <span className="text-sm text-amber-600 font-medium block">
                                                                <AlertCircle className="inline h-3 w-3 mr-1" />
                                                                Suspicious Pattern
                                                            </span>
                                                        )}
                                                        {record.warnings && record.warnings.length > 0 && (
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => openWarningsDialog(record)}
                                                                className="mt-1 h-auto py-1 px-2 text-amber-700 hover:text-amber-900 hover:bg-amber-50"
                                                            >
                                                                <AlertCircle className="h-3 w-3 mr-1" />
                                                                View {record.warnings.length} Warning{record.warnings.length > 1 ? 's' : ''}
                                                            </Button>
                                                        )}
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex gap-1">
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() => openVerifyDialog(record)}
                                                        >
                                                            <Edit className="h-4 w-4 mr-1" />
                                                            Verify
                                                        </Button>
                                                        {isNightShiftMissingTimeOut(record) && (
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                onClick={() => openPartialDialog(record)}
                                                                className="text-purple-600 border-purple-300 hover:bg-purple-50"
                                                                title="Complete night shift by adding time out"
                                                            >
                                                                <Moon className="h-4 w-4" />
                                                            </Button>
                                                        )}
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </div>

                        {/* Mobile View */}
                        <div className="md:hidden space-y-4">
                            {attendanceData.data.map(record => (
                                <div
                                    key={record.id}
                                    ref={highlightedRecordId === record.id ? highlightedRowRef as React.RefObject<HTMLDivElement> : null}
                                    className={`bg-card border rounded-lg p-4 shadow-sm space-y-3 transition-colors duration-300 ${highlightedRecordId === record.id
                                        ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/30'
                                        : ''
                                        }`}
                                >
                                    <div className="flex justify-between items-start">
                                        <Input
                                            type="checkbox"
                                            checked={selectedRecords.has(record.id)}
                                            onChange={() => handleSelectRecord(record.id, record.status, record.secondary_status)}
                                            disabled={selectedStatus !== null && (record.status !== selectedStatus || record.secondary_status !== selectedSecondaryStatus)}
                                            className="h-5 w-5 rounded border-gray-300 mt-1 disabled:opacity-30 disabled:cursor-not-allowed"
                                        />
                                        <div className="flex-1 mx-3">
                                            <div className="text-lg font-semibold">{record.user.name}</div>
                                            <div className="text-sm text-muted-foreground">
                                                {formatDate(record.shift_date)}
                                            </div>
                                        </div>
                                        {getStatusBadges(record)}
                                    </div>

                                    <div className="space-y-2 text-sm">
                                        <div>
                                            <span className="font-medium">Assigned Site:</span>{" "}
                                            {record.employee_schedule?.site?.name || "-"}
                                            {record.is_cross_site_bio && (
                                                <Badge variant="outline" className="ml-2 text-orange-600 border-orange-600 text-xs">
                                                    Cross-Site
                                                </Badge>
                                            )}
                                        </div>
                                        <div>
                                            <span className="font-medium">Time In:</span>{" "}
                                            {formatDateTime(record.actual_time_in)}
                                            {record.bio_in_site && record.is_cross_site_bio && (
                                                <span className="text-muted-foreground"> @ {record.bio_in_site.name}</span>
                                            )}
                                        </div>
                                        <div>
                                            <span className="font-medium">Time Out:</span>{" "}
                                            {formatDateTime(record.actual_time_out)}
                                            {record.bio_out_site && record.is_cross_site_bio && (
                                                <span className="text-muted-foreground"> @ {record.bio_out_site.name}</span>
                                            )}
                                        </div>
                                        <div>
                                            <span className="font-medium">Issues:</span>
                                            <div className="mt-1 space-y-1">
                                                {(record.status === 'failed_bio_in' || record.secondary_status === 'failed_bio_in') && (
                                                    <span className="text-red-600 block">• No Time In</span>
                                                )}
                                                {(record.status === 'failed_bio_out' || record.secondary_status === 'failed_bio_out') && (
                                                    <span className="text-red-600 block">• No Time Out</span>
                                                )}
                                                {(record.status === 'ncns' || record.secondary_status === 'ncns') && (
                                                    <span className="text-red-600 block">• No Show</span>
                                                )}
                                                {(record.status === 'tardy' || record.secondary_status === 'tardy') && (
                                                    <span className="text-orange-600 block">• Late Arrival</span>
                                                )}
                                                {(record.status === 'undertime' || record.secondary_status === 'undertime') && (
                                                    <span className="text-orange-600 block">• Early Leave</span>
                                                )}
                                                {(record.status === 'half_day_absence' || record.secondary_status === 'half_day_absence') && (
                                                    <span className="text-orange-600 block">• Half Day</span>
                                                )}
                                            </div>
                                        </div>
                                        {((record.tardy_minutes && record.tardy_minutes > 0) || (record.undertime_minutes && record.undertime_minutes > 0) || (record.overtime_minutes && record.overtime_minutes > 0)) && (
                                            <div>
                                                <span className="font-medium">Time Adjustments:</span>
                                                <div className="mt-1 space-y-1">
                                                    {record.tardy_minutes && record.tardy_minutes > 0 && (
                                                        <span className="text-orange-600 block">
                                                            • Tardy: +{record.tardy_minutes >= 60 ? `${Math.floor(record.tardy_minutes / 60)}h` : `${record.tardy_minutes}m`} T
                                                        </span>
                                                    )}
                                                    {record.undertime_minutes && record.undertime_minutes > 0 && (
                                                        <span className="text-orange-600 block">
                                                            • Undertime: {record.undertime_minutes >= 60 ? `${Math.floor(record.undertime_minutes / 60)}h` : `${record.undertime_minutes}m`} UT
                                                        </span>
                                                    )}
                                                    {record.overtime_minutes && record.overtime_minutes > 0 && (
                                                        <span className={`block ${record.overtime_approved ? 'text-green-600' : 'text-blue-600'}`}>
                                                            • Overtime: +{record.overtime_minutes >= 60 ? `${Math.floor(record.overtime_minutes / 60)}h` : `${record.overtime_minutes}m`} OT
                                                            {record.overtime_approved && ' (Approved)'}
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        )}
                                        {(record.notes || record.verification_notes) && (
                                            <div>
                                                <span className="font-medium">Notes:</span>{" "}
                                                <NotesDisplay record={record} />
                                            </div>
                                        )}
                                    </div>

                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => openVerifyDialog(record)}
                                        className="w-full"
                                    >
                                        <Edit className="h-4 w-4 mr-2" />
                                        Verify Record
                                    </Button>
                                    {isNightShiftMissingTimeOut(record) && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => openPartialDialog(record)}
                                            className="w-full mt-2 text-purple-600 border-purple-300 hover:bg-purple-50"
                                        >
                                            <Moon className="h-4 w-4 mr-2" />
                                            Complete Night Shift
                                        </Button>
                                    )}
                                </div>
                            ))}
                        </div>

                        <div className="flex justify-center mt-4">
                            {attendanceData.links && attendanceData.links.length > 0 && (
                                <PaginationNav links={attendanceData.links} only={["attendances"]} />
                            )}
                        </div>
                    </>
                )}
            </div>

            {/* Batch Verification Dialog */}
            <Dialog open={isBatchDialogOpen} onOpenChange={setIsBatchDialogOpen}>
                <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Batch Verify Attendance Records</DialogTitle>
                        <DialogDescription>
                            Verify {selectedRecords.size} attendance record{selectedRecords.size === 1 ? "" : "s"} at once with common settings
                            {selectedStatus && (
                                <span className="block mt-1">
                                    Current Status: <span className="font-semibold">{selectedStatus.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}</span>
                                </span>
                            )}
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={handleBatchVerify} className="space-y-4">
                        {/* Selected Records Summary */}
                        <div className="bg-muted p-4 rounded-md">
                            <h4 className="font-semibold text-sm mb-2">Selected Records ({selectedRecords.size})</h4>
                            <div className="max-h-32 overflow-y-auto text-sm space-y-1">
                                {attendanceData.data
                                    .filter(r => selectedRecords.has(r.id))
                                    .map(record => (
                                        <div key={record.id} className="text-muted-foreground">
                                            • {record.user.name} - {formatDate(record.shift_date)}
                                        </div>
                                    ))}
                            </div>
                        </div>

                        {/* Common Status */}
                        <div className="space-y-2">
                            <Label htmlFor="batch-status">
                                Status (Applied to All) <span className="text-red-500">*</span>
                            </Label>
                            <Select value={batchData.status} onValueChange={value => setBatchData("status", value)}>
                                <SelectTrigger id="batch-status">
                                    <SelectValue placeholder="Select status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="on_time">On Time</SelectItem>
                                    <SelectItem value="tardy">Tardy</SelectItem>
                                    <SelectItem value="half_day_absence">Half Day Absence</SelectItem>
                                    <SelectItem value="advised_absence">Advised Absence</SelectItem>
                                    <SelectItem value="on_leave">On Leave</SelectItem>
                                    <SelectItem value="ncns">NCNS</SelectItem>
                                    <SelectItem value="undertime">Undertime</SelectItem>
                                    <SelectItem value="failed_bio_in">Failed Bio In</SelectItem>
                                    <SelectItem value="failed_bio_out">Failed Bio Out</SelectItem>
                                    <SelectItem value="present_no_bio">Present (No Bio)</SelectItem>
                                    <SelectItem value="non_work_day">Non-Work Day</SelectItem>
                                </SelectContent>
                            </Select>
                            {batchErrors.status && <p className="text-sm text-red-500">{batchErrors.status}</p>}
                        </div>

                        {/* Overtime Approval - Only show if selected records have overtime */}
                        {hasOvertimeRecords() && (
                            <div className="space-y-2 p-4 bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                                <div className="flex items-center gap-2">
                                    <Input
                                        type="checkbox"
                                        id="batch_overtime_approved"
                                        checked={batchData.overtime_approved}
                                        onChange={e => setBatchData("overtime_approved", e.target.checked)}
                                        className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                    />
                                    <Label htmlFor="batch_overtime_approved" className="text-sm font-medium cursor-pointer">
                                        Approve Overtime for All Selected Records
                                    </Label>
                                </div>
                                <p className="text-xs text-blue-700 dark:text-blue-400">
                                    This will approve overtime for any records that have overtime hours
                                </p>
                            </div>
                        )}

                        {/* Common Verification Notes */}
                        <div className="space-y-2">
                            <Label htmlFor="batch_verification_notes">
                                Verification Notes (Applied to All) <span className="text-red-500">*</span>
                            </Label>
                            <div className="flex flex-wrap gap-2 mb-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setBatchData("verification_notes", "Verified attendance records as accurate")}
                                    className="h-8 text-xs"
                                >
                                    Verified as accurate
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setBatchData("verification_notes", "Corrected time entries based on supervisor confirmation")}
                                    className="h-8 text-xs"
                                >
                                    Supervisor confirmed
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setBatchData("verification_notes", "Adjusted status per attendance policy")}
                                    className="h-8 text-xs"
                                >
                                    Policy adjustment
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setBatchData("verification_notes", "Manual verification due to system anomaly")}
                                    className="h-8 text-xs"
                                >
                                    System anomaly
                                </Button>
                            </div>
                            <Textarea
                                id="batch_verification_notes"
                                value={batchData.verification_notes}
                                onChange={e => setBatchData("verification_notes", e.target.value)}
                                placeholder="Explain why these records are being verified..."
                                rows={4}
                            />
                            {batchErrors.verification_notes && (
                                <p className="text-sm text-red-500">{batchErrors.verification_notes}</p>
                            )}
                        </div>

                        <div className="bg-yellow-50 dark:bg-yellow-950/20 border border-yellow-200 dark:border-yellow-800 p-3 rounded-md">
                            <p className="text-sm text-yellow-800 dark:text-yellow-400">
                                <strong>Note:</strong> This will apply the same status and notes to all selected records.
                                Time entries will remain as they are. Use individual verification for records needing time adjustments.
                            </p>
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setIsBatchDialogOpen(false)}
                                disabled={batchProcessing}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={batchProcessing}>
                                {batchProcessing ? "Verifying..." : `Verify ${selectedRecords.size} Record${selectedRecords.size === 1 ? "" : "s"}`}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Single Verification Dialog */}
            <Dialog open={isDialogOpen} onOpenChange={setIsDialogOpen}>
                <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Verify Attendance Record</DialogTitle>
                        <DialogDescription>
                            Review and update attendance information for {selectedRecord?.user.name} on{" "}
                            {selectedRecord && formatDate(selectedRecord.shift_date)}
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={handleVerify} className="space-y-4">
                        {/* Leave Warning - Show detailed impact */}
                        {selectedRecord?.leave_request && (
                            <div className="bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 p-4 rounded-md">
                                <div className="flex items-start gap-3">
                                    <AlertCircle className="h-5 w-5 text-amber-600 flex-shrink-0 mt-0.5" />
                                    <div className="flex-1">
                                        <h4 className="font-semibold text-amber-800 dark:text-amber-200">
                                            {selectedRecord.actual_time_in || selectedRecord.actual_time_out
                                                ? "Employee Reported to Office During Leave"
                                                : "Employee is on Approved Leave"}
                                        </h4>
                                        <p className="text-sm text-amber-700 dark:text-amber-300 mt-1">
                                            {selectedRecord.leave_request.leave_type} Leave: {formatDate(selectedRecord.leave_request.start_date)} to {formatDate(selectedRecord.leave_request.end_date)} ({selectedRecord.leave_request.days_requested} day{selectedRecord.leave_request.days_requested !== 1 ? 's' : ''})
                                        </p>
                                        {(selectedRecord.actual_time_in || selectedRecord.actual_time_out) && (
                                            <div className="mt-2 p-2 bg-amber-100 dark:bg-amber-900/50 rounded text-xs">
                                                <p className="font-medium text-amber-800 dark:text-amber-200 mb-1">
                                                    If you verify this as worked (not "On Leave"):
                                                </p>
                                                <ul className="list-disc list-inside text-amber-700 dark:text-amber-300 space-y-0.5">
                                                    <li>Leave dates will be adjusted to exclude this day and any following days</li>
                                                    <li>Unused leave credits will be automatically restored</li>
                                                    <li>Employee will be notified of the change</li>
                                                </ul>
                                            </div>
                                        )}
                                        {!(selectedRecord.actual_time_in || selectedRecord.actual_time_out) && (
                                            <p className="text-xs text-amber-600 dark:text-amber-400 mt-2">
                                                ⚠️ Changing status from "On Leave" will adjust or cancel the leave request and restore credits.
                                            </p>
                                        )}
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* Current Info */}
                        {selectedRecord && (() => {
                            // Use attendance's employee_schedule or fallback to user's active_schedule
                            const schedule = selectedRecord.employee_schedule || selectedRecord.user?.active_schedule;
                            return (
                                <div className="bg-muted p-4 rounded-md space-y-2 text-sm">
                                    <h4 className="font-semibold">Current Information</h4>
                                    {!selectedRecord.employee_schedule && selectedRecord.user?.active_schedule && (
                                        <p className="text-xs text-amber-600 dark:text-amber-400 mb-2">
                                            ⓘ Using employee's current active schedule (attendance record has no assigned schedule)
                                        </p>
                                    )}
                                    <div className="grid grid-cols-2 gap-2">
                                        <div>
                                            <span className="text-muted-foreground">Scheduled In:</span>{" "}
                                            {formatTime(schedule?.scheduled_time_in) || "-"}
                                        </div>
                                        <div>
                                            <span className="text-muted-foreground">Scheduled Out:</span>{" "}
                                            {formatTime(schedule?.scheduled_time_out) || "-"}
                                        </div>
                                        <div>
                                            <span className="text-muted-foreground">Assigned Site:</span>{" "}
                                            {schedule?.site?.name || "-"}
                                        </div>
                                        <div>
                                            <span className="text-muted-foreground">Grace Period:</span>{" "}
                                            {schedule?.grace_period_minutes ?? 15} minutes
                                        </div>
                                        {selectedRecord.is_cross_site_bio && (
                                            <>
                                                <div>
                                                    <span className="text-muted-foreground">Bio In Site:</span>{" "}
                                                    {selectedRecord.bio_in_site?.name || "-"}
                                                </div>
                                                <div>
                                                    <span className="text-muted-foreground">Bio Out Site:</span>{" "}
                                                    {selectedRecord.bio_out_site?.name || "-"}
                                                </div>
                                            </>
                                        )}
                                        {selectedRecord.notes && (
                                            <div className="col-span-2">
                                                <span className="text-muted-foreground">Current Notes:</span>{" "}
                                                <span className="text-foreground">{selectedRecord.notes}</span>
                                            </div>
                                        )}
                                    </div>
                                    {selectedRecord.is_cross_site_bio && (
                                        <div className="flex items-center gap-2 mt-2 text-orange-600">
                                            <AlertCircle className="h-4 w-4" />
                                            <span className="text-xs font-medium">
                                                Cross-site biometric detected - employee bio'd at different location
                                            </span>
                                        </div>
                                    )}
                                </div>
                            );
                        })()}

                        {/* Status */}
                        <div className="space-y-2">
                            <Label htmlFor="status">
                                Status <span className="text-red-500">*</span>
                            </Label>

                            {/* Suggested Status Info */}
                            {suggestedStatus && (
                                <div className={`p-3 rounded-lg border ${isStatusManuallyOverridden ? 'bg-amber-50 border-amber-200 dark:bg-amber-950/20 dark:border-amber-800' : 'bg-green-50 border-green-200 dark:bg-green-950/20 dark:border-green-800'}`}>
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="flex-1">
                                            <div className="flex items-center gap-2 mb-1">
                                                {isStatusManuallyOverridden ? (
                                                    <AlertCircle className="h-4 w-4 text-amber-600" />
                                                ) : (
                                                    <CheckCircle className="h-4 w-4 text-green-600" />
                                                )}
                                                <span className={`text-sm font-medium ${isStatusManuallyOverridden ? 'text-amber-800 dark:text-amber-400' : 'text-green-800 dark:text-green-400'}`}>
                                                    {isStatusManuallyOverridden ? 'Manual Override Active' : 'Auto-Suggested Status'}
                                                </span>
                                            </div>
                                            <p className={`text-xs ${isStatusManuallyOverridden ? 'text-amber-700 dark:text-amber-500' : 'text-green-700 dark:text-green-500'}`}>
                                                {isStatusManuallyOverridden
                                                    ? `Suggested: ${suggestedStatus.status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())} — ${suggestedStatus.reason}`
                                                    : suggestedStatus.reason
                                                }
                                            </p>
                                            {suggestedStatus.secondaryStatus && (
                                                <p className="text-xs text-muted-foreground mt-1">
                                                    Secondary: {suggestedStatus.secondaryStatus.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}
                                                </p>
                                            )}
                                        </div>
                                        {isStatusManuallyOverridden && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={resetToSuggestedStatus}
                                                className="h-7 text-xs text-amber-700 hover:text-amber-900 hover:bg-amber-100"
                                            >
                                                Use Suggested
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            )}

                            <Select value={data.status} onValueChange={handleStatusChange}>
                                <SelectTrigger className={isStatusManuallyOverridden ? 'border-amber-400' : ''}>
                                    <SelectValue placeholder="Select status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="on_time">On Time</SelectItem>
                                    <SelectItem value="tardy">Tardy</SelectItem>
                                    <SelectItem value="half_day_absence">Half Day Absence</SelectItem>
                                    <SelectItem value="advised_absence">Advised Absence</SelectItem>
                                    <SelectItem value="on_leave">On Leave</SelectItem>
                                    <SelectItem value="ncns">NCNS</SelectItem>
                                    <SelectItem value="undertime">Undertime</SelectItem>
                                    <SelectItem value="failed_bio_in">Failed Bio In</SelectItem>
                                    <SelectItem value="failed_bio_out">Failed Bio Out</SelectItem>
                                    <SelectItem value="present_no_bio">Present (No Bio)</SelectItem>
                                    <SelectItem value="non_work_day">Non-Work Day</SelectItem>
                                </SelectContent>
                            </Select>
                            {errors.status && <p className="text-sm text-red-500">{errors.status}</p>}
                        </div>

                        {/* Time In */}
                        <div className="space-y-2">
                            <Label htmlFor="actual_time_in">Actual Time In</Label>
                            <div className="grid grid-cols-2 gap-2">
                                <div className="space-y-1">
                                    <Input
                                        type="date"
                                        value={data.actual_time_in ? data.actual_time_in.slice(0, 10) : ""}
                                        onChange={e => {
                                            const date = e.target.value;
                                            const time = data.actual_time_in ? data.actual_time_in.slice(11, 16) : "00:00";
                                            setData("actual_time_in", date ? `${date}T${time}` : "");
                                        }}
                                    />
                                </div>
                                <div className="space-y-1">
                                    <TimeInput
                                        id="actual_time_in_time"
                                        value={data.actual_time_in ? data.actual_time_in.slice(11, 16) : ""}
                                        onChange={(time: string) => {
                                            const date = data.actual_time_in ? data.actual_time_in.slice(0, 10) : "";
                                            setData("actual_time_in", date && time ? `${date}T${time}` : "");
                                        }}
                                    />
                                </div>
                            </div>
                            {errors.actual_time_in && (
                                <p className="text-sm text-red-500">{errors.actual_time_in}</p>
                            )}
                        </div>

                        {/* Time Out */}
                        <div className="space-y-2">
                            <Label htmlFor="actual_time_out">Actual Time Out</Label>
                            <div className="grid grid-cols-2 gap-2">
                                <div className="space-y-1">
                                    <Input
                                        type="date"
                                        value={data.actual_time_out ? data.actual_time_out.slice(0, 10) : ""}
                                        onChange={e => {
                                            const date = e.target.value;
                                            const time = data.actual_time_out ? data.actual_time_out.slice(11, 16) : "00:00";
                                            setData("actual_time_out", date ? `${date}T${time}` : "");
                                        }}
                                    />
                                </div>
                                <div className="space-y-1">
                                    <TimeInput
                                        id="actual_time_out_time"
                                        value={data.actual_time_out ? data.actual_time_out.slice(11, 16) : ""}
                                        onChange={(time: string) => {
                                            const date = data.actual_time_out ? data.actual_time_out.slice(0, 10) : "";
                                            setData("actual_time_out", date && time ? `${date}T${time}` : "");
                                        }}
                                    />
                                </div>
                            </div>
                            {errors.actual_time_out && (
                                <p className="text-sm text-red-500">{errors.actual_time_out}</p>
                            )}
                        </div>

                        {/* Overtime Approval */}
                        {selectedRecord?.overtime_minutes && selectedRecord.overtime_minutes > 0 && (
                            <div className="space-y-2 p-4 bg-blue-50 dark:bg-blue-950/50 border border-blue-200 dark:border-blue-800 rounded-lg">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <Label className="text-sm font-semibold text-blue-900 dark:text-blue-100">
                                            Overtime Detected: {selectedRecord.overtime_minutes} minutes
                                        </Label>
                                        <p className="text-xs text-blue-700 dark:text-blue-300 mt-1">
                                            Employee worked beyond scheduled time out
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Input
                                            type="checkbox"
                                            id="overtime_approved"
                                            checked={data.overtime_approved}
                                            onChange={e => setData("overtime_approved", e.target.checked)}
                                            className="h-4 w-4 rounded border-gray-300 dark:border-gray-600 text-blue-600 focus:ring-blue-500"
                                        />
                                        <Label htmlFor="overtime_approved" className="text-sm font-medium cursor-pointer">
                                            Approve Overtime
                                        </Label>
                                    </div>
                                </div>
                                {selectedRecord.overtime_approved && (
                                    <div className="text-xs text-green-700 dark:text-green-400 mt-2">
                                        ✓ Overtime was approved
                                        {selectedRecord.overtime_approved_at && (
                                            <span> on {new Date(selectedRecord.overtime_approved_at).toLocaleString()}</span>
                                        )}
                                    </div>
                                )}
                            </div>
                        )}

                        {/* Notes */}
                        <div className="space-y-2">
                            <Label htmlFor="notes">
                                Notes (Optional)
                            </Label>
                            <Textarea
                                id="notes"
                                value={data.notes}
                                onChange={e => setData("notes", e.target.value)}
                                placeholder="Add notes about this attendance record (e.g., reason for absence, special circumstances)..."
                                rows={3}
                                maxLength={500}
                            />
                            {errors.notes && (
                                <p className="text-sm text-red-500">{errors.notes}</p>
                            )}
                            <p className="text-xs text-muted-foreground">
                                {data.notes.length}/500 characters
                            </p>
                        </div>

                        {/* Verification Notes */}
                        <div className="space-y-2">
                            <Label htmlFor="verification_notes">
                                Verification Notes <span className="text-red-500">*</span>
                            </Label>
                            <div className="flex flex-wrap gap-2 mb-2">
                                {[
                                    "Verified",
                                    "Corrected",
                                    "Manual entry",
                                    "Bio scanner issue",
                                    "Network delay",
                                    "Shift adjustment",
                                    "Approved by supervisor",
                                    "System error",
                                ].map((phrase) => (
                                    <Button
                                        key={phrase}
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className="h-7 text-xs"
                                        onClick={() => {
                                            const currentNotes = data.verification_notes.trim();
                                            const newNotes = currentNotes
                                                ? `${currentNotes}${currentNotes.endsWith('.') ? '' : '.'} ${phrase}.`
                                                : `${phrase}.`;
                                            setData("verification_notes", newNotes);
                                        }}
                                    >
                                        {phrase}
                                    </Button>
                                ))}
                            </div>
                            <Textarea
                                id="verification_notes"
                                value={data.verification_notes}
                                onChange={e => setData("verification_notes", e.target.value)}
                                placeholder="Explain why this record is being verified/corrected..."
                                rows={4}
                            />
                            {errors.verification_notes && (
                                <p className="text-sm text-red-500">{errors.verification_notes}</p>
                            )}
                        </div>

                        <DialogFooter className="flex-col sm:flex-row gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setIsDialogOpen(false)}
                                disabled={processing}
                            >
                                Cancel
                            </Button>
                            {/* Show "Flag as Reported" for on_leave records with biometric data - this directly saves */}
                            {selectedRecord?.status === 'on_leave' && selectedRecord?.leave_request && (selectedRecord?.actual_time_in || selectedRecord?.actual_time_out) ? (
                                <Button
                                    type="button"
                                    onClick={handleFlagAsReported}
                                    disabled={processing}
                                    className="bg-orange-500 hover:bg-orange-600 text-white"
                                >
                                    <UserCheck className="h-4 w-4 mr-2" />
                                    {processing ? "Saving..." : "Flag as Reported & Save"}
                                </Button>
                            ) : (
                                <Button type="submit" disabled={processing}>
                                    {processing ? "Verifying..." : "Verify & Save"}
                                </Button>
                            )}
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Warnings Details Dialog */}
            <Dialog open={isWarningsDialogOpen} onOpenChange={closeWarningsDialog}>
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <AlertCircle className="h-5 w-5 text-amber-600" />
                            Suspicious Pattern Detected
                        </DialogTitle>
                        <DialogDescription>
                            {warningsDialogRecord && (
                                <span>
                                    {warningsDialogRecord.user.name} - {formatDate(warningsDialogRecord.shift_date)}
                                </span>
                            )}
                        </DialogDescription>
                    </DialogHeader>

                    {warningsDialogRecord && (
                        <div className="space-y-4">
                            {/* Attendance Summary */}
                            <div className="bg-muted p-4 rounded-md space-y-2">
                                <div className="grid grid-cols-2 gap-4 text-sm">
                                    <div>
                                        <span className="font-medium">Scheduled Shift:</span>
                                        <div className="text-muted-foreground">
                                            {warningsDialogRecord.employee_schedule?.shift_type || 'Not Scheduled'}
                                        </div>
                                        {warningsDialogRecord.employee_schedule && (
                                            <div className="text-muted-foreground text-xs">
                                                {warningsDialogRecord.employee_schedule.scheduled_time_in} - {warningsDialogRecord.employee_schedule.scheduled_time_out}
                                            </div>
                                        )}
                                    </div>
                                    <div>
                                        <span className="font-medium">Recorded Times:</span>
                                        <div className="text-muted-foreground">
                                            In: {warningsDialogRecord.actual_time_in ? formatDateTime(warningsDialogRecord.actual_time_in) : 'N/A'}
                                        </div>
                                        <div className="text-muted-foreground">
                                            Out: {warningsDialogRecord.actual_time_out ? formatDateTime(warningsDialogRecord.actual_time_out) : 'N/A'}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* Warnings List */}
                            <div className="space-y-2">
                                <h4 className="font-semibold text-sm">Detected Issues:</h4>
                                <div className="space-y-3">
                                    {warningsDialogRecord.warnings?.map((warning, idx) => (
                                        <div key={idx} className="bg-amber-50 border border-amber-200 rounded-md p-3">
                                            <div className="flex items-start gap-2">
                                                <AlertCircle className="h-4 w-4 text-amber-600 mt-0.5 flex-shrink-0" />
                                                <div className="text-sm text-amber-900">{warning}</div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>

                            {/* Recommendation */}
                            <div className="bg-blue-50 border border-blue-200 rounded-md p-3">
                                <div className="flex items-start gap-2">
                                    <AlertCircle className="h-4 w-4 text-blue-600 mt-0.5 flex-shrink-0" />
                                    <div className="text-sm text-blue-900">
                                        <span className="font-medium block mb-1">Recommended Action:</span>
                                        Review the biometric records and employee schedule. Verify with the employee or supervisor to determine the correct attendance status.
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    <DialogFooter>
                        <Button variant="outline" onClick={closeWarningsDialog}>
                            Close
                        </Button>
                        {warningsDialogRecord && (
                            <Button onClick={() => {
                                closeWarningsDialog();
                                openVerifyDialog(warningsDialogRecord);
                            }}>
                                <Edit className="h-4 w-4 mr-2" />
                                Verify Record
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Notes Dialog */}
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

            {/* Partial Approval Dialog (Night Shift Completion) */}
            <Dialog open={isPartialDialogOpen} onOpenChange={setIsPartialDialogOpen}>
                <DialogContent className="max-w-[95vw] sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Moon className="h-5 w-5 text-purple-600" />
                            Complete Night Shift
                        </DialogTitle>
                        <DialogDescription>
                            Add the time out for {partialRecord?.user.name}'s night shift on{" "}
                            {partialRecord && formatDate(partialRecord.shift_date)}
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={handlePartialApprove} className="space-y-4">
                        {/* Current Info */}
                        {partialRecord && (() => {
                            const schedule = partialRecord.employee_schedule || partialRecord.user?.active_schedule;
                            return (
                                <div className="bg-muted p-4 rounded-md space-y-2 text-sm">
                                    <h4 className="font-semibold">Night Shift Information</h4>
                                    <div className="grid grid-cols-2 gap-2">
                                        <div>
                                            <span className="text-muted-foreground">Shift Date:</span>{" "}
                                            {formatDate(partialRecord.shift_date)}
                                        </div>
                                        <div>
                                            <span className="text-muted-foreground">Shift Type:</span>{" "}
                                            {schedule?.shift_type?.replace('_', ' ') || "Night Shift"}
                                        </div>
                                        <div>
                                            <span className="text-muted-foreground">Scheduled In:</span>{" "}
                                            {formatTime(schedule?.scheduled_time_in) || "-"}
                                        </div>
                                        <div>
                                            <span className="text-muted-foreground">Scheduled Out:</span>{" "}
                                            {formatTime(schedule?.scheduled_time_out) || "-"} (next day)
                                        </div>
                                        <div className="col-span-2">
                                            <span className="text-muted-foreground">Actual Time In:</span>{" "}
                                            <span className="font-medium">{formatDateTime(partialRecord.actual_time_in)}</span>
                                        </div>
                                        {partialRecord.tardy_minutes && partialRecord.tardy_minutes > 0 && (
                                            <div className="col-span-2 text-orange-600">
                                                Tardy: {partialRecord.tardy_minutes} minutes late
                                            </div>
                                        )}
                                    </div>
                                </div>
                            );
                        })()}

                        {/* Time Out Input */}
                        <div className="space-y-2">
                            <Label>Actual Time Out <span className="text-red-500">*</span></Label>
                            <div className="grid grid-cols-2 gap-2">
                                <div className="space-y-1">
                                    <Label className="text-xs text-muted-foreground">Date</Label>
                                    <Input
                                        type="date"
                                        value={partialData.actual_time_out ? partialData.actual_time_out.slice(0, 10) : ""}
                                        onChange={e => {
                                            const date = e.target.value;
                                            const time = partialData.actual_time_out ? partialData.actual_time_out.slice(11, 16) : "07:00";
                                            setPartialData("actual_time_out", date ? `${date}T${time}` : "");
                                        }}
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-xs text-muted-foreground">Time</Label>
                                    <TimeInput
                                        value={partialData.actual_time_out ? partialData.actual_time_out.slice(11, 16) : ""}
                                        onChange={(time: string) => {
                                            const date = partialData.actual_time_out ? partialData.actual_time_out.slice(0, 10) : "";
                                            setPartialData("actual_time_out", date && time ? `${date}T${time}` : "");
                                        }}
                                    />
                                </div>
                            </div>
                            {partialErrors.actual_time_out && (
                                <p className="text-sm text-red-500">{partialErrors.actual_time_out}</p>
                            )}
                        </div>

                        {/* Status Override (Optional) */}
                        <div className="space-y-2">
                            <Label>Status Override (Optional)</Label>
                            <Select
                                value={partialData.status || ""}
                                onValueChange={value => setPartialData("status", value)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Keep current status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="">Keep current status</SelectItem>
                                    <SelectItem value="on_time">On Time</SelectItem>
                                    <SelectItem value="tardy">Tardy</SelectItem>
                                    <SelectItem value="half_day_absence">Half Day Absence</SelectItem>
                                    <SelectItem value="undertime">Undertime</SelectItem>
                                    <SelectItem value="undertime_more_than_hour">Undertime (&gt;1hr)</SelectItem>
                                </SelectContent>
                            </Select>
                            <p className="text-xs text-muted-foreground">
                                Leave empty to auto-calculate based on undertime
                            </p>
                        </div>

                        {/* Verification Notes */}
                        <div className="space-y-2">
                            <Label>Verification Notes <span className="text-red-500">*</span></Label>
                            <div className="flex flex-wrap gap-2 mb-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setPartialData("verification_notes", "Night shift time out completed.")}
                                    className="h-7 text-xs"
                                >
                                    Night shift completed
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setPartialData("verification_notes", "Time out from next day biometric upload.")}
                                    className="h-7 text-xs"
                                >
                                    From biometric upload
                                </Button>
                            </div>
                            <Textarea
                                value={partialData.verification_notes}
                                onChange={e => setPartialData("verification_notes", e.target.value)}
                                placeholder="Explain why this night shift is being completed..."
                                rows={3}
                            />
                            {partialErrors.verification_notes && (
                                <p className="text-sm text-red-500">{partialErrors.verification_notes}</p>
                            )}
                        </div>

                        <div className="bg-purple-50 dark:bg-purple-950/20 border border-purple-200 dark:border-purple-800 p-3 rounded-md">
                            <p className="text-sm text-purple-800 dark:text-purple-400">
                                <strong>Note:</strong> This will complete the night shift record by adding the time out
                                and automatically verify it. Attendance points will be generated if there are violations.
                            </p>
                        </div>

                        <DialogFooter className="flex-col sm:flex-row gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setIsPartialDialogOpen(false)}
                                disabled={partialProcessing}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={partialProcessing}
                                className="bg-purple-600 hover:bg-purple-700"
                            >
                                <Moon className="h-4 w-4 mr-2" />
                                {partialProcessing ? "Completing..." : "Complete & Verify"}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
