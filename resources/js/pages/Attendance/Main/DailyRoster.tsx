import React, { useState, useEffect, useMemo } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { PageHeader } from '@/components/PageHeader';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { TimeInput } from '@/components/ui/time-input';
import { DatePicker } from '@/components/ui/date-picker';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { formatTime } from '@/lib/utils';
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
    DialogFooter,
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
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { getShiftTypeBadge, AttendanceStatusBadges } from '@/components/attendance';
import { Clock, AlertCircle, Users, Calendar, Check, Search, CheckCircle, Pencil, X, ExternalLink, ChevronsUpDown, Send } from 'lucide-react';
import { Switch } from '@/components/ui/switch';
import { useFlashMessage, usePageLoading, usePageMeta } from '@/hooks';
import { usePermission } from '@/hooks/use-permission';
import { hub as attendanceHub, dailyRoster, generate as generateAttendance, verify, requestUndertimeApproval, approveUndertime } from '@/routes/attendance';
import { LoadingOverlay } from '@/components/LoadingOverlay';

interface Schedule {
    id: number;
    shift_type: string;
    scheduled_time_in: string;
    scheduled_time_out: string;
    site_id: number | null;
    site_name: string | null;
    campaign_id: number | null;
    campaign_name: string | null;
    grace_period_minutes: number;
    work_days: string[];
}

interface ExistingAttendance {
    id: number;
    status: string;
    secondary_status: string | null;
    actual_time_in: string | null;
    actual_time_out: string | null;
    total_minutes_worked?: number;
    admin_verified: boolean;
    notes: string | null;
    verification_notes: string | null;
    overtime_approved: boolean;
    is_set_home?: boolean;
    undertime_minutes?: number;
    undertime_approval_status?: 'pending' | 'approved' | 'rejected' | null;
    undertime_approval_reason?: 'generate_points' | 'skip_points' | 'lunch_used' | null;
    undertime_approval_notes?: string;
}

interface OnLeave {
    id: number;
    leave_type: string;
}

interface Employee {
    id: number;
    name: string;
    email: string;
    schedule: Schedule | null;
    existing_attendance: ExistingAttendance | null;
    on_leave: OnLeave | null;
}

interface Site {
    id: number;
    name: string;
}

interface Campaign {
    id: number;
    name: string;
}

interface PendingTimeOut {
    id: number;
    user_id: number;
    name: string;
    email: string | null;
    shift_date: string;
    shift_date_formatted: string;
    actual_time_in: string | null;
    status: string;
    secondary_status: string | null;
    tardy_minutes: number | null;
    verification_notes: string | null;
    schedule: {
        id: number;
        shift_type: string;
        scheduled_time_in: string;
        scheduled_time_out: string;
        site_name: string | null;
        campaign_id: number | null;
        campaign_name: string | null;
        grace_period_minutes: number;
    } | null;
}

interface Props {
    employees: Employee[];
    sites: Site[];
    campaigns: Campaign[];
    teamLeadCampaignIds?: number[];
    selectedDate: string;
    dayName: string;
    pendingTimeOuts?: PendingTimeOut[];
    filters: {
        site_id?: string;
        campaign_id?: string;
        status?: string;
        search?: string;
        date?: string;
    };
}

/**
 * Get status badges for DailyRoster attendance display
 * Uses shared AttendanceStatusBadges with showManualLeaveLabel for this page
 */
const getStatusBadges = (attendance: ExistingAttendance) => {
    return (
        <AttendanceStatusBadges
            status={attendance.status}
            secondaryStatus={attendance.secondary_status}
            adminVerified={attendance.admin_verified}
            showManualLeaveLabel={true}
        />
    );
};

/**
 * Point values for each violation type (matching backend AttendancePoint::POINT_VALUES)
 */
const POINT_VALUES: Record<string, number> = {
    whole_day_absence: 1.00,
    half_day_absence: 0.50,
    undertime: 0.25,
    undertime_more_than_hour: 0.50,
    tardy: 0.25,
    ncns: 1.00, // No Call No Show = whole day
    failed_bio_in: 0.25, // Same as tardy
    failed_bio_out: 0.25, // Same as undertime
};

/**
 * Get the point value for a status
 */
const getPointValue = (status: string): number => {
    return POINT_VALUES[status] ?? 0;
};

/**
 * Calculate the suggested attendance status based on actual times and employee schedule.
 * Handles multiple violations and selects the higher point violation as primary.
 */
const calculateSuggestedStatus = (
    schedule: Schedule,
    shiftDate: string,
    actualTimeIn: string,
    actualTimeOut: string
): {
    status: string;
    secondaryStatus?: string;
    tardyMinutes?: number;
    undertimeMinutes?: number;
    overtimeMinutes?: number;
    reason: string;
    isPartial: boolean;
    violations: string[];
} => {
    const hasBioIn = !!actualTimeIn;
    const hasBioOut = !!actualTimeOut;

    // No bio at all
    if (!hasBioIn && !hasBioOut) {
        return {
            status: 'ncns',
            reason: 'No time in or time out recorded',
            isPartial: true,
            violations: ['ncns'],
        };
    }

    // Has time out but no time in
    if (!hasBioIn && hasBioOut) {
        return {
            status: 'failed_bio_in',
            reason: 'Missing time in record',
            isPartial: true,
            violations: ['failed_bio_in'],
        };
    }

    const gracePeriodMinutes = schedule.grace_period_minutes ?? 15;

    // Parse times
    const timeInDate = hasBioIn ? new Date(actualTimeIn) : null;
    const timeOutDate = hasBioOut ? new Date(actualTimeOut) : null;

    // Build scheduled times
    const [schedInHour, schedInMin] = schedule.scheduled_time_in.split(':').map(Number);
    const scheduledTimeIn = new Date(shiftDate + 'T00:00:00');
    scheduledTimeIn.setHours(schedInHour, schedInMin, 0, 0);

    const [schedOutHour, schedOutMin] = schedule.scheduled_time_out.split(':').map(Number);
    const scheduledTimeOut = new Date(shiftDate + 'T00:00:00');
    scheduledTimeOut.setHours(schedOutHour, schedOutMin, 0, 0);

    // Handle night shift (time out is next day)
    const isNightShift = schedule.shift_type === 'night_shift' ||
        (schedOutHour < schedInHour || (schedOutHour === schedInHour && schedOutMin <= schedInMin));
    if (isNightShift) {
        scheduledTimeOut.setDate(scheduledTimeOut.getDate() + 1);
    }

    let isTardy = false;
    let isHalfDay = false;
    let hasUndertime = false;
    let hasUndertimeMoreThanHour = false;
    let tardyMinutes: number | undefined;
    let undertimeMinutes: number | undefined;
    let overtimeMinutes: number | undefined;

    // Calculate tardiness (late arrival)
    if (timeInDate) {
        const diffMinutes = Math.floor((timeInDate.getTime() - scheduledTimeIn.getTime()) / (1000 * 60));
        if (diffMinutes > gracePeriodMinutes) {
            // More than grace period = half day absence
            isHalfDay = true;
            tardyMinutes = diffMinutes;
        } else if (diffMinutes >= 1) {
            // 1+ minutes late but within grace period = tardy
            isTardy = true;
            tardyMinutes = diffMinutes;
        }
    }

    // Calculate undertime/overtime
    if (timeOutDate) {
        const diffFromScheduledOut = Math.floor((timeOutDate.getTime() - scheduledTimeOut.getTime()) / (1000 * 60));
        if (diffFromScheduledOut < -60) {
            hasUndertimeMoreThanHour = true;
            hasUndertime = true;
            undertimeMinutes = Math.abs(diffFromScheduledOut);
        } else if (diffFromScheduledOut < 0) {
            hasUndertime = true;
            undertimeMinutes = Math.abs(diffFromScheduledOut);
        } else if (diffFromScheduledOut > 60) {
            overtimeMinutes = diffFromScheduledOut;
        }
    }

    // Collect all violations
    const violations: string[] = [];
    if (isHalfDay) violations.push('half_day_absence');
    if (isTardy) violations.push('tardy');
    if (hasUndertimeMoreThanHour) violations.push('undertime_more_than_hour');
    else if (hasUndertime) violations.push('undertime');
    if (!hasBioOut) violations.push('failed_bio_out');

    // Determine status based on violations, selecting higher point as primary
    let status: string;
    let secondaryStatus: string | undefined;
    let reason: string;
    const isPartial = !hasBioIn || !hasBioOut;

    if (!hasBioOut) {
        // Has time in but no time out - check for tardiness violations too
        if (isHalfDay) {
            // Half day (0.50) > failed_bio_out (0.25), so half_day is primary
            status = 'half_day_absence';
            secondaryStatus = 'failed_bio_out';
            reason = `Arrived ${tardyMinutes} minutes late (more than ${gracePeriodMinutes}min grace period), missing time out`;
        } else if (isTardy) {
            // Tardy (0.25) = failed_bio_out (0.25), tardy is primary (arrival issue first)
            status = 'tardy';
            secondaryStatus = 'failed_bio_out';
            reason = `Arrived ${tardyMinutes} minutes late, missing time out`;
        } else {
            status = 'failed_bio_out';
            reason = 'Missing time out record';
        }
    } else if (isHalfDay && (hasUndertime || hasUndertimeMoreThanHour)) {
        // Half day (0.50) >= undertime_more_than_hour (0.50) or undertime (0.25)
        status = 'half_day_absence';
        secondaryStatus = hasUndertimeMoreThanHour ? 'undertime_more_than_hour' : 'undertime';
        reason = `Arrived ${tardyMinutes} minutes late AND left ${undertimeMinutes} minutes early`;
    } else if (isHalfDay) {
        status = 'half_day_absence';
        reason = `Arrived ${tardyMinutes} minutes late (more than ${gracePeriodMinutes}min grace period)`;
    } else if (isTardy && hasUndertimeMoreThanHour) {
        // undertime_more_than_hour (0.50) > tardy (0.25), so undertime is primary
        status = 'undertime_more_than_hour';
        secondaryStatus = 'tardy';
        reason = `Left ${undertimeMinutes} minutes early AND arrived ${tardyMinutes} minutes late`;
    } else if (isTardy && hasUndertime) {
        // tardy (0.25) = undertime (0.25), tardy is primary (arrival issue first)
        status = 'tardy';
        secondaryStatus = 'undertime';
        reason = `Arrived ${tardyMinutes} minutes late AND left ${undertimeMinutes} minutes early`;
    } else if (isTardy) {
        status = 'tardy';
        reason = `Arrived ${tardyMinutes} minutes late`;
    } else if (hasUndertimeMoreThanHour) {
        status = 'undertime_more_than_hour';
        reason = `Left ${undertimeMinutes} minutes early (more than 1 hour)`;
    } else if (hasUndertime) {
        status = 'undertime';
        reason = `Left ${undertimeMinutes} minutes early`;
    } else {
        status = 'on_time';
        reason = 'Arrived on time';
    }

    return { status, secondaryStatus, tardyMinutes, undertimeMinutes, overtimeMinutes, reason, isPartial, violations };
};

export default function DailyRoster({ employees, sites, campaigns, teamLeadCampaignIds, selectedDate, dayName, pendingTimeOuts = [], filters }: Props) {
    useFlashMessage();
    const isPageLoading = usePageLoading();
    const { can } = usePermission();

    // Pre-compute permission checks for undertime approval
    const canApproveUndertime = can('attendance.approve_undertime');
    const canRequestUndertimeApproval = can('attendance.request_undertime_approval');

    // Detect if user is a Team Lead (teamLeadCampaignIds will be set if they are)
    const isTeamLead = !!teamLeadCampaignIds?.length;

    // Undertime approval state
    const [isRequestingUndertimeApproval, setIsRequestingUndertimeApproval] = useState(false);
    const [isApprovingUndertime, setIsApprovingUndertime] = useState(false);
    const [undertimeApprovalReason, setUndertimeApprovalReason] = useState<'generate_points' | 'skip_points' | 'lunch_used'>('lunch_used');

    const { title, breadcrumbs } = usePageMeta({
        title: `Daily Roster - ${dayName}`,
        breadcrumbs: [
            { title: 'Attendance', href: attendanceHub().url },
            { title: 'Daily Roster' },
        ],
    });

    const [isDialogOpen, setIsDialogOpen] = useState(false);
    const [isEditMode, setIsEditMode] = useState(false);
    const [selectedEmployee, setSelectedEmployee] = useState<Employee | null>(null);

    // "Time-in only" partial creation toggle: when true, the time-out field is
    // hidden and the record is created with is_partially_verified=true so that
    // the next-shift admin can complete the time-out via the Pending Time-Outs panel.
    const [partialOnly, setPartialOnly] = useState(false);

    // Active roster tab: 'time_out' (complete previous) | 'time_in' (record today's)
    // Default to Time-In; Complete Time-Out is a secondary step shown in the second tab.
    const [activeRosterTab, setActiveRosterTab] = useState<'time_out' | 'time_in'>('time_in');

    const [pendingDialogOpen, setPendingDialogOpen] = useState(false);
    const [activePending, setActivePending] = useState<PendingTimeOut | null>(null);
    const [timeOutValue, setTimeOutValue] = useState<string>('');
    const [timeOutNotes, setTimeOutNotes] = useState<string>('');
    const [isSubmittingTimeOut, setIsSubmittingTimeOut] = useState(false);
    const [overtimeApprovedForPending, setOvertimeApprovedForPending] = useState(false);
    const [suggestedStatus, setSuggestedStatus] = useState<{
        status: string;
        secondaryStatus?: string;
        overtimeMinutes?: number;
        undertimeMinutes?: number;
        reason: string;
        isPartial: boolean;
        violations: string[];
    } | null>(null);
    const [isStatusManuallyOverridden, setIsStatusManuallyOverridden] = useState(false);
    const [siteFilter, setSiteFilter] = useState(filters.site_id || 'all');
    // Auto-select Team Lead's campaign if no filter is applied
    const [campaignFilter, setCampaignFilter] = useState(() => {
        if (filters.campaign_id) return filters.campaign_id;
        return 'all';
    });
    const [statusFilter, setStatusFilter] = useState(filters.status || 'all');
    const [searchQuery, setSearchQuery] = useState(filters.search || '');
    const [dateFilter, setDateFilter] = useState(filters.date || selectedDate);

    // Employee search popover state
    const [isEmployeePopoverOpen, setIsEmployeePopoverOpen] = useState(false);
    const [employeeSearchQuery, setEmployeeSearchQuery] = useState('');
    const [selectedEmployeeId, setSelectedEmployeeId] = useState('');

    // Filter employees based on search query
    const filteredEmployees = useMemo(() => {
        if (!employeeSearchQuery) return employees;
        return employees.filter(emp =>
            emp.name.toLowerCase().includes(employeeSearchQuery.toLowerCase()) ||
            emp.email.toLowerCase().includes(employeeSearchQuery.toLowerCase())
        );
    }, [employees, employeeSearchQuery]);

    // Group employees by campaign, sorted alphabetically
    const groupedEmployees = useMemo(() => {
        const groups: Record<string, Employee[]> = {};
        for (const emp of employees) {
            const key = emp.schedule?.campaign_name || 'No Campaign';
            if (!groups[key]) groups[key] = [];
            groups[key].push(emp);
        }
        return Object.entries(groups)
            .sort(([a], [b]) => a.localeCompare(b))
            .map(([campaignName, emps]) => ({
                campaignName,
                employees: [...emps].sort((a, b) => a.name.localeCompare(b.name)),
            }));
    }, [employees]);

    // Get selected employee name for display
    const selectedEmployeeName = selectedEmployeeId
        ? employees.find(e => String(e.id) === selectedEmployeeId)?.name || 'Unknown'
        : 'All Employees';

    const { data, setData, post, processing, errors, reset } = useForm({
        user_id: '',
        shift_date: selectedDate,
        actual_time_in: '',
        actual_time_out: '',
        status: '',
        secondary_status: '',
        notes: '',
        verification_notes: '',
        overtime_approved: false,
        is_set_home: false,
    });

    // Statistics
    const totalEmployees = employees.length;
    const pendingCount = employees.filter(e => !e.existing_attendance && !e.on_leave).length;
    const recordedCount = employees.filter(e => e.existing_attendance).length;
    const onLeaveCount = employees.filter(e => e.on_leave).length;

    const handleGenerateClick = (employee: Employee) => {
        if (!employee.schedule) return;

        setSelectedEmployee(employee);
        setIsEditMode(false);

        // Prefill with schedule times
        const scheduleTimeIn = employee.schedule.scheduled_time_in;
        const scheduleTimeOut = employee.schedule.scheduled_time_out;

        // For night shift, time out is next day
        const isNightShift = employee.schedule.shift_type === 'night_shift';
        const timeOutDate = isNightShift
            ? new Date(new Date(selectedDate).getTime() + 24 * 60 * 60 * 1000).toISOString().split('T')[0]
            : selectedDate;

        const timeIn = `${selectedDate}T${scheduleTimeIn.slice(0, 5)}`;
        const timeOut = `${timeOutDate}T${scheduleTimeOut.slice(0, 5)}`;

        // Calculate initial status
        const suggestion = calculateSuggestedStatus(employee.schedule, selectedDate, timeIn, timeOut);
        setSuggestedStatus(suggestion);
        setIsStatusManuallyOverridden(false);
        setPartialOnly(false);

        setData({
            user_id: String(employee.id),
            shift_date: selectedDate,
            actual_time_in: timeIn,
            actual_time_out: timeOut,
            status: suggestion.status,
            secondary_status: suggestion.secondaryStatus || '',
            notes: '',
            verification_notes: '',
            overtime_approved: false,
            is_set_home: false,
        });

        setIsDialogOpen(true);
    };

    const handleEditClick = (employee: Employee) => {
        if (!employee.schedule || !employee.existing_attendance) return;

        setSelectedEmployee(employee);
        setIsEditMode(true);

        const attendance = employee.existing_attendance;
        const timeIn = attendance.actual_time_in || '';
        const timeOut = attendance.actual_time_out || '';

        // Calculate suggested status based on existing times
        if (timeIn && timeOut) {
            const suggestion = calculateSuggestedStatus(employee.schedule, selectedDate, timeIn, timeOut);
            setSuggestedStatus(suggestion);
        } else {
            setSuggestedStatus(null);
        }
        setIsStatusManuallyOverridden(false);

        // Pre-fill form with existing attendance data
        setData({
            user_id: String(employee.id),
            shift_date: selectedDate,
            actual_time_in: timeIn,
            actual_time_out: timeOut,
            status: attendance.status,
            secondary_status: attendance.secondary_status || '',
            notes: attendance.notes || '',
            verification_notes: attendance.verification_notes || '',
            overtime_approved: attendance.overtime_approved || false,
            is_set_home: attendance.is_set_home || false,
        });

        setIsDialogOpen(true);
    };

    // Recalculate status when times change
    const recalculateSuggestedStatus = (timeIn: string, timeOut: string) => {
        if (!selectedEmployee?.schedule) return;

        const suggestion = calculateSuggestedStatus(
            selectedEmployee.schedule,
            selectedDate,
            timeIn,
            timeOut
        );

        setSuggestedStatus(suggestion);

        // Only auto-update status if user hasn't manually overridden it
        if (!isStatusManuallyOverridden) {
            setData('status', suggestion.status);
            setData('secondary_status', suggestion.secondaryStatus || '');
        }
    };

    // Handle status change - clear appropriate time fields based on status
    const handleStatusChange = (newStatus: string) => {
        setData('status', newStatus);

        // Clear time fields based on selected status
        if (newStatus === 'failed_bio_in') {
            // Failed bio in means no time in record
            setData('actual_time_in', '');
        } else if (newStatus === 'failed_bio_out') {
            // Failed bio out means no time out record
            setData('actual_time_out', '');
        } else if (newStatus === 'ncns' || newStatus === 'advised_absence' || newStatus === 'on_leave') {
            // NCNS, Advised Absence, or On Leave means no time in or time out
            setData('actual_time_in', '');
            setData('actual_time_out', '');
        } else if (newStatus === 'present_no_bio' || newStatus === 'non_work_day') {
            // Present but no biometric records / Non-work day
            setData('actual_time_in', '');
            setData('actual_time_out', '');
        }

        if (suggestedStatus && newStatus !== suggestedStatus.status) {
            setIsStatusManuallyOverridden(true);
        } else {
            setIsStatusManuallyOverridden(false);
        }
    };

    // Statuses that should bypass partial verification (fully verified even without times)
    const statusesBypassingPartialVerification = ['ncns', 'advised_absence', 'present_no_bio', 'on_leave', 'non_work_day', 'failed_bio_in', 'failed_bio_out'];
    const shouldShowPartialVerification = suggestedStatus?.isPartial &&
        !statusesBypassingPartialVerification.includes(data.status);

    // Reset to suggested status
    const resetToSuggestedStatus = () => {
        if (suggestedStatus) {
            setData('status', suggestedStatus.status);
            setData('secondary_status', suggestedStatus.secondaryStatus || '');
            setIsStatusManuallyOverridden(false);
        }
    };

    // Recalculate when times change
    useEffect(() => {
        if (!selectedEmployee || !isDialogOpen) return;

        const timer = setTimeout(() => {
            // For partial-only creation, synthesize a time-out equal to the scheduled
            // time-out so the suggester ignores failed_bio_out / undertime checks and
            // only evaluates tardiness from the actual time-in.
            let timeOutForCalc = data.actual_time_out;
            if (partialOnly && selectedEmployee.schedule && data.actual_time_in) {
                const isNight = selectedEmployee.schedule.shift_type === 'night_shift'
                    || selectedEmployee.schedule.shift_type === 'graveyard_shift';
                const outDate = isNight
                    ? new Date(new Date(selectedDate).getTime() + 24 * 60 * 60 * 1000).toISOString().split('T')[0]
                    : selectedDate;
                timeOutForCalc = `${outDate}T${selectedEmployee.schedule.scheduled_time_out.slice(0, 5)}`;
            }
            recalculateSuggestedStatus(data.actual_time_in, timeOutForCalc);
        }, 300);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data.actual_time_in, data.actual_time_out, selectedEmployee?.id, isDialogOpen, partialOnly]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        if (isEditMode && selectedEmployee?.existing_attendance) {
            // Update existing attendance via verify endpoint
            post(verify(selectedEmployee.existing_attendance.id).url, {
                onSuccess: () => {
                    setIsDialogOpen(false);
                    setSelectedEmployee(null);
                    setIsEditMode(false);
                    reset();
                },
            });
        } else {
            // For partial-only creation, blank out the time-out so the backend
            // creates a partially-verified record (time-out completed next shift).
            if (partialOnly) {
                setData('actual_time_out', '');
            }

            post(generateAttendance().url, {
                onSuccess: () => {
                    setIsDialogOpen(false);
                    setSelectedEmployee(null);
                    setPartialOnly(false);
                    reset();
                },
            });
        }
    };

    // Open the small "Add Time-Out" dialog for a pending record from the previous shift.
    const openPendingTimeOutDialog = (pending: PendingTimeOut) => {
        if (!pending.schedule) return;
        setActivePending(pending);

        // Suggested time-out: schedule's time-out on the day after shift_date for night shifts,
        // or same day for other shifts.
        const isNight = pending.schedule.shift_type === 'night_shift'
            || pending.schedule.shift_type === 'graveyard_shift';
        const suggestedDate = isNight
            ? new Date(new Date(pending.shift_date).getTime() + 24 * 60 * 60 * 1000).toISOString().split('T')[0]
            : pending.shift_date;
        setTimeOutValue(`${suggestedDate}T${pending.schedule.scheduled_time_out.slice(0, 5)}`);
        setTimeOutNotes('');
        setPendingDialogOpen(true);
    };

    // Live-recalculate suggestion as the admin types a time-out value.
    const pendingTimeOutSuggestion = useMemo(() => {
        if (!activePending?.schedule || !timeOutValue) return null;
        return calculateSuggestedStatus(
            { ...activePending.schedule, work_days: [] } as unknown as Schedule,
            activePending.shift_date,
            activePending.actual_time_in || '',
            timeOutValue,
        );
    }, [activePending, timeOutValue]);

    const submitPendingTimeOut = (e: React.FormEvent) => {
        e.preventDefault();
        if (!activePending || !activePending.schedule || !timeOutValue) return;

        // Use the already-computed live suggestion.
        const suggestion = pendingTimeOutSuggestion ?? calculateSuggestedStatus(
            { ...activePending.schedule, work_days: [] } as unknown as Schedule,
            activePending.shift_date,
            activePending.actual_time_in || '',
            timeOutValue,
        );

        setIsSubmittingTimeOut(true);
        router.post(
            verify(activePending.id).url,
            {
                status: suggestion.status,
                secondary_status: suggestion.secondaryStatus || '',
                actual_time_in: activePending.actual_time_in,
                actual_time_out: timeOutValue,
                overtime_approved: overtimeApprovedForPending,
                verification_notes: timeOutNotes || `Time-out completed on ${new Date().toISOString().split('T')[0]}.`,
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    setIsSubmittingTimeOut(false);
                    setPendingDialogOpen(false);
                    setActivePending(null);
                },
            },
        );
    };

    const handleApplyFilters = () => {
        router.get(dailyRoster().url, {
            site_id: siteFilter === 'all' ? undefined : siteFilter,
            campaign_id: campaignFilter === 'all' ? undefined : campaignFilter,
            status: statusFilter === 'all' ? undefined : statusFilter,
            search: searchQuery || undefined,
            date: dateFilter || undefined,
        }, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handleClearFilters = () => {
        setSiteFilter('all');
        setCampaignFilter('all');
        setStatusFilter('all');
        setSearchQuery('');
        setSelectedEmployeeId('');
        setEmployeeSearchQuery('');
        setDateFilter(new Date().toISOString().split('T')[0]);
        router.get(dailyRoster().url, {}, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const hasFilters = filters.site_id || filters.campaign_id || filters.status || filters.search || filters.date;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                <LoadingOverlay isLoading={isPageLoading || processing} />

                <PageHeader
                    title={title}
                    description={`Employees expected to work on ${selectedDate} (${dayName}) based on their schedules`}
                />

                {/* Filters Row */}
                <div className="flex flex-col gap-3">
                    <div className="flex flex-col lg:flex-row lg:flex-wrap gap-3">
                        {/* Employee Search */}
                        <Popover open={isEmployeePopoverOpen} onOpenChange={setIsEmployeePopoverOpen}>
                            <PopoverTrigger asChild>
                                <Button
                                    variant="outline"
                                    role="combobox"
                                    aria-expanded={isEmployeePopoverOpen}
                                    className="w-full justify-between font-normal lg:flex-1 lg:min-w-50"
                                >
                                    <span className="truncate">
                                        {selectedEmployeeName}
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
                                                    setSelectedEmployeeId('');
                                                    setSearchQuery('');
                                                    setIsEmployeePopoverOpen(false);
                                                    setEmployeeSearchQuery('');
                                                }}
                                                className="cursor-pointer"
                                            >
                                                <Check
                                                    className={`mr-2 h-4 w-4 ${!selectedEmployeeId ? 'opacity-100' : 'opacity-0'}`}
                                                />
                                                All Employees
                                            </CommandItem>
                                            {filteredEmployees.map((emp) => (
                                                <CommandItem
                                                    key={emp.id}
                                                    value={emp.name}
                                                    onSelect={() => {
                                                        setSelectedEmployeeId(String(emp.id));
                                                        setSearchQuery(emp.name);
                                                        setIsEmployeePopoverOpen(false);
                                                        setEmployeeSearchQuery('');
                                                    }}
                                                    className="cursor-pointer"
                                                >
                                                    <Check
                                                        className={`mr-2 h-4 w-4 ${selectedEmployeeId === String(emp.id) ? 'opacity-100' : 'opacity-0'}`}
                                                    />
                                                    <div className="flex flex-col">
                                                        <span>{emp.name}</span>
                                                        <span className="text-xs text-muted-foreground">{emp.email}</span>
                                                    </div>
                                                </CommandItem>
                                            ))}
                                        </CommandGroup>
                                    </CommandList>
                                </Command>
                            </PopoverContent>
                        </Popover>

                        {/* Date Selection */}
                        <DatePicker
                            value={dateFilter}
                            onChange={(value) => setDateFilter(value)}
                            placeholder="Select date"
                            className="lg:flex-1 lg:min-w-40"
                        />

                        <Select value={siteFilter} onValueChange={setSiteFilter}>
                            <SelectTrigger className="lg:flex-1 lg:min-w-30">
                                <SelectValue placeholder="All Sites" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Sites</SelectItem>
                                {sites.map((site) => (
                                    <SelectItem key={site.id} value={String(site.id)}>
                                        {site.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select value={campaignFilter} onValueChange={setCampaignFilter}>
                            <SelectTrigger className="lg:flex-1 lg:min-w-35">
                                <SelectValue placeholder="All Campaigns" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Campaigns</SelectItem>
                                {(teamLeadCampaignIds?.length ? campaigns.filter(c => teamLeadCampaignIds.includes(c.id)) : campaigns).map((campaign) => (
                                    <SelectItem key={campaign.id} value={String(campaign.id)}>
                                        {campaign.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select value={statusFilter} onValueChange={setStatusFilter}>
                            <SelectTrigger className="lg:flex-1 lg:min-w-32.5">
                                <SelectValue placeholder="All Statuses" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Statuses</SelectItem>
                                <SelectItem value="pending">Pending Entry</SelectItem>
                                <SelectItem value="recorded">Already Recorded</SelectItem>
                                <SelectItem value="on_leave">On Leave</SelectItem>
                            </SelectContent>
                        </Select>

                        <div className="flex gap-2">
                            <Button variant="default" onClick={handleApplyFilters}>
                                <Search className="mr-2 h-4 w-4" />
                                Search
                            </Button>
                            {hasFilters && (
                                <Button variant="outline" onClick={handleClearFilters} size="icon">
                                    <X className="h-4 w-4" />
                                </Button>
                            )}
                        </div>
                    </div>
                </div>

                {/* Roster Tabs */}
                <Tabs value={activeRosterTab} onValueChange={(v) => setActiveRosterTab(v as 'time_out' | 'time_in')}>
                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <TabsList>
                            <TabsTrigger value="time_in" className="flex items-center gap-1.5">
                                <Users className="h-4 w-4" />
                                <span>Record Time-In</span>
                            </TabsTrigger>
                            <TabsTrigger value="time_out" className="flex items-center gap-1.5">
                                <Clock className="h-4 w-4" />
                                <span>Complete Time-Out</span>
                                {pendingTimeOuts.length > 0 && (
                                    <Badge className="ml-1 h-5 px-1.5 text-xs bg-amber-600 hover:bg-amber-600">
                                        {pendingTimeOuts.length}
                                    </Badge>
                                )}
                            </TabsTrigger>
                        </TabsList>

                        {/* Statistics — always visible, tab-independent */}
                        <div className="flex flex-wrap items-center gap-4 text-sm">
                            <div className="flex items-center gap-1.5">
                                <Users className="h-4 w-4 text-muted-foreground" />
                                <span className="font-medium">{totalEmployees}</span>
                                <span className="text-muted-foreground">Total</span>
                            </div>
                            <div className="flex items-center gap-1.5">
                                <Clock className="h-4 w-4 text-yellow-500" />
                                <span className="font-medium">{pendingCount}</span>
                                <span className="text-muted-foreground">Pending</span>
                            </div>
                            <div className="flex items-center gap-1.5">
                                <Check className="h-4 w-4 text-green-500" />
                                <span className="font-medium">{recordedCount}</span>
                                <span className="text-muted-foreground">Recorded</span>
                            </div>
                            <div className="flex items-center gap-1.5">
                                <Calendar className="h-4 w-4 text-blue-500" />
                                <span className="font-medium">{onLeaveCount}</span>
                                <span className="text-muted-foreground">On Leave</span>
                            </div>
                            {hasFilters && <Badge variant="secondary" className="font-normal">Filtered</Badge>}
                        </div>
                    </div>

                    {/* ── TAB: Complete Time-Out ── */}
                    <TabsContent value="time_out" className="mt-4">
                        {pendingTimeOuts.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-16 text-center border rounded-lg bg-card">
                                <Check className="h-10 w-10 text-green-500 mb-3" />
                                <p className="font-medium text-lg">All time-outs are complete</p>
                                <p className="text-sm text-muted-foreground mt-1">
                                    No pending time-outs from previous shifts.
                                </p>
                                <Button
                                    variant="outline"
                                    className="mt-4"
                                    onClick={() => setActiveRosterTab('time_in')}
                                >
                                    ← Back to Record Time-In
                                </Button>
                            </div>
                        ) : (
                            <div className="border rounded-lg overflow-hidden">
                                <div className="px-4 py-3 bg-amber-50 dark:bg-amber-950/20 border-b border-amber-200 dark:border-amber-800">
                                    <p className="text-sm text-amber-800 dark:text-amber-300 font-medium">
                                        Complete time-outs from previous shifts before recording today's time-ins.
                                    </p>
                                </div>

                                {/* Desktop */}
                                <div className="hidden md:block">
                                    <Table className="table-fixed w-full">
                                        <TableHeader>
                                            <TableRow className="bg-muted/50">
                                                <TableHead className="w-[24%]">Employee</TableHead>
                                                <TableHead className="w-[14%]">Shift Date</TableHead>
                                                <TableHead className="w-[14%]">Shift</TableHead>
                                                <TableHead className="w-[14%]">Time In</TableHead>
                                                <TableHead className="w-[16%]">Scheduled Out</TableHead>
                                                {!isTeamLead && <TableHead className="w-[18%] text-right pr-4">Action</TableHead>}
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {pendingTimeOuts.map((p) => (
                                                <TableRow key={p.id} className="hover:bg-muted/30">
                                                    <TableCell>
                                                        <div className="font-medium truncate">{p.name}</div>
                                                        {p.schedule?.campaign_name && (
                                                            <div className="text-xs text-muted-foreground">{p.schedule.campaign_name}</div>
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-sm">{p.shift_date_formatted}</TableCell>
                                                    <TableCell>
                                                        {p.schedule ? getShiftTypeBadge(p.schedule.shift_type) : <span className="text-muted-foreground">—</span>}
                                                    </TableCell>
                                                    <TableCell className="font-mono text-sm">
                                                        {p.actual_time_in?.split('T')[1]?.slice(0, 5) ?? '—'}
                                                    </TableCell>
                                                    <TableCell className="font-mono text-sm">
                                                        {p.schedule ? formatTime(p.schedule.scheduled_time_out) : '—'}
                                                    </TableCell>
                                                    {!isTeamLead && (
                                                        <TableCell className="text-right">
                                                            <Button
                                                                size="sm"
                                                                onClick={() => openPendingTimeOutDialog(p)}
                                                            >
                                                                <Clock className="h-4 w-4 mr-1" />
                                                                Add Time-Out
                                                            </Button>
                                                        </TableCell>
                                                    )}
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>

                                {/* Mobile */}
                                <div className="md:hidden divide-y">
                                    {pendingTimeOuts.map((p) => (
                                        <div key={p.id} className="px-4 py-3 flex items-center gap-3">
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-1.5">
                                                    <span className="font-medium">{p.name}</span>
                                                    {p.schedule && getShiftTypeBadge(p.schedule.shift_type)}
                                                </div>
                                                <div className="text-xs text-muted-foreground mt-0.5">
                                                    {p.shift_date_formatted} · In: <span className="font-mono">{p.actual_time_in?.split('T')[1]?.slice(0, 5) ?? '—'}</span>
                                                    {p.schedule && <> · Sched Out: <span className="font-mono">{formatTime(p.schedule.scheduled_time_out)}</span></>}
                                                </div>
                                            </div>
                                            {!isTeamLead && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() => openPendingTimeOutDialog(p)}
                                                    className="shrink-0"
                                                >
                                                    <Clock className="h-3.5 w-3.5 mr-1" />
                                                    Add Time-Out
                                                </Button>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </TabsContent>

                    {/* ── TAB: Record Time-In ── */}
                    <TabsContent value="time_in" className="mt-4">

                        {/* Desktop Table */}
                        <div className="hidden md:block border rounded-lg overflow-hidden">
                            <Table className="table-fixed w-full">
                                <TableHeader>
                                    <TableRow className="bg-muted/50">
                                        <TableHead className="w-[22%]">Employee</TableHead>
                                        <TableHead className="w-[18%]">Site / Campaign</TableHead>
                                        <TableHead className="w-[12%]">Shift</TableHead>
                                        <TableHead className="w-[18%]">Schedule</TableHead>
                                        <TableHead className="w-[15%]">Status</TableHead>
                                        {!isTeamLead && <TableHead className="w-[15%] text-right pr-4">Actions</TableHead>}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {groupedEmployees.map(({ campaignName, employees: groupEmps }) => (
                                        <React.Fragment key={campaignName}>
                                            <TableRow className="bg-muted/60 hover:bg-muted/60">
                                                <TableCell colSpan={isTeamLead ? 5 : 6} className="py-2 px-4">
                                                    <div className="flex items-center gap-2">
                                                        <span className="font-semibold text-sm">{campaignName}</span>
                                                        <Badge variant="secondary" className="font-normal text-xs">{groupEmps.length}</Badge>
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                            {groupEmps.map((employee) => (
                                                <TableRow key={employee.id} className="hover:bg-muted/30">
                                                    <TableCell>
                                                        <div>
                                                            <div className="font-medium truncate">{employee.name}</div>
                                                            <div className="text-xs text-muted-foreground truncate">{employee.email}</div>
                                                        </div>
                                                    </TableCell>
                                                    <TableCell>
                                                        {employee.schedule ? (
                                                            <div>
                                                                <div className="font-medium truncate">{employee.schedule.site_name || 'No site'}</div>
                                                                {employee.schedule.campaign_name && (
                                                                    <div className="text-xs text-muted-foreground">
                                                                        {employee.schedule.campaign_name}
                                                                    </div>
                                                                )}
                                                            </div>
                                                        ) : (
                                                            <span className="text-muted-foreground">-</span>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        {employee.schedule ? (
                                                            getShiftTypeBadge(employee.schedule.shift_type)
                                                        ) : (
                                                            <span className="text-muted-foreground">-</span>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        {employee.schedule ? (
                                                            <span className="text-sm whitespace-nowrap">
                                                                {formatTime(employee.schedule.scheduled_time_in)} - {formatTime(employee.schedule.scheduled_time_out)}
                                                            </span>
                                                        ) : (
                                                            <span className="text-muted-foreground">-</span>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        {employee.on_leave ? (
                                                            <div className="flex items-center gap-1">
                                                                <Badge className="bg-blue-600">
                                                                    On {employee.on_leave.leave_type}
                                                                </Badge>
                                                                {employee.existing_attendance?.admin_verified && (
                                                                    <span title="Attendance verified"><CheckCircle className="h-3.5 w-3.5 text-green-500" /></span>
                                                                )}
                                                            </div>
                                                        ) : employee.existing_attendance ? (
                                                            getStatusBadges(employee.existing_attendance)
                                                        ) : (
                                                            <Badge variant="outline" className="text-yellow-600 border-yellow-600">
                                                                Pending Entry
                                                            </Badge>
                                                        )}
                                                    </TableCell>
                                                    {!isTeamLead && (
                                                        <TableCell className="text-right">
                                                            <div className="flex items-center justify-end gap-1">
                                                                {employee.on_leave && (
                                                                    <Button
                                                                        size="sm"
                                                                        variant="ghost"
                                                                        className="h-8 px-2"
                                                                        onClick={() => window.open(`/attendance/review?date_from=${selectedDate}&date_to=${selectedDate}&user_id=${employee.id}`, '_blank')}
                                                                    >
                                                                        <ExternalLink className="h-4 w-4 mr-1" />
                                                                        Review
                                                                    </Button>
                                                                )}
                                                                {!employee.on_leave && !employee.existing_attendance && employee.schedule && (
                                                                    <Button
                                                                        size="sm"
                                                                        className="h-8"
                                                                        onClick={() => handleGenerateClick(employee)}
                                                                    >
                                                                        Generate
                                                                    </Button>
                                                                )}
                                                                {!employee.on_leave && employee.existing_attendance && employee.schedule && (
                                                                    <Button
                                                                        size="sm"
                                                                        variant="ghost"
                                                                        className="h-8 px-2"
                                                                        onClick={() => handleEditClick(employee)}
                                                                    >
                                                                        <Pencil className="h-4 w-4 mr-1" />
                                                                        Edit
                                                                    </Button>
                                                                )}
                                                            </div>
                                                        </TableCell>
                                                    )}
                                                </TableRow>
                                            ))}
                                        </React.Fragment>
                                    ))}
                                    {groupedEmployees.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={isTeamLead ? 5 : 6} className="h-24 text-center text-muted-foreground">
                                                No employees expected to work today
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </div>

                        {/* Mobile Cards */}
                        <div className="md:hidden space-y-4">
                            {groupedEmployees.map(({ campaignName, employees: groupEmps }) => (
                                <div key={campaignName}>
                                    <div className="flex items-center gap-2 px-1 py-2">
                                        <span className="font-semibold text-sm">{campaignName}</span>
                                        <Badge variant="secondary" className="font-normal text-xs">{groupEmps.length}</Badge>
                                    </div>
                                    <div className="space-y-3">
                                        {groupEmps.map((employee) => (
                                            <div key={employee.id} className="bg-card border rounded-lg p-4 shadow-sm space-y-3">
                                                <div className="flex justify-between items-start">
                                                    <div>
                                                        <div className="text-lg font-semibold">{employee.name}</div>
                                                        <div className="text-sm text-muted-foreground">{employee.email}</div>
                                                    </div>
                                                    {employee.on_leave ? (
                                                        <div className="flex flex-col items-end gap-1">
                                                            <Badge className="bg-blue-600">
                                                                On {employee.on_leave.leave_type}
                                                            </Badge>
                                                            {employee.existing_attendance?.admin_verified && (
                                                                <span title="Attendance verified"><CheckCircle className="h-4 w-4 text-green-500" /></span>
                                                            )}
                                                        </div>
                                                    ) : employee.existing_attendance ? (
                                                        <div className="flex flex-wrap items-end justify-end gap-1">
                                                            {getStatusBadges(employee.existing_attendance)}
                                                        </div>
                                                    ) : (
                                                        <Badge variant="outline" className="text-yellow-600 border-yellow-600">
                                                            Pending
                                                        </Badge>
                                                    )}
                                                </div>

                                                {employee.schedule && (
                                                    <div className="space-y-2 text-sm">
                                                        <div>
                                                            <span className="font-medium">Site:</span> {employee.schedule.site_name || 'No site'}
                                                            {employee.schedule.campaign_name && (
                                                                <span className="text-muted-foreground"> / {employee.schedule.campaign_name}</span>
                                                            )}
                                                        </div>
                                                        <div className="flex items-center gap-2">
                                                            <span className="font-medium">Shift:</span>
                                                            {getShiftTypeBadge(employee.schedule.shift_type)}
                                                        </div>
                                                        <div>
                                                            <span className="font-medium">Schedule:</span>{' '}
                                                            {formatTime(employee.schedule.scheduled_time_in)} - {formatTime(employee.schedule.scheduled_time_out)}
                                                        </div>
                                                    </div>
                                                )}

                                                {!isTeamLead && (
                                                    <div className="pt-2 border-t flex gap-2">
                                                        {employee.on_leave && (
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                className="flex-1"
                                                                onClick={() => window.open(`/attendance/review?date_from=${selectedDate}&date_to=${selectedDate}&user_id=${employee.id}`, '_blank')}
                                                            >
                                                                <ExternalLink className="h-4 w-4 mr-1" />
                                                                Review in New Tab
                                                            </Button>
                                                        )}
                                                        {!employee.on_leave && !employee.existing_attendance && employee.schedule && (
                                                            <Button
                                                                size="sm"
                                                                className="flex-1"
                                                                onClick={() => handleGenerateClick(employee)}
                                                            >
                                                                Generate Attendance
                                                            </Button>
                                                        )}
                                                        {!employee.on_leave && employee.existing_attendance && employee.schedule && (
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                className="flex-1"
                                                                onClick={() => handleEditClick(employee)}
                                                            >
                                                                <Pencil className="h-4 w-4 mr-1" />
                                                                Edit Record
                                                            </Button>
                                                        )}
                                                    </div>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            ))}
                            {groupedEmployees.length === 0 && (
                                <div className="py-12 text-center text-gray-500 border rounded-lg bg-card">
                                    No employees expected to work today
                                </div>
                            )}
                        </div>
                    </TabsContent>
                </Tabs>
            </div>

            {/* Generate/Edit Attendance Dialog */}
            <Dialog open={isDialogOpen} onOpenChange={(open) => {
                setIsDialogOpen(open);
                if (!open) {
                    setIsEditMode(false);
                    setSelectedEmployee(null);
                    reset();
                }
            }}>
                <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>{isEditMode ? 'Edit Attendance Record' : 'Generate & Verify Attendance'}</DialogTitle>
                        <DialogDescription>
                            {isEditMode
                                ? `Update attendance record for ${selectedEmployee?.name}`
                                : `Create and verify attendance record for ${selectedEmployee?.name}`
                            }
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={handleSubmit} className="space-y-4">
                        {/* Schedule Info */}
                        {selectedEmployee?.schedule && (
                            <div className="bg-muted p-4 rounded-md space-y-2 text-sm">
                                <h4 className="font-semibold">Schedule Information</h4>
                                <div className="grid grid-cols-2 gap-2">
                                    <div>
                                        <span className="text-muted-foreground">Site:</span>{' '}
                                        {selectedEmployee.schedule.site_name || 'N/A'}
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <span className="text-muted-foreground">Shift:</span>
                                        {getShiftTypeBadge(selectedEmployee.schedule.shift_type)}
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">Scheduled In:</span>{' '}
                                        {formatTime(selectedEmployee.schedule.scheduled_time_in)}
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">Scheduled Out:</span>{' '}
                                        {formatTime(selectedEmployee.schedule.scheduled_time_out)}
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">Grace Period:</span>{' '}
                                        {selectedEmployee.schedule.grace_period_minutes} minutes
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* Partial Verification Warning */}
                        {shouldShowPartialVerification && !partialOnly && (
                            <div className="p-3 rounded-lg border bg-orange-50 border-orange-200 dark:bg-orange-950/20 dark:border-orange-800">
                                <div className="flex items-center gap-2">
                                    <AlertCircle className="h-4 w-4 text-orange-600" />
                                    <span className="text-sm font-medium text-orange-800 dark:text-orange-400">
                                        Partial Verification
                                    </span>
                                </div>
                                <p className="text-xs text-orange-700 dark:text-orange-500 mt-1">
                                    {!data.actual_time_in && !data.actual_time_out
                                        ? 'Both time in and time out are missing. Points will be generated based on violations.'
                                        : !data.actual_time_in
                                            ? 'Time in is missing. Record will be partially verified with points generated.'
                                            : 'Time out is missing. Record will be partially verified with points generated.'
                                    }
                                </p>
                            </div>
                        )}

                        {/* Violations Summary with Points */}
                        {!partialOnly && suggestedStatus && suggestedStatus.violations.length > 0 && suggestedStatus.status !== 'on_time' && (
                            <div className="p-3 rounded-lg border bg-red-50 border-red-200 dark:bg-red-950/20 dark:border-red-800">
                                <div className="flex items-center gap-2 mb-2">
                                    <AlertCircle className="h-4 w-4 text-red-600" />
                                    <span className="text-sm font-medium text-red-800 dark:text-red-400">
                                        Detected Violations
                                    </span>
                                </div>
                                <div className="space-y-1">
                                    {suggestedStatus.violations.map((violation, index) => (
                                        <div key={violation} className="flex justify-between items-center text-xs">
                                            <span className={`${index === 0 ? 'font-medium text-red-700 dark:text-red-400' : 'text-red-600 dark:text-red-500'}`}>
                                                {index === 0 ? '▶ ' : '  '}{violation.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}
                                                {index === 0 && ' (Primary)'}
                                                {index === 1 && ' (Secondary)'}
                                            </span>
                                            <Badge variant="outline" className="text-red-600 border-red-400 text-xs h-5">
                                                {getPointValue(violation).toFixed(2)} pts
                                            </Badge>
                                        </div>
                                    ))}
                                </div>
                                <p className="text-xs text-red-600 dark:text-red-500 mt-2 pt-2 border-t border-red-200 dark:border-red-800">
                                    Higher point violation is selected as primary status. Points will be generated for both violations.
                                </p>
                            </div>
                        )}

                        {/* Status Selection */}
                        <div className="space-y-2">
                            <Label htmlFor="status">
                                Status <span className="text-red-500">*</span>
                            </Label>

                            {/* Suggested Status Info */}
                            {suggestedStatus && (
                                <div className={`p-3 rounded-lg border ${isStatusManuallyOverridden ? 'bg-amber-50 border-amber-200 dark:bg-amber-950/20 dark:border-amber-800' : 'bg-green-50 border-green-200 dark:bg-green-950/20 dark:border-green-800'}`}>
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="flex-1">
                                            <div className="flex items-center gap-2 mb-1">
                                                {isStatusManuallyOverridden ? (
                                                    <AlertCircle className="h-4 w-4 text-amber-600" />
                                                ) : (
                                                    <CheckCircle className="h-4 w-4 text-green-600" />
                                                )}
                                                <span className={`text-sm font-medium ${isStatusManuallyOverridden ? 'text-amber-800 dark:text-amber-400' : 'text-green-800 dark:text-green-400'}`}>
                                                    {isStatusManuallyOverridden ? 'Manual Override Active' : 'Auto-Suggested Status'}
                                                </span>
                                            </div>
                                            <p className={`text-xs ${isStatusManuallyOverridden ? 'text-amber-700 dark:text-amber-500' : 'text-green-700 dark:text-green-500'}`}>
                                                {isStatusManuallyOverridden
                                                    ? `Suggested: ${suggestedStatus.status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())} — ${suggestedStatus.reason}`
                                                    : suggestedStatus.reason
                                                }
                                            </p>
                                            {suggestedStatus.secondaryStatus && (
                                                <p className="text-xs text-muted-foreground mt-1">
                                                    Secondary: {suggestedStatus.secondaryStatus.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}
                                                </p>
                                            )}
                                        </div>
                                        {isStatusManuallyOverridden && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={resetToSuggestedStatus}
                                                className="h-7 text-xs text-amber-700 hover:text-amber-900 hover:bg-amber-100"
                                            >
                                                Use Suggested
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            )}

                            <Select value={data.status} onValueChange={handleStatusChange}>
                                <SelectTrigger className={isStatusManuallyOverridden ? 'border-amber-400' : ''}>
                                    <SelectValue placeholder="Select status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="on_time">On Time</SelectItem>
                                    <SelectItem value="tardy">Tardy</SelectItem>
                                    <SelectItem value="half_day_absence">Half Day Absence</SelectItem>
                                    <SelectItem value="advised_absence">Advised Absence</SelectItem>
                                    <SelectItem value="on_leave">On Leave</SelectItem>
                                    <SelectItem value="ncns">NCNS</SelectItem>
                                    <SelectItem value="undertime">Undertime</SelectItem>
                                    <SelectItem value="undertime_more_than_hour">Undertime (&gt;1hr)</SelectItem>
                                    <SelectItem value="failed_bio_in">Failed Bio In</SelectItem>
                                    <SelectItem value="failed_bio_out">Failed Bio Out</SelectItem>
                                    <SelectItem value="present_no_bio">Present (No Bio)</SelectItem>
                                    <SelectItem value="non_work_day">Non-Work Day</SelectItem>
                                </SelectContent>
                            </Select>
                            {errors.status && <p className="text-sm text-red-500">{errors.status}</p>}
                        </div>

                        {/* Time In */}
                        <div className="space-y-2">
                            <Label>Actual Time In</Label>
                            <div className="grid grid-cols-2 gap-2">
                                <DatePicker
                                    value={data.actual_time_in?.split('T')[0] || ''}
                                    onChange={(value) => {
                                        const time = data.actual_time_in?.split('T')[1] || '00:00';
                                        setData('actual_time_in', `${value}T${time}`);
                                    }}
                                    placeholder="Date"
                                />
                                <TimeInput
                                    value={data.actual_time_in?.split('T')[1] || ''}
                                    onChange={(value) => {
                                        const date = data.actual_time_in?.split('T')[0] || selectedDate;
                                        setData('actual_time_in', `${date}T${value}`);
                                    }}
                                />
                            </div>
                            {errors.actual_time_in && (
                                <p className="text-sm text-red-500">{errors.actual_time_in}</p>
                            )}
                        </div>

                        {/* Time-in only toggle (only when creating, not editing) */}
                        {!isEditMode && (
                            <div className="flex items-center justify-between rounded-md border bg-muted/30 px-3 py-2">
                                <div>
                                    <Label htmlFor="partial_only" className="text-sm font-medium cursor-pointer">
                                        Time-in only — complete time-out next shift
                                    </Label>
                                    <p className="text-xs text-muted-foreground mt-0.5">
                                        Creates a partially-verified record. The time-out will appear in Step 1 on the next admin shift.
                                    </p>
                                </div>
                                <Switch
                                    id="partial_only"
                                    checked={partialOnly}
                                    onCheckedChange={(checked) => {
                                        setPartialOnly(checked);
                                        if (checked) {
                                            setData('actual_time_out', '');
                                        } else if (selectedEmployee?.schedule) {
                                            // Restore default suggested time-out
                                            const isNight = selectedEmployee.schedule.shift_type === 'night_shift';
                                            const timeOutDate = isNight
                                                ? new Date(new Date(selectedDate).getTime() + 24 * 60 * 60 * 1000).toISOString().split('T')[0]
                                                : selectedDate;
                                            setData('actual_time_out', `${timeOutDate}T${selectedEmployee.schedule.scheduled_time_out.slice(0, 5)}`);
                                        }
                                    }}
                                />
                            </div>
                        )}

                        {/* Time Out */}
                        {!partialOnly && (
                            <div className="space-y-2">
                                <Label>Actual Time Out</Label>
                                <div className="grid grid-cols-2 gap-2">
                                    <DatePicker
                                        value={data.actual_time_out?.split('T')[0] || ''}
                                        onChange={(value) => {
                                            const time = data.actual_time_out?.split('T')[1] || '00:00';
                                            setData('actual_time_out', `${value}T${time}`);
                                        }}
                                        placeholder="Date"
                                    />
                                    <TimeInput
                                        value={data.actual_time_out?.split('T')[1] || ''}
                                        onChange={(value) => {
                                            const date = data.actual_time_out?.split('T')[0] || selectedDate;
                                            setData('actual_time_out', `${date}T${value}`);
                                        }}
                                    />
                                </div>
                                {errors.actual_time_out && (
                                    <p className="text-sm text-red-500">{errors.actual_time_out}</p>
                                )}
                            </div>
                        )}

                        {/* Overtime Approval */}
                        {suggestedStatus?.overtimeMinutes && suggestedStatus.overtimeMinutes > 0 && (
                            <div className="space-y-2 p-4 bg-blue-50 dark:bg-blue-950/50 border border-blue-200 dark:border-blue-800 rounded-lg">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <Label className="text-sm font-semibold text-blue-900 dark:text-blue-100">
                                            Overtime Detected: {suggestedStatus.overtimeMinutes} minutes
                                        </Label>
                                        <p className="text-xs text-blue-700 dark:text-blue-300 mt-1">
                                            Employee worked beyond scheduled time out
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Input
                                            type="checkbox"
                                            id="overtime_approved"
                                            checked={data.overtime_approved}
                                            onChange={e => setData('overtime_approved', e.target.checked)}
                                            className="h-4 w-4 rounded border-gray-300 dark:border-gray-600 text-blue-600 focus:ring-blue-500"
                                        />
                                        <Label htmlFor="overtime_approved" className="text-sm font-medium cursor-pointer">
                                            Approve Overtime
                                        </Label>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* Set Home & Undertime Approval Section - for undertime > 30 minutes */}
                        {suggestedStatus?.undertimeMinutes && suggestedStatus.undertimeMinutes > 30 && (
                            <div className="space-y-3 p-4 bg-amber-50 dark:bg-amber-950/50 border border-amber-200 dark:border-amber-800 rounded-lg">
                                {/* Undertime Header with Set Home toggle inline */}
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-2">
                                        <Clock className="h-4 w-4 text-amber-600" />
                                        <span className="text-sm font-medium text-amber-900 dark:text-amber-100">
                                            Undertime: {suggestedStatus.undertimeMinutes} min
                                        </span>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Label htmlFor="is_set_home" className="text-xs text-amber-700 dark:text-amber-300 cursor-pointer">
                                            Set Home
                                        </Label>
                                        <Switch
                                            id="is_set_home"
                                            checked={data.is_set_home}
                                            onCheckedChange={(checked) => setData('is_set_home', checked)}
                                        />
                                    </div>
                                </div>

                                {data.is_set_home ? (
                                    <p className="text-xs text-green-700 dark:text-green-400">
                                        ✓ Employee sent home early - no undertime points
                                    </p>
                                ) : isEditMode && selectedEmployee?.existing_attendance ? (
                                    /* Editing existing record - show approval status/options */
                                    selectedEmployee.existing_attendance.undertime_approval_status === 'approved' ? (
                                        <p className="text-xs text-green-700 dark:text-green-400">
                                            ✓ Approved: {selectedEmployee.existing_attendance.undertime_approval_reason === 'skip_points'
                                                ? 'No points (Set Home)'
                                                : selectedEmployee.existing_attendance.undertime_approval_reason === 'lunch_used'
                                                    ? 'Worked through lunch (+1hr credited)'
                                                    : 'Points generated'}
                                        </p>
                                    ) : selectedEmployee.existing_attendance.undertime_approval_status === 'rejected' ? (
                                        <p className="text-xs text-red-600 dark:text-red-400">
                                            ✗ Rejected - points will be generated
                                        </p>
                                    ) : selectedEmployee.existing_attendance.undertime_approval_status === 'pending' ? (
                                        <div className="flex items-center gap-2">
                                            <Clock className="h-3 w-3 text-yellow-600 animate-pulse" />
                                            <p className="text-xs text-yellow-700 dark:text-yellow-400">
                                                Pending approval from Admin/HR
                                            </p>
                                        </div>
                                    ) : canApproveUndertime ? (
                                        /* Admin/HR: Approval options */
                                        <div className="space-y-2">
                                            <div className="flex flex-wrap gap-2">
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant={undertimeApprovalReason === 'generate_points' ? 'default' : 'outline'}
                                                    onClick={() => setUndertimeApprovalReason('generate_points')}
                                                    className="h-7 text-xs"
                                                >
                                                    <Check className="h-3 w-3 mr-1" />
                                                    Generate Points
                                                </Button>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant={undertimeApprovalReason === 'lunch_used' ? 'default' : 'outline'}
                                                    onClick={() => setUndertimeApprovalReason('lunch_used')}
                                                    className="h-7 text-xs"
                                                >
                                                    <Clock className="h-3 w-3 mr-1" />
                                                    Lunch Used
                                                </Button>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    onClick={() => {
                                                        setIsApprovingUndertime(true);
                                                        router.post(approveUndertime(selectedEmployee.existing_attendance!.id).url, {
                                                            status: 'approved',
                                                            reason: undertimeApprovalReason,
                                                            notes: data.verification_notes,
                                                        }, {
                                                            preserveScroll: true,
                                                            onFinish: () => setIsApprovingUndertime(false),
                                                        });
                                                    }}
                                                    disabled={isApprovingUndertime}
                                                    className="h-7 text-xs bg-green-600 hover:bg-green-700"
                                                >
                                                    <CheckCircle className="h-3 w-3 mr-1" />
                                                    {isApprovingUndertime ? 'Saving...' : 'Approve'}
                                                </Button>
                                            </div>
                                            <p className="text-xs text-amber-700 dark:text-amber-300">
                                                {undertimeApprovalReason === 'lunch_used' && '✓ Will approve — worked through lunch, +1hr credited to total hours'}
                                                {undertimeApprovalReason === 'generate_points' && '• Points will be generated'}
                                            </p>
                                        </div>
                                    ) : canRequestUndertimeApproval ? (
                                        /* Team Lead: Request approval with suggestion */
                                        <div className="space-y-2">
                                            <div className="flex flex-wrap gap-2">
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant={undertimeApprovalReason === 'generate_points' ? 'default' : 'outline'}
                                                    onClick={() => setUndertimeApprovalReason('generate_points')}
                                                    className="h-7 text-xs"
                                                >
                                                    <Check className="h-3 w-3 mr-1" />
                                                    Generate Points
                                                </Button>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant={undertimeApprovalReason === 'lunch_used' ? 'default' : 'outline'}
                                                    onClick={() => setUndertimeApprovalReason('lunch_used')}
                                                    className="h-7 text-xs"
                                                >
                                                    <Clock className="h-3 w-3 mr-1" />
                                                    Lunch Used
                                                </Button>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    onClick={() => {
                                                        setIsRequestingUndertimeApproval(true);
                                                        router.post(requestUndertimeApproval(selectedEmployee.existing_attendance!.id).url, {
                                                            suggested_reason: undertimeApprovalReason,
                                                        }, {
                                                            preserveScroll: true,
                                                            onFinish: () => setIsRequestingUndertimeApproval(false),
                                                        });
                                                    }}
                                                    disabled={isRequestingUndertimeApproval}
                                                    className="h-7 text-xs"
                                                >
                                                    <Send className="h-3 w-3 mr-1" />
                                                    {isRequestingUndertimeApproval ? 'Sending...' : 'Request Approval'}
                                                </Button>
                                            </div>
                                            <p className="text-xs text-amber-700 dark:text-amber-300">
                                                {undertimeApprovalReason === 'lunch_used' && '• Suggesting: Worked through lunch (+1hr credited)'}
                                                {undertimeApprovalReason === 'generate_points' && '• Suggesting: Generate points'}
                                            </p>
                                        </div>
                                    ) : (
                                        <p className="text-xs text-amber-700 dark:text-amber-300">
                                            • Undertime points will be generated
                                        </p>
                                    )
                                ) : (
                                    /* Creating new record */
                                    <p className="text-xs text-amber-700 dark:text-amber-300">
                                        • Undertime points will be generated. Approval can be requested after saving.
                                    </p>
                                )}
                            </div>
                        )}

                        {/* Notes */}
                        <div className="space-y-2">
                            <Label htmlFor="notes">Notes (Optional)</Label>
                            <Textarea
                                id="notes"
                                value={data.notes}
                                onChange={e => setData('notes', e.target.value)}
                                placeholder="Add notes about this attendance record..."
                                rows={2}
                                maxLength={500}
                            />
                            {errors.notes && <p className="text-sm text-red-500">{errors.notes}</p>}
                        </div>

                        {/* Verification Notes */}
                        <div className="space-y-2">
                            <Label htmlFor="verification_notes">
                                Verification Notes (Optional)
                            </Label>
                            <div className="flex flex-wrap gap-2 mb-2">
                                {[
                                    "Manual entry",
                                    "Verified by supervisor",
                                    "Shift adjustment",
                                    "System entry",
                                    "Schedule confirmed",
                                    ...(isEditMode ? ["Correction", "Time updated"] : []),
                                ].map((phrase) => (
                                    <Button
                                        key={phrase}
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className="h-7 text-xs"
                                        onClick={() => {
                                            const currentNotes = data.verification_notes.trim();
                                            const newNotes = currentNotes
                                                ? `${currentNotes}${currentNotes.endsWith('.') ? '' : '.'} ${phrase}.`
                                                : `${phrase}.`;
                                            setData('verification_notes', newNotes);
                                        }}
                                    >
                                        {phrase}
                                    </Button>
                                ))}
                            </div>
                            <Textarea
                                id="verification_notes"
                                value={data.verification_notes}
                                onChange={e => setData('verification_notes', e.target.value)}
                                placeholder={isEditMode ? "Explain why this record is being updated..." : "Explain why this record is being created..."}
                                rows={3}
                            />
                            {errors.verification_notes && (
                                <p className="text-sm text-red-500">{errors.verification_notes}</p>
                            )}
                        </div>

                        <DialogFooter className="flex-col sm:flex-row gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setIsDialogOpen(false)}
                                disabled={processing}
                                className="w-full sm:w-auto"
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={processing}
                                className="w-full sm:w-auto"
                            >
                                {processing ? (isEditMode ? 'Updating...' : 'Creating...') : (isEditMode ? 'Update Record' : 'Create & Verify')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Add Time-Out Dialog (Step 1 completion) */}
            <Dialog open={pendingDialogOpen} onOpenChange={(open) => {
                setPendingDialogOpen(open);
                if (!open) {
                    setActivePending(null);
                    setTimeOutValue('');
                    setTimeOutNotes('');
                    setOvertimeApprovedForPending(false);
                }
            }}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Add Time-Out</DialogTitle>
                        <DialogDescription>
                            Complete the time-out for {activePending?.name} ({activePending?.shift_date_formatted}).
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submitPendingTimeOut} className="space-y-4">
                        {activePending?.schedule && (
                            <div className="bg-muted p-3 rounded-md text-sm space-y-1">
                                <div className="flex items-center gap-2">
                                    {getShiftTypeBadge(activePending.schedule.shift_type)}
                                    <span className="text-muted-foreground">
                                        {formatTime(activePending.schedule.scheduled_time_in)} – {formatTime(activePending.schedule.scheduled_time_out)}
                                    </span>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Time In:</span>{' '}
                                    <span className="font-mono">{activePending.actual_time_in?.replace('T', ' ') ?? '—'}</span>
                                </div>
                            </div>
                        )}

                        <div className="space-y-2">
                            <Label>Actual Time Out</Label>
                            <div className="grid grid-cols-2 gap-2">
                                <DatePicker
                                    value={timeOutValue.split('T')[0] || ''}
                                    onChange={(value) => {
                                        const time = timeOutValue.split('T')[1] || '00:00';
                                        setTimeOutValue(`${value}T${time}`);
                                    }}
                                    placeholder="Date"
                                />
                                <TimeInput
                                    value={timeOutValue.split('T')[1] || ''}
                                    onChange={(value) => {
                                        const date = timeOutValue.split('T')[0] || activePending?.shift_date || selectedDate;
                                        setTimeOutValue(`${date}T${value}`);
                                    }}
                                />
                            </div>
                        </div>

                        {/* Live violations preview */}
                        {pendingTimeOutSuggestion && timeOutValue && (
                            <div className={`p-3 rounded-lg border text-sm ${pendingTimeOutSuggestion.violations.length > 0 && pendingTimeOutSuggestion.status !== 'on_time'
                                    ? 'bg-red-50 border-red-200 dark:bg-red-950/20 dark:border-red-800'
                                    : 'bg-green-50 border-green-200 dark:bg-green-950/20 dark:border-green-800'
                                }`}>
                                {pendingTimeOutSuggestion.violations.length > 0 && pendingTimeOutSuggestion.status !== 'on_time' ? (
                                    <>
                                        <div className="flex items-center gap-2 mb-2">
                                            <AlertCircle className="h-4 w-4 text-red-600" />
                                            <span className="font-medium text-red-800 dark:text-red-400">Detected Violations</span>
                                        </div>
                                        <div className="space-y-1">
                                            {pendingTimeOutSuggestion.violations.map((violation, index) => (
                                                <div key={violation} className="flex justify-between items-center">
                                                    <span className={`${index === 0
                                                            ? 'font-medium text-red-700 dark:text-red-400'
                                                            : 'text-red-600 dark:text-red-500'
                                                        }`}>
                                                        {index === 0 ? '▶ ' : '  '}
                                                        {violation.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}
                                                        {index === 0 && ' (Primary)'}
                                                        {index === 1 && ' (Secondary)'}
                                                    </span>
                                                    <Badge variant="outline" className="text-red-600 border-red-400 text-xs h-5">
                                                        {getPointValue(violation).toFixed(2)} pts
                                                    </Badge>
                                                </div>
                                            ))}
                                        </div>
                                        <p className="text-xs text-red-600 dark:text-red-500 mt-2 pt-2 border-t border-red-200 dark:border-red-800">
                                            {pendingTimeOutSuggestion.reason}
                                        </p>
                                    </>
                                ) : (
                                    <div className="flex items-center gap-2">
                                        <CheckCircle className="h-4 w-4 text-green-600" />
                                        <span className="text-green-800 dark:text-green-400 font-medium">On Time — no violations</span>
                                    </div>
                                )}
                            </div>
                        )}

                        {/* Overtime approval */}
                        {pendingTimeOutSuggestion && (pendingTimeOutSuggestion.overtimeMinutes ?? 0) > 0 && (
                            <div className="p-3 rounded-lg border bg-blue-50 border-blue-200 dark:bg-blue-950/20 dark:border-blue-800">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-sm font-semibold text-blue-900 dark:text-blue-100">
                                            Overtime Detected: {pendingTimeOutSuggestion.overtimeMinutes} min
                                        </p>
                                        <p className="text-xs text-blue-700 dark:text-blue-300 mt-0.5">
                                            Employee worked beyond scheduled time out
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Input
                                            type="checkbox"
                                            id="pending_overtime_approved"
                                            checked={overtimeApprovedForPending}
                                            onChange={e => setOvertimeApprovedForPending(e.target.checked)}
                                            className="h-4 w-4 rounded border-gray-300 dark:border-gray-600 text-blue-600 focus:ring-blue-500"
                                        />
                                        <Label htmlFor="pending_overtime_approved" className="text-sm font-medium cursor-pointer">
                                            Approve Overtime
                                        </Label>
                                    </div>
                                </div>
                            </div>
                        )}

                        <div className="space-y-2">
                            <Label htmlFor="time_out_notes">Verification Notes (Optional)</Label>
                            <Textarea
                                id="time_out_notes"
                                value={timeOutNotes}
                                onChange={(e) => setTimeOutNotes(e.target.value)}
                                placeholder="e.g. Time-out from biometric log..."
                                rows={2}
                            />
                        </div>

                        <DialogFooter className="flex-col sm:flex-row gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setPendingDialogOpen(false)}
                                disabled={isSubmittingTimeOut}
                                className="w-full sm:w-auto"
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={isSubmittingTimeOut || !timeOutValue}
                                className="w-full sm:w-auto"
                            >
                                {isSubmittingTimeOut ? 'Saving...' : 'Complete Time-Out'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
