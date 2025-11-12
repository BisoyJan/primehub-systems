import React, { useState } from "react";
import { Head, router, usePage } from "@inertiajs/react";
import AppLayout from "@/layouts/app-layout";
import { useFlashMessage } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { Button } from "@/components/ui/button";
import type { BreadcrumbItem, SharedData } from "@/types";
import { toast } from "sonner";
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
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Checkbox } from "@/components/ui/checkbox";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
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
import { MoreVertical } from "lucide-react";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import PaginationNav, { PaginationLink } from "@/components/pagination-nav";
import { AlertCircle, Filter, TrendingUp, Users, Eye, Award, RefreshCw, CheckCircle, XCircle, FileText, Download } from "lucide-react";

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Attendance Points', href: '/attendance-points' }
];

interface User {
    id: number;
    name: string;
}

interface ExcusedBy {
    id: number;
    name: string;
}

interface AttendancePoint {
    id: number;
    user: User;
    shift_date: string;
    point_type: 'whole_day_absence' | 'half_day_absence' | 'undertime' | 'tardy';
    points: number;
    status: string | null;
    is_advised: boolean;
    is_excused: boolean;
    excused_by: ExcusedBy | null;
    excused_at: string | null;
    excuse_reason: string | null;
    notes: string | null;
    expires_at: string | null;
    expiration_type: 'sro' | 'gbro' | 'none';
    is_expired: boolean;
    expired_at: string | null;
    violation_details: string | null;
    tardy_minutes: number | null;
    undertime_minutes: number | null;
    eligible_for_gbro: boolean;
    gbro_applied_at: string | null;
}

interface PointsPayload {
    data: AttendancePoint[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number;
    to: number;
}

interface Stats {
    total_points: number;
    excused_points: number;
    expired_points: number;
    total_violations: number;
    by_type: {
        whole_day_absence: number;
        half_day_absence: number;
        undertime: number;
        tardy: number;
    };
}

interface Filters {
    user_id?: string;
    point_type?: string;
    status?: string;
    date_from?: string;
    date_to?: string;
    expiring_soon?: string;
    gbro_eligible?: string;
}

interface PageProps extends SharedData {
    points?: PointsPayload;
    users?: User[];
    stats?: Stats;
    filters?: Filters;
    [key: string]: unknown;
}

const formatDate = (date: string) => {
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric'
    });
};

const formatDateTime = (value: string, timeFormat: '12' | '24' = '24') => {
    const date = new Date(value);
    const dateStr = date.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric'
    });
    const timeStr = date.toLocaleTimeString('en-US', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: timeFormat === '12'
    });
    return `${dateStr} ${timeStr}`;
};

const getPointTypeBadge = (type: string) => {
    const variants = {
        whole_day_absence: { className: 'bg-red-100 text-red-800 border-red-200 dark:bg-red-900 dark:text-red-100', label: 'Whole Day Absence' },
        half_day_absence: { className: 'bg-orange-100 text-orange-800 border-orange-200 dark:bg-orange-900 dark:text-orange-100', label: 'Half-Day Absence' },
        undertime: { className: 'bg-yellow-100 text-yellow-800 border-yellow-200 dark:bg-yellow-900 dark:text-yellow-100', label: 'Undertime' },
        tardy: { className: 'bg-yellow-100 text-yellow-800 border-yellow-200 dark:bg-yellow-900 dark:text-yellow-100', label: 'Tardy' },
    };

    const variant = variants[type as keyof typeof variants] || { className: 'bg-gray-100 text-gray-800 border-gray-200', label: type };

    return (
        <Badge className={`${variant.className} border`}>
            {variant.label}
        </Badge>
    );
};

export default function AttendancePointsIndex({ points, users, stats, filters }: PageProps) {
    useFlashMessage();
    const { auth } = usePage<PageProps>().props;
    const timeFormat = auth.user.time_format as '12' | '24';

    const [selectedUserId, setSelectedUserId] = useState(filters?.user_id || "");
    const [employeeSearch, setEmployeeSearch] = useState("");
    const [selectedPointType, setSelectedPointType] = useState(filters?.point_type || "");
    const [selectedStatus, setSelectedStatus] = useState(filters?.status || "");
    const [dateFrom, setDateFrom] = useState(filters?.date_from || "");
    const [dateTo, setDateTo] = useState(filters?.date_to || "");
    const [filterExpiringSoon, setFilterExpiringSoon] = useState(filters?.expiring_soon === 'true' || false);
    const [filterGbroEligible, setFilterGbroEligible] = useState(filters?.gbro_eligible === 'true' || false);
    const [isRescanOpen, setIsRescanOpen] = useState(false);
    const [rescanDateFrom, setRescanDateFrom] = useState("");
    const [rescanDateTo, setRescanDateTo] = useState("");
    const [isRescanning, setIsRescanning] = useState(false);
    const [isExcuseDialogOpen, setIsExcuseDialogOpen] = useState(false);
    const [selectedPoint, setSelectedPoint] = useState<AttendancePoint | null>(null);
    const [excuseReason, setExcuseReason] = useState("");
    const [notes, setNotes] = useState("");
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [isUnexcuseDialogOpen, setIsUnexcuseDialogOpen] = useState(false);
    const [pointToUnexcuse, setPointToUnexcuse] = useState<number | null>(null);
    const [isViolationDetailsOpen, setIsViolationDetailsOpen] = useState(false);
    const [selectedViolationPoint, setSelectedViolationPoint] = useState<AttendancePoint | null>(null);

    const pointsData = {
        data: points?.data || [],
        links: points?.links || [],
        meta: {
            current_page: points?.current_page ?? 1,
            last_page: points?.last_page ?? 1,
            per_page: points?.per_page ?? 50,
            total: points?.total ?? 0,
            from: points?.from ?? 0,
            to: points?.to ?? 0
        }
    };

    const handleFilter = () => {
        router.get(
            "/attendance-points",
            {
                user_id: selectedUserId,
                point_type: selectedPointType,
                status: selectedStatus,
                date_from: dateFrom,
                date_to: dateTo,
                expiring_soon: filterExpiringSoon ? 'true' : undefined,
                gbro_eligible: filterGbroEligible ? 'true' : undefined,
            },
            { preserveState: true }
        );
    };

    const handleReset = () => {
        setSelectedUserId("");
        setEmployeeSearch("");
        setSelectedPointType("");
        setSelectedStatus("");
        setDateFrom("");
        setDateTo("");
        setFilterExpiringSoon(false);
        setFilterGbroEligible(false);
        router.get("/attendance-points");
    };

    const handleRescan = () => {
        if (!rescanDateFrom || !rescanDateTo) {
            toast.error("Please select both start and end dates");
            return;
        }

        setIsRescanning(true);
        router.post(
            "/attendance-points/rescan",
            {
                date_from: rescanDateFrom,
                date_to: rescanDateTo
            },
            {
                onSuccess: () => {
                    toast.success("Attendance points rescanned successfully");
                },
                onError: () => {
                    toast.error("Failed to rescan attendance points");
                },
                onFinish: () => {
                    setIsRescanning(false);
                    setIsRescanOpen(false);
                    setRescanDateFrom("");
                    setRescanDateTo("");
                }
            }
        );
    };

    const handleExportAllCSV = () => {
        const params = new URLSearchParams({
            ...(selectedUserId && { user_id: selectedUserId }),
            ...(selectedPointType && { point_type: selectedPointType }),
            ...(selectedStatus && { status: selectedStatus }),
            ...(dateFrom && { date_from: dateFrom }),
            ...(dateTo && { date_to: dateTo }),
            ...(filterExpiringSoon && { expiring_soon: 'true' }),
            ...(filterGbroEligible && { gbro_eligible: 'true' }),
        });
        window.location.href = `/attendance-points/export-all?${params.toString()}`;
    };

    const handleExportAllExcel = () => {
        const params = new URLSearchParams({
            ...(selectedUserId && { user_id: selectedUserId }),
            ...(selectedPointType && { point_type: selectedPointType }),
            ...(selectedStatus && { status: selectedStatus }),
            ...(dateFrom && { date_from: dateFrom }),
            ...(dateTo && { date_to: dateTo }),
            ...(filterExpiringSoon && { expiring_soon: 'true' }),
            ...(filterGbroEligible && { gbro_eligible: 'true' }),
        });
        window.location.href = `/attendance-points/export-all-excel?${params.toString()}`;
    };

    const viewUserDetails = (userId: number) => {
        router.get(`/attendance-points/${userId}`);
    };

    const openExcuseDialog = (point: AttendancePoint) => {
        setSelectedPoint(point);
        setExcuseReason(point.excuse_reason || "");
        setNotes(point.notes || "");
        setIsExcuseDialogOpen(true);
    };

    const handleExcuse = () => {
        if (!selectedPoint || !excuseReason) {
            toast.error("Please provide a reason for excusing this point");
            return;
        }

        setIsSubmitting(true);
        router.post(
            `/attendance-points/${selectedPoint.id}/excuse`,
            {
                excuse_reason: excuseReason,
                notes: notes,
            },
            {
                onSuccess: () => {
                    toast.success("Attendance point excused successfully");
                },
                onError: () => {
                    toast.error("Failed to excuse attendance point");
                },
                onFinish: () => {
                    setIsSubmitting(false);
                    setIsExcuseDialogOpen(false);
                    setSelectedPoint(null);
                    setExcuseReason("");
                    setNotes("");
                }
            }
        );
    };

    const handleUnexcuse = (pointId: number) => {
        setPointToUnexcuse(pointId);
        setIsUnexcuseDialogOpen(true);
    };

    const confirmUnexcuse = () => {
        if (!pointToUnexcuse) return;

        router.post(
            `/attendance-points/${pointToUnexcuse}/unexcuse`,
            {},
            {
                onSuccess: () => {
                    toast.success("Excuse removed successfully");
                },
                onError: () => {
                    toast.error("Failed to remove excuse");
                },
                onFinish: () => {
                    setIsUnexcuseDialogOpen(false);
                    setPointToUnexcuse(null);
                }
            }
        );
    };

    const showClearFilters = selectedUserId || selectedPointType || selectedStatus || dateFrom || dateTo || employeeSearch || filterExpiringSoon || filterGbroEligible;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Attendance Points" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3">
                <PageHeader
                    title="Attendance Points"
                    description="Track and manage employee attendance violations and points"
                />

                {/* Statistics Cards */}
                <div className="grid gap-4 md:grid-cols-4">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Active Points</CardTitle>
                            <Award className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-red-600 dark:text-red-400">
                                {stats?.total_points ? Number(stats.total_points).toFixed(2) : '0.00'}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {stats?.total_violations || 0} violations
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Whole Day Absence</CardTitle>
                            <AlertCircle className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-red-600 dark:text-red-400">
                                {stats?.by_type.whole_day_absence ? Number(stats.by_type.whole_day_absence).toFixed(2) : '0.00'}
                            </div>
                            <p className="text-xs text-muted-foreground">1.0 pt each</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Half-Day Absence</CardTitle>
                            <AlertCircle className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-orange-600 dark:text-orange-400">
                                {stats?.by_type.half_day_absence ? Number(stats.by_type.half_day_absence).toFixed(2) : '0.00'}
                            </div>
                            <p className="text-xs text-muted-foreground">0.5 pt each</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Tardy & Undertime</CardTitle>
                            <TrendingUp className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-yellow-600 dark:text-yellow-400">
                                {(Number(stats?.by_type.tardy || 0) + Number(stats?.by_type.undertime || 0)).toFixed(2)}
                            </div>
                            <p className="text-xs text-muted-foreground">0.25 pt each</p>
                        </CardContent>
                    </Card>
                </div>

                {/* Filters */}
                <div className="flex flex-col gap-3">
                    <div className="flex items-center justify-between">
                        <h3 className="text-lg font-medium">Filters</h3>
                        <div className="flex items-center gap-2">
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button variant="outline" size="sm">
                                        <Download className="h-4 w-4 mr-2" />
                                        Export
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem onClick={handleExportAllCSV}>
                                        <FileText className="mr-2 h-4 w-4" />
                                        Export as CSV
                                    </DropdownMenuItem>
                                    <DropdownMenuItem onClick={handleExportAllExcel}>
                                        <FileText className="mr-2 h-4 w-4" />
                                        Export as Excel
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                            <Button
                                variant="outline"
                                onClick={() => setIsRescanOpen(true)}
                                className="gap-2"
                            >
                                <RefreshCw className="h-4 w-4" />
                                Rescan Points
                            </Button>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
                        <Input
                            type="text"
                            placeholder="Search employee name..."
                            value={employeeSearch}
                            onChange={(e) => {
                                setEmployeeSearch(e.target.value);
                                // Find matching user and set ID
                                const matchedUser = users?.find(u =>
                                    u.name.toLowerCase().includes(e.target.value.toLowerCase())
                                );
                                setSelectedUserId(matchedUser?.id.toString() || "");
                            }}
                            className="w-full"
                        />

                        <Select value={selectedPointType || undefined} onValueChange={(value) => setSelectedPointType(value || "")}>
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="All Types" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="whole_day_absence">Whole Day Absence</SelectItem>
                                <SelectItem value="half_day_absence">Half-Day Absence</SelectItem>
                                <SelectItem value="undertime">Undertime</SelectItem>
                                <SelectItem value="tardy">Tardy</SelectItem>
                            </SelectContent>
                        </Select>

                        <Select value={selectedStatus || undefined} onValueChange={(value) => setSelectedStatus(value || "")}>
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="All Status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="active">Active</SelectItem>
                                <SelectItem value="excused">Excused</SelectItem>
                            </SelectContent>
                        </Select>

                        <div className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                            <span className="text-muted-foreground text-xs">From:</span>
                            <input
                                type="date"
                                value={dateFrom}
                                onChange={(e) => setDateFrom(e.target.value)}
                                className="w-full bg-transparent outline-none text-sm"
                            />
                        </div>

                        <div className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                            <span className="text-muted-foreground text-xs">To:</span>
                            <input
                                type="date"
                                value={dateTo}
                                onChange={(e) => setDateTo(e.target.value)}
                                className="w-full bg-transparent outline-none text-sm"
                            />
                        </div>

                        <Button onClick={handleFilter} className="w-full">
                            <Filter className="h-4 w-4 mr-2" />
                            Apply Filters
                        </Button>
                    </div>

                    {/* Additional Filters Row */}
                    <div className="flex flex-wrap gap-4 items-center">
                        <div className="flex items-center space-x-2">
                            <Checkbox
                                id="expiring-soon"
                                checked={filterExpiringSoon}
                                onCheckedChange={(checked) => setFilterExpiringSoon(checked as boolean)}
                            />
                            <label
                                htmlFor="expiring-soon"
                                className="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70 cursor-pointer"
                            >
                                Expiring within 30 days
                            </label>
                        </div>

                        <div className="flex items-center space-x-2">
                            <Checkbox
                                id="gbro-eligible"
                                checked={filterGbroEligible}
                                onCheckedChange={(checked) => setFilterGbroEligible(checked as boolean)}
                            />
                            <label
                                htmlFor="gbro-eligible"
                                className="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70 cursor-pointer"
                            >
                                GBRO Eligible Only
                            </label>
                        </div>
                    </div>

                    {showClearFilters && (
                        <Button variant="outline" onClick={handleReset} className="w-full sm:w-auto">
                            Reset Filters
                        </Button>
                    )}
                </div>

                <div className="flex justify-between items-center text-sm">
                    <div className="text-muted-foreground">
                        Showing {pointsData.meta.from} to {pointsData.meta.to} of {pointsData.meta.total} point
                        {pointsData.meta.total === 1 ? "" : "s"}
                        {showClearFilters ? " (filtered)" : ""}
                    </div>
                </div>

                {/* Desktop Table */}
                <div className="hidden md:block shadow rounded-md overflow-hidden">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Employee</TableHead>
                                    <TableHead>Date</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead className="text-right">Points</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Violation Details</TableHead>
                                    <TableHead>Expires</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {pointsData.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={8} className="text-center py-8 text-muted-foreground">
                                            No attendance points found
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    pointsData.data.map((point) => (
                                        <TableRow key={point.id} className={`hover:bg-muted/50 ${point.is_expired ? 'opacity-60' : ''}`}>
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    <Users className="h-4 w-4 text-muted-foreground" />
                                                    <span className="font-medium">{point.user.name}</span>
                                                </div>
                                            </TableCell>
                                            <TableCell>{formatDate(point.shift_date)}</TableCell>
                                            <TableCell>{getPointTypeBadge(point.point_type)}</TableCell>
                                            <TableCell className="text-right font-bold text-red-600 dark:text-red-400">
                                                {Number(point.points).toFixed(2)}
                                            </TableCell>
                                            <TableCell>
                                                {point.is_expired ? (
                                                    <Badge className="bg-gray-100 text-gray-800 border-gray-200 dark:bg-gray-900 dark:text-gray-100 border">
                                                        {point.expiration_type === 'gbro' ? 'Expired (GBRO)' : 'Expired (SRO)'}
                                                    </Badge>
                                                ) : point.is_excused ? (
                                                    <Badge className="bg-green-100 text-green-800 border-green-200 dark:bg-green-900 dark:text-green-100 border">
                                                        Excused
                                                    </Badge>
                                                ) : (
                                                    <Badge className="bg-red-100 text-red-800 border-red-200 dark:bg-red-900 dark:text-red-100 border">
                                                        Active
                                                    </Badge>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {point.violation_details ? (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="h-8 text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                                                        onClick={() => {
                                                            setSelectedViolationPoint(point);
                                                            setIsViolationDetailsOpen(true);
                                                        }}
                                                    >
                                                        <FileText className="h-4 w-4 mr-1" />
                                                        View Details
                                                    </Button>
                                                ) : (
                                                    <span className="text-muted-foreground text-sm">-</span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {point.expires_at ? (
                                                    <div className="text-sm">
                                                        <div className="font-medium">{formatDate(point.expires_at)}</div>
                                                        {!point.is_expired && (
                                                            <div className="text-xs text-muted-foreground">
                                                                {Math.ceil((new Date(point.expires_at).getTime() - new Date().getTime()) / (1000 * 60 * 60 * 24))} days left
                                                            </div>
                                                        )}
                                                        {point.is_expired && point.expired_at && (
                                                            <div className="text-xs text-muted-foreground">
                                                                Expired: {formatDate(point.expired_at)}
                                                            </div>
                                                        )}
                                                    </div>
                                                ) : (
                                                    <span className="text-muted-foreground text-sm">-</span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <div className="flex items-center justify-end gap-2">
                                                    <button
                                                        onClick={() => viewUserDetails(point.user.id)}
                                                        className="inline-flex items-center gap-1 text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                                                    >
                                                        <Eye className="h-4 w-4" />
                                                        View
                                                    </button>
                                                    <DropdownMenu>
                                                        <DropdownMenuTrigger asChild>
                                                            <Button variant="ghost" size="sm" className="h-8 w-8 p-0">
                                                                <MoreVertical className="h-4 w-4" />
                                                            </Button>
                                                        </DropdownMenuTrigger>
                                                        <DropdownMenuContent align="end">
                                                            {!point.is_excused && !point.is_expired ? (
                                                                <DropdownMenuItem onClick={() => openExcuseDialog(point)}>
                                                                    <CheckCircle className="mr-2 h-4 w-4" />
                                                                    Excuse Point
                                                                </DropdownMenuItem>
                                                            ) : point.is_excused ? (
                                                                <DropdownMenuItem onClick={() => handleUnexcuse(point.id)}>
                                                                    <XCircle className="mr-2 h-4 w-4" />
                                                                    Remove Excuse
                                                                </DropdownMenuItem>
                                                            ) : null}
                                                            <DropdownMenuItem onClick={() => openExcuseDialog(point)}>
                                                                <FileText className="mr-2 h-4 w-4" />
                                                                View Details
                                                            </DropdownMenuItem>
                                                        </DropdownMenuContent>
                                                    </DropdownMenu>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </div>

                {/* Mobile Cards */}
                <div className="md:hidden space-y-4">
                    {pointsData.data.length === 0 ? (
                        <div className="py-12 text-center text-muted-foreground border rounded-lg bg-card">
                            No attendance points found
                        </div>
                    ) : (
                        pointsData.data.map((point) => (
                            <div
                                key={point.id}
                                className="bg-card border rounded-lg p-4 shadow-sm space-y-3"
                            >
                                <div className="flex items-start justify-between">
                                    <div className="flex items-center gap-2 flex-1">
                                        <Users className="h-4 w-4 text-muted-foreground" />
                                        <span className="font-medium text-sm">{point.user.name}</span>
                                    </div>
                                    {point.is_expired ? (
                                        <Badge className="bg-gray-100 text-gray-800 border-gray-200 dark:bg-gray-900 dark:text-gray-100 border text-xs">
                                            {point.expiration_type === 'gbro' ? 'GBRO' : 'Expired'}
                                        </Badge>
                                    ) : point.is_excused ? (
                                        <Badge className="bg-green-100 text-green-800 border-green-200 dark:bg-green-900 dark:text-green-100 border text-xs">
                                            Excused
                                        </Badge>
                                    ) : (
                                        <Badge className="bg-red-100 text-red-800 border-red-200 dark:bg-red-900 dark:text-red-100 border text-xs">
                                            Active
                                        </Badge>
                                    )}
                                </div>

                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <span className="text-muted-foreground text-sm">Date:</span>
                                        <p className="font-medium">{formatDate(point.shift_date)}</p>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">Points:</span>
                                        <p className="font-bold text-red-600 dark:text-red-400">{Number(point.points).toFixed(2)}</p>
                                    </div>
                                </div>

                                <div>
                                    <span className="text-muted-foreground text-sm">Type:</span>
                                    <div className="mt-1">{getPointTypeBadge(point.point_type)}</div>
                                </div>

                                {point.violation_details && (
                                    <div>
                                        <span className="text-muted-foreground text-sm">Violation:</span>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="w-full mt-1 justify-start text-left"
                                            onClick={() => {
                                                setSelectedViolationPoint(point);
                                                setIsViolationDetailsOpen(true);
                                            }}
                                        >
                                            <FileText className="h-4 w-4 mr-2 flex-shrink-0" />
                                            <span className="line-clamp-1">{point.violation_details}</span>
                                        </Button>
                                    </div>
                                )}

                                {point.expires_at && (
                                    <div>
                                        <span className="text-muted-foreground text-sm">Expiration:</span>
                                        <div className="text-sm mt-1">
                                            <p className="font-medium">{formatDate(point.expires_at)}</p>
                                            {!point.is_expired && (
                                                <p className="text-xs text-muted-foreground">
                                                    {Math.ceil((new Date(point.expires_at).getTime() - new Date().getTime()) / (1000 * 60 * 60 * 24))} days remaining
                                                </p>
                                            )}
                                            {point.is_expired && point.expired_at && (
                                                <p className="text-xs text-muted-foreground">
                                                    Expired on: {formatDate(point.expired_at)}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                )}

                                {point.is_excused && point.excused_by && (
                                    <div className="text-xs text-muted-foreground pt-2 border-t">
                                        Excused by: {point.excused_by.name}
                                    </div>
                                )}

                                <div className="flex gap-2 pt-2 border-t">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="flex-1"
                                        onClick={() => viewUserDetails(point.user.id)}
                                    >
                                        <Eye className="h-4 w-4 mr-1" />
                                        View
                                    </Button>
                                    {!point.is_expired && !point.is_excused && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="flex-1"
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                openExcuseDialog(point);
                                            }}
                                        >
                                            <CheckCircle className="h-4 w-4 mr-1" />
                                            Excuse
                                        </Button>
                                    )}
                                    {!point.is_expired && point.is_excused && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="flex-1"
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                handleUnexcuse(point.id);
                                            }}
                                        >
                                            <XCircle className="h-4 w-4 mr-1" />
                                            Remove
                                        </Button>
                                    )}
                                </div>
                            </div>
                        ))
                    )}
                </div>

                <div className="flex justify-center mt-4">
                    {pointsData.links && pointsData.links.length > 0 && (
                        <PaginationNav links={pointsData.links} />
                    )}
                </div>
            </div>

            {/* Rescan Dialog */}
            <Dialog open={isRescanOpen} onOpenChange={setIsRescanOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Rescan Attendance Points</DialogTitle>
                        <DialogDescription>
                            This will scan attendance records within the selected date range and create attendance points
                            for any violations found. Existing points will not be duplicated.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 py-4">
                        <div className="grid gap-2">
                            <Label htmlFor="rescan_date_from">From Date</Label>
                            <Input
                                id="rescan_date_from"
                                type="date"
                                value={rescanDateFrom}
                                onChange={(e) => setRescanDateFrom(e.target.value)}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="rescan_date_to">To Date</Label>
                            <Input
                                id="rescan_date_to"
                                type="date"
                                value={rescanDateTo}
                                onChange={(e) => setRescanDateTo(e.target.value)}
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => {
                                setIsRescanOpen(false);
                                setRescanDateFrom("");
                                setRescanDateTo("");
                            }}
                            disabled={isRescanning}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={handleRescan}
                            disabled={!rescanDateFrom || !rescanDateTo || isRescanning}
                        >
                            {isRescanning ? (
                                <>
                                    <RefreshCw className="mr-2 h-4 w-4 animate-spin" />
                                    Rescanning...
                                </>
                            ) : (
                                <>
                                    <RefreshCw className="mr-2 h-4 w-4" />
                                    Rescan
                                </>
                            )}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Excuse Point Dialog */}
            <Dialog open={isExcuseDialogOpen} onOpenChange={setIsExcuseDialogOpen}>
                <DialogContent className="sm:max-w-[500px]">
                    <DialogHeader>
                        <DialogTitle>
                            {selectedPoint?.is_excused ? 'Point Details' : 'Excuse Attendance Point'}
                        </DialogTitle>
                        <DialogDescription>
                            {selectedPoint?.is_excused
                                ? 'View excuse reason and notes for this attendance point.'
                                : 'Provide a reason to excuse this attendance point. This will waive the accumulated points.'}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 py-4">
                        {selectedPoint && (
                            <div className="space-y-2 p-3 bg-muted rounded-md">
                                <div className="flex justify-between text-sm">
                                    <span className="text-muted-foreground">Employee:</span>
                                    <span className="font-medium">{selectedPoint.user.name}</span>
                                </div>
                                <div className="flex justify-between text-sm">
                                    <span className="text-muted-foreground">Date:</span>
                                    <span className="font-medium">{formatDate(selectedPoint.shift_date)}</span>
                                </div>
                                <div className="flex justify-between text-sm">
                                    <span className="text-muted-foreground">Type:</span>
                                    <span>{getPointTypeBadge(selectedPoint.point_type)}</span>
                                </div>
                                <div className="flex justify-between text-sm">
                                    <span className="text-muted-foreground">Points:</span>
                                    <span className="font-bold text-red-600">{Number(selectedPoint.points).toFixed(2)}</span>
                                </div>
                            </div>
                        )}

                        <div className="grid gap-2">
                            <Label htmlFor="excuse_reason">
                                Excuse Reason <span className="text-red-500">*</span>
                            </Label>
                            <Textarea
                                id="excuse_reason"
                                placeholder="Enter reason for excusing this point..."
                                value={excuseReason}
                                onChange={(e) => setExcuseReason(e.target.value)}
                                rows={3}
                                disabled={selectedPoint?.is_excused}
                                className="resize-none"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="notes">Additional Notes (Optional)</Label>
                            <Textarea
                                id="notes"
                                placeholder="Add any additional notes..."
                                value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                                rows={3}
                                disabled={selectedPoint?.is_excused}
                                className="resize-none"
                            />
                        </div>

                        {selectedPoint?.is_excused && selectedPoint.excused_by && (
                            <div className="text-xs text-muted-foreground p-3 bg-muted rounded-md">
                                <p>Excused by: <span className="font-medium">{selectedPoint.excused_by.name}</span></p>
                                {selectedPoint.excused_at && (
                                    <p>On: <span className="font-medium">{formatDateTime(selectedPoint.excused_at, timeFormat)}</span></p>
                                )}
                            </div>
                        )}
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => {
                                setIsExcuseDialogOpen(false);
                                setSelectedPoint(null);
                                setExcuseReason("");
                                setNotes("");
                            }}
                            disabled={isSubmitting}
                        >
                            {selectedPoint?.is_excused ? 'Close' : 'Cancel'}
                        </Button>
                        {!selectedPoint?.is_excused && (
                            <Button
                                onClick={handleExcuse}
                                disabled={!excuseReason || isSubmitting}
                            >
                                {isSubmitting ? (
                                    <>
                                        <RefreshCw className="mr-2 h-4 w-4 animate-spin" />
                                        Excusing...
                                    </>
                                ) : (
                                    <>
                                        <CheckCircle className="mr-2 h-4 w-4" />
                                        Excuse Point
                                    </>
                                )}
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Unexcuse Confirmation Dialog */}
            <AlertDialog open={isUnexcuseDialogOpen} onOpenChange={setIsUnexcuseDialogOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Remove Excuse</AlertDialogTitle>
                        <AlertDialogDescription>
                            Are you sure you want to remove the excuse for this attendance point?
                            This will restore the point to active status.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel onClick={() => setPointToUnexcuse(null)}>
                            Cancel
                        </AlertDialogCancel>
                        <AlertDialogAction onClick={confirmUnexcuse}>
                            Remove Excuse
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* Violation Details Dialog */}
            <Dialog open={isViolationDetailsOpen} onOpenChange={setIsViolationDetailsOpen}>
                <DialogContent className="sm:max-w-[600px]">
                    <DialogHeader>
                        <DialogTitle>Violation Details</DialogTitle>
                        <DialogDescription>
                            Complete information about this attendance violation
                        </DialogDescription>
                    </DialogHeader>

                    {selectedViolationPoint && (
                        <div className="space-y-4">
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <Label className="text-sm font-medium text-muted-foreground">Employee</Label>
                                    <p className="text-sm font-medium mt-1">{selectedViolationPoint.user.name}</p>
                                </div>
                                <div>
                                    <Label className="text-sm font-medium text-muted-foreground">Date</Label>
                                    <p className="text-sm font-medium mt-1">{formatDate(selectedViolationPoint.shift_date)}</p>
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <Label className="text-sm font-medium text-muted-foreground">Type</Label>
                                    <div className="mt-1">{getPointTypeBadge(selectedViolationPoint.point_type)}</div>
                                </div>
                                <div>
                                    <Label className="text-sm font-medium text-muted-foreground">Points</Label>
                                    <p className="text-lg font-bold text-red-600 dark:text-red-400 mt-1">
                                        {Number(selectedViolationPoint.points).toFixed(2)}
                                    </p>
                                </div>
                            </div>

                            <div>
                                <Label className="text-sm font-medium text-muted-foreground">Violation Details</Label>
                                <div className="mt-2 p-3 bg-muted rounded-md">
                                    <p className="text-sm">{selectedViolationPoint.violation_details}</p>
                                </div>
                            </div>

                            {selectedViolationPoint.tardy_minutes && (
                                <div>
                                    <Label className="text-sm font-medium text-muted-foreground">Tardy Duration</Label>
                                    <p className="text-sm mt-1">{selectedViolationPoint.tardy_minutes} minutes</p>
                                </div>
                            )}

                            {selectedViolationPoint.undertime_minutes && (
                                <div>
                                    <Label className="text-sm font-medium text-muted-foreground">Undertime Duration</Label>
                                    <p className="text-sm mt-1">{selectedViolationPoint.undertime_minutes} minutes</p>
                                </div>
                            )}

                            {selectedViolationPoint.expires_at && (
                                <div className="grid grid-cols-2 gap-4 pt-4 border-t">
                                    <div>
                                        <Label className="text-sm font-medium text-muted-foreground">Expiration Date</Label>
                                        <p className="text-sm font-medium mt-1">{formatDate(selectedViolationPoint.expires_at)}</p>
                                        {!selectedViolationPoint.is_expired && (
                                            <p className="text-xs text-muted-foreground mt-1">
                                                {Math.ceil((new Date(selectedViolationPoint.expires_at).getTime() - new Date().getTime()) / (1000 * 60 * 60 * 24))} days remaining
                                            </p>
                                        )}
                                    </div>
                                    <div>
                                        <Label className="text-sm font-medium text-muted-foreground">Status</Label>
                                        <div className="mt-1">
                                            {selectedViolationPoint.is_expired ? (
                                                <Badge className="bg-gray-100 text-gray-800 border-gray-200 dark:bg-gray-900 dark:text-gray-100 border">
                                                    {selectedViolationPoint.expiration_type === 'gbro' ? 'Expired (GBRO)' : 'Expired (SRO)'}
                                                </Badge>
                                            ) : selectedViolationPoint.is_excused ? (
                                                <Badge className="bg-green-100 text-green-800 border-green-200 dark:bg-green-900 dark:text-green-100 border">
                                                    Excused
                                                </Badge>
                                            ) : (
                                                <Badge className="bg-red-100 text-red-800 border-red-200 dark:bg-red-900 dark:text-red-100 border">
                                                    Active
                                                </Badge>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            )}

                            {selectedViolationPoint.is_expired && selectedViolationPoint.expired_at && (
                                <div>
                                    <Label className="text-sm font-medium text-muted-foreground">Expired On</Label>
                                    <p className="text-sm mt-1">{formatDate(selectedViolationPoint.expired_at)}</p>
                                </div>
                            )}

                            {/* GBRO Eligibility Info */}
                            {selectedViolationPoint && !selectedViolationPoint.is_expired && !selectedViolationPoint.is_excused && selectedViolationPoint.eligible_for_gbro && (
                                <div className="pt-4 border-t">
                                    <div className="rounded-lg border border-green-200 bg-green-50 dark:bg-green-950 p-4">
                                        <div className="flex items-start gap-3">
                                            <CheckCircle className="h-5 w-5 text-green-600 dark:text-green-400 mt-0.5 flex-shrink-0" />
                                            <div>
                                                <p className="font-semibold text-green-800 dark:text-green-200">
                                                    GBRO Eligible Point
                                                </p>
                                                <p className="text-sm text-green-700 dark:text-green-300 mt-1">
                                                    This point is eligible for Good Behavior Roll Off (GBRO).
                                                    After <strong>60 consecutive days</strong> without new violations,
                                                    the <strong>last 2 eligible points</strong> will be automatically removed from the employee's record.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            )}

                            {selectedViolationPoint && !selectedViolationPoint.eligible_for_gbro && !selectedViolationPoint.is_expired && !selectedViolationPoint.is_excused && (
                                <div className="pt-4 border-t">
                                    <div className="rounded-lg border border-orange-200 bg-orange-50 dark:bg-orange-950 p-4">
                                        <div className="flex items-start gap-3">
                                            <AlertCircle className="h-5 w-5 text-orange-600 dark:text-orange-400 mt-0.5 flex-shrink-0" />
                                            <div>
                                                <p className="font-semibold text-orange-800 dark:text-orange-200">
                                                    Not Eligible for GBRO
                                                </p>
                                                <p className="text-sm text-orange-700 dark:text-orange-300 mt-1">
                                                    This point type (likely NCNS or FTN) requires a <strong>1-year expiration period</strong>
                                                    and cannot be removed through Good Behavior Roll Off.
                                                    It will expire automatically after {selectedViolationPoint.expires_at ? formatDate(selectedViolationPoint.expires_at) : '1 year'}.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            )}

                            {selectedViolationPoint.is_excused && selectedViolationPoint.excuse_reason && (
                                <div className="pt-4 border-t">
                                    <Label className="text-sm font-medium text-muted-foreground">Excuse Reason</Label>
                                    <p className="text-sm mt-1">{selectedViolationPoint.excuse_reason}</p>
                                    {selectedViolationPoint.excused_by && selectedViolationPoint.excused_at && (
                                        <p className="text-xs text-muted-foreground mt-1">
                                            Excused by: {selectedViolationPoint.excused_by.name} on {formatDateTime(selectedViolationPoint.excused_at, timeFormat)}
                                        </p>
                                    )}
                                </div>
                            )}
                        </div>
                    )}

                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => {
                                setIsViolationDetailsOpen(false);
                                setSelectedViolationPoint(null);
                            }}
                        >
                            Close
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
