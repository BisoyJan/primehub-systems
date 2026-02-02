import React, { useState, useEffect, useCallback } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { format } from 'date-fns';
import AppLayout from '@/layouts/app-layout';
import { PageHeader } from '@/components/PageHeader';
import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { TimeInput } from '@/components/ui/time-input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatTime } from '@/lib/utils';
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
import { AlertCircle, Check, CheckCircle, ChevronsUpDown, Clock, Repeat, X } from 'lucide-react';
import { toast } from 'sonner';
import { useFlashMessage, usePageMeta, usePermission } from '@/hooks';
import { index as attendanceIndex, create as attendanceCreate, store as attendanceStore, bulkStore as attendanceBulkStore } from '@/routes/attendance';
import { Switch } from '@/components/ui/switch';

interface User {
    id: number;
    name: string;
    email: string;
    schedule: {
        shift_type: string;
        scheduled_time_in: string;
        scheduled_time_out: string;
        site_name: string;
        campaign_id: number | null;
        campaign_name: string | null;
        grace_period_minutes: number;
    } | null;
}

interface Campaign {
    id: number;
    name: string;
}

interface Props {
    users: User[];
    campaigns: Campaign[];
}

export default function Create({ users, campaigns }: Props) {
    useFlashMessage();
    const { can } = usePermission();

    // Pre-compute permission checks for undertime approval
    const canApproveUndertime = can('attendance.approve_undertime');

    const { title, breadcrumbs } = usePageMeta({
        title: 'Create Manual Attendance',
        breadcrumbs: [
            { title: 'Attendance', href: attendanceIndex().url },
            { title: 'Manual Entry', href: attendanceCreate().url },
        ],
    });

    const { data, setData, post, processing, errors } = useForm({
        user_id: '',
        shift_date: '',
        status: '',
        secondary_status: '',
        actual_time_in: '',
        actual_time_out: '',
        actual_time_in_date: '',
        actual_time_in_time: '',
        actual_time_out_date: '',
        actual_time_out_time: '',
        notes: '',
        is_set_home: false,
        undertime_approval_status: '' as '' | 'approved',
        undertime_approval_reason: '' as '' | 'generate_points' | 'skip_points' | 'lunch_used',
    });

    const { data: bulkData, setData: setBulkData, post: bulkPost, processing: bulkProcessing, errors: bulkErrors } = useForm({
        user_ids: [] as number[],
        shift_date: '',
        status: '',
        secondary_status: '',
        actual_time_in: '',
        actual_time_out: '',
        actual_time_in_date: '',
        actual_time_in_time: '',
        actual_time_out_date: '',
        actual_time_out_time: '',
        notes: '',
        is_set_home: false,
        undertime_approval_status: '' as '' | 'approved',
        undertime_approval_reason: '' as '' | 'generate_points' | 'skip_points' | 'lunch_used',
    });

    const [searchQuery, setSearchQuery] = useState('');
    const [isUserPopoverOpen, setIsUserPopoverOpen] = useState(false);
    const [isBulkMode, setIsBulkMode] = useState(false);
    const [selectedUserIds, setSelectedUserIds] = useState<number[]>([]);
    const [shiftFilter, setShiftFilter] = useState<string>('all');
    const [campaignFilter, setCampaignFilter] = useState<string>('all');
    const [timeOutValidationError, setTimeOutValidationError] = useState<string>('');

    // Multi-entry mode state - persisted in localStorage
    const [multiEntryMode, setMultiEntryMode] = useState(() => {
        if (typeof window !== 'undefined') {
            return localStorage.getItem('attendance_multi_entry_mode') === 'true';
        }
        return false;
    });
    const [recordsCreatedCount, setRecordsCreatedCount] = useState(0);

    // Auto-status selection state (single entry mode only)
    const [suggestedStatus, setSuggestedStatus] = useState<string>('');
    const [suggestedSecondaryStatus, setSuggestedSecondaryStatus] = useState<string>('');
    const [suggestedUndertimeMinutes, setSuggestedUndertimeMinutes] = useState<number>(0);
    const [isStatusManuallyOverridden, setIsStatusManuallyOverridden] = useState(false);

    // Define selectedUser early so it can be used in callbacks
    const selectedUser = data.user_id ? users.find(user => user.id === Number(data.user_id)) : undefined;

    // Helper function to calculate suggested status based on schedule and times
    // Returns object with status, secondaryStatus, and reason (matching Review.tsx logic)
    const calculateSuggestedStatus = useCallback((
        schedule: User['schedule'],
        timeInDate: string,
        timeInTime: string,
        timeOutDate: string,
        timeOutTime: string
    ): { status: string; secondaryStatus?: string; reason: string; undertimeMinutes?: number } => {
        // If no schedule, can't calculate - return empty to let backend handle
        if (!schedule) return { status: '', reason: 'No schedule found' };

        const hasTimeIn = timeInDate && timeInTime;
        const hasTimeOut = timeOutDate && timeOutTime;

        // No times provided - can't determine status
        if (!hasTimeIn && !hasTimeOut) return { status: '', reason: 'No times provided' };

        // Has time out but no time in - failed bio in
        if (!hasTimeIn && hasTimeOut) return { status: 'failed_bio_in', reason: 'Missing time in record' };

        // Both times provided - calculate based on schedule
        const scheduledIn = schedule.scheduled_time_in; // Format: "HH:mm:ss"
        const scheduledOut = schedule.scheduled_time_out;
        const gracePeriodMinutes = schedule.grace_period_minutes ?? 15; // Use schedule's grace period for half day threshold

        // Parse scheduled times
        const [schedInH, schedInM] = scheduledIn.split(':').map(Number);
        const [schedOutH, schedOutM] = scheduledOut.split(':').map(Number);

        // Parse actual times (time format is "HH:mm")
        const [actualInH, actualInM] = timeInTime.split(':').map(Number);

        // Create date objects for comparison
        const schedInDate = new Date(timeInDate);
        schedInDate.setHours(schedInH, schedInM, 0, 0);

        const actualInDateTime = new Date(timeInDate);
        actualInDateTime.setHours(actualInH, actualInM, 0, 0);

        // Check for night shift (scheduled out is before scheduled in - crosses midnight)
        const isNightShift = schedule.shift_type === 'night_shift' || schedOutH < schedInH;

        // Calculate scheduled out datetime
        const schedOutDate = new Date(timeInDate);
        if (isNightShift) {
            schedOutDate.setDate(schedOutDate.getDate() + 1);
        }
        schedOutDate.setHours(schedOutH, schedOutM, 0, 0);

        // Calculate tardiness in minutes
        const tardyMinutes = Math.max(0, (actualInDateTime.getTime() - schedInDate.getTime()) / (1000 * 60));

        // Check time-in violations
        let isTardy = false;
        let isHalfDay = false;

        if (tardyMinutes > gracePeriodMinutes) {
            // More than grace period late = half_day_absence
            isHalfDay = true;
        } else if (tardyMinutes >= 1) {
            // 1+ minutes late = tardy
            isTardy = true;
        }

        // Check time-out violations (if we have time out)
        let hasUndertime = false;
        let undertimeMinutes = 0;

        if (hasTimeOut) {
            const [actualOutH, actualOutM] = timeOutTime.split(':').map(Number);
            const actualOutDateTime = new Date(timeOutDate);
            actualOutDateTime.setHours(actualOutH, actualOutM, 0, 0);

            const earlyDepartureMinutes = (schedOutDate.getTime() - actualOutDateTime.getTime()) / (1000 * 60);

            // Any early departure (1 minute or more) is undertime
            if (earlyDepartureMinutes >= 1) {
                hasUndertime = true;
                undertimeMinutes = Math.round(earlyDepartureMinutes);
            }
        }

        // Determine status based on violations
        let status: string;
        let secondaryStatus: string | undefined;
        let reason: string;

        if (!hasTimeOut) {
            // Has time in but no time out
            if (isHalfDay) {
                status = 'half_day_absence';
                secondaryStatus = 'failed_bio_out';
                reason = `Arrived ${Math.round(tardyMinutes)} minutes late (more than ${gracePeriodMinutes}min grace period), missing time out`;
            } else if (isTardy) {
                status = 'tardy';
                secondaryStatus = 'failed_bio_out';
                reason = `Arrived ${Math.round(tardyMinutes)} minutes late, missing time out`;
            } else {
                status = 'failed_bio_out';
                reason = 'Missing time out record';
            }
        } else if (isHalfDay && hasUndertime) {
            status = 'half_day_absence';
            // Determine undertime status based on minutes
            secondaryStatus = undertimeMinutes > 60 ? 'undertime_more_than_hour' : 'undertime';
            reason = `Arrived ${Math.round(tardyMinutes)} minutes late AND left ${undertimeMinutes} minutes early`;
        } else if (isHalfDay) {
            status = 'half_day_absence';
            reason = `Arrived ${Math.round(tardyMinutes)} minutes late (more than ${gracePeriodMinutes}min grace period)`;
        } else if (isTardy && hasUndertime) {
            status = 'tardy';
            // Determine undertime status based on minutes
            secondaryStatus = undertimeMinutes > 60 ? 'undertime_more_than_hour' : 'undertime';
            reason = `Arrived ${Math.round(tardyMinutes)} minutes late AND left ${undertimeMinutes} minutes early`;
        } else if (isTardy) {
            status = 'tardy';
            reason = `Arrived ${Math.round(tardyMinutes)} minutes late`;
        } else if (hasUndertime) {
            // Determine undertime status based on minutes
            status = undertimeMinutes > 60 ? 'undertime_more_than_hour' : 'undertime';
            reason = `Left ${undertimeMinutes} minutes early`;
        } else {
            status = 'on_time';
            reason = 'Arrived on time';
        }

        return { status, secondaryStatus, reason, undertimeMinutes };
    }, []);

    // Recalculate suggested status when relevant fields change (single entry mode)
    const recalculateSuggestedStatus = useCallback(() => {
        if (isBulkMode || !selectedUser?.schedule) {
            setSuggestedStatus('');
            setSuggestedSecondaryStatus('');
            setSuggestedUndertimeMinutes(0);
            return;
        }

        const result = calculateSuggestedStatus(
            selectedUser.schedule,
            data.actual_time_in_date,
            data.actual_time_in_time,
            data.actual_time_out_date,
            data.actual_time_out_time
        );

        setSuggestedStatus(result.status);
        setSuggestedSecondaryStatus(result.secondaryStatus || '');
        setSuggestedUndertimeMinutes(result.undertimeMinutes || 0);

        // If status hasn't been manually overridden, auto-update it
        if (!isStatusManuallyOverridden && result.status) {
            setData('status', result.status);
            setData('secondary_status', result.secondaryStatus || '');
        }
    }, [isBulkMode, selectedUser, data.actual_time_in_date, data.actual_time_in_time, data.actual_time_out_date, data.actual_time_out_time, isStatusManuallyOverridden, calculateSuggestedStatus, setData]);

    // Handle status change - track if manually overridden
    const handleStatusChange = (value: string) => {
        if (isBulkMode) {
            setBulkData('status', value);
        } else {
            setData('status', value);
            // Mark as manually overridden if different from suggested
            if (value !== suggestedStatus) {
                setIsStatusManuallyOverridden(true);
                // Clear secondary status when manually overriding
                setData('secondary_status', '');
            }
        }
    };

    // Reset to suggested status
    const resetToSuggestedStatus = () => {
        if (suggestedStatus) {
            setData('status', suggestedStatus);
            setData('secondary_status', suggestedSecondaryStatus);
            setIsStatusManuallyOverridden(false);
        }
    };

    // Effect to recalculate status when times change
    useEffect(() => {
        if (isBulkMode) return;

        const timer = setTimeout(() => {
            recalculateSuggestedStatus();
        }, 300); // Debounce

        return () => clearTimeout(timer);
    }, [data.actual_time_in_date, data.actual_time_in_time, data.actual_time_out_date, data.actual_time_out_time, selectedUser, recalculateSuggestedStatus, isBulkMode]);

    // Reset override state when user changes
    useEffect(() => {
        setIsStatusManuallyOverridden(false);
        setSuggestedStatus('');
        setSuggestedSecondaryStatus('');
        setData('status', '');
        setData('secondary_status', '');
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data.user_id]);

    // Persist multi-entry mode to localStorage
    useEffect(() => {
        localStorage.setItem('attendance_multi_entry_mode', multiEntryMode.toString());
    }, [multiEntryMode]);

    // Auto-populate time-in date from shift date
    useEffect(() => {
        if (isBulkMode) {
            if (bulkData.shift_date && !bulkData.actual_time_in_date) {
                setBulkData('actual_time_in_date', bulkData.shift_date);
            }
        } else {
            if (data.shift_date && !data.actual_time_in_date) {
                setData('actual_time_in_date', data.shift_date);
            }
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data.shift_date, bulkData.shift_date, isBulkMode]);

    // Auto-calculate time-out date based on shift type and time values
    const calculateTimeOutDate = useCallback((schedule: User['schedule'], shiftDate: string, timeInTime: string, timeOutTime: string): string => {
        if (!shiftDate || !timeOutTime) return shiftDate;

        const timeInHour = parseInt(timeInTime?.split(':')[0] || '0');
        const timeOutHour = parseInt(timeOutTime.split(':')[0]);

        // Determine if time-out should be next day based on shift type and times
        let isNextDay = false;

        if (schedule) {
            const schedInHour = parseInt(schedule.scheduled_time_in.split(':')[0]);
            const schedOutHour = parseInt(schedule.scheduled_time_out.split(':')[0]);

            // Night shift: scheduled out is before scheduled in (e.g., 22:00 -> 07:00)
            if (schedule.shift_type === 'night_shift' || schedOutHour < schedInHour) {
                // If time-out hour is in the morning range (0-12) and time-in is in evening (14-23)
                if (timeOutHour >= 0 && timeOutHour <= 14 && timeInHour >= 14) {
                    isNextDay = true;
                }
            }
        } else {
            // No schedule - use heuristic: if time-out is significantly earlier than time-in, assume next day
            // E.g., Time in 22:00, Time out 07:00 = next day
            if (timeOutHour >= 0 && timeOutHour <= 12 && timeInHour >= 18) {
                isNextDay = true;
            }
        }

        if (isNextDay) {
            const nextDay = new Date(shiftDate);
            nextDay.setDate(nextDay.getDate() + 1);
            return nextDay.toISOString().split('T')[0];
        }

        return shiftDate;
    }, []);

    // Auto-populate time-out date based on shift type and times
    useEffect(() => {
        if (isBulkMode) {
            if (bulkData.shift_date && bulkData.actual_time_out_time) {
                // For bulk mode, we don't have a single schedule, so use basic heuristic
                const calculatedDate = calculateTimeOutDate(
                    null,
                    bulkData.shift_date,
                    bulkData.actual_time_in_time,
                    bulkData.actual_time_out_time
                );
                if (calculatedDate !== bulkData.actual_time_out_date) {
                    setBulkData('actual_time_out_date', calculatedDate);
                }
            }
        } else {
            if (data.shift_date && data.actual_time_out_time && selectedUser?.schedule) {
                const calculatedDate = calculateTimeOutDate(
                    selectedUser.schedule,
                    data.shift_date,
                    data.actual_time_in_time,
                    data.actual_time_out_time
                );
                if (calculatedDate !== data.actual_time_out_date) {
                    setData('actual_time_out_date', calculatedDate);
                }
            } else if (data.shift_date && data.actual_time_out_time && !data.actual_time_out_date) {
                // No schedule but has times - default to shift date
                setData('actual_time_out_date', data.shift_date);
            }
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [
        data.shift_date, data.actual_time_in_time, data.actual_time_out_time, selectedUser?.schedule,
        bulkData.shift_date, bulkData.actual_time_in_time, bulkData.actual_time_out_time,
        isBulkMode, calculateTimeOutDate
    ]);

    // Get unique shift schedules
    const uniqueShifts = Array.from(
        new Set(
            users
                .filter(u => u.schedule)
                .map(u => JSON.stringify({
                    shift_type: u.schedule!.shift_type,
                    time_in: u.schedule!.scheduled_time_in,
                    time_out: u.schedule!.scheduled_time_out,
                    site: u.schedule!.site_name,
                }))
        )
    ).map(str => JSON.parse(str));

    const filteredUsers = users.filter((user) => {
        const query = searchQuery.toLowerCase();
        const matchesSearch = user.name.toLowerCase().includes(query) || user.email.toLowerCase().includes(query);

        // Apply campaign filter
        let matchesCampaign = true;
        if (campaignFilter !== 'all') {
            if (campaignFilter === 'no_campaign') {
                matchesCampaign = !user.schedule || !user.schedule.campaign_id;
            } else {
                matchesCampaign = !!(user.schedule && user.schedule.campaign_id === parseInt(campaignFilter));
            }
        }

        // Apply shift filter
        if (shiftFilter === 'all') {
            return matchesSearch && matchesCampaign;
        }

        if (shiftFilter === 'no_schedule') {
            return matchesSearch && matchesCampaign && !user.schedule;
        }

        const filterShift = JSON.parse(shiftFilter);
        return matchesSearch && matchesCampaign && user.schedule &&
            user.schedule.shift_type === filterShift.shift_type &&
            user.schedule.scheduled_time_in === filterShift.time_in &&
            user.schedule.scheduled_time_out === filterShift.time_out &&
            user.schedule.site_name === filterShift.site;
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        // Validate time out to prevent hour 24 on same date
        const timeOutHour = data.actual_time_out_time ? data.actual_time_out_time.split(':')[0] : '';
        if (data.actual_time_out_date && !validateTimeOut(data.actual_time_in_date, data.actual_time_out_date, timeOutHour)) {
            toast.error('Invalid time out', {
                description: 'Hour 24 on the same date represents the next day. Please adjust the date or time.',
            });
            return;
        }

        // Debug: Log the form data before submission
        console.log('Form data before submission:', {
            user_id: data.user_id,
            shift_date: data.shift_date,
            status: data.status,
            actual_time_in_date: data.actual_time_in_date,
            actual_time_in_time: data.actual_time_in_time,
            actual_time_out_date: data.actual_time_out_date,
            actual_time_out_time: data.actual_time_out_time,
            notes: data.notes,
        });

        // Use transform to modify data before submission
        post(attendanceStore().url, {
            preserveScroll: true,
            preserveState: multiEntryMode,
            onSuccess: () => {
                if (multiEntryMode) {
                    // Multi-entry mode: stay on page and reset form
                    setRecordsCreatedCount(prev => prev + 1);
                    toast.success(`Attendance record #${recordsCreatedCount + 1} created!`, {
                        description: 'Form reset. Add another record.',
                    });
                    // Reset form fields but keep shift_date for convenience
                    const currentShiftDate = data.shift_date;
                    setData({
                        user_id: '',
                        shift_date: currentShiftDate,
                        status: '',
                        secondary_status: '',
                        actual_time_in: '',
                        actual_time_out: '',
                        actual_time_in_date: currentShiftDate,
                        actual_time_in_time: '',
                        actual_time_out_date: '',
                        actual_time_out_time: '',
                        notes: '',
                    });
                    setIsStatusManuallyOverridden(false);
                    setSuggestedStatus('');
                    setSuggestedSecondaryStatus('');
                } else {
                    // Normal mode: redirect to index
                    toast.success('Attendance record created successfully!');
                    router.visit(attendanceIndex().url);
                }
            },
            onError: () => {
                toast.error('Failed to create attendance record', {
                    description: 'Please check the form for errors.',
                });
            },
        });
    };

    const handleBulkSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        // Validate time out to prevent hour 24 on same date
        const timeOutHour = bulkData.actual_time_out_time ? bulkData.actual_time_out_time.split(':')[0] : '';
        if (bulkData.actual_time_out_date && !validateTimeOut(bulkData.actual_time_in_date, bulkData.actual_time_out_date, timeOutHour)) {
            toast.error('Invalid time out', {
                description: 'Hour 24 on the same date represents the next day. Please adjust the date or time.',
            });
            return;
        }

        bulkPost(attendanceBulkStore().url, {
            preserveScroll: true,
            preserveState: multiEntryMode,
            onSuccess: () => {
                const createdCount = selectedUserIds.length;
                if (multiEntryMode) {
                    // Multi-entry mode: stay on page and reset form
                    setRecordsCreatedCount(prev => prev + createdCount);
                    toast.success(`${createdCount} attendance records created!`, {
                        description: `Total this session: ${recordsCreatedCount + createdCount}. Form reset.`,
                    });
                    // Reset form fields but keep shift_date
                    const currentShiftDate = bulkData.shift_date;
                    setBulkData({
                        user_ids: [],
                        shift_date: currentShiftDate,
                        status: '',
                        secondary_status: '',
                        actual_time_in: '',
                        actual_time_out: '',
                        actual_time_in_date: currentShiftDate,
                        actual_time_in_time: '',
                        actual_time_out_date: '',
                        actual_time_out_time: '',
                        notes: '',
                    });
                    setSelectedUserIds([]);
                    setCampaignFilter('all');
                    setShiftFilter('all');
                } else {
                    // Normal mode: redirect to index
                    toast.success(`${createdCount} attendance records created successfully!`);
                    router.visit(attendanceIndex().url);
                }
            },
            onError: () => {
                toast.error('Failed to create attendance records', {
                    description: 'Please check the form for errors.',
                });
            },
        });
    };

    const toggleUserSelection = (userId: number) => {
        setSelectedUserIds(prev => {
            const newSelection = prev.includes(userId)
                ? prev.filter(id => id !== userId)
                : [...prev, userId];
            setBulkData('user_ids', newSelection);
            return newSelection;
        });
    };

    const selectAllUsers = () => {
        const allUserIds = filteredUsers.map(u => u.id);
        setSelectedUserIds(allUserIds);
        setBulkData('user_ids', allUserIds);
    };

    const clearAllUsers = () => {
        setSelectedUserIds([]);
        setBulkData('user_ids', []);
    };

    // formatTime is now imported from @/lib/utils

    const selectUsersByShift = (shiftKey: string) => {
        if (shiftKey === 'all') {
            selectAllUsers();
            return;
        }

        if (shiftKey === 'no_schedule') {
            const noScheduleUserIds = users.filter(u => !u.schedule).map(u => u.id);
            setSelectedUserIds(noScheduleUserIds);
            setBulkData('user_ids', noScheduleUserIds);
            return;
        }

        const filterShift = JSON.parse(shiftKey);
        const matchingUserIds = users
            .filter(u => u.schedule &&
                u.schedule.shift_type === filterShift.shift_type &&
                u.schedule.scheduled_time_in === filterShift.time_in &&
                u.schedule.scheduled_time_out === filterShift.time_out &&
                u.schedule.site_name === filterShift.site
            )
            .map(u => u.id);

        setSelectedUserIds(matchingUserIds);
        setBulkData('user_ids', matchingUserIds);
    };

    const selectUsersByCampaign = (campaignId: string) => {
        if (campaignId === 'all') {
            selectAllUsers();
            return;
        }

        if (campaignId === 'no_campaign') {
            const noCampaignUserIds = users.filter(u => !u.schedule || !u.schedule.campaign_id).map(u => u.id);
            setSelectedUserIds(noCampaignUserIds);
            setBulkData('user_ids', noCampaignUserIds);
            return;
        }

        const matchingUserIds = users
            .filter(u => u.schedule && u.schedule.campaign_id === parseInt(campaignId))
            .map(u => u.id);

        setSelectedUserIds(matchingUserIds);
        setBulkData('user_ids', matchingUserIds);
    };

    // Validate time out to prevent hour 24 on the same date (should be next day 00:00)
    const validateTimeOut = (timeInDate: string, timeOutDate: string, timeOutHour: string) => {
        if (timeInDate && timeOutDate && timeInDate === timeOutDate && timeOutHour === '24') {
            setTimeOutValidationError('Hour 24 on the same date represents the next day. Please use the next date with hour 01 (or 00), or change to hour 23.');
            return false;
        }
        setTimeOutValidationError('');
        return true;
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3">

                <PageHeader
                    title="Create Manual Attendance"
                    description="Add a manual attendance record"
                />

                {/* Multi-Entry Toggle */}
                <div className="flex justify-end items-center gap-3 px-1">
                    {multiEntryMode && recordsCreatedCount > 0 && (
                        <span className="text-sm text-muted-foreground">
                            Records created this session: <strong className="text-foreground">{recordsCreatedCount}</strong>
                        </span>
                    )}
                    <div className="flex items-center gap-2 p-3 rounded-lg border bg-card">
                        <Repeat className={`h-4 w-4 ${multiEntryMode ? 'text-primary' : 'text-muted-foreground'}`} />
                        <Label htmlFor="multi-entry-toggle" className="text-sm font-medium cursor-pointer">
                            Create Multiple Records
                        </Label>
                        <Switch
                            id="multi-entry-toggle"
                            checked={multiEntryMode}
                            onCheckedChange={setMultiEntryMode}
                        />
                    </div>
                </div>
                {multiEntryMode && (
                    <p className="text-xs text-muted-foreground text-right px-1 -mt-2">
                        Stay on this page after saving. Form will reset for next entry.
                    </p>
                )}

                <div className="flex justify-center mb-4">
                    <div className="flex gap-2">
                        <Button
                            variant={!isBulkMode ? 'default' : 'outline'}
                            onClick={() => {
                                setIsBulkMode(false);
                                // Reset filters when switching to single entry mode
                                setCampaignFilter('all');
                                setShiftFilter('all');
                            }}
                        >
                            Single Entry
                        </Button>
                        <Button
                            variant={isBulkMode ? 'default' : 'outline'}
                            onClick={() => setIsBulkMode(true)}
                        >
                            Bulk Entry
                        </Button>
                    </div>
                </div>

                <div className="flex justify-center">
                    <Card className="w-full max-w-2xl">
                        <CardHeader>
                            <CardTitle>Attendance Details</CardTitle>
                            <CardDescription>
                                {isBulkMode
                                    ? 'Select multiple employees and fill in common attendance details'
                                    : 'Fill in the information below to create a manual attendance record'}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={isBulkMode ? handleBulkSubmit : handleSubmit} className="space-y-6">
                                {/* Employee Selection */}
                                <div className="space-y-2">
                                    <Label htmlFor="user_id">
                                        {isBulkMode ? 'Employees' : 'Employee'} <span className="text-red-500">*</span>
                                    </Label>
                                    {isBulkMode && (
                                        <>
                                            <div className="grid grid-cols-2 gap-3 mb-3">
                                                <div className="space-y-2">
                                                    <Label htmlFor="campaign_filter" className="text-sm font-normal">
                                                        Quick Select by Campaign
                                                    </Label>
                                                    <Select value={campaignFilter} onValueChange={(value) => {
                                                        setCampaignFilter(value);
                                                        selectUsersByCampaign(value);
                                                    }}>
                                                        <SelectTrigger>
                                                            <SelectValue placeholder="Select campaign" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="all">All Campaigns ({users.length})</SelectItem>
                                                            {campaigns.map((campaign) => {
                                                                const count = users.filter(u => u.schedule && u.schedule.campaign_id === campaign.id).length;
                                                                return (
                                                                    <SelectItem key={campaign.id} value={campaign.id.toString()}>
                                                                        {campaign.name} ({count})
                                                                    </SelectItem>
                                                                );
                                                            })}
                                                            <SelectItem value="no_campaign">
                                                                No Campaign ({users.filter(u => !u.schedule || !u.schedule.campaign_id).length})
                                                            </SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                                <div className="space-y-2">
                                                    <Label htmlFor="shift_filter" className="text-sm font-normal">
                                                        Quick Select by Shift Schedule
                                                    </Label>
                                                    <Select value={shiftFilter} onValueChange={(value) => {
                                                        setShiftFilter(value);
                                                        selectUsersByShift(value);
                                                    }}>
                                                        <SelectTrigger>
                                                            <SelectValue placeholder="Select shift schedule" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="all">All Employees ({users.length})</SelectItem>
                                                            {uniqueShifts.map((shift, index) => {
                                                                const shiftKey = JSON.stringify(shift);
                                                                const count = users.filter(u => u.schedule &&
                                                                    u.schedule.shift_type === shift.shift_type &&
                                                                    u.schedule.scheduled_time_in === shift.time_in &&
                                                                    u.schedule.scheduled_time_out === shift.time_out &&
                                                                    u.schedule.site_name === shift.site
                                                                ).length;
                                                                return (
                                                                    <SelectItem key={index} value={shiftKey}>
                                                                        {shift.shift_type.replace('_', ' ').toUpperCase()} - {formatTime(shift.time_in)} to {formatTime(shift.time_out)}
                                                                        {shift.site && ` (${shift.site})`} ({count})
                                                                    </SelectItem>
                                                                );
                                                            })}
                                                            <SelectItem value="no_schedule">
                                                                No Schedule ({users.filter(u => !u.schedule).length})
                                                            </SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                            </div>
                                            <div className="flex gap-2 mb-2">
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={selectAllUsers}
                                                >
                                                    Select All ({filteredUsers.length})
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={clearAllUsers}
                                                >
                                                    Clear All
                                                </Button>
                                                {selectedUserIds.length > 0 && (
                                                    <span className="text-sm text-muted-foreground self-center">
                                                        {selectedUserIds.length} selected
                                                    </span>
                                                )}
                                            </div>
                                        </>
                                    )}
                                    <Popover open={isUserPopoverOpen} onOpenChange={setIsUserPopoverOpen}>
                                        <PopoverTrigger asChild>
                                            <Button
                                                variant="outline"
                                                role="combobox"
                                                aria-expanded={isUserPopoverOpen}
                                                className="w-full justify-between font-normal"
                                            >
                                                {isBulkMode ? (
                                                    selectedUserIds.length > 0 ? (
                                                        <span className="truncate">
                                                            {selectedUserIds.length} employee{selectedUserIds.length > 1 ? 's' : ''} selected
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">Select employees...</span>
                                                    )
                                                ) : selectedUser ? (
                                                    <span className="truncate">
                                                        {selectedUser.name} ({selectedUser.email})
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
                                                        {filteredUsers.map((user) => (
                                                            <CommandItem
                                                                key={user.id}
                                                                value={`${user.name} ${user.email}`}
                                                                onSelect={() => {
                                                                    if (isBulkMode) {
                                                                        toggleUserSelection(user.id);
                                                                    } else {
                                                                        setData('user_id', user.id.toString());
                                                                        setIsUserPopoverOpen(false);
                                                                        setSearchQuery('');
                                                                    }
                                                                }}
                                                                className="cursor-pointer"
                                                            >
                                                                <Check
                                                                    className={`mr-2 h-4 w-4 ${isBulkMode
                                                                        ? selectedUserIds.includes(user.id)
                                                                            ? 'opacity-100'
                                                                            : 'opacity-0'
                                                                        : Number(data.user_id) === user.id
                                                                            ? 'opacity-100'
                                                                            : 'opacity-0'
                                                                        }`}
                                                                />
                                                                <div className="flex flex-col">
                                                                    <span className="font-medium">{user.name}</span>
                                                                    <span className="text-xs text-muted-foreground">
                                                                        {user.email}
                                                                    </span>
                                                                    {user.schedule && (
                                                                        <span className="text-xs text-muted-foreground">
                                                                            {user.schedule.shift_type.replace('_', ' ').toUpperCase()}: {formatTime(user.schedule.scheduled_time_in)} - {formatTime(user.schedule.scheduled_time_out)}
                                                                        </span>
                                                                    )}
                                                                </div>
                                                            </CommandItem>
                                                        ))}
                                                    </CommandGroup>
                                                </CommandList>
                                            </Command>
                                        </PopoverContent>
                                    </Popover>
                                    {/* Display selected employee schedule for single entry mode */}
                                    {!isBulkMode && selectedUser && (
                                        <div className="mt-3 p-4 bg-muted/50 border rounded-lg">
                                            <p className="text-sm font-semibold mb-2 flex items-center gap-2">
                                                <span>📅</span> Employee Schedule
                                            </p>
                                            {selectedUser.schedule ? (
                                                <div className="text-sm space-y-1.5">
                                                    <div className="flex items-center gap-2">
                                                        <span className="text-muted-foreground w-24">Shift Type:</span>
                                                        <span className="font-medium capitalize">{selectedUser.schedule.shift_type.replace('_', ' ')}</span>
                                                    </div>
                                                    <div className="flex items-center gap-2">
                                                        <span className="text-muted-foreground w-24">Time In/Out:</span>
                                                        <span className="font-medium">
                                                            {formatTime(selectedUser.schedule.scheduled_time_in)} - {formatTime(selectedUser.schedule.scheduled_time_out)}
                                                        </span>
                                                    </div>
                                                    <div className="flex items-center gap-2">
                                                        <span className="text-muted-foreground w-24">Site:</span>
                                                        <span className="font-medium">{selectedUser.schedule.site_name}</span>
                                                    </div>
                                                    {selectedUser.schedule.campaign_name && (
                                                        <div className="flex items-center gap-2">
                                                            <span className="text-muted-foreground w-24">Campaign:</span>
                                                            <span className="font-medium">{selectedUser.schedule.campaign_name}</span>
                                                        </div>
                                                    )}
                                                </div>
                                            ) : (
                                                <p className="text-sm text-muted-foreground italic">No schedule assigned to this employee</p>
                                            )}
                                        </div>
                                    )}
                                    {errors.user_id && (
                                        <p className="text-sm text-red-500">{errors.user_id}</p>
                                    )}
                                    {bulkErrors.user_ids && (
                                        <p className="text-sm text-red-500">{bulkErrors.user_ids}</p>
                                    )}
                                </div>

                                {/* Shift Date */}
                                <div className="space-y-2">
                                    <Label htmlFor="shift_date">
                                        Shift Date <span className="text-red-500">*</span>
                                    </Label>
                                    <DatePicker
                                        value={isBulkMode ? bulkData.shift_date : data.shift_date}
                                        onChange={(value) => {
                                            const newShiftDate = value;
                                            if (isBulkMode) {
                                                setBulkData('shift_date', newShiftDate);
                                                // Auto-update time-in date
                                                setBulkData('actual_time_in_date', newShiftDate);
                                                // Auto-update time-out date if time-out time exists
                                                if (bulkData.actual_time_out_time) {
                                                    const calculatedTimeOutDate = calculateTimeOutDate(
                                                        null,
                                                        newShiftDate,
                                                        bulkData.actual_time_in_time,
                                                        bulkData.actual_time_out_time
                                                    );
                                                    setBulkData('actual_time_out_date', calculatedTimeOutDate);
                                                } else {
                                                    setBulkData('actual_time_out_date', newShiftDate);
                                                }
                                            } else {
                                                setData('shift_date', newShiftDate);
                                                // Auto-update time-in date
                                                setData('actual_time_in_date', newShiftDate);
                                                // Auto-update time-out date if time-out time exists
                                                if (data.actual_time_out_time) {
                                                    const calculatedTimeOutDate = calculateTimeOutDate(
                                                        selectedUser?.schedule || null,
                                                        newShiftDate,
                                                        data.actual_time_in_time,
                                                        data.actual_time_out_time
                                                    );
                                                    setData('actual_time_out_date', calculatedTimeOutDate);
                                                } else {
                                                    setData('actual_time_out_date', newShiftDate);
                                                }
                                            }
                                        }}
                                        placeholder="Select date"
                                    />
                                    {(isBulkMode ? bulkErrors.shift_date : errors.shift_date) && (
                                        <p className="text-sm text-red-500">{isBulkMode ? bulkErrors.shift_date : errors.shift_date}</p>
                                    )}
                                </div>

                                {/* Status */}
                                <div className="space-y-2">
                                    <Label htmlFor="status">
                                        Status <span className="text-xs text-muted-foreground font-normal">(Optional - Auto-calculated from times)</span>
                                    </Label>

                                    {/* Auto-suggested status indicator (single entry mode only) */}
                                    {!isBulkMode && suggestedStatus && (
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
                                                            ? `Suggested: ${suggestedStatus.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}${suggestedSecondaryStatus ? ` + ${suggestedSecondaryStatus.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}` : ''} — Based on schedule and entered times`
                                                            : `${suggestedStatus.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}${suggestedSecondaryStatus ? ` + ${suggestedSecondaryStatus.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}` : ''} — Based on schedule and entered times`
                                                        }
                                                    </p>
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

                                    <Select
                                        value={isBulkMode ? bulkData.status : data.status}
                                        onValueChange={handleStatusChange}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Auto (based on time in/out)" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="on_time">On Time</SelectItem>
                                            <SelectItem value="tardy">Tardy</SelectItem>
                                            <SelectItem value="half_day_absence">Half Day Absence</SelectItem>
                                            <SelectItem value="advised_absence">Advised Absence</SelectItem>
                                            <SelectItem value="ncns">NCNS (No Call No Show)</SelectItem>
                                            <SelectItem value="undertime">Undertime (&lt;1hr)</SelectItem>
                                            <SelectItem value="undertime_more_than_hour">Undertime (&gt;1hr)</SelectItem>
                                            <SelectItem value="failed_bio_in">Failed Bio In</SelectItem>
                                            <SelectItem value="failed_bio_out">Failed Bio Out</SelectItem>
                                            <SelectItem value="present_no_bio">Present (No Bio)</SelectItem>
                                            <SelectItem value="non_work_day">Non-Work Day</SelectItem>
                                            <SelectItem value="on_leave">On Leave</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <p className="text-xs text-muted-foreground">
                                        {isBulkMode
                                            ? 'Leave empty to automatically determine status based on each employee\'s schedule and actual times.'
                                            : suggestedStatus
                                                ? 'Status auto-selected based on schedule. Override by selecting a different option.'
                                                : 'Leave empty to automatically determine status based on the schedule and actual times.'
                                        }
                                    </p>
                                    {(isBulkMode ? bulkErrors.status : errors.status) && (
                                        <p className="text-sm text-red-500">{isBulkMode ? bulkErrors.status : errors.status}</p>
                                    )}
                                </div>

                                {/* Time In/Out */}
                                <div className="space-y-4">
                                    <div className="space-y-2">
                                        <Label htmlFor="actual_time_in">
                                            Actual Time In
                                        </Label>
                                        <div className="grid grid-cols-2 gap-2">
                                            <div className="space-y-1">
                                                <DatePicker
                                                    value={isBulkMode ? bulkData.actual_time_in_date : data.actual_time_in_date}
                                                    onChange={(value) => {
                                                        if (isBulkMode) {
                                                            setBulkData('actual_time_in_date', value);
                                                        } else {
                                                            setData('actual_time_in_date', value);
                                                        }
                                                    }}
                                                    placeholder="Date"
                                                />
                                                {(isBulkMode ? bulkData.actual_time_in_date : data.actual_time_in_date) && (
                                                    <p className="text-xs text-muted-foreground">
                                                        {format(new Date((isBulkMode ? bulkData.actual_time_in_date : data.actual_time_in_date) + 'T00:00:00'), 'EEEE, MMM d, yyyy')}
                                                    </p>
                                                )}
                                            </div>
                                            <div className="space-y-1">
                                                <TimeInput
                                                    id="actual_time_in_time"
                                                    value={isBulkMode ? bulkData.actual_time_in_time : data.actual_time_in_time}
                                                    onChange={(value: string) => {
                                                        if (isBulkMode) {
                                                            setBulkData('actual_time_in_time', value);
                                                        } else {
                                                            setData('actual_time_in_time', value);
                                                        }
                                                    }}
                                                />
                                            </div>
                                        </div>
                                        {(isBulkMode ? bulkErrors.actual_time_in : errors.actual_time_in) && (
                                            <p className="text-sm text-red-500">{isBulkMode ? bulkErrors.actual_time_in : errors.actual_time_in}</p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="actual_time_out">
                                            Actual Time Out
                                            <span className="ml-2 text-xs text-muted-foreground font-normal">
                                                (Date auto-calculated based on shift)
                                            </span>
                                        </Label>
                                        <div className="grid grid-cols-2 gap-2">
                                            <div className="space-y-1">
                                                <DatePicker
                                                    value={isBulkMode ? bulkData.actual_time_out_date : data.actual_time_out_date}
                                                    onChange={(value) => {
                                                        if (isBulkMode) {
                                                            setBulkData('actual_time_out_date', value);
                                                        } else {
                                                            setData('actual_time_out_date', value);
                                                        }
                                                    }}
                                                    placeholder="Date"
                                                />
                                                {(isBulkMode ? bulkData.actual_time_out_date : data.actual_time_out_date) && (
                                                    <p className="text-xs text-muted-foreground">
                                                        {format(new Date((isBulkMode ? bulkData.actual_time_out_date : data.actual_time_out_date) + 'T00:00:00'), 'EEEE, MMM d, yyyy')}
                                                        {(isBulkMode ? bulkData.actual_time_out_date : data.actual_time_out_date) !== (isBulkMode ? bulkData.actual_time_in_date : data.actual_time_in_date) && (
                                                            <span className="ml-1 text-orange-600 font-medium">(Next Day)</span>
                                                        )}
                                                    </p>
                                                )}
                                            </div>
                                            <div className="space-y-1">
                                                <TimeInput
                                                    id="actual_time_out_time"
                                                    value={isBulkMode ? bulkData.actual_time_out_time : data.actual_time_out_time}
                                                    onChange={(value: string) => {
                                                        if (isBulkMode) {
                                                            setBulkData('actual_time_out_time', value);
                                                        } else {
                                                            setData('actual_time_out_time', value);
                                                        }
                                                    }}
                                                />
                                            </div>
                                        </div>
                                        {timeOutValidationError && (
                                            <p className="text-sm text-red-500">{timeOutValidationError}</p>
                                        )}
                                        {(isBulkMode ? bulkErrors.actual_time_out : errors.actual_time_out) && (
                                            <p className="text-sm text-red-500">{isBulkMode ? bulkErrors.actual_time_out : errors.actual_time_out}</p>
                                        )}
                                    </div>
                                </div>

                                {/* Set Home & Undertime Options - for undertime > 30 minutes (single entry mode only) */}
                                {!isBulkMode && suggestedUndertimeMinutes > 30 && (
                                    <div className="space-y-3 p-4 bg-amber-50 dark:bg-amber-950/50 border border-amber-200 dark:border-amber-800 rounded-lg">
                                        {/* Undertime Header */}
                                        <div className="flex items-center justify-between">
                                            <div className="flex items-center gap-2">
                                                <Clock className="h-4 w-4 text-amber-600" />
                                                <span className="text-sm font-medium text-amber-900 dark:text-amber-100">
                                                    Undertime: {suggestedUndertimeMinutes} min
                                                </span>
                                            </div>
                                            {/* Set Home Toggle - inline */}
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
                                        ) : canApproveUndertime ? (
                                            /* Admin/HR: Approval options */
                                            <div className="space-y-2">
                                                <div className="flex flex-wrap gap-2">
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant={data.undertime_approval_reason === 'generate_points' ? 'default' : 'outline'}
                                                        onClick={() => {
                                                            setData('undertime_approval_reason', 'generate_points');
                                                            setData('undertime_approval_status', 'approved');
                                                        }}
                                                        className="h-7 text-xs"
                                                    >
                                                        <Check className="h-3 w-3 mr-1" />
                                                        Generate Points
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant={data.undertime_approval_reason === 'skip_points' ? 'default' : 'outline'}
                                                        onClick={() => {
                                                            setData('undertime_approval_reason', 'skip_points');
                                                            setData('undertime_approval_status', 'approved');
                                                        }}
                                                        className="h-7 text-xs"
                                                    >
                                                        <X className="h-3 w-3 mr-1" />
                                                        Skip Points
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant={data.undertime_approval_reason === 'lunch_used' ? 'default' : 'outline'}
                                                        onClick={() => {
                                                            setData('undertime_approval_reason', 'lunch_used');
                                                            setData('undertime_approval_status', 'approved');
                                                        }}
                                                        className="h-7 text-xs"
                                                    >
                                                        <Clock className="h-3 w-3 mr-1" />
                                                        Lunch Used
                                                    </Button>
                                                    {data.undertime_approval_reason && (
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={() => {
                                                                setData('undertime_approval_reason', '');
                                                                setData('undertime_approval_status', '');
                                                            }}
                                                            className="h-7 text-xs text-muted-foreground"
                                                        >
                                                            Clear
                                                        </Button>
                                                    )}
                                                </div>
                                                <p className="text-xs text-amber-700 dark:text-amber-300">
                                                    {data.undertime_approval_reason === 'skip_points' && '✓ No points will be generated'}
                                                    {data.undertime_approval_reason === 'lunch_used' && '✓ Lunch time credited (+1hr)'}
                                                    {data.undertime_approval_reason === 'generate_points' && '• Points will be generated'}
                                                    {!data.undertime_approval_reason && '• Select option or leave blank for default (generate points)'}
                                                </p>
                                            </div>
                                        ) : (
                                            <p className="text-xs text-amber-700 dark:text-amber-300">
                                                • Undertime points will be generated. Request approval after creating record.
                                            </p>
                                        )}
                                    </div>
                                )}

                                {/* Notes */}
                                <div className="space-y-2">
                                    <Label htmlFor="notes">Notes</Label>
                                    <Textarea
                                        id="notes"
                                        value={isBulkMode ? bulkData.notes : data.notes}
                                        onChange={(e) => {
                                            if (isBulkMode) {
                                                setBulkData('notes', e.target.value);
                                            } else {
                                                setData('notes', e.target.value);
                                            }
                                        }}
                                        placeholder={isBulkMode
                                            ? 'Add notes that will apply to all selected employees...'
                                            : 'Add any additional notes about this attendance record...'
                                        }
                                        rows={4}
                                        className="resize-none"
                                    />
                                    {(isBulkMode ? bulkErrors.notes : errors.notes) && (
                                        <p className="text-sm text-red-500">{isBulkMode ? bulkErrors.notes : errors.notes}</p>
                                    )}
                                    <p className="text-xs text-muted-foreground">
                                        {(isBulkMode ? bulkData.notes : data.notes).length}/500 characters
                                    </p>
                                </div>

                                {/* Info Alert */}
                                <Alert>
                                    <AlertCircle className="h-4 w-4" />
                                    <AlertTitle>Note</AlertTitle>
                                    <AlertDescription>
                                        {isBulkMode
                                            ? `All ${selectedUserIds.length} attendance records will be automatically marked as verified. The status, tardy, undertime, and overtime will be calculated automatically for each employee based on their actual times and schedule.`
                                            : 'Manual attendance records are automatically marked as verified. The status (on_time, tardy, undertime, etc.) will be determined automatically based on the actual times and the employee\'s schedule. You can override the status by selecting one manually.'}
                                    </AlertDescription>
                                </Alert>

                                {/* Submit Buttons */}
                                <div className="flex gap-4">
                                    <Button
                                        type="submit"
                                        disabled={isBulkMode ? (bulkProcessing || selectedUserIds.length === 0) : processing}
                                    >
                                        {isBulkMode
                                            ? bulkProcessing
                                                ? 'Creating...'
                                                : `Create ${selectedUserIds.length} Record${selectedUserIds.length > 1 ? 's' : ''}`
                                            : processing
                                                ? 'Creating...'
                                                : 'Create Attendance Record'}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => router.visit(attendanceIndex().url)}
                                    >
                                        Cancel
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
