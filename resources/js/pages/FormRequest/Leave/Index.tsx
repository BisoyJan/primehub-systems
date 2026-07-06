import React, { useState, useEffect, useRef } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { format, parseISO, getYear } from 'date-fns';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Can } from '@/components/authorization';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';

import { Plus, Eye, Ban, RefreshCw, Filter, Trash2, Pencil, CheckCircle, Play, Pause, Download, Calendar, FileImage, AlertTriangle, List, Clock, XCircle } from 'lucide-react';
import { toast } from 'sonner';
import { useFlashMessage, usePageLoading, usePageMeta } from '@/hooks';
import { usePermission } from '@/hooks/use-permission';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { TableSkeleton } from '@/components/TableSkeleton';
import PaginationNav from '@/components/pagination-nav';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Progress } from '@/components/ui/progress';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { index as leaveIndexRoute, create as leaveCreateRoute, show as leaveShowRoute, cancel as leaveCancelRoute, destroy as leaveDestroyRoute, edit as leaveEditRoute } from '@/routes/leave-requests';
import { MultiSelectFilter, parseMultiSelectParam, multiSelectToParam } from '@/components/multi-select-filter';

interface User {
    id: number;
    name: string;
}

interface LeaveRequest {
    id: number;
    user: User;
    leave_type: string;
    start_date: string;
    end_date: string;
    days_requested: number;
    has_partial_denial: boolean;
    approved_days: number | null;
    campaign_department: string;
    status: string;
    admin_approved_at: string | null;
    hr_approved_at: string | null;
    requires_tl_approval: boolean;
    tl_approved_at: string | null;
    tl_rejected: boolean;
    created_at: string;
    medical_cert_path: string | null;
    medical_cert_submitted: boolean;
    documents_count?: number;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface Employee {
    id: number;
    name: string;
}

type StatusTab = 'all' | 'pending' | 'upcoming' | 'approved' | 'denied' | 'cancelled';

interface StatusCounts {
    all: number;
    pending: number;
    upcoming: number;
    approved: number;
    denied: number;
    cancelled: number;
}

interface Props {
    leaveRequests: {
        data: LeaveRequest[];
        links?: PaginationLink[];
        meta?: PaginationMeta;
    };
    filters: {
        status?: string;
        type?: string;
        period?: string;
        employee_name?: string;
        campaign_department?: string;
        user_id?: string;
    };
    statusCounts: StatusCounts;
    isAdmin: boolean;
    isTeamLead?: boolean;
    hasPendingRequests: boolean;
    auth: {
        user: {
            id: number;
            name: string;
        };
    };
    campaigns?: string[];
    allEmployees?: Employee[];
    teamLeadCampaignNames?: string[];
}

const statusTabs: { key: StatusTab; label: string; icon: React.ComponentType<{ className?: string }> }[] = [
    { key: 'all', label: 'All', icon: List },
    { key: 'upcoming', label: 'Upcoming', icon: Calendar },
    { key: 'pending', label: 'Pending', icon: Clock },
    { key: 'approved', label: 'Approved', icon: CheckCircle },
    { key: 'denied', label: 'Denied', icon: XCircle },
    { key: 'cancelled', label: 'Cancelled', icon: Ban },
];

export default function Index({ leaveRequests, filters, statusCounts, isAdmin, isTeamLead, auth, campaigns = [], allEmployees = [], teamLeadCampaignNames }: Props) {
    // Show employee column for admins and team leads (who can see other users' requests)
    const showEmployeeColumn = isAdmin || isTeamLead;

    const { title, breadcrumbs } = usePageMeta({
        title: 'Leave Requests',
        breadcrumbs: [
            { title: 'Form Requests', href: '/form-requests' },
            { title: 'Leave Requests', href: leaveIndexRoute().url },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();
    const { can } = usePermission();

    const [activeTab, setActiveTab] = useState<StatusTab>((filters.status as StatusTab) || 'all');
    const [selectedTypes, setSelectedTypes] = useState<string[]>(parseMultiSelectParam(filters.type));
    const [filterPeriod, setFilterPeriod] = useState(filters.period || 'all');
    const [selectedUserIds, setSelectedUserIds] = useState<string[]>(parseMultiSelectParam(filters.user_id));
    const [selectedCampaigns, setSelectedCampaigns] = useState<string[]>(parseMultiSelectParam(filters.campaign_department));
    const [showCancelDialog, setShowCancelDialog] = useState(false);
    const [showDeleteDialog, setShowDeleteDialog] = useState(false);
    const [selectedLeaveId, setSelectedLeaveId] = useState<number | null>(null);
    const cancelForm = useForm({ cancellation_reason: '' });
    const [lastRefresh, setLastRefresh] = useState<Date>(new Date());
    const [autoRefreshEnabled, setAutoRefreshEnabled] = useState(false);

    // Export dialog state
    const [showExportDialog, setShowExportDialog] = useState(false);
    const [exportYear, setExportYear] = useState(new Date().getFullYear() - 1);
    const [isExporting, setIsExporting] = useState(false);
    const [exportProgress, setExportProgress] = useState({ percent: 0, status: '' });

    const paginationMeta: PaginationMeta = leaveRequests.meta || {
        current_page: 1,
        last_page: 1,
        per_page: leaveRequests.data.length || 1,
        total: leaveRequests.data.length,
    };
    const paginationLinks = leaveRequests.links || [];

    // Filter employees based on search query (from all employees list)
    const showClearFilters = activeTab !== 'all' || selectedTypes.length > 0 || selectedUserIds.length > 0 || selectedCampaigns.length > 0 || filterPeriod !== 'all';

    const buildFilterParams = React.useCallback((overrideStatus?: StatusTab) => {
        const params: Record<string, string> = {};
        const status = overrideStatus ?? activeTab;
        if (status !== 'all') {
            params.status = status;
        }
        const typesParam = multiSelectToParam(selectedTypes);
        if (typesParam) {
            params.type = typesParam;
        }
        const userIdsParam = multiSelectToParam(selectedUserIds);
        if (userIdsParam) {
            params.user_id = userIdsParam;
        }
        const campaignsParam = multiSelectToParam(selectedCampaigns);
        if (campaignsParam) {
            params.campaign_department = campaignsParam;
        }
        if (filterPeriod !== 'all') {
            params.period = filterPeriod;
        }
        return params;
    }, [activeTab, selectedTypes, selectedUserIds, selectedCampaigns, filterPeriod]);

    const requestWithFilters = (params: Record<string, string>) => {
        router.get(leaveIndexRoute().url, params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: () => setLastRefresh(new Date()),
        });
    };

    const handleFilter = () => {
        requestWithFilters(buildFilterParams());
    };

    const handleManualRefresh = () => {
        requestWithFilters(buildFilterParams());
    };

    // Auto-refresh every 30 seconds
    const isPollingRef = useRef(false);
    useEffect(() => {
        if (!autoRefreshEnabled) return;
        const interval = setInterval(() => {
            if (isPollingRef.current) return;
            isPollingRef.current = true;
            router.get(leaveIndexRoute().url, buildFilterParams(), {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['leaveRequests', 'hasPendingRequests'],
                onSuccess: () => setLastRefresh(new Date()),
                onFinish: () => { isPollingRef.current = false; },
            });
        }, 30000);

        return () => clearInterval(interval);
    }, [autoRefreshEnabled, buildFilterParams]);

    const handleTabChange = (tab: StatusTab) => {
        setActiveTab(tab);
        requestWithFilters(buildFilterParams(tab));
    };

    const clearFilters = () => {
        setActiveTab('all');
        setSelectedTypes([]);
        setSelectedUserIds([]);
        setSelectedCampaigns([]);
        setFilterPeriod('all');
        requestWithFilters({});
    };

    const handleCancelRequest = (id: number) => {
        setSelectedLeaveId(id);
        cancelForm.reset();
        setShowCancelDialog(true);
    };

    const handleDeleteRequest = (id: number) => {
        setSelectedLeaveId(id);
        setShowDeleteDialog(true);
    };

    const confirmCancel = () => {
        if (!selectedLeaveId) return;

        cancelForm.post(
            leaveCancelRoute(selectedLeaveId).url,
            {
                onSuccess: () => {
                    toast.success('Leave request cancelled successfully');
                    setShowCancelDialog(false);
                    setSelectedLeaveId(null);
                    cancelForm.reset();
                },
                onError: (errors) => {
                    if (errors.error) {
                        toast.error(errors.error);
                    }
                }
            }
        );
    };

    const confirmDelete = () => {
        if (!selectedLeaveId) return;

        router.delete(
            leaveDestroyRoute(selectedLeaveId).url,
            {
                onSuccess: () => {
                    toast.success('Leave request deleted successfully');
                    setShowDeleteDialog(false);
                    setSelectedLeaveId(null);
                },
                onError: () => {
                    toast.error('Failed to delete leave request');
                }
            }
        );
    };

    const getStatusBadge = (
        status: string,
        adminApprovedAt: string | null,
        hrApprovedAt: string | null,
        requiresTlApproval: boolean = false,
        tlApprovedAt: string | null = null,
        tlRejected: boolean = false
    ) => {
        const isAdminApproved = !!adminApprovedAt;
        const isHrApproved = !!hrApprovedAt;
        const isTlApproved = !!tlApprovedAt;

        // For pending status, show partial approval info
        if (status === 'pending') {
            // Check TL approval first for agent requests
            if (requiresTlApproval && !isTlApproved && !tlRejected) {
                return (
                    <Badge variant="outline" className="bg-orange-100 text-orange-800 border-orange-300">
                        PENDING TL
                    </Badge>
                );
            }

            if (requiresTlApproval && isTlApproved) {
                // TL approved, now waiting for Admin/HR
                if (isAdminApproved && !isHrApproved) {
                    return (
                        <div className="flex flex-col gap-1">
                            <Badge variant="outline" className="bg-yellow-100 text-yellow-800 border-yellow-300">
                                PENDING HR
                            </Badge>
                            <span className="text-xs text-green-600 flex items-center gap-1">
                                <CheckCircle className="h-3 w-3" /> TL & Admin ✓
                            </span>
                        </div>
                    );
                }
                if (!isAdminApproved && isHrApproved) {
                    return (
                        <div className="flex flex-col gap-1">
                            <Badge variant="outline" className="bg-yellow-100 text-yellow-800 border-yellow-300">
                                PENDING ADMIN
                            </Badge>
                            <span className="text-xs text-green-600 flex items-center gap-1">
                                <CheckCircle className="h-3 w-3" /> TL & HR ✓
                            </span>
                        </div>
                    );
                }
                if (!isAdminApproved && !isHrApproved) {
                    return (
                        <div className="flex flex-col gap-1">
                            <Badge variant="outline" className="bg-yellow-100 text-yellow-800 border-yellow-300">
                                PENDING ADMIN/HR
                            </Badge>
                            <span className="text-xs text-green-600 flex items-center gap-1">
                                <CheckCircle className="h-3 w-3" /> TL ✓
                            </span>
                        </div>
                    );
                }
            }

            // Non-TL required or regular flow
            if (isAdminApproved && !isHrApproved) {
                return (
                    <div className="flex flex-col gap-1">
                        <Badge variant="outline" className="bg-yellow-100 text-yellow-800 border-yellow-300">
                            PENDING HR
                        </Badge>
                        <span className="text-xs text-green-600 flex items-center gap-1">
                            <CheckCircle className="h-3 w-3" /> Admin ✓
                        </span>
                    </div>
                );
            }
            if (!isAdminApproved && isHrApproved) {
                return (
                    <div className="flex flex-col gap-1">
                        <Badge variant="outline" className="bg-yellow-100 text-yellow-800 border-yellow-300">
                            PENDING ADMIN
                        </Badge>
                        <span className="text-xs text-green-600 flex items-center gap-1">
                            <CheckCircle className="h-3 w-3" /> HR ✓
                        </span>
                    </div>
                );
            }
            return (
                <Badge variant="outline" className="bg-yellow-100 text-yellow-800 border-yellow-300">
                    PENDING
                </Badge>
            );
        }

        const variants = {
            pending: 'bg-yellow-100 text-yellow-800 border-yellow-300',
            approved: 'bg-green-100 text-green-800 border-green-300',
            denied: 'bg-red-100 text-red-800 border-red-300',
            cancelled: 'bg-gray-100 text-gray-800 border-gray-300',
        };
        return (
            <Badge variant="outline" className={variants[status as keyof typeof variants]}>
                {status.toUpperCase()}
            </Badge>
        );
    };

    const getLeaveTypeBadge = (type: string) => {
        return <Badge variant="secondary">{type}</Badge>;
    };

    // Allow creating new leave requests even with pending requests
    // Backend now allows multiple pending requests as long as they're not duplicates

    const handleExportCredits = async () => {
        if (!exportYear || exportYear < 2020 || exportYear > new Date().getFullYear() + 1) {
            toast.error('Invalid year', {
                description: 'Please enter a valid year between 2020 and ' + (new Date().getFullYear() + 1),
            });
            return;
        }

        setIsExporting(true);
        setExportProgress({ percent: 0, status: 'Starting export...' });

        try {
            // Start export job
            const response = await fetch('/form-requests/leave-requests/credits/export', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ year: exportYear }),
            });

            if (!response.ok) {
                throw new Error('Failed to start export');
            }

            const { job_id } = await response.json();

            // Poll for progress
            const pollInterval = setInterval(async () => {
                try {
                    const progressResponse = await fetch(`/form-requests/leave-requests/credits/export/progress?job_id=${job_id}`);
                    if (!progressResponse.ok) {
                        throw new Error('Failed to fetch progress');
                    }

                    const progress = await progressResponse.json();
                    setExportProgress({ percent: progress.percent, status: progress.status });

                    if (progress.finished) {
                        clearInterval(pollInterval);
                        setIsExporting(false);
                        setShowExportDialog(false);

                        if (progress.downloadUrl) {
                            toast.success('Export completed!', {
                                description: 'Your file is ready. Download will start automatically.',
                            });
                            window.location.href = progress.downloadUrl;
                        }
                    }

                    if (progress.error) {
                        clearInterval(pollInterval);
                        setIsExporting(false);
                        toast.error('Export failed', {
                            description: progress.status,
                        });
                    }
                } catch {
                    clearInterval(pollInterval);
                    setIsExporting(false);
                    toast.error('Error checking export progress');
                }
            }, 2000);

            // Timeout after 5 minutes
            setTimeout(() => {
                clearInterval(pollInterval);
                setIsExporting(false);
                toast.error('Export timeout', {
                    description: 'The export is taking longer than expected. Please try again.',
                });
            }, 300000);
        } catch {
            setIsExporting(false);
            toast.error('Failed to export leave credits', {
                description: 'An error occurred while starting the export.',
            });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                <LoadingOverlay isLoading={isPageLoading} />

                <PageHeader
                    title="Leave Requests"
                    description={isAdmin ? 'Manage all leave requests' : 'View your leave requests'}
                />

                <div className="flex flex-col gap-4">
                    <div className="flex flex-col sm:flex-row gap-4 justify-between items-start sm:items-center">
                        <div className="w-full sm:w-auto flex-1 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">

                            {showEmployeeColumn && (
                                <MultiSelectFilter
                                    options={allEmployees.map((emp) => ({ label: emp.name, value: String(emp.id) }))}
                                    value={selectedUserIds}
                                    onChange={setSelectedUserIds}
                                    placeholder="All Employees"
                                    emptyMessage="No employee found."
                                    multipleSelectionLabel={(n) => `${n} employees selected`}
                                />
                            )}

                            <MultiSelectFilter
                                options={[
                                    { label: 'Vacation Leave', value: 'VL' },
                                    { label: 'Sick Leave', value: 'SL' },
                                    { label: 'Bereavement Leave', value: 'BL' },
                                    { label: 'Solo Parent Leave', value: 'SPL' },
                                    { label: 'Leave of Absence', value: 'LOA' },
                                    { label: 'Leave due to Domestic Violence', value: 'LDV' },
                                    { label: 'Unpaid Time Off', value: 'UPTO' },
                                    { label: 'Maternity Leave', value: 'ML' },
                                    { label: 'Incomplete Workday', value: 'IW' },
                                ]}
                                value={selectedTypes}
                                onChange={setSelectedTypes}
                                placeholder="All Types"
                                emptyMessage="No type found."
                                multipleSelectionLabel={(n) => `${n} types selected`}
                            />

                            <MultiSelectFilter
                                options={(teamLeadCampaignNames?.length ? campaigns.filter(c => teamLeadCampaignNames.includes(c)) : campaigns).map((c) => ({ label: c, value: c }))}
                                value={selectedCampaigns}
                                onChange={setSelectedCampaigns}
                                placeholder="All Campaigns"
                                emptyMessage="No campaign found."
                                multipleSelectionLabel={(n) => `${n} campaigns selected`}
                            />

                            <Select value={filterPeriod} onValueChange={setFilterPeriod}>
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="All Periods" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Periods</SelectItem>
                                    <SelectItem value="upcoming">Upcoming</SelectItem>
                                    <SelectItem value="this_week">This Week</SelectItem>
                                    <SelectItem value="this_month">This Month</SelectItem>
                                    <SelectItem value="past">Past</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="flex flex-wrap gap-2 w-full sm:w-auto">
                            <Button variant="outline" onClick={handleFilter} className="flex-1 sm:flex-none">
                                <Filter className="mr-2 h-4 w-4" />
                                Filter
                            </Button>

                            {showClearFilters && (
                                <Button variant="outline" onClick={clearFilters} className="flex-1 sm:flex-none">
                                    Reset
                                </Button>
                            )}

                            <div className="flex gap-2">
                                {isAdmin && (
                                    <Button
                                        variant="outline"
                                        onClick={() => setShowExportDialog(true)}
                                        className="flex-1 sm:flex-none"
                                        title="Export Leave Credits"
                                    >
                                        <Download className="mr-2 h-4 w-4" />
                                        Export Credits
                                    </Button>
                                )}

                                <Link href="/form-requests/leave-requests/calendar">
                                    <Button
                                        variant="outline"
                                        className="flex-1 sm:flex-none"
                                        title="View Leave Calendar"
                                    >
                                        <Calendar className="mr-2 h-4 w-4" />
                                        Calendar
                                    </Button>
                                </Link>

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

                            <Can permission="leave.create">
                                <Link href={leaveCreateRoute().url} className="flex-1 sm:flex-none">
                                    <Button className="w-full sm:w-auto">
                                        <Plus className="mr-2 h-4 w-4" />
                                        Request Leave
                                    </Button>
                                </Link>
                            </Can>
                        </div>
                    </div>

                    {/* Status Tabs */}
                    <div className="flex overflow-x-auto gap-1 rounded-lg border bg-muted/30 p-1">
                        {statusTabs.map(({ key, label, icon: Icon }) => (
                            <button
                                key={key}
                                onClick={() => handleTabChange(key)}
                                className={`flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium whitespace-nowrap shrink-0 transition-colors ${activeTab === key
                                    ? 'bg-background text-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground'
                                    }`}
                            >
                                <Icon className="h-3.5 w-3.5" />
                                <span className="hidden sm:inline">{label}</span>
                                <span className={`ml-0.5 rounded-full px-1.5 py-0.5 text-[10px] font-semibold ${key === 'upcoming'
                                    ? 'bg-red-500 text-white'
                                    : activeTab === key
                                        ? 'bg-primary/10 text-primary'
                                        : 'bg-muted text-muted-foreground'
                                    }`}>
                                    {statusCounts[key]}
                                </span>
                            </button>
                        ))}
                    </div>

                    <div className="flex justify-between items-center text-sm">
                        <div className="text-muted-foreground">
                            Showing {leaveRequests.data.length} of {paginationMeta.total} leave request{paginationMeta.total === 1 ? '' : 's'}
                            {showClearFilters && ' (filtered)'}
                        </div>
                        <div className="text-xs text-muted-foreground">Last updated: {lastRefresh.toLocaleTimeString()}</div>
                    </div>
                </div>

                {/* Desktop Table View */}
                <div className="hidden md:block shadow rounded-md overflow-hidden bg-card">
                    {isPageLoading ? (
                        <TableSkeleton columns={8} rows={8} />
                    ) : (
                        <>
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow className="bg-muted/50">
                                            {showEmployeeColumn && <TableHead>Employee</TableHead>}
                                            {showEmployeeColumn && <TableHead>Campaign</TableHead>}
                                            <TableHead>Type</TableHead>
                                            <TableHead>Period</TableHead>
                                            <TableHead>Days</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Submitted</TableHead>
                                            <TableHead className="text-right">Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {leaveRequests.data.length === 0 ? (
                                            <TableRow>
                                                <TableCell
                                                    colSpan={showEmployeeColumn ? 8 : 6}
                                                    className="text-center py-8 text-muted-foreground"
                                                >
                                                    No leave requests found
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            leaveRequests.data.map((request) => (
                                                <TableRow key={request.id}>
                                                    {showEmployeeColumn && (
                                                        <TableCell className="font-medium">
                                                            {request.user.name}
                                                        </TableCell>
                                                    )}
                                                    {showEmployeeColumn && (
                                                        <TableCell className="text-sm">
                                                            {request.campaign_department}
                                                        </TableCell>
                                                    )}
                                                    <TableCell>{getLeaveTypeBadge(request.leave_type)}</TableCell>
                                                    <TableCell className="whitespace-nowrap">
                                                        {(() => {
                                                            const start = parseISO(request.start_date);
                                                            const end = parseISO(request.end_date);
                                                            return getYear(start) === getYear(end)
                                                                ? `${format(start, 'MMM dd')} - ${format(end, 'MMM dd, yyyy')}`
                                                                : `${format(start, 'MMM dd, yyyy')} - ${format(end, 'MMM dd, yyyy')}`;
                                                        })()}
                                                    </TableCell>
                                                    <TableCell>
                                                        {request.has_partial_denial && request.approved_days !== null ? (
                                                            <span className="text-orange-600" title={`${request.approved_days} of ${Math.floor(request.days_requested)} days approved`}>
                                                                {Math.floor(request.approved_days)} {Math.floor(request.approved_days) === 1 ? 'day' : 'days'}
                                                                <span className="text-xs text-muted-foreground ml-1">(partial)</span>
                                                            </span>
                                                        ) : (
                                                            <>{Math.floor(request.days_requested)} {Math.floor(request.days_requested) === 1 ? 'day' : 'days'}</>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>{getStatusBadge(request.status, request.admin_approved_at, request.hr_approved_at, request.requires_tl_approval, request.tl_approved_at, request.tl_rejected)}</TableCell>
                                                    <TableCell className="text-sm text-muted-foreground">
                                                        {format(parseISO(request.created_at), 'MMM d, yyyy')}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <div className="flex justify-end gap-2">
                                                            <Link href={leaveShowRoute(request.id).url}>
                                                                <Button size="icon" variant="outline" title="View Details">
                                                                    <Eye className="h-4 w-4" />
                                                                </Button>
                                                            </Link>
                                                            {/* Medical/Supporting Document Button - For SL, BL, UPTO, and IW with uploaded document */}
                                                            {(request.leave_type === 'SL' || request.leave_type === 'BL' || request.leave_type === 'UPTO' || request.leave_type === 'IW') && ((request.documents_count ?? 0) > 0 || request.medical_cert_path) && (auth.user.id === request.user.id || isAdmin) && (
                                                                <Link href={leaveShowRoute(request.id).url}>
                                                                    <Button
                                                                        size="icon"
                                                                        variant="outline"
                                                                        title={`View ${request.leave_type === 'SL' ? 'Medical Certificate' : request.leave_type === 'BL' ? 'Death Certificate' : 'Supporting Document'}`}
                                                                        className="text-green-600 hover:text-green-700 border-green-300"
                                                                    >
                                                                        <FileImage className="h-4 w-4" />
                                                                    </Button>
                                                                </Link>
                                                            )}
                                                            {request.status === 'pending' && (auth.user.id === request.user.id || can('leave.edit')) && (
                                                                <Link href={leaveEditRoute({ leaveRequest: request.id }).url}>
                                                                    <Button size="icon" variant="outline" title="Edit Request">
                                                                        <Pencil className="h-4 w-4" />
                                                                    </Button>
                                                                </Link>
                                                            )}
                                                            {(request.status === 'pending' || (request.status === 'approved' && (request.has_partial_denial || new Date(request.start_date + 'T00:00:00') > new Date()))) && auth.user.id === request.user.id && (
                                                                <Button
                                                                    size="icon"
                                                                    variant="outline"
                                                                    onClick={() => handleCancelRequest(request.id)}
                                                                    title="Cancel Request"
                                                                    className="text-orange-600 hover:text-orange-700 border-orange-300"
                                                                >
                                                                    <Ban className="h-4 w-4" />
                                                                </Button>
                                                            )}
                                                            {can('leave.cancel') && auth.user.id !== request.user.id && (request.status === 'pending' || (request.status === 'approved' && new Date(request.end_date + 'T23:59:59') >= new Date())) && (
                                                                <Button
                                                                    size="icon"
                                                                    variant="outline"
                                                                    onClick={() => handleCancelRequest(request.id)}
                                                                    title="Cancel Request"
                                                                    className="text-red-600 hover:text-red-700 border-red-300"
                                                                >
                                                                    <Ban className="h-4 w-4" />
                                                                </Button>
                                                            )}
                                                            {(can('leave.delete') || (auth.user.id === request.user.id && (request.status === 'cancelled' || request.status === 'denied'))) && (
                                                                <Button
                                                                    size="icon"
                                                                    variant="outline"
                                                                    onClick={() => handleDeleteRequest(request.id)}
                                                                    title="Delete Request"
                                                                    className="text-red-600 hover:text-red-700 border-red-300"
                                                                >
                                                                    <Trash2 className="h-4 w-4" />
                                                                </Button>
                                                            )}
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </div>

                            {paginationLinks.length > 0 && (
                                <div className="border-t px-4 py-3 flex justify-center">
                                    <PaginationNav links={paginationLinks} />
                                </div>
                            )}
                        </>
                    )}
                </div>

                {/* Mobile Card View */}
                <div className="md:hidden space-y-4">
                    {leaveRequests.data.length === 0 ? (
                        <div className="py-12 text-center text-muted-foreground border rounded-lg bg-card">
                            No leave requests found
                        </div>
                    ) : (
                        leaveRequests.data.map((request) => (
                            <div key={request.id} className="bg-card border rounded-lg p-4 shadow-sm space-y-3">
                                <div className="flex justify-between items-start">
                                    <div>
                                        {showEmployeeColumn && (
                                            <div className="text-lg font-semibold">{request.user.name}</div>
                                        )}
                                        <div className="flex items-center gap-2 mt-1">
                                            {getLeaveTypeBadge(request.leave_type)}
                                        </div>
                                    </div>
                                    {getStatusBadge(request.status, request.admin_approved_at, request.hr_approved_at, request.requires_tl_approval, request.tl_approved_at, request.tl_rejected)}
                                </div>

                                <div className="space-y-2 text-sm">
                                    {showEmployeeColumn && (
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">Campaign:</span>
                                            <span className="font-medium">{request.campaign_department}</span>
                                        </div>
                                    )}
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Period:</span>
                                        <span className="font-medium">
                                            {(() => {
                                                const start = parseISO(request.start_date);
                                                const end = parseISO(request.end_date);
                                                return getYear(start) === getYear(end)
                                                    ? `${format(start, 'MMM dd')} - ${format(end, 'MMM dd, yyyy')}`
                                                    : `${format(start, 'MMM dd, yyyy')} - ${format(end, 'MMM dd, yyyy')}`;
                                            })()}
                                        </span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">{request.has_partial_denial ? 'Days Approved:' : 'Days Requested:'}</span>
                                        {request.has_partial_denial && request.approved_days !== null ? (
                                            <span className="font-medium text-orange-600">
                                                {request.approved_days} of {Math.floor(request.days_requested)} {Math.floor(request.days_requested) === 1 ? 'day' : 'days'}
                                            </span>
                                        ) : (
                                            <span className="font-medium">{Math.floor(request.days_requested)} {Math.floor(request.days_requested) === 1 ? 'day' : 'days'}</span>
                                        )}
                                    </div>
                                    <div className="text-xs text-muted-foreground pt-1">
                                        Submitted: {format(parseISO(request.created_at), 'MMM d, yyyy')}
                                    </div>
                                </div>

                                <div className="flex flex-wrap gap-2 pt-2 border-t">
                                    <Link href={leaveShowRoute(request.id).url} className="flex-1">
                                        <Button size="sm" variant="outline" className="w-full">
                                            <Eye className="mr-2 h-4 w-4" />
                                            View
                                        </Button>
                                    </Link>
                                    {/* Medical/Supporting Document Button - Mobile */}
                                    {(request.leave_type === 'SL' || request.leave_type === 'BL' || request.leave_type === 'UPTO' || request.leave_type === 'IW') && ((request.documents_count ?? 0) > 0 || request.medical_cert_path) && (auth.user.id === request.user.id || isAdmin) && (
                                        <Link href={leaveShowRoute(request.id).url} className="flex-1">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                className="w-full"
                                            >
                                                <FileImage className="mr-2 h-4 w-4 text-green-600" />
                                                {request.leave_type === 'SL' ? 'Med Cert' : request.leave_type === 'BL' ? 'Death Cert' : 'Document'}
                                            </Button>
                                        </Link>
                                    )}
                                    {request.status === 'pending' && (auth.user.id === request.user.id || can('leave.edit')) && (
                                        <Link href={leaveEditRoute({ leaveRequest: request.id }).url} className="flex-1">
                                            <Button size="sm" variant="outline" className="w-full">
                                                <Pencil className="mr-2 h-4 w-4" />
                                                Edit
                                            </Button>
                                        </Link>
                                    )}
                                    {(request.status === 'pending' || (request.status === 'approved' && (request.has_partial_denial || new Date(request.start_date + 'T00:00:00') > new Date()))) && auth.user.id === request.user.id && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => handleCancelRequest(request.id)}
                                            className="flex-1 text-orange-600 hover:text-orange-700 border-orange-300"
                                        >
                                            <Ban className="mr-2 h-4 w-4" />
                                            Cancel
                                        </Button>
                                    )}
                                    {can('leave.cancel') && auth.user.id !== request.user.id && (request.status === 'pending' || (request.status === 'approved' && new Date(request.end_date + 'T23:59:59') >= new Date())) && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => handleCancelRequest(request.id)}
                                            className="flex-1 text-red-600 hover:text-red-700 border-red-300"
                                        >
                                            <Ban className="mr-2 h-4 w-4" />
                                            Cancel
                                        </Button>
                                    )}
                                    {(can('leave.delete') || (auth.user.id === request.user.id && (request.status === 'cancelled' || request.status === 'denied'))) && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => handleDeleteRequest(request.id)}
                                            className="flex-1 text-red-600 hover:text-red-700 border-red-300"
                                        >
                                            <Trash2 className="mr-2 h-4 w-4" />
                                            Delete
                                        </Button>
                                    )}
                                </div>
                            </div>
                        ))
                    )}

                    {paginationLinks.length > 0 && (
                        <div className="flex justify-center pt-4">
                            <PaginationNav links={paginationLinks} />
                        </div>
                    )}
                </div>
            </div>

            {/* Cancel Confirmation Dialog */}
            <Dialog open={showCancelDialog} onOpenChange={(open) => { setShowCancelDialog(open); if (!open) { setSelectedLeaveId(null); cancelForm.reset(); } }}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Cancel Leave Request</DialogTitle>
                        <DialogDescription>
                            Are you sure you want to cancel this leave request? This action cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-2">
                        <Label htmlFor="index-cancel-reason">Reason for cancellation <span className="text-red-500">*</span></Label>
                        <Textarea
                            id="index-cancel-reason"
                            placeholder="Please provide a reason for cancelling this leave request..."
                            value={cancelForm.data.cancellation_reason}
                            onChange={(e) => cancelForm.setData('cancellation_reason', e.target.value)}
                            rows={3}
                        />
                        {cancelForm.errors.cancellation_reason && (
                            <p className="text-sm text-red-500">{cancelForm.errors.cancellation_reason}</p>
                        )}
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => { setShowCancelDialog(false); setSelectedLeaveId(null); cancelForm.reset(); }}>
                            No, Keep It
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={confirmCancel}
                            disabled={cancelForm.processing || !cancelForm.data.cancellation_reason.trim()}
                        >
                            {cancelForm.processing ? 'Cancelling...' : 'Yes, Cancel Request'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Delete Confirmation Dialog */}
            <AlertDialog open={showDeleteDialog} onOpenChange={setShowDeleteDialog}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete Leave Request</AlertDialogTitle>
                        <AlertDialogDescription>
                            Are you sure you want to permanently delete this leave request? This action cannot be undone and will remove all associated data.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    {(() => {
                        const selectedRequest = selectedLeaveId ? leaveRequests.data.find(r => r.id === selectedLeaveId) : null;
                        if (selectedRequest && selectedRequest.status === 'approved') {
                            return (
                                <div className="rounded-md border border-amber-300 bg-amber-50 p-3 dark:border-amber-700 dark:bg-amber-950">
                                    <div className="flex items-start gap-2">
                                        <AlertTriangle className="h-5 w-5 text-amber-600 mt-0.5 flex-shrink-0" />
                                        <div className="text-sm text-amber-800 dark:text-amber-200">
                                            <p className="font-semibold">Leave credits will NOT be restored.</p>
                                            <p className="mt-1">Deleting does not restore deducted leave credits or rollback attendance records. If you need credits restored, cancel the leave request first, then delete it.</p>
                                        </div>
                                    </div>
                                </div>
                            );
                        }
                        return null;
                    })()}
                    <AlertDialogFooter>
                        <AlertDialogCancel onClick={() => setSelectedLeaveId(null)}>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={confirmDelete} className="bg-red-600 hover:bg-red-700">
                            Delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* Export Credits Dialog */}
            <Dialog open={showExportDialog} onOpenChange={setShowExportDialog}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Export Leave Credits Summary</DialogTitle>
                        <DialogDescription>
                            Export a summary of all employee leave credits for a specific year. This will generate an Excel file with total earned, used, and balance for each employee.
                        </DialogDescription>
                    </DialogHeader>

                    {!isExporting ? (
                        <div className="space-y-4 py-4">
                            <div className="space-y-2">
                                <Label htmlFor="exportYear">Year</Label>
                                <Input
                                    id="exportYear"
                                    type="number"
                                    min={2020}
                                    max={new Date().getFullYear() + 1}
                                    value={exportYear}
                                    onChange={(e) => setExportYear(parseInt(e.target.value))}
                                    placeholder="Enter year (e.g., 2024)"
                                />
                                <p className="text-xs text-muted-foreground">
                                    Default is last year ({new Date().getFullYear() - 1}). Credits reset annually and don't carry over.
                                </p>
                            </div>
                        </div>
                    ) : (
                        <div className="space-y-4 py-6">
                            <div className="space-y-2">
                                <div className="flex justify-between text-sm">
                                    <span className="text-muted-foreground">Progress</span>
                                    <span className="font-medium">{exportProgress.percent}%</span>
                                </div>
                                <Progress value={exportProgress.percent} className="h-2" />
                                <p className="text-sm text-muted-foreground">{exportProgress.status}</p>
                            </div>
                        </div>
                    )}

                    <DialogFooter>
                        {!isExporting ? (
                            <>
                                <Button variant="outline" onClick={() => setShowExportDialog(false)}>
                                    Cancel
                                </Button>
                                <Button onClick={handleExportCredits}>
                                    <Download className="mr-2 h-4 w-4" />
                                    Export
                                </Button>
                            </>
                        ) : (
                            <Button variant="outline" disabled>
                                Exporting...
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
