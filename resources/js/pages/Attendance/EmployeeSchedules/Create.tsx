import React, { useState, useEffect } from "react";
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
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from "@/components/ui/command";
import { Switch } from "@/components/ui/switch";
import { Check, ChevronsUpDown, AlertTriangle } from "lucide-react";
import { toast } from "sonner";
import {
    index as employeeSchedulesIndex,
    create as employeeSchedulesCreate,
    store as employeeSchedulesStore,
} from "@/routes/employee-schedules";
import { store as scheduleSetupStore } from "@/routes/schedule-setup";
import { deriveShiftType, SHIFT_META } from "@/lib/shift-type";
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";

interface User {
    id: number;
    name: string;
    email?: string;
    role?: string;
    hired_date?: string | null;
    has_schedule: boolean;
}

interface CurrentUser {
    id: number;
    name: string;
    email: string;
    role: string;
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
    currentUser: CurrentUser;
    isRestrictedRole: boolean;
    isFirstTimeSetup: boolean;
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

export default function EmployeeScheduleCreate() {
    const { users, campaigns, sites, currentUser, isRestrictedRole, isFirstTimeSetup } = usePage<PageProps>().props;

    // State for confirmation dialog
    const [showConfirmDialog, setShowConfirmDialog] = useState(false);

    const { title, breadcrumbs } = usePageMeta({
        title: isFirstTimeSetup ? "Schedule Setup" : "Create Employee Schedule",
        breadcrumbs: isFirstTimeSetup
            ? [{ title: "Schedule Setup", href: employeeSchedulesCreate().url }]
            : [
                { title: "Employee Schedules", href: employeeSchedulesIndex().url },
                { title: "Create", href: employeeSchedulesCreate().url },
            ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    // Employee search popover state
    const [isEmployeePopoverOpen, setIsEmployeePopoverOpen] = useState(false);
    const [employeeSearchQuery, setEmployeeSearchQuery] = useState("");

    const { data, setData, post, processing, errors } = useForm({
        user_id: isRestrictedRole ? String(currentUser.id) : "",
        campaign_id: null as number | null,
        campaign_ids: [] as number[],
        site_id: null as number | null,
        is_utility: false,
        scheduled_time_in: "22:00",
        scheduled_time_out: "07:00",
        work_days: ["monday", "tuesday", "wednesday", "thursday", "friday"],
        grace_period_minutes: 0,
        effective_date: new Date().toISOString().split("T")[0],
        end_date: "",
    });

    // Auto-select current user for restricted roles or from URL parameter
    useEffect(() => {
        if (isRestrictedRole && currentUser) {
            setData("user_id", String(currentUser.id));
        } else {
            // Check if user_id is passed in URL query parameter
            const urlParams = new URLSearchParams(window.location.search);
            const userIdParam = urlParams.get('user_id');
            if (userIdParam && !data.user_id) {
                setData("user_id", userIdParam);
            }
            // Pre-fill effective_date from query param (e.g. after re-hire)
            const effectiveDateParam = urlParams.get('effective_date');
            if (effectiveDateParam) {
                setData("effective_date", effectiveDateParam);
            }
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isRestrictedRole, currentUser]);

    // Auto-fill effective_date from URL param (re-hire flow) handled above.

    // Derived shift type from current Time In + utility toggle.
    const derivedShiftType = deriveShiftType(data.scheduled_time_in, data.is_utility);
    const derivedShiftMeta = SHIFT_META[derivedShiftType];

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        // For first-time setup, show confirmation dialog
        if (isFirstTimeSetup) {
            setShowConfirmDialog(true);
        } else {
            submitForm();
        }
    };

    const submitForm = () => {
        // Use different route for first-time setup vs regular create
        const submitUrl = isFirstTimeSetup
            ? scheduleSetupStore().url
            : employeeSchedulesStore().url;

        // Close the confirmation dialog first
        setShowConfirmDialog(false);

        post(submitUrl, {
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

    // Filter users based on search query
    const filteredUsers = users.filter((user) => {
        const query = employeeSearchQuery.toLowerCase();
        return user.name.toLowerCase().includes(query) ||
            (user.email && user.email.toLowerCase().includes(query));
    });

    // Get selected user for display
    const selectedUser = data.user_id ? users.find(user => user.id === Number(data.user_id)) : undefined;

    // Check if the selected user is a Team Lead (for multi-campaign assignment)
    const isSelectedUserTeamLead = isRestrictedRole
        ? currentUser.role === 'Team Lead'
        : selectedUser?.role === 'Team Lead';

    // Toggle campaign in the campaign_ids array
    const toggleCampaignId = (campaignId: number) => {
        if (data.campaign_ids.includes(campaignId)) {
            setData("campaign_ids", data.campaign_ids.filter(id => id !== campaignId));
        } else {
            setData("campaign_ids", [...data.campaign_ids, campaignId]);
        }
    };

    // Get campaign and site names for confirmation dialog
    const selectedCampaign = data.campaign_id ? campaigns.find(c => c.id === data.campaign_id) : null;
    const selectedSite = data.site_id ? sites.find(s => s.id === data.site_id) : null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-6 rounded-xl p-3 relative">
                <LoadingOverlay isLoading={isPageLoading} />

                <PageHeader
                    title={isFirstTimeSetup ? "Welcome! Let's Set Up Your Schedule" : "Create Employee Schedule"}
                    description={isFirstTimeSetup
                        ? "Please fill out your schedule information to complete your account setup"
                        : "Set up a new employee work schedule"
                    }
                />
                <div className="max-w-2xl mx-auto w-full">
                    {isFirstTimeSetup && (
                        <Alert className="mb-6 border-amber-500 bg-amber-50 dark:bg-amber-950/20">
                            <AlertTriangle className="h-4 w-4 text-amber-600" />
                            <AlertTitle className="text-amber-800 dark:text-amber-200">Important Notice</AlertTitle>
                            <AlertDescription className="text-amber-700 dark:text-amber-300">
                                Please ensure all information is accurate before submitting. Once submitted, you will not be able to modify this information yourself. Contact an administrator if changes are needed later.
                            </AlertDescription>
                        </Alert>
                    )}
                    <Card>
                        <CardHeader>
                            <div>
                                <CardTitle>Schedule Details</CardTitle>
                                <CardDescription>
                                    {isFirstTimeSetup
                                        ? "Complete the form below with your work schedule information"
                                        : "Configure the employee's shift times, work days, and assignments"
                                    }
                                </CardDescription>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-6">
                                {/* Employee Selection - Hidden for restricted roles */}
                                {!isRestrictedRole ? (
                                    <div className="space-y-2">
                                        <Label htmlFor="user_id">
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
                                                    {selectedUser ? (
                                                        <span className="truncate">
                                                            {selectedUser.name}{selectedUser.email && ` (${selectedUser.email})`}
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
                                                        value={employeeSearchQuery}
                                                        onValueChange={setEmployeeSearchQuery}
                                                    />
                                                    <CommandList>
                                                        <CommandEmpty>
                                                            {users.length === 0
                                                                ? "All employees already have schedules."
                                                                : "No employee found."}
                                                        </CommandEmpty>
                                                        <CommandGroup>
                                                            {filteredUsers.map((user) => (
                                                                <CommandItem
                                                                    key={user.id}
                                                                    value={`${user.name} ${user.email || ''}`}
                                                                    onSelect={() => {
                                                                        setData("user_id", String(user.id));
                                                                        // Auto-fill hired_date if user already has one
                                                                        if (user.hired_date) {
                                                                            setData("effective_date", user.hired_date);
                                                                        }
                                                                        setIsEmployeePopoverOpen(false);
                                                                        setEmployeeSearchQuery("");
                                                                    }}
                                                                    className="cursor-pointer"
                                                                >
                                                                    <Check
                                                                        className={`mr-2 h-4 w-4 ${Number(data.user_id) === user.id
                                                                            ? 'opacity-100'
                                                                            : 'opacity-0'
                                                                            }`}
                                                                    />
                                                                    <div className="flex flex-col">
                                                                        <div className="flex items-center gap-2">
                                                                            <span className="font-medium">{user.name}</span>
                                                                            {user.has_schedule && (
                                                                                <span className="text-xs bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200 px-1.5 py-0.5 rounded">
                                                                                    Has Schedule
                                                                                </span>
                                                                            )}
                                                                        </div>
                                                                        {user.email && (
                                                                            <span className="text-xs text-muted-foreground">
                                                                                {user.email}
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
                                        <p className="text-xs text-muted-foreground">
                                            Search and select an employee to create a schedule for
                                        </p>
                                        {errors.user_id && (
                                            <p className="text-sm text-red-500">{errors.user_id}</p>
                                        )}
                                    </div>
                                ) : (
                                    <div className="space-y-2">
                                        <Label>Employee</Label>
                                        <Input
                                            value={`${currentUser.name} (${currentUser.email})`}
                                            disabled
                                            className="bg-muted"
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            Your account is automatically selected
                                        </p>
                                    </div>
                                )}

                                {/* Campaign & Site */}
                                <div className={isSelectedUserTeamLead ? "grid grid-cols-1 gap-4" : "grid grid-cols-1 md:grid-cols-2 gap-4"}>
                                    {/* Single Campaign dropdown is hidden for Team Leads.
                                        TLs use the "Managed Campaigns" multi-select below; the schedule's
                                        campaign_id is derived from the first managed campaign on the server. */}
                                    {!isSelectedUserTeamLead && (
                                        <div className="space-y-2">
                                            <Label htmlFor="campaign_id">
                                                Campaign {isRestrictedRole && <span className="text-red-500">*</span>}
                                            </Label>
                                            <Select
                                                value={data.campaign_id ? String(data.campaign_id) : undefined}
                                                onValueChange={value => setData("campaign_id", value ? parseInt(value) : null)}
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder={isRestrictedRole ? "Select campaign" : "Select campaign (optional)"} />
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
                                        <Label htmlFor="site_id">
                                            Site {isRestrictedRole && <span className="text-red-500">*</span>}
                                        </Label>
                                        <Select
                                            value={data.site_id ? String(data.site_id) : undefined}
                                            onValueChange={value => setData("site_id", value ? parseInt(value) : null)}
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder={isRestrictedRole ? "Select site" : "Select site (optional)"} />
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
                                {isSelectedUserTeamLead && (
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

                                {/* 24-Hour Utility Toggle (admin only) */}
                                {!isRestrictedRole && (
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
                                )}

                                {/* Shift Times */}
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div className="space-y-2">
                                        <Label>
                                            Time In <span className="text-red-500">*</span>
                                        </Label>
                                        {isRestrictedRole && (
                                            <p className="text-xs text-muted-foreground mb-1">
                                                If you're unsure about your shift time, please ask your admin or team lead.
                                            </p>
                                        )}
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
                                        <Label>
                                            Time Out <span className="text-red-500">*</span>
                                        </Label>
                                        {isRestrictedRole && (
                                            <p className="text-xs text-muted-foreground mb-1">
                                                If you're unsure about your shift time, please ask your admin or team lead.
                                            </p>
                                        )}
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

                                {/* Grace Period - Hidden for restricted roles */}
                                {!isRestrictedRole && (
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
                                            Minutes late before considered tardy (default 0 minutes)
                                        </p>
                                        {errors.grace_period_minutes && (
                                            <p className="text-sm text-red-500">{errors.grace_period_minutes}</p>
                                        )}
                                    </div>
                                )}

                                {/* Effective Dates */}
                                <div className={`grid grid-cols-1 ${!isRestrictedRole ? 'md:grid-cols-2' : ''} gap-4`}>
                                    <div className="space-y-2">
                                        <Label htmlFor="effective_date">
                                            Hired Date <span className="text-red-500">*</span>
                                        </Label>
                                        <DatePicker
                                            value={data.effective_date}
                                            onChange={(value) => setData("effective_date", value)}
                                            placeholder="Select date"
                                            disabled={!isRestrictedRole && selectedUser?.has_schedule}
                                            className={!isRestrictedRole && selectedUser?.has_schedule ? "bg-muted" : ""}
                                        />
                                        {isRestrictedRole && (
                                            <p className="text-xs text-amber-600 dark:text-amber-400 font-medium">
                                                ⚠️ Please make sure this is your actual hired date. If you don't know, please ask your admin or HR.
                                            </p>
                                        )}
                                        {!isRestrictedRole && selectedUser?.has_schedule && (
                                            <p className="text-xs text-muted-foreground">
                                                Hired date is locked because this employee already has a schedule record.
                                            </p>
                                        )}
                                        {errors.effective_date && (
                                            <p className="text-sm text-red-500">{errors.effective_date}</p>
                                        )}
                                    </div>

                                    {/* End Date - Hidden for restricted roles */}
                                    {!isRestrictedRole && (
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
                                    )}
                                </div>

                                {/* Form Actions */}
                                <div className="flex gap-3 pt-4">
                                    <Button type="submit" disabled={processing}>
                                        {processing
                                            ? (isFirstTimeSetup ? "Submitting..." : "Creating...")
                                            : (isFirstTimeSetup ? "Submit" : "Create Schedule")
                                        }
                                    </Button>
                                    {!isFirstTimeSetup && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => router.get(employeeSchedulesIndex().url)}
                                        >
                                            Cancel
                                        </Button>
                                    )}
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                </div>
            </div>

            {/* Confirmation Dialog for First Time Setup */}
            <AlertDialog open={showConfirmDialog} onOpenChange={setShowConfirmDialog}>
                <AlertDialogContent className="max-w-md">
                    <AlertDialogHeader>
                        <AlertDialogTitle>Confirm Your Schedule Information</AlertDialogTitle>
                        <AlertDialogDescription className="space-y-3">
                            <p>Please review your information carefully. Once submitted, you will not be able to modify this yourself.</p>
                            <div className="bg-muted p-4 rounded-lg space-y-2 text-sm">
                                <div><strong>Campaign:</strong> {selectedCampaign?.name || 'Not selected'}</div>
                                <div><strong>Site:</strong> {selectedSite?.name || 'Not selected'}</div>
                                <div><strong>Shift:</strong> {derivedShiftMeta.icon} {derivedShiftMeta.label}</div>
                                <div><strong>Time:</strong> {data.scheduled_time_in} - {data.scheduled_time_out}</div>
                                <div><strong>Work Days:</strong> {data.work_days.map(d => d.charAt(0).toUpperCase() + d.slice(1)).join(', ')}</div>
                                <div><strong>Hired Date:</strong> {data.effective_date}</div>
                            </div>
                            <p className="text-amber-600 dark:text-amber-400 font-medium">
                                By clicking "Confirm & Submit", you confirm that all information above is true and correct.
                            </p>
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Go Back & Edit</AlertDialogCancel>
                        <AlertDialogAction onClick={submitForm} disabled={processing}>
                            {processing ? "Submitting..." : "Confirm & Submit"}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}
