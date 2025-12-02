import React, { useState, useEffect, useCallback } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { PageHeader } from '@/components/PageHeader';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { format, parse } from 'date-fns';
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
import { AlertCircle, Check, ChevronsUpDown, Sparkles, RefreshCw } from 'lucide-react';
import { toast } from 'sonner';
import { useFlashMessage, usePageMeta } from '@/hooks';
import { index as attendanceIndex, create as attendanceCreate, store as attendanceStore, bulkStore as attendanceBulkStore } from '@/routes/attendance';

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
    const { auth } = usePage<{ auth: { user: { time_format: string } } }>().props;
    const rawTimeFormat = auth.user?.time_format;
    const timeFormat = String(rawTimeFormat || '24');
    const is24HourFormat = timeFormat === '24';

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
        actual_time_in_time: '01:00',
        actual_time_out_date: '',
        actual_time_out_time: '01:00',
        notes: '',
    });

    const { data: bulkData, setData: setBulkData, post: bulkPost, processing: bulkProcessing, errors: bulkErrors } = useForm({
        user_ids: [] as number[],
        shift_date: '',
        status: '',
        secondary_status: '',
        actual_time_in: '',
        actual_time_out: '',
        actual_time_in_date: '',
        actual_time_in_time: '01:00',
        actual_time_out_date: '',
        actual_time_out_time: '01:00',
        notes: '',
    });

    const [searchQuery, setSearchQuery] = useState('');
    const [isUserPopoverOpen, setIsUserPopoverOpen] = useState(false);
    const [isBulkMode, setIsBulkMode] = useState(false);
    const [selectedUserIds, setSelectedUserIds] = useState<number[]>([]);
    const [shiftFilter, setShiftFilter] = useState<string>('all');
    const [campaignFilter, setCampaignFilter] = useState<string>('all');
    const [timeOutValidationError, setTimeOutValidationError] = useState<string>('');

    // Auto-status selection state (single entry mode only)
    const [suggestedStatus, setSuggestedStatus] = useState<string>('');
    const [suggestedSecondaryStatus, setSuggestedSecondaryStatus] = useState<string>('');
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
    ): { status: string; secondaryStatus?: string; reason: string } => {
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
        const gracePeriodMinutes = 15; // Default grace period

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
        } else if (tardyMinutes >= 15) {
            // 15+ minutes late but within grace period = tardy
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

            if (earlyDepartureMinutes >= 60) {
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
            secondaryStatus = 'undertime';
            reason = `Arrived ${Math.round(tardyMinutes)} minutes late AND left ${undertimeMinutes} minutes early`;
        } else if (isHalfDay) {
            status = 'half_day_absence';
            reason = `Arrived ${Math.round(tardyMinutes)} minutes late (more than ${gracePeriodMinutes}min grace period)`;
        } else if (isTardy && hasUndertime) {
            status = 'tardy';
            secondaryStatus = 'undertime';
            reason = `Arrived ${Math.round(tardyMinutes)} minutes late AND left ${undertimeMinutes} minutes early`;
        } else if (isTardy) {
            status = 'tardy';
            reason = `Arrived ${Math.round(tardyMinutes)} minutes late`;
        } else if (hasUndertime) {
            status = 'undertime';
            reason = `Left ${undertimeMinutes} minutes early`;
        } else {
            status = 'on_time';
            reason = 'Arrived within grace period';
        }

        return { status, secondaryStatus, reason };
    }, []);

    // Recalculate suggested status when relevant fields change (single entry mode)
    const recalculateSuggestedStatus = useCallback(() => {
        if (isBulkMode || !selectedUser?.schedule) {
            setSuggestedStatus('');
            setSuggestedSecondaryStatus('');
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

    // Time format conversion helpers
    const convertTo24Hour = (hour: string, minute: string, period: 'AM' | 'PM') => {
        let h = parseInt(hour);
        if (period === 'PM' && h !== 12) h += 12;
        if (period === 'AM' && h === 12) h = 0;
        return `${h.toString().padStart(2, '0')}:${minute.padStart(2, '0')}`;
    };

    const convertFrom24Hour = (time24: string): { hour: string; minute: string; period: 'AM' | 'PM' } => {
        if (!time24) return { hour: '12', minute: '00', period: 'AM' };
        const [h, m] = time24.split(':');
        let hour = parseInt(h);
        const period: 'AM' | 'PM' = hour >= 12 ? 'PM' : 'AM';
        if (hour === 0) hour = 12;
        else if (hour > 12) hour -= 12;
        return { hour: hour.toString(), minute: m || '00', period };
    };

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
            onSuccess: () => {
                toast.success('Attendance record created successfully!');
                router.visit(attendanceIndex().url);
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
            onSuccess: () => {
                toast.success(`${selectedUserIds.length} attendance records created successfully!`);
                router.visit(attendanceIndex().url);
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

    // Helper function to format time based on user preference
    const formatTimeForDisplay = (time: string) => {
        if (!time) return '';
        try {
            const date = parse(time, 'HH:mm:ss', new Date());
            return is24HourFormat ? format(date, 'HH:mm') : format(date, 'hh:mm a');
        } catch {
            return time;
        }
    };

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
                                                                        {shift.shift_type.replace('_', ' ').toUpperCase()} - {formatTimeForDisplay(shift.time_in)} to {formatTimeForDisplay(shift.time_out)}
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
                                                                            {user.schedule.shift_type.replace('_', ' ').toUpperCase()}: {formatTimeForDisplay(user.schedule.scheduled_time_in)} - {formatTimeForDisplay(user.schedule.scheduled_time_out)}
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
                                                            {formatTimeForDisplay(selectedUser.schedule.scheduled_time_in)} - {formatTimeForDisplay(selectedUser.schedule.scheduled_time_out)}
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
                                    <Input
                                        id="shift_date"
                                        type="date"
                                        value={isBulkMode ? bulkData.shift_date : data.shift_date}
                                        onChange={(e) => {
                                            if (isBulkMode) {
                                                setBulkData('shift_date', e.target.value);
                                            } else {
                                                setData('shift_date', e.target.value);
                                            }
                                        }}
                                        max={new Date().toISOString().split('T')[0]}
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
                                        <div className={`p-3 rounded-md border ${!isStatusManuallyOverridden
                                            ? 'bg-green-50 border-green-200 dark:bg-green-950/30 dark:border-green-800'
                                            : 'bg-amber-50 border-amber-200 dark:bg-amber-950/30 dark:border-amber-800'
                                            }`}>
                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center gap-2">
                                                    <Sparkles className={`h-4 w-4 ${!isStatusManuallyOverridden ? 'text-green-600' : 'text-amber-600'}`} />
                                                    <span className="text-sm font-medium">
                                                        {!isStatusManuallyOverridden ? 'Auto-suggested: ' : 'Suggested: '}
                                                        <span className="capitalize">{suggestedStatus.replace(/_/g, ' ')}</span>
                                                        {suggestedSecondaryStatus && (
                                                            <span className="text-muted-foreground"> + <span className="capitalize">{suggestedSecondaryStatus.replace(/_/g, ' ')}</span></span>
                                                        )}
                                                    </span>
                                                </div>
                                                {isStatusManuallyOverridden && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={resetToSuggestedStatus}
                                                        className="h-7 text-xs"
                                                    >
                                                        <RefreshCw className="h-3 w-3 mr-1" />
                                                        Use Suggested
                                                    </Button>
                                                )}
                                            </div>
                                            {!isStatusManuallyOverridden && (
                                                <p className="text-xs text-muted-foreground mt-1">
                                                    Based on schedule and entered times. You can override by selecting a different status below.
                                                </p>
                                            )}
                                            {isStatusManuallyOverridden && (
                                                <p className="text-xs text-amber-600 dark:text-amber-400 mt-1">
                                                    You've selected a different status. Click "Use Suggested" to revert.
                                                </p>
                                            )}
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
                                            <SelectItem value="undertime">Undertime</SelectItem>
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
                                            <span className="ml-2 text-xs text-muted-foreground font-normal">
                                                ({is24HourFormat ? '24-hour format' : '12-hour format'})
                                            </span>
                                        </Label>
                                        <div className="grid grid-cols-2 gap-2">
                                            <Input
                                                id="actual_time_in_date"
                                                type="date"
                                                placeholder="Date"
                                                value={isBulkMode ? bulkData.actual_time_in_date : data.actual_time_in_date}
                                                onChange={(e) => {
                                                    if (isBulkMode) {
                                                        setBulkData('actual_time_in_date', e.target.value);
                                                    } else {
                                                        setData('actual_time_in_date', e.target.value);
                                                    }
                                                }}
                                            />
                                            {is24HourFormat === true ? (
                                                <div className="grid grid-cols-2 gap-1">
                                                    <Select
                                                        value={isBulkMode ? bulkData.actual_time_in_time.split(':')[0] || '00' : data.actual_time_in_time.split(':')[0] || '00'}
                                                        onValueChange={(hour) => {
                                                            const minute = (isBulkMode ? bulkData.actual_time_in_time : data.actual_time_in_time).split(':')[1] || '00';
                                                            // Convert display value (01-24) to internal value (00-23)
                                                            const internalHour = hour === '24' ? '00' : hour;
                                                            const time24 = `${internalHour}:${minute}`;
                                                            if (isBulkMode) {
                                                                setBulkData('actual_time_in_time', time24);
                                                            } else {
                                                                setData('actual_time_in_time', time24);
                                                            }
                                                        }}
                                                    >
                                                        <SelectTrigger className="h-10">
                                                            <SelectValue placeholder="HH" />
                                                        </SelectTrigger>
                                                        <SelectContent className="max-h-[200px]">
                                                            {Array.from({ length: 24 }, (_, i) => i + 1).map((h) => (
                                                                <SelectItem key={h} value={h === 24 ? '00' : h.toString().padStart(2, '0')}>
                                                                    {h.toString().padStart(2, '0')}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                    <Select
                                                        value={(isBulkMode ? bulkData.actual_time_in_time : data.actual_time_in_time).split(':')[1] || '00'}
                                                        onValueChange={(minute) => {
                                                            const hour = (isBulkMode ? bulkData.actual_time_in_time : data.actual_time_in_time).split(':')[0] || '00';
                                                            const time24 = `${hour}:${minute}`;
                                                            if (isBulkMode) {
                                                                setBulkData('actual_time_in_time', time24);
                                                            } else {
                                                                setData('actual_time_in_time', time24);
                                                            }
                                                        }}
                                                    >
                                                        <SelectTrigger className="h-10">
                                                            <SelectValue placeholder="MM" />
                                                        </SelectTrigger>
                                                        <SelectContent className="max-h-[200px]">
                                                            {Array.from({ length: 60 }, (_, i) => i).map((m) => (
                                                                <SelectItem key={m} value={m.toString().padStart(2, '0')}>
                                                                    {m.toString().padStart(2, '0')}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                            ) : is24HourFormat === false ? (
                                                <div className="grid grid-cols-3 gap-1">
                                                    <Select
                                                        value={convertFrom24Hour(isBulkMode ? bulkData.actual_time_in_time : data.actual_time_in_time).hour}
                                                        onValueChange={(hour) => {
                                                            const current = convertFrom24Hour(isBulkMode ? bulkData.actual_time_in_time : data.actual_time_in_time);
                                                            const time24 = convertTo24Hour(hour, current.minute, current.period);
                                                            if (isBulkMode) {
                                                                setBulkData('actual_time_in_time', time24);
                                                            } else {
                                                                setData('actual_time_in_time', time24);
                                                            }
                                                        }}
                                                    >
                                                        <SelectTrigger className="h-10">
                                                            <SelectValue placeholder="HH" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {Array.from({ length: 12 }, (_, i) => i + 1).map((h) => (
                                                                <SelectItem key={h} value={h.toString()}>
                                                                    {h.toString().padStart(2, '0')}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                    <Select
                                                        value={convertFrom24Hour(isBulkMode ? bulkData.actual_time_in_time : data.actual_time_in_time).minute}
                                                        onValueChange={(minute) => {
                                                            const current = convertFrom24Hour(isBulkMode ? bulkData.actual_time_in_time : data.actual_time_in_time);
                                                            const time24 = convertTo24Hour(current.hour, minute, current.period);
                                                            if (isBulkMode) {
                                                                setBulkData('actual_time_in_time', time24);
                                                            } else {
                                                                setData('actual_time_in_time', time24);
                                                            }
                                                        }}
                                                    >
                                                        <SelectTrigger className="h-10">
                                                            <SelectValue placeholder="MM" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {Array.from({ length: 60 }, (_, i) => i).map((m) => (
                                                                <SelectItem key={m} value={m.toString().padStart(2, '0')}>
                                                                    {m.toString().padStart(2, '0')}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                    <Select
                                                        value={convertFrom24Hour(isBulkMode ? bulkData.actual_time_in_time : data.actual_time_in_time).period}
                                                        onValueChange={(period: 'AM' | 'PM') => {
                                                            const current = convertFrom24Hour(isBulkMode ? bulkData.actual_time_in_time : data.actual_time_in_time);
                                                            const time24 = convertTo24Hour(current.hour, current.minute, period);
                                                            if (isBulkMode) {
                                                                setBulkData('actual_time_in_time', time24);
                                                            } else {
                                                                setData('actual_time_in_time', time24);
                                                            }
                                                        }}
                                                    >
                                                        <SelectTrigger className="h-10">
                                                            <SelectValue />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="AM">AM</SelectItem>
                                                            <SelectItem value="PM">PM</SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                            ) : null}
                                        </div>
                                        {(isBulkMode ? bulkErrors.actual_time_in : errors.actual_time_in) && (
                                            <p className="text-sm text-red-500">{isBulkMode ? bulkErrors.actual_time_in : errors.actual_time_in}</p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="actual_time_out">
                                            Actual Time Out
                                            <span className="ml-2 text-xs text-muted-foreground font-normal">
                                                ({is24HourFormat ? '24-hour format' : '12-hour format'})
                                            </span>
                                        </Label>
                                        <div className="grid grid-cols-2 gap-2">
                                            <Input
                                                id="actual_time_out_date"
                                                type="date"
                                                placeholder="Date"
                                                value={isBulkMode ? bulkData.actual_time_out_date : data.actual_time_out_date}
                                                onChange={(e) => {
                                                    if (isBulkMode) {
                                                        setBulkData('actual_time_out_date', e.target.value);
                                                        // Re-validate when date changes
                                                        const hour = bulkData.actual_time_out_time.split(':')[0] || '00';
                                                        const displayHour = hour === '00' ? '24' : hour;
                                                        validateTimeOut(bulkData.actual_time_in_date, e.target.value, displayHour);
                                                    } else {
                                                        setData('actual_time_out_date', e.target.value);
                                                        // Re-validate when date changes
                                                        const hour = data.actual_time_out_time.split(':')[0] || '00';
                                                        const displayHour = hour === '00' ? '24' : hour;
                                                        validateTimeOut(data.actual_time_in_date, e.target.value, displayHour);
                                                    }
                                                }}
                                            />
                                            {is24HourFormat === true ? (
                                                <div className="grid grid-cols-2 gap-1">
                                                    <Select
                                                        value={isBulkMode ? bulkData.actual_time_out_time.split(':')[0] || '00' : data.actual_time_out_time.split(':')[0] || '00'}
                                                        onValueChange={(hour) => {
                                                            const minute = (isBulkMode ? bulkData.actual_time_out_time : data.actual_time_out_time).split(':')[1] || '00';
                                                            // Convert display value (01-24) to internal value (00-23)
                                                            const internalHour = hour === '24' ? '00' : hour;
                                                            const time24 = `${internalHour}:${minute}`;
                                                            if (isBulkMode) {
                                                                setBulkData('actual_time_out_time', time24);
                                                                // Validate if hour 24 is selected on same date
                                                                validateTimeOut(bulkData.actual_time_in_date, bulkData.actual_time_out_date, hour);
                                                            } else {
                                                                setData('actual_time_out_time', time24);
                                                                // Validate if hour 24 is selected on same date
                                                                validateTimeOut(data.actual_time_in_date, data.actual_time_out_date, hour);
                                                            }
                                                        }}
                                                    >
                                                        <SelectTrigger className="h-10">
                                                            <SelectValue placeholder="HH" />
                                                        </SelectTrigger>
                                                        <SelectContent className="max-h-[200px]">
                                                            {Array.from({ length: 24 }, (_, i) => i + 1).map((h) => (
                                                                <SelectItem key={h} value={h === 24 ? '00' : h.toString().padStart(2, '0')}>
                                                                    {h.toString().padStart(2, '0')}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                    <Select
                                                        value={(isBulkMode ? bulkData.actual_time_out_time : data.actual_time_out_time).split(':')[1] || '00'}
                                                        onValueChange={(minute) => {
                                                            const hour = (isBulkMode ? bulkData.actual_time_out_time : data.actual_time_out_time).split(':')[0] || '00';
                                                            const time24 = `${hour}:${minute}`;
                                                            if (isBulkMode) {
                                                                setBulkData('actual_time_out_time', time24);
                                                            } else {
                                                                setData('actual_time_out_time', time24);
                                                            }
                                                        }}
                                                    >
                                                        <SelectTrigger className="h-10">
                                                            <SelectValue placeholder="MM" />
                                                        </SelectTrigger>
                                                        <SelectContent className="max-h-[200px]">
                                                            {Array.from({ length: 60 }, (_, i) => i).map((m) => (
                                                                <SelectItem key={m} value={m.toString().padStart(2, '0')}>
                                                                    {m.toString().padStart(2, '0')}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                            ) : is24HourFormat === false ? (
                                                <div className="grid grid-cols-3 gap-1">
                                                    <Select
                                                        value={convertFrom24Hour(isBulkMode ? bulkData.actual_time_out_time : data.actual_time_out_time).hour}
                                                        onValueChange={(hour) => {
                                                            const current = convertFrom24Hour(isBulkMode ? bulkData.actual_time_out_time : data.actual_time_out_time);
                                                            const time24 = convertTo24Hour(hour, current.minute, current.period);
                                                            if (isBulkMode) {
                                                                setBulkData('actual_time_out_time', time24);
                                                            } else {
                                                                setData('actual_time_out_time', time24);
                                                            }
                                                        }}
                                                    >
                                                        <SelectTrigger className="h-10">
                                                            <SelectValue placeholder="HH" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {Array.from({ length: 12 }, (_, i) => i + 1).map((h) => (
                                                                <SelectItem key={h} value={h.toString()}>
                                                                    {h.toString().padStart(2, '0')}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                    <Select
                                                        value={convertFrom24Hour(isBulkMode ? bulkData.actual_time_out_time : data.actual_time_out_time).minute}
                                                        onValueChange={(minute) => {
                                                            const current = convertFrom24Hour(isBulkMode ? bulkData.actual_time_out_time : data.actual_time_out_time);
                                                            const time24 = convertTo24Hour(current.hour, minute, current.period);
                                                            if (isBulkMode) {
                                                                setBulkData('actual_time_out_time', time24);
                                                            } else {
                                                                setData('actual_time_out_time', time24);
                                                            }
                                                        }}
                                                    >
                                                        <SelectTrigger className="h-10">
                                                            <SelectValue placeholder="MM" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {Array.from({ length: 60 }, (_, i) => i).map((m) => (
                                                                <SelectItem key={m} value={m.toString().padStart(2, '0')}>
                                                                    {m.toString().padStart(2, '0')}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                    <Select
                                                        value={convertFrom24Hour(isBulkMode ? bulkData.actual_time_out_time : data.actual_time_out_time).period}
                                                        onValueChange={(period: 'AM' | 'PM') => {
                                                            const current = convertFrom24Hour(isBulkMode ? bulkData.actual_time_out_time : data.actual_time_out_time);
                                                            const time24 = convertTo24Hour(current.hour, current.minute, period);
                                                            if (isBulkMode) {
                                                                setBulkData('actual_time_out_time', time24);
                                                            } else {
                                                                setData('actual_time_out_time', time24);
                                                            }
                                                        }}
                                                    >
                                                        <SelectTrigger className="h-10">
                                                            <SelectValue />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="AM">AM</SelectItem>
                                                            <SelectItem value="PM">PM</SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                            ) : null}
                                        </div>
                                        {timeOutValidationError && (
                                            <p className="text-sm text-red-500">{timeOutValidationError}</p>
                                        )}
                                        {(isBulkMode ? bulkErrors.actual_time_out : errors.actual_time_out) && (
                                            <p className="text-sm text-red-500">{isBulkMode ? bulkErrors.actual_time_out : errors.actual_time_out}</p>
                                        )}
                                    </div>
                                </div>

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
