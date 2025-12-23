import React, { useState, useEffect } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { format, parseISO } from 'date-fns';
import { toast } from 'sonner';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Checkbox } from '@/components/ui/checkbox';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion';
import { AlertCircle, Calendar, CreditCard, Check, ChevronsUpDown, AlertTriangle } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { index as leaveIndexRoute, create as leaveCreateRoute, store as leaveStoreRoute } from '@/routes/leave-requests';

interface CreditsSummary {
    year: number;
    is_eligible: boolean;
    eligibility_date: string | null;
    monthly_rate: number;
    total_earned: number;
    total_used: number;
    balance: number;
    pending_credits: number;
}

interface AttendanceViolation {
    id: number;
    shift_date: string;
    point_type: string;
    points: number;
    violation_details: string;
    expires_at: string;
}

interface Employee {
    id: number;
    name: string;
    email: string;
}

interface ExistingLeaveRequest {
    id: number;
    leave_type: string;
    start_date: string;
    end_date: string;
    status: string;
}

interface Props {
    creditsSummary: CreditsSummary;
    attendancePoints: number;
    attendanceViolations: AttendanceViolation[];
    hasRecentAbsence: boolean;
    hasPendingRequests: boolean;
    nextEligibleLeaveDate: string | null;
    campaigns: string[];
    selectedCampaign: string | null;
    twoWeeksFromNow: string;
    isAdmin: boolean;
    employees: Employee[];
    selectedEmployeeId: number;
    canOverrideShortNotice: boolean;
    existingLeaveRequests: ExistingLeaveRequest[];
}

export default function Create({
    creditsSummary,
    attendancePoints,
    attendanceViolations,
    hasRecentAbsence,
    nextEligibleLeaveDate,
    campaigns,
    selectedCampaign,
    twoWeeksFromNow,
    isAdmin,
    employees,
    selectedEmployeeId,
    canOverrideShortNotice = false,
    existingLeaveRequests = [],
}: Props) {
    const { data, setData, post, processing, errors } = useForm({
        employee_id: selectedEmployeeId,
        leave_type: '',
        start_date: '',
        end_date: '',
        reason: '',
        campaign_department: selectedCampaign || '',
        medical_cert_submitted: false,
        short_notice_override: false,
    });

    const [selectedEmployee, setSelectedEmployee] = useState<number>(selectedEmployeeId);
    const [searchQuery, setSearchQuery] = useState<string>('');
    const [isEmployeePopoverOpen, setIsEmployeePopoverOpen] = useState(false);

    const [calculatedDays, setCalculatedDays] = useState<number>(0);
    const [validationWarnings, setValidationWarnings] = useState<string[]>([]);
    const [shortNoticeWarning, setShortNoticeWarning] = useState<string | null>(null);
    const [weekendError, setWeekendError] = useState<{ start: string | null; end: string | null }>({ start: null, end: null });
    const [slCreditInfo, setSlCreditInfo] = useState<string | null>(null);

    // Helper function to check if a date is a weekend
    const isWeekend = (dateString: string): boolean => {
        if (!dateString) return false;
        const date = new Date(dateString);
        const day = date.getDay();
        return day === 0 || day === 6; // 0 = Sunday, 6 = Saturday
    };

    // Helper function to get the day name
    const getDayName = (dateString: string): string => {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { weekday: 'long' });
    };

    // Get date constraints for Sick Leave (7 days back to today)
    const getSlMinDate = (): string => {
        const date = new Date();
        date.setDate(date.getDate() - 7);
        return date.toISOString().split('T')[0];
    };

    const getTodayDate = (): string => {
        return new Date().toISOString().split('T')[0];
    };

    // Handle start date change with weekend validation
    const handleStartDateChange = (value: string) => {
        if (isWeekend(value)) {
            setWeekendError(prev => ({ ...prev, start: `${getDayName(value)} is a weekend. Please select a weekday.` }));
        } else {
            setWeekendError(prev => ({ ...prev, start: null }));
        }
        setData('start_date', value);
    };

    // Handle end date change with weekend validation
    const handleEndDateChange = (value: string) => {
        if (isWeekend(value)) {
            setWeekendError(prev => ({ ...prev, end: `${getDayName(value)} is a weekend. Please select a weekday.` }));
        } else {
            setWeekendError(prev => ({ ...prev, end: null }));
        }
        setData('end_date', value);
    };

    // Update campaign_department when selectedCampaign changes
    useEffect(() => {
        if (selectedCampaign && !data.campaign_department) {
            setData('campaign_department', selectedCampaign);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedCampaign]);

    // Filter employees based on search query
    const filteredEmployees = employees.filter((employee) => {
        const query = searchQuery.toLowerCase();
        return (
            employee.name.toLowerCase().includes(query) ||
            employee.email.toLowerCase().includes(query)
        );
    });

    // Get selected employee details
    const selectedEmployeeDetails = employees.find(emp => emp.id === selectedEmployee);

    // Handle employee selection change for admins
    const handleEmployeeChange = (employeeId: number) => {
        setSelectedEmployee(employeeId);
        setIsEmployeePopoverOpen(false);
        setSearchQuery('');
        // Refresh the page with new employee data
        router.get(leaveCreateRoute().url, { employee_id: employeeId }, {
            preserveState: false,
            preserveScroll: true,
        });
    };

    // Calculate working days when dates change (excluding weekends)
    useEffect(() => {
        if (data.start_date && data.end_date) {
            try {
                const start = parseISO(data.start_date);
                const end = parseISO(data.end_date);

                // Count only weekdays (Monday-Friday)
                let workingDays = 0;
                const currentDate = new Date(start);

                while (currentDate <= end) {
                    const dayOfWeek = currentDate.getDay();
                    // 0 = Sunday, 6 = Saturday, exclude these
                    // 1-5 = Monday-Friday, count these
                    if (dayOfWeek >= 1 && dayOfWeek <= 5) {
                        workingDays++;
                    }
                    currentDate.setDate(currentDate.getDate() + 1);
                }

                setCalculatedDays(workingDays);
            } catch {
                setCalculatedDays(0);
            }
        } else {
            setCalculatedDays(0);
        }
    }, [data.start_date, data.end_date]);

    // Real-time validation warnings
    useEffect(() => {
        const warnings: string[] = [];
        let shortNotice: string | null = null;

        // Check eligibility (skip warning for SL - allow submission without credits)
        if (!creditsSummary.is_eligible && ['VL', 'BL'].includes(data.leave_type)) {
            const eligibilityDateStr = creditsSummary.eligibility_date
                ? format(parseISO(creditsSummary.eligibility_date), 'MMMM d, yyyy')
                : 'N/A';
            warnings.push(
                `You are not eligible to use leave credits yet. Eligible on ${eligibilityDateStr}.`
            );
        }

        // Check 2-week notice (only for VL and BL, not SL as it's unpredictable)
        // Track separately for override capability
        if (data.start_date && ['VL', 'BL'].includes(data.leave_type)) {
            const start = new Date(data.start_date);
            start.setHours(0, 0, 0, 0);
            const twoWeeks = new Date(twoWeeksFromNow);
            twoWeeks.setHours(0, 0, 0, 0);
            if (start.getTime() < twoWeeks.getTime()) {
                const warningMsg = `Leave must be requested at least 2 weeks in advance. Earliest date: ${format(twoWeeks, 'MMMM d, yyyy')}`;
                // Only add to warnings if not overridden
                if (!data.short_notice_override) {
                    warnings.push(warningMsg);
                }
                shortNotice = warningMsg;
            }
        }

        // Check attendance points for VL/BL
        if (['VL', 'BL'].includes(data.leave_type) && attendancePoints > 6) {
            warnings.push(
                `You have ${attendancePoints} attendance points (must be ≤6 for Vacation Leave).`
            );
        }

        // Check recent absence for VL/BL
        if (['VL', 'BL'].includes(data.leave_type) && hasRecentAbsence && nextEligibleLeaveDate) {
            warnings.push(
                `You had an absence in the last 30 days. Next eligible date: ${format(parseISO(nextEligibleLeaveDate), 'MMMM d, yyyy')}`
            );
        }

        // Check leave credits balance (only block for VL/BL, SL can proceed without credits)
        // Use available balance (total balance - pending credits) for validation
        if (['VL', 'BL'].includes(data.leave_type) && calculatedDays > 0) {
            const availableBalance = Math.max(0, creditsSummary.balance - creditsSummary.pending_credits);
            if (availableBalance < calculatedDays) {
                warnings.push(
                    `Insufficient leave credits. Available: ${availableBalance.toFixed(2)} days, Requested: ${calculatedDays} days`
                );
            }
        }

        // Check for overlapping dates with existing pending/approved leave requests
        if (data.start_date && data.end_date && existingLeaveRequests.length > 0) {
            const newStart = new Date(data.start_date);
            const newEnd = new Date(data.end_date);
            newStart.setHours(0, 0, 0, 0);
            newEnd.setHours(0, 0, 0, 0);

            for (const existing of existingLeaveRequests) {
                const existingStart = new Date(existing.start_date);
                const existingEnd = new Date(existing.end_date);
                existingStart.setHours(0, 0, 0, 0);
                existingEnd.setHours(0, 0, 0, 0);

                // Check if dates overlap
                if (newStart <= existingEnd && newEnd >= existingStart) {
                    const startStr = format(existingStart, 'MMM d, yyyy');
                    const endStr = format(existingEnd, 'MMM d, yyyy');
                    const status = existing.status.charAt(0).toUpperCase() + existing.status.slice(1);
                    warnings.push(
                        `Selected dates overlap with an existing ${status} ${existing.leave_type} request (${startStr} to ${endStr}).`
                    );
                    break; // Only show one overlap warning
                }
            }
        }

        setValidationWarnings(warnings);
        setShortNoticeWarning(shortNotice);
    }, [
        data.leave_type,
        data.start_date,
        data.end_date,
        data.short_notice_override,
        creditsSummary,
        attendancePoints,
        hasRecentAbsence,
        nextEligibleLeaveDate,
        calculatedDays,
        existingLeaveRequests,
        twoWeeksFromNow,
    ]);

    // Update SL credit info message
    useEffect(() => {
        if (data.leave_type !== 'SL') {
            setSlCreditInfo(null);
            return;
        }

        if (!creditsSummary.is_eligible) {
            setSlCreditInfo('Leave credits will NOT be deducted - You are not yet eligible for leave credits');
        } else if (creditsSummary.balance < calculatedDays && calculatedDays > 0) {
            setSlCreditInfo('Leave credits will NOT be deducted - Insufficient balance');
        } else if (!data.medical_cert_submitted) {
            setSlCreditInfo('Leave credits will NOT be deducted - No medical certificate');
        } else {
            setSlCreditInfo(null);
        }
    }, [data.leave_type, data.medical_cert_submitted, creditsSummary, calculatedDays]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        post(leaveStoreRoute().url, {
            onSuccess: () => {
                toast.success('Leave request submitted successfully!', {
                    description: 'Your request has been sent for approval.',
                });
            },
            onError: (errors) => {
                if (errors.error) {
                    toast.error('Failed to submit leave request', {
                        description: errors.error as string,
                    });
                } else if (errors.validation) {
                    toast.error('Validation failed', {
                        description: 'Please check the form for errors.',
                    });
                } else {
                    toast.error('Failed to submit leave request', {
                        description: 'Please try again.',
                    });
                }
            },
        });
    };

    const requiresCredits = ['VL', 'SL'].includes(data.leave_type);

    return (
        <AppLayout>
            <Head title="Request Leave" />

            <div className="container mx-auto px-4 py-8 max-w-4xl">
                <div className="mb-6">
                    <h1 className="text-3xl font-bold">Request Leave</h1>
                    <p className="text-muted-foreground mt-2">
                        Submit a leave request for approval
                    </p>
                </div>

                {/* Leave Credits Summary */}
                {creditsSummary.is_eligible && (
                    <Card className="mb-6">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <CreditCard className="h-5 w-5" />
                                Leave Credits Balance
                            </CardTitle>
                            <CardDescription>
                                Year {creditsSummary.year} • Credits reset annually and do not carry over
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
                                <div>
                                    <p className="text-sm text-muted-foreground">Total Balance</p>
                                    <p className="text-2xl font-bold">{creditsSummary.balance.toFixed(2)}</p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">Pending Requests</p>
                                    <p className="text-2xl font-bold text-yellow-600">
                                        {creditsSummary.pending_credits > 0 ? `-${creditsSummary.pending_credits.toFixed(2)}` : '0'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">Available</p>
                                    <p className="text-2xl font-bold text-blue-600">
                                        {Math.max(0, creditsSummary.balance - creditsSummary.pending_credits).toFixed(2)}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">This Request</p>
                                    <p className="text-2xl font-bold text-orange-600">
                                        {requiresCredits && calculatedDays > 0 ? `-${calculatedDays}` : '0'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">After Submit</p>
                                    <p className="text-2xl font-bold text-green-600">
                                        {Math.max(0, creditsSummary.balance - creditsSummary.pending_credits - (requiresCredits ? calculatedDays : 0)).toFixed(2)}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">Monthly Rate</p>
                                    <p className="text-2xl font-bold">{creditsSummary.monthly_rate}</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {!creditsSummary.is_eligible && (
                    <Alert className="mb-6">
                        <AlertCircle className="h-4 w-4" />
                        <AlertTitle>Not Eligible Yet</AlertTitle>
                        <AlertDescription>
                            {creditsSummary.eligibility_date ? (
                                <>
                                    You will be eligible to use leave credits on{' '}
                                    <strong className="text-orange-600 dark:text-orange-400">
                                        {format(parseISO(creditsSummary.eligibility_date), 'MMMM d, yyyy')}
                                    </strong>
                                    You can still apply for non-credited leave types (SPL, LOA, LDV).
                                </>
                            ) : (
                                <>
                                    Eligibility date not set. Please contact HR to update your hire date. You can still
                                    apply for non-credited leave types (SPL, LOA, LDV).
                                </>
                            )}
                        </AlertDescription>
                    </Alert>
                )}

                {/* Attendance Violations Display */}
                {attendanceViolations.length > 0 && (
                    <Card className="mb-6 border-orange-200 dark:border-orange-800">
                        <CardHeader className="pb-3">
                            <div className="flex items-center gap-2 text-orange-700 dark:text-orange-400">
                                <AlertTriangle className="h-5 w-5" />
                                <CardTitle>Active Attendance Violations ({attendancePoints.toFixed(2)} points)</CardTitle>
                            </div>
                            <CardDescription>
                                {attendancePoints > 6
                                    ? 'You have more than 6 attendance points. Vacation/Bereavement leave requests may be denied.'
                                    : 'Current attendance violations that may affect leave approval'}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Accordion type="single" collapsible className="w-full">
                                <AccordionItem value="violations" className="border-0">
                                    <AccordionTrigger className="py-2 hover:no-underline">
                                        <span className="text-sm font-medium">
                                            View {attendanceViolations.length} violation{attendanceViolations.length !== 1 ? 's' : ''}
                                        </span>
                                    </AccordionTrigger>
                                    <AccordionContent className="pt-2">
                                        <div className="space-y-3">
                                            {attendanceViolations.map((violation) => {
                                                const getPointTypeBadge = (type: string) => {
                                                    const variants: Record<string, { className: string; label: string }> = {
                                                        whole_day_absence: { className: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-100', label: 'Whole Day' },
                                                        half_day_absence: { className: 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-100', label: 'Half Day' },
                                                        undertime: { className: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-100', label: 'Undertime' },
                                                        tardy: { className: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-100', label: 'Tardy' },
                                                    };
                                                    const variant = variants[type] || { className: 'bg-gray-100 text-gray-800', label: type };
                                                    return <Badge className={variant.className}>{variant.label}</Badge>;
                                                };

                                                return (
                                                    <div key={violation.id} className="p-3 border rounded-lg bg-muted/50">
                                                        <div className="flex items-start justify-between gap-2 mb-2">
                                                            <div className="flex items-center gap-2">
                                                                {getPointTypeBadge(violation.point_type)}
                                                                <span className="text-sm font-medium">
                                                                    {format(parseISO(violation.shift_date), 'MMM d, yyyy')}
                                                                </span>
                                                            </div>
                                                            <span className="text-sm font-bold text-red-600 dark:text-red-400">
                                                                {Number(violation.points).toFixed(2)} pts
                                                            </span>
                                                        </div>
                                                        <p className="text-xs text-muted-foreground">
                                                            {violation.violation_details}
                                                        </p>
                                                        <p className="text-xs text-muted-foreground mt-1">
                                                            Expires: {format(parseISO(violation.expires_at), 'MMM d, yyyy')}
                                                        </p>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                        {attendancePoints > 6 && (
                                            <Alert variant="destructive" className="mt-4">
                                                <AlertCircle className="h-4 w-4" />
                                                <AlertTitle>High Attendance Points</AlertTitle>
                                                <AlertDescription>
                                                    Your attendance points exceed 6.0. This may result in automatic denial of Vacation Leave (VL) and Bereavement Leave (BL) requests. Please work on improving attendance or wait for points to expire.
                                                </AlertDescription>
                                            </Alert>
                                        )}
                                    </AccordionContent>
                                </AccordionItem>
                            </Accordion>
                        </CardContent>
                    </Card>
                )}

                {/* Validation Warnings */}
                {validationWarnings.length > 0 && (
                    <Alert variant="destructive" className="mb-6">
                        <AlertCircle className="h-4 w-4" />
                        <AlertTitle>Validation Issues</AlertTitle>
                        <AlertDescription>
                            <ul className="list-disc list-inside space-y-1">
                                {validationWarnings.map((warning, idx) => (
                                    <li key={idx}>{warning}</li>
                                ))}
                            </ul>
                        </AlertDescription>
                    </Alert>
                )}

                {/* Short Notice Override Option (Admin/Super Admin Only) */}
                {canOverrideShortNotice && shortNoticeWarning && !data.short_notice_override && (
                    <Alert className="mb-6 border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950">
                        <AlertTriangle className="h-4 w-4 text-amber-600" />
                        <AlertTitle className="text-amber-800 dark:text-amber-200">Short Notice Leave Request</AlertTitle>
                        <AlertDescription className="text-amber-700 dark:text-amber-300">
                            <p className="mb-3">{shortNoticeWarning}</p>
                            <p className="mb-3 text-sm">As Admin/Super Admin, you can override this requirement.</p>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="border-amber-500 text-amber-700 hover:bg-amber-100 dark:border-amber-600 dark:text-amber-300 dark:hover:bg-amber-900"
                                onClick={() => setData('short_notice_override', true)}
                            >
                                Override 2-Week Notice Requirement
                            </Button>
                        </AlertDescription>
                    </Alert>
                )}

                {/* Short Notice Override Active */}
                {data.short_notice_override && (
                    <Alert className="mb-6 border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-950">
                        <Check className="h-4 w-4 text-blue-600" />
                        <AlertTitle className="text-blue-800 dark:text-blue-200">Short Notice Override Active</AlertTitle>
                        <AlertDescription className="text-blue-700 dark:text-blue-300">
                            <p className="mb-2">The 2-week advance notice requirement has been overridden by Admin.</p>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-200 p-0 h-auto"
                                onClick={() => setData('short_notice_override', false)}
                            >
                                Remove Override
                            </Button>
                        </AlertDescription>
                    </Alert>
                )}

                {/* Leave Request Form */}
                <Card>
                    <CardHeader>
                        <CardTitle>Leave Details</CardTitle>
                        <CardDescription>Fill in the information below</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit} className="space-y-6">
                            {/* Employee Selection (Admin Only) */}
                            {isAdmin && (
                                <div className="space-y-2">
                                    <Label htmlFor="employee_id">
                                        Employee <span className="text-red-500">*</span>
                                    </Label>
                                    <Popover open={isEmployeePopoverOpen} onOpenChange={setIsEmployeePopoverOpen}>
                                        <PopoverTrigger asChild>
                                            <Button
                                                variant="outline"
                                                role="combobox"
                                                aria-expanded={isEmployeePopoverOpen}
                                                className="w-full justify-between font-normal"
                                            >
                                                {selectedEmployeeDetails ? (
                                                    <span className="truncate">
                                                        {selectedEmployeeDetails.name} ({selectedEmployeeDetails.email})
                                                    </span>
                                                ) : (
                                                    <span className="text-muted-foreground">Search employee...</span>
                                                )}
                                                <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                            </Button>
                                        </PopoverTrigger>
                                        <PopoverContent className="w-full p-0" align="start">
                                            <Command shouldFilter={false}>
                                                <CommandInput
                                                    placeholder="Search by name or email..."
                                                    value={searchQuery}
                                                    onValueChange={setSearchQuery}
                                                />
                                                <CommandList>
                                                    <CommandEmpty>No employee found.</CommandEmpty>
                                                    <CommandGroup>
                                                        {filteredEmployees.map((employee) => (
                                                            <CommandItem
                                                                key={employee.id}
                                                                value={`${employee.name} ${employee.email}`}
                                                                onSelect={() => handleEmployeeChange(employee.id)}
                                                                className="cursor-pointer"
                                                            >
                                                                <Check
                                                                    className={`mr-2 h-4 w-4 ${selectedEmployee === employee.id
                                                                        ? 'opacity-100'
                                                                        : 'opacity-0'
                                                                        }`}
                                                                />
                                                                <div className="flex flex-col">
                                                                    <span className="font-medium">{employee.name}</span>
                                                                    <span className="text-xs text-muted-foreground">{employee.email}</span>
                                                                </div>
                                                            </CommandItem>
                                                        ))}
                                                    </CommandGroup>
                                                </CommandList>
                                            </Command>
                                        </PopoverContent>
                                    </Popover>
                                    {errors.employee_id && (
                                        <p className="text-sm text-red-500">{errors.employee_id}</p>
                                    )}
                                    <p className="text-xs text-muted-foreground">
                                        Search and select the employee for whom you are creating this leave request
                                    </p>
                                </div>
                            )}

                            {/* Leave Type */}
                            <div className="space-y-2">
                                <Label htmlFor="leave_type">
                                    Leave Type <span className="text-red-500">*</span>
                                </Label>
                                <Select
                                    value={data.leave_type}
                                    onValueChange={(value) => setData('leave_type', value)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select leave type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="VL">Vacation Leave (VL)</SelectItem>
                                        <SelectItem disabled value="SL">Sick Leave (SL)</SelectItem>  {/* SL is disabled for new requests */}
                                        <SelectItem value="BL">Bereavement Leave (BL)</SelectItem>
                                        <SelectItem value="SPL">Solo Parent Leave (SPL)</SelectItem>
                                        <SelectItem value="LOA">Leave of Absence (LOA)</SelectItem>
                                        <SelectItem value="LDV">
                                            Leave Due to Domestic Violence (LDV)
                                        </SelectItem>
                                        <SelectItem value="UPTO">
                                            Unpaid Personal Time Off (UPTO)
                                        </SelectItem>
                                        <SelectItem value="ML">Maternity Leave (ML)</SelectItem>
                                    </SelectContent>
                                </Select>
                                {errors.leave_type && (
                                    <p className="text-sm text-red-500">{errors.leave_type}</p>
                                )}
                                {data.leave_type && (
                                    <p className="text-xs text-muted-foreground">
                                        {requiresCredits
                                            ? '✓ Deducts from leave credits'
                                            : '○ Does not deduct from leave credits'}
                                    </p>
                                )}
                            </div>

                            {/* Date Range */}
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <Label htmlFor="start_date">
                                        Start Date <span className="text-red-500">*</span>
                                    </Label>
                                    <Input
                                        id="start_date"
                                        type="date"
                                        value={data.start_date}
                                        onChange={(e) => handleStartDateChange(e.target.value)}
                                        min={data.leave_type === 'SL' ? getSlMinDate() : new Date().toISOString().split('T')[0]}
                                        max={data.leave_type === 'SL' ? getTodayDate() : undefined}
                                        className={weekendError.start ? 'border-red-500' : ''}
                                    />
                                    {weekendError.start && (
                                        <p className="text-sm text-red-500">{weekendError.start}</p>
                                    )}
                                    {errors.start_date && (
                                        <p className="text-sm text-red-500">{errors.start_date}</p>
                                    )}
                                    {data.leave_type === 'SL' ? (
                                        <p className="text-xs text-muted-foreground">Sick Leave: Select from last 7 days to today</p>
                                    ) : (
                                        <p className="text-xs text-muted-foreground">Weekends (Sat/Sun) are not allowed</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="end_date">
                                        End Date <span className="text-red-500">*</span>
                                    </Label>
                                    <Input
                                        id="end_date"
                                        type="date"
                                        value={data.end_date}
                                        onChange={(e) => handleEndDateChange(e.target.value)}
                                        min={data.leave_type === 'SL' ? (data.start_date || getSlMinDate()) : (data.start_date || new Date().toISOString().split('T')[0])}
                                        max={data.leave_type === 'SL' ? getTodayDate() : undefined}
                                        className={weekendError.end ? 'border-red-500' : ''}
                                    />
                                    {weekendError.end && (
                                        <p className="text-sm text-red-500">{weekendError.end}</p>
                                    )}
                                    {errors.end_date && (
                                        <p className="text-sm text-red-500">{errors.end_date}</p>
                                    )}
                                    {data.leave_type === 'SL' ? (
                                        <p className="text-xs text-muted-foreground">Sick Leave: Cannot exceed today</p>
                                    ) : (
                                        <p className="text-xs text-muted-foreground">Weekends (Sat/Sun) are not allowed</p>
                                    )}
                                </div>
                            </div>

                            {/* Calculated Days Display */}
                            {calculatedDays > 0 && (
                                <Alert>
                                    <Calendar className="h-4 w-4" />
                                    <AlertTitle>Duration</AlertTitle>
                                    <AlertDescription>
                                        <strong>{calculatedDays}</strong> day{calculatedDays !== 1 ? 's' : ''}{' '}
                                        requested
                                    </AlertDescription>
                                </Alert>
                            )}

                            {/* Campaign/Department */}
                            <div className="space-y-2">
                                <Label htmlFor="campaign_department">
                                    Campaign/Department <span className="text-red-500">*</span>
                                </Label>
                                <Select
                                    value={data.campaign_department}
                                    onValueChange={(value) => setData('campaign_department', value)}
                                    disabled={!!selectedCampaign && !!data.campaign_department}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select campaign/department" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {campaigns.map((campaign) => (
                                            <SelectItem key={campaign} value={campaign}>
                                                {campaign}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.campaign_department && (
                                    <p className="text-sm text-red-500">{errors.campaign_department}</p>
                                )}
                                {selectedCampaign && data.campaign_department && (
                                    <p className="text-xs text-muted-foreground">
                                        Auto-selected from employee schedule
                                    </p>
                                )}
                                {!selectedCampaign && (
                                    <p className="text-xs text-muted-foreground">
                                        Employee has no active schedule - please select manually
                                    </p>
                                )}
                            </div>

                            {/* Medical Certificate (for SL) */}
                            {data.leave_type === 'SL' && (
                                <div className="space-y-3">
                                    <div className="flex items-center space-x-2">
                                        <Checkbox
                                            id="medical_cert_submitted"
                                            checked={data.medical_cert_submitted}
                                            onCheckedChange={(checked) =>
                                                setData('medical_cert_submitted', checked as boolean)
                                            }
                                        />
                                        <Label htmlFor="medical_cert_submitted" className="font-normal">
                                            I will submit a medical certificate
                                        </Label>
                                    </div>
                                    {slCreditInfo && (
                                        <Alert className="border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-950">
                                            <AlertCircle className="h-4 w-4 text-blue-600 dark:text-blue-400" />
                                            <AlertDescription className="text-blue-800 dark:text-blue-200">
                                                {slCreditInfo}
                                            </AlertDescription>
                                        </Alert>
                                    )}
                                </div>
                            )}

                            {/* Reason */}
                            <div className="space-y-2">
                                <Label htmlFor="reason">
                                    Reason <span className="text-red-500">*</span>
                                </Label>
                                <Textarea
                                    id="reason"
                                    value={data.reason}
                                    onChange={(e) => setData('reason', e.target.value)}
                                    placeholder="Please provide a detailed reason for your leave request..."
                                    rows={4}
                                    className="resize-none"
                                />
                                {errors.reason && <p className="text-sm text-red-500">{errors.reason}</p>}
                                <p className="text-xs text-muted-foreground">
                                    {data.reason.length}/1000 characters (minimum 10)
                                </p>
                            </div>

                            {/* Form Errors */}
                            {(errors as Record<string, string | string[]>).validation && (
                                <Alert variant="destructive">
                                    <AlertCircle className="h-4 w-4" />
                                    <AlertTitle>Cannot Submit Request</AlertTitle>
                                    <AlertDescription>
                                        <ul className="list-disc list-inside space-y-1">
                                            {Array.isArray((errors as Record<string, string | string[]>).validation) ? (
                                                ((errors as Record<string, string | string[]>).validation as string[]).map((error: string, idx: number) => (
                                                    <li key={idx}>{error}</li>
                                                ))
                                            ) : (
                                                <li>{(errors as Record<string, string | string[]>).validation as string}</li>
                                            )}
                                        </ul>
                                    </AlertDescription>
                                </Alert>
                            )}

                            {/* Submit Button */}
                            <div className="flex gap-4">
                                <Button type="submit" disabled={processing || validationWarnings.length > 0 || !!weekendError.start || !!weekendError.end}>
                                    {processing ? 'Submitting...' : 'Submit Leave Request'}
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => router.visit(leaveIndexRoute().url)}
                                >
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
