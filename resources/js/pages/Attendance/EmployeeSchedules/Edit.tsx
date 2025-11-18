import React from "react";
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
import { toast } from "sonner";

interface User {
    id: number;
    name: string;
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

// Helper function to format time range based on user preference
const formatTimeRange = (start: string, end: string, format: string): string => {
    if (format === '12') {
        const formatTime12 = (time: string) => {
            const [hour, minute] = time.split(':');
            const h = parseInt(hour);
            const period = h >= 12 ? 'PM' : 'AM';
            const hour12 = h === 0 ? 12 : h > 12 ? h - 12 : h;
            return `${hour12}:${minute} ${period}`;
        };
        return `${formatTime12(start)} - ${formatTime12(end)}`;
    }
    return `${start} - ${end}`;
};

export default function EmployeeScheduleEdit() {
    const { schedule, users, campaigns, sites, auth } = usePage<PageProps>().props;
    const timeFormat = auth.user.time_format || '24';

    const { title, breadcrumbs } = usePageMeta({
        title: "Edit Employee Schedule",
        breadcrumbs: [
            { title: "Employee Schedules", href: "/employee-schedules" },
            { title: "Edit", href: "" },
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
        put(`/employee-schedules/${schedule.id}`, {
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

                <Card className="w-full">
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
                                    <Label htmlFor="scheduled_time_in">
                                        Time In <span className="text-red-500">*</span>
                                    </Label>
                                    <Input
                                        type="time"
                                        value={data.scheduled_time_in}
                                        onChange={e => setData("scheduled_time_in", e.target.value)}
                                    />
                                    {errors.scheduled_time_in && (
                                        <p className="text-sm text-red-500">{errors.scheduled_time_in}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="scheduled_time_out">
                                        Time Out <span className="text-red-500">*</span>
                                    </Label>
                                    <Input
                                        type="time"
                                        value={data.scheduled_time_out}
                                        onChange={e => setData("scheduled_time_out", e.target.value)}
                                    />
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
                                    onClick={() => router.get("/employee-schedules")}
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
