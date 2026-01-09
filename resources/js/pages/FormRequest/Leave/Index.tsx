import React, { useState, useEffect } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { format, parseISO } from 'date-fns';
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
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Plus, Eye, Ban, RefreshCw, Filter, Trash2, Pencil, CheckCircle, Play, Pause, Download, ChevronsUpDown, Check, Calendar, FileImage } from 'lucide-react';
import { toast } from 'sonner';
import { useFlashMessage, usePageLoading, usePageMeta } from '@/hooks';
import { usePermission } from '@/hooks/use-permission';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import PaginationNav from '@/components/pagination-nav';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { index as leaveIndexRoute, create as leaveCreateRoute, show as leaveShowRoute, cancel as leaveCancelRoute, destroy as leaveDestroyRoute, edit as leaveEditRoute, medicalCert as leaveMedicalCertRoute } from '@/routes/leave-requests';

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

interface Props {
    leaveRequests: {
        data: LeaveRequest[];
        links?: PaginationLink[];
        meta?: PaginationMeta;
    };
    filters: {
        status?: string;
        type?: string;
        start_date?: string;
        end_date?: string;
        employee_name?: string;
        campaign_department?: string;
    };
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
}

export default function Index({ leaveRequests, filters, isAdmin, isTeamLead, auth, campaigns = [], allEmployees = [] }: Props) {
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

    const [filterStatus, setFilterStatus] = useState(filters.status || 'all');
    const [filterType, setFilterType] = useState(filters.type || 'all');
    const [filterEmployeeName, setFilterEmployeeName] = useState(filters.employee_name || '');
    const [filterCampaign, setFilterCampaign] = useState(filters.campaign_department || 'all');
    const [employeeSearchQuery, setEmployeeSearchQuery] = useState('');
    const [showCancelDialog, setShowCancelDialog] = useState(false);
    const [showDeleteDialog, setShowDeleteDialog] = useState(false);
    const [selectedLeaveId, setSelectedLeaveId] = useState<number | null>(null);
    const [lastRefresh, setLastRefresh] = useState<Date>(new Date());
    const [autoRefreshEnabled, setAutoRefreshEnabled] = useState(false);
    const [showEmployeeSearch, setShowEmployeeSearch] = useState(false);

    // Medical Certificate dialog state
    const [showMedicalCertDialog, setShowMedicalCertDialog] = useState(false);
    const [selectedMedicalCertLeaveId, setSelectedMedicalCertLeaveId] = useState<number | null>(null);
    const [selectedMedicalCertUserName, setSelectedMedicalCertUserName] = useState<string>('');

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

    // Handle opening medical certificate dialog
    const handleViewMedicalCert = (leaveId: number, userName: string) => {
        setSelectedMedicalCertLeaveId(leaveId);
        setSelectedMedicalCertUserName(userName);
        setShowMedicalCertDialog(true);
    };

    // Filter employees based on search query (from all employees list)
    const filteredEmployees = React.useMemo(() => {
        if (!employeeSearchQuery) return allEmployees.slice(0, 50);
        return allEmployees.filter(emp =>
            emp.name.toLowerCase().includes(employeeSearchQuery.toLowerCase())
        ).slice(0, 50);
    }, [allEmployees, employeeSearchQuery]);

    const showClearFilters = filterStatus !== 'all' || filterType !== 'all' || filterEmployeeName !== '' || (filterCampaign && filterCampaign !== 'all');

    // Clear employee search query when popover closes
    useEffect(() => {
        if (!showEmployeeSearch) {
            setEmployeeSearchQuery('');
        }
    }, [showEmployeeSearch]);

    const buildFilterParams = React.useCallback(() => {
        const params: Record<string, string> = {};
        if (filterStatus !== 'all') {
            params.status = filterStatus;
        }
        if (filterType !== 'all') {
            params.type = filterType;
        }
        if (filterEmployeeName) {
            params.employee_name = filterEmployeeName;
        }
        if (filterCampaign && filterCampaign !== 'all') {
            params.campaign_department = filterCampaign;
        }
        return params;
    }, [filterStatus, filterType, filterEmployeeName, filterCampaign]);

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
    useEffect(() => {
        if (!autoRefreshEnabled) return;
        const interval = setInterval(() => {
            router.get(leaveIndexRoute().url, buildFilterParams(), {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['leaveRequests', 'hasPendingRequests'],
                onSuccess: () => setLastRefresh(new Date()),
            });
        }, 30000);

        return () => clearInterval(interval);
    }, [autoRefreshEnabled, buildFilterParams]);

    const clearFilters = () => {
        setFilterStatus('all');
        setFilterType('all');
        setFilterEmployeeName('');
        setFilterCampaign('all');
        requestWithFilters({});
    };

    const handleCancelRequest = (id: number) => {
        setSelectedLeaveId(id);
        setShowCancelDialog(true);
    };

    const handleDeleteRequest = (id: number) => {
        setSelectedLeaveId(id);
        setShowDeleteDialog(true);
    };

    const confirmCancel = () => {
        if (!selectedLeaveId) return;

        router.post(
            leaveCancelRoute(selectedLeaveId).url,
            {},
            {
                onSuccess: () => {
                    toast.success('Leave request cancelled successfully');
                    setShowCancelDialog(false);
                    setSelectedLeaveId(null);
                },
                onError: () => {
                    toast.error('Failed to cancel leave request');
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
            const response = await fetch('/form-requests/leave-requests/export/credits', {
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
                    const progressResponse = await fetch(`/form-requests/leave-requests/export/credits/progress?job_id=${job_id}`);
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
                                <Popover open={showEmployeeSearch} onOpenChange={setShowEmployeeSearch}>
                                    <PopoverTrigger asChild>
                                        <Button
                                            variant="outline"
                                            role="combobox"
                                            aria-expanded={showEmployeeSearch}
                                            className="w-full justify-between font-normal"
                                        >
                                            <span className="truncate">
                                                {filterEmployeeName || "All Employees"}
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
                                                            setFilterEmployeeName('');
                                                            setShowEmployeeSearch(false);
                                                        }}
                                                        className="cursor-pointer"
                                                    >
                                                        <Check
                                                            className={`mr-2 h-4 w-4 ${!filterEmployeeName ? "opacity-100" : "opacity-0"}`}
                                                        />
                                                        All Employees
                                                    </CommandItem>
                                                    {filteredEmployees.map((employee) => (
                                                        <CommandItem
                                                            key={employee.id}
                                                            value={employee.name}
                                                            onSelect={() => {
                                                                setFilterEmployeeName(employee.name);
                                                                setShowEmployeeSearch(false);
                                                            }}
                                                            className="cursor-pointer"
                                                        >
                                                            <Check
                                                                className={`mr-2 h-4 w-4 ${filterEmployeeName === employee.name
                                                                    ? "opacity-100"
                                                                    : "opacity-0"
                                                                    }`}
                                                            />
                                                            {employee.name}
                                                        </CommandItem>
                                                    ))}
                                                </CommandGroup>
                                            </CommandList>
                                        </Command>
                                    </PopoverContent>
                                </Popover>
                            )}

                            <Select value={filterStatus} onValueChange={setFilterStatus}>
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="All Statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Statuses</SelectItem>
                                    <SelectItem value="pending">Pending</SelectItem>
                                    <SelectItem value="approved">Approved</SelectItem>
                                    <SelectItem value="denied">Denied</SelectItem>
                                    <SelectItem value="cancelled">Cancelled</SelectItem>
                                </SelectContent>
                            </Select>

                            <Select value={filterType} onValueChange={setFilterType}>
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="All Types" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Types</SelectItem>
                                    <SelectItem value="VL">Vacation Leave</SelectItem>
                                    <SelectItem value="SL">Sick Leave</SelectItem>
                                    <SelectItem value="BL">Bereavement Leave</SelectItem>
                                    <SelectItem value="SPL">Solo Parent Leave</SelectItem>
                                    <SelectItem value="LOA">Leave of Absence</SelectItem>
                                    <SelectItem value="LDV">Leave due to Domestic Violence</SelectItem>
                                    <SelectItem value="UPTO">Unpaid Time Off</SelectItem>
                                    <SelectItem value="ML">Maternity Leave</SelectItem>
                                </SelectContent>
                            </Select>

                            <Select value={filterCampaign} onValueChange={setFilterCampaign}>
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="All Campaigns" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Campaigns</SelectItem>
                                    {campaigns.map((c) => (
                                        <SelectItem key={c} value={c}>{c}</SelectItem>
                                    ))}
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
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    {showEmployeeColumn && <TableHead>Employee</TableHead>}
                                    {showEmployeeColumn && <TableHead>Campaign</TableHead>}
                                    <TableHead>Type</TableHead>
                                    <TableHead>Start Date</TableHead>
                                    <TableHead>End Date</TableHead>
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
                                            colSpan={showEmployeeColumn ? 9 : 7}
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
                                            <TableCell>
                                                {format(parseISO(request.start_date), 'MMM d, yyyy')}
                                            </TableCell>
                                            <TableCell>
                                                {format(parseISO(request.end_date), 'MMM d, yyyy')}
                                            </TableCell>
                                            <TableCell>{Math.floor(request.days_requested)} {Math.floor(request.days_requested) === 1 ? 'day' : 'days'}</TableCell>
                                            <TableCell>{getStatusBadge(request.status, request.admin_approved_at, request.hr_approved_at, request.requires_tl_approval, request.tl_approved_at, request.tl_rejected)}</TableCell>
                                            <TableCell className="text-sm text-muted-foreground">
                                                {format(parseISO(request.created_at), 'MMM d, yyyy')}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <div className="flex justify-end gap-2">
                                                    <Link href={leaveShowRoute(request.id).url}>
                                                        <Button size="sm" variant="ghost">
                                                            <Eye className="h-4 w-4" />
                                                        </Button>
                                                    </Link>
                                                    {/* Medical/Supporting Document Button - For SL and BL with uploaded cert */}
                                                    {(request.leave_type === 'SL' || request.leave_type === 'BL') && request.medical_cert_path && isAdmin && (
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={() => handleViewMedicalCert(request.id, request.user.name)}
                                                            title={`View ${request.leave_type === 'SL' ? 'Medical Certificate' : 'Supporting Document'}`}
                                                        >
                                                            <FileImage className="h-4 w-4 text-green-600" />
                                                        </Button>
                                                    )}
                                                    {request.status === 'pending' && (auth.user.id === request.user.id || can('leave.edit')) && (
                                                        <Link href={leaveEditRoute({ leaveRequest: request.id }).url}>
                                                            <Button size="sm" variant="ghost">
                                                                <Pencil className="h-4 w-4" />
                                                            </Button>
                                                        </Link>
                                                    )}
                                                    {request.status === 'pending' && auth.user.id === request.user.id && (
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={() => handleCancelRequest(request.id)}
                                                        >
                                                            <Ban className="h-4 w-4 text-red-500" />
                                                        </Button>
                                                    )}
                                                    {can('leave.delete') && (
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={() => handleDeleteRequest(request.id)}
                                                        >
                                                            <Trash2 className="h-4 w-4 text-red-500" />
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
                                        <span className="text-muted-foreground">Start Date:</span>
                                        <span className="font-medium">{format(parseISO(request.start_date), 'MMM d, yyyy')}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">End Date:</span>
                                        <span className="font-medium">{format(parseISO(request.end_date), 'MMM d, yyyy')}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Days Requested:</span>
                                        <span className="font-medium">{Math.floor(request.days_requested)} {Math.floor(request.days_requested) === 1 ? 'day' : 'days'}</span>
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
                                    {(request.leave_type === 'SL' || request.leave_type === 'BL') && request.medical_cert_path && isAdmin && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => handleViewMedicalCert(request.id, request.user.name)}
                                            className="flex-1"
                                        >
                                            <FileImage className="mr-2 h-4 w-4 text-green-600" />
                                            {request.leave_type === 'SL' ? 'Med Cert' : 'Document'}
                                        </Button>
                                    )}
                                    {request.status === 'pending' && (auth.user.id === request.user.id || can('leave.edit')) && (
                                        <Link href={leaveEditRoute({ leaveRequest: request.id }).url} className="flex-1">
                                            <Button size="sm" variant="outline" className="w-full">
                                                <Pencil className="mr-2 h-4 w-4" />
                                                Edit
                                            </Button>
                                        </Link>
                                    )}
                                    {request.status === 'pending' && auth.user.id === request.user.id && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => handleCancelRequest(request.id)}
                                            className="flex-1"
                                        >
                                            <Ban className="mr-2 h-4 w-4 text-red-500" />
                                            Cancel
                                        </Button>
                                    )}
                                    {can('leave.delete') && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => handleDeleteRequest(request.id)}
                                            className="flex-1"
                                        >
                                            <Trash2 className="mr-2 h-4 w-4 text-red-500" />
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
            <AlertDialog open={showCancelDialog} onOpenChange={setShowCancelDialog}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Cancel Leave Request</AlertDialogTitle>
                        <AlertDialogDescription>
                            Are you sure you want to cancel this leave request? This action cannot be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel onClick={() => setSelectedLeaveId(null)}>No, Keep It</AlertDialogCancel>
                        <AlertDialogAction onClick={confirmCancel} className="bg-red-600 hover:bg-red-700">
                            Yes, Cancel Request
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* Delete Confirmation Dialog */}
            <AlertDialog open={showDeleteDialog} onOpenChange={setShowDeleteDialog}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete Leave Request</AlertDialogTitle>
                        <AlertDialogDescription>
                            Are you sure you want to permanently delete this leave request? This action cannot be undone and will remove all associated data.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
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

            {/* Medical Certificate Dialog */}
            <Dialog open={showMedicalCertDialog} onOpenChange={setShowMedicalCertDialog}>
                <DialogContent className="max-w-[90vw] sm:max-w-2xl max-h-[90vh] overflow-auto">
                    <DialogHeader>
                        <DialogTitle>
                            {selectedMedicalCertLeaveId && leaveRequests.data.find(r => r.id === selectedMedicalCertLeaveId)?.leave_type === 'SL'
                                ? 'Medical Certificate'
                                : 'Supporting Document'}
                        </DialogTitle>
                        <DialogDescription>
                            {selectedMedicalCertLeaveId && leaveRequests.data.find(r => r.id === selectedMedicalCertLeaveId)?.leave_type === 'SL'
                                ? 'Medical certificate'
                                : 'Supporting document'} submitted by {selectedMedicalCertUserName}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="flex justify-center items-center p-4 bg-muted/30 rounded-lg">
                        {selectedMedicalCertLeaveId && (
                            <img
                                src={leaveMedicalCertRoute(selectedMedicalCertLeaveId).url}
                                alt={leaveRequests.data.find(r => r.id === selectedMedicalCertLeaveId)?.leave_type === 'SL'
                                    ? 'Medical Certificate'
                                    : 'Supporting Document'}
                                className="max-w-full max-h-[60vh] object-contain rounded-lg shadow-lg"
                            />
                        )}
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setShowMedicalCertDialog(false)}>
                            Close
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
