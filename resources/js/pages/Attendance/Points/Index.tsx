import React, { useState, useEffect, useRef, useCallback } from "react";
import { Head, router } from "@inertiajs/react";
import AppLayout from "@/layouts/app-layout";
import { useFlashMessage, usePageMeta, usePageLoading } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { Button } from "@/components/ui/button";
import type { SharedData, UserRole } from "@/types";
import { formatDateShort, formatDateTime } from "@/lib/utils";
import { toast } from "sonner";
import { Can, HasRole } from '@/components/authorization';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue
} from "@/components/ui/select";
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from "@/components/ui/command";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Input } from "@/components/ui/input";
import { DatePicker } from "@/components/ui/date-picker";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Checkbox } from "@/components/ui/checkbox";
import { Progress } from "@/components/ui/progress";
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
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import PaginationNav, { PaginationLink } from "@/components/pagination-nav";
import { AlertCircle, TrendingUp, Users, Eye, Award, RefreshCw, CheckCircle, XCircle, FileText, Download, Check, ChevronsUpDown, Search, Plus, Pencil, Trash2, Loader2, Play, Pause, Settings, RotateCcw, AlertTriangle, ClipboardList, BellOff } from "lucide-react";
import { LoadingOverlay } from "@/components/LoadingOverlay";
import {
    index as attendancePointsIndex,
    show as attendancePointsShow,
    store as attendancePointsStore,
    update as attendancePointsUpdate,
    destroy as attendancePointsDestroy,
    rescan as attendancePointsRescan,
    startExportAllExcel as attendancePointsStartExportAllExcel,
    excuse as attendancePointsExcuse,
    unexcuse as attendancePointsUnexcuse,
    recalculateGbro as attendancePointsRecalculateGbro,
} from "@/routes/attendance-points";
import exportAllExcelRoutes from "@/routes/attendance-points/export-all-excel";
import managementRoutes from "@/routes/attendance-points/management";

const defaultTitle = "Attendance Points";

interface User {
    id: number;
    name: string;
    first_name: string;
    last_name: string;
    middle_name?: string;
}

interface Campaign {
    id: number;
    name: string;
}

// Helper function to format user name as "Last Name, First Name M."
const formatUserName = (user: User | { first_name: string; last_name: string; middle_name?: string }): string => {
    const middleInitial = user.middle_name ? ` ${user.middle_name}.` : '';
    return `${user.last_name}, ${user.first_name}${middleInitial}`;
};

interface ExcusedBy {
    id: number;
    name: string;
}

interface CreatedBy {
    id: number;
    name: string;
}

interface AttendancePoint {
    id: number;
    user: User;
    shift_date: string;
    point_type: 'whole_day_absence' | 'half_day_absence' | 'undertime' | 'undertime_more_than_hour' | 'tardy';
    points: number;
    status: string | null;
    is_advised: boolean;
    is_excused: boolean;
    is_manual: boolean;
    created_by: CreatedBy | null;
    excused_by: ExcusedBy | null;
    excused_at: string | null;
    excuse_reason: string | null;
    notes: string | null;
    expires_at: string | null;
    gbro_expires_at: string | null;
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
        undertime_more_than_hour: number;
        tardy: number;
    };
    high_points_employees?: HighPointsEmployee[];
}

interface HighPointsEmployee {
    user_id: number;
    user_name: string;
    total_points: number;
    violations_count: number;
    points: {
        id: number;
        shift_date: string;
        point_type: string;
        points: number;
        violation_details: string | null;
        expires_at: string | null;
    }[];
}

interface Filters {
    user_id?: string;
    campaign_id?: string;
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
    campaigns?: Campaign[];
    stats?: Stats;
    filters?: Filters;
    [key: string]: unknown;
}

// formatDateShort, formatDateTime are now imported from @/lib/utils
const formatDate = formatDateShort; // Alias for backward compatibility

/**
 * Calculate days remaining until expiration.
 * Returns positive number if days remaining, 0 if expired today, negative if already passed.
 */
const getDaysRemaining = (expiresAt: string): number => {
    const expDate = new Date(expiresAt);
    expDate.setHours(0, 0, 0, 0); // Start of expiration day
    const now = new Date();
    now.setHours(0, 0, 0, 0); // Start of today
    return Math.round((expDate.getTime() - now.getTime()) / (1000 * 60 * 60 * 24));
};

/**
 * Check if a point is actually expired based on date (regardless of is_expired flag).
 */
const isActuallyExpired = (expiresAt: string): boolean => {
    return getDaysRemaining(expiresAt) < 0;
};

const getPointTypeBadge = (type: string) => {
    const variants = {
        whole_day_absence: { className: 'bg-red-100 text-red-800 border-red-200 dark:bg-red-900 dark:text-red-100', label: 'Whole Day Absence' },
        half_day_absence: { className: 'bg-orange-100 text-orange-800 border-orange-200 dark:bg-orange-900 dark:text-orange-100', label: 'Half-Day Absence' },
        undertime: { className: 'bg-yellow-100 text-yellow-800 border-yellow-200 dark:bg-yellow-900 dark:text-yellow-100', label: 'Undertime (Hour)' },
        undertime_more_than_hour: { className: 'bg-orange-100 text-orange-800 border-orange-200 dark:bg-orange-900 dark:text-orange-100', label: 'Undertime (>Hour)' },
        tardy: { className: 'bg-yellow-100 text-yellow-800 border-yellow-200 dark:bg-yellow-900 dark:text-yellow-100', label: 'Tardy' },
    };

    const variant = variants[type as keyof typeof variants] || { className: 'bg-gray-100 text-gray-800 border-gray-200', label: type };

    return (
        <Badge className={`${variant.className} border`}>
            {variant.label}
        </Badge>
    );
};

export default function AttendancePointsIndex({ points, users, campaigns, stats, filters, auth }: PageProps) {
    useFlashMessage();
    const { title, breadcrumbs } = usePageMeta({
        title: defaultTitle,
        breadcrumbs: [{ title: defaultTitle, href: attendancePointsIndex().url }],
    });
    const isPageLoading = usePageLoading();

    // Roles that should only see their own attendance points
    const restrictedRoles: UserRole[] = ['Agent', 'IT', 'Utility'];
    const isRestrictedUser = auth.user.role && restrictedRoles.includes(auth.user.role);

    const [selectedUserId, setSelectedUserId] = useState(filters?.user_id || "");
    const [isUserPopoverOpen, setIsUserPopoverOpen] = useState(false);
    const [userSearchQuery, setUserSearchQuery] = useState("");
    const [selectedCampaignId, setSelectedCampaignId] = useState(filters?.campaign_id || "");
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
    const [lastRefresh, setLastRefresh] = useState<Date>(new Date());
    const [autoRefreshEnabled, setAutoRefreshEnabled] = useState(false);

    // Manual entry state
    const [isManualEntryOpen, setIsManualEntryOpen] = useState(false);
    const [isEditMode, setIsEditMode] = useState(false);
    const [editingPoint, setEditingPoint] = useState<AttendancePoint | null>(null);
    const [manualEntryUserId, setManualEntryUserId] = useState("");
    const [isManualUserPopoverOpen, setIsManualUserPopoverOpen] = useState(false);
    const [manualUserSearchQuery, setManualUserSearchQuery] = useState("");
    const [manualShiftDate, setManualShiftDate] = useState("");
    const [manualPointType, setManualPointType] = useState<string>("");
    const [manualIsAdvised, setManualIsAdvised] = useState(false);
    const [manualViolationDetails, setManualViolationDetails] = useState("");
    const [manualNotes, setManualNotes] = useState("");
    const [manualTardyMinutes, setManualTardyMinutes] = useState<string>("");
    const [manualUndertimeMinutes, setManualUndertimeMinutes] = useState<string>("");
    const [isManualSubmitting, setIsManualSubmitting] = useState(false);
    const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
    const [pointToDelete, setPointToDelete] = useState<AttendancePoint | null>(null);

    // High points employees dialog state
    const [isHighPointsDialogOpen, setIsHighPointsDialogOpen] = useState(false);
    const [selectedHighPointsEmployee, setSelectedHighPointsEmployee] = useState<HighPointsEmployee | null>(null);

    // Export progress state
    const [isExportDialogOpen, setIsExportDialogOpen] = useState(false);
    const [exportProgress, setExportProgress] = useState(0);
    const [exportStatus, setExportStatus] = useState('');
    const [, setExportJobId] = useState<string | null>(null);
    const [exportError, setExportError] = useState(false);
    const [exportDownloadUrl, setExportDownloadUrl] = useState<string | null>(null);
    const exportPollingRef = useRef<NodeJS.Timeout | null>(null);

    // Management state
    const [isManagementDialogOpen, setIsManagementDialogOpen] = useState(false);
    const [managementStats, setManagementStats] = useState<{
        duplicates_count: number;
        pending_expirations_count: number;
        expired_count: number;
        missing_points_count: number;
    } | null>(null);
    const [isLoadingStats, setIsLoadingStats] = useState(false);
    const [isManagementAction, setIsManagementAction] = useState(false);
    const [confirmAction, setConfirmAction] = useState<'remove-duplicates' | 'expire-all' | 'reset-expired' | 'regenerate' | 'cleanup' | 'initialize-gbro-dates' | 'fix-gbro-dates' | 'recalculate-gbro' | null>(null);

    // Management filters for regenerate and reset-expired
    const [mgmtDateFrom, setMgmtDateFrom] = useState('');
    const [mgmtDateTo, setMgmtDateTo] = useState('');
    const [mgmtUserId, setMgmtUserId] = useState(''); // For regenerate (single select)
    const [mgmtUserIds, setMgmtUserIds] = useState<string[]>([]); // For reset-expired (multi-select)
    const [isMgmtUserPopoverOpen, setIsMgmtUserPopoverOpen] = useState(false);
    const [mgmtUserSearchQuery, setMgmtUserSearchQuery] = useState('');

    // Expiration type selection for expire-all
    const [expirationType, setExpirationType] = useState<'both' | 'sro' | 'gbro'>('both');

    // Recalculate GBRO - all employees toggle
    const [recalculateAllEmployees, setRecalculateAllEmployees] = useState(false);

    // Cleanup polling on unmount
    useEffect(() => {
        return () => {
            if (exportPollingRef.current) {
                clearInterval(exportPollingRef.current);
            }
        };
    }, []);

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

    const buildFilterQuery = useCallback(() => {
        const query: Record<string, string> = {};
        if (selectedUserId) query.user_id = selectedUserId;
        if (selectedCampaignId) query.campaign_id = selectedCampaignId;
        if (selectedPointType) query.point_type = selectedPointType;
        if (selectedStatus) query.status = selectedStatus;
        if (dateFrom) query.date_from = dateFrom;
        if (dateTo) query.date_to = dateTo;
        if (filterExpiringSoon) query.expiring_soon = 'true';
        if (filterGbroEligible) query.gbro_eligible = 'true';
        return query;
    }, [selectedUserId, selectedCampaignId, selectedPointType, selectedStatus, dateFrom, dateTo, filterExpiringSoon, filterGbroEligible]);

    const handleFilter = () => {
        router.get(
            attendancePointsIndex().url,
            buildFilterQuery(),
            {
                preserveState: true,
                onSuccess: () => setLastRefresh(new Date())
            }
        );
    };

    const handleManualRefresh = () => {
        handleFilter();
    };

    // Auto-refresh every 30 seconds
    useEffect(() => {
        if (!autoRefreshEnabled) return;
        const interval = setInterval(() => {
            router.get(
                attendancePointsIndex().url,
                buildFilterQuery(),
                {
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                    only: ['points', 'stats'],
                    onSuccess: () => setLastRefresh(new Date())
                }
            );
        }, 30000);

        return () => clearInterval(interval);
    }, [autoRefreshEnabled, buildFilterQuery]);

    const handleReset = () => {
        setSelectedUserId("");
        setUserSearchQuery("");
        setSelectedCampaignId("");
        setSelectedPointType("");
        setSelectedStatus("");
        setDateFrom("");
        setDateTo("");
        setFilterExpiringSoon(false);
        setFilterGbroEligible(false);

        // Trigger reload with cleared filters
        router.get(attendancePointsIndex().url, {}, {
            preserveState: true,
            onSuccess: () => setLastRefresh(new Date())
        });
    };

    const handleRescan = () => {
        if (!rescanDateFrom || !rescanDateTo) {
            toast.error("Please select both start and end dates");
            return;
        }

        setIsRescanning(true);
        router.post(
            attendancePointsRescan().url,
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

    const handleExportAllExcel = async () => {
        // Reset state
        setExportProgress(0);
        setExportStatus('Starting export...');
        setExportError(false);
        setExportDownloadUrl(null);
        setIsExportDialogOpen(true);

        try {
            // Start the export job
            const response = await fetch(attendancePointsStartExportAllExcel().url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify(buildFilterQuery()),
            });

            const data = await response.json();

            if (!data.jobId) {
                throw new Error('Failed to start export');
            }

            setExportJobId(data.jobId);

            // Immediately check status (job may have completed synchronously)
            const immediateStatus = await fetch(exportAllExcelRoutes.status({ jobId: data.jobId }).url);
            const immediateData = await immediateStatus.json();

            if (immediateData.finished && immediateData.downloadUrl) {
                setExportProgress(100);
                setExportStatus('Complete');
                setExportDownloadUrl(immediateData.downloadUrl);
                return; // Job already done, no need to poll
            }

            if (immediateData.error) {
                setExportError(true);
                setExportStatus(immediateData.status || 'Export failed');
                return;
            }

            // Update with initial progress
            setExportProgress(immediateData.percent || 0);
            setExportStatus(immediateData.status || 'Processing...');

            // Start polling for status (for async queue)
            exportPollingRef.current = setInterval(async () => {
                try {
                    const statusResponse = await fetch(exportAllExcelRoutes.status({ jobId: data.jobId }).url);
                    const statusData = await statusResponse.json();

                    setExportProgress(statusData.percent || 0);
                    setExportStatus(statusData.status || 'Processing...');

                    if (statusData.error) {
                        setExportError(true);
                        if (exportPollingRef.current) {
                            clearInterval(exportPollingRef.current);
                            exportPollingRef.current = null;
                        }
                    }

                    if (statusData.finished && statusData.downloadUrl) {
                        setExportDownloadUrl(statusData.downloadUrl);
                        if (exportPollingRef.current) {
                            clearInterval(exportPollingRef.current);
                            exportPollingRef.current = null;
                        }
                    }
                } catch {
                    // Continue polling on status check failure
                }
            }, 500);
        } catch {
            setExportError(true);
            setExportStatus('Failed to start export');
        }
    };

    const handleDownloadExport = async () => {
        if (exportDownloadUrl) {
            try {
                // Use fetch to get the file with credentials
                const response = await fetch(exportDownloadUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    throw new Error('Download failed');
                }

                // Get the blob and create a download link
                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `attendance-points-export-${new Date().toISOString().split('T')[0]}.xlsx`;
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);

                setIsExportDialogOpen(false);
                // Reset state
                setExportJobId(null);
                setExportProgress(0);
                setExportStatus('');
                setExportDownloadUrl(null);
            } catch {
                setExportError(true);
                setExportStatus('Download failed. Please try generating a new export.');
            }
        }
    };

    const handleCloseExportDialog = () => {
        if (exportPollingRef.current) {
            clearInterval(exportPollingRef.current);
            exportPollingRef.current = null;
        }
        setIsExportDialogOpen(false);
        setExportJobId(null);
        setExportProgress(0);
        setExportStatus('');
        setExportError(false);
        setExportDownloadUrl(null);
    };

    // Management Handlers
    const fetchManagementStats = async () => {
        setIsLoadingStats(true);
        try {
            const response = await fetch(managementRoutes.stats().url, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
            const data = await response.json();
            setManagementStats(data);
        } catch {
            toast.error('Failed to fetch management stats');
        } finally {
            setIsLoadingStats(false);
        }
    };

    const handleOpenManagementDialog = () => {
        setIsManagementDialogOpen(true);
        // Reset filters when opening
        setMgmtDateFrom('');
        setMgmtDateTo('');
        setMgmtUserId('');
        setMgmtUserIds([]);
        setExpirationType('both');
        fetchManagementStats();
    };

    const handleManagementAction = async (action: 'remove-duplicates' | 'expire-all' | 'reset-expired' | 'regenerate' | 'cleanup' | 'initialize-gbro-dates' | 'fix-gbro-dates' | 'recalculate-gbro') => {
        setIsManagementAction(true);
        try {
            // Special handling for recalculate-gbro (per-user action)
            if (action === 'recalculate-gbro') {
                // Determine which users to process
                let userIdsToProcess: string[] = [];

                if (recalculateAllEmployees) {
                    // Get all users who have GBRO-eligible points
                    userIdsToProcess = users?.map(u => String(u.id)) || [];
                } else {
                    if (mgmtUserIds.length === 0) {
                        toast.error('Please select at least one employee or choose "All Employees"');
                        setIsManagementAction(false);
                        return;
                    }
                    userIdsToProcess = mgmtUserIds;
                }

                let successCount = 0;
                let errorCount = 0;

                for (const userId of userIdsToProcess) {
                    try {
                        await new Promise<void>((resolve) => {
                            router.post(
                                attendancePointsRecalculateGbro({ user: parseInt(userId) }).url,
                                {},
                                {
                                    preserveScroll: true,
                                    preserveState: true,
                                    onSuccess: () => {
                                        successCount++;
                                        resolve();
                                    },
                                    onError: () => {
                                        errorCount++;
                                        resolve();
                                    },
                                }
                            );
                        });
                    } catch {
                        errorCount++;
                    }
                }

                if (successCount > 0) {
                    toast.success(`Recalculated GBRO for ${successCount} employee(s)`);
                }
                if (errorCount > 0) {
                    toast.error(`Failed for ${errorCount} employee(s)`);
                }

                setIsManagementAction(false);
                setConfirmAction(null);
                setMgmtUserIds([]);
                setRecalculateAllEmployees(false);
                handleFilter();
                return;
            }

            const routeMap: Record<string, { url: string }> = {
                'remove-duplicates': managementRoutes.removeDuplicates(),
                'expire-all': managementRoutes.expireAll(),
                'reset-expired': managementRoutes.resetExpired(),
                'regenerate': managementRoutes.regenerate(),
                'cleanup': managementRoutes.cleanup(),
                'initialize-gbro-dates': managementRoutes.initializeGbroDates(),
                'fix-gbro-dates': managementRoutes.fixGbroDates(),
            };

            // Build request body with filters for regenerate and reset-expired
            const body: Record<string, string | string[]> = {};
            if (action === 'regenerate') {
                if (mgmtDateFrom) body.date_from = mgmtDateFrom;
                if (mgmtDateTo) body.date_to = mgmtDateTo;
                if (mgmtUserId) body.user_id = mgmtUserId;
            }
            if (action === 'reset-expired') {
                if (mgmtUserIds.length > 0) body.user_ids = mgmtUserIds;
            }
            if (action === 'initialize-gbro-dates' || action === 'fix-gbro-dates') {
                if (mgmtUserIds.length > 0) body.user_ids = mgmtUserIds;
            }
            if (action === 'expire-all') {
                body.expiration_type = expirationType;
                if (mgmtUserIds.length > 0) body.user_ids = mgmtUserIds;
            }

            const response = await fetch(routeMap[action].url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify(body),
            });

            const data = await response.json();

            if (data.success) {
                toast.success(data.message);
                // Refresh the stats and the page
                fetchManagementStats();
                handleFilter();
            } else {
                toast.error(data.message || 'Action failed');
            }
        } catch {
            toast.error('Failed to perform action');
        } finally {
            setIsManagementAction(false);
            setConfirmAction(null);
            setExpirationType('both');
        }
    };

    const viewUserDetails = (userId: number, shiftDate: string) => {
        // Calculate a date range around the shift date (1 month before and after)
        const date = new Date(shiftDate);
        const dateFrom = new Date(date.getFullYear(), date.getMonth(), 1).toISOString().split('T')[0];
        const dateTo = new Date(date.getFullYear(), date.getMonth() + 1, 0).toISOString().split('T')[0];

        router.get(attendancePointsShow({ user: userId }).url, {
            date_from: dateFrom,
            date_to: dateTo,
        });
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
            attendancePointsExcuse({ point: selectedPoint.id }).url,
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
            attendancePointsUnexcuse({ point: pointToUnexcuse }).url,
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

    // Manual Entry Handlers
    const resetManualEntryForm = () => {
        setManualEntryUserId("");
        setManualUserSearchQuery("");
        setManualShiftDate("");
        setManualPointType("");
        setManualIsAdvised(false);
        setManualViolationDetails("");
        setManualNotes("");
        setManualTardyMinutes("");
        setManualUndertimeMinutes("");
        setIsEditMode(false);
        setEditingPoint(null);
    };

    const openManualEntryDialog = () => {
        resetManualEntryForm();
        setIsManualEntryOpen(true);
    };

    const openEditDialog = (point: AttendancePoint) => {
        setEditingPoint(point);
        setIsEditMode(true);
        setManualEntryUserId(String(point.user.id));
        // Format date as YYYY-MM-DD for date input
        const formattedDate = point.shift_date.includes('T')
            ? point.shift_date.split('T')[0]
            : point.shift_date;
        setManualShiftDate(formattedDate);
        setManualPointType(point.point_type);
        setManualIsAdvised(point.is_advised);
        setManualViolationDetails(point.violation_details || "");
        setManualNotes(point.notes || "");
        setManualTardyMinutes(point.tardy_minutes ? String(point.tardy_minutes) : "");
        setManualUndertimeMinutes(point.undertime_minutes ? String(point.undertime_minutes) : "");
        setIsManualEntryOpen(true);
    };

    const handleManualEntrySubmit = () => {
        if (!manualEntryUserId || !manualShiftDate || !manualPointType) {
            toast.error("Please fill in all required fields");
            return;
        }

        // Validate undertime minutes based on selected type
        const undertimeMinutesValue = manualUndertimeMinutes ? parseInt(manualUndertimeMinutes) : 0;

        if (manualPointType === 'undertime' && undertimeMinutesValue > 60) {
            toast.error("Undertime (Hour) should be 60 minutes or less. Use 'Undertime - More Than Hour' for 61+ minutes.");
            return;
        }

        if (manualPointType === 'undertime_more_than_hour' && undertimeMinutesValue < 61) {
            toast.error("Undertime (More Than Hour) should be at least 61 minutes. Use 'Undertime - Hour' for 60 minutes or less.");
            return;
        }

        setIsManualSubmitting(true);

        const payload = {
            user_id: parseInt(manualEntryUserId),
            shift_date: manualShiftDate,
            point_type: manualPointType,
            is_advised: manualIsAdvised,
            violation_details: manualViolationDetails || null,
            notes: manualNotes || null,
            tardy_minutes: manualTardyMinutes ? parseInt(manualTardyMinutes) : null,
            undertime_minutes: manualUndertimeMinutes ? parseInt(manualUndertimeMinutes) : null,
        };

        if (isEditMode && editingPoint) {
            router.put(
                attendancePointsUpdate({ point: editingPoint.id }).url,
                payload,
                {
                    onSuccess: () => {
                        toast.success("Manual attendance point updated successfully");
                    },
                    onError: (errors) => {
                        const errorMessage = Object.values(errors).flat().join(', ') || "Failed to update attendance point";
                        toast.error(errorMessage);
                    },
                    onFinish: () => {
                        setIsManualSubmitting(false);
                        setIsManualEntryOpen(false);
                        resetManualEntryForm();
                    }
                }
            );
        } else {
            router.post(
                attendancePointsStore().url,
                payload,
                {
                    onSuccess: () => {
                        toast.success("Manual attendance point created successfully");
                    },
                    onError: (errors) => {
                        const errorMessage = Object.values(errors).flat().join(', ') || "Failed to create attendance point";
                        toast.error(errorMessage);
                    },
                    onFinish: () => {
                        setIsManualSubmitting(false);
                        setIsManualEntryOpen(false);
                        resetManualEntryForm();
                    }
                }
            );
        }
    };

    const openDeleteDialog = (point: AttendancePoint) => {
        setPointToDelete(point);
        setIsDeleteDialogOpen(true);
    };

    const confirmDelete = () => {
        if (!pointToDelete) return;

        router.delete(
            attendancePointsDestroy({ point: pointToDelete.id }).url,
            {
                onFinish: () => {
                    setIsDeleteDialogOpen(false);
                    setPointToDelete(null);
                }
            }
        );
    };

    // Handle viewing high points employee's attendance points
    const handleViewHighPointsEmployee = (employee: HighPointsEmployee) => {
        setSelectedHighPointsEmployee(employee);
        // Points are already loaded from the backend, no need to fetch
    };

    const showClearFilters = selectedUserId || selectedCampaignId || selectedPointType || selectedStatus || dateFrom || dateTo || userSearchQuery || filterExpiringSoon || filterGbroEligible;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="relative flex h-full flex-1 flex-col gap-4 rounded-xl p-3">
                <LoadingOverlay isLoading={isPageLoading} />
                <PageHeader
                    title={title}
                    description="Track and manage employee attendance violations and points"
                />

                {/* Statistics Cards */}
                <div className="grid gap-4 md:grid-cols-3 lg:grid-cols-4">
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
                            <CardTitle className="text-sm font-medium">Tardy</CardTitle>
                            <TrendingUp className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-yellow-600 dark:text-yellow-400">
                                {Number(stats?.by_type.tardy || 0).toFixed(2)}
                            </div>
                            <p className="text-xs text-muted-foreground">0.25 pt each</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Undertime (Hour)</CardTitle>
                            <TrendingUp className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-yellow-600 dark:text-yellow-400">
                                {Number(stats?.by_type.undertime || 0).toFixed(2)}
                            </div>
                            <p className="text-xs text-muted-foreground">0.25 pt each</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Undertime (&gt;Hour)</CardTitle>
                            <TrendingUp className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-orange-600 dark:text-orange-400">
                                {Number(stats?.by_type.undertime_more_than_hour || 0).toFixed(2)}
                            </div>
                            <p className="text-xs text-muted-foreground">0.5 pt each</p>
                        </CardContent>
                    </Card>

                    {/* High Points Employees Card - Only show for non-restricted users */}
                    {!isRestrictedUser && (
                        <Card
                            className="cursor-pointer hover:bg-accent/50 transition-colors border-red-200 dark:border-red-800"
                            onClick={() => setIsHighPointsDialogOpen(true)}
                        >
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">High Risk Employees</CardTitle>
                                <Users className="h-4 w-4 text-red-500" />
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold text-red-600 dark:text-red-400">
                                    {stats?.high_points_employees?.length || 0}
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Employees with 6+ points
                                </p>
                            </CardContent>
                        </Card>
                    )}
                </div>

                {/* Filters */}
                <div className="flex flex-col gap-3">
                    <div className="flex items-center justify-between">
                        <h3 className="text-lg font-medium">Filters</h3>
                        {!isRestrictedUser && (
                            <div className="flex items-center gap-2">
                                <Can permission="attendance_points.export">
                                    <Button variant="outline" size="sm" onClick={handleExportAllExcel}>
                                        <Download className="h-4 w-4 mr-2" />
                                        Export
                                    </Button>
                                </Can>
                                <Can permission="attendance_points.rescan">
                                    <Button
                                        variant="outline"
                                        onClick={() => setIsRescanOpen(true)}
                                        className="gap-2"
                                    >
                                        <RefreshCw className="h-4 w-4" />
                                        Rescan Points
                                    </Button>
                                </Can>
                                <Can permission="attendance_points.create">
                                    <Button
                                        onClick={openManualEntryDialog}
                                        className="gap-2"
                                    >
                                        <Plus className="h-4 w-4" />
                                        Add Manual Entry
                                    </Button>
                                </Can>
                                <HasRole role={['IT', 'Super Admin']}>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button variant="outline" className="gap-2">
                                                <Settings className="h-4 w-4" />
                                                Manage
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuItem onClick={handleOpenManagementDialog}>
                                                <ClipboardList className="h-4 w-4 mr-2" />
                                                View Statistics
                                            </DropdownMenuItem>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem onClick={() => setConfirmAction('regenerate')}>
                                                <RefreshCw className="h-4 w-4 mr-2" />
                                                Regenerate Points
                                            </DropdownMenuItem>
                                            <DropdownMenuItem onClick={() => setConfirmAction('remove-duplicates')}>
                                                <Trash2 className="h-4 w-4 mr-2" />
                                                Remove Duplicates
                                            </DropdownMenuItem>
                                            <DropdownMenuItem onClick={() => setConfirmAction('expire-all')}>
                                                <AlertTriangle className="h-4 w-4 mr-2" />
                                                Expire All Pending
                                            </DropdownMenuItem>
                                            <DropdownMenuItem onClick={() => setConfirmAction('reset-expired')}>
                                                <RotateCcw className="h-4 w-4 mr-2" />
                                                Reset Expired Points
                                            </DropdownMenuItem>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem onClick={() => setConfirmAction('initialize-gbro-dates')}>
                                                <Play className="h-4 w-4 mr-2" />
                                                Initialize GBRO Dates
                                            </DropdownMenuItem>
                                            <DropdownMenuItem onClick={() => setConfirmAction('fix-gbro-dates')}>
                                                <RefreshCw className="h-4 w-4 mr-2" />
                                                Fix GBRO Dates
                                            </DropdownMenuItem>
                                            <DropdownMenuItem onClick={() => setConfirmAction('recalculate-gbro')}>
                                                <RotateCcw className="h-4 w-4 mr-2" />
                                                Recalculate GBRO Dates
                                            </DropdownMenuItem>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem onClick={() => setConfirmAction('cleanup')}>
                                                <Settings className="h-4 w-4 mr-2" />
                                                Full Cleanup
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </HasRole>
                            </div>
                        )}
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
                        {!isRestrictedUser && (
                            <Popover open={isUserPopoverOpen} onOpenChange={setIsUserPopoverOpen}>
                                <PopoverTrigger asChild>
                                    <Button
                                        variant="outline"
                                        role="combobox"
                                        aria-expanded={isUserPopoverOpen}
                                        className="w-full justify-between font-normal"
                                    >
                                        <span className="truncate">
                                            {selectedUserId
                                                ? (() => {
                                                    const user = users?.find(u => String(u.id) === selectedUserId);
                                                    return user ? formatUserName(user) : "Select employee...";
                                                })()
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
                                                        className={`mr-2 h-4 w-4 ${!selectedUserId
                                                            ? "opacity-100"
                                                            : "opacity-0"
                                                            }`}
                                                    />
                                                    All Employees
                                                </CommandItem>
                                                {users
                                                    ?.filter(user => {
                                                        if (!userSearchQuery) return true;
                                                        const query = userSearchQuery.toLowerCase();
                                                        const formattedName = formatUserName(user).toLowerCase();
                                                        const regularName = user.name.toLowerCase();
                                                        return formattedName.includes(query) || regularName.includes(query);
                                                    })
                                                    .map((user) => (
                                                        <CommandItem
                                                            key={user.id}
                                                            value={formatUserName(user)}
                                                            onSelect={() => {
                                                                setSelectedUserId(String(user.id));
                                                                setIsUserPopoverOpen(false);
                                                                setUserSearchQuery("");
                                                            }}
                                                            className="cursor-pointer"
                                                        >
                                                            <Check
                                                                className={`mr-2 h-4 w-4 ${selectedUserId === String(user.id)
                                                                    ? "opacity-100"
                                                                    : "opacity-0"
                                                                    }`}
                                                            />
                                                            {formatUserName(user)}
                                                        </CommandItem>
                                                    ))}
                                            </CommandGroup>
                                        </CommandList>
                                    </Command>
                                </PopoverContent>
                            </Popover>
                        )}

                        {!isRestrictedUser && (
                            <Select value={selectedCampaignId || "all"} onValueChange={(value) => setSelectedCampaignId(value === "all" ? "" : value)}>
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="All Campaigns" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Campaigns</SelectItem>
                                    {campaigns?.map(campaign => (
                                        <SelectItem key={campaign.id} value={String(campaign.id)}>
                                            {campaign.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}

                        <Select value={selectedPointType || undefined} onValueChange={(value) => setSelectedPointType(value || "")}>
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="All Types" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="whole_day_absence">Whole Day Absence</SelectItem>
                                <SelectItem value="half_day_absence">Half-Day Absence</SelectItem>
                                <SelectItem value="undertime">Undertime (Hour)</SelectItem>
                                <SelectItem value="undertime_more_than_hour">Undertime (More than Hour)</SelectItem>
                                <SelectItem value="tardy">Tardy</SelectItem>
                            </SelectContent>
                        </Select>

                        <Select value={selectedStatus || "all"} onValueChange={(value) => setSelectedStatus(value === "all" ? "" : value)}>
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="All Status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Status</SelectItem>
                                <SelectItem value="active">Active</SelectItem>
                                <SelectItem value="excused">Excused</SelectItem>
                                <SelectItem value="expired">Expired</SelectItem>
                            </SelectContent>
                        </Select>

                        <div className="flex items-center gap-2 text-sm">
                            <span className="text-muted-foreground text-xs">From:</span>
                            <DatePicker
                                value={dateFrom}
                                onChange={(value) => setDateFrom(value)}
                                placeholder="Start date"
                                className="w-full"
                            />
                        </div>

                        <div className="flex items-center gap-2 text-sm">
                            <span className="text-muted-foreground text-xs">To:</span>
                            <DatePicker
                                value={dateTo}
                                onChange={(value) => setDateTo(value)}
                                placeholder="End date"
                                className="w-full"
                            />
                        </div>
                    </div>

                    {/* Additional Filters Row */}
                    <div className="flex flex-wrap gap-4 items-center justify-between">
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

                        <div className="flex flex-wrap gap-3 sm:flex-row sm:items-center sm:justify-end">
                            <Button variant="default" onClick={handleFilter} className="flex-1 sm:flex-none">
                                <Search className="mr-2 h-4 w-4" />
                                Apply Filters
                            </Button>

                            {showClearFilters && (
                                <Button variant="outline" onClick={handleReset} className="flex-1 sm:flex-none">
                                    Clear Filters
                                </Button>
                            )}

                            <div className="flex gap-2">
                                <Button variant="ghost" size="icon" onClick={handleManualRefresh} title="Refresh">
                                    <RefreshCw className="h-4 w-4" />
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

                <div className="flex justify-between items-center text-sm">
                    <div className="text-muted-foreground">
                        Showing {pointsData.meta.from} to {pointsData.meta.to} of {pointsData.meta.total} point
                        {pointsData.meta.total === 1 ? "" : "s"}
                        {showClearFilters ? " (filtered)" : ""}
                    </div>
                    <div className="text-xs">
                        Last updated: {lastRefresh.toLocaleTimeString()}
                    </div>
                </div>

                {/* Desktop Table */}
                <div className="hidden md:block shadow rounded-md overflow-hidden">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow className="bg-muted/50">
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
                                                    <span className="font-medium">{formatUserName(point.user)}</span>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    <span>{formatDate(point.shift_date)}</span>
                                                    {point.point_type === 'whole_day_absence' && !point.is_advised && (
                                                        <Badge className="bg-purple-600 text-white text-xs border-0">
                                                            NCNS
                                                        </Badge>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell>{getPointTypeBadge(point.point_type)}</TableCell>
                                            <TableCell className="text-right font-bold text-red-600 dark:text-red-400">
                                                {Number(point.points).toFixed(2)}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex flex-col gap-1">
                                                    {point.is_expired ? (
                                                        <Badge className="bg-gray-100 text-gray-800 border-gray-200 dark:bg-gray-900 dark:text-gray-100 border w-fit">
                                                            {point.expiration_type === 'gbro' ? 'Expired (GBRO)' : 'Expired (SRO)'}
                                                        </Badge>
                                                    ) : point.is_excused ? (
                                                        <Badge className="bg-green-100 text-green-800 border-green-200 dark:bg-green-900 dark:text-green-100 border w-fit">
                                                            Excused
                                                        </Badge>
                                                    ) : (
                                                        <Badge className="bg-red-100 text-red-800 border-red-200 dark:bg-red-900 dark:text-red-100 border w-fit">
                                                            Active
                                                        </Badge>
                                                    )}
                                                    {point.is_manual && (
                                                        <Badge className="bg-purple-100 text-purple-800 border-purple-200 dark:bg-purple-900 dark:text-purple-100 border w-fit text-xs">
                                                            Manual
                                                        </Badge>
                                                    )}
                                                </div>
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
                                                {point.is_excused ? (
                                                    <div className="text-sm">
                                                        <div className="text-muted-foreground italic">Excused (Won't Expire)</div>
                                                    </div>
                                                ) : point.is_expired ? (
                                                    <div className="text-xs space-y-0.5">
                                                        {point.expires_at && (
                                                            <div className="flex items-center gap-1">
                                                                <span className={`font-semibold ${point.expiration_type === 'sro' ? 'text-blue-600 dark:text-blue-400' : 'text-muted-foreground'}`}>SRO:</span>
                                                                <span className={`${point.expiration_type === 'sro' ? 'font-medium' : 'text-muted-foreground line-through'}`}>{formatDate(point.expires_at)}</span>
                                                            </div>
                                                        )}
                                                        {point.gbro_expires_at && (
                                                            <div className="flex items-center gap-1">
                                                                <span className={`font-semibold ${point.expiration_type === 'gbro' ? 'text-green-600 dark:text-green-400' : 'text-muted-foreground'}`}>GBRO:</span>
                                                                <span className={`${point.expiration_type === 'gbro' ? 'font-medium' : 'text-muted-foreground line-through'}`}>{formatDate(point.gbro_expires_at)}</span>
                                                            </div>
                                                        )}
                                                    </div>
                                                ) : point.expires_at ? (
                                                    <div className="text-xs space-y-0.5">
                                                        {/* SRO Expiration */}
                                                        <div className="flex items-center gap-1">
                                                            <span className={!point.eligible_for_gbro ? 'font-semibold text-blue-600 dark:text-blue-400' : 'text-muted-foreground'}>SRO:</span>
                                                            <span className={!point.eligible_for_gbro ? 'font-medium' : 'text-muted-foreground'}>{formatDate(point.expires_at)}</span>
                                                        </div>
                                                        {/* GBRO Expiration (if eligible) */}
                                                        {point.eligible_for_gbro && point.gbro_expires_at && (
                                                            <div className="flex items-center gap-1">
                                                                <span className="font-semibold text-green-600 dark:text-green-400">GBRO:</span>
                                                                <span className="font-medium text-green-600 dark:text-green-400">{formatDate(point.gbro_expires_at)}</span>
                                                            </div>
                                                        )}
                                                        {/* Days remaining or pending status */}
                                                        {!isActuallyExpired(point.expires_at) && (
                                                            <div className="text-xs text-muted-foreground">
                                                                {getDaysRemaining(point.expires_at)} days left (SRO)
                                                            </div>
                                                        )}
                                                        {isActuallyExpired(point.expires_at) && (
                                                            <div className="text-xs text-orange-600 dark:text-orange-400">
                                                                Pending expiration
                                                            </div>
                                                        )}
                                                    </div>
                                                ) : (
                                                    <span className="text-muted-foreground text-sm">-</span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <div className="flex items-center justify-end gap-1">
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="h-8 w-8 text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                                                        onClick={() => viewUserDetails(point.user.id, point.shift_date)}
                                                        title="View User Details"
                                                    >
                                                        <Eye className="h-4 w-4" />
                                                    </Button>
                                                    {point.is_manual && (
                                                        <>
                                                            <Can permission="attendance_points.edit">
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="h-8 w-8"
                                                                    onClick={() => openEditDialog(point)}
                                                                    title="Edit"
                                                                >
                                                                    <Pencil className="h-4 w-4" />
                                                                </Button>
                                                            </Can>
                                                            <Can permission="attendance_points.delete">
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="h-8 w-8 text-red-600 hover:text-red-800 dark:text-red-400"
                                                                    onClick={() => openDeleteDialog(point)}
                                                                    title="Delete"
                                                                >
                                                                    <Trash2 className="h-4 w-4" />
                                                                </Button>
                                                            </Can>
                                                        </>
                                                    )}
                                                    {!point.is_expired && !point.is_excused && (
                                                        <Can permission="attendance_points.excuse">
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                className="h-8 w-8 text-green-600 hover:text-green-800 dark:text-green-400"
                                                                onClick={() => openExcuseDialog(point)}
                                                                title="Excuse"
                                                            >
                                                                <CheckCircle className="h-4 w-4" />
                                                            </Button>
                                                        </Can>
                                                    )}
                                                    {!point.is_expired && point.is_excused && (
                                                        <Can permission="attendance_points.excuse">
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                className="h-8 w-8 text-red-600 hover:text-red-800 dark:text-red-400"
                                                                onClick={() => handleUnexcuse(point.id)}
                                                                title="Remove Excuse"
                                                            >
                                                                <XCircle className="h-4 w-4" />
                                                            </Button>
                                                        </Can>
                                                    )}
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
                                        <span className="font-medium text-sm">{formatUserName(point.user)}</span>
                                    </div>
                                    <div className="flex flex-col items-end gap-1">
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
                                        {point.is_manual && (
                                            <Badge className="bg-purple-100 text-purple-800 border-purple-200 dark:bg-purple-900 dark:text-purple-100 border text-xs">
                                                Manual
                                            </Badge>
                                        )}
                                    </div>
                                </div>

                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <span className="text-muted-foreground text-sm">Date:</span>
                                        <div className="flex items-center gap-2">
                                            <p className="font-medium">{formatDate(point.shift_date)}</p>
                                            {point.point_type === 'whole_day_absence' && !point.is_advised && (
                                                <Badge className="bg-purple-600 text-white text-xs border-0">
                                                    NCNS
                                                </Badge>
                                            )}
                                        </div>
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

                                {point.is_excused ? (
                                    <div>
                                        <span className="text-muted-foreground text-sm">Expiration:</span>
                                        <div className="text-sm mt-1">
                                            <p className="text-muted-foreground italic">Excused (Won't Expire)</p>
                                        </div>
                                    </div>
                                ) : point.is_expired ? (
                                    <div>
                                        <span className="text-muted-foreground text-sm">Expiration:</span>
                                        <div className="text-xs mt-1 space-y-0.5">
                                            {point.expires_at && (
                                                <div className="flex items-center gap-1">
                                                    <span className={`font-semibold ${point.expiration_type === 'sro' ? 'text-blue-600 dark:text-blue-400' : 'text-muted-foreground'}`}>SRO:</span>
                                                    <span className={`${point.expiration_type === 'sro' ? 'font-medium' : 'text-muted-foreground line-through'}`}>{formatDate(point.expires_at)}</span>
                                                </div>
                                            )}
                                            {point.gbro_expires_at && (
                                                <div className="flex items-center gap-1">
                                                    <span className={`font-semibold ${point.expiration_type === 'gbro' ? 'text-green-600 dark:text-green-400' : 'text-muted-foreground'}`}>GBRO:</span>
                                                    <span className={`${point.expiration_type === 'gbro' ? 'font-medium' : 'text-muted-foreground line-through'}`}>{formatDate(point.gbro_expires_at)}</span>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                ) : point.expires_at && (
                                    <div>
                                        <span className="text-muted-foreground text-sm">Expiration:</span>
                                        <div className="text-xs mt-1 space-y-0.5">
                                            {/* SRO Expiration */}
                                            <div className="flex items-center gap-1">
                                                <span className={!point.eligible_for_gbro ? 'font-semibold text-blue-600 dark:text-blue-400' : 'text-muted-foreground'}>SRO:</span>
                                                <span className={!point.eligible_for_gbro ? 'font-medium' : 'text-muted-foreground'}>{formatDate(point.expires_at)}</span>
                                            </div>
                                            {/* GBRO Expiration (if eligible) */}
                                            {point.eligible_for_gbro && point.gbro_expires_at && (
                                                <div className="flex items-center gap-1">
                                                    <span className="font-semibold text-green-600 dark:text-green-400">GBRO:</span>
                                                    <span className="font-medium text-green-600 dark:text-green-400">{formatDate(point.gbro_expires_at)}</span>
                                                </div>
                                            )}
                                            {/* Days remaining or pending status */}
                                            {!isActuallyExpired(point.expires_at) && (
                                                <p className="text-xs text-muted-foreground">
                                                    {getDaysRemaining(point.expires_at)} days left (SRO)
                                                </p>
                                            )}
                                            {isActuallyExpired(point.expires_at) && (
                                                <p className="text-xs text-orange-600 dark:text-orange-400">
                                                    Pending expiration
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

                                <div className="flex flex-wrap gap-2 pt-2 border-t">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="flex-1"
                                        onClick={() => viewUserDetails(point.user.id, point.shift_date)}
                                    >
                                        <Eye className="h-4 w-4 mr-1" />
                                        View
                                    </Button>
                                    {point.is_manual && (
                                        <>
                                            <Can permission="attendance_points.edit">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    className="flex-1"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        openEditDialog(point);
                                                    }}
                                                >
                                                    <Pencil className="h-4 w-4 mr-1" />
                                                    Edit
                                                </Button>
                                            </Can>
                                            <Can permission="attendance_points.delete">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    className="flex-1 text-red-600 hover:text-red-800 dark:text-red-400 border-red-200"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        openDeleteDialog(point);
                                                    }}
                                                >
                                                    <Trash2 className="h-4 w-4 mr-1" />
                                                    Delete
                                                </Button>
                                            </Can>
                                        </>
                                    )}
                                    {!point.is_expired && !point.is_excused && (
                                        <Can permission="attendance_points.excuse">
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
                                        </Can>
                                    )}
                                    {!point.is_expired && point.is_excused && (
                                        <Can permission="attendance_points.excuse">
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
                                        </Can>
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
                            <DatePicker
                                value={rescanDateFrom}
                                onChange={(value) => setRescanDateFrom(value)}
                                placeholder="Select start date"
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="rescan_date_to">To Date</Label>
                            <DatePicker
                                value={rescanDateTo}
                                onChange={(value) => setRescanDateTo(value)}
                                placeholder="Select end date"
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
                                    <span className="font-medium">{formatUserName(selectedPoint.user)}</span>
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
                                    <p>On: <span className="font-medium">{formatDateTime(selectedPoint.excused_at)}</span></p>
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
                                    <p className="text-sm font-medium mt-1">{formatUserName(selectedViolationPoint.user)}</p>
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

                            {selectedViolationPoint.is_excused ? (
                                <div className="grid grid-cols-2 gap-4 pt-4 border-t">
                                    <div>
                                        <Label className="text-sm font-medium text-muted-foreground">Expiration Date</Label>
                                        <p className="text-sm text-muted-foreground italic mt-1">N/A (Excused)</p>
                                    </div>
                                    <div>
                                        <Label className="text-sm font-medium text-muted-foreground">Status</Label>
                                        <div className="mt-1">
                                            <Badge className="bg-green-100 text-green-800 border-green-200 dark:bg-green-900 dark:text-green-100 border">
                                                Excused
                                            </Badge>
                                        </div>
                                    </div>
                                </div>
                            ) : selectedViolationPoint.expires_at && (
                                <div className="grid grid-cols-2 gap-4 pt-4 border-t">
                                    <div>
                                        <Label className="text-sm font-medium text-muted-foreground">Expiration Date</Label>
                                        <p className="text-sm font-medium mt-1">{formatDate(selectedViolationPoint.expires_at)}</p>
                                        {!selectedViolationPoint.is_expired && !isActuallyExpired(selectedViolationPoint.expires_at) && (
                                            <p className="text-xs text-muted-foreground mt-1">
                                                {getDaysRemaining(selectedViolationPoint.expires_at)} days remaining
                                            </p>
                                        )}
                                        {!selectedViolationPoint.is_expired && isActuallyExpired(selectedViolationPoint.expires_at) && (
                                            <p className="text-xs text-orange-600 dark:text-orange-400 mt-1">
                                                Pending expiration
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
                                            Excused by: {selectedViolationPoint.excused_by.name} on {formatDateTime(selectedViolationPoint.excused_at)}
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

            {/* Manual Entry Dialog */}
            <Dialog open={isManualEntryOpen} onOpenChange={(open) => {
                if (!open) resetManualEntryForm();
                setIsManualEntryOpen(open);
            }}>
                <DialogContent className="sm:max-w-[600px] max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>
                            {isEditMode ? 'Edit Manual Attendance Point' : 'Add Manual Attendance Point'}
                        </DialogTitle>
                        <DialogDescription>
                            {isEditMode
                                ? 'Update the details of this manual attendance point.'
                                : 'Create a new manual attendance point. This will not be linked to an attendance record.'}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        {/* Employee Selection */}
                        <div className="grid gap-2">
                            <Label htmlFor="manual_user">
                                Employee <span className="text-red-500">*</span>
                            </Label>
                            <Popover open={isManualUserPopoverOpen} onOpenChange={setIsManualUserPopoverOpen}>
                                <PopoverTrigger asChild>
                                    <Button
                                        variant="outline"
                                        role="combobox"
                                        aria-expanded={isManualUserPopoverOpen}
                                        className="w-full justify-between font-normal"
                                        disabled={isManualSubmitting}
                                    >
                                        <span className="truncate">
                                            {manualEntryUserId
                                                ? (() => {
                                                    const user = users?.find(u => String(u.id) === manualEntryUserId);
                                                    return user ? formatUserName(user) : "Select employee...";
                                                })()
                                                : "Select employee..."}
                                        </span>
                                        <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                    </Button>
                                </PopoverTrigger>
                                <PopoverContent className="w-full p-0" align="start">
                                    <Command shouldFilter={false}>
                                        <CommandInput
                                            placeholder="Search employee..."
                                            value={manualUserSearchQuery}
                                            onValueChange={setManualUserSearchQuery}
                                        />
                                        <CommandList>
                                            <CommandEmpty>No employee found.</CommandEmpty>
                                            <CommandGroup>
                                                {users
                                                    ?.filter(user => {
                                                        if (!manualUserSearchQuery) return true;
                                                        const query = manualUserSearchQuery.toLowerCase();
                                                        const formattedName = formatUserName(user).toLowerCase();
                                                        const regularName = user.name.toLowerCase();
                                                        return formattedName.includes(query) || regularName.includes(query);
                                                    })
                                                    .map((user) => (
                                                        <CommandItem
                                                            key={user.id}
                                                            value={formatUserName(user)}
                                                            onSelect={() => {
                                                                setManualEntryUserId(String(user.id));
                                                                setIsManualUserPopoverOpen(false);
                                                                setManualUserSearchQuery("");
                                                            }}
                                                            className="cursor-pointer"
                                                        >
                                                            <Check
                                                                className={`mr-2 h-4 w-4 ${manualEntryUserId === String(user.id)
                                                                    ? "opacity-100"
                                                                    : "opacity-0"
                                                                    }`}
                                                            />
                                                            {formatUserName(user)}
                                                        </CommandItem>
                                                    ))}
                                            </CommandGroup>
                                        </CommandList>
                                    </Command>
                                </PopoverContent>
                            </Popover>
                        </div>

                        {/* Violation Date */}
                        <div className="grid gap-2">
                            <Label htmlFor="manual_shift_date">
                                Violation Date <span className="text-red-500">*</span>
                            </Label>
                            <DatePicker
                                value={manualShiftDate}
                                onChange={(value) => setManualShiftDate(value)}
                                placeholder="Select violation date"
                                disabled={isManualSubmitting}
                            />
                        </div>

                        {/* Violation Type */}
                        <div className="grid gap-2">
                            <Label htmlFor="manual_point_type">
                                Violation Type <span className="text-red-500">*</span>
                            </Label>
                            <Select
                                value={manualPointType}
                                onValueChange={setManualPointType}
                                disabled={isManualSubmitting}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select violation type" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="whole_day_absence">Whole Day Absence (1.0 pt)</SelectItem>
                                    <SelectItem value="half_day_absence">Half-Day Absence (0.5 pt)</SelectItem>
                                    <SelectItem value="tardy">Tardy (0.25 pt)</SelectItem>
                                    <SelectItem value="undertime">Undertime - Hour (0.25 pt)</SelectItem>
                                    <SelectItem value="undertime_more_than_hour">Undertime - More Than Hour (0.5 pt)</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        {/* Is Advised (only for whole_day_absence) */}
                        {manualPointType === 'whole_day_absence' && (
                            <div className="flex items-center space-x-2">
                                <Checkbox
                                    id="manual_is_advised"
                                    checked={manualIsAdvised}
                                    onCheckedChange={(checked) => setManualIsAdvised(checked as boolean)}
                                    disabled={isManualSubmitting}
                                />
                                <label
                                    htmlFor="manual_is_advised"
                                    className="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70 cursor-pointer"
                                >
                                    Advised absence
                                </label>
                            </div>
                        )}

                        {/* Tardy Minutes (only for tardy type) */}
                        {manualPointType === 'tardy' && (
                            <div className="grid gap-2">
                                <Label htmlFor="manual_tardy_minutes">Tardy Minutes</Label>
                                <Input
                                    id="manual_tardy_minutes"
                                    type="number"
                                    min="0"
                                    placeholder="Enter minutes late"
                                    value={manualTardyMinutes}
                                    onChange={(e) => setManualTardyMinutes(e.target.value)}
                                    disabled={isManualSubmitting}
                                />
                            </div>
                        )}

                        {/* Undertime Minutes (for undertime types) */}
                        {(manualPointType === 'undertime' || manualPointType === 'undertime_more_than_hour') && (
                            <div className="grid gap-2">
                                <Label htmlFor="manual_undertime_minutes">
                                    Undertime Minutes
                                    <span className="text-xs text-muted-foreground ml-2">
                                        ({manualPointType === 'undertime' ? '1-60 mins' : '61+ mins'})
                                    </span>
                                </Label>
                                <Input
                                    id="manual_undertime_minutes"
                                    type="number"
                                    min={manualPointType === 'undertime_more_than_hour' ? "61" : "1"}
                                    max={manualPointType === 'undertime' ? "60" : undefined}
                                    placeholder={manualPointType === 'undertime' ? "Enter minutes (1-60)" : "Enter minutes (61+)"}
                                    value={manualUndertimeMinutes}
                                    onChange={(e) => setManualUndertimeMinutes(e.target.value)}
                                    disabled={isManualSubmitting}
                                />
                                <p className="text-xs text-muted-foreground">
                                    {manualPointType === 'undertime'
                                        ? 'For early departures up to 1 hour (1-60 minutes)'
                                        : 'For early departures more than 1 hour (61+ minutes)'}
                                </p>
                            </div>
                        )}

                        {/* Violation Details */}
                        <div className="grid gap-2">
                            <Label htmlFor="manual_violation_details">Violation Details</Label>
                            <Textarea
                                id="manual_violation_details"
                                placeholder="Enter violation details (optional - auto-generated if empty)"
                                value={manualViolationDetails}
                                onChange={(e) => setManualViolationDetails(e.target.value)}
                                rows={3}
                                disabled={isManualSubmitting}
                                className="resize-none"
                            />
                        </div>

                        {/* Notes */}
                        <div className="grid gap-2">
                            <Label htmlFor="manual_notes">Additional Notes</Label>
                            <Textarea
                                id="manual_notes"
                                placeholder="Add any additional notes..."
                                value={manualNotes}
                                onChange={(e) => setManualNotes(e.target.value)}
                                rows={2}
                                disabled={isManualSubmitting}
                                className="resize-none"
                            />
                        </div>

                        {/* Point Value Preview */}
                        {manualPointType && (
                            <div className="rounded-lg border bg-muted/50 p-4">
                                <div className="flex items-center justify-between">
                                    <span className="text-sm text-muted-foreground">Point Value:</span>
                                    <span className="text-lg font-bold text-red-600 dark:text-red-400">
                                        {manualPointType === 'whole_day_absence' ? '1.00' :
                                            (manualPointType === 'half_day_absence' || manualPointType === 'undertime_more_than_hour') ? '0.50' : '0.25'} pts
                                    </span>
                                </div>
                                <div className="flex items-center justify-between mt-2">
                                    <span className="text-sm text-muted-foreground">Expiration:</span>
                                    <span className="text-sm font-medium">
                                        {manualPointType === 'whole_day_absence' && !manualIsAdvised
                                            ? '1 year (NCNS)'
                                            : '6 months (Standard)'}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between mt-2">
                                    <span className="text-sm text-muted-foreground">GBRO Eligible:</span>
                                    <span className="text-sm font-medium">
                                        {manualPointType === 'whole_day_absence' && !manualIsAdvised
                                            ? 'No'
                                            : 'Yes'}
                                    </span>
                                </div>
                            </div>
                        )}
                    </div>

                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => {
                                setIsManualEntryOpen(false);
                                resetManualEntryForm();
                            }}
                            disabled={isManualSubmitting}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={handleManualEntrySubmit}
                            disabled={!manualEntryUserId || !manualShiftDate || !manualPointType || isManualSubmitting}
                        >
                            {isManualSubmitting ? (
                                <>
                                    <RefreshCw className="mr-2 h-4 w-4 animate-spin" />
                                    {isEditMode ? 'Updating...' : 'Creating...'}
                                </>
                            ) : (
                                <>
                                    {isEditMode ? (
                                        <>
                                            <Pencil className="mr-2 h-4 w-4" />
                                            Update Point
                                        </>
                                    ) : (
                                        <>
                                            <Plus className="mr-2 h-4 w-4" />
                                            Create Point
                                        </>
                                    )}
                                </>
                            )}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Delete Confirmation Dialog */}
            <AlertDialog open={isDeleteDialogOpen} onOpenChange={setIsDeleteDialogOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete Manual Attendance Point</AlertDialogTitle>
                        <AlertDialogDescription>
                            Are you sure you want to delete this manual attendance point?
                            This action cannot be undone.
                            {pointToDelete && (
                                <div className="mt-3 p-3 bg-muted rounded-md">
                                    <p className="text-sm"><strong>Employee:</strong> {formatUserName(pointToDelete.user)}</p>
                                    <p className="text-sm"><strong>Date:</strong> {formatDate(pointToDelete.shift_date)}</p>
                                    <p className="text-sm"><strong>Type:</strong> {pointToDelete.point_type.replace(/_/g, ' ')}</p>
                                    <p className="text-sm"><strong>Points:</strong> {Number(pointToDelete.points).toFixed(2)}</p>
                                </div>
                            )}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel onClick={() => setPointToDelete(null)}>
                            Cancel
                        </AlertDialogCancel>
                        <AlertDialogAction
                            onClick={confirmDelete}
                            className="bg-red-600 hover:bg-red-700"
                        >
                            Delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* Export Progress Dialog */}
            <Dialog open={isExportDialogOpen} onOpenChange={(open) => !open && handleCloseExportDialog()}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Exporting Attendance Points</DialogTitle>
                        <DialogDescription>
                            Generating Excel file for all employees
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4 py-4">
                        {exportError ? (
                            <div className="flex flex-col items-center gap-4 py-4">
                                <div className="rounded-full bg-red-100 p-3">
                                    <XCircle className="h-8 w-8 text-red-600" />
                                </div>
                                <p className="text-sm text-muted-foreground text-center">
                                    {exportStatus || 'Export failed. Please try again.'}
                                </p>
                            </div>
                        ) : exportDownloadUrl ? (
                            <div className="flex flex-col items-center gap-4 py-4">
                                <div className="rounded-full bg-green-100 p-3">
                                    <CheckCircle className="h-8 w-8 text-green-600" />
                                </div>
                                <p className="text-sm text-muted-foreground text-center">
                                    Export complete! Click download to get your file.
                                </p>
                            </div>
                        ) : (
                            <>
                                <div className="flex items-center justify-center py-2">
                                    <Loader2 className="h-8 w-8 animate-spin text-primary" />
                                </div>
                                <Progress value={exportProgress} className="w-full" />
                                <p className="text-sm text-muted-foreground text-center">
                                    {exportStatus || 'Processing...'} ({Math.round(exportProgress)}%)
                                </p>
                            </>
                        )}
                    </div>

                    <DialogFooter>
                        {exportDownloadUrl ? (
                            <>
                                <Button variant="outline" onClick={handleCloseExportDialog}>
                                    Close
                                </Button>
                                <Button onClick={handleDownloadExport}>
                                    <Download className="h-4 w-4 mr-2" />
                                    Download
                                </Button>
                            </>
                        ) : exportError ? (
                            <Button variant="outline" onClick={handleCloseExportDialog}>
                                Close
                            </Button>
                        ) : (
                            <Button variant="outline" onClick={handleCloseExportDialog}>
                                Cancel
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* High Points Employees Dialog */}
            <Dialog open={isHighPointsDialogOpen} onOpenChange={(open) => {
                setIsHighPointsDialogOpen(open);
                if (!open) {
                    setSelectedHighPointsEmployee(null);
                }
            }}>
                <DialogContent className="sm:max-w-[700px] max-h-[85vh] overflow-hidden flex flex-col">
                    <DialogHeader>
                        <DialogTitle>
                            {selectedHighPointsEmployee
                                ? `Attendance Points - ${selectedHighPointsEmployee.user_name}`
                                : 'High Risk Employees'}
                        </DialogTitle>
                        <DialogDescription>
                            {selectedHighPointsEmployee
                                ? `Active attendance points (${selectedHighPointsEmployee.total_points} total points)`
                                : 'Employees with 6 or more active attendance points'}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="flex-1 overflow-y-auto">
                        {selectedHighPointsEmployee ? (
                            // Show employee's attendance points
                            <div className="space-y-3">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => {
                                        setSelectedHighPointsEmployee(null);
                                    }}
                                    className="mb-2"
                                >
                                    ← Back to list
                                </Button>

                                {selectedHighPointsEmployee.points.length === 0 ? (
                                    <div className="text-center py-8 text-muted-foreground">
                                        No active attendance points found
                                    </div>
                                ) : (
                                    <div className="space-y-2">
                                        {selectedHighPointsEmployee.points.map((point) => (
                                            <div
                                                key={point.id}
                                                className="p-3 border rounded-lg bg-card hover:bg-accent/50 transition-colors"
                                            >
                                                <div className="flex items-center justify-between">
                                                    <div className="flex items-center gap-3">
                                                        <Badge className={getPointTypeBadge(point.point_type).props.className}>
                                                            {getPointTypeBadge(point.point_type).props.children}
                                                        </Badge>
                                                        <span className="text-sm text-muted-foreground">
                                                            {formatDate(point.shift_date)}
                                                        </span>
                                                    </div>
                                                    <span className="font-bold text-red-600 dark:text-red-400">
                                                        {Number(point.points).toFixed(2)} pts
                                                    </span>
                                                </div>
                                                {point.violation_details && (
                                                    <p className="text-xs text-muted-foreground mt-2 line-clamp-2">
                                                        {point.violation_details}
                                                    </p>
                                                )}
                                                {point.expires_at && (
                                                    <p className="text-xs text-muted-foreground mt-1">
                                                        Expires: {formatDate(point.expires_at)}
                                                    </p>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                )}

                                <div className="pt-4 border-t">
                                    <Button
                                        variant="outline"
                                        className="w-full"
                                        onClick={() => {
                                            router.visit(attendancePointsShow({ user: selectedHighPointsEmployee.user_id }).url + '?show_all=1');
                                        }}
                                    >
                                        <Eye className="h-4 w-4 mr-2" />
                                        View Full Details
                                    </Button>
                                </div>
                            </div>
                        ) : (
                            // Show list of high points employees
                            <div className="space-y-2">
                                {!stats?.high_points_employees || stats.high_points_employees.length === 0 ? (
                                    <div className="text-center py-8 text-muted-foreground">
                                        <CheckCircle className="h-12 w-12 mx-auto mb-3 text-green-500" />
                                        <p>No employees with 6 or more points</p>
                                        <p className="text-sm mt-1">All employees are within acceptable limits</p>
                                    </div>
                                ) : (
                                    stats.high_points_employees.map((employee) => (
                                        <div
                                            key={employee.user_id}
                                            className="p-4 border rounded-lg hover:bg-accent cursor-pointer transition-colors"
                                            onClick={() => handleViewHighPointsEmployee(employee)}
                                        >
                                            <div className="flex items-center justify-between">
                                                <div>
                                                    <p className="font-medium">{employee.user_name}</p>
                                                    <p className="text-sm text-muted-foreground">
                                                        {employee.violations_count} violation{employee.violations_count !== 1 ? 's' : ''}
                                                    </p>
                                                </div>
                                                <div className="text-right">
                                                    <p className="text-xl font-bold text-red-600 dark:text-red-400">
                                                        {employee.total_points.toFixed(2)}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">points</p>
                                                </div>
                                            </div>
                                        </div>
                                    ))
                                )}
                            </div>
                        )}
                    </div>

                    <DialogFooter className="mt-4">
                        <Button variant="outline" onClick={() => {
                            setIsHighPointsDialogOpen(false);
                            setSelectedHighPointsEmployee(null);
                        }}>
                            Close
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Management Statistics Dialog */}
            <Dialog open={isManagementDialogOpen} onOpenChange={setIsManagementDialogOpen}>
                <DialogContent className="sm:max-w-[550px] max-h-[85vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Attendance Points Management</DialogTitle>
                        <DialogDescription>
                            View statistics and perform bulk management actions on attendance points.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4 py-4">
                        {isLoadingStats ? (
                            <div className="flex items-center justify-center py-8">
                                <Loader2 className="h-8 w-8 animate-spin text-primary" />
                            </div>
                        ) : managementStats ? (
                            <div className="grid gap-3">
                                <div className="flex items-center justify-between p-3 border rounded-lg">
                                    <div className="flex items-center gap-3">
                                        <RefreshCw className="h-5 w-5 text-green-600" />
                                        <div>
                                            <p className="font-medium">Missing Points</p>
                                            <p className="text-sm text-muted-foreground">
                                                Verified records without points
                                            </p>
                                        </div>
                                    </div>
                                    <Badge variant={managementStats.missing_points_count > 0 ? "destructive" : "secondary"}>
                                        {managementStats.missing_points_count}
                                    </Badge>
                                </div>

                                <div className="flex items-center justify-between p-3 border rounded-lg">
                                    <div className="flex items-center gap-3">
                                        <Trash2 className="h-5 w-5 text-yellow-600" />
                                        <div>
                                            <p className="font-medium">Duplicate Points</p>
                                            <p className="text-sm text-muted-foreground">
                                                Same user, date, and type entries
                                            </p>
                                        </div>
                                    </div>
                                    <Badge variant={managementStats.duplicates_count > 0 ? "destructive" : "secondary"}>
                                        {managementStats.duplicates_count}
                                    </Badge>
                                </div>

                                <div className="flex items-center justify-between p-3 border rounded-lg">
                                    <div className="flex items-center gap-3">
                                        <AlertTriangle className="h-5 w-5 text-orange-600" />
                                        <div>
                                            <p className="font-medium">Pending Expirations</p>
                                            <p className="text-sm text-muted-foreground">
                                                Should be expired but not marked
                                            </p>
                                        </div>
                                    </div>
                                    <Badge variant={managementStats.pending_expirations_count > 0 ? "destructive" : "secondary"}>
                                        {managementStats.pending_expirations_count}
                                    </Badge>
                                </div>

                                <div className="flex items-center justify-between p-3 border rounded-lg">
                                    <div className="flex items-center gap-3">
                                        <RotateCcw className="h-5 w-5 text-blue-600" />
                                        <div>
                                            <p className="font-medium">Expired Points</p>
                                            <p className="text-sm text-muted-foreground">
                                                Can be reset (excused excluded)
                                            </p>
                                        </div>
                                    </div>
                                    <Badge variant="secondary">
                                        {managementStats.expired_count}
                                    </Badge>
                                </div>
                            </div>
                        ) : (
                            <p className="text-center text-muted-foreground">
                                Failed to load statistics
                            </p>
                        )}

                        {/* No Notification Notice */}
                        <div className="flex items-center gap-2 p-3 bg-amber-50 dark:bg-amber-950 border border-amber-200 dark:border-amber-800 rounded-lg">
                            <BellOff className="h-5 w-5 text-amber-600 dark:text-amber-400 shrink-0" />
                            <p className="text-sm text-amber-700 dark:text-amber-300">
                                <strong>Note:</strong> All management actions are performed silently. Agents will NOT receive any notifications.
                            </p>
                        </div>

                        <div className="border-t pt-4">
                            <h4 className="text-sm font-medium mb-3">Quick Actions</h4>
                            <div className="grid gap-2">
                                <Button
                                    variant="outline"
                                    className="justify-start"
                                    disabled={!managementStats || managementStats.missing_points_count === 0 || isManagementAction}
                                    onClick={() => setConfirmAction('regenerate')}
                                >
                                    <RefreshCw className="h-4 w-4 mr-2" />
                                    Regenerate Points
                                    {managementStats && managementStats.missing_points_count > 0 && (
                                        <Badge variant="destructive" className="ml-auto">
                                            {managementStats.missing_points_count}
                                        </Badge>
                                    )}
                                </Button>
                                <Button
                                    variant="outline"
                                    className="justify-start"
                                    disabled={!managementStats || managementStats.duplicates_count === 0 || isManagementAction}
                                    onClick={() => setConfirmAction('remove-duplicates')}
                                >
                                    <Trash2 className="h-4 w-4 mr-2" />
                                    Remove Duplicates
                                    {managementStats && managementStats.duplicates_count > 0 && (
                                        <Badge variant="destructive" className="ml-auto">
                                            {managementStats.duplicates_count}
                                        </Badge>
                                    )}
                                </Button>
                                <Button
                                    variant="outline"
                                    className="justify-start"
                                    disabled={!managementStats || managementStats.pending_expirations_count === 0 || isManagementAction}
                                    onClick={() => setConfirmAction('expire-all')}
                                >
                                    <AlertTriangle className="h-4 w-4 mr-2" />
                                    Expire All Pending
                                    {managementStats && managementStats.pending_expirations_count > 0 && (
                                        <Badge variant="destructive" className="ml-auto">
                                            {managementStats.pending_expirations_count}
                                        </Badge>
                                    )}
                                </Button>
                                <Button
                                    variant="outline"
                                    className="justify-start"
                                    disabled={!managementStats || managementStats.expired_count === 0 || isManagementAction}
                                    onClick={() => setConfirmAction('reset-expired')}
                                >
                                    <RotateCcw className="h-4 w-4 mr-2" />
                                    Reset Expired Points
                                    {managementStats && managementStats.expired_count > 0 && (
                                        <Badge variant="secondary" className="ml-auto">
                                            {managementStats.expired_count}
                                        </Badge>
                                    )}
                                </Button>
                                <Button
                                    variant="outline"
                                    className="justify-start"
                                    disabled={isManagementAction}
                                    onClick={() => setConfirmAction('initialize-gbro-dates')}
                                >
                                    <Play className="h-4 w-4 mr-2" />
                                    Initialize GBRO Dates
                                    <span className="ml-auto text-xs text-muted-foreground">
                                        (set predictions)
                                    </span>
                                </Button>
                                <Button
                                    variant="outline"
                                    className="justify-start"
                                    disabled={isManagementAction}
                                    onClick={() => setConfirmAction('fix-gbro-dates')}
                                >
                                    <RefreshCw className="h-4 w-4 mr-2" />
                                    Fix GBRO Dates
                                    <span className="ml-auto text-xs text-muted-foreground">
                                        (fix references)
                                    </span>
                                </Button>
                                <Button
                                    variant="outline"
                                    className="justify-start"
                                    disabled={!managementStats || (managementStats.duplicates_count === 0 && managementStats.pending_expirations_count === 0) || isManagementAction}
                                    onClick={() => setConfirmAction('cleanup')}
                                >
                                    <Settings className="h-4 w-4 mr-2" />
                                    Full Cleanup
                                    <span className="ml-auto text-xs text-muted-foreground">
                                        (duplicates + expire)
                                    </span>
                                </Button>
                            </div>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setIsManagementDialogOpen(false)}>
                            Close
                        </Button>
                        <Button onClick={fetchManagementStats} disabled={isLoadingStats}>
                            {isLoadingStats ? (
                                <>
                                    <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                                    Refreshing...
                                </>
                            ) : (
                                <>
                                    <RefreshCw className="h-4 w-4 mr-2" />
                                    Refresh
                                </>
                            )}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Management Action Confirmation Dialog */}
            <AlertDialog open={!!confirmAction} onOpenChange={(open) => {
                if (!open) {
                    setConfirmAction(null);
                    // Reset filters when closing
                    setMgmtDateFrom('');
                    setMgmtDateTo('');
                    setMgmtUserId('');
                    setMgmtUserIds([]);
                    setExpirationType('both');
                }
            }}>
                <AlertDialogContent className="sm:max-w-[500px]">
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {confirmAction === 'regenerate' && 'Regenerate Attendance Points'}
                            {confirmAction === 'remove-duplicates' && 'Remove Duplicate Points'}
                            {confirmAction === 'expire-all' && 'Expire All Pending Points'}
                            {confirmAction === 'reset-expired' && 'Reset Expired Points'}
                            {confirmAction === 'cleanup' && 'Full Cleanup'}
                            {confirmAction === 'initialize-gbro-dates' && 'Initialize GBRO Dates'}
                            {confirmAction === 'fix-gbro-dates' && 'Fix GBRO Dates'}
                            {confirmAction === 'recalculate-gbro' && 'Recalculate GBRO Dates'}
                        </AlertDialogTitle>
                        <AlertDialogDescription asChild>
                            <div>
                                {confirmAction === 'regenerate' && (
                                    <>
                                        <p className="mb-3">
                                            This will regenerate attendance points from verified attendance records that don't have corresponding points.
                                        </p>

                                        {/* Filters for regenerate */}
                                        <div className="space-y-3 p-3 border rounded-lg bg-muted/50 mb-3">
                                            <p className="text-sm font-medium text-foreground">Filter Options (optional):</p>
                                            <div className="grid grid-cols-2 gap-2">
                                                <div>
                                                    <Label htmlFor="mgmt_date_from" className="text-xs">From Date</Label>
                                                    <DatePicker
                                                        value={mgmtDateFrom}
                                                        onChange={(value) => setMgmtDateFrom(value)}
                                                        placeholder="Start date"
                                                        className="h-8 text-sm"
                                                    />
                                                </div>
                                                <div>
                                                    <Label htmlFor="mgmt_date_to" className="text-xs">To Date</Label>
                                                    <DatePicker
                                                        value={mgmtDateTo}
                                                        onChange={(value) => setMgmtDateTo(value)}
                                                        placeholder="End date"
                                                        className="h-8 text-sm"
                                                    />
                                                </div>
                                            </div>
                                            <div>
                                                <Label htmlFor="mgmt_user" className="text-xs">Employee (optional)</Label>
                                                <Popover open={isMgmtUserPopoverOpen} onOpenChange={setIsMgmtUserPopoverOpen}>
                                                    <PopoverTrigger asChild>
                                                        <Button
                                                            variant="outline"
                                                            role="combobox"
                                                            className="w-full justify-between h-8 text-sm font-normal"
                                                        >
                                                            <span className="truncate">
                                                                {mgmtUserId
                                                                    ? (() => {
                                                                        const user = users?.find(u => String(u.id) === mgmtUserId);
                                                                        return user ? formatUserName(user) : "Select employee...";
                                                                    })()
                                                                    : "All Employees"}
                                                            </span>
                                                            <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                                        </Button>
                                                    </PopoverTrigger>
                                                    <PopoverContent className="w-full p-0" align="start">
                                                        <Command shouldFilter={false}>
                                                            <CommandInput
                                                                placeholder="Search employee..."
                                                                value={mgmtUserSearchQuery}
                                                                onValueChange={setMgmtUserSearchQuery}
                                                            />
                                                            <CommandList>
                                                                <CommandEmpty>No employee found.</CommandEmpty>
                                                                <CommandGroup>
                                                                    <CommandItem
                                                                        value="all"
                                                                        onSelect={() => {
                                                                            setMgmtUserId('');
                                                                            setIsMgmtUserPopoverOpen(false);
                                                                            setMgmtUserSearchQuery('');
                                                                        }}
                                                                        className="cursor-pointer"
                                                                    >
                                                                        <Check className={`mr-2 h-4 w-4 ${!mgmtUserId ? "opacity-100" : "opacity-0"}`} />
                                                                        All Employees
                                                                    </CommandItem>
                                                                    {users?.filter(user => {
                                                                        if (!mgmtUserSearchQuery) return true;
                                                                        const query = mgmtUserSearchQuery.toLowerCase();
                                                                        return formatUserName(user).toLowerCase().includes(query) || user.name.toLowerCase().includes(query);
                                                                    }).slice(0, 50).map((user) => (
                                                                        <CommandItem
                                                                            key={user.id}
                                                                            value={formatUserName(user)}
                                                                            onSelect={() => {
                                                                                setMgmtUserId(String(user.id));
                                                                                setIsMgmtUserPopoverOpen(false);
                                                                                setMgmtUserSearchQuery('');
                                                                            }}
                                                                            className="cursor-pointer"
                                                                        >
                                                                            <Check className={`mr-2 h-4 w-4 ${mgmtUserId === String(user.id) ? "opacity-100" : "opacity-0"}`} />
                                                                            {formatUserName(user)}
                                                                        </CommandItem>
                                                                    ))}
                                                                </CommandGroup>
                                                            </CommandList>
                                                        </Command>
                                                    </PopoverContent>
                                                </Popover>
                                            </div>
                                        </div>

                                        <span className="flex items-center gap-1 text-amber-600">
                                            <BellOff className="h-4 w-4 inline" />
                                            <strong>No notifications will be sent.</strong>
                                        </span>
                                    </>
                                )}
                                {confirmAction === 'remove-duplicates' && (
                                    <>
                                        This will remove all duplicate attendance point entries (same user, date, and type).
                                        <br /><br />
                                        <strong className="text-green-600">Excused points will be preserved.</strong> If duplicates exist and one is excused, the excused entry will be kept.
                                        <br /><br />
                                        <span className="flex items-center gap-1 text-amber-600">
                                            <BellOff className="h-4 w-4 inline" />
                                            <strong>No notifications will be sent.</strong>
                                        </span>
                                        <br />
                                        <strong className="text-yellow-600">This action cannot be undone.</strong>
                                    </>
                                )}
                                {confirmAction === 'expire-all' && (
                                    <>
                                        <p className="mb-3">
                                            This will mark pending expiration points as expired immediately.
                                            Points that have passed their expiration date but not yet marked will be updated.
                                        </p>

                                        {/* Expiration Type Selection */}
                                        <div className="space-y-3 p-3 border rounded-lg bg-muted/50 mb-3">
                                            <p className="text-sm font-medium text-foreground">Expiration Type:</p>
                                            <div className="flex flex-col gap-2">
                                                <label className="flex items-center gap-2 cursor-pointer">
                                                    <input
                                                        type="radio"
                                                        name="expirationType"
                                                        value="both"
                                                        checked={expirationType === 'both'}
                                                        onChange={() => setExpirationType('both')}
                                                        className="w-4 h-4"
                                                    />
                                                    <span className="text-sm">Both SRO + GBRO (default)</span>
                                                </label>
                                                <label className="flex items-center gap-2 cursor-pointer">
                                                    <input
                                                        type="radio"
                                                        name="expirationType"
                                                        value="sro"
                                                        checked={expirationType === 'sro'}
                                                        onChange={() => setExpirationType('sro')}
                                                        className="w-4 h-4"
                                                    />
                                                    <span className="text-sm">SRO only (Standard Roll Off - 6 months)</span>
                                                </label>
                                                <label className="flex items-center gap-2 cursor-pointer">
                                                    <input
                                                        type="radio"
                                                        name="expirationType"
                                                        value="gbro"
                                                        checked={expirationType === 'gbro'}
                                                        onChange={() => setExpirationType('gbro')}
                                                        className="w-4 h-4"
                                                    />
                                                    <span className="text-sm">GBRO only (Good Behavior Roll Off - 60 days)</span>
                                                </label>
                                            </div>
                                        </div>

                                        {/* User Filter - Multi-select */}
                                        <div className="space-y-3 p-3 border rounded-lg bg-muted/50 mb-3">
                                            <p className="text-sm font-medium text-foreground">Filter by Employees (optional):</p>
                                            <div>
                                                <Label htmlFor="expire_all_users" className="text-xs">Select Employees</Label>
                                                <Popover open={isMgmtUserPopoverOpen} onOpenChange={setIsMgmtUserPopoverOpen}>
                                                    <PopoverTrigger asChild>
                                                        <Button
                                                            variant="outline"
                                                            role="combobox"
                                                            className="w-full justify-between h-auto min-h-8 text-sm font-normal"
                                                        >
                                                            <span className="truncate text-left">
                                                                {mgmtUserIds.length === 0
                                                                    ? "All Employees"
                                                                    : mgmtUserIds.length === 1
                                                                        ? (() => {
                                                                            const user = users?.find(u => String(u.id) === mgmtUserIds[0]);
                                                                            return user ? formatUserName(user) : "1 employee selected";
                                                                        })()
                                                                        : `${mgmtUserIds.length} employees selected`}
                                                            </span>
                                                            <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                                        </Button>
                                                    </PopoverTrigger>
                                                    <PopoverContent className="w-full p-0" align="start">
                                                        <Command shouldFilter={false}>
                                                            <CommandInput
                                                                placeholder="Search employee..."
                                                                value={mgmtUserSearchQuery}
                                                                onValueChange={setMgmtUserSearchQuery}
                                                            />
                                                            <CommandList>
                                                                <CommandEmpty>No employee found.</CommandEmpty>
                                                                <CommandGroup>
                                                                    <CommandItem
                                                                        value="clear-all"
                                                                        onSelect={() => {
                                                                            setMgmtUserIds([]);
                                                                            setMgmtUserSearchQuery('');
                                                                        }}
                                                                        className="cursor-pointer"
                                                                    >
                                                                        <Check className={`mr-2 h-4 w-4 ${mgmtUserIds.length === 0 ? "opacity-100" : "opacity-0"}`} />
                                                                        All Employees (clear selection)
                                                                    </CommandItem>
                                                                    {users?.filter(user => {
                                                                        if (!mgmtUserSearchQuery) return true;
                                                                        const query = mgmtUserSearchQuery.toLowerCase();
                                                                        return formatUserName(user).toLowerCase().includes(query) || user.name.toLowerCase().includes(query);
                                                                    }).slice(0, 50).map((user) => {
                                                                        const isSelected = mgmtUserIds.includes(String(user.id));
                                                                        return (
                                                                            <CommandItem
                                                                                key={user.id}
                                                                                value={formatUserName(user)}
                                                                                onSelect={() => {
                                                                                    if (isSelected) {
                                                                                        setMgmtUserIds(mgmtUserIds.filter(id => id !== String(user.id)));
                                                                                    } else {
                                                                                        setMgmtUserIds([...mgmtUserIds, String(user.id)]);
                                                                                    }
                                                                                }}
                                                                                className="cursor-pointer"
                                                                            >
                                                                                <Check className={`mr-2 h-4 w-4 ${isSelected ? "opacity-100" : "opacity-0"}`} />
                                                                                {formatUserName(user)}
                                                                            </CommandItem>
                                                                        );
                                                                    })}
                                                                </CommandGroup>
                                                            </CommandList>
                                                        </Command>
                                                    </PopoverContent>
                                                </Popover>
                                                {mgmtUserIds.length > 0 && (
                                                    <div className="flex flex-wrap gap-1 mt-2">
                                                        {mgmtUserIds.map(id => {
                                                            const user = users?.find(u => String(u.id) === id);
                                                            return user ? (
                                                                <Badge key={id} variant="secondary" className="text-xs">
                                                                    {formatUserName(user)}
                                                                    <button
                                                                        type="button"
                                                                        className="ml-1 hover:text-destructive"
                                                                        onClick={() => setMgmtUserIds(mgmtUserIds.filter(uid => uid !== id))}
                                                                    >
                                                                        ×
                                                                    </button>
                                                                </Badge>
                                                            ) : null;
                                                        })}
                                                    </div>
                                                )}
                                            </div>
                                        </div>

                                        <span className="flex items-center gap-1 text-amber-600">
                                            <BellOff className="h-4 w-4 inline" />
                                            <strong>No notifications will be sent to agents.</strong>
                                        </span>
                                    </>
                                )}
                                {confirmAction === 'reset-expired' && (
                                    <>
                                        <p className="mb-3">
                                            This will reset expired points back to active status.
                                            Their expiration dates will be recalculated based on the original violation date.
                                        </p>

                                        {/* Filter for reset-expired - Multi-select */}
                                        <div className="space-y-3 p-3 border rounded-lg bg-muted/50 mb-3">
                                            <p className="text-sm font-medium text-foreground">Filter by Employees (optional):</p>
                                            <div>
                                                <Label htmlFor="mgmt_user_reset" className="text-xs">Select Employees</Label>
                                                <Popover open={isMgmtUserPopoverOpen} onOpenChange={setIsMgmtUserPopoverOpen}>
                                                    <PopoverTrigger asChild>
                                                        <Button
                                                            variant="outline"
                                                            role="combobox"
                                                            className="w-full justify-between h-auto min-h-8 text-sm font-normal"
                                                        >
                                                            <span className="truncate text-left">
                                                                {mgmtUserIds.length === 0
                                                                    ? "All Employees"
                                                                    : mgmtUserIds.length === 1
                                                                        ? (() => {
                                                                            const user = users?.find(u => String(u.id) === mgmtUserIds[0]);
                                                                            return user ? formatUserName(user) : "1 employee selected";
                                                                        })()
                                                                        : `${mgmtUserIds.length} employees selected`}
                                                            </span>
                                                            <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                                        </Button>
                                                    </PopoverTrigger>
                                                    <PopoverContent className="w-full p-0" align="start">
                                                        <Command shouldFilter={false}>
                                                            <CommandInput
                                                                placeholder="Search employee..."
                                                                value={mgmtUserSearchQuery}
                                                                onValueChange={setMgmtUserSearchQuery}
                                                            />
                                                            <CommandList>
                                                                <CommandEmpty>No employee found.</CommandEmpty>
                                                                <CommandGroup>
                                                                    <CommandItem
                                                                        value="clear-all"
                                                                        onSelect={() => {
                                                                            setMgmtUserIds([]);
                                                                            setMgmtUserSearchQuery('');
                                                                        }}
                                                                        className="cursor-pointer"
                                                                    >
                                                                        <Check className={`mr-2 h-4 w-4 ${mgmtUserIds.length === 0 ? "opacity-100" : "opacity-0"}`} />
                                                                        All Employees (clear selection)
                                                                    </CommandItem>
                                                                    {users?.filter(user => {
                                                                        if (!mgmtUserSearchQuery) return true;
                                                                        const query = mgmtUserSearchQuery.toLowerCase();
                                                                        return formatUserName(user).toLowerCase().includes(query) || user.name.toLowerCase().includes(query);
                                                                    }).slice(0, 50).map((user) => {
                                                                        const isSelected = mgmtUserIds.includes(String(user.id));
                                                                        return (
                                                                            <CommandItem
                                                                                key={user.id}
                                                                                value={formatUserName(user)}
                                                                                onSelect={() => {
                                                                                    if (isSelected) {
                                                                                        setMgmtUserIds(mgmtUserIds.filter(id => id !== String(user.id)));
                                                                                    } else {
                                                                                        setMgmtUserIds([...mgmtUserIds, String(user.id)]);
                                                                                    }
                                                                                }}
                                                                                className="cursor-pointer"
                                                                            >
                                                                                <Check className={`mr-2 h-4 w-4 ${isSelected ? "opacity-100" : "opacity-0"}`} />
                                                                                {formatUserName(user)}
                                                                            </CommandItem>
                                                                        );
                                                                    })}
                                                                </CommandGroup>
                                                            </CommandList>
                                                        </Command>
                                                    </PopoverContent>
                                                </Popover>
                                                {mgmtUserIds.length > 0 && (
                                                    <div className="flex flex-wrap gap-1 mt-2">
                                                        {mgmtUserIds.map(id => {
                                                            const user = users?.find(u => String(u.id) === id);
                                                            return user ? (
                                                                <Badge key={id} variant="secondary" className="text-xs">
                                                                    {formatUserName(user)}
                                                                    <button
                                                                        type="button"
                                                                        className="ml-1 hover:text-destructive"
                                                                        onClick={() => setMgmtUserIds(mgmtUserIds.filter(uid => uid !== id))}
                                                                    >
                                                                        ×
                                                                    </button>
                                                                </Badge>
                                                            ) : null;
                                                        })}
                                                    </div>
                                                )}
                                            </div>
                                        </div>

                                        <strong className="text-blue-600">Excused points will NOT be affected.</strong>
                                        <br /><br />
                                        <span className="flex items-center gap-1 text-amber-600">
                                            <BellOff className="h-4 w-4 inline" />
                                            <strong>No notifications will be sent.</strong>
                                        </span>
                                        <br />
                                        <span className="text-xs text-muted-foreground">
                                            Use this if you need to reprocess expirations or correct accidental expirations.
                                        </span>
                                    </>
                                )}
                                {confirmAction === 'cleanup' && (
                                    <>
                                        This will perform a full cleanup of attendance points:
                                        <br /><br />
                                        <ul className="list-disc list-inside space-y-1 text-sm">
                                            <li><strong>Remove Duplicates:</strong> Delete duplicate entries (excused points preserved)</li>
                                            <li><strong>Expire Pending:</strong> Mark all past-due points as expired (excused excluded)</li>
                                        </ul>
                                        <br />
                                        <strong className="text-green-600">Excused points will NOT be affected.</strong>
                                        <br /><br />
                                        <span className="flex items-center gap-1 text-amber-600">
                                            <BellOff className="h-4 w-4 inline" />
                                            <strong>No notifications will be sent.</strong>
                                        </span>
                                    </>
                                )}
                                {confirmAction === 'initialize-gbro-dates' && (
                                    <>
                                        <p className="mb-3">
                                            This will initialize GBRO (Good Behavior Roll Off) prediction dates for active GBRO-eligible points.
                                        </p>

                                        {/* User filter */}
                                        <div className="space-y-3 p-3 border rounded-lg bg-muted/50 mb-3">
                                            <p className="text-sm font-medium text-foreground">Filter by Employees (optional):</p>
                                            <div>
                                                <Label htmlFor="gbro_init_users" className="text-xs">Select Employees</Label>
                                                <Popover open={isMgmtUserPopoverOpen} onOpenChange={setIsMgmtUserPopoverOpen}>
                                                    <PopoverTrigger asChild>
                                                        <Button
                                                            variant="outline"
                                                            role="combobox"
                                                            className="w-full justify-between h-auto min-h-8 text-sm font-normal"
                                                        >
                                                            <span className="truncate text-left">
                                                                {mgmtUserIds.length === 0
                                                                    ? "All Employees"
                                                                    : mgmtUserIds.length === 1
                                                                        ? (() => {
                                                                            const user = users?.find(u => String(u.id) === mgmtUserIds[0]);
                                                                            return user ? formatUserName(user) : "1 employee selected";
                                                                        })()
                                                                        : `${mgmtUserIds.length} employees selected`}
                                                            </span>
                                                            <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                                        </Button>
                                                    </PopoverTrigger>
                                                    <PopoverContent className="w-full p-0" align="start">
                                                        <Command shouldFilter={false}>
                                                            <CommandInput
                                                                placeholder="Search employee..."
                                                                value={mgmtUserSearchQuery}
                                                                onValueChange={setMgmtUserSearchQuery}
                                                            />
                                                            <CommandList>
                                                                <CommandEmpty>No employee found.</CommandEmpty>
                                                                <CommandGroup>
                                                                    <CommandItem
                                                                        value="clear-all"
                                                                        onSelect={() => {
                                                                            setMgmtUserIds([]);
                                                                            setMgmtUserSearchQuery('');
                                                                        }}
                                                                        className="cursor-pointer"
                                                                    >
                                                                        <Check className={`mr-2 h-4 w-4 ${mgmtUserIds.length === 0 ? "opacity-100" : "opacity-0"}`} />
                                                                        All Employees (clear selection)
                                                                    </CommandItem>
                                                                    {users?.filter(user => {
                                                                        if (!mgmtUserSearchQuery) return true;
                                                                        const query = mgmtUserSearchQuery.toLowerCase();
                                                                        return formatUserName(user).toLowerCase().includes(query) || user.name.toLowerCase().includes(query);
                                                                    }).slice(0, 50).map((user) => {
                                                                        const isSelected = mgmtUserIds.includes(String(user.id));
                                                                        return (
                                                                            <CommandItem
                                                                                key={user.id}
                                                                                value={formatUserName(user)}
                                                                                onSelect={() => {
                                                                                    if (isSelected) {
                                                                                        setMgmtUserIds(mgmtUserIds.filter(id => id !== String(user.id)));
                                                                                    } else {
                                                                                        setMgmtUserIds([...mgmtUserIds, String(user.id)]);
                                                                                    }
                                                                                }}
                                                                                className="cursor-pointer"
                                                                            >
                                                                                <Check className={`mr-2 h-4 w-4 ${isSelected ? "opacity-100" : "opacity-0"}`} />
                                                                                {formatUserName(user)}
                                                                            </CommandItem>
                                                                        );
                                                                    })}
                                                                </CommandGroup>
                                                            </CommandList>
                                                        </Command>
                                                    </PopoverContent>
                                                </Popover>
                                                {mgmtUserIds.length > 0 && (
                                                    <div className="flex flex-wrap gap-1 mt-2">
                                                        {mgmtUserIds.map(id => {
                                                            const user = users?.find(u => String(u.id) === id);
                                                            return user ? (
                                                                <Badge key={id} variant="secondary" className="text-xs">
                                                                    {formatUserName(user)}
                                                                    <button
                                                                        type="button"
                                                                        className="ml-1 hover:text-destructive"
                                                                        onClick={() => setMgmtUserIds(mgmtUserIds.filter(uid => uid !== id))}
                                                                    >
                                                                        ×
                                                                    </button>
                                                                </Badge>
                                                            ) : null;
                                                        })}
                                                    </div>
                                                )}
                                            </div>
                                        </div>

                                        <ul className="list-disc list-inside space-y-1 text-sm mb-3">
                                            <li>Sets <code className="bg-muted px-1 rounded">gbro_expires_at</code> for points that don't have it</li>
                                            <li>Uses cascading pair logic (every 2 points = 60 days)</li>
                                            <li>Reference date = most recent of: last violation OR last GBRO application</li>
                                        </ul>
                                        <span className="text-blue-600 text-sm">
                                            <strong>Safe operation:</strong> Only updates prediction dates, does not expire any points.
                                        </span>
                                    </>
                                )}
                                {confirmAction === 'fix-gbro-dates' && (
                                    <>
                                        <p className="mb-3">
                                            This will fix GBRO prediction dates for points that were updated with incorrect references.
                                        </p>

                                        {/* User filter */}
                                        <div className="space-y-3 p-3 border rounded-lg bg-muted/50 mb-3">
                                            <p className="text-sm font-medium text-foreground">Filter by Employees (optional):</p>
                                            <div>
                                                <Label htmlFor="gbro_fix_users" className="text-xs">Select Employees</Label>
                                                <Popover open={isMgmtUserPopoverOpen} onOpenChange={setIsMgmtUserPopoverOpen}>
                                                    <PopoverTrigger asChild>
                                                        <Button
                                                            variant="outline"
                                                            role="combobox"
                                                            className="w-full justify-between h-auto min-h-8 text-sm font-normal"
                                                        >
                                                            <span className="truncate text-left">
                                                                {mgmtUserIds.length === 0
                                                                    ? "All Employees"
                                                                    : mgmtUserIds.length === 1
                                                                        ? (() => {
                                                                            const user = users?.find(u => String(u.id) === mgmtUserIds[0]);
                                                                            return user ? formatUserName(user) : "1 employee selected";
                                                                        })()
                                                                        : `${mgmtUserIds.length} employees selected`}
                                                            </span>
                                                            <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                                        </Button>
                                                    </PopoverTrigger>
                                                    <PopoverContent className="w-full p-0" align="start">
                                                        <Command shouldFilter={false}>
                                                            <CommandInput
                                                                placeholder="Search employee..."
                                                                value={mgmtUserSearchQuery}
                                                                onValueChange={setMgmtUserSearchQuery}
                                                            />
                                                            <CommandList>
                                                                <CommandEmpty>No employee found.</CommandEmpty>
                                                                <CommandGroup>
                                                                    <CommandItem
                                                                        value="clear-all"
                                                                        onSelect={() => {
                                                                            setMgmtUserIds([]);
                                                                            setMgmtUserSearchQuery('');
                                                                        }}
                                                                        className="cursor-pointer"
                                                                    >
                                                                        <Check className={`mr-2 h-4 w-4 ${mgmtUserIds.length === 0 ? "opacity-100" : "opacity-0"}`} />
                                                                        All Employees (clear selection)
                                                                    </CommandItem>
                                                                    {users?.filter(user => {
                                                                        if (!mgmtUserSearchQuery) return true;
                                                                        const query = mgmtUserSearchQuery.toLowerCase();
                                                                        return formatUserName(user).toLowerCase().includes(query) || user.name.toLowerCase().includes(query);
                                                                    }).slice(0, 50).map((user) => {
                                                                        const isSelected = mgmtUserIds.includes(String(user.id));
                                                                        return (
                                                                            <CommandItem
                                                                                key={user.id}
                                                                                value={formatUserName(user)}
                                                                                onSelect={() => {
                                                                                    if (isSelected) {
                                                                                        setMgmtUserIds(mgmtUserIds.filter(id => id !== String(user.id)));
                                                                                    } else {
                                                                                        setMgmtUserIds([...mgmtUserIds, String(user.id)]);
                                                                                    }
                                                                                }}
                                                                                className="cursor-pointer"
                                                                            >
                                                                                <Check className={`mr-2 h-4 w-4 ${isSelected ? "opacity-100" : "opacity-0"}`} />
                                                                                {formatUserName(user)}
                                                                            </CommandItem>
                                                                        );
                                                                    })}
                                                                </CommandGroup>
                                                            </CommandList>
                                                        </Command>
                                                    </PopoverContent>
                                                </Popover>
                                                {mgmtUserIds.length > 0 && (
                                                    <div className="flex flex-wrap gap-1 mt-2">
                                                        {mgmtUserIds.map(id => {
                                                            const user = users?.find(u => String(u.id) === id);
                                                            return user ? (
                                                                <Badge key={id} variant="secondary" className="text-xs">
                                                                    {formatUserName(user)}
                                                                    <button
                                                                        type="button"
                                                                        className="ml-1 hover:text-destructive"
                                                                        onClick={() => setMgmtUserIds(mgmtUserIds.filter(uid => uid !== id))}
                                                                    >
                                                                        ×
                                                                    </button>
                                                                </Badge>
                                                            ) : null;
                                                        })}
                                                    </div>
                                                )}
                                            </div>
                                        </div>

                                        <ul className="list-disc list-inside space-y-1 text-sm mb-3">
                                            <li>Finds users who have had GBRO applied previously</li>
                                            <li>Recalculates remaining points' prediction dates based on the scheduled GBRO date</li>
                                            <li>Ensures fairness: new prediction = scheduled_gbro_date + 60 days</li>
                                        </ul>
                                        <span className="text-blue-600 text-sm">
                                            <strong>Safe operation:</strong> Only updates prediction dates, does not expire any points.
                                        </span>
                                    </>
                                )}
                                {confirmAction === 'recalculate-gbro' && (
                                    <>
                                        <p className="mb-3">
                                            This performs a <strong>full cascade recalculation</strong> of all GBRO dates.
                                            It resets all GBRO states and re-simulates the entire timeline chronologically.
                                        </p>

                                        {/* All employees toggle */}
                                        <div className="space-y-3 p-3 border rounded-lg bg-muted/50 mb-3">
                                            <div className="flex items-center space-x-2">
                                                <Checkbox
                                                    id="recalculate-all"
                                                    checked={recalculateAllEmployees}
                                                    onCheckedChange={(checked) => {
                                                        setRecalculateAllEmployees(checked === true);
                                                        if (checked) {
                                                            setMgmtUserIds([]);
                                                        }
                                                    }}
                                                />
                                                <Label htmlFor="recalculate-all" className="text-sm font-medium cursor-pointer">
                                                    Recalculate for All Employees
                                                </Label>
                                            </div>

                                            {!recalculateAllEmployees && (
                                                <div className="mt-3">
                                                    <Label htmlFor="gbro_recalc_users" className="text-xs">Or Select Specific Employees</Label>
                                                    <Popover open={isMgmtUserPopoverOpen} onOpenChange={setIsMgmtUserPopoverOpen}>
                                                        <PopoverTrigger asChild>
                                                            <Button
                                                                variant="outline"
                                                                role="combobox"
                                                                className="w-full justify-between h-auto min-h-8 text-sm font-normal mt-1"
                                                            >
                                                                <span className="truncate text-left">
                                                                    {mgmtUserIds.length === 0
                                                                        ? "Select employees..."
                                                                        : mgmtUserIds.length === 1
                                                                            ? (() => {
                                                                                const user = users?.find(u => String(u.id) === mgmtUserIds[0]);
                                                                                return user ? formatUserName(user) : "1 employee selected";
                                                                            })()
                                                                            : `${mgmtUserIds.length} employees selected`}
                                                                </span>
                                                                <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                                            </Button>
                                                        </PopoverTrigger>
                                                        <PopoverContent className="w-full p-0" align="start">
                                                            <Command shouldFilter={false}>
                                                                <CommandInput
                                                                    placeholder="Search employee..."
                                                                    value={mgmtUserSearchQuery}
                                                                    onValueChange={setMgmtUserSearchQuery}
                                                                />
                                                                <CommandList>
                                                                    <CommandEmpty>No employee found.</CommandEmpty>
                                                                    <CommandGroup>
                                                                        {users?.filter(user => {
                                                                            if (!mgmtUserSearchQuery) return true;
                                                                            const query = mgmtUserSearchQuery.toLowerCase();
                                                                            return formatUserName(user).toLowerCase().includes(query) || user.name.toLowerCase().includes(query);
                                                                        }).slice(0, 50).map((user) => {
                                                                            const isSelected = mgmtUserIds.includes(String(user.id));
                                                                            return (
                                                                                <CommandItem
                                                                                    key={user.id}
                                                                                    value={formatUserName(user)}
                                                                                    onSelect={() => {
                                                                                        if (isSelected) {
                                                                                            setMgmtUserIds(mgmtUserIds.filter(id => id !== String(user.id)));
                                                                                        } else {
                                                                                            setMgmtUserIds([...mgmtUserIds, String(user.id)]);
                                                                                        }
                                                                                    }}
                                                                                    className="cursor-pointer"
                                                                                >
                                                                                    <Check className={`mr-2 h-4 w-4 ${isSelected ? "opacity-100" : "opacity-0"}`} />
                                                                                    {formatUserName(user)}
                                                                                </CommandItem>
                                                                            );
                                                                        })}
                                                                    </CommandGroup>
                                                                </CommandList>
                                                            </Command>
                                                        </PopoverContent>
                                                    </Popover>
                                                    {mgmtUserIds.length > 0 && (
                                                        <div className="flex flex-wrap gap-1 mt-2">
                                                            {mgmtUserIds.map(id => {
                                                                const user = users?.find(u => String(u.id) === id);
                                                                return user ? (
                                                                    <Badge key={id} variant="secondary" className="text-xs">
                                                                        {formatUserName(user)}
                                                                        <button
                                                                            type="button"
                                                                            className="ml-1 hover:text-destructive"
                                                                            onClick={() => setMgmtUserIds(mgmtUserIds.filter(uid => uid !== id))}
                                                                        >
                                                                            ×
                                                                        </button>
                                                                    </Badge>
                                                                ) : null;
                                                            })}
                                                        </div>
                                                    )}
                                                </div>
                                            )}
                                        </div>

                                        <p className="text-sm mb-3"><strong>Use this when:</strong></p>
                                        <ul className="list-disc list-inside space-y-1 text-sm mb-3">
                                            <li>Backdated points were added, edited, or deleted</li>
                                            <li>GBRO dates appear incorrect after data changes</li>
                                            <li>Points expired in wrong order</li>
                                        </ul>
                                        {recalculateAllEmployees && (
                                            <span className="text-orange-600 text-sm">
                                                <strong>Warning:</strong> This will process all {users?.length || 0} employees. This may take a while.
                                            </span>
                                        )}
                                    </>
                                )}
                            </div>
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={isManagementAction}>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            disabled={isManagementAction}
                            onClick={() => confirmAction && handleManagementAction(confirmAction)}
                            className={
                                confirmAction === 'regenerate' ? 'bg-green-600 hover:bg-green-700' :
                                    confirmAction === 'remove-duplicates' ? 'bg-yellow-600 hover:bg-yellow-700' :
                                        confirmAction === 'expire-all' ? 'bg-orange-600 hover:bg-orange-700' :
                                            confirmAction === 'cleanup' ? 'bg-purple-600 hover:bg-purple-700' :
                                                confirmAction === 'initialize-gbro-dates' ? 'bg-cyan-600 hover:bg-cyan-700' :
                                                    confirmAction === 'fix-gbro-dates' ? 'bg-teal-600 hover:bg-teal-700' :
                                                        confirmAction === 'recalculate-gbro' ? 'bg-indigo-600 hover:bg-indigo-700' :
                                                            'bg-blue-600 hover:bg-blue-700'
                            }
                        >
                            {isManagementAction ? (
                                <>
                                    <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                                    Processing...
                                </>
                            ) : (
                                <>
                                    {confirmAction === 'regenerate' && 'Regenerate Points'}
                                    {confirmAction === 'remove-duplicates' && 'Remove Duplicates'}
                                    {confirmAction === 'expire-all' && 'Expire All'}
                                    {confirmAction === 'reset-expired' && 'Reset Points'}
                                    {confirmAction === 'cleanup' && 'Run Cleanup'}
                                    {confirmAction === 'initialize-gbro-dates' && 'Initialize GBRO Dates'}
                                    {confirmAction === 'fix-gbro-dates' && 'Fix GBRO Dates'}
                                    {confirmAction === 'recalculate-gbro' && 'Recalculate GBRO'}
                                </>
                            )}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}
