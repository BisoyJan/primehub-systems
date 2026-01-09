import React, { useState, useCallback, useMemo, useEffect } from 'react';
import { Head, Link, router } from '@inertiajs/react';
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
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import { Download, Eye, Filter, ChevronsUpDown, Check, Banknote } from 'lucide-react';
import { toast } from 'sonner';
import { useFlashMessage, usePageLoading, usePageMeta } from '@/hooks';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import PaginationNav from '@/components/pagination-nav';
import { index as leaveIndexRoute } from '@/routes/leave-requests';
import { format } from 'date-fns';
import { Progress } from '@/components/ui/progress';

interface CarryoverData {
    credits: number;
    to_year: number;
    is_processed: boolean;
    cash_converted: boolean;
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

interface Props {
    creditsData: {
        data: CreditData[];
        links?: PaginationLink[];
        meta?: PaginationMeta;
        total?: number;
    };
    allEmployees: Employee[];
    filters: {
        year: number;
        search: string;
        role: string;
        eligibility: string;
    };
    availableYears: number[];
}

export default function Index({ creditsData, allEmployees, filters, availableYears }: Props) {
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

    // Popover search state
    const [isEmployeePopoverOpen, setIsEmployeePopoverOpen] = useState(false);
    const [employeeSearchQuery, setEmployeeSearchQuery] = useState('');

    // Export dialog state
    const [showExportDialog, setShowExportDialog] = useState(false);
    const [exportYear, setExportYear] = useState(new Date().getFullYear());
    const [isExporting, setIsExporting] = useState(false);
    const [exportProgress, setExportProgress] = useState({ percent: 0, status: '' });

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

    const showClearFilters = selectedEmployeeId || roleFilter !== 'all' || eligibilityFilter !== 'all' || yearFilter !== new Date().getFullYear().toString();

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
        return params;
    }, [selectedEmployeeId, yearFilter, roleFilter, eligibilityFilter]);

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

            const { job_id } = await response.json();
            pollExportProgress(job_id);
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
                                <SelectTrigger className="w-full sm:w-[140px]">
                                    <SelectValue placeholder="Eligibility" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All</SelectItem>
                                    <SelectItem value="eligible">Eligible</SelectItem>
                                    <SelectItem value="not_eligible">Not Eligible</SelectItem>
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
                                <TableRow>
                                    <TableHead>Employee</TableHead>
                                    <TableHead>Role</TableHead>
                                    <TableHead>Hire Date</TableHead>
                                    <TableHead className="text-center">Eligibility</TableHead>
                                    <TableHead className="text-right">Rate/Month</TableHead>
                                    <TableHead className="text-right">Earned</TableHead>
                                    <TableHead className="text-right">Used</TableHead>
                                    <TableHead className="text-right">Balance</TableHead>
                                    <TableHead className="text-center">Carryover (Cash)</TableHead>
                                    <TableHead className="text-center">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {!creditsData?.data || creditsData.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={10} className="text-center py-8 text-muted-foreground">
                                            No employees found
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    creditsData.data.map((employee) => (
                                        <TableRow key={employee.id}>
                                            <TableCell>
                                                <div>
                                                    <p className="font-medium">{employee.name}</p>
                                                    <p className="text-xs text-muted-foreground">{employee.email}</p>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant={getRoleBadgeVariant(employee.role)}>
                                                    {employee.role}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>{format(new Date(employee.hired_date), 'MMM d, yyyy')}</TableCell>
                                            <TableCell className="text-center">
                                                {employee.is_eligible ? (
                                                    <Badge variant="default" className="bg-green-600">Eligible</Badge>
                                                ) : (
                                                    <Badge variant="secondary">
                                                        {format(new Date(employee.eligibility_date), 'MMM d, yyyy')}
                                                    </Badge>
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
                                            <TableCell className="text-center">
                                                {employee.carryover ? (
                                                    <TooltipProvider>
                                                        <Tooltip>
                                                            <TooltipTrigger asChild>
                                                                <Badge 
                                                                    variant="outline" 
                                                                    className={employee.carryover.cash_converted 
                                                                        ? "border-green-500 text-green-600 bg-green-50" 
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
                                                                <p>To {employee.carryover.to_year} (for cash)</p>
                                                                <p className="text-xs text-muted-foreground">
                                                                    {employee.carryover.cash_converted 
                                                                        ? 'Cash converted' 
                                                                        : employee.carryover.is_processed
                                                                            ? 'Pending conversion'
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
                                                <Link href={`/form-requests/leave-requests/credits/${employee.id}?year=${yearFilter}`}>
                                                    <Button variant="ghost" size="sm">
                                                        <Eye className="h-4 w-4" />
                                                    </Button>
                                                </Link>
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
                                            <p className="text-xs text-muted-foreground">{employee.email}</p>
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
                                            <p className="text-muted-foreground">Status</p>
                                            {employee.is_eligible ? (
                                                <Badge variant="default" className="bg-green-600">Eligible</Badge>
                                            ) : (
                                                <Badge variant="secondary">Not Eligible</Badge>
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
                                        <div className={`flex items-center justify-between p-2 rounded-md border ${
                                            employee.carryover.cash_converted 
                                                ? 'bg-green-50 border-green-200' 
                                                : employee.carryover.is_processed
                                                    ? 'bg-amber-50 border-amber-200'
                                                    : 'bg-blue-50 border-blue-200'
                                        }`}>
                                            <div className="flex items-center gap-2">
                                                <Banknote className={`h-4 w-4 ${
                                                    employee.carryover.cash_converted 
                                                        ? 'text-green-600' 
                                                        : employee.carryover.is_processed
                                                            ? 'text-amber-600'
                                                            : 'text-blue-600'
                                                }`} />
                                                <span className={`text-sm ${
                                                    employee.carryover.cash_converted 
                                                        ? 'text-green-700' 
                                                        : employee.carryover.is_processed
                                                            ? 'text-amber-700'
                                                            : 'text-blue-700'
                                                }`}>
                                                    Carryover to {employee.carryover.to_year}
                                                </span>
                                            </div>
                                            <Badge 
                                                variant="outline" 
                                                className={employee.carryover.cash_converted 
                                                    ? "border-green-500 text-green-600" 
                                                    : employee.carryover.is_processed
                                                        ? "border-amber-500 text-amber-600"
                                                        : "border-blue-500 text-blue-600"
                                                }
                                            >
                                                {employee.carryover.credits.toFixed(2)} credits
                                            </Badge>
                                        </div>
                                    )}

                                    <Link href={`/form-requests/leave-requests/credits/${employee.id}?year=${yearFilter}`}>
                                        <Button variant="outline" size="sm" className="w-full">
                                            <Eye className="h-4 w-4 mr-2" />
                                            View History
                                        </Button>
                                    </Link>
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
        </AppLayout>
    );
}
