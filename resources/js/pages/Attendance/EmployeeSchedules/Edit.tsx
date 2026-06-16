import { Head, router, useForm, usePage } from "@inertiajs/react";
import AppLayout from "@/layouts/app-layout";
import { useFlashMessage, usePageLoading, usePageMeta } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { LoadingOverlay } from "@/components/LoadingOverlay";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { DatePicker } from "@/components/ui/date-picker";
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
import { Switch } from "@/components/ui/switch";

import { toast } from "sonner";
import { deriveShiftType, SHIFT_META } from "@/lib/shift-type";
import {
    index as employeeSchedulesIndex,
    edit as employeeSchedulesEdit,
    update as employeeSchedulesUpdate,
} from "@/routes/employee-schedules";

interface User {
    id: number;
    name: string;
    email?: string;
    role?: string;
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
    canEditEffectiveDate: boolean;
    userCampaignIds: number[];
    auth: {
        user: object;
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

export default function EmployeeScheduleEdit() {
    const { schedule, users, campaigns, sites, canEditEffectiveDate, userCampaignIds } = usePage<PageProps>().props;

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
        campaign_ids: userCampaignIds || [],
        site_id: schedule.site_id || null,
        is_utility: schedule.shift_type === 'utility_24h',
        scheduled_time_in: schedule.scheduled_time_in,
        scheduled_time_out: schedule.scheduled_time_out,
        work_days: schedule.work_days,
        grace_period_minutes: schedule.grace_period_minutes,
        is_active: schedule.is_active,
        effective_date: schedule.effective_date || "",
        end_date: schedule.end_date || "",
    });

    // Derived shift type from current Time In + utility toggle.
    const derivedShiftType = deriveShiftType(data.scheduled_time_in, data.is_utility);
    const derivedShiftMeta = SHIFT_META[derivedShiftType];

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

    // Check if the schedule owner is a Team Lead (for multi-campaign assignment)
    const isScheduleOwnerTeamLead = selectedUser?.role === 'Team Lead';

    // Toggle campaign in the campaign_ids array
    const toggleCampaignId = (campaignId: number) => {
        if (data.campaign_ids.includes(campaignId)) {
            setData("campaign_ids", data.campaign_ids.filter(id => id !== campaignId));
        } else {
            setData("campaign_ids", [...data.campaign_ids, campaignId]);
        }
    };

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
                            <div>
                                <CardTitle>Schedule Details</CardTitle>
                                <CardDescription>
                                    Configure the employee's shift times, work days, and assignments
                                </CardDescription>
                            </div>
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
                                <div className={isScheduleOwnerTeamLead ? "grid grid-cols-1 gap-4" : "grid grid-cols-1 md:grid-cols-2 gap-4"}>
                                    {/* Single Campaign dropdown is hidden for Team Leads.
                                        TLs use the "Managed Campaigns" multi-select below; the schedule's
                                        campaign_id is derived from the first managed campaign on the server. */}
                                    {!isScheduleOwnerTeamLead && (
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
                                    )}

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

                                {/* Managed Campaigns (Team Lead multi-select) */}
                                {isScheduleOwnerTeamLead && (
                                    <div className="space-y-2">
                                        <Label>
                                            Managed Campaigns <span className="text-red-500">*</span>
                                        </Label>
                                        <p className="text-xs text-muted-foreground">
                                            Select all campaigns this Team Lead will manage. This determines which agents they can coach, view, and approve leave requests for. The first selected campaign is used as the schedule&apos;s primary campaign.
                                        </p>
                                        <div className="grid grid-cols-1 md:grid-cols-2 gap-3 rounded-md border p-3">
                                            {campaigns.map(campaign => (
                                                <div key={campaign.id} className="flex items-center space-x-2">
                                                    <Checkbox
                                                        id={`campaign-${campaign.id}`}
                                                        checked={data.campaign_ids.includes(campaign.id)}
                                                        onCheckedChange={() => toggleCampaignId(campaign.id)}
                                                    />
                                                    <Label
                                                        htmlFor={`campaign-${campaign.id}`}
                                                        className="text-sm font-normal cursor-pointer"
                                                    >
                                                        {campaign.name}
                                                    </Label>
                                                </div>
                                            ))}
                                        </div>
                                        {errors.campaign_ids && (
                                            <p className="text-sm text-red-500">{errors.campaign_ids}</p>
                                        )}
                                    </div>
                                )}

                                {/* 24-Hour Utility Toggle */}
                                <div className="flex items-center justify-between rounded-md border p-3">
                                    <div className="space-y-0.5">
                                        <Label htmlFor="is_utility" className="text-sm font-medium cursor-pointer">
                                            24-Hour Utility Schedule
                                        </Label>
                                        <p className="text-xs text-muted-foreground">
                                            Enable for roles without a fixed shift window (e.g. utility staff). Hours are tracked by total time worked.
                                        </p>
                                    </div>
                                    <Switch
                                        id="is_utility"
                                        checked={data.is_utility}
                                        onCheckedChange={(checked) => setData("is_utility", checked)}
                                    />
                                </div>

                                {/* Shift Times */}
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div className="space-y-2">
                                        <Label>
                                            Time In <span className="text-red-500">*</span>
                                        </Label>
                                        <Input
                                            type="time"
                                            value={data.scheduled_time_in}
                                            onChange={(e) => setData("scheduled_time_in", e.target.value)}
                                        />
                                        {errors.scheduled_time_in && (
                                            <p className="text-sm text-red-500">{errors.scheduled_time_in}</p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label>
                                            Time Out <span className="text-red-500">*</span>
                                        </Label>
                                        <Input
                                            type="time"
                                            value={data.scheduled_time_out}
                                            onChange={(e) => setData("scheduled_time_out", e.target.value)}
                                        />
                                        {errors.scheduled_time_out && (
                                            <p className="text-sm text-red-500">{errors.scheduled_time_out}</p>
                                        )}
                                    </div>
                                </div>

                                {/* Derived Shift Type (read-only badge) */}
                                <div className="rounded-md border border-dashed bg-muted/40 p-3 flex items-center gap-3">
                                    <span className="text-2xl leading-none" aria-hidden="true">{derivedShiftMeta.icon}</span>
                                    <div className="flex-1">
                                        <p className="text-sm font-medium">
                                            Detected shift: {derivedShiftMeta.label}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {derivedShiftMeta.description}
                                        </p>
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

                                {/* Effective Date */}
                                <div className="space-y-2">
                                    <Label htmlFor="effective_date">Hired Date</Label>
                                    <DatePicker
                                        value={canEditEffectiveDate ? data.effective_date : schedule.effective_date}
                                        onChange={(value) => setData("effective_date", value)}
                                        placeholder="Select date"
                                        disabled={!canEditEffectiveDate}
                                        className={!canEditEffectiveDate ? "bg-muted" : ""}
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        {canEditEffectiveDate
                                            ? "The employee's hire date (can be updated by admins)"
                                            : "Hired date cannot be changed after creation"}
                                    </p>
                                    {errors.effective_date && (
                                        <p className="text-sm text-red-500">{errors.effective_date}</p>
                                    )}
                                </div>

                                {/* End Date */}
                                <div className="space-y-2">
                                    <Label htmlFor="end_date">End Date (Optional)</Label>
                                    <DatePicker
                                        value={data.end_date}
                                        onChange={(value) => setData("end_date", value)}
                                        placeholder="Select date"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Leave blank for indefinite schedule
                                    </p>
                                    {errors.end_date && (
                                        <p className="text-sm text-red-500">{errors.end_date}</p>
                                    )}
                                </div>

                                {/* Active Status */}
                                <div className="space-y-2">
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
                                    {data.is_active && !schedule.is_active && (
                                        <p className="text-xs text-amber-600">
                                            ⚠️ Activating this schedule will deactivate any other active schedule for this employee.
                                        </p>
                                    )}
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
