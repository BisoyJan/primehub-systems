import React, { useState } from "react";
import { Head, router, usePage } from "@inertiajs/react";
import AppLayout from "@/layouts/app-layout";
import { useFlashMessage } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { Button } from "@/components/ui/button";
import { toast } from "sonner";
import { usePermission } from "@/hooks/useAuthorization";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
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
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { ArrowLeft, Award, AlertCircle, TrendingUp, Calendar, MoreVertical, CheckCircle, XCircle, FileText, Download, BarChart3 } from "lucide-react";
import type { BreadcrumbItem, SharedData } from "@/types";

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
    user?: User;
    // Expiration fields
    expires_at: string | null;
    expiration_type: 'sro' | 'gbro' | 'none' | null;
    is_expired: boolean;
    expired_at: string | null;
    violation_details: string | null;
    tardy_minutes: number | null;
    undertime_minutes: number | null;
    eligible_for_gbro: boolean;
    gbro_applied_at: string | null;
    gbro_batch_id: string | null;
}

interface Totals {
    total_points: number;
    excused_points: number;
    expired_points: number;
    by_type: {
        whole_day_absence: number;
        half_day_absence: number;
        undertime: number;
        tardy: number;
    };
    count_by_type: {
        whole_day_absence: number;
        half_day_absence: number;
        undertime: number;
        tardy: number;
    };
}

interface DateRange {
    start: string;
    end: string;
}

interface GbroStats {
    days_clean: number;
    days_until_gbro: number;
    eligible_points_count: number;
    last_violation_date: string | null;
    is_gbro_ready: boolean;
}

interface PageProps extends SharedData {
    user: User;
    points: AttendancePoint[];
    totals: Totals;
    dateRange: DateRange;
    gbroStats: GbroStats;
}

const formatDate = (date: string) => {
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric'
    });
};

const formatDateTime = (dateTime: string, timeFormat: '12' | '24' = '24') => {
    const date = new Date(dateTime);
    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: timeFormat === '12'
    }).format(date);
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

const AttendancePointsShow: React.FC<PageProps> = ({ user, points, totals, dateRange, gbroStats }) => {
    useFlashMessage();
    const { auth } = usePage<PageProps>().props;
    const timeFormat = auth.user.time_format || '24';
    const { can } = usePermission();

    const [isExcuseDialogOpen, setIsExcuseDialogOpen] = useState(false);
    const [selectedPoint, setSelectedPoint] = useState<AttendancePoint | null>(null);
    const [excuseReason, setExcuseReason] = useState("");
    const [notes, setNotes] = useState("");
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [isUnexcuseDialogOpen, setIsUnexcuseDialogOpen] = useState(false);
    const [pointToUnexcuse, setPointToUnexcuse] = useState<number | null>(null);
    const [isViolationDetailsOpen, setIsViolationDetailsOpen] = useState(false);
    const [selectedViolationPoint, setSelectedViolationPoint] = useState<AttendancePoint | null>(null);
    const [isStatisticsDialogOpen, setIsStatisticsDialogOpen] = useState(false);
    const [statistics, setStatistics] = useState<{
        total_points: number;
        active_points: number;
        expired_points: number;
        excused_points: number;
        by_type: {
            whole_day_absence: number;
            half_day_absence: number;
            undertime: number;
            tardy: number;
        };
    } | null>(null);
    const [isLoadingStats, setIsLoadingStats] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Attendance Points', href: '/attendance-points' },
        { title: `${user.name}'s Points`, href: '' }
    ];

    const goBack = () => {
        router.get("/attendance-points");
    };

    const handleExportCSV = () => {
        window.location.href = `/attendance-points/${user.id}/export`;
    };

    const handleExportExcel = () => {
        window.location.href = `/attendance-points/${user.id}/export-excel`;
    };

    const handleViewStatistics = async () => {
        setIsLoadingStats(true);
        setIsStatisticsDialogOpen(true);

        try {
            const response = await fetch(`/attendance-points/${user.id}/statistics`);
            const data = await response.json();
            setStatistics(data);
        } catch {
            toast.error("Failed to load statistics");
            setIsStatisticsDialogOpen(false);
        } finally {
            setIsLoadingStats(false);
        }
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

    const formattedDateRange = `${formatDate(dateRange.start)} - ${formatDate(dateRange.end)}`;

    // Calculate which points are the last 2 eligible for GBRO
    const eligibleGbroPoints = React.useMemo(() => {
        return points
            .filter(p => !p.is_excused && !p.is_expired && p.eligible_for_gbro)
            .sort((a, b) => new Date(b.shift_date).getTime() - new Date(a.shift_date).getTime())
            .slice(0, 2)
            .map(p => p.id);
    }, [points]);

    const isEligibleForGbroDeduction = (pointId: number) => {
        return eligibleGbroPoints.includes(pointId);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Attendance Points - ${user.name}`} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3">
                <div className="flex items-center justify-between gap-4">
                    <Button variant="ghost" size="sm" onClick={goBack}>
                        <ArrowLeft className="h-4 w-4 mr-2" />
                        Back to Points
                    </Button>

                    <div className="flex items-center gap-2">
                        <Button variant="outline" size="sm" onClick={handleViewStatistics}>
                            <BarChart3 className="h-4 w-4 mr-2" />
                            View Statistics
                        </Button>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="outline" size="sm">
                                    <Download className="h-4 w-4 mr-2" />
                                    Export
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem onClick={handleExportCSV}>
                                    <FileText className="mr-2 h-4 w-4" />
                                    Export as CSV
                                </DropdownMenuItem>
                                <DropdownMenuItem onClick={handleExportExcel}>
                                    <FileText className="mr-2 h-4 w-4" />
                                    Export as Excel
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>

                <PageHeader
                    title={`${user.name}'s Attendance Points`}
                    description={formattedDateRange}
                />

                {/* Summary Cards */}
                <div className="grid gap-4 md:grid-cols-4">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Total Active Points</CardTitle>
                            <Award className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-red-600 dark:text-red-400">
                                {Number(totals.total_points).toFixed(2)}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {Number(totals.excused_points).toFixed(2)} excused · {Number(totals.expired_points || 0).toFixed(2)} expired
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
                                {Number(totals.by_type.whole_day_absence).toFixed(2)}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {totals.count_by_type.whole_day_absence} occurrences
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Half-Day Absence</CardTitle>
                            <AlertCircle className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-orange-600 dark:text-orange-400">
                                {Number(totals.by_type.half_day_absence).toFixed(2)}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {totals.count_by_type.half_day_absence} occurrences
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Tardy & Undertime</CardTitle>
                            <TrendingUp className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-yellow-600 dark:text-yellow-400">
                                {(Number(totals.by_type.tardy) + Number(totals.by_type.undertime)).toFixed(2)}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {totals.count_by_type.tardy + totals.count_by_type.undertime} occurrences
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {/* GBRO Statistics Card */}
                {gbroStats.last_violation_date && (
                    <Card className={`border-2 ${gbroStats.is_gbro_ready ? 'border-green-500 bg-green-50 dark:bg-green-950' : 'border-blue-500 bg-blue-50 dark:bg-blue-950'}`}>
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <CheckCircle className={`h-4 w-4 ${gbroStats.is_gbro_ready ? 'text-green-600' : 'text-blue-600'}`} />
                                Good Behavior Roll Off (GBRO) Status
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="grid gap-3 md:grid-cols-3">
                                <div>
                                    <Label className="text-xs font-medium text-muted-foreground">Days Without Violation</Label>
                                    <p className="text-xl font-bold mt-0.5">
                                        {Math.floor(gbroStats.days_clean)} days
                                    </p>
                                </div>
                                <div>
                                    <Label className="text-xs font-medium text-muted-foreground">Days Until GBRO Eligibility</Label>
                                    <p className="text-xl font-bold mt-0.5">
                                        {gbroStats.days_until_gbro === 0 ? (
                                            <span className="text-green-600 dark:text-green-400">Eligible Now!</span>
                                        ) : (
                                            <span>{Math.floor(gbroStats.days_until_gbro)} days</span>
                                        )}
                                    </p>
                                </div>
                                <div>
                                    <Label className="text-xs font-medium text-muted-foreground">Eligible Points for Deduction</Label>
                                    <p className="text-xl font-bold mt-0.5">
                                        {gbroStats.eligible_points_count} {gbroStats.eligible_points_count === 1 ? 'point' : 'points'}
                                    </p>
                                </div>
                            </div>

                            {gbroStats.is_gbro_ready && gbroStats.eligible_points_count > 0 && (
                                <div className="rounded-lg border border-green-200 bg-green-100 dark:bg-green-900 dark:border-green-800 p-3">
                                    <div className="flex items-start gap-2">
                                        <CheckCircle className="h-4 w-4 text-green-600 dark:text-green-400 mt-0.5 flex-shrink-0" />
                                        <div>
                                            <p className="font-semibold text-sm text-green-800 dark:text-green-200">
                                                🎉 GBRO Eligible!
                                            </p>
                                            <p className="text-xs text-green-700 dark:text-green-300 mt-0.5">
                                                {Math.floor(gbroStats.days_clean)} days without violations.
                                                The <strong>last {gbroStats.eligible_points_count === 1 ? '1 violation point' : '2 violation points'}</strong> will be automatically deducted.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            )}

                            {!gbroStats.is_gbro_ready && gbroStats.days_until_gbro > 0 && (
                                <div className="rounded-lg border border-blue-200 bg-blue-100 dark:bg-blue-900 dark:border-blue-800 p-3">
                                    <div className="flex items-start gap-2">
                                        <AlertCircle className="h-4 w-4 text-blue-600 dark:text-blue-400 mt-0.5 flex-shrink-0" />
                                        <div>
                                            <p className="font-semibold text-sm text-blue-800 dark:text-blue-200">
                                                Keep up the good work!
                                            </p>
                                            <p className="text-xs text-blue-700 dark:text-blue-300 mt-0.5">
                                                After <strong>{Math.floor(gbroStats.days_until_gbro)} more days</strong> without violations,
                                                {gbroStats.eligible_points_count > 0
                                                    ? ` the last ${gbroStats.eligible_points_count === 1 ? '1 violation point' : '2 violation points'} will be automatically removed.`
                                                    : ' GBRO benefits will be available if there are eligible points.'}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            )}

                            <div className="text-xs text-muted-foreground">
                                Last violation: {formatDate(gbroStats.last_violation_date)}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Points Table */}
                <Card>
                    <CardHeader>
                        <CardTitle>Point History</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {points.length === 0 ? (
                            <div className="py-12 text-center text-muted-foreground">
                                No attendance points for this period
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Date</TableHead>
                                            <TableHead>Type</TableHead>
                                            <TableHead className="text-right">Points</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Violation Details</TableHead>
                                            <TableHead>Expires</TableHead>
                                            {can('attendance_points.excuse') && <TableHead className="text-right">Actions</TableHead>}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {points.map((point) => {
                                            const isGbroEligible = isEligibleForGbroDeduction(point.id);
                                            return (
                                                <TableRow
                                                    key={point.id}
                                                    className={`
                                                        ${point.is_expired ? 'opacity-60' : point.is_excused ? 'opacity-60' : ''}
                                                        ${isGbroEligible ? 'bg-green-50 dark:bg-green-950 border-l-4 border-l-green-500' : ''}
                                                    `}
                                                >
                                                    <TableCell>
                                                        <div className="flex items-center gap-2">
                                                            <Calendar className="h-4 w-4 text-muted-foreground" />
                                                            <span>{formatDate(point.shift_date)}</span>
                                                            {isGbroEligible && (
                                                                <Badge className="bg-green-600 text-white text-xs">
                                                                    {gbroStats.is_gbro_ready ? 'Ready for GBRO' : `GBRO in ${Math.floor(gbroStats.days_until_gbro)}d`}
                                                                </Badge>
                                                            )}
                                                        </div>
                                                    </TableCell>
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
                                                    {can('attendance_points.excuse') && (
                                                        <TableCell className="text-right">
                                                            <DropdownMenu>
                                                                <DropdownMenuTrigger asChild>
                                                                    <Button variant="ghost" size="sm" className="h-8 w-8 p-0">
                                                                        <MoreVertical className="h-4 w-4" />
                                                                    </Button>
                                                                </DropdownMenuTrigger>
                                                                <DropdownMenuContent align="end">
                                                                    {!point.is_expired && !point.is_excused && (
                                                                        <DropdownMenuItem onClick={() => openExcuseDialog(point)}>
                                                                            <CheckCircle className="mr-2 h-4 w-4" />
                                                                            Excuse Point
                                                                        </DropdownMenuItem>
                                                                    )}
                                                                    {point.is_excused && (
                                                                        <DropdownMenuItem onClick={() => handleUnexcuse(point.id)}>
                                                                            <XCircle className="mr-2 h-4 w-4" />
                                                                            Remove Excuse
                                                                        </DropdownMenuItem>
                                                                    )}
                                                                </DropdownMenuContent>
                                                            </DropdownMenu>
                                                        </TableCell>
                                                    )}
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            {/* Excuse Point Dialog */}
            <Dialog open={isExcuseDialogOpen} onOpenChange={setIsExcuseDialogOpen}>
                <DialogContent className="sm:max-w-[500px]">
                    <DialogHeader>
                        <DialogTitle>
                            {selectedPoint?.is_excused ? 'View Excuse Details' : 'Excuse Attendance Point'}
                        </DialogTitle>
                        <DialogDescription>
                            {selectedPoint?.is_excused
                                ? 'Review the excuse details for this point.'
                                : 'Provide a reason to excuse this attendance point. This will remove it from the active count.'}
                        </DialogDescription>
                    </DialogHeader>

                    {selectedPoint && (
                        <div className="space-y-4">
                            <div className="rounded-lg border bg-muted/50 p-4 space-y-2">
                                <div className="flex justify-between text-sm">
                                    <span className="text-muted-foreground">Employee:</span>
                                    <span className="font-medium">{user.name}</span>
                                </div>
                                <div className="flex justify-between text-sm">
                                    <span className="text-muted-foreground">Date:</span>
                                    <span className="font-medium">
                                        {new Date(selectedPoint.shift_date).toLocaleDateString()}
                                    </span>
                                </div>
                                <div className="flex justify-between text-sm">
                                    <span className="text-muted-foreground">Type:</span>
                                    <span className="font-medium">{selectedPoint.point_type}</span>
                                </div>
                                <div className="flex justify-between text-sm">
                                    <span className="text-muted-foreground">Points:</span>
                                    <span className="font-medium text-red-600">
                                        {Number(selectedPoint.points).toFixed(2)}
                                    </span>
                                </div>
                            </div>

                            {selectedPoint.is_excused && (
                                <div className="rounded-lg border border-green-200 bg-green-50 p-4 space-y-2">
                                    <div className="flex items-center gap-2 text-green-700 font-medium">
                                        <CheckCircle className="h-4 w-4" />
                                        <span>This point has been excused</span>
                                    </div>
                                    <div className="text-sm text-green-600">
                                        <span className="text-muted-foreground">By:</span>{' '}
                                        {selectedPoint.excused_by?.name || 'Unknown'}
                                    </div>
                                    <div className="text-sm text-green-600">
                                        <span className="text-muted-foreground">On:</span>{' '}
                                        {selectedPoint.excused_at
                                            ? formatDateTime(selectedPoint.excused_at, timeFormat)
                                            : 'Unknown'}
                                    </div>
                                </div>
                            )}

                            <div className="space-y-2">
                                <Label htmlFor="excuse-reason">
                                    Reason <span className="text-red-500">*</span>
                                </Label>
                                <Textarea
                                    id="excuse-reason"
                                    placeholder="Enter the reason for excusing this point..."
                                    value={excuseReason}
                                    onChange={(e) => setExcuseReason(e.target.value)}
                                    disabled={selectedPoint.is_excused || isSubmitting}
                                    className="min-h-[100px]"
                                    required
                                />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="excuse-notes">Additional Notes</Label>
                                <Textarea
                                    id="excuse-notes"
                                    placeholder="Add any additional context or notes..."
                                    value={notes}
                                    onChange={(e) => setNotes(e.target.value)}
                                    disabled={selectedPoint.is_excused || isSubmitting}
                                    className="min-h-[80px]"
                                />
                            </div>
                        </div>
                    )}

                    {!selectedPoint?.is_excused && (
                        <DialogFooter>
                            <Button
                                variant="outline"
                                onClick={() => setIsExcuseDialogOpen(false)}
                                disabled={isSubmitting}
                            >
                                Cancel
                            </Button>
                            <Button onClick={handleExcuse} disabled={isSubmitting || !excuseReason.trim()}>
                                {isSubmitting ? 'Excusing...' : 'Excuse Point'}
                            </Button>
                        </DialogFooter>
                    )}
                </DialogContent>
            </Dialog>

            {/* Unexcuse Confirmation Dialog */}
            <AlertDialog open={isUnexcuseDialogOpen} onOpenChange={setIsUnexcuseDialogOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Remove Excuse?</AlertDialogTitle>
                        <AlertDialogDescription>
                            This will remove the excuse and restore the point to active status. The point will
                            count towards the employee's total again. This action cannot be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={isSubmitting}>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={confirmUnexcuse}
                            disabled={isSubmitting}
                            className="bg-red-600 hover:bg-red-700"
                        >
                            {isSubmitting ? 'Removing...' : 'Remove Excuse'}
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
                                    <p className="text-sm font-medium mt-1">{user.name}</p>
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

                            {/* GBRO Eligibility Section */}
                            {selectedViolationPoint && !selectedViolationPoint.is_expired && !selectedViolationPoint.is_excused && selectedViolationPoint.eligible_for_gbro && (
                                <div className="pt-4 border-t">
                                    {isEligibleForGbroDeduction(selectedViolationPoint.id) ? (
                                        <div className="rounded-lg border-2 border-green-500 bg-green-50 dark:bg-green-950 p-4">
                                            <div className="flex items-start gap-3">
                                                <CheckCircle className="h-5 w-5 text-green-600 dark:text-green-400 mt-0.5 flex-shrink-0" />
                                                <div>
                                                    <p className="font-semibold text-green-800 dark:text-green-200">
                                                        🎯 GBRO Eligible Point
                                                    </p>
                                                    <p className="text-sm text-green-700 dark:text-green-300 mt-1">
                                                        This is one of the <strong>last 2 violation points</strong> that will be automatically deducted
                                                        after {Math.floor(gbroStats.days_clean)} days without violations.
                                                        {gbroStats.is_gbro_ready && (
                                                            <span className="block mt-1 font-semibold">
                                                                ✅ Ready for GBRO processing!
                                                            </span>
                                                        )}
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    ) : (
                                        <div className="rounded-lg border border-blue-200 bg-blue-50 dark:bg-blue-950 p-4">
                                            <div className="flex items-start gap-3">
                                                <AlertCircle className="h-5 w-5 text-blue-600 dark:text-blue-400 mt-0.5 flex-shrink-0" />
                                                <div>
                                                    <p className="font-semibold text-blue-800 dark:text-blue-200">
                                                        GBRO Eligible
                                                    </p>
                                                    <p className="text-sm text-blue-700 dark:text-blue-300 mt-1">
                                                        This point is eligible for Good Behavior Roll Off.
                                                        After <strong>60 consecutive days</strong> without violations,
                                                        the last 2 eligible points will be automatically removed.
                                                        <span className="block mt-1">
                                                            Current status: {Math.floor(gbroStats.days_clean)} days clean, {Math.floor(gbroStats.days_until_gbro)} days until GBRO eligibility.
                                                        </span>
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    )}
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

            {/* Statistics Dialog */}
            <Dialog open={isStatisticsDialogOpen} onOpenChange={setIsStatisticsDialogOpen}>
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Detailed Statistics - {user.name}</DialogTitle>
                        <DialogDescription>
                            Comprehensive breakdown of attendance points
                        </DialogDescription>
                    </DialogHeader>

                    {isLoadingStats ? (
                        <div className="flex items-center justify-center py-8">
                            <div className="text-muted-foreground">Loading statistics...</div>
                        </div>
                    ) : statistics ? (
                        <div className="space-y-6">
                            {/* Overview Section */}
                            <div>
                                <h3 className="text-sm font-semibold mb-3">Overview</h3>
                                <div className="grid grid-cols-2 gap-4">
                                    <Card>
                                        <CardHeader className="pb-2">
                                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                                Total Active Points
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <div className="text-2xl font-bold text-red-600">
                                                {Number(statistics.total_points).toFixed(2)}
                                            </div>
                                        </CardContent>
                                    </Card>

                                    <Card>
                                        <CardHeader className="pb-2">
                                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                                Active Violations
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <div className="text-2xl font-bold">
                                                {statistics.active_points}
                                            </div>
                                        </CardContent>
                                    </Card>

                                    <Card>
                                        <CardHeader className="pb-2">
                                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                                Expired Points
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <div className="text-2xl font-bold text-gray-500">
                                                {statistics.expired_points}
                                            </div>
                                        </CardContent>
                                    </Card>

                                    <Card>
                                        <CardHeader className="pb-2">
                                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                                Excused Points
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <div className="text-2xl font-bold text-green-600">
                                                {statistics.excused_points}
                                            </div>
                                        </CardContent>
                                    </Card>
                                </div>
                            </div>

                            {/* By Type Section */}
                            <div>
                                <h3 className="text-sm font-semibold mb-3">Points by Type</h3>
                                <div className="space-y-3">
                                    <div className="flex items-center justify-between p-3 rounded-lg border bg-red-50 dark:bg-red-950 border-red-200">
                                        <div className="flex items-center gap-2">
                                            <AlertCircle className="h-4 w-4 text-red-600" />
                                            <span className="text-sm font-medium">Whole Day Absence</span>
                                        </div>
                                        <span className="text-lg font-bold text-red-600">
                                            {Number(statistics.by_type.whole_day_absence).toFixed(2)}
                                        </span>
                                    </div>

                                    <div className="flex items-center justify-between p-3 rounded-lg border bg-orange-50 dark:bg-orange-950 border-orange-200">
                                        <div className="flex items-center gap-2">
                                            <AlertCircle className="h-4 w-4 text-orange-600" />
                                            <span className="text-sm font-medium">Half Day Absence</span>
                                        </div>
                                        <span className="text-lg font-bold text-orange-600">
                                            {Number(statistics.by_type.half_day_absence).toFixed(2)}
                                        </span>
                                    </div>

                                    <div className="flex items-center justify-between p-3 rounded-lg border bg-yellow-50 dark:bg-yellow-950 border-yellow-200">
                                        <div className="flex items-center gap-2">
                                            <TrendingUp className="h-4 w-4 text-yellow-600" />
                                            <span className="text-sm font-medium">Tardy</span>
                                        </div>
                                        <span className="text-lg font-bold text-yellow-600">
                                            {Number(statistics.by_type.tardy).toFixed(2)}
                                        </span>
                                    </div>

                                    <div className="flex items-center justify-between p-3 rounded-lg border bg-yellow-50 dark:bg-yellow-950 border-yellow-200">
                                        <div className="flex items-center gap-2">
                                            <TrendingUp className="h-4 w-4 text-yellow-600" />
                                            <span className="text-sm font-medium">Undertime</span>
                                        </div>
                                        <span className="text-lg font-bold text-yellow-600">
                                            {Number(statistics.by_type.undertime).toFixed(2)}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    ) : null}

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setIsStatisticsDialogOpen(false)}>
                            Close
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
};

export default AttendancePointsShow;
