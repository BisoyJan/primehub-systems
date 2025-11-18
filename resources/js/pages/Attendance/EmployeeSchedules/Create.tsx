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

interface PageProps {
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

export default function EmployeeScheduleCreate() {
    const { users, campaigns, sites, auth } = usePage<PageProps>().props;
    const timeFormat = auth.user.time_format || '24';

    const { title, breadcrumbs } = usePageMeta({
        title: "Create Employee Schedule",
        breadcrumbs: [
            { title: "Employee Schedules", href: "/employee-schedules" },
            { title: "Create", href: "" },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    const { data, setData, post, processing, errors } = useForm({
        user_id: "",
        campaign_id: null as number | null,
        site_id: null as number | null,
        shift_type: "night_shift",
        scheduled_time_in: "22:00",
        scheduled_time_out: "07:00",
        work_days: ["monday", "tuesday", "wednesday", "thursday", "friday"],
        grace_period_minutes: 15,
        effective_date: new Date().toISOString().split("T")[0],
        end_date: "",
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post("/employee-schedules", {
            onError: (errors) => {
                const firstError = Object.values(errors)[0] as string;
                toast.error(firstError || "Failed to create employee schedule");
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

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-6 rounded-xl p-3 relative">
                <LoadingOverlay isLoading={isPageLoading} />

                <PageHeader
                    title="Create Employee Schedule"
                    description="Set up a new employee work schedule"
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
                            {/* Employee Selection */}
                            <div className="space-y-2">
                                <Label htmlFor="user_id">
                                    Employee <span className="text-red-500">*</span>
                                </Label>
                                <Select
                                    value={data.user_id}
                                    onValueChange={value => setData("user_id", value)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select employee" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {users.map(user => (
                                            <SelectItem key={user.id} value={String(user.id)}>
                                                {user.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.user_id && (
                                    <p className="text-sm text-red-500">{errors.user_id}</p>
                                )}
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
                                    onChange={e => setData("grace_period_minutes", parseInt(e.target.value))}
                                />
                                <p className="text-xs text-muted-foreground">
                                    Minutes late before considered tardy (typically 15 minutes)
                                </p>
                                {errors.grace_period_minutes && (
                                    <p className="text-sm text-red-500">{errors.grace_period_minutes}</p>
                                )}
                            </div>

                            {/* Effective Dates */}
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <Label htmlFor="effective_date">
                                        Effective Date <span className="text-red-500">*</span>
                                    </Label>
                                    <Input
                                        type="date"
                                        value={data.effective_date}
                                        onChange={e => setData("effective_date", e.target.value)}
                                    />
                                    {errors.effective_date && (
                                        <p className="text-sm text-red-500">{errors.effective_date}</p>
                                    )}
                                </div>

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
                            </div>

                            {/* Form Actions */}
                            <div className="flex gap-3 pt-4">
                                <Button type="submit" disabled={processing}>
                                    {processing ? "Creating..." : "Create Schedule"}
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
