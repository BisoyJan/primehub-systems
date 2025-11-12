import React, { useState } from "react";
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
import { AlertCircle, CheckCircle, Edit, Search, X } from "lucide-react";

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
        ncns: { label: "NCNS", className: "bg-red-500" },
        undertime: { label: "Undertime", className: "bg-orange-400" },
        failed_bio_in: { label: "Failed Bio In", className: "bg-purple-500" },
        failed_bio_out: { label: "Failed Bio Out", className: "bg-purple-500" },
        present_no_bio: { label: "Present (No Bio)", className: "bg-gray-500" },
    };
    const config = statusConfig[status] || { label: status, className: "bg-gray-500" };
    return <Badge className={config.className}>{config.label}</Badge>;
};

const getStatusBadges = (record: AttendanceRecord) => {
    return (
        <div className="flex gap-1 flex-wrap">
            {getStatusBadge(record.status)}
            {record.secondary_status && getStatusBadge(record.secondary_status)}
        </div>
    );
};

export default function AttendanceReview() {
    const { attendances, filters, auth } = usePage<PageProps>().props;
    const attendanceData = {
        data: attendances?.data ?? [],
        links: attendances?.links ?? [],
        meta: attendances?.meta ?? DEFAULT_META,
    };

    const timeFormat = auth.user.time_format || '24';

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

    // Search state
    const [searchQuery, setSearchQuery] = useState(filters?.search || "");
    const [statusFilter, setStatusFilter] = useState(filters?.status || "all");
    const [verifiedFilter, setVerifiedFilter] = useState(filters?.verified || "all");
    const [dateFrom, setDateFrom] = useState(filters?.date_from || "");
    const [dateTo, setDateTo] = useState(filters?.date_to || "");

    const { data, setData, post, processing, errors, reset } = useForm({
        status: "",
        actual_time_in: "",
        actual_time_out: "",
        verification_notes: "",
        overtime_approved: false,
    });

    const handleSearch = () => {
        router.get(
            "/attendance/review",
            {
                search: searchQuery,
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
        setSearchQuery("");
        setStatusFilter("all");
        setVerifiedFilter("all");
        setDateFrom("");
        setDateTo("");
        router.get("/attendance/review", {}, { preserveState: true });
    };

    const openVerifyDialog = (record: AttendanceRecord) => {
        setSelectedRecord(record);
        setData({
            status: record.status,
            actual_time_in: record.actual_time_in ? new Date(record.actual_time_in).toISOString().slice(0, 16) : "",
            actual_time_out: record.actual_time_out ? new Date(record.actual_time_out).toISOString().slice(0, 16) : "",
            verification_notes: record.verification_notes || "",
            overtime_approved: record.overtime_approved || false,
        });
        setIsDialogOpen(true);
    };

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
                                {/* Search by Name */}
                                <div className="space-y-2">
                                    <Label htmlFor="search">Search Employee</Label>
                                    <Input
                                        id="search"
                                        placeholder="Search by name..."
                                        value={searchQuery}
                                        onChange={(e) => setSearchQuery(e.target.value)}
                                        onKeyDown={(e) => e.key === "Enter" && handleSearch()}
                                    />
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
                                            <SelectValue placeholder="All records" />
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
                    </div>
                    <Button variant="outline" onClick={() => router.get("/attendance")}>
                        Back to Attendance
                    </Button>
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
                                            <TableRow key={record.id}>
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
                                                        {record.tardy_minutes ? (
                                                            <div className="text-orange-600">+{record.tardy_minutes}m</div>
                                                        ) : record.undertime_minutes ? (
                                                            <div className="text-orange-600">-{record.undertime_minutes}m</div>
                                                        ) : (
                                                            <div>-</div>
                                                        )}
                                                        {record.overtime_minutes && record.overtime_minutes > 0 && (
                                                            <div className={`text-xs ${record.overtime_approved ? 'text-green-600' : 'text-blue-600'}`}>
                                                                +{record.overtime_minutes}m OT
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
                                <div key={record.id} className="bg-card border rounded-lg p-4 shadow-sm space-y-3">
                                    <div className="flex justify-between items-start">
                                        <div>
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
                                        {(record.tardy_minutes || record.undertime_minutes || (record.overtime_minutes && record.overtime_minutes > 0)) && (
                                            <div>
                                                <span className="font-medium">Time Adjustments:</span>
                                                <div className="mt-1 space-y-1">
                                                    {record.tardy_minutes && (
                                                        <span className="text-orange-600 block">• Tardy: +{record.tardy_minutes} minutes</span>
                                                    )}
                                                    {record.undertime_minutes && (
                                                        <span className="text-orange-600 block">• Undertime: -{record.undertime_minutes} minutes</span>
                                                    )}
                                                    {record.overtime_minutes && record.overtime_minutes > 0 && (
                                                        <span className={`block ${record.overtime_approved ? 'text-green-600' : 'text-blue-600'}`}>
                                                            • Overtime: +{record.overtime_minutes} minutes
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

            {/* Verification Dialog */}
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
                                    <SelectItem value="ncns">NCNS</SelectItem>
                                    <SelectItem value="undertime">Undertime</SelectItem>
                                    <SelectItem value="failed_bio_in">Failed Bio In</SelectItem>
                                    <SelectItem value="failed_bio_out">Failed Bio Out</SelectItem>
                                    <SelectItem value="present_no_bio">Present (No Bio)</SelectItem>
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
                                        <input
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
        </AppLayout>
    );
}
