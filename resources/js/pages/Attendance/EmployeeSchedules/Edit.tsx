import React, { useState } from "react";
import { Head, router, useForm, usePage } from "@inertiajs/react";
import AppLayout from "@/layouts/app-layout";
import { useFlashMessage, usePageLoading, usePageMeta } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { LoadingOverlay } from "@/components/LoadingOverlay";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue
} from "@/components/ui/select";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from "@/components/ui/command";
import { Check, ChevronsUpDown } from "lucide-react";
import { toast } from "sonner";
import {
    index as employeeSchedulesIndex,
    edit as employeeSchedulesEdit,
    update as employeeSchedulesUpdate,
} from "@/routes/employee-schedules";

interface User {
    id: number;
    name: string;
    email?: string;
}

interface Campaign {
    id: number;
    name: string;
}

interface Site {
    id: number;
    name: string;
}

interface Schedule {
    id: number;
    user_id: number;
    campaign_id?: number;
    site_id?: number;
    shift_type: string;
    scheduled_time_in: string;
    scheduled_time_out: string;
    work_days: string[];
    grace_period_minutes: number;
    is_active: boolean;
    effective_date: string;
    end_date?: string;
}

interface PageProps {
    schedule: Schedule;
    users: User[];
    campaigns: Campaign[];
    sites: Site[];
    auth: {
        user: {
            time_format: string;
        };
    };
    [key: string]: unknown;
}

const DAYS_OF_WEEK = [
    { value: "monday", label: "Monday" },
    { value: "tuesday", label: "Tuesday" },
    { value: "wednesday", label: "Wednesday" },
    { value: "thursday", label: "Thursday" },
    { value: "friday", label: "Friday" },
    { value: "saturday", label: "Saturday" },
    { value: "sunday", label: "Sunday" },
];

// Helper function to format time based on user preference
const formatTime = (time: string, format: string): string => {
    if (!time) return '';
    if (format === '12') {
        const [hour, minute] = time.split(':');
        const h = parseInt(hour);
        const period = h >= 12 ? 'PM' : 'AM';
        const hour12 = h === 0 ? 12 : h > 12 ? h - 12 : h;
        return `${hour12}:${minute} ${period}`;
    }
    return time;
};

// Helper function to format time range based on user preference
const formatTimeRange = (start: string, end: string, format: string): string => {
    return `${formatTime(start, format)} - ${formatTime(end, format)}`;
};

// Generate hour options based on format
const getHourOptions = (format: string) => {
    if (format === '12') {
        return Array.from({ length: 12 }, (_, i) => {
            const hour = i === 0 ? 12 : i;
            return { value: String(hour), label: String(hour).padStart(2, '0') };
        });
    }
    return Array.from({ length: 24 }, (_, i) => ({
        value: String(i),
        label: String(i).padStart(2, '0')
    }));
};

// Generate minute options
const MINUTE_OPTIONS = Array.from({ length: 60 }, (_, i) => ({
    value: String(i).padStart(2, '0'),
    label: String(i).padStart(2, '0')
}));

// Parse 24h time string to components
const parseTime = (time: string, format: string): { hour: string; minute: string; period: string } => {
    if (!time) return { hour: '', minute: '', period: 'AM' };
    const [h, m] = time.split(':');
    const hour24 = parseInt(h);

    if (format === '12') {
        const period = hour24 >= 12 ? 'PM' : 'AM';
        let hour12 = hour24 % 12;
        if (hour12 === 0) hour12 = 12;
        return { hour: String(hour12), minute: m, period };
    }
    return { hour: String(hour24), minute: m, period: 'AM' };
};

// Convert components back to 24h time string
const buildTime = (hour: string, minute: string, period: string, format: string): string => {
    if (!hour || !minute) return '';
    let h = parseInt(hour);

    if (format === '12') {
        if (period === 'AM') {
            h = h === 12 ? 0 : h;
        } else {
            h = h === 12 ? 12 : h + 12;
        }
    }
    return `${String(h).padStart(2, '0')}:${minute}`;
};

export default function EmployeeScheduleEdit() {
    const { schedule, users, campaigns, sites, auth } = usePage<PageProps>().props;
    const timeFormat = auth.user.time_format || '24';

    const { title, breadcrumbs } = usePageMeta({
        title: "Edit Employee Schedule",
        breadcrumbs: [
            { title: "Employee Schedules", href: employeeSchedulesIndex().url },
            { title: "Edit", href: employeeSchedulesEdit({ employee_schedule: schedule.id }).url },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    const { data, setData, put, processing, errors } = useForm({
        campaign_id: schedule.campaign_id || null,
        site_id: schedule.site_id || null,
        shift_type: schedule.shift_type,
        scheduled_time_in: schedule.scheduled_time_in,
        scheduled_time_out: schedule.scheduled_time_out,
        work_days: schedule.work_days,
        grace_period_minutes: schedule.grace_period_minutes,
        is_active: schedule.is_active,
        end_date: schedule.end_date || "",
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(employeeSchedulesUpdate({ employee_schedule: schedule.id }).url, {
            onError: (errors) => {
                const firstError = Object.values(errors)[0] as string;
                toast.error(firstError || "Failed to update employee schedule");
            },
        });
    };

    const toggleWorkDay = (day: string) => {
        if (data.work_days.includes(day)) {
            setData("work_days", data.work_days.filter(d => d !== day));
        } else {
            setData("work_days", [...data.work_days, day]);
        }
    };

    const selectedUser = users.find(u => u.id === schedule.user_id);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-6 rounded-xl p-3 relative">
                <LoadingOverlay isLoading={isPageLoading} />

                <PageHeader
                    title="Edit Employee Schedule"
                    description="Update employee work schedule"
                />
                <div className="max-w-2xl mx-auto w-full">
                    <Card>
                        <CardHeader>
                            <CardTitle>Schedule Details</CardTitle>
                            <CardDescription>
                                Configure the employee's shift times, work days, and assignments
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-6">
                                {/* Employee Display (Read-only) */}
                                <div className="space-y-2">
                                    <Label>Employee</Label>
                                    <Input
                                        value={selectedUser?.name || "Unknown"}
                                        disabled
                                        className="bg-muted"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Employee cannot be changed after schedule creation
                                    </p>
                                </div>

                                {/* Campaign & Site */}
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div className="space-y-2">
                                        <Label htmlFor="campaign_id">Campaign</Label>
                                        <Select
                                            value={data.campaign_id ? String(data.campaign_id) : undefined}
                                            onValueChange={value => setData("campaign_id", value ? parseInt(value) : null)}
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select campaign (optional)" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {campaigns.map(campaign => (
                                                    <SelectItem key={campaign.id} value={String(campaign.id)}>
                                                        {campaign.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {errors.campaign_id && (
                                            <p className="text-sm text-red-500">{errors.campaign_id}</p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="site_id">Site</Label>
                                        <Select
                                            value={data.site_id ? String(data.site_id) : undefined}
                                            onValueChange={value => setData("site_id", value ? parseInt(value) : null)}
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select site (optional)" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {sites.map(site => (
                                                    <SelectItem key={site.id} value={String(site.id)}>
                                                        {site.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {errors.site_id && (
                                            <p className="text-sm text-red-500">{errors.site_id}</p>
                                        )}
                                    </div>
                                </div>

                                {/* Shift Type */}
                                <div className="space-y-2">
                                    <Label htmlFor="shift_type">
                                        Shift Type <span className="text-red-500">*</span>
                                    </Label>
                                    <Select
                                        value={data.shift_type}
                                        onValueChange={value => setData("shift_type", value)}
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="graveyard_shift">
                                                Graveyard Shift ({formatTimeRange('00:00', '09:00', timeFormat)})
                                            </SelectItem>
                                            <SelectItem value="morning_shift">
                                                Morning Shift ({formatTimeRange('05:00', '14:00', timeFormat)})
                                            </SelectItem>
                                            <SelectItem value="afternoon_shift">
                                                Afternoon Shift ({formatTimeRange('14:00', '23:00', timeFormat)})
                                            </SelectItem>
                                            <SelectItem value="night_shift">
                                                Night Shift ({formatTimeRange('22:00', '07:00', timeFormat)})
                                            </SelectItem>
                                            <SelectItem value="utility_24h">24H Utility</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    {errors.shift_type && (
                                        <p className="text-sm text-red-500">{errors.shift_type}</p>
                                    )}
                                </div>

                                {/* Shift Times */}
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div className="space-y-2">
                                        <Label>
                                            Time In <span className="text-red-500">*</span>
                                        </Label>
                                        <div className="flex gap-2">
                                            <Select
                                                value={parseTime(data.scheduled_time_in, timeFormat).hour}
                                                onValueChange={(hour) => {
                                                    const parsed = parseTime(data.scheduled_time_in, timeFormat);
                                                    setData("scheduled_time_in", buildTime(hour, parsed.minute || '00', parsed.period, timeFormat));
                                                }}
                                            >
                                                <SelectTrigger className="w-[80px]">
                                                    <SelectValue placeholder="HH" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {getHourOptions(timeFormat).map(opt => (
                                                        <SelectItem key={opt.value} value={opt.value}>
                                                            {opt.label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <span className="flex items-center text-lg">:</span>
                                            <Select
                                                value={parseTime(data.scheduled_time_in, timeFormat).minute}
                                                onValueChange={(minute) => {
                                                    const parsed = parseTime(data.scheduled_time_in, timeFormat);
                                                    setData("scheduled_time_in", buildTime(parsed.hour || '0', minute, parsed.period, timeFormat));
                                                }}
                                            >
                                                <SelectTrigger className="w-[80px]">
                                                    <SelectValue placeholder="MM" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {MINUTE_OPTIONS.map(opt => (
                                                        <SelectItem key={opt.value} value={opt.value}>
                                                            {opt.label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            {timeFormat === '12' && (
                                                <Select
                                                    value={parseTime(data.scheduled_time_in, timeFormat).period}
                                                    onValueChange={(period) => {
                                                        const parsed = parseTime(data.scheduled_time_in, timeFormat);
                                                        setData("scheduled_time_in", buildTime(parsed.hour || '12', parsed.minute || '00', period, timeFormat));
                                                    }}
                                                >
                                                    <SelectTrigger className="w-[80px]">
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="AM">AM</SelectItem>
                                                        <SelectItem value="PM">PM</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            )}
                                        </div>
                                        {errors.scheduled_time_in && (
                                            <p className="text-sm text-red-500">{errors.scheduled_time_in}</p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label>
                                            Time Out <span className="text-red-500">*</span>
                                        </Label>
                                        <div className="flex gap-2">
                                            <Select
                                                value={parseTime(data.scheduled_time_out, timeFormat).hour}
                                                onValueChange={(hour) => {
                                                    const parsed = parseTime(data.scheduled_time_out, timeFormat);
                                                    setData("scheduled_time_out", buildTime(hour, parsed.minute || '00', parsed.period, timeFormat));
                                                }}
                                            >
                                                <SelectTrigger className="w-[80px]">
                                                    <SelectValue placeholder="HH" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {getHourOptions(timeFormat).map(opt => (
                                                        <SelectItem key={opt.value} value={opt.value}>
                                                            {opt.label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <span className="flex items-center text-lg">:</span>
                                            <Select
                                                value={parseTime(data.scheduled_time_out, timeFormat).minute}
                                                onValueChange={(minute) => {
                                                    const parsed = parseTime(data.scheduled_time_out, timeFormat);
                                                    setData("scheduled_time_out", buildTime(parsed.hour || '0', minute, parsed.period, timeFormat));
                                                }}
                                            >
                                                <SelectTrigger className="w-[80px]">
                                                    <SelectValue placeholder="MM" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {MINUTE_OPTIONS.map(opt => (
                                                        <SelectItem key={opt.value} value={opt.value}>
                                                            {opt.label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            {timeFormat === '12' && (
                                                <Select
                                                    value={parseTime(data.scheduled_time_out, timeFormat).period}
                                                    onValueChange={(period) => {
                                                        const parsed = parseTime(data.scheduled_time_out, timeFormat);
                                                        setData("scheduled_time_out", buildTime(parsed.hour || '12', parsed.minute || '00', period, timeFormat));
                                                    }}
                                                >
                                                    <SelectTrigger className="w-[80px]">
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="AM">AM</SelectItem>
                                                        <SelectItem value="PM">PM</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            )}
                                        </div>
                                        {errors.scheduled_time_out && (
                                            <p className="text-sm text-red-500">{errors.scheduled_time_out}</p>
                                        )}
                                    </div>
                                </div>

                                {/* Work Days */}
                                <div className="space-y-2">
                                    <Label>
                                        Work Days <span className="text-red-500">*</span>
                                    </Label>
                                    <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                                        {DAYS_OF_WEEK.map(day => (
                                            <div key={day.value} className="flex items-center space-x-2">
                                                <Checkbox
                                                    id={day.value}
                                                    checked={data.work_days.includes(day.value)}
                                                    onCheckedChange={() => toggleWorkDay(day.value)}
                                                />
                                                <Label
                                                    htmlFor={day.value}
                                                    className="text-sm font-normal cursor-pointer"
                                                >
                                                    {day.label}
                                                </Label>
                                            </div>
                                        ))}
                                    </div>
                                    {errors.work_days && (
                                        <p className="text-sm text-red-500">{errors.work_days}</p>
                                    )}
                                </div>

                                {/* Grace Period */}
                                <div className="space-y-2">
                                    <Label htmlFor="grace_period_minutes">
                                        Grace Period (minutes) <span className="text-red-500">*</span>
                                    </Label>
                                    <Input
                                        type="number"
                                        min="0"
                                        max="60"
                                        value={data.grace_period_minutes}
                                        onChange={e => setData("grace_period_minutes", parseInt(e.target.value) || 0)}
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Minutes late before considered tardy (typically 15 minutes)
                                    </p>
                                    {errors.grace_period_minutes && (
                                        <p className="text-sm text-red-500">{errors.grace_period_minutes}</p>
                                    )}
                                </div>

                                {/* Effective Date (Read-only) */}
                                <div className="space-y-2">
                                    <Label>Effective Date</Label>
                                    <Input
                                        type="date"
                                        value={schedule.effective_date}
                                        disabled
                                        className="bg-muted"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Effective date cannot be changed after creation
                                    </p>
                                </div>

                                {/* End Date */}
                                <div className="space-y-2">
                                    <Label htmlFor="end_date">End Date (Optional)</Label>
                                    <Input
                                        type="date"
                                        value={data.end_date}
                                        onChange={e => setData("end_date", e.target.value)}
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Leave blank for indefinite schedule
                                    </p>
                                    {errors.end_date && (
                                        <p className="text-sm text-red-500">{errors.end_date}</p>
                                    )}
                                </div>

                                {/* Active Status */}
                                <div className="flex items-center space-x-2">
                                    <Checkbox
                                        id="is_active"
                                        checked={data.is_active}
                                        onCheckedChange={checked => setData("is_active", checked as boolean)}
                                    />
                                    <Label htmlFor="is_active" className="text-sm font-normal cursor-pointer">
                                        Active Schedule
                                    </Label>
                                </div>

                                {/* Form Actions */}
                                <div className="flex gap-3 pt-4">
                                    <Button type="submit" disabled={processing}>
                                        {processing ? "Updating..." : "Update Schedule"}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => router.get(employeeSchedulesIndex().url)}
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
