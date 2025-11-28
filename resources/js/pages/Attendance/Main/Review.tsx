import React, { useState, useEffect } from "react";
import { Head, router, useForm, usePage } from "@inertiajs/react";
import AppLayout from "@/layouts/app-layout";
import { useFlashMessage, usePageLoading, usePageMeta } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { LoadingOverlay } from "@/components/LoadingOverlay";
import { type SharedData } from "@/types";
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
import { AlertCircle, Check, CheckCircle, ChevronsUpDown, Edit, Search, X } from "lucide-react";

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
    filters?: {
        search?: string;
        user_id?: string;
        status?: string;
        date_from?: string;
        date_to?: string;
        verified?: string;
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
    const date = new Date(dateString);
    if (Number.isNaN(date.getTime())) {
        return dateString;
    }
    return date.toLocaleDateString('en-US', {
        weekday: 'short',
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        timeZone: 'UTC'
    });
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

export default function AttendanceReview() {
    const { attendances, employees, filters, auth } = usePage<PageProps>().props;
    const attendanceData = {
        data: attendances?.data ?? [],
        links: attendances?.links ?? [],
        meta: attendances?.meta ?? DEFAULT_META,
    };

    const timeFormat = auth.user.time_format || '24';

    // Employee search popover state
    const [isEmployeePopoverOpen, setIsEmployeePopoverOpen] = useState(false);
    const [employeeSearchQuery, setEmployeeSearchQuery] = useState("");
    const [selectedUserId, setSelectedUserId] = useState(filters?.user_id || "");

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
        actual_time_in: "",
        actual_time_out: "",
        verification_notes: "",
        overtime_approved: false,
    });

    const { data: batchData, setData: setBatchData, post: postBatch, processing: batchProcessing, errors: batchErrors, reset: resetBatch } = useForm({
        record_ids: [] as number[],
        status: "",
        verification_notes: "",
        overtime_approved: false,
    });

    const handleSearch = () => {
        router.get(
            "/attendance/review",
            {
                user_id: selectedUserId,
                status: statusFilter === "all" ? "" : statusFilter,
                verified: verifiedFilter,
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
        setData({
            status: record.status,
            actual_time_in: toLocalDateTimeString(record.actual_time_in),
            actual_time_out: toLocalDateTimeString(record.actual_time_out),
            verification_notes: record.verification_notes || "",
            overtime_approved: record.overtime_approved || false,
        });
        setIsDialogOpen(true);
    };

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
                            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
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
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => openVerifyDialog(record)}
                                                    >
                                                        <Edit className="h-4 w-4 mr-1" />
                                                        Verify
                                                    </Button>
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
                                            {formatDateTime(record.actual_time_in, timeFormat)}
                                            {record.bio_in_site && record.is_cross_site_bio && (
                                                <span className="text-muted-foreground"> @ {record.bio_in_site.name}</span>
                                            )}
                                        </div>
                                        <div>
                                            <span className="font-medium">Time Out:</span>{" "}
                                            {formatDateTime(record.actual_time_out, timeFormat)}
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
                        {/* Current Info */}
                        {selectedRecord && (
                            <div className="bg-muted p-4 rounded-md space-y-2 text-sm">
                                <h4 className="font-semibold">Current Information</h4>
                                <div className="grid grid-cols-2 gap-2">
                                    <div>
                                        <span className="text-muted-foreground">Scheduled In:</span>{" "}
                                        {selectedRecord.employee_schedule?.scheduled_time_in || "-"}
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">Scheduled Out:</span>{" "}
                                        {selectedRecord.employee_schedule?.scheduled_time_out || "-"}
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">Assigned Site:</span>{" "}
                                        {selectedRecord.employee_schedule?.site?.name || "-"}
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
                        )}

                        {/* Status */}
                        <div className="space-y-2">
                            <Label htmlFor="status">
                                Status <span className="text-red-500">*</span>
                            </Label>
                            <Select value={data.status} onValueChange={value => setData("status", value)}>
                                <SelectTrigger>
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
                            <div className="flex gap-2">
                                <Input
                                    type="date"
                                    value={data.actual_time_in ? data.actual_time_in.slice(0, 10) : ""}
                                    onChange={e => {
                                        const date = e.target.value;
                                        const time = data.actual_time_in ? data.actual_time_in.slice(11, 16) : "00:00";
                                        setData("actual_time_in", date ? `${date}T${time}` : "");
                                    }}
                                    className="flex-1"
                                />
                                {timeFormat === '24' ? (
                                    <div className="flex gap-1">
                                        <Input
                                            type="number"
                                            min="0"
                                            max="23"
                                            placeholder="HH"
                                            value={data.actual_time_in ? data.actual_time_in.slice(11, 13) : "00"}
                                            onChange={e => {
                                                const hour = e.target.value.padStart(2, '0');
                                                const date = data.actual_time_in ? data.actual_time_in.slice(0, 10) : "";
                                                const minute = data.actual_time_in ? data.actual_time_in.slice(14, 16) : "00";
                                                setData("actual_time_in", date ? `${date}T${hour}:${minute}` : "");
                                            }}
                                            className="w-16 text-center"
                                        />
                                        <span className="flex items-center">:</span>
                                        <Input
                                            type="number"
                                            min="0"
                                            max="59"
                                            placeholder="MM"
                                            value={data.actual_time_in ? data.actual_time_in.slice(14, 16) : "00"}
                                            onChange={e => {
                                                const minute = e.target.value.padStart(2, '0');
                                                const date = data.actual_time_in ? data.actual_time_in.slice(0, 10) : "";
                                                const hour = data.actual_time_in ? data.actual_time_in.slice(11, 13) : "00";
                                                setData("actual_time_in", date ? `${date}T${hour}:${minute}` : "");
                                            }}
                                            className="w-16 text-center"
                                        />
                                    </div>
                                ) : (
                                    <div className="flex gap-1">
                                        <Input
                                            type="number"
                                            min="1"
                                            max="12"
                                            placeholder="HH"
                                            value={(() => {
                                                if (!data.actual_time_in) return "12";
                                                const hour24 = parseInt(data.actual_time_in.slice(11, 13));
                                                const hour12 = hour24 === 0 ? 12 : hour24 > 12 ? hour24 - 12 : hour24;
                                                return hour12.toString();
                                            })()}
                                            onChange={e => {
                                                const hour12 = parseInt(e.target.value) || 1;
                                                const date = data.actual_time_in ? data.actual_time_in.slice(0, 10) : "";
                                                const minute = data.actual_time_in ? data.actual_time_in.slice(14, 16) : "00";
                                                const currentHour24 = data.actual_time_in ? parseInt(data.actual_time_in.slice(11, 13)) : 0;
                                                const isPM = currentHour24 >= 12;
                                                let hour24 = hour12;
                                                if (isPM && hour12 !== 12) hour24 = hour12 + 12;
                                                else if (!isPM && hour12 === 12) hour24 = 0;
                                                setData("actual_time_in", date ? `${date}T${hour24.toString().padStart(2, '0')}:${minute}` : "");
                                            }}
                                            className="w-14 text-center"
                                        />
                                        <span className="flex items-center">:</span>
                                        <Input
                                            type="number"
                                            min="0"
                                            max="59"
                                            placeholder="MM"
                                            value={data.actual_time_in ? data.actual_time_in.slice(14, 16) : "00"}
                                            onChange={e => {
                                                const minute = e.target.value.padStart(2, '0');
                                                const date = data.actual_time_in ? data.actual_time_in.slice(0, 10) : "";
                                                const hour = data.actual_time_in ? data.actual_time_in.slice(11, 13) : "00";
                                                setData("actual_time_in", date ? `${date}T${hour}:${minute}` : "");
                                            }}
                                            className="w-14 text-center"
                                        />
                                        <Select
                                            value={(() => {
                                                if (!data.actual_time_in) return "AM";
                                                const hour24 = parseInt(data.actual_time_in.slice(11, 13));
                                                return hour24 >= 12 ? "PM" : "AM";
                                            })()}
                                            onValueChange={period => {
                                                const date = data.actual_time_in ? data.actual_time_in.slice(0, 10) : "";
                                                const minute = data.actual_time_in ? data.actual_time_in.slice(14, 16) : "00";
                                                const currentHour24 = data.actual_time_in ? parseInt(data.actual_time_in.slice(11, 13)) : 0;
                                                const currentHour12 = currentHour24 === 0 ? 12 : currentHour24 > 12 ? currentHour24 - 12 : currentHour24;
                                                let hour24 = currentHour12;
                                                if (period === 'PM' && currentHour12 !== 12) hour24 = currentHour12 + 12;
                                                else if (period === 'AM' && currentHour12 === 12) hour24 = 0;
                                                setData("actual_time_in", date ? `${date}T${hour24.toString().padStart(2, '0')}:${minute}` : "");
                                            }}
                                        >
                                            <SelectTrigger className="w-20">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="AM">AM</SelectItem>
                                                <SelectItem value="PM">PM</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                )}
                            </div>
                            {errors.actual_time_in && (
                                <p className="text-sm text-red-500">{errors.actual_time_in}</p>
                            )}
                        </div>

                        {/* Time Out */}
                        <div className="space-y-2">
                            <Label htmlFor="actual_time_out">Actual Time Out</Label>
                            <div className="flex gap-2">
                                <Input
                                    type="date"
                                    value={data.actual_time_out ? data.actual_time_out.slice(0, 10) : ""}
                                    onChange={e => {
                                        const date = e.target.value;
                                        const time = data.actual_time_out ? data.actual_time_out.slice(11, 16) : "00:00";
                                        setData("actual_time_out", date ? `${date}T${time}` : "");
                                    }}
                                    className="flex-1"
                                />
                                {timeFormat === '24' ? (
                                    <div className="flex gap-1">
                                        <Input
                                            type="number"
                                            min="0"
                                            max="23"
                                            placeholder="HH"
                                            value={data.actual_time_out ? data.actual_time_out.slice(11, 13) : "00"}
                                            onChange={e => {
                                                const hour = e.target.value.padStart(2, '0');
                                                const date = data.actual_time_out ? data.actual_time_out.slice(0, 10) : "";
                                                const minute = data.actual_time_out ? data.actual_time_out.slice(14, 16) : "00";
                                                setData("actual_time_out", date ? `${date}T${hour}:${minute}` : "");
                                            }}
                                            className="w-16 text-center"
                                        />
                                        <span className="flex items-center">:</span>
                                        <Input
                                            type="number"
                                            min="0"
                                            max="59"
                                            placeholder="MM"
                                            value={data.actual_time_out ? data.actual_time_out.slice(14, 16) : "00"}
                                            onChange={e => {
                                                const minute = e.target.value.padStart(2, '0');
                                                const date = data.actual_time_out ? data.actual_time_out.slice(0, 10) : "";
                                                const hour = data.actual_time_out ? data.actual_time_out.slice(11, 13) : "00";
                                                setData("actual_time_out", date ? `${date}T${hour}:${minute}` : "");
                                            }}
                                            className="w-16 text-center"
                                        />
                                    </div>
                                ) : (
                                    <div className="flex gap-1">
                                        <Input
                                            type="number"
                                            min="1"
                                            max="12"
                                            placeholder="HH"
                                            value={(() => {
                                                if (!data.actual_time_out) return "12";
                                                const hour24 = parseInt(data.actual_time_out.slice(11, 13));
                                                const hour12 = hour24 === 0 ? 12 : hour24 > 12 ? hour24 - 12 : hour24;
                                                return hour12.toString();
                                            })()}
                                            onChange={e => {
                                                const hour12 = parseInt(e.target.value) || 1;
                                                const date = data.actual_time_out ? data.actual_time_out.slice(0, 10) : "";
                                                const minute = data.actual_time_out ? data.actual_time_out.slice(14, 16) : "00";
                                                const currentHour24 = data.actual_time_out ? parseInt(data.actual_time_out.slice(11, 13)) : 0;
                                                const isPM = currentHour24 >= 12;
                                                let hour24 = hour12;
                                                if (isPM && hour12 !== 12) hour24 = hour12 + 12;
                                                else if (!isPM && hour12 === 12) hour24 = 0;
                                                setData("actual_time_out", date ? `${date}T${hour24.toString().padStart(2, '0')}:${minute}` : "");
                                            }}
                                            className="w-14 text-center"
                                        />
                                        <span className="flex items-center">:</span>
                                        <Input
                                            type="number"
                                            min="0"
                                            max="59"
                                            placeholder="MM"
                                            value={data.actual_time_out ? data.actual_time_out.slice(14, 16) : "00"}
                                            onChange={e => {
                                                const minute = e.target.value.padStart(2, '0');
                                                const date = data.actual_time_out ? data.actual_time_out.slice(0, 10) : "";
                                                const hour = data.actual_time_out ? data.actual_time_out.slice(11, 13) : "00";
                                                setData("actual_time_out", date ? `${date}T${hour}:${minute}` : "");
                                            }}
                                            className="w-14 text-center"
                                        />
                                        <Select
                                            value={(() => {
                                                if (!data.actual_time_out) return "AM";
                                                const hour24 = parseInt(data.actual_time_out.slice(11, 13));
                                                return hour24 >= 12 ? "PM" : "AM";
                                            })()}
                                            onValueChange={period => {
                                                const date = data.actual_time_out ? data.actual_time_out.slice(0, 10) : "";
                                                const minute = data.actual_time_out ? data.actual_time_out.slice(14, 16) : "00";
                                                const currentHour24 = data.actual_time_out ? parseInt(data.actual_time_out.slice(11, 13)) : 0;
                                                const currentHour12 = currentHour24 === 0 ? 12 : currentHour24 > 12 ? currentHour24 - 12 : currentHour24;
                                                let hour24 = currentHour12;
                                                if (period === 'PM' && currentHour12 !== 12) hour24 = currentHour12 + 12;
                                                else if (period === 'AM' && currentHour12 === 12) hour24 = 0;
                                                setData("actual_time_out", date ? `${date}T${hour24.toString().padStart(2, '0')}:${minute}` : "");
                                            }}
                                        >
                                            <SelectTrigger className="w-20">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="AM">AM</SelectItem>
                                                <SelectItem value="PM">PM</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                )}
                            </div>
                            {errors.actual_time_out && (
                                <p className="text-sm text-red-500">{errors.actual_time_out}</p>
                            )}
                        </div>

                        {/* Overtime Approval */}
                        {selectedRecord?.overtime_minutes && selectedRecord.overtime_minutes > 0 && (
                            <div className="space-y-2 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <Label className="text-sm font-semibold text-blue-900">
                                            Overtime Detected: {selectedRecord.overtime_minutes} minutes
                                        </Label>
                                        <p className="text-xs text-blue-700 mt-1">
                                            Employee worked beyond scheduled time out
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Input
                                            type="checkbox"
                                            id="overtime_approved"
                                            checked={data.overtime_approved}
                                            onChange={e => setData("overtime_approved", e.target.checked)}
                                            className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                        />
                                        <Label htmlFor="overtime_approved" className="text-sm font-medium cursor-pointer">
                                            Approve Overtime
                                        </Label>
                                    </div>
                                </div>
                                {selectedRecord.overtime_approved && (
                                    <div className="text-xs text-green-700 mt-2">
                                        ✓ Overtime was approved
                                        {selectedRecord.overtime_approved_at && (
                                            <span> on {new Date(selectedRecord.overtime_approved_at).toLocaleString()}</span>
                                        )}
                                    </div>
                                )}
                            </div>
                        )}

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

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setIsDialogOpen(false)}
                                disabled={processing}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {processing ? "Verifying..." : "Verify & Save"}
                            </Button>
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
        </AppLayout>
    );
}
