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
import { AlertCircle, Calendar, CreditCard, Check, ChevronsUpDown, AlertTriangle, Upload, X, FileImage, Eye, Users, Info, Lightbulb, ArrowRight, ChevronDown } from 'lucide-react';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import { Switch } from '@/components/ui/switch';
import { index as leaveIndexRoute, create as leaveCreateRoute, store as leaveStoreRoute } from '@/routes/leave-requests';
import {
    type CreditsSummary,
    type AttendanceViolation,
    type ExistingLeaveRequest,
    type CampaignConflict,
    type DateSuggestion,
    isWeekend,
    getDayName,
    getSlMinDate,
    getSlMaxEndDate,
    getSplMinDate,
    getSplMaxEndDate,
    getMlMaxEndDate,
    willBeEligibleByStartDate as checkEligibleByStartDate,
    getProjectedBalance as calcProjectedBalance,
    calculateFutureCredits as calcFutureCredits,
    countWorkingDays,
} from '@/lib/leave-utils';

interface Employee {
    id: number;
    name: string;
    email: string;
}

interface Props {
    creditsSummary: CreditsSummary;
    splCreditsSummary: { total: number; used: number; balance: number; year: number } | null;
    isSoloParent: boolean;
    attendancePoints: number;
    attendanceViolations: AttendanceViolation[];
    hasRecentAbsence: boolean;
    hasPendingRequests: boolean;
    nextEligibleLeaveDate: string | null;
    lastAbsenceDate: string | null;
    campaigns: string[];
    selectedCampaign: string | null;
    twoWeeksFromNow: string;
    canFileForOthers: boolean;
    employees: Employee[];
    selectedEmployeeId: number;
    existingLeaveRequests: ExistingLeaveRequest[];
}

export default function Create({
    creditsSummary,
    splCreditsSummary,
    isSoloParent,
    attendancePoints,
    attendanceViolations,
    hasRecentAbsence,
    nextEligibleLeaveDate,
    lastAbsenceDate,
    campaigns,
    selectedCampaign,
    twoWeeksFromNow,
    canFileForOthers = false,
    employees,
    selectedEmployeeId,
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
        spl_day_settings: [] as { date: string; is_half_day: boolean }[],
    });

    const [selectedEmployee, setSelectedEmployee] = useState<number>(selectedEmployeeId);
    const [searchQuery, setSearchQuery] = useState<string>('');
    const [isEmployeePopoverOpen, setIsEmployeePopoverOpen] = useState(false);
    const [medicalCertPreview, setMedicalCertPreview] = useState<string | null>(null);
    const [isPdfFile, setIsPdfFile] = useState<boolean>(false);
    const [campaignConflicts, setCampaignConflicts] = useState<CampaignConflict[]>([]);
    const [suggestedDates, setSuggestedDates] = useState<DateSuggestion[]>([]);
    const [absenceWindowInfo, setAbsenceWindowInfo] = useState<string | null>(null);

    const [calculatedDays, setCalculatedDays] = useState<number>(0);
    const [validationWarnings, setValidationWarnings] = useState<React.ReactNode[]>([]);
    const [vlCreditWarning, setVlCreditWarning] = useState<React.ReactNode>(null);
    const [weekendError, setWeekendError] = useState<{ start: string | null; end: string | null }>({ start: null, end: null });
    const [slCreditInfo, setSlCreditInfo] = useState<string | null>(null);
    const [futureCredits, setFutureCredits] = useState<number>(0);

    // Delegate to shared pure functions with component state
    const willBeEligibleByStartDate = () =>
        checkEligibleByStartDate(data.start_date, creditsSummary.is_eligible, creditsSummary.eligibility_date);

    const getProjectedBalance = () =>
        calcProjectedBalance(data.start_date, creditsSummary.eligibility_date, creditsSummary.monthly_rate, creditsSummary.pending_regularization_credits);

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

    // Calculate future credits delegating to shared utility
    const calculateFutureCredits = useCallback(
        (startDate: string): number => calcFutureCredits(startDate, creditsSummary),
        [creditsSummary.is_eligible, creditsSummary.eligibility_date, creditsSummary.monthly_rate],
    );

    // Calculate working days when dates change (excluding weekends)
    useEffect(() => {
        if (data.start_date && data.end_date) {
            try {
                setCalculatedDays(countWorkingDays(data.start_date, data.end_date));

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

    // Auto-generate SPL day settings when dates change
    useEffect(() => {
        if (data.leave_type !== 'SPL' || !data.start_date || !data.end_date) {
            if (data.spl_day_settings.length > 0) {
                setData('spl_day_settings', []);
            }
            return;
        }

        const start = parseISO(data.start_date);
        const end = parseISO(data.end_date);
        const newSettings: { date: string; is_half_day: boolean }[] = [];
        const currentDate = new Date(start);

        while (currentDate <= end) {
            const dayOfWeek = currentDate.getDay();
            if (dayOfWeek >= 1 && dayOfWeek <= 5) {
                const dateStr = format(currentDate, 'yyyy-MM-dd');
                // Preserve existing setting if date matches
                const existing = data.spl_day_settings.find(s => s.date === dateStr);
                newSettings.push({
                    date: dateStr,
                    is_half_day: existing ? existing.is_half_day : false,
                });
            }
            currentDate.setDate(currentDate.getDate() + 1);
        }

        // Only update if settings actually changed
        const hasChanged = newSettings.length !== data.spl_day_settings.length ||
            newSettings.some((s, i) => s.date !== data.spl_day_settings[i]?.date);
        if (hasChanged) {
            setData('spl_day_settings', newSettings);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data.leave_type, data.start_date, data.end_date]);

    // Real-time validation warnings
    useEffect(() => {
        const warnings: React.ReactNode[] = [];

        // Eligibility info is already shown in the "Not Eligible Yet" alert above the form
        // No need to duplicate it in validation warnings

        //NOTE: SL is intentionally excluded from the eligibility warning since users can still submit and it will be handled at approval time with potential UPTO conversion. BL is included in the eligibility warning since it's a non-credited leave type and eligibility is a hard requirement.
        // Check 2-week notice (only for VL,UPTO not SL/BL/ML as they are unpredictable)
        // Short notice override is handled at approval time on the Show page
        if (data.start_date && ['VL', 'UPTO'].includes(data.leave_type)) {
            const start = parseISO(data.start_date);
            start.setHours(0, 0, 0, 0);
            const twoWeeks = new Date(twoWeeksFromNow);
            twoWeeks.setHours(0, 0, 0, 0);
            if (start.getTime() < twoWeeks.getTime()) {
                warnings.push(
                    <>Leave must be requested at least 2 weeks in advance. Earliest date: <strong>{format(twoWeeks, 'MMMM d, yyyy')}</strong>. Admin can override this during approval.</>
                );
            }
        }

        // Check attendance points for VL only (BL/SL/ML are exempt)
        if (data.leave_type === 'VL' && attendancePoints > 6) {
            warnings.push(
                `You have ${attendancePoints} attendance points (must be ≤6 for Vacation Leave).`
            );
        }

        // Check recent absence for VL only (BL/SL/ML are exempt)
        if (data.leave_type === 'VL' && hasRecentAbsence && nextEligibleLeaveDate) {
            warnings.push(
                `You had an absence in the last 30 days. Next eligible date: ${format(parseISO(nextEligibleLeaveDate), 'MMMM d, yyyy')}`
            );
        }

        // Check leave credits balance for VL (informational warning only — does not block submission)
        // SL handles insufficient credits at approval time (SL→UPTO conversion)
        // BL does not consume credits (non-credited leave type)
        let newVlCreditWarning: React.ReactNode = null;
        if (data.leave_type === 'VL' && calculatedDays > 0) {
            // Calculate projected credits inline to avoid stale state from concurrent useEffect
            const projectedCredits = data.start_date ? calculateFutureCredits(data.start_date) : 0;
            const availableBalance = Math.max(0, creditsSummary.balance - creditsSummary.pending_credits + projectedCredits);
            if (availableBalance < calculatedDays) {
                newVlCreditWarning = <>Insufficient VL credits. Available: <strong>{availableBalance.toFixed(2)} day(s)</strong>{projectedCredits > 0 ? <> (includes {projectedCredits.toFixed(2)} future credits)</> : ''}, Requested: <strong>{calculatedDays} day(s)</strong>. Some days may be converted to UPTO (Unpaid Time Off) upon approval.</>;
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
                        <>Selected dates overlap with an existing <strong>{status} {existing.leave_type}</strong> request (<strong>{startStr}</strong> to <strong>{endStr}</strong>).</>
                    );
                    break; // Only show one overlap warning
                }
            }
        }

        setValidationWarnings(warnings);
        setVlCreditWarning(newVlCreditWarning);
    }, [
        data.leave_type,
        data.start_date,
        data.end_date,
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
                const creditedDays = Math.floor(creditsSummary.balance);
                const uptoDays = calculatedDays - creditedDays;
                setSlCreditInfo(
                    creditedDays > 0
                        ? `⚠️ Partial UPTO conversion - ${creditedDays} day(s) will be credited from your balance, ${uptoDays} day(s) will be converted to UPTO (Unpaid Time Off)`
                        : `⚠️ All ${calculatedDays} day(s) will be converted to UPTO (Unpaid Time Off) - No whole credits available (balance: ${creditsSummary.balance.toFixed(2)} days)`
                );
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
            const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
            if (!allowedTypes.includes(file.type)) {
                toast.error('Invalid file type', {
                    description: 'Please upload a JPEG, PNG, GIF, WebP image or PDF file.',
                });
                e.target.value = '';
                return;
            }

            setData('medical_cert_file', file);
            setData('medical_cert_submitted', true);

            // Revoke previous object URL if any
            if (isPdfFile && medicalCertPreview) {
                URL.revokeObjectURL(medicalCertPreview);
            }

            // Create preview
            if (file.type.startsWith('image/')) {
                setIsPdfFile(false);
                const reader = new FileReader();
                reader.onloadend = () => {
                    setMedicalCertPreview(reader.result as string);
                };
                reader.readAsDataURL(file);
            } else {
                // For PDFs, create an object URL for embedded preview
                setIsPdfFile(true);
                const objectUrl = URL.createObjectURL(file);
                setMedicalCertPreview(objectUrl);
            }
        }
    };

    // Clear medical certificate
    const clearMedicalCert = () => {
        // Revoke object URL if it was a PDF
        if (isPdfFile && medicalCertPreview) {
            URL.revokeObjectURL(medicalCertPreview);
        }
        setData('medical_cert_file', null);
        setData('medical_cert_submitted', false);
        setMedicalCertPreview(null);
        setIsPdfFile(false);
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

                {/* Leave Credits Summary - Compact with progress bar */}
                {requiresCredits && creditsSummary.is_eligible && (
                    <Card className="mb-6">
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <CreditCard className="h-4 w-4" />
                                Leave Credits
                                <span className="text-sm font-normal text-muted-foreground">
                                    Year {creditsSummary.year}
                                </span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {(() => {
                                const available = Math.max(0, creditsSummary.balance - creditsSummary.pending_credits + futureCredits);
                                const afterSubmit = Math.max(0, available - (requiresCredits ? calculatedDays : 0));
                                const total = creditsSummary.balance + futureCredits;
                                const usedPercent = total > 0 ? Math.min(100, ((total - available) / total) * 100) : 0;
                                const requestPercent = total > 0 ? Math.min(100 - usedPercent, (calculatedDays / total) * 100) : 0;

                                return (
                                    <>
                                        {/* Progress bar visualization */}
                                        <div className="mb-3">
                                            <div className="flex justify-between text-sm mb-1.5">
                                                <span className="font-medium">
                                                    {available.toFixed(2)} available
                                                    {calculatedDays > 0 && <span className="text-orange-600"> → {afterSubmit.toFixed(2)} after this request</span>}
                                                </span>
                                                <span className="text-muted-foreground">of {total.toFixed(2)} total</span>
                                            </div>
                                            <div className="h-2.5 rounded-full bg-muted overflow-hidden flex">
                                                {usedPercent > 0 && (
                                                    <div
                                                        className="h-full bg-gray-400 dark:bg-gray-600 transition-all"
                                                        style={{ width: `${usedPercent}%` }}
                                                    />
                                                )}
                                                {requestPercent > 0 && (
                                                    <div
                                                        className="h-full bg-orange-400 dark:bg-orange-500 transition-all"
                                                        style={{ width: `${requestPercent}%` }}
                                                    />
                                                )}
                                            </div>
                                            <div className="flex gap-4 mt-1.5 text-xs text-muted-foreground">
                                                <span className="flex items-center gap-1">
                                                    <span className="inline-block h-2 w-2 rounded-full bg-gray-400" /> Used/Pending
                                                </span>
                                                {calculatedDays > 0 && (
                                                    <span className="flex items-center gap-1">
                                                        <span className="inline-block h-2 w-2 rounded-full bg-orange-400" /> This request ({calculatedDays}d)
                                                    </span>
                                                )}
                                                <span className="flex items-center gap-1">
                                                    <span className="inline-block h-2 w-2 rounded-full bg-muted border" /> Available
                                                </span>
                                            </div>
                                        </div>

                                        {/* Compact details row */}
                                        <Collapsible>
                                            <CollapsibleTrigger className="flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground">
                                                <ChevronDown className="h-3 w-3 transition-transform duration-200 [[data-state=open]>&]:rotate-180" />
                                                View breakdown
                                            </CollapsibleTrigger>
                                            <CollapsibleContent className="mt-3">
                                                <div className="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                                                    <div>
                                                        <p className="text-xs text-muted-foreground">Balance</p>
                                                        <p className="font-semibold">{creditsSummary.balance.toFixed(2)}</p>
                                                    </div>
                                                    <div>
                                                        <p className="text-xs text-muted-foreground">Pending</p>
                                                        <p className="font-semibold text-yellow-600">
                                                            {creditsSummary.pending_credits > 0 ? `-${creditsSummary.pending_credits.toFixed(2)}` : '0'}
                                                        </p>
                                                    </div>
                                                    {futureCredits > 0 && (
                                                        <div>
                                                            <p className="text-xs text-muted-foreground">Future Credits</p>
                                                            <p className="font-semibold text-purple-600">+{futureCredits.toFixed(2)}</p>
                                                        </div>
                                                    )}
                                                    <div>
                                                        <p className="text-xs text-muted-foreground">Monthly Rate</p>
                                                        <p className="font-semibold">{creditsSummary.monthly_rate}</p>
                                                    </div>
                                                </div>
                                                {futureCredits > 0 && (
                                                    <p className="mt-2 text-xs text-purple-700 dark:text-purple-300">
                                                        <Info className="h-3 w-3 inline mr-1" />
                                                        {futureCredits.toFixed(2)} credits will accrue before your leave date ({creditsSummary.monthly_rate}/month).
                                                    </p>
                                                )}
                                            </CollapsibleContent>
                                        </Collapsible>
                                    </>
                                );
                            })()}
                        </CardContent>
                    </Card>
                )}

                {requiresCredits && !creditsSummary.is_eligible && (
                    <>
                        {/* Projected balance — compact with progress bar */}
                        {willBeEligibleByStartDate() && data.start_date ? (
                            <Card className="mb-6 border-green-200 dark:border-green-800">
                                <CardHeader className="pb-3">
                                    <CardTitle className="flex items-center gap-2 text-base text-green-700 dark:text-green-400">
                                        <CreditCard className="h-4 w-4" />
                                        Projected Leave Credits
                                        <span className="text-sm font-normal text-muted-foreground">
                                            as of {format(parseISO(data.start_date), 'MMM d, yyyy')}
                                        </span>
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {(() => {
                                        const projected = getProjectedBalance();
                                        const available = Math.max(0, projected - creditsSummary.pending_credits);
                                        const afterSubmit = Math.max(0, available - (requiresCredits ? calculatedDays : 0));
                                        const usedPercent = projected > 0 ? Math.min(100, (creditsSummary.pending_credits / projected) * 100) : 0;
                                        const requestPercent = projected > 0 ? Math.min(100 - usedPercent, (calculatedDays / projected) * 100) : 0;

                                        return (
                                            <>
                                                <div className="mb-3">
                                                    <div className="flex justify-between text-sm mb-1.5">
                                                        <span className="font-medium">
                                                            {available.toFixed(2)} available
                                                            {calculatedDays > 0 && <span className="text-orange-600"> → {afterSubmit.toFixed(2)} after this request</span>}
                                                        </span>
                                                        <span className="text-muted-foreground">of {projected.toFixed(2)} projected</span>
                                                    </div>
                                                    <div className="h-2.5 rounded-full bg-muted overflow-hidden flex">
                                                        {usedPercent > 0 && (
                                                            <div className="h-full bg-gray-400 dark:bg-gray-600 transition-all" style={{ width: `${usedPercent}%` }} />
                                                        )}
                                                        {requestPercent > 0 && (
                                                            <div className="h-full bg-orange-400 dark:bg-orange-500 transition-all" style={{ width: `${requestPercent}%` }} />
                                                        )}
                                                    </div>
                                                </div>
                                                <Collapsible>
                                                    <CollapsibleTrigger className="flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground">
                                                        <ChevronDown className="h-3 w-3 transition-transform duration-200 [[data-state=open]>&]:rotate-180" />
                                                        View eligibility details
                                                    </CollapsibleTrigger>
                                                    <CollapsibleContent className="mt-3">
                                                        <div className="p-3 bg-green-50 dark:bg-green-950 border border-green-200 dark:border-green-800 rounded-md">
                                                            <div className="text-sm text-green-800 dark:text-green-200">
                                                                <p>
                                                                    <strong>Future Eligibility:</strong> Eligible on{' '}
                                                                    {format(parseISO(creditsSummary.eligibility_date!), 'MMMM d, yyyy')}.
                                                                    You will have {projected.toFixed(2)} credits available by your leave date.
                                                                    {creditsSummary.pending_credits > 0 && (
                                                                        <> After subtracting {creditsSummary.pending_credits.toFixed(2)} pending, you'll have {available.toFixed(2)} available.</>
                                                                    )}
                                                                </p>
                                                                {creditsSummary.pending_regularization_credits?.is_pending && (
                                                                    <p className="mt-1 text-xs">
                                                                        <strong>Breakdown:</strong>{' '}
                                                                        {creditsSummary.pending_regularization_credits.credits.toFixed(2)} from {creditsSummary.pending_regularization_credits.year} ({creditsSummary.pending_regularization_credits.months_accrued} months accrued)
                                                                        {(() => {
                                                                            const startDate = parseISO(data.start_date);
                                                                            const eligibilityDate = parseISO(creditsSummary.eligibility_date!);
                                                                            const monthsAfterReg = (startDate.getFullYear() - eligibilityDate.getFullYear()) * 12 + (startDate.getMonth() - eligibilityDate.getMonth());
                                                                            const postRegCredits = Math.max(0, monthsAfterReg) * creditsSummary.monthly_rate;
                                                                            return postRegCredits > 0 ? ` + ${postRegCredits.toFixed(2)} from ${creditsSummary.year} (${monthsAfterReg} months after regularization)` : '';
                                                                        })()}
                                                                    </p>
                                                                )}
                                                            </div>
                                                        </div>
                                                    </CollapsibleContent>
                                                </Collapsible>
                                            </>
                                        );
                                    })()}
                                </CardContent>
                            </Card>
                        ) : null}
                    </>
                )}

                {/* SPL Credits Balance — compact with progress bar */}
                {data.leave_type === 'SPL' && splCreditsSummary && (
                    <Card className="mb-6 border-violet-200 dark:border-violet-800">
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base text-violet-700 dark:text-violet-400">
                                <CreditCard className="h-4 w-4" />
                                Solo Parent Leave Credits
                                <span className="text-sm font-normal text-muted-foreground">
                                    {splCreditsSummary.year} • {splCreditsSummary.total} days/yr
                                </span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {(() => {
                                const requestedCredits = data.spl_day_settings.reduce((sum, d) => sum + (d.is_half_day ? 0.5 : 1), 0);
                                const afterSubmit = Math.max(0, splCreditsSummary.balance - requestedCredits);
                                const usedPercent = splCreditsSummary.total > 0 ? Math.min(100, (splCreditsSummary.used / splCreditsSummary.total) * 100) : 0;
                                const requestPercent = splCreditsSummary.total > 0 ? Math.min(100 - usedPercent, (requestedCredits / splCreditsSummary.total) * 100) : 0;
                                const isInsufficient = requestedCredits > 0 && requestedCredits > splCreditsSummary.balance;

                                return (
                                    <>
                                        <div className="mb-3">
                                            <div className="flex justify-between text-sm mb-1.5">
                                                <span className="font-medium">
                                                    {splCreditsSummary.balance.toFixed(2)} available
                                                    {requestedCredits > 0 && <span className="text-violet-600"> → {afterSubmit.toFixed(2)} after this request</span>}
                                                </span>
                                                <span className="text-muted-foreground">of {splCreditsSummary.total.toFixed(0)} total</span>
                                            </div>
                                            <div className="h-2.5 rounded-full bg-muted overflow-hidden flex">
                                                {usedPercent > 0 && (
                                                    <div className="h-full bg-gray-400 dark:bg-gray-600 transition-all" style={{ width: `${usedPercent}%` }} />
                                                )}
                                                {requestPercent > 0 && (
                                                    <div className={`h-full transition-all ${isInsufficient ? 'bg-red-400 dark:bg-red-500' : 'bg-violet-400 dark:bg-violet-500'}`} style={{ width: `${requestPercent}%` }} />
                                                )}
                                            </div>
                                        </div>
                                        {isInsufficient && (
                                            <p className="text-xs text-amber-700 dark:text-amber-300 mt-2">
                                                Requesting {requestedCredits.toFixed(1)} but only {splCreditsSummary.balance.toFixed(2)} available — excess days will be marked absent.
                                            </p>
                                        )}
                                        <Collapsible>
                                            <CollapsibleTrigger className="flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground mt-1">
                                                <ChevronDown className="h-3 w-3 transition-transform duration-200 [[data-state=open]>&]:rotate-180" />
                                                View breakdown
                                            </CollapsibleTrigger>
                                            <CollapsibleContent className="mt-2 grid grid-cols-4 gap-3 text-center text-sm">
                                                <div>
                                                    <p className="text-muted-foreground text-xs">Total</p>
                                                    <p className="font-semibold">{splCreditsSummary.total.toFixed(2)}</p>
                                                </div>
                                                <div>
                                                    <p className="text-muted-foreground text-xs">Used</p>
                                                    <p className="font-semibold text-gray-600">{splCreditsSummary.used.toFixed(2)}</p>
                                                </div>
                                                <div>
                                                    <p className="text-muted-foreground text-xs">Available</p>
                                                    <p className="font-semibold text-blue-600">{splCreditsSummary.balance.toFixed(2)}</p>
                                                </div>
                                                <div>
                                                    <p className="text-muted-foreground text-xs">This Request</p>
                                                    <p className="font-semibold text-violet-600">{requestedCredits > 0 ? `-${requestedCredits.toFixed(1)}` : '0'}</p>
                                                </div>
                                            </CollapsibleContent>
                                        </Collapsible>
                                    </>
                                );
                            })()}
                        </CardContent>
                    </Card>
                )}

                {data.leave_type === 'SPL' && !isSoloParent && (
                    <Alert className="mb-6 border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-950">
                        <AlertCircle className="h-4 w-4 text-red-600" />
                        <AlertTitle className="text-red-800 dark:text-red-200">Not Eligible for SPL</AlertTitle>
                        <AlertDescription className="text-red-700 dark:text-red-300">
                            Your account is not flagged as a solo parent. Please contact HR to update your solo parent status before filing SPL.
                        </AlertDescription>
                    </Alert>
                )}

                {/* Attendance Violations Display - Only show if points >= 6, hide for ML */}
                {attendanceViolations.length > 0 && attendancePoints >= 6 && data.leave_type !== 'ML' && (
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
                                            <Alert className="mt-4 border-red-300 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200">
                                                <AlertCircle className="h-4 w-4" />
                                                <AlertTitle className="text-red-800 dark:text-red-200">High Attendance Points</AlertTitle>
                                                <AlertDescription className="text-red-700 dark:text-red-300">
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

                {/* Leave Request Form */}
                <Card>
                    <CardHeader>
                        <CardTitle>Leave Details</CardTitle>
                        <CardDescription>Fill in the information below</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit} className="space-y-6">
                            {/* Employee Selection (Admin/Team Lead) */}
                            {canFileForOthers && (
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
                                        {isSoloParent && (
                                            <SelectItem value="SPL">Solo Parent Leave (SPL)</SelectItem>
                                        )}
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
                                {/* Consolidated Leave Type Notices */}
                                {(() => {
                                    const showReminder = ['VL', 'UPTO', 'LOA', 'ML'].includes(data.leave_type);
                                    const showNotEligible = requiresCredits && !creditsSummary.is_eligible && !willBeEligibleByStartDate();
                                    const showVlCredit = !!vlCreditWarning;
                                    const showWarnings = validationWarnings.length > 0;
                                    const totalNotices = [showReminder, showNotEligible, showVlCredit, showWarnings].filter(Boolean).length;

                                    if (totalNotices === 0) return null;

                                    // Determine highest severity for the consolidated alert border
                                    const hasWarning = showNotEligible || showVlCredit || showWarnings;

                                    return (
                                        <Alert className={hasWarning
                                            ? "border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950"
                                            : "border-muted bg-muted/50"
                                        }>
                                            <AlertTriangle className={`h-4 w-4 ${hasWarning ? 'text-amber-600' : 'text-muted-foreground'}`} />
                                            <AlertTitle className={`flex items-center gap-2 ${hasWarning ? 'text-amber-800 dark:text-amber-200' : 'text-foreground'}`}>
                                                {hasWarning ? 'Notices & Warnings' : 'Reminder'}
                                                {totalNotices > 1 && (
                                                    <Badge variant="secondary" className="text-xs">
                                                        {totalNotices}
                                                    </Badge>
                                                )}
                                            </AlertTitle>
                                            <AlertDescription>
                                                <div className="space-y-3 mt-1">
                                                    {/* Reminder - soft notice */}
                                                    {showReminder && (
                                                        <div className={`text-sm ${hasWarning ? 'text-amber-700 dark:text-amber-300' : 'text-muted-foreground'}`}>
                                                            <ul className="list-disc space-y-1 ml-4">
                                                                <li>Inform your <strong>clients</strong> at least <strong>two weeks in advance</strong> before applying for leave.</li>
                                                                <li>Notify your <strong>Team Lead (TL)</strong> or <strong>Admins</strong> of your planned leave prior to filing.</li>
                                                            </ul>
                                                        </div>
                                                    )}

                                                    {/* Not Eligible Yet - amber warning */}
                                                    {showNotEligible && (
                                                        <>
                                                            {showReminder && <hr className="border-amber-200 dark:border-amber-700" />}
                                                            <div className="text-sm text-amber-700 dark:text-amber-300">
                                                                <p className="font-medium mb-1">Not Eligible Yet</p>
                                                                <p>
                                                                    {creditsSummary.eligibility_date ? (
                                                                        <>
                                                                            You will be eligible to use leave credits on <strong>{format(parseISO(creditsSummary.eligibility_date), 'MMMM d, yyyy')}</strong>.
                                                                            {' '}{data.start_date
                                                                                ? 'Your selected leave date is before eligibility. Select a date on or after your eligibility date to use credits.'
                                                                                : 'Select a leave start date on or after this date to see your projected balance.'
                                                                            }
                                                                            {' '}You can still apply for non-credited leave types (SPL, LOA, LDV).
                                                                        </>
                                                                    ) : (
                                                                        <>Eligibility date not set. Please contact HR to update your hire date. You can still apply for non-credited leave types (SPL, LOA, LDV).</>
                                                                    )}
                                                                </p>
                                                            </div>
                                                        </>
                                                    )}

                                                    {/* Insufficient VL Credits - amber warning */}
                                                    {showVlCredit && (
                                                        <>
                                                            {(showReminder || showNotEligible) && <hr className="border-amber-200 dark:border-amber-700" />}
                                                            <div className="text-sm text-amber-700 dark:text-amber-300">
                                                                <p className="font-medium mb-1">Insufficient VL Credits</p>
                                                                <p>{vlCreditWarning}</p>
                                                            </div>
                                                        </>
                                                    )}

                                                    {/* Informational Warnings - collapsible when multiple */}
                                                    {showWarnings && (
                                                        <>
                                                            {(showReminder || showNotEligible || showVlCredit) && <hr className="border-amber-200 dark:border-amber-700" />}
                                                            <div className="text-sm text-amber-700 dark:text-amber-300">
                                                                {validationWarnings.length <= 2 ? (
                                                                    <>
                                                                        <p className="text-xs mb-1">You may still submit — reviewers will see these warnings.</p>
                                                                        <ul className="list-disc ml-4 space-y-1">
                                                                            {validationWarnings.map((warning, idx) => (
                                                                                <li key={idx}>{warning}</li>
                                                                            ))}
                                                                        </ul>
                                                                    </>
                                                                ) : (
                                                                    <Collapsible>
                                                                        <CollapsibleTrigger className="flex items-center gap-1.5 text-amber-800 dark:text-amber-200 font-medium hover:underline">
                                                                            <ChevronDown className="h-3.5 w-3.5 transition-transform duration-200 [[data-state=open]>&]:rotate-180" />
                                                                            {validationWarnings.length} warnings — click to expand
                                                                        </CollapsibleTrigger>
                                                                        <CollapsibleContent className="mt-2">
                                                                            <p className="text-xs mb-1">You may still submit — reviewers will see these warnings.</p>
                                                                            <ul className="list-disc ml-4 space-y-1">
                                                                                {validationWarnings.map((warning, idx) => (
                                                                                    <li key={idx}>{warning}</li>
                                                                                ))}
                                                                            </ul>
                                                                        </CollapsibleContent>
                                                                    </Collapsible>
                                                                )}
                                                            </div>
                                                        </>
                                                    )}
                                                </div>
                                            </AlertDescription>
                                        </Alert>
                                    );
                                })()}
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
                                        maxDate={data.leave_type === 'SL' ? getSlMaxEndDate() : data.leave_type === 'SPL' ? getSplMaxEndDate() : data.leave_type === 'ML' ? getMlMaxEndDate() : undefined}
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
                                    ) : data.leave_type === 'ML' ? (
                                        <p className="text-xs text-muted-foreground">Maternity Leave: Up to 1 year ahead</p>
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
                                        maxDate={data.leave_type === 'SL' ? getSlMaxEndDate() : data.leave_type === 'SPL' ? getSplMaxEndDate() : data.leave_type === 'ML' ? getMlMaxEndDate() : undefined}
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
                                    ) : data.leave_type === 'ML' ? (
                                        <p className="text-xs text-muted-foreground">Maternity Leave: Up to 1 year from today</p>
                                    ) : (
                                        <p className="text-xs text-muted-foreground">Weekends (Sat/Sun) are not allowed</p>
                                    )}
                                </div>
                            </div>

                            {/* Date-Related Warnings — positioned near the date fields that trigger them */}

                            {/* 30-Day Absence Window Notice (VL only) — muted severity tier */}
                            {absenceWindowInfo && (
                                <Alert className="border-muted bg-muted/50">
                                    <Info className="h-4 w-4 text-muted-foreground" />
                                    <AlertTitle className="text-muted-foreground">30-Day Absence Window Notice</AlertTitle>
                                    <AlertDescription className="text-muted-foreground">
                                        {absenceWindowInfo}
                                    </AlertDescription>
                                </Alert>
                            )}

                            {/* Campaign Conflicts Warning (VL and UPTO) — collapsed by default */}
                            {campaignConflicts.length > 0 && (
                                <Collapsible>
                                    <Alert className="border-yellow-200 bg-yellow-50 dark:border-yellow-800 dark:bg-yellow-950">
                                        <Users className="h-4 w-4 text-yellow-600" />
                                        <AlertTitle className="text-yellow-800 dark:text-yellow-200">
                                            <CollapsibleTrigger className="flex items-center gap-2 hover:underline w-full">
                                                <span>Campaign Leave Conflicts</span>
                                                <Badge variant="secondary" className="bg-yellow-100 text-yellow-700">
                                                    {campaignConflicts.length}
                                                </Badge>
                                                <ChevronDown className="h-3.5 w-3.5 ml-auto transition-transform duration-200 [[data-state=open]>&]:rotate-180" />
                                            </CollapsibleTrigger>
                                        </AlertTitle>
                                        <AlertDescription className="text-yellow-700 dark:text-yellow-300">
                                            <p className="text-sm">
                                                {campaignConflicts.length} teammate{campaignConflicts.length !== 1 ? 's' : ''} on leave during your selected dates. You may still submit.
                                            </p>
                                            <CollapsibleContent className="mt-3">
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
                                            </CollapsibleContent>
                                        </AlertDescription>
                                    </Alert>
                                </Collapsible>
                            )}

                            {/* Date Suggestions - shown below campaign conflicts */}
                            {campaignConflicts.length > 0 && suggestedDates.length > 0 && (
                                <div className="p-4 bg-green-50 dark:bg-green-950/50 rounded-lg border border-green-200 dark:border-green-800">
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

                            {/* Calculated Days Display */}
                            {calculatedDays > 0 && (
                                <Alert>
                                    <Calendar className="h-4 w-4" />
                                    <AlertTitle>Duration</AlertTitle>
                                    <AlertDescription>
                                        <strong>{calculatedDays}</strong> day{calculatedDays !== 1 ? 's' : ''}{' '}
                                        requested
                                        {data.leave_type === 'SPL' && data.spl_day_settings.length > 0 && (
                                            <> ({data.spl_day_settings.reduce((sum, d) => sum + (d.is_half_day ? 0.5 : 1), 0).toFixed(1)} credit{data.spl_day_settings.reduce((sum, d) => sum + (d.is_half_day ? 0.5 : 1), 0) !== 1 ? 's' : ''} to consume)</>
                                        )}
                                    </AlertDescription>
                                </Alert>
                            )}

                            {/* SPL Half-Day Toggles */}
                            {data.leave_type === 'SPL' && data.spl_day_settings.length > 0 && (
                                <div className="space-y-3">
                                    <Label>Half-Day Settings per Day</Label>
                                    <p className="text-xs text-muted-foreground">
                                        Toggle half-day for days you only need a half day off. Half-day = 0.5 credits, whole day = 1.0 credits.
                                    </p>
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                        {data.spl_day_settings.map((daySetting, index) => (
                                            <div
                                                key={daySetting.date}
                                                className={`flex items-center justify-between p-3 border rounded-lg transition-colors ${daySetting.is_half_day
                                                    ? 'bg-violet-50 border-violet-200 dark:bg-violet-950/30 dark:border-violet-800'
                                                    : 'bg-card'
                                                    }`}
                                            >
                                                <div className="flex items-center gap-2">
                                                    <Calendar className="h-4 w-4 text-muted-foreground" />
                                                    <span className="text-sm font-medium">
                                                        {format(parseISO(daySetting.date), 'EEE, MMM d')}
                                                    </span>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <span className={`text-xs ${daySetting.is_half_day ? 'text-violet-600 font-medium dark:text-violet-400' : 'text-muted-foreground'}`}>
                                                        {daySetting.is_half_day ? 'Half Day (0.5)' : 'Whole Day (1.0)'}
                                                    </span>
                                                    <Switch
                                                        checked={daySetting.is_half_day}
                                                        onCheckedChange={(checked) => {
                                                            const updated = [...data.spl_day_settings];
                                                            updated[index] = { ...updated[index], is_half_day: checked };
                                                            setData('spl_day_settings', updated);
                                                        }}
                                                    />
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
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
                                                accept="image/jpeg,image/jpg,image/png,image/gif,image/webp,application/pdf"
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
                                                        JPEG, PNG, GIF, WebP or PDF (max 4MB)
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
                                            {medicalCertPreview && !isPdfFile ? (
                                                <div className="relative aspect-video max-h-48 overflow-hidden rounded-md bg-muted">
                                                    <img
                                                        src={medicalCertPreview}
                                                        alt="Medical certificate preview"
                                                        className="object-contain w-full h-full"
                                                    />
                                                </div>
                                            ) : medicalCertPreview && isPdfFile ? (
                                                <div className="space-y-2">
                                                    <div className="relative rounded-md overflow-hidden border bg-muted h-50">
                                                        <iframe
                                                            src={medicalCertPreview}
                                                            title="PDF preview"
                                                            className="w-full h-full"
                                                        />
                                                    </div>
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => window.open(medicalCertPreview!, '_blank')}
                                                        className="w-full"
                                                    >
                                                        <Eye className="h-4 w-4 mr-1" />
                                                        View Full Document
                                                    </Button>
                                                </div>
                                            ) : null}
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
                                <Alert className="border-red-300 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200">
                                    <AlertCircle className="h-4 w-4" />
                                    <AlertTitle className="text-red-800 dark:text-red-200">Cannot Submit Request</AlertTitle>
                                    <AlertDescription className="text-red-700 dark:text-red-300">
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
