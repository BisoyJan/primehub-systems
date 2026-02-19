import React, { useState, useCallback, useMemo, useEffect } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
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
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { Separator } from '@/components/ui/separator';
import { Download, Eye, Filter, ChevronsUpDown, Check, Banknote, Clock, ArrowRight, Settings, RefreshCw, Play, Loader2, TrendingUp, AlertTriangle, ChevronDown, ChevronRight, Pencil, Info, ArrowRightLeft } from 'lucide-react';
import { toast } from 'sonner';
import { useFlashMessage, usePageLoading, usePageMeta } from '@/hooks';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import PaginationNav from '@/components/pagination-nav';
import { index as leaveIndexRoute } from '@/routes/leave-requests';
import { format } from 'date-fns';
import { Progress } from '@/components/ui/progress';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { creditsUpdateCarryover, processCashConversions, convertUserCarryover } from '@/actions/App/Http/Controllers/LeaveRequestController';

interface RegularizationStats {
    pending_count: number;
    total_eligible: number;
    already_processed: number;
    year: number;
}

interface CarryoverData {
    credits: number;
    to_year: number;
    is_processed: boolean;
    is_expired: boolean;
    cash_converted: boolean;
}

interface CarryoverReceivedData {
    id: number;
    credits: number;
    from_year: number;
    is_first_regularization: boolean;
    cash_converted: boolean;
}

interface PendingRegularizationCredits {
    from_year: number;
    to_year: number;
    credits: number;
    months_accrued: number;
}

interface RegularizationData {
    is_regularized: boolean;
    regularization_date: string | null;
    hire_year: number | null;
    days_until_regularization: number;
    has_first_regularization: boolean;
    pending_credits: PendingRegularizationCredits | null;
}

interface CreditData {
    id: number;
    name: string;
    email: string;
    role: string;
    hired_date: string;
    is_eligible: boolean;
    eligibility_date: string;
    monthly_rate: number;
    total_earned: number;
    total_used: number;
    balance: number;
    carryover: CarryoverData | null;
    carryover_received: CarryoverReceivedData | null;
    carryover_forward: CarryoverData | null;
    regularization: RegularizationData;
    pending_count: number;
    pending_credits: number;
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
    email: string;
}

interface Campaign {
    id: number;
    name: string;
}

interface Props {
    creditsData: {
        data: CreditData[];
        links?: PaginationLink[];
        meta?: PaginationMeta;
        total?: number;
    };
    allEmployees: Employee[];
    campaigns: Campaign[];
    teamLeadCampaignId?: number;
    filters: {
        year: number;
        search: string;
        role: string;
        eligibility: string;
        campaign_id: string;
    };
    availableYears: number[];
    canEdit: boolean;
}

interface MismatchRequest {
    id: number;
    leave_type: string;
    start_date: string;
    end_date: string;
    days_requested: number;
    credits_deducted: number;
    current_credits_year: number;
    correct_credits_year: number;
    filed_date: string;
}

interface MismatchUser {
    user_id: number;
    name: string;
    email: string;
    requests: MismatchRequest[];
    total_to_restore: number;
}

interface MismatchScanResult {
    total_requests: number;
    total_users: number;
    total_credits: number;
    users: MismatchUser[];
}

export default function Index({ creditsData, allEmployees, campaigns = [], teamLeadCampaignId, filters, availableYears, canEdit = false }: Props) {
    const { title, breadcrumbs } = usePageMeta({
        title: 'Leave Credits',
        breadcrumbs: [
            { title: 'Form Requests', href: '/form-requests' },
            { title: 'Leave Requests', href: leaveIndexRoute().url },
            { title: 'Leave Credits', href: '/form-requests/leave-requests/credits' },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    const [selectedEmployeeId, setSelectedEmployeeId] = useState(filters.search || '');
    const [yearFilter, setYearFilter] = useState(filters.year.toString());
    const [roleFilter, setRoleFilter] = useState(filters.role || 'all');
    const [eligibilityFilter, setEligibilityFilter] = useState(filters.eligibility || 'all');
    const [campaignFilter, setCampaignFilter] = useState(() => {
        if (filters.campaign_id) return filters.campaign_id;
        if (teamLeadCampaignId) return teamLeadCampaignId.toString();
        return 'all';
    });

    // Popover search state
    const [isEmployeePopoverOpen, setIsEmployeePopoverOpen] = useState(false);
    const [employeeSearchQuery, setEmployeeSearchQuery] = useState('');

    // Export dialog state
    const [showExportDialog, setShowExportDialog] = useState(false);
    const [exportYear, setExportYear] = useState(new Date().getFullYear());
    const [isExporting, setIsExporting] = useState(false);
    const [exportProgress, setExportProgress] = useState({ percent: 0, status: '' });

    // Management dialog state
    const [isManagementDialogOpen, setIsManagementDialogOpen] = useState(false);
    const [isLoadingStats, setIsLoadingStats] = useState(false);
    const [managementStats, setManagementStats] = useState<RegularizationStats | null>(null);
    const [isProcessing, setIsProcessing] = useState(false);
    const [confirmAction, setConfirmAction] = useState<string | null>(null);
    const [managementYear, setManagementYear] = useState(new Date().getFullYear());

    // Mismatch dialog state
    const [isMismatchDialogOpen, setIsMismatchDialogOpen] = useState(false);
    const [isScanningMismatch, setIsScanningMismatch] = useState(false);
    const [mismatchData, setMismatchData] = useState<MismatchScanResult | null>(null);
    const [isFixingMismatch, setIsFixingMismatch] = useState(false);
    const [confirmMismatchFix, setConfirmMismatchFix] = useState(false);
    const [expandedMismatchUsers, setExpandedMismatchUsers] = useState<Set<number>>(new Set());

    // Edit carryover dialog state
    const [isEditCarryoverOpen, setIsEditCarryoverOpen] = useState(false);
    const [editingEmployee, setEditingEmployee] = useState<CreditData | null>(null);
    const editCarryoverForm = useForm({
        carryover_credits: 0,
        year: new Date().getFullYear(),
        reason: '',
        notes: '',
    });

    const [pendingAcknowledged, setPendingAcknowledged] = useState(false);

    // Cash conversion state
    const [convertingEmployeeId, setConvertingEmployeeId] = useState<number | null>(null);
    const [convertingEmployeeName, setConvertingEmployeeName] = useState<string>('');
    const [isConverting, setIsConverting] = useState(false);

    const openEditCarryover = (employee: CreditData) => {
        if (!employee.carryover_received) return;
        setEditingEmployee(employee);
        editCarryoverForm.setData({
            carryover_credits: employee.carryover_received.credits,
            year: parseInt(yearFilter),
            reason: '',
            notes: '',
        });
        setPendingAcknowledged(false);
        setIsEditCarryoverOpen(true);
    };

    const submitEditCarryover = () => {
        if (!editingEmployee) return;
        editCarryoverForm.put(creditsUpdateCarryover({ user: editingEmployee.id }).url, {
            preserveScroll: true,
            onSuccess: () => {
                setIsEditCarryoverOpen(false);
                setEditingEmployee(null);
                editCarryoverForm.reset();
            },
        });
    };

    // Filter employees based on search query (from all employees list)
    const filteredEmployees = useMemo(() => {
        if (!employeeSearchQuery) return allEmployees.slice(0, 20);
        return allEmployees.filter(emp =>
            emp.name.toLowerCase().includes(employeeSearchQuery.toLowerCase()) ||
            emp.email.toLowerCase().includes(employeeSearchQuery.toLowerCase())
        ).slice(0, 20);
    }, [allEmployees, employeeSearchQuery]);

    // Get selected employee name
    const selectedEmployee = useMemo(() => {
        if (!selectedEmployeeId) return null;
        return allEmployees.find(emp => emp.id.toString() === selectedEmployeeId);
    }, [allEmployees, selectedEmployeeId]);

    const showClearFilters = selectedEmployeeId || roleFilter !== 'all' || eligibilityFilter !== 'all' || campaignFilter !== 'all' || yearFilter !== new Date().getFullYear().toString();

    // Clear employee search query when popover closes
    useEffect(() => {
        if (!isEmployeePopoverOpen) {
            setEmployeeSearchQuery('');
        }
    }, [isEmployeePopoverOpen]);

    const buildFilterParams = useCallback(() => {
        const params: Record<string, string> = {};
        if (selectedEmployeeId) params.search = selectedEmployeeId;
        if (yearFilter) params.year = yearFilter;
        if (roleFilter !== 'all') params.role = roleFilter;
        if (eligibilityFilter !== 'all') params.eligibility = eligibilityFilter;
        if (campaignFilter !== 'all') params.campaign_id = campaignFilter;
        return params;
    }, [selectedEmployeeId, yearFilter, roleFilter, eligibilityFilter, campaignFilter]);

    const applyFilters = useCallback(() => {
        router.get('/form-requests/leave-requests/credits', buildFilterParams(), {
            preserveState: true,
            preserveScroll: true,
        });
    }, [buildFilterParams]);

    const clearFilters = () => {
        setSelectedEmployeeId('');
        setYearFilter(new Date().getFullYear().toString());
        setRoleFilter('all');
        setEligibilityFilter('all');
        setCampaignFilter('all');
        router.get('/form-requests/leave-requests/credits', {}, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    // Export handlers
    const startExport = async () => {
        setIsExporting(true);
        setExportProgress({ percent: 0, status: 'Starting export...' });

        try {
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

            if (!response.ok) throw new Error('Failed to start export');

            const result = await response.json();

            // If the job completed synchronously, download immediately
            if (result.finished && result.downloadUrl) {
                window.location.href = result.downloadUrl;
                toast.success('Export completed! Download started.');
                setIsExporting(false);
                setShowExportDialog(false);
            } else {
                // Fall back to polling if needed (for async processing)
                pollExportProgress(result.job_id);
            }
        } catch {
            toast.error('Failed to start export');
            setIsExporting(false);
        }
    };

    const pollExportProgress = async (jobId: string) => {
        const checkProgress = async () => {
            try {
                const response = await fetch(`/form-requests/leave-requests/export/credits/progress?job_id=${jobId}`);
                const progress = await response.json();

                setExportProgress({ percent: progress.percent, status: progress.status });

                if (progress.finished) {
                    if (progress.error) {
                        toast.error('Export failed: ' + progress.status);
                        setIsExporting(false);
                    } else if (progress.downloadUrl) {
                        window.location.href = progress.downloadUrl;
                        toast.success('Export completed! Download started.');
                        setIsExporting(false);
                        setShowExportDialog(false);
                    }
                } else {
                    setTimeout(checkProgress, 1000);
                }
            } catch {
                toast.error('Error checking export progress');
                setIsExporting(false);
            }
        };

        checkProgress();
    };

    // Management dialog handlers
    const fetchManagementStats = async () => {
        setIsLoadingStats(true);
        try {
            const response = await fetch(`/form-requests/leave-requests/credits/regularization/stats?year=${managementYear}`, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            if (!response.ok) throw new Error('Failed to fetch stats');
            const data = await response.json();
            setManagementStats(data);
        } catch {
            toast.error('Failed to load management statistics');
            setManagementStats(null);
        } finally {
            setIsLoadingStats(false);
        }
    };

    const handleProcessRegularization = async (dryRun: boolean = false) => {
        setIsProcessing(true);
        try {
            const response = await fetch('/form-requests/leave-requests/credits/regularization/process', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    year: managementYear,
                    dry_run: dryRun,
                }),
            });

            if (!response.ok) throw new Error('Failed to process regularization');

            const result = await response.json();

            if (result.success) {
                if (dryRun) {
                    toast.success(
                        `Dry Run Complete: ${result.summary.processed} would be processed, ${result.summary.skipped} skipped`
                    );
                } else {
                    toast.success(
                        `Processed ${result.summary.processed} regularization transfers successfully`
                    );
                    // Refresh the page to show updated data
                    router.reload({ only: ['creditsData'] });
                }
                // Refresh stats after processing
                fetchManagementStats();
            } else {
                toast.error(result.error || 'Failed to process regularization');
            }
        } catch {
            toast.error('Failed to process regularization');
        } finally {
            setIsProcessing(false);
            setConfirmAction(null);
        }
    };

    const handleProcessMonthlyAccruals = async () => {
        setIsProcessing(true);
        try {
            const response = await fetch('/form-requests/leave-requests/credits/accruals/process', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) throw new Error('Failed to process monthly accruals');

            const result = await response.json();

            if (result.success) {
                toast.success(
                    `Monthly accruals processed: ${result.summary.processed} users, ${result.summary.total_credits.toFixed(2)} total credits`
                );
                // Refresh the page to show updated data
                router.reload({ only: ['creditsData'] });
                fetchManagementStats();
            } else {
                toast.error(result.error || 'Failed to process monthly accruals');
            }
        } catch {
            toast.error('Failed to process monthly accruals');
        } finally {
            setIsProcessing(false);
            setConfirmAction(null);
        }
    };

    const handleProcessCashConversions = async () => {
        setIsProcessing(true);
        try {
            const response = await fetch(processCashConversions().url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    year: managementYear,
                }),
            });

            if (!response.ok) throw new Error('Failed to process cash conversions');

            const result = await response.json();

            if (result.success) {
                toast.success(
                    `Cash conversion processed: ${result.summary.processed} carryovers converted, ${result.summary.total_converted.toFixed(2)} total credits`
                );
                router.reload({ only: ['creditsData'] });
                fetchManagementStats();
            } else {
                toast.error(result.error || 'Failed to process cash conversions');
            }
        } catch {
            toast.error('Failed to process cash conversions');
        } finally {
            setIsProcessing(false);
            setConfirmAction(null);
        }
    };

    const handleConvertSingleCarryover = async (employeeId: number) => {
        setIsConverting(true);
        try {
            const response = await fetch(convertUserCarryover({ user: employeeId }).url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    year: parseInt(yearFilter),
                }),
            });

            const result = await response.json();

            if (result.success) {
                toast.success(result.message);
                router.reload({ only: ['creditsData'] });
            } else {
                toast.error(result.error || 'Failed to convert carryover');
            }
        } catch {
            toast.error('Failed to convert carryover');
        } finally {
            setIsConverting(false);
            setConvertingEmployeeId(null);
            setConvertingEmployeeName('');
        }
    };

    const handleProcessYearEndCarryovers = async () => {
        setIsProcessing(true);
        try {
            const response = await fetch('/form-requests/leave-requests/credits/carryovers/process', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    from_year: managementYear - 1,
                }),
            });

            if (!response.ok) throw new Error('Failed to process year-end carryovers');

            const result = await response.json();

            if (result.success) {
                toast.success(
                    `Carryovers processed: ${result.summary.processed} users, ${result.summary.total_carryover.toFixed(2)} credits carried over`
                );
                // Refresh the page to show updated data
                router.reload({ only: ['creditsData'] });
                fetchManagementStats();
            } else {
                toast.error(result.error || 'Failed to process year-end carryovers');
            }
        } catch {
            toast.error('Failed to process year-end carryovers');
        } finally {
            setIsProcessing(false);
            setConfirmAction(null);
        }
    };

    // Open management dialog and fetch stats
    const openManagementDialog = () => {
        setIsManagementDialogOpen(true);
        fetchManagementStats();
    };

    // Mismatch scan/fix handlers
    const scanMismatch = async () => {
        setIsScanningMismatch(true);
        setMismatchData(null);
        setExpandedMismatchUsers(new Set());
        try {
            const response = await fetch('/form-requests/leave-requests/credits/mismatch/scan', {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            if (!response.ok) throw new Error('Failed to scan');
            const data: MismatchScanResult = await response.json();
            setMismatchData(data);
        } catch {
            toast.error('Failed to scan for credits year mismatches');
        } finally {
            setIsScanningMismatch(false);
        }
    };

    const openMismatchDialog = () => {
        setIsMismatchDialogOpen(true);
        scanMismatch();
    };

    const handleFixMismatch = async () => {
        setIsFixingMismatch(true);
        try {
            const response = await fetch('/form-requests/leave-requests/credits/mismatch/fix', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                const errorData = await response.json();
                throw new Error(errorData.error || 'Failed to fix mismatches');
            }

            const result = await response.json();

            if (result.success) {
                toast.success(result.message);
                setMismatchData(null);
                setConfirmMismatchFix(false);
                setIsMismatchDialogOpen(false);
                router.reload({ only: ['creditsData'] });
            } else {
                toast.error(result.error || 'Failed to fix mismatches');
            }
        } catch (e) {
            toast.error(e instanceof Error ? e.message : 'Failed to fix mismatches');
        } finally {
            setIsFixingMismatch(false);
            setConfirmMismatchFix(false);
        }
    };

    const toggleMismatchUser = (userId: number) => {
        setExpandedMismatchUsers(prev => {
            const next = new Set(prev);
            if (next.has(userId)) {
                next.delete(userId);
            } else {
                next.add(userId);
            }
            return next;
        });
    };

    const getRoleBadgeVariant = (role: string) => {
        const variants: Record<string, 'default' | 'secondary' | 'outline' | 'destructive'> = {
            'Super Admin': 'destructive',
            'Admin': 'destructive',
            'HR': 'default',
            'Team Lead': 'default',
            'Agent': 'secondary',
            'IT': 'outline',
            'Utility': 'outline',
        };
        return variants[role] || 'secondary';
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                <LoadingOverlay isLoading={isPageLoading} />

                <PageHeader
                    title="Leave Credits"
                    description="View all employees' leave credit balances and history"
                />

                {/* Filters */}
                <div className="flex flex-col gap-3">
                    <div className="flex flex-col lg:flex-row gap-3 items-start lg:items-center">
                        <div className="flex flex-wrap gap-2 flex-1 w-full">
                            <Popover open={isEmployeePopoverOpen} onOpenChange={setIsEmployeePopoverOpen}>
                                <PopoverTrigger asChild>
                                    <Button
                                        variant="outline"
                                        role="combobox"
                                        aria-expanded={isEmployeePopoverOpen}
                                        className="w-full sm:w-auto justify-between font-normal"
                                    >
                                        <span className="truncate">
                                            {selectedEmployee
                                                ? selectedEmployee.name
                                                : "All Employees"}
                                        </span>
                                        <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                    </Button>
                                </PopoverTrigger>
                                <PopoverContent className="w-64 p-0" align="start">
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
                                                        setSelectedEmployeeId('');
                                                        setIsEmployeePopoverOpen(false);
                                                    }}
                                                    className="cursor-pointer"
                                                >
                                                    <Check
                                                        className={`mr-2 h-4 w-4 ${!selectedEmployeeId ? "opacity-100" : "opacity-0"}`}
                                                    />
                                                    All Employees
                                                </CommandItem>
                                                {filteredEmployees.map((employee) => (
                                                    <CommandItem
                                                        key={employee.id}
                                                        value={employee.name}
                                                        onSelect={() => {
                                                            setSelectedEmployeeId(employee.id.toString());
                                                            setIsEmployeePopoverOpen(false);
                                                        }}
                                                        className="cursor-pointer"
                                                    >
                                                        <Check
                                                            className={`mr-2 h-4 w-4 ${selectedEmployeeId === employee.id.toString()
                                                                ? "opacity-100"
                                                                : "opacity-0"
                                                                }`}
                                                        />
                                                        <div className="flex flex-col">
                                                            <span>{employee.name}</span>
                                                            <span className="text-xs text-muted-foreground">{employee.email}</span>
                                                        </div>
                                                    </CommandItem>
                                                ))}
                                            </CommandGroup>
                                        </CommandList>
                                    </Command>
                                </PopoverContent>
                            </Popover>

                            <Select value={yearFilter} onValueChange={(v) => { setYearFilter(v); }}>
                                <SelectTrigger className="w-full sm:w-[120px]">
                                    <SelectValue placeholder="Year" />
                                </SelectTrigger>
                                <SelectContent>
                                    {availableYears.map((year) => (
                                        <SelectItem key={year} value={year.toString()}>
                                            {year}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            <Select value={roleFilter} onValueChange={setRoleFilter}>
                                <SelectTrigger className="w-full sm:w-[140px]">
                                    <SelectValue placeholder="Role" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Roles</SelectItem>
                                    <SelectItem value="Super Admin">Super Admin</SelectItem>
                                    <SelectItem value="Admin">Admin</SelectItem>
                                    <SelectItem value="HR">HR</SelectItem>
                                    <SelectItem value="Team Lead">Team Lead</SelectItem>
                                    <SelectItem value="Agent">Agent</SelectItem>
                                    <SelectItem value="IT">IT</SelectItem>
                                    <SelectItem value="Utility">Utility</SelectItem>
                                </SelectContent>
                            </Select>

                            <Select value={eligibilityFilter} onValueChange={setEligibilityFilter}>
                                <SelectTrigger className="w-full sm:w-[180px]">
                                    <SelectValue placeholder="Status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Status</SelectItem>
                                    <SelectItem value="eligible">Regularized</SelectItem>
                                    <SelectItem value="not_eligible">Probationary</SelectItem>
                                    <SelectItem value="pending_regularization">Pending Transfer</SelectItem>
                                </SelectContent>
                            </Select>

                            <Select value={campaignFilter} onValueChange={setCampaignFilter}>
                                <SelectTrigger className="w-full sm:w-[180px]">
                                    <SelectValue placeholder="All Campaigns" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Campaigns</SelectItem>
                                    {campaigns.map(campaign => (
                                        <SelectItem key={campaign.id} value={String(campaign.id)}>
                                            {campaign.name}{teamLeadCampaignId === campaign.id ? " (Your Campaign)" : ""}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="flex flex-wrap gap-2 w-full lg:w-auto">
                            <Button variant="outline" onClick={applyFilters} className="flex-1 sm:flex-none">
                                <Filter className="mr-2 h-4 w-4" />
                                Filter
                            </Button>

                            {showClearFilters && (
                                <Button variant="outline" onClick={clearFilters} className="flex-1 sm:flex-none">
                                    Reset
                                </Button>
                            )}

                            <Button variant="outline" onClick={() => setShowExportDialog(true)} className="flex-1 sm:flex-none">
                                <Download className="h-4 w-4 mr-2" />
                                Export Credits
                            </Button>

                            <Button variant="outline" onClick={openManagementDialog} className="flex-1 sm:flex-none">
                                <Settings className="h-4 w-4 mr-2" />
                                Management
                            </Button>

                            <Button variant="outline" onClick={openMismatchDialog} className="flex-1 sm:flex-none">
                                <AlertTriangle className="h-4 w-4 mr-2" />
                                Fix Credits Year
                            </Button>
                        </div>
                    </div>

                    <div className="text-sm text-muted-foreground">
                        Showing {creditsData?.data?.length || 0} of {creditsData?.total || creditsData?.meta?.total || 0} employee{(creditsData?.total || creditsData?.meta?.total || 0) === 1 ? '' : 's'}
                        {showClearFilters && ' (filtered)'}
                    </div>
                </div>

                {/* Desktop Table */}
                <div className="hidden md:block shadow rounded-md overflow-hidden bg-card">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow className="bg-muted/50">
                                    <TableHead>Employee</TableHead>
                                    <TableHead>Role</TableHead>
                                    <TableHead>Hire Date</TableHead>
                                    <TableHead className="text-center">Regularization</TableHead>
                                    <TableHead className="text-right">Rate/Month</TableHead>
                                    <TableHead className="text-right">Earned</TableHead>
                                    <TableHead className="text-right">Used</TableHead>
                                    <TableHead className="text-right">Balance</TableHead>
                                    <TableHead className="text-center">Pending Transfer</TableHead>
                                    <TableHead className="text-center">Carryover Received</TableHead>
                                    <TableHead className="text-center">Carryover Forward</TableHead>
                                    <TableHead className="text-center">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {!creditsData?.data || creditsData.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={12} className="text-center py-8 text-muted-foreground">
                                            No employees found
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    creditsData.data.map((employee) => (
                                        <TableRow key={employee.id}>
                                            <TableCell>
                                                <div>
                                                    <p className="font-medium">{employee.name}</p>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant={getRoleBadgeVariant(employee.role)}>
                                                    {employee.role}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>{format(new Date(employee.hired_date), 'MMM d, yyyy')}</TableCell>
                                            <TableCell className="text-center">
                                                {employee.regularization.is_regularized ? (
                                                    <TooltipProvider>
                                                        <Tooltip>
                                                            <TooltipTrigger asChild>
                                                                <Badge variant="default" className="bg-green-600 cursor-help">
                                                                    {employee.regularization.regularization_date
                                                                        ? format(new Date(employee.regularization.regularization_date), 'MMM d, yyyy')
                                                                        : 'Regularized'
                                                                    }
                                                                </Badge>
                                                            </TooltipTrigger>
                                                            <TooltipContent>
                                                                <p className="font-medium">Regular Employee</p>
                                                                <p className="text-xs text-muted-foreground">
                                                                    Hired: {format(new Date(employee.hired_date), 'MMM d, yyyy')}
                                                                </p>
                                                                {employee.regularization.has_first_regularization && (
                                                                    <p className="text-xs text-green-600 mt-1">
                                                                        ✓ First regularization transfer completed
                                                                    </p>
                                                                )}
                                                            </TooltipContent>
                                                        </Tooltip>
                                                    </TooltipProvider>
                                                ) : (
                                                    <TooltipProvider>
                                                        <Tooltip>
                                                            <TooltipTrigger asChild>
                                                                <Badge variant="secondary" className="cursor-help">
                                                                    <Clock className="h-3 w-3 mr-1" />
                                                                    {format(new Date(employee.eligibility_date), 'MMM d, yyyy')}
                                                                </Badge>
                                                            </TooltipTrigger>
                                                            <TooltipContent>
                                                                <p className="font-medium">Probationary Period</p>
                                                                <p className="text-xs">
                                                                    Regularization: {format(new Date(employee.eligibility_date), 'MMM d, yyyy')}
                                                                </p>
                                                                <p className="text-xs text-muted-foreground">
                                                                    {employee.regularization.days_until_regularization > 0
                                                                        ? `${employee.regularization.days_until_regularization} days remaining`
                                                                        : 'Awaiting system processing'}
                                                                </p>
                                                            </TooltipContent>
                                                        </Tooltip>
                                                    </TooltipProvider>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">{employee.monthly_rate}</TableCell>
                                            <TableCell className="text-right font-medium text-green-600">
                                                +{employee.total_earned.toFixed(2)}
                                            </TableCell>
                                            <TableCell className="text-right font-medium text-orange-600">
                                                -{employee.total_used.toFixed(2)}
                                            </TableCell>
                                            <TableCell className="text-right font-bold">
                                                {employee.balance.toFixed(2)}
                                            </TableCell>
                                            {/* Accrued (Pending Regularization) Column */}
                                            <TableCell className="text-center">
                                                {employee.regularization.pending_credits ? (
                                                    <TooltipProvider>
                                                        <Tooltip>
                                                            <TooltipTrigger asChild>
                                                                <Badge
                                                                    variant="outline"
                                                                    className="border-purple-500 text-purple-600 bg-purple-50 cursor-help whitespace-nowrap"
                                                                >
                                                                    <Clock className="h-3 w-3 mr-1" />
                                                                    {employee.regularization.pending_credits.credits.toFixed(2)}
                                                                    <span className="mx-0.5 text-purple-400 text-xs">({employee.regularization.pending_credits.from_year}→{employee.regularization.pending_credits.to_year})</span>
                                                                </Badge>
                                                            </TooltipTrigger>
                                                            <TooltipContent>
                                                                <p className="font-medium">Pending Regularization Credits</p>
                                                                <p className="text-xs">
                                                                    {employee.regularization.pending_credits.credits.toFixed(2)} credits from {employee.regularization.pending_credits.from_year}
                                                                </p>
                                                                <p className="text-xs text-muted-foreground">
                                                                    ({employee.regularization.pending_credits.months_accrued} months accrued)
                                                                </p>
                                                                <p className="text-xs text-purple-600 mt-1">
                                                                    {employee.regularization.pending_credits.from_year} → {employee.regularization.pending_credits.to_year} transfer upon regularization
                                                                </p>
                                                            </TooltipContent>
                                                        </Tooltip>
                                                    </TooltipProvider>
                                                ) : (
                                                    <span className="text-muted-foreground text-sm">—</span>
                                                )}
                                            </TableCell>
                                            {/* Carryover Received (from previous year) */}
                                            <TableCell className="text-center">
                                                {employee.carryover_received ? (
                                                    <TooltipProvider>
                                                        <Tooltip>
                                                            <TooltipTrigger asChild>
                                                                <Badge
                                                                    variant="outline"
                                                                    className={employee.carryover_received.cash_converted
                                                                        ? "border-gray-400 text-gray-500 bg-gray-50 cursor-help line-through"
                                                                        : employee.carryover_received.is_first_regularization
                                                                            ? "border-green-500 text-green-600 bg-green-50 cursor-help"
                                                                            : "border-blue-500 text-blue-600 bg-blue-50 cursor-help"
                                                                    }
                                                                >
                                                                    {employee.carryover_received.cash_converted
                                                                        ? <Banknote className="h-3 w-3 mr-1" />
                                                                        : <Check className="h-3 w-3 mr-1" />
                                                                    }
                                                                    {employee.carryover_received.credits.toFixed(2)}
                                                                </Badge>
                                                            </TooltipTrigger>
                                                            <TooltipContent>
                                                                <p className="font-medium">
                                                                    {employee.carryover_received.cash_converted
                                                                        ? 'Converted to Cash'
                                                                        : employee.carryover_received.is_first_regularization
                                                                            ? 'First Regularization Transfer'
                                                                            : 'Carryover Received'
                                                                    }
                                                                </p>
                                                                <p className="text-xs">
                                                                    {employee.carryover_received.credits.toFixed(2)} credits from {employee.carryover_received.from_year}
                                                                </p>
                                                                {employee.carryover_received.cash_converted && (
                                                                    <p className="text-xs text-green-600 mt-1">
                                                                        Credits converted to cash — not available for leave
                                                                    </p>
                                                                )}
                                                                {employee.carryover_received.is_first_regularization && !employee.carryover_received.cash_converted && (
                                                                    <p className="text-xs text-green-600 mt-1">
                                                                        All credits transferred (first regularization)
                                                                    </p>
                                                                )}
                                                            </TooltipContent>
                                                        </Tooltip>
                                                    </TooltipProvider>
                                                ) : (
                                                    <span className="text-muted-foreground text-sm">—</span>
                                                )}
                                            </TableCell>
                                            {/* Carryover Forward (to next year) */}
                                            <TableCell className="text-center">
                                                {employee.carryover ? (
                                                    <TooltipProvider>
                                                        <Tooltip>
                                                            <TooltipTrigger asChild>
                                                                <Badge
                                                                    variant="outline"
                                                                    className={employee.carryover.cash_converted
                                                                        ? "border-green-500 text-green-600 bg-green-50"
                                                                        : employee.carryover.is_expired
                                                                            ? "border-red-500 text-red-600 bg-red-50"
                                                                            : employee.carryover.is_processed
                                                                                ? "border-amber-500 text-amber-600 bg-amber-50"
                                                                                : "border-blue-500 text-blue-600 bg-blue-50"
                                                                    }
                                                                >
                                                                    <Banknote className="h-3 w-3 mr-1" />
                                                                    {employee.carryover.credits.toFixed(2)}
                                                                </Badge>
                                                            </TooltipTrigger>
                                                            <TooltipContent>
                                                                <p>To {employee.carryover.to_year} (for conversion/leave)</p>
                                                                <p className="text-xs text-muted-foreground">
                                                                    {employee.carryover.cash_converted
                                                                        ? 'Converted'
                                                                        : employee.carryover.is_expired
                                                                            ? 'Expired (past March)'
                                                                            : employee.carryover.is_processed
                                                                                ? 'Available until March'
                                                                                : 'Projected (not yet processed)'}
                                                                </p>
                                                            </TooltipContent>
                                                        </Tooltip>
                                                    </TooltipProvider>
                                                ) : (
                                                    <span className="text-muted-foreground text-sm">—</span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-center">
                                                <div className="flex items-center justify-center gap-1">
                                                    <Link href={`/form-requests/leave-requests/credits/${employee.id}?year=${yearFilter}`}>
                                                        <Button variant="outline" size="icon" title="View History">
                                                            <Eye className="h-4 w-4" />
                                                        </Button>
                                                    </Link>
                                                    {canEdit && employee.carryover_received && (
                                                        <Button
                                                            variant="outline"
                                                            size="icon"
                                                            title="Edit Carryover Credits"
                                                            onClick={() => openEditCarryover(employee)}
                                                        >
                                                            <Pencil className="h-4 w-4" />
                                                        </Button>
                                                    )}
                                                    {canEdit && employee.carryover_received && !employee.carryover_received.cash_converted && !employee.carryover_received.is_first_regularization && (
                                                        <TooltipProvider>
                                                            <Tooltip>
                                                                <TooltipTrigger asChild>
                                                                    <Button
                                                                        variant="outline"
                                                                        size="icon"
                                                                        title="Convert Carryover to Cash"
                                                                        onClick={() => {
                                                                            setConvertingEmployeeId(employee.id);
                                                                            setConvertingEmployeeName(employee.name);
                                                                        }}
                                                                        disabled={isConverting}
                                                                    >
                                                                        <ArrowRightLeft className="h-4 w-4" />
                                                                    </Button>
                                                                </TooltipTrigger>
                                                                <TooltipContent>
                                                                    <p>Convert {employee.carryover_received.credits.toFixed(2)} carryover credits to cash</p>
                                                                </TooltipContent>
                                                            </Tooltip>
                                                        </TooltipProvider>
                                                    )}
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </div>

                    {creditsData.links && creditsData.links.length > 0 && (
                        <div className="border-t px-4 py-3 flex justify-center">
                            <PaginationNav links={creditsData.links} only={["creditsData"]} />
                        </div>
                    )}
                </div>

                {/* Mobile Cards */}
                <div className="md:hidden space-y-4">
                    {!creditsData?.data || creditsData.data.length === 0 ? (
                        <div className="bg-card border rounded-lg p-8 shadow-sm">
                            <p className="text-center text-muted-foreground">No employees found</p>
                        </div>
                    ) : (
                        <>
                            {creditsData.data.map((employee) => (
                                <div key={employee.id} className="bg-card border rounded-lg p-4 shadow-sm space-y-3">
                                    <div className="flex justify-between items-start">
                                        <div>
                                            <p className="font-medium">{employee.name}</p>
                                        </div>
                                        <Badge variant={getRoleBadgeVariant(employee.role)}>
                                            {employee.role}
                                        </Badge>
                                    </div>

                                    <div className="grid grid-cols-2 gap-2 text-sm">
                                        <div>
                                            <p className="text-muted-foreground">Hired</p>
                                            <p>{format(new Date(employee.hired_date), 'MMM d, yyyy')}</p>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground">Regularization</p>
                                            {employee.regularization.is_regularized ? (
                                                <Badge variant="default" className="bg-green-600">
                                                    {employee.regularization.regularization_date
                                                        ? format(new Date(employee.regularization.regularization_date), 'MMM d, yyyy')
                                                        : 'Regularized'
                                                    }
                                                </Badge>
                                            ) : (
                                                <Badge variant="secondary">
                                                    <Clock className="h-3 w-3 mr-1" />
                                                    {format(new Date(employee.eligibility_date), 'MMM d')}
                                                    {employee.regularization.days_until_regularization > 0 && (
                                                        <span className="ml-1 text-xs">({employee.regularization.days_until_regularization}d)</span>
                                                    )}
                                                </Badge>
                                            )}
                                        </div>
                                    </div>

                                    <Separator />

                                    <div className="grid grid-cols-4 gap-2 text-center">
                                        <div>
                                            <p className="text-xs text-muted-foreground">Rate</p>
                                            <p className="font-medium">{employee.monthly_rate}</p>
                                        </div>
                                        <div>
                                            <p className="text-xs text-muted-foreground">Earned</p>
                                            <p className="font-medium text-green-600">+{employee.total_earned.toFixed(1)}</p>
                                        </div>
                                        <div>
                                            <p className="text-xs text-muted-foreground">Used</p>
                                            <p className="font-medium text-orange-600">-{employee.total_used.toFixed(1)}</p>
                                        </div>
                                        <div>
                                            <p className="text-xs text-muted-foreground">Balance</p>
                                            <p className="font-bold">{employee.balance.toFixed(2)}</p>
                                        </div>
                                    </div>

                                    {employee.carryover && (
                                        <div className={`flex items-center justify-between p-2 rounded-md border ${employee.carryover.cash_converted
                                            ? 'bg-green-50 border-green-200'
                                            : employee.carryover.is_expired
                                                ? 'bg-red-50 border-red-200'
                                                : employee.carryover.is_processed
                                                    ? 'bg-amber-50 border-amber-200'
                                                    : 'bg-blue-50 border-blue-200'
                                            }`}>
                                            <div className="flex items-center gap-2">
                                                <Banknote className={`h-4 w-4 ${employee.carryover.cash_converted
                                                    ? 'text-green-600'
                                                    : employee.carryover.is_expired
                                                        ? 'text-red-600'
                                                        : employee.carryover.is_processed
                                                            ? 'text-amber-600'
                                                            : 'text-blue-600'
                                                    }`} />
                                                <span className={`text-sm ${employee.carryover.cash_converted
                                                    ? 'text-green-700'
                                                    : employee.carryover.is_expired
                                                        ? 'text-red-700'
                                                        : employee.carryover.is_processed
                                                            ? 'text-amber-700'
                                                            : 'text-blue-700'
                                                    }`}>
                                                    {employee.carryover.is_expired
                                                        ? `Expired (${employee.carryover.to_year})`
                                                        : `Forward to ${employee.carryover.to_year}`
                                                    }
                                                </span>
                                            </div>
                                            <Badge
                                                variant="outline"
                                                className={employee.carryover.cash_converted
                                                    ? "border-green-500 text-green-600"
                                                    : employee.carryover.is_expired
                                                        ? "border-red-500 text-red-600"
                                                        : employee.carryover.is_processed
                                                            ? "border-amber-500 text-amber-600"
                                                            : "border-blue-500 text-blue-600"
                                                }
                                            >
                                                {employee.carryover.credits.toFixed(2)} credits
                                            </Badge>
                                        </div>
                                    )}

                                    {/* Carryover Received (from previous year) - Mobile */}
                                    {employee.carryover_received && (
                                        <div className={`flex items-center justify-between p-2 rounded-md border ${employee.carryover_received.cash_converted
                                            ? 'bg-gray-50 border-gray-300'
                                            : 'bg-green-50 border-green-200'
                                            }`}>
                                            <div className="flex items-center gap-2">
                                                {employee.carryover_received.cash_converted
                                                    ? <Banknote className="h-4 w-4 text-gray-500" />
                                                    : <Check className="h-4 w-4 text-green-600" />
                                                }
                                                <div className="flex flex-col">
                                                    <span className={`text-sm ${employee.carryover_received.cash_converted ? 'text-gray-500 line-through' : 'text-green-700'}`}>
                                                        {employee.carryover_received.is_first_regularization
                                                            ? 'Regularization Transfer'
                                                            : 'Received from ' + employee.carryover_received.from_year
                                                        }
                                                    </span>
                                                    {employee.carryover_received.cash_converted && (
                                                        <span className="text-xs text-green-600 font-medium">
                                                            Converted to cash
                                                        </span>
                                                    )}
                                                    {employee.carryover_received.is_first_regularization && !employee.carryover_received.cash_converted && (
                                                        <span className="text-xs text-green-500">
                                                            From {employee.carryover_received.from_year} (all credits)
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                            <Badge variant="outline" className={employee.carryover_received.cash_converted
                                                ? 'border-gray-400 text-gray-500'
                                                : 'border-green-500 text-green-600'
                                            }>
                                                {employee.carryover_received.credits.toFixed(2)}
                                            </Badge>
                                        </div>
                                    )}

                                    {/* Pending Regularization Credits (Mobile) */}
                                    {employee.regularization.pending_credits && (
                                        <div className="flex items-center justify-between p-2 rounded-md border bg-purple-50 border-purple-200">
                                            <div className="flex items-center gap-2">
                                                <Clock className="h-4 w-4 text-purple-600" />
                                                <div className="flex flex-col">
                                                    <span className="text-sm text-purple-700">
                                                        Pending from {employee.regularization.pending_credits.from_year}
                                                    </span>
                                                    <span className="text-xs text-purple-500">
                                                        Transfers to {employee.regularization.pending_credits.to_year} upon regularization
                                                    </span>
                                                </div>
                                            </div>
                                            <Badge variant="outline" className="border-purple-500 text-purple-600">
                                                {employee.regularization.pending_credits.credits.toFixed(2)}
                                            </Badge>
                                        </div>
                                    )}

                                    <Link href={`/form-requests/leave-requests/credits/${employee.id}?year=${yearFilter}`}>
                                        <Button variant="outline" size="sm" className="w-full">
                                            <Eye className="h-4 w-4 mr-2" />
                                            View History
                                        </Button>
                                    </Link>
                                    {canEdit && employee.carryover_received && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="w-full"
                                            onClick={() => openEditCarryover(employee)}
                                        >
                                            <Pencil className="h-4 w-4 mr-2" />
                                            Edit Carryover
                                        </Button>
                                    )}
                                    {canEdit && employee.carryover_received && !employee.carryover_received.cash_converted && !employee.carryover_received.is_first_regularization && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="w-full"
                                            disabled={isConverting}
                                            onClick={() => {
                                                setConvertingEmployeeId(employee.id);
                                                setConvertingEmployeeName(employee.name);
                                            }}
                                        >
                                            <ArrowRightLeft className="h-4 w-4 mr-2" />
                                            Convert to Cash
                                        </Button>
                                    )}
                                </div>
                            ))}
                            {creditsData.links && creditsData.links.length > 0 && (
                                <div className="flex justify-center mt-4">
                                    <PaginationNav links={creditsData.links} only={["creditsData"]} />
                                </div>
                            )}
                        </>
                    )}
                </div>
            </div>

            {/* Export Dialog */}
            <Dialog open={showExportDialog} onOpenChange={setShowExportDialog}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Export Leave Credits Summary</DialogTitle>
                        <DialogDescription>
                            Export a summary of all employee leave credits for a specific year to Excel.
                        </DialogDescription>
                    </DialogHeader>

                    {!isExporting ? (
                        <div className="space-y-4 py-4">
                            <div className="space-y-2">
                                <label className="text-sm font-medium">Select Year</label>
                                <Select value={exportYear.toString()} onValueChange={(v) => setExportYear(parseInt(v))}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {availableYears.map((year) => (
                                            <SelectItem key={year} value={year.toString()}>
                                                {year}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <Button onClick={startExport} className="w-full">
                                <Download className="h-4 w-4 mr-2" />
                                Start Export
                            </Button>
                        </div>
                    ) : (
                        <div className="space-y-4 py-4">
                            <Progress value={exportProgress.percent} />
                            <p className="text-sm text-center text-muted-foreground">
                                {exportProgress.status}
                            </p>
                        </div>
                    )}
                </DialogContent>
            </Dialog>

            {/* Management Dialog */}
            <Dialog open={isManagementDialogOpen} onOpenChange={setIsManagementDialogOpen}>
                <DialogContent className="sm:max-w-[550px] max-h-[85vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Leave Credits Management</DialogTitle>
                        <DialogDescription>
                            Process regularization credit transfers and manage leave credits.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4 py-4">
                        {/* Year Selection */}
                        <div className="space-y-2">
                            <label className="text-sm font-medium">Target Year</label>
                            <Select
                                value={managementYear.toString()}
                                onValueChange={(v) => {
                                    setManagementYear(parseInt(v));
                                    setManagementStats(null);
                                }}
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {availableYears.map((year) => (
                                        <SelectItem key={year} value={year.toString()}>
                                            {year}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        {isLoadingStats ? (
                            <div className="flex items-center justify-center py-8">
                                <Loader2 className="h-8 w-8 animate-spin text-primary" />
                            </div>
                        ) : managementStats ? (
                            <div className="grid gap-3">
                                <div className="flex items-center justify-between p-3 border rounded-lg">
                                    <div className="flex items-center gap-3">
                                        <Clock className="h-5 w-5 text-purple-600" />
                                        <div>
                                            <p className="font-medium">Pending Regularization</p>
                                            <p className="text-sm text-muted-foreground">
                                                Users with credits waiting to transfer
                                            </p>
                                        </div>
                                    </div>
                                    <Badge variant={managementStats.pending_count > 0 ? "default" : "secondary"}>
                                        {managementStats.pending_count}
                                    </Badge>
                                </div>

                                <div className="flex items-center justify-between p-3 border rounded-lg">
                                    <div className="flex items-center gap-3">
                                        <ArrowRight className="h-5 w-5 text-blue-600" />
                                        <div>
                                            <p className="font-medium">Total Eligible</p>
                                            <p className="text-sm text-muted-foreground">
                                                Users eligible for first regularization
                                            </p>
                                        </div>
                                    </div>
                                    <Badge variant="secondary">
                                        {managementStats.total_eligible}
                                    </Badge>
                                </div>

                                <div className="flex items-center justify-between p-3 border rounded-lg">
                                    <div className="flex items-center gap-3">
                                        <Check className="h-5 w-5 text-green-600" />
                                        <div>
                                            <p className="font-medium">Already Processed</p>
                                            <p className="text-sm text-muted-foreground">
                                                First regularization already done
                                            </p>
                                        </div>
                                    </div>
                                    <Badge variant="outline" className="border-green-500 text-green-600">
                                        {managementStats.already_processed}
                                    </Badge>
                                </div>
                            </div>
                        ) : (
                            <div className="text-center py-4">
                                <p className="text-muted-foreground">Click refresh to load statistics</p>
                            </div>
                        )}

                        {/* Info Notice - Probationary Accrual */}
                        <div className="flex items-start gap-2 p-3 bg-purple-50 dark:bg-purple-950 border border-purple-200 dark:border-purple-800 rounded-lg">
                            <TrendingUp className="h-5 w-5 text-purple-600 dark:text-purple-400 shrink-0 mt-0.5" />
                            <div className="text-sm text-purple-700 dark:text-purple-300">
                                <p className="font-medium mb-1">Probationary Accrual (6 Months)</p>
                                <p>
                                    During probation, leave credits accrue on the <strong>hire date anniversary</strong> each month
                                    (e.g., hired July 11 → Aug 11 +1.25, Sep 11 +1.25, etc.).
                                    Hire month does not receive credit. After 6 months, employee becomes regularized.
                                </p>
                            </div>
                        </div>

                        {/* Info Notice - First Regularization Transfer */}
                        <div className="flex items-start gap-2 p-3 bg-blue-50 dark:bg-blue-950 border border-blue-200 dark:border-blue-800 rounded-lg">
                            <Clock className="h-5 w-5 text-blue-600 dark:text-blue-400 shrink-0 mt-0.5" />
                            <div className="text-sm text-blue-700 dark:text-blue-300">
                                <p className="font-medium mb-1">First-Time Regularization Transfer</p>
                                <p>
                                    When an employee is regularized (6 months after hire), their accrued leave credits
                                    from the hire year are automatically transferred to the regularization year.
                                    <strong> All credits transfer for first regularization (no cap).</strong>
                                </p>
                            </div>
                        </div>

                        {/* Info Notice - Regular Employee Carryover */}
                        <div className="flex items-start gap-2 p-3 bg-amber-50 dark:bg-amber-950 border border-amber-200 dark:border-amber-800 rounded-lg">
                            <Banknote className="h-5 w-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
                            <div className="text-sm text-amber-700 dark:text-amber-300">
                                <p className="font-medium mb-1">Regular Employee Year-End Carryover</p>
                                <p>
                                    Regular employees can carry over a <strong>maximum of 4 leave credits</strong> to the next year.
                                    These credits can be used for leave or conversion until <strong>March 31</strong>.
                                    Any remaining carryover credits after March expire.
                                </p>
                            </div>
                        </div>

                        <Separator />

                        <div>
                            <h4 className="text-sm font-medium mb-3">Credit Processing</h4>
                            <div className="grid gap-2">
                                <Button
                                    variant="outline"
                                    className="justify-start"
                                    disabled={isProcessing}
                                    onClick={() => setConfirmAction('process-accruals')}
                                >
                                    <TrendingUp className="h-4 w-4 mr-2" />
                                    Process Monthly Accruals
                                    <span className="ml-auto text-xs text-muted-foreground">All Users</span>
                                </Button>

                                <Button
                                    variant="outline"
                                    className="justify-start"
                                    disabled={isProcessing}
                                    onClick={() => setConfirmAction('process-carryovers')}
                                >
                                    <Banknote className="h-4 w-4 mr-2" />
                                    Process Year-End Carryovers
                                    <span className="ml-auto text-xs text-muted-foreground">{managementYear - 1} → {managementYear}</span>
                                </Button>

                                <Button
                                    variant="outline"
                                    className="justify-start"
                                    disabled={isProcessing}
                                    onClick={() => setConfirmAction('process-cash-conversion')}
                                >
                                    <ArrowRightLeft className="h-4 w-4 mr-2" />
                                    Convert Carryover to Cash
                                    <span className="ml-auto text-xs text-muted-foreground">{managementYear}</span>
                                </Button>
                            </div>
                        </div>

                        <Separator />

                        <div>
                            <h4 className="text-sm font-medium mb-3">Regularization Transfers</h4>
                            <div className="grid gap-2">
                                <Button
                                    variant="outline"
                                    className="justify-start"
                                    disabled={!managementStats || managementStats.pending_count === 0 || isProcessing}
                                    onClick={() => setConfirmAction('process-dry-run')}
                                >
                                    <Play className="h-4 w-4 mr-2" />
                                    Preview Transfer (Dry Run)
                                    {managementStats && managementStats.pending_count > 0 && (
                                        <Badge variant="default" className="ml-auto">
                                            {managementStats.pending_count}
                                        </Badge>
                                    )}
                                </Button>

                                <Button
                                    variant="outline"
                                    className="justify-start"
                                    disabled={!managementStats || managementStats.pending_count === 0 || isProcessing}
                                    onClick={() => setConfirmAction('process-regularization')}
                                >
                                    <RefreshCw className="h-4 w-4 mr-2" />
                                    Process Regularization Transfers
                                    {managementStats && managementStats.pending_count > 0 && (
                                        <Badge variant="default" className="ml-auto">
                                            {managementStats.pending_count}
                                        </Badge>
                                    )}
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

            {/* Credits Year Mismatch Dialog */}
            <Dialog open={isMismatchDialogOpen} onOpenChange={setIsMismatchDialogOpen}>
                <DialogContent className="sm:max-w-[700px] max-h-[85vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <AlertTriangle className="h-5 w-5 text-amber-500" />
                            Fix Credits Year Mismatch
                        </DialogTitle>
                        <DialogDescription>
                            Scans for approved leave requests where the credits were deducted from the wrong year
                            (e.g., filed in 2025 but credits deducted from 2026). This will restore credits to the wrong year
                            and re-deduct from the correct year.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4 py-2">
                        {isScanningMismatch ? (
                            <div className="flex flex-col items-center justify-center py-8 gap-3">
                                <Loader2 className="h-8 w-8 animate-spin text-primary" />
                                <p className="text-sm text-muted-foreground">Scanning leave requests...</p>
                            </div>
                        ) : mismatchData ? (
                            mismatchData.total_requests === 0 ? (
                                <div className="flex flex-col items-center justify-center py-8 gap-2">
                                    <Check className="h-10 w-10 text-green-500" />
                                    <p className="font-medium text-green-700 dark:text-green-400">No mismatches found</p>
                                    <p className="text-sm text-muted-foreground">All leave request credits are assigned to the correct year.</p>
                                </div>
                            ) : (
                                <>
                                    {/* Summary */}
                                    <div className="grid grid-cols-3 gap-3">
                                        <div className="p-3 border rounded-lg text-center">
                                            <p className="text-2xl font-bold text-amber-600">{mismatchData.total_users}</p>
                                            <p className="text-xs text-muted-foreground">Affected Users</p>
                                        </div>
                                        <div className="p-3 border rounded-lg text-center">
                                            <p className="text-2xl font-bold text-amber-600">{mismatchData.total_requests}</p>
                                            <p className="text-xs text-muted-foreground">Leave Requests</p>
                                        </div>
                                        <div className="p-3 border rounded-lg text-center">
                                            <p className="text-2xl font-bold text-amber-600">{mismatchData.total_credits}</p>
                                            <p className="text-xs text-muted-foreground">Credits to Move</p>
                                        </div>
                                    </div>

                                    {/* User list with expandable details */}
                                    <div className="border rounded-lg divide-y max-h-[40vh] overflow-y-auto">
                                        {mismatchData.users.map((user) => (
                                            <div key={user.user_id}>
                                                <button
                                                    type="button"
                                                    className="w-full flex items-center justify-between p-3 hover:bg-muted/50 transition-colors text-left"
                                                    onClick={() => toggleMismatchUser(user.user_id)}
                                                >
                                                    <div className="flex items-center gap-2">
                                                        {expandedMismatchUsers.has(user.user_id) ? (
                                                            <ChevronDown className="h-4 w-4 text-muted-foreground" />
                                                        ) : (
                                                            <ChevronRight className="h-4 w-4 text-muted-foreground" />
                                                        )}
                                                        <div>
                                                            <p className="font-medium text-sm">{user.name}</p>
                                                            <p className="text-xs text-muted-foreground">{user.email}</p>
                                                        </div>
                                                    </div>
                                                    <div className="flex items-center gap-3">
                                                        <Badge variant="outline" className="text-xs">
                                                            {user.requests.length} request{user.requests.length !== 1 ? 's' : ''}
                                                        </Badge>
                                                        <Badge variant="destructive" className="text-xs">
                                                            {user.total_to_restore} credits
                                                        </Badge>
                                                    </div>
                                                </button>

                                                {expandedMismatchUsers.has(user.user_id) && (
                                                    <div className="px-3 pb-3">
                                                        <div className="bg-muted/30 rounded-md overflow-hidden">
                                                            <Table>
                                                                <TableHeader>
                                                                    <TableRow className="text-xs">
                                                                        <TableHead className="py-2 text-xs">Leave</TableHead>
                                                                        <TableHead className="py-2 text-xs">Filed</TableHead>
                                                                        <TableHead className="py-2 text-xs">Dates</TableHead>
                                                                        <TableHead className="py-2 text-xs text-right">Credits</TableHead>
                                                                        <TableHead className="py-2 text-xs text-center">Year Fix</TableHead>
                                                                    </TableRow>
                                                                </TableHeader>
                                                                <TableBody>
                                                                    {user.requests.map((req) => (
                                                                        <TableRow key={req.id} className="text-xs">
                                                                            <TableCell className="py-1.5">
                                                                                <span className="font-medium">#{req.id}</span>
                                                                                <span className="text-muted-foreground ml-1">{req.leave_type}</span>
                                                                            </TableCell>
                                                                            <TableCell className="py-1.5">{req.filed_date}</TableCell>
                                                                            <TableCell className="py-1.5">
                                                                                {req.start_date} — {req.end_date}
                                                                            </TableCell>
                                                                            <TableCell className="py-1.5 text-right font-medium">
                                                                                {req.credits_deducted}
                                                                            </TableCell>
                                                                            <TableCell className="py-1.5 text-center">
                                                                                <span className="text-red-500 line-through">{req.current_credits_year}</span>
                                                                                <ArrowRight className="inline h-3 w-3 mx-1 text-muted-foreground" />
                                                                                <span className="text-green-600 font-medium">{req.correct_credits_year}</span>
                                                                            </TableCell>
                                                                        </TableRow>
                                                                    ))}
                                                                </TableBody>
                                                            </Table>
                                                        </div>
                                                    </div>
                                                )}
                                            </div>
                                        ))}
                                    </div>

                                    {/* Warning notice */}
                                    <div className="flex items-start gap-2 p-3 bg-amber-50 dark:bg-amber-950 border border-amber-200 dark:border-amber-800 rounded-lg">
                                        <AlertTriangle className="h-5 w-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
                                        <div className="text-sm text-amber-700 dark:text-amber-300">
                                            <p className="font-medium mb-1">What will happen</p>
                                            <p>
                                                For each affected leave request: credits will be <strong>restored</strong> to the wrong year,
                                                then <strong>re-deducted</strong> from the correct year (based on the filing date).
                                                All changes are executed in a single database transaction.
                                            </p>
                                        </div>
                                    </div>
                                </>
                            )
                        ) : (
                            <div className="text-center py-4">
                                <p className="text-muted-foreground">Click scan to check for mismatches</p>
                            </div>
                        )}
                    </div>

                    <DialogFooter className="gap-2">
                        <Button variant="outline" onClick={() => setIsMismatchDialogOpen(false)}>
                            Close
                        </Button>
                        <Button variant="outline" onClick={scanMismatch} disabled={isScanningMismatch}>
                            {isScanningMismatch ? (
                                <>
                                    <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                                    Scanning...
                                </>
                            ) : (
                                <>
                                    <RefreshCw className="h-4 w-4 mr-2" />
                                    Rescan
                                </>
                            )}
                        </Button>
                        {mismatchData && mismatchData.total_requests > 0 && (
                            <Button
                                variant="destructive"
                                onClick={() => setConfirmMismatchFix(true)}
                                disabled={isFixingMismatch}
                            >
                                <AlertTriangle className="h-4 w-4 mr-2" />
                                Fix {mismatchData.total_requests} Request{mismatchData.total_requests !== 1 ? 's' : ''}
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Mismatch Fix Confirmation */}
            <AlertDialog open={confirmMismatchFix} onOpenChange={setConfirmMismatchFix}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Confirm Credits Year Fix</AlertDialogTitle>
                        <AlertDialogDescription>
                            This will fix <strong>{mismatchData?.total_requests}</strong> leave request{(mismatchData?.total_requests ?? 0) !== 1 ? 's' : ''} across{' '}
                            <strong>{mismatchData?.total_users}</strong> user{(mismatchData?.total_users ?? 0) !== 1 ? 's' : ''}, moving a total of{' '}
                            <strong>{mismatchData?.total_credits}</strong> credits to their correct year.
                            <br /><br />
                            All changes run in a single transaction and will be rolled back if any error occurs.
                            <br /><br />
                            <strong>This action cannot be undone.</strong>
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={isFixingMismatch}>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={handleFixMismatch}
                            disabled={isFixingMismatch}
                            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                        >
                            {isFixingMismatch ? (
                                <>
                                    <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                                    Fixing...
                                </>
                            ) : (
                                'Confirm Fix'
                            )}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* Confirmation Dialog */}
            <AlertDialog open={!!confirmAction} onOpenChange={(open) => !open && setConfirmAction(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {confirmAction === 'process-dry-run' && 'Preview Regularization Transfers'}
                            {confirmAction === 'process-regularization' && 'Process Regularization Transfers'}
                            {confirmAction === 'process-accruals' && 'Process Monthly Accruals'}
                            {confirmAction === 'process-carryovers' && 'Process Year-End Carryovers'}
                            {confirmAction === 'process-cash-conversion' && 'Convert Carryover Credits to Cash'}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {confirmAction === 'process-dry-run' && (
                                <>
                                    This will preview all pending first-time regularization credit transfers for {managementYear}.
                                    No changes will be made to the database.
                                </>
                            )}
                            {confirmAction === 'process-regularization' && (
                                <>
                                    This will process all pending first-time regularization credit transfers for {managementYear}.
                                    Credits from the hire year will be transferred to the regularization year for all eligible employees.
                                    <br /><br />
                                    <strong>This action cannot be undone.</strong>
                                </>
                            )}
                            {confirmAction === 'process-accruals' && (
                                <>
                                    This will process monthly leave credit accruals for all eligible users.
                                    <br /><br />
                                    <strong>Probationary employees:</strong> Credits accrue on their hire date anniversary each month.
                                    <br />
                                    <strong>Regular employees:</strong> Credits accrue at the end of each month.
                                    <br /><br />
                                    <strong>This backfills any missing credits from hire date to current date.</strong>
                                </>
                            )}
                            {confirmAction === 'process-carryovers' && (
                                <>
                                    This will process year-end carryovers from {managementYear - 1} to {managementYear}.
                                    Each employee can carry over a maximum of 4 leave credits.
                                    Remaining credits above the cap will be forfeited.
                                    <br /><br />
                                    <strong>Carryover credits are valid until March 31, {managementYear} for leave use or conversion.</strong>
                                    <br /><br />
                                    <strong>This should be run at the start of each year.</strong>
                                </>
                            )}
                            {confirmAction === 'process-cash-conversion' && (
                                <>
                                    This will convert all eligible carryover credits for {managementYear} to cash.
                                    <br /><br />
                                    <strong>Converted credits will no longer be available for VL or SL leave applications.</strong>
                                    <br /><br />
                                    First-regularization carryovers will be skipped (they are not eligible for cash conversion).
                                    <br /><br />
                                    <strong>This action cannot be undone.</strong>
                                </>
                            )}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={isProcessing}>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() => {
                                if (confirmAction === 'process-dry-run') {
                                    handleProcessRegularization(true);
                                } else if (confirmAction === 'process-regularization') {
                                    handleProcessRegularization(false);
                                } else if (confirmAction === 'process-accruals') {
                                    handleProcessMonthlyAccruals();
                                } else if (confirmAction === 'process-carryovers') {
                                    handleProcessYearEndCarryovers();
                                } else if (confirmAction === 'process-cash-conversion') {
                                    handleProcessCashConversions();
                                }
                            }}
                            disabled={isProcessing}
                        >
                            {isProcessing ? (
                                <>
                                    <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                                    Processing...
                                </>
                            ) : (
                                <>
                                    {confirmAction === 'process-dry-run' && 'Run Preview'}
                                    {confirmAction === 'process-regularization' && 'Process Transfers'}
                                    {confirmAction === 'process-accruals' && 'Process Accruals'}
                                    {confirmAction === 'process-carryovers' && 'Process Carryovers'}
                                    {confirmAction === 'process-cash-conversion' && 'Convert to Cash'}
                                </>
                            )}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* Per-Employee Cash Conversion Confirmation */}
            <AlertDialog open={!!convertingEmployeeId} onOpenChange={(open) => {
                if (!open) {
                    setConvertingEmployeeId(null);
                    setConvertingEmployeeName('');
                }
            }}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Convert Carryover to Cash</AlertDialogTitle>
                        <AlertDialogDescription>
                            Are you sure you want to convert <strong>{convertingEmployeeName}</strong>&apos;s carryover credits to cash?
                            <br /><br />
                            <strong>This will make the carryover credits permanently unavailable for VL or SL leave applications.</strong>
                            <br /><br />
                            This action cannot be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={isConverting}>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() => {
                                if (convertingEmployeeId) {
                                    handleConvertSingleCarryover(convertingEmployeeId);
                                }
                            }}
                            disabled={isConverting}
                        >
                            {isConverting ? (
                                <>
                                    <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                                    Converting...
                                </>
                            ) : (
                                'Convert to Cash'
                            )}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* Edit Carryover Credits Dialog */}
            <Dialog open={isEditCarryoverOpen} onOpenChange={(open) => {
                if (!open) {
                    setIsEditCarryoverOpen(false);
                    setEditingEmployee(null);
                    editCarryoverForm.reset();
                }
            }}>
                <DialogContent className="max-w-[90vw] sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Edit Carryover Credits</DialogTitle>
                        <DialogDescription>
                            {editingEmployee && (
                                <>Adjust carryover credits for <strong>{editingEmployee.name}</strong> ({yearFilter})</>
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    {editingEmployee?.carryover_received && (
                        <div className="space-y-4">
                            <div className="grid grid-cols-2 gap-4 p-3 rounded-lg bg-muted/50 border text-sm">
                                <div>
                                    <p className="text-muted-foreground">Current Credits</p>
                                    <p className="font-semibold text-lg">{editingEmployee.carryover_received.credits.toFixed(2)}</p>
                                </div>
                                <div>
                                    <p className="text-muted-foreground">From Year</p>
                                    <p className="font-semibold text-lg">{editingEmployee.carryover_received.from_year}</p>
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="carryover_credits">New Carryover Credits</Label>
                                <Input
                                    id="carryover_credits"
                                    type="number"
                                    step="0.25"
                                    min="0"
                                    max="30"
                                    value={editCarryoverForm.data.carryover_credits}
                                    onChange={(e) => editCarryoverForm.setData('carryover_credits', parseFloat(e.target.value) || 0)}
                                />
                                {editCarryoverForm.errors.carryover_credits && (
                                    <p className="text-sm text-red-600">{editCarryoverForm.errors.carryover_credits}</p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="edit_reason">Reason <span className="text-red-500">*</span></Label>
                                <Textarea
                                    id="edit_reason"
                                    placeholder="Explain why the carryover credits are being adjusted..."
                                    value={editCarryoverForm.data.reason}
                                    onChange={(e) => editCarryoverForm.setData('reason', e.target.value)}
                                    rows={3}
                                />
                                {editCarryoverForm.errors.reason && (
                                    <p className="text-sm text-red-600">{editCarryoverForm.errors.reason}</p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="edit_notes">Notes (optional)</Label>
                                <Textarea
                                    id="edit_notes"
                                    placeholder="Any additional notes..."
                                    value={editCarryoverForm.data.notes}
                                    onChange={(e) => editCarryoverForm.setData('notes', e.target.value)}
                                    rows={2}
                                />
                            </div>

                            {editCarryoverForm.data.carryover_credits < editingEmployee.carryover_received.credits && (
                                <div className="flex items-start gap-2 p-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-700 text-sm dark:bg-amber-950 dark:border-amber-800 dark:text-amber-200">
                                    <AlertTriangle className="h-4 w-4 mt-0.5 shrink-0" />
                                    <p>Reducing carryover credits may trigger cascading adjustments to monthly credit records if the consumed amount exceeds the new value.</p>
                                </div>
                            )}

                            {editingEmployee.pending_count > 0 && (
                                <div className="flex items-start gap-2 p-3 rounded-lg bg-blue-50 border border-blue-200 text-blue-700 text-sm dark:bg-blue-950 dark:border-blue-800 dark:text-blue-200">
                                    <Info className="h-4 w-4 mt-0.5 shrink-0" />
                                    <div>
                                        <p className="font-medium">Pending Leave Requests</p>
                                        <p>
                                            This employee has <strong>{editingEmployee.pending_count}</strong> pending leave request(s) totaling{' '}
                                            <strong>{editingEmployee.pending_credits.toFixed(2)}</strong> credit(s) awaiting approval.
                                            {editCarryoverForm.data.carryover_credits < editingEmployee.carryover_received.credits && (
                                                <> If approved, the effective balance would be further reduced.</>
                                            )}
                                        </p>
                                    </div>
                                </div>
                            )}

                            {editingEmployee.pending_count > 0 && editingEmployee.balance - (editingEmployee.carryover_received.credits - editCarryoverForm.data.carryover_credits) < editingEmployee.pending_credits && (
                                <label className="flex items-start gap-2 p-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-700 text-sm cursor-pointer dark:bg-amber-950 dark:border-amber-800 dark:text-amber-200">
                                    <input
                                        type="checkbox"
                                        checked={pendingAcknowledged}
                                        onChange={(e) => setPendingAcknowledged(e.target.checked)}
                                        className="mt-0.5 rounded border-amber-300"
                                    />
                                    <span>I understand this may cause insufficient balance for pending leave requests.</span>
                                </label>
                            )}
                        </div>
                    )}
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setIsEditCarryoverOpen(false)} disabled={editCarryoverForm.processing}>
                            Cancel
                        </Button>
                        <Button onClick={submitEditCarryover} disabled={
                            editCarryoverForm.processing
                            || !editCarryoverForm.data.reason
                            || (editingEmployee != null && editingEmployee.carryover_received !== null && editingEmployee.pending_count > 0 && editingEmployee.balance - (editingEmployee.carryover_received.credits - editCarryoverForm.data.carryover_credits) < editingEmployee.pending_credits && !pendingAcknowledged)
                        }>
                            {editCarryoverForm.processing ? (
                                <><Loader2 className="h-4 w-4 mr-2 animate-spin" /> Saving...</>
                            ) : (
                                'Save Changes'
                            )}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
