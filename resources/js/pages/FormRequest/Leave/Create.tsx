import React, { useState, useEffect, useCallback } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { format, parseISO, addDays, isWeekend as isWeekendDateFns, isBefore, startOfDay } from 'date-fns';
import { toast } from 'sonner';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion';
import { AlertCircle, Calendar, CreditCard, Check, ChevronsUpDown, AlertTriangle, Upload, X, FileImage, Users, Info, Lightbulb, ArrowRight } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import { index as leaveIndexRoute, create as leaveCreateRoute, store as leaveStoreRoute } from '@/routes/leave-requests';

interface PendingRegularizationCredits {
    year: number;
    credits: number;
    months_accrued: number;
    regularization_date: string | null;
    is_pending: boolean;
}

interface CreditsSummary {
    year: number;
    is_eligible: boolean;
    eligibility_date: string | null;
    monthly_rate: number;
    total_earned: number;
    total_used: number;
    balance: number;
    pending_credits: number;
    pending_regularization_credits?: PendingRegularizationCredits;
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

interface CampaignConflict {
    id: number;
    user_name: string;
    leave_type: string;
    start_date: string;
    end_date: string;
    status: string;
    created_at: string;
    overlapping_dates: string[];
}

interface DateSuggestion {
    start_date: string;
    end_date: string;
    conflicts: number;
    label: string;
}

interface Props {
    creditsSummary: CreditsSummary;
    attendancePoints: number;
    attendanceViolations: AttendanceViolation[];
    hasRecentAbsence: boolean;
    hasPendingRequests: boolean;
    nextEligibleLeaveDate: string | null;
    lastAbsenceDate: string | null;
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
    lastAbsenceDate,
    campaigns,
    selectedCampaign,
    twoWeeksFromNow,
    isAdmin,
    employees,
    selectedEmployeeId,
    canOverrideShortNotice = false,
    existingLeaveRequests = [],
}: Props) {
    const { data, setData, post, processing, errors, progress } = useForm({
        employee_id: selectedEmployeeId,
        leave_type: '',
        start_date: '',
        end_date: '',
        reason: '',
        campaign_department: selectedCampaign || '',
        medical_cert_submitted: false,
        medical_cert_file: null as File | null,
        short_notice_override: false,
    });

    const [selectedEmployee, setSelectedEmployee] = useState<number>(selectedEmployeeId);
    const [searchQuery, setSearchQuery] = useState<string>('');
    const [isEmployeePopoverOpen, setIsEmployeePopoverOpen] = useState(false);
    const [medicalCertPreview, setMedicalCertPreview] = useState<string | null>(null);
    const [campaignConflicts, setCampaignConflicts] = useState<CampaignConflict[]>([]);
    const [suggestedDates, setSuggestedDates] = useState<DateSuggestion[]>([]);
    const [absenceWindowInfo, setAbsenceWindowInfo] = useState<string | null>(null);

    const [calculatedDays, setCalculatedDays] = useState<number>(0);
    const [validationWarnings, setValidationWarnings] = useState<string[]>([]);
    const [shortNoticeWarning, setShortNoticeWarning] = useState<string | null>(null);
    const [weekendError, setWeekendError] = useState<{ start: string | null; end: string | null }>({ start: null, end: null });
    const [slCreditInfo, setSlCreditInfo] = useState<string | null>(null);
    const [futureCredits, setFutureCredits] = useState<number>(0);

    // Check if user will be eligible by the selected start date
    const willBeEligibleByStartDate = (): boolean => {
        if (creditsSummary.is_eligible) return true;
        if (!data.start_date || !creditsSummary.eligibility_date) return false;
        const startDate = parseISO(data.start_date);
        const eligibilityDate = parseISO(creditsSummary.eligibility_date);
        return startDate >= eligibilityDate;
    };

    // Calculate projected balance for users who will be eligible by their leave date
    const getProjectedBalance = (): number => {
        if (!data.start_date || !creditsSummary.eligibility_date) return 0;

        const startDate = parseISO(data.start_date);
        const eligibilityDate = parseISO(creditsSummary.eligibility_date);

        // If not eligible by start date, no projected balance
        if (startDate < eligibilityDate) return 0;

        // Start with pending regularization credits (from probation period in previous year)
        let projectedBalance = 0;
        if (creditsSummary.pending_regularization_credits?.is_pending) {
            projectedBalance += creditsSummary.pending_regularization_credits.credits;
        }

        // Calculate months from eligibility to leave start
        // Credits accrue monthly starting from eligibility month
        const eligMonth = eligibilityDate.getMonth();
        const eligYear = eligibilityDate.getFullYear();
        const leaveMonth = startDate.getMonth();
        const leaveYear = startDate.getFullYear();

        // Months of credit accrual between eligibility and leave (after regularization)
        const monthsOfCredits = (leaveYear - eligYear) * 12 + (leaveMonth - eligMonth);
        projectedBalance += Math.max(0, monthsOfCredits) * creditsSummary.monthly_rate;

        return projectedBalance;
    };

    // Helper function to check if a date is a weekend
    const isWeekend = (dateString: string): boolean => {
        if (!dateString) return false;
        const date = parseISO(dateString);
        const day = date.getDay();
        return day === 0 || day === 6; // 0 = Sunday, 6 = Saturday
    };

    // Helper function to get the day name
    const getDayName = (dateString: string): string => {
        const date = parseISO(dateString);
        return date.toLocaleDateString('en-US', { weekday: 'long' });
    };

    // Get date constraints for Sick Leave (3 weeks back for start, 1 month ahead for end)
    const getSlMinDate = (): string => {
        const date = new Date();
        date.setDate(date.getDate() - 21); // 3 weeks ago
        return format(date, 'yyyy-MM-dd');
    };

    const getSlMaxEndDate = (): string => {
        const date = new Date();
        date.setMonth(date.getMonth() + 1); // 1 month from now
        return format(date, 'yyyy-MM-dd');
    };

    // Get date constraints for Solo Parent Leave (2 weeks back for start, 1 month ahead for end)
    const getSplMinDate = (): string => {
        const date = new Date();
        date.setDate(date.getDate() - 14); // 2 weeks ago
        return format(date, 'yyyy-MM-dd');
    };

    const getSplMaxEndDate = (): string => {
        const date = new Date();
        date.setMonth(date.getMonth() + 1); // 1 month from now
        return format(date, 'yyyy-MM-dd');
    };

    // Handle start date change with weekend validation
    const handleStartDateChange = (value: string) => {
        if (isWeekend(value)) {
            setWeekendError(prev => ({ ...prev, start: `${getDayName(value)} is a weekend. Please select a weekday.` }));
        } else {
            setWeekendError(prev => ({ ...prev, start: null }));
        }

        // Clear end date if it's now before the new start date
        if (data.end_date && value) {
            const start = parseISO(value);
            const end = parseISO(data.end_date);
            if (end < start) {
                setData(prev => ({ ...prev, start_date: value, end_date: '' }));
                setWeekendError(prev => ({ ...prev, end: null }));
                return;
            }
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

    // Calculate future credits that will accrue by the leave start date
    const calculateFutureCredits = useCallback((startDate: string): number => {
        if (!startDate) return 0;

        // Check if user will be eligible by the start date (not just current eligibility)
        if (!creditsSummary.is_eligible && creditsSummary.eligibility_date) {
            const leaveStart = parseISO(startDate);
            const eligibilityDate = parseISO(creditsSummary.eligibility_date);
            // If leave start date is before eligibility, no future credits calculation
            if (leaveStart < eligibilityDate) return 0;
        } else if (!creditsSummary.is_eligible) {
            return 0;
        }

        const today = new Date();
        const leaveStart = parseISO(startDate);

        // Get the current month and year
        const currentMonth = today.getMonth(); // 0-indexed
        const currentYear = today.getFullYear();

        // Get the leave start month and year
        const leaveMonth = leaveStart.getMonth();
        const leaveYear = leaveStart.getFullYear();

        // Calculate how many full months will pass before the leave date
        // Credits accrue at the end of each month, so if leave is in Feb, Jan credits will be added
        let monthsToAccrue = 0;

        if (leaveYear > currentYear || (leaveYear === currentYear && leaveMonth > currentMonth)) {
            // Calculate months difference
            monthsToAccrue = (leaveYear - currentYear) * 12 + (leaveMonth - currentMonth);
        }

        return monthsToAccrue * creditsSummary.monthly_rate;
    }, [creditsSummary.is_eligible, creditsSummary.eligibility_date, creditsSummary.monthly_rate]);

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

                // Calculate future credits based on start date
                const projectedCredits = calculateFutureCredits(data.start_date);
                setFutureCredits(projectedCredits);
            } catch {
                setCalculatedDays(0);
                setFutureCredits(0);
            }
        } else {
            setCalculatedDays(0);
            setFutureCredits(0);
        }
    }, [data.start_date, data.end_date, creditsSummary.is_eligible, creditsSummary.monthly_rate, calculateFutureCredits]);

    // Real-time validation warnings
    useEffect(() => {
        const warnings: string[] = [];
        let shortNotice: string | null = null;

        // Check eligibility (skip warning for SL - allow submission without credits)
        // Check if user will be eligible BY the selected start date (not current date)
        if (!creditsSummary.is_eligible && ['VL', 'BL'].includes(data.leave_type)) {
            const eligibilityDateStr = creditsSummary.eligibility_date
                ? format(parseISO(creditsSummary.eligibility_date), 'MMMM d, yyyy')
                : 'N/A';

            // Check if user will be eligible by the start date
            if (data.start_date && creditsSummary.eligibility_date) {
                const startDate = parseISO(data.start_date);
                const eligibilityDate = parseISO(creditsSummary.eligibility_date);
                // Only show warning if start date is BEFORE eligibility date
                if (startDate < eligibilityDate) {
                    warnings.push(
                        `You will not be eligible by the leave start date. Eligible on ${eligibilityDateStr}.`
                    );
                }
                // If start date is on or after eligibility date, user will be eligible - no warning needed
            } else if (!data.start_date) {
                // No start date selected yet, show general eligibility info
                warnings.push(
                    `You are not currently eligible for leave credits. Eligible on ${eligibilityDateStr}. Select a start date on or after this date.`
                );
            }
        }

        // Check 2-week notice (only for VL and BL, not SL as it's unpredictable)
        // Track separately for override capability
        if (data.start_date && ['VL', 'BL'].includes(data.leave_type)) {
            const start = parseISO(data.start_date);
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
        // Use available balance (total balance - pending credits + future credits) for validation
        if (['VL', 'BL'].includes(data.leave_type) && calculatedDays > 0) {
            const projectedCredits = data.start_date ? calculateFutureCredits(data.start_date) : 0;
            const availableBalance = Math.max(0, creditsSummary.balance - creditsSummary.pending_credits + projectedCredits);
            if (availableBalance < calculatedDays) {
                warnings.push(
                    `Insufficient leave credits. Available: ${availableBalance.toFixed(2)} days${projectedCredits > 0 ? ` (includes ${projectedCredits.toFixed(2)} future credits)` : ''}, Requested: ${calculatedDays} days`
                );
            }
        }

        // Check for overlapping dates with existing pending/approved leave requests
        if (data.start_date && data.end_date && existingLeaveRequests.length > 0) {
            const newStart = parseISO(data.start_date);
            const newEnd = parseISO(data.end_date);
            newStart.setHours(0, 0, 0, 0);
            newEnd.setHours(0, 0, 0, 0);

            for (const existing of existingLeaveRequests) {
                const existingStart = parseISO(existing.start_date);
                const existingEnd = parseISO(existing.end_date);
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
        calculateFutureCredits,
    ]);

    // Update SL credit info message
    useEffect(() => {
        if (data.leave_type !== 'SL') {
            setSlCreditInfo(null);
            return;
        }

        if (!creditsSummary.is_eligible) {
            setSlCreditInfo('Leave credits will NOT be deducted - You are not yet eligible for leave credits (less than 6 months employment)');
        } else if (creditsSummary.balance < calculatedDays && calculatedDays > 0) {
            if (data.medical_cert_submitted) {
                setSlCreditInfo(`⚠️ This SL will be converted to UPTO (Unpaid Time Off) - Insufficient credits (balance: ${creditsSummary.balance} days, requesting: ${calculatedDays} days)`);
            } else {
                setSlCreditInfo('Leave credits will NOT be deducted - Insufficient balance. Submit a medical certificate to convert to UPTO.');
            }
        } else if (!data.medical_cert_submitted) {
            setSlCreditInfo('Leave credits will NOT be deducted - No medical certificate submitted');
        } else {
            setSlCreditInfo(null);
        }
    }, [data.leave_type, data.medical_cert_submitted, creditsSummary, calculatedDays]);

    // Check for campaign conflicts (VL and UPTO)
    useEffect(() => {
        const checkConflicts = async () => {
            // Only check for VL and UPTO with valid dates and campaign
            if (!['VL', 'UPTO'].includes(data.leave_type) || !data.start_date || !data.end_date || !data.campaign_department) {
                setCampaignConflicts([]);
                setSuggestedDates([]);
                return;
            }

            try {
                const response = await fetch('/form-requests/leave-requests/api/check-campaign-conflicts', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        campaign_department: data.campaign_department,
                        start_date: data.start_date,
                        end_date: data.end_date,
                        exclude_user_id: selectedEmployeeId,
                    }),
                });

                if (response.ok) {
                    const conflicts = await response.json();
                    setCampaignConflicts(conflicts);

                    // Calculate date suggestions if there are conflicts
                    if (conflicts.length > 0) {
                        calculateDateSuggestions(conflicts, data.start_date, data.end_date, data.campaign_department);
                    } else {
                        setSuggestedDates([]);
                    }
                }
            } catch (error) {
                console.error('Failed to check campaign conflicts:', error);
            }
        };

        // Debounce the API call
        const timeoutId = setTimeout(checkConflicts, 500);
        return () => clearTimeout(timeoutId);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data.leave_type, data.start_date, data.end_date, data.campaign_department, selectedEmployeeId]);

    // Calculate date suggestions with fewer or no conflicts
    const calculateDateSuggestions = async (currentConflicts: CampaignConflict[], startDate: string, endDate: string, campaign: string) => {
        const suggestions: DateSuggestion[] = [];
        const start = parseISO(startDate);
        const end = parseISO(endDate);
        const today = startOfDay(new Date());
        const twoWeeksFromToday = addDays(today, 14);

        // Calculate the number of working days requested
        let requestedDays = 0;
        const currentDate = new Date(start);
        while (currentDate <= end) {
            if (!isWeekendDateFns(currentDate)) {
                requestedDays++;
            }
            currentDate.setDate(currentDate.getDate() + 1);
        }

        // Helper to check conflicts for a date range
        const checkConflictsForRange = async (checkStart: string, checkEnd: string): Promise<number> => {
            try {
                const response = await fetch('/form-requests/leave-requests/api/check-campaign-conflicts', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        campaign_department: campaign,
                        start_date: checkStart,
                        end_date: checkEnd,
                        exclude_user_id: selectedEmployeeId,
                    }),
                });
                if (response.ok) {
                    const conflicts = await response.json();
                    return conflicts.length;
                }
            } catch {
                // Ignore errors
            }
            return 999; // High number to indicate error
        };

        // Helper to calculate end date for given working days
        const calculateEndDate = (startDate: Date, workingDays: number): Date => {
            let count = 0;
            const current = new Date(startDate);
            while (count < workingDays) {
                if (!isWeekendDateFns(current)) {
                    count++;
                }
                if (count < workingDays) {
                    current.setDate(current.getDate() + 1);
                }
            }
            return current;
        };

        // Helper to find next weekday
        const findNextWeekday = (date: Date): Date => {
            const next = new Date(date);
            while (isWeekendDateFns(next)) {
                next.setDate(next.getDate() + 1);
            }
            return next;
        };

        // Generate candidate dates to check
        const candidates: { start: Date; end: Date; label: string }[] = [];

        // Option 1: Two weeks after (minimum required advance notice)
        const twoWeeksAfter = addDays(start, 14);
        const twoWeeksAfterStart = findNextWeekday(twoWeeksAfter);
        // Ensure it's at least 2 weeks from today
        const minStartDate = findNextWeekday(twoWeeksFromToday);
        const actualTwoWeeksStart = isBefore(twoWeeksAfterStart, minStartDate) ? minStartDate : twoWeeksAfterStart;
        const twoWeeksAfterEnd = calculateEndDate(actualTwoWeeksStart, requestedDays);
        candidates.push({ start: actualTwoWeeksStart, end: twoWeeksAfterEnd, label: '2 weeks later' });

        // Option 2: Three weeks after
        const threeWeeksAfter = addDays(start, 21);
        const threeWeeksAfterStart = findNextWeekday(threeWeeksAfter);
        const actualThreeWeeksStart = isBefore(threeWeeksAfterStart, minStartDate) ? minStartDate : threeWeeksAfterStart;
        const threeWeeksAfterEnd = calculateEndDate(actualThreeWeeksStart, requestedDays);
        candidates.push({ start: actualThreeWeeksStart, end: threeWeeksAfterEnd, label: '3 weeks later' });

        // Option 3: Day after current conflicts end (if at least 2 weeks from today)
        const dayAfterEnd = addDays(end, 1);
        const nextStart = findNextWeekday(dayAfterEnd);
        // Only add if it's at least 2 weeks from today
        if (!isBefore(nextStart, minStartDate)) {
            const nextEnd = calculateEndDate(nextStart, requestedDays);
            candidates.push({ start: nextStart, end: nextEnd, label: 'After current conflicts' });
        }

        // Check each candidate for conflicts
        for (const candidate of candidates) {
            const startStr = format(candidate.start, 'yyyy-MM-dd');
            const endStr = format(candidate.end, 'yyyy-MM-dd');

            // Skip if same as current dates
            if (startStr === startDate && endStr === endDate) continue;

            // Skip if start date is in the past
            if (isBefore(candidate.start, today)) continue;

            const conflictCount = await checkConflictsForRange(startStr, endStr);

            // Only suggest if fewer conflicts than current
            if (conflictCount < currentConflicts.length) {
                suggestions.push({
                    start_date: startStr,
                    end_date: endStr,
                    conflicts: conflictCount,
                    label: candidate.label,
                });
            }
        }

        // Sort by conflicts (ascending) and limit to 3 suggestions
        suggestions.sort((a, b) => a.conflicts - b.conflicts);
        setSuggestedDates(suggestions.slice(0, 3));
    };

    // Check 30-day absence window for VL
    useEffect(() => {
        if (data.leave_type !== 'VL' || !data.start_date || !lastAbsenceDate) {
            setAbsenceWindowInfo(null);
            return;
        }

        const startDate = parseISO(data.start_date);
        const absenceDate = parseISO(lastAbsenceDate);
        const windowEndDate = addDays(absenceDate, 30);

        if (startDate <= windowEndDate) {
            const absenceDateStr = format(absenceDate, 'MMMM d, yyyy');
            const eligibleDateStr = format(addDays(windowEndDate, 1), 'MMMM d, yyyy');
            setAbsenceWindowInfo(
                `Your last recorded absence was on ${absenceDateStr}. ` +
                `You can apply for VL starting ${eligibleDateStr} (30 days after absence). ` +
                `You may still submit this request, but reviewers will see this information when approving.`
            );
        } else {
            setAbsenceWindowInfo(null);
        }
    }, [data.leave_type, data.start_date, lastAbsenceDate]);

    // Handle medical certificate file selection
    const handleMedicalCertChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) {
            // Validate file size (4MB max)
            if (file.size > 4 * 1024 * 1024) {
                toast.error('File too large', {
                    description: 'Medical certificate must be less than 4MB.',
                });
                e.target.value = '';
                return;
            }

            // Validate file type
            const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            if (!allowedTypes.includes(file.type)) {
                toast.error('Invalid file type', {
                    description: 'Please upload a JPEG, PNG, GIF, or WebP image.',
                });
                e.target.value = '';
                return;
            }

            setData('medical_cert_file', file);
            setData('medical_cert_submitted', true);

            // Create preview
            const reader = new FileReader();
            reader.onloadend = () => {
                setMedicalCertPreview(reader.result as string);
            };
            reader.readAsDataURL(file);
        }
    };

    // Clear medical certificate
    const clearMedicalCert = () => {
        setData('medical_cert_file', null);
        setData('medical_cert_submitted', false);
        setMedicalCertPreview(null);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        post(leaveStoreRoute().url, {
            forceFormData: true,
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
                } else if (errors.medical_cert_file) {
                    toast.error('Medical certificate error', {
                        description: errors.medical_cert_file as string,
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

                {/* Leave Credits Summary - Only show for leave types that use credits */}
                {requiresCredits && creditsSummary.is_eligible && (
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
                                {futureCredits > 0 && (
                                    <div>
                                        <p className="text-sm text-muted-foreground">Future Credits</p>
                                        <p className="text-2xl font-bold text-purple-600">
                                            +{futureCredits.toFixed(2)}
                                        </p>
                                    </div>
                                )}
                                <div>
                                    <p className="text-sm text-muted-foreground">Available</p>
                                    <p className="text-2xl font-bold text-blue-600">
                                        {Math.max(0, creditsSummary.balance - creditsSummary.pending_credits + futureCredits).toFixed(2)}
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
                                        {Math.max(0, creditsSummary.balance - creditsSummary.pending_credits + futureCredits - (requiresCredits ? calculatedDays : 0)).toFixed(2)}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">Monthly Rate</p>
                                    <p className="text-2xl font-bold">{creditsSummary.monthly_rate}</p>
                                </div>
                            </div>
                            {/* Info about projected credits - only show when applying for future months */}
                            {futureCredits > 0 && (
                                <div className="mt-4 p-3 bg-purple-50 dark:bg-purple-950 border border-purple-200 dark:border-purple-800 rounded-md">
                                    <div className="flex items-start gap-2">
                                        <Info className="h-4 w-4 text-purple-600 mt-0.5 flex-shrink-0" />
                                        <p className="text-sm text-purple-800 dark:text-purple-200">
                                            <strong>Future Credits Applied:</strong> Since your leave starts in a future month, {futureCredits.toFixed(2)} credits will accrue before your leave date ({creditsSummary.monthly_rate} per month). These are included in your available balance.
                                        </p>
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                {requiresCredits && !creditsSummary.is_eligible && (
                    <>
                        {/* Show projected balance when user will be eligible by start date */}
                        {willBeEligibleByStartDate() && data.start_date ? (
                            <Card className="mb-6 border-green-200 dark:border-green-800">
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2 text-green-700 dark:text-green-400">
                                        <CreditCard className="h-5 w-5" />
                                        Projected Leave Credits Balance
                                    </CardTitle>
                                    <CardDescription>
                                        Projected balance as of {format(parseISO(data.start_date), 'MMMM d, yyyy')} (when you will be eligible)
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
                                        <div>
                                            <p className="text-sm text-muted-foreground">Projected Balance</p>
                                            <p className="text-2xl font-bold text-green-600">{getProjectedBalance().toFixed(2)}</p>
                                        </div>
                                        <div>
                                            <p className="text-sm text-muted-foreground">Pending Requests</p>
                                            <p className="text-2xl font-bold text-yellow-600">
                                                {creditsSummary.pending_credits > 0 ? `-${creditsSummary.pending_credits.toFixed(2)}` : '0'}
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
                                            <p className="text-2xl font-bold text-blue-600">
                                                {Math.max(0, getProjectedBalance() - creditsSummary.pending_credits - (requiresCredits ? calculatedDays : 0)).toFixed(2)}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-sm text-muted-foreground">Monthly Rate</p>
                                            <p className="text-2xl font-bold">{creditsSummary.monthly_rate}</p>
                                        </div>
                                    </div>
                                    <div className="mt-4 p-3 bg-green-50 dark:bg-green-950 border border-green-200 dark:border-green-800 rounded-md">
                                        <div className="flex items-start gap-2">
                                            <Info className="h-4 w-4 text-green-600 mt-0.5 flex-shrink-0" />
                                            <div className="text-sm text-green-800 dark:text-green-200">
                                                <p>
                                                    <strong>Future Eligibility:</strong> You are not yet eligible (eligible on{' '}
                                                    {format(parseISO(creditsSummary.eligibility_date!), 'MMMM d, yyyy')}), but since your leave starts after this date,
                                                    you will have {getProjectedBalance().toFixed(2)} credits available by then.
                                                    {creditsSummary.pending_credits > 0 && (
                                                        <> After subtracting {creditsSummary.pending_credits.toFixed(2)} pending credits, you'll have {Math.max(0, getProjectedBalance() - creditsSummary.pending_credits).toFixed(2)} available.</>)}
                                                </p>
                                                {creditsSummary.pending_regularization_credits?.is_pending && (
                                                    <p className="mt-1 text-xs">
                                                        <strong>Breakdown:</strong>{' '}
                                                        {creditsSummary.pending_regularization_credits.credits.toFixed(2)} credits from {creditsSummary.pending_regularization_credits.year} (probation period, {creditsSummary.pending_regularization_credits.months_accrued} months accrued)
                                                        {(() => {
                                                            const startDate = parseISO(data.start_date);
                                                            const eligibilityDate = parseISO(creditsSummary.eligibility_date!);
                                                            const monthsAfterReg = (startDate.getFullYear() - eligibilityDate.getFullYear()) * 12 + (startDate.getMonth() - eligibilityDate.getMonth());
                                                            const postRegCredits = Math.max(0, monthsAfterReg) * creditsSummary.monthly_rate;
                                                            return postRegCredits > 0 ? ` + ${postRegCredits.toFixed(2)} credits from ${creditsSummary.year} (${monthsAfterReg} months after regularization)` : '';
                                                        })()}
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        ) : (
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
                                            . {data.start_date ? (
                                                <>Your selected leave date is before eligibility. Select a date on or after your eligibility date to use credits.</>
                                            ) : (
                                                <>Select a leave start date on or after this date to see your projected balance.</>
                                            )}
                                            {' '}You can still apply for non-credited leave types (SPL, LOA, LDV).
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
                    </>
                )}

                {/* Attendance Violations Display - Only show if points >= 6 */}
                {attendanceViolations.length > 0 && attendancePoints >= 6 && (
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
                    <Alert className="mb-6 border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950">
                        <AlertTriangle className="h-4 w-4 text-amber-600" />
                        <AlertTitle className="text-amber-800 dark:text-amber-200">Informational Warnings</AlertTitle>
                        <AlertDescription className="text-amber-700 dark:text-amber-300">
                            <p className="mb-2 text-sm">You may still submit this request. Reviewers will see this information when making approval decisions.</p>
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

                {/* 30-Day Absence Window Warning (VL only) */}
                {absenceWindowInfo && (
                    <Alert className="mb-6 border-orange-200 bg-orange-50 dark:border-orange-800 dark:bg-orange-950">
                        <Info className="h-4 w-4 text-orange-600" />
                        <AlertTitle className="text-orange-800 dark:text-orange-200">30-Day Absence Window Notice</AlertTitle>
                        <AlertDescription className="text-orange-700 dark:text-orange-300">
                            {absenceWindowInfo}
                        </AlertDescription>
                    </Alert>
                )}

                {/* Campaign Conflicts Warning (VL and UPTO) */}
                {campaignConflicts.length > 0 && (
                    <>
                        <Alert className="mb-6 border-yellow-200 bg-yellow-50 dark:border-yellow-800 dark:bg-yellow-950">
                            <Users className="h-4 w-4 text-yellow-600" />
                            <AlertTitle className="text-yellow-800 dark:text-yellow-200 flex items-center gap-2">
                                <span>Campaign Leave Conflicts</span>
                                <Badge variant="secondary" className="bg-yellow-100 text-yellow-700">
                                    {campaignConflicts.length}
                                </Badge>
                            </AlertTitle>
                            <AlertDescription className="text-yellow-700 dark:text-yellow-300">
                                <p className="mb-3 text-sm">
                                    The following employees from your campaign have already applied for leave during the selected dates. You may still submit your request, but be aware of potential scheduling conflicts.
                                </p>
                                <div className="space-y-2 w-full max-w-3xl mx-auto">
                                    {campaignConflicts.map((conflict) => (
                                        <div key={conflict.id} className="grid grid-cols-1 sm:grid-cols-[minmax(180px,1fr)_auto_minmax(160px,1fr)] items-center gap-x-6 gap-y-2 text-sm bg-yellow-100 dark:bg-yellow-900/50 p-3 rounded-md border border-yellow-200 dark:border-yellow-800/50 transition-colors hover:bg-yellow-200/50 dark:hover:bg-yellow-900/70">
                                            <div className="flex items-center gap-3 justify-self-center sm:justify-self-start">
                                                <span className="font-semibold text-yellow-900 dark:text-yellow-100">{conflict.user_name}</span>
                                                <Badge variant="outline" className="text-[10px] px-1.5 h-5 text-yellow-700 dark:text-yellow-300 border-yellow-400 bg-yellow-50/50 dark:bg-yellow-900/30">
                                                    {conflict.leave_type}
                                                </Badge>
                                            </div>

                                            <div className="flex items-center gap-2 justify-self-center sm:justify-self-center">
                                                <Calendar className="h-3.5 w-3.5 text-yellow-500" />
                                                <span className="text-yellow-800 dark:text-yellow-200 font-medium whitespace-nowrap">
                                                    {format(parseISO(conflict.start_date), 'MMM d')} - {format(parseISO(conflict.end_date), 'MMM d, yyyy')}
                                                </span>
                                            </div>

                                            <div className="flex items-center gap-3 justify-self-center sm:justify-self-end">
                                                <Badge
                                                    variant="outline"
                                                    className={`text-[10px] px-1.5 h-5 capitalize ${conflict.status === 'approved'
                                                        ? 'text-green-700 border-green-400 dark:text-green-400 bg-green-50/50 dark:bg-green-900/20'
                                                        : 'text-orange-700 border-orange-400 dark:text-orange-400 bg-orange-50/50 dark:bg-orange-900/20'
                                                        }`}
                                                >
                                                    {conflict.status}
                                                </Badge>
                                                <span className="text-xs text-yellow-600 dark:text-yellow-400 italic whitespace-nowrap">
                                                    Requested: {format(parseISO(conflict.created_at), 'MMM d, yyyy')}
                                                </span>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </AlertDescription>
                        </Alert>

                        {/* Date Suggestions */}
                        {suggestedDates.length > 0 && (
                            <div className="mb-6 p-4 bg-green-50 dark:bg-green-950/50 rounded-lg border border-green-200 dark:border-green-800">
                                <div className="flex items-center gap-2 mb-2">
                                    <Lightbulb className="h-4 w-4 text-green-600" />
                                    <span className="font-semibold text-green-800 dark:text-green-200 text-sm">Suggested Alternative Dates</span>
                                </div>
                                <p className="text-xs text-green-700 dark:text-green-300 mb-3">
                                    These dates have fewer or no conflicts with employees in <span className="font-medium">{data.campaign_department}</span>:
                                </p>
                                <div className="space-y-2">
                                    {[...suggestedDates].sort((a, b) => a.conflicts - b.conflicts).map((suggestion, index) => (
                                        <div
                                            key={index}
                                            className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 p-3 bg-white dark:bg-green-900/30 rounded-lg border border-green-200 dark:border-green-700 hover:border-green-400 dark:hover:border-green-500 transition-colors"
                                        >
                                            <div className="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3">
                                                <div className="flex items-center gap-2">
                                                    <Badge
                                                        variant="outline"
                                                        className={`text-xs shrink-0 ${suggestion.conflicts === 0
                                                            ? 'bg-green-100 text-green-700 border-green-400 dark:bg-green-900 dark:text-green-300 dark:border-green-600'
                                                            : 'bg-yellow-100 text-yellow-700 border-yellow-400 dark:bg-yellow-900 dark:text-yellow-300 dark:border-yellow-600'
                                                            }`}
                                                    >
                                                        {suggestion.conflicts === 0 ? (
                                                            <span className="flex items-center gap-1">
                                                                <Check className="h-3 w-3" />
                                                                No conflicts
                                                            </span>
                                                        ) : (
                                                            `${suggestion.conflicts} conflict${suggestion.conflicts !== 1 ? 's' : ''}`
                                                        )}
                                                    </Badge>
                                                    <span className="text-xs text-muted-foreground">•</span>
                                                    <span className="text-xs text-green-600 dark:text-green-400 whitespace-nowrap">
                                                        {suggestion.label}
                                                    </span>
                                                </div>
                                                <div className="flex items-center gap-1.5 text-sm">
                                                    <Calendar className="h-3.5 w-3.5 text-green-600 shrink-0" />
                                                    <span className="font-medium text-green-800 dark:text-green-200 whitespace-nowrap">
                                                        {format(parseISO(suggestion.start_date), 'MMM d')} - {format(parseISO(suggestion.end_date), 'MMM d, yyyy')}
                                                    </span>
                                                </div>
                                            </div>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                className="text-xs h-8 px-3 border-green-400 text-green-700 hover:bg-green-100 dark:border-green-600 dark:text-green-300 dark:hover:bg-green-900 w-full sm:w-auto"
                                                onClick={() => {
                                                    setData('start_date', suggestion.start_date);
                                                    setData('end_date', suggestion.end_date);
                                                    toast.success('Dates updated', {
                                                        description: `Changed to ${format(parseISO(suggestion.start_date), 'MMM d')} - ${format(parseISO(suggestion.end_date), 'MMM d, yyyy')}`,
                                                    });
                                                }}
                                            >
                                                Use these dates
                                                <ArrowRight className="h-3 w-3 ml-1" />
                                            </Button>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </>
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
                                        <SelectItem value="SL">Sick Leave (SL)</SelectItem>
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
                                    <DatePicker
                                        value={data.start_date}
                                        onChange={(value) => handleStartDateChange(value)}
                                        placeholder="Select start date"
                                        className={weekendError.start ? 'border-red-500' : ''}
                                        minDate={data.leave_type === 'SL' ? getSlMinDate() : data.leave_type === 'SPL' ? getSplMinDate() : undefined}
                                        maxDate={data.leave_type === 'SL' ? getSlMaxEndDate() : data.leave_type === 'SPL' ? getSplMaxEndDate() : undefined}
                                    />
                                    {weekendError.start && (
                                        <p className="text-sm text-red-500">{weekendError.start}</p>
                                    )}
                                    {errors.start_date && (
                                        <p className="text-sm text-red-500">{errors.start_date}</p>
                                    )}
                                    {data.leave_type === 'SL' ? (
                                        <p className="text-xs text-muted-foreground">Sick Leave: Select from last 3 weeks to 1 month ahead</p>
                                    ) : data.leave_type === 'SPL' ? (
                                        <p className="text-xs text-muted-foreground">Solo Parent Leave: Select from last 2 weeks to 1 month ahead</p>
                                    ) : (
                                        <p className="text-xs text-muted-foreground">Weekends (Sat/Sun) are not allowed</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="end_date">
                                        End Date <span className="text-red-500">*</span>
                                    </Label>
                                    <DatePicker
                                        value={data.end_date}
                                        onChange={(value) => handleEndDateChange(value)}
                                        placeholder="Select end date"
                                        className={weekendError.end ? 'border-red-500' : ''}
                                        minDate={data.start_date || (data.leave_type === 'SL' ? getSlMinDate() : data.leave_type === 'SPL' ? getSplMinDate() : undefined)}
                                        maxDate={data.leave_type === 'SL' ? getSlMaxEndDate() : data.leave_type === 'SPL' ? getSplMaxEndDate() : undefined}
                                        defaultMonth={data.start_date || undefined}
                                    />
                                    {weekendError.end && (
                                        <p className="text-sm text-red-500">{weekendError.end}</p>
                                    )}
                                    {errors.end_date && (
                                        <p className="text-sm text-red-500">{errors.end_date}</p>
                                    )}
                                    {data.leave_type === 'SL' ? (
                                        <p className="text-xs text-muted-foreground">Sick Leave: Up to 1 month from today</p>
                                    ) : data.leave_type === 'SPL' ? (
                                        <p className="text-xs text-muted-foreground">Solo Parent Leave: Up to 1 month from today</p>
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

                            {/* Medical/Supporting Document (for SL, BL, and UPTO) */}
                            {(data.leave_type === 'SL' || data.leave_type === 'BL' || data.leave_type === 'UPTO') && (
                                <div className="space-y-4">
                                    <div>
                                        <Label className="text-base font-medium">
                                            {data.leave_type === 'SL' ? 'Medical Certificate' : data.leave_type === 'BL' ? 'Death Certificate' : 'Supporting Document'} (Optional)
                                        </Label>
                                        <p className="text-sm text-muted-foreground mt-1">
                                            {data.leave_type === 'SL'
                                                ? 'Upload your medical certificate to have leave credits deducted. Without a certificate, the leave will be recorded as unpaid.'
                                                : data.leave_type === 'BL'
                                                    ? 'Upload a death certificate to support your bereavement leave request.'
                                                    : 'Upload any supporting document for your unpaid time off request.'}
                                        </p>
                                    </div>

                                    {!medicalCertPreview ? (
                                        <div className="border-2 border-dashed rounded-lg p-6 text-center hover:border-primary/50 transition-colors">
                                            <input
                                                type="file"
                                                id="medical_cert_file"
                                                accept="image/jpeg,image/jpg,image/png,image/gif,image/webp"
                                                onChange={handleMedicalCertChange}
                                                className="hidden"
                                            />
                                            <label
                                                htmlFor="medical_cert_file"
                                                className="cursor-pointer flex flex-col items-center gap-2"
                                            >
                                                <div className="p-3 bg-muted rounded-full">
                                                    <Upload className="h-6 w-6 text-muted-foreground" />
                                                </div>
                                                <div>
                                                    <p className="font-medium">
                                                        Click to upload {data.leave_type === 'SL' ? 'medical certificate' : data.leave_type === 'BL' ? 'death certificate' : 'supporting document'}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground mt-1">
                                                        JPEG, PNG, GIF, or WebP (max 4MB)
                                                    </p>
                                                </div>
                                            </label>
                                        </div>
                                    ) : (
                                        <div className="border rounded-lg p-4 space-y-3">
                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center gap-2">
                                                    <FileImage className="h-5 w-5 text-green-600" />
                                                    <span className="text-sm font-medium text-green-600">
                                                        {data.leave_type === 'SL' ? 'Medical certificate' : data.leave_type === 'BL' ? 'Death certificate' : 'Supporting document'} attached
                                                    </span>
                                                </div>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={clearMedicalCert}
                                                    className="text-red-600 hover:text-red-700 hover:bg-red-50"
                                                >
                                                    <X className="h-4 w-4 mr-1" />
                                                    Remove
                                                </Button>
                                            </div>
                                            <div className="relative aspect-video max-h-48 overflow-hidden rounded-md bg-muted">
                                                <img
                                                    src={medicalCertPreview}
                                                    alt="Medical certificate preview"
                                                    className="object-contain w-full h-full"
                                                />
                                            </div>
                                            {data.medical_cert_file && (
                                                <p className="text-xs text-muted-foreground">
                                                    {data.medical_cert_file.name} ({(data.medical_cert_file.size / 1024 / 1024).toFixed(2)} MB)
                                                </p>
                                            )}
                                        </div>
                                    )}

                                    {errors.medical_cert_file && (
                                        <p className="text-sm text-red-500">{errors.medical_cert_file}</p>
                                    )}

                                    {/* Upload progress */}
                                    {progress && (progress.percentage ?? 0) > 0 && (progress.percentage ?? 0) < 100 && (
                                        <div className="space-y-2">
                                            <Progress value={progress.percentage ?? 0} className="h-2" />
                                            <p className="text-xs text-muted-foreground text-center">
                                                Uploading... {progress.percentage ?? 0}%
                                            </p>
                                        </div>
                                    )}

                                    {slCreditInfo && (
                                        <Alert className={slCreditInfo.includes('converted to UPTO')
                                            ? "border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950"
                                            : "border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-950"
                                        }>
                                            <AlertCircle className={slCreditInfo.includes('converted to UPTO')
                                                ? "h-4 w-4 text-amber-600 dark:text-amber-400"
                                                : "h-4 w-4 text-blue-600 dark:text-blue-400"
                                            } />
                                            <AlertDescription className={slCreditInfo.includes('converted to UPTO')
                                                ? "text-amber-800 dark:text-amber-200"
                                                : "text-blue-800 dark:text-blue-200"
                                            }>
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
                                <Button type="submit" disabled={processing || !!weekendError.start || !!weekendError.end}>
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
