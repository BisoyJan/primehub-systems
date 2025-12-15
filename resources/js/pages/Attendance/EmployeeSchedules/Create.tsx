import React, { useState, useEffect } from "react";
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
import { Check, ChevronsUpDown, AlertTriangle, HelpCircle } from "lucide-react";
import { toast } from "sonner";
import {
    index as employeeSchedulesIndex,
    create as employeeSchedulesCreate,
    store as employeeSchedulesStore,
} from "@/routes/employee-schedules";
import { store as scheduleSetupStore } from "@/routes/schedule-setup";
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from "@/components/ui/dialog";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";

interface User {
    id: number;
    name: string;
    email?: string;
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

// Generate minute options (00, 15, 30, 45 for convenience, or all 60)
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

// Define shift type default times (for auto-fill when shift type changes)
const SHIFT_DEFAULTS: Record<string, { timeIn: string; timeOut: string }> = {
    morning_shift: { timeIn: '05:00', timeOut: '14:00' },
    afternoon_shift: { timeIn: '14:00', timeOut: '23:00' },
    night_shift: { timeIn: '22:00', timeOut: '07:00' },
    graveyard_shift: { timeIn: '00:00', timeOut: '09:00' },
};

// Define flexible time ranges for each shift type (more lenient validation)
const SHIFT_TIME_RANGES: Record<string, {
    timeInMin: number; timeInMax: number;
    timeOutMin: number; timeOutMax: number;
    label: string; hint: string;
}> = {
    // Morning: starts 04:00-09:00, ends 12:00-17:00
    morning_shift: { timeInMin: 4, timeInMax: 9, timeOutMin: 12, timeOutMax: 17, label: 'Morning Shift', hint: 'Time In: 04:00-09:00 (4AM-9AM), Time Out: 12:00-17:00 (12PM-5PM)' },
    // Afternoon: starts 11:00-16:00, ends 19:00-24:00
    afternoon_shift: { timeInMin: 11, timeInMax: 16, timeOutMin: 19, timeOutMax: 24, label: 'Afternoon Shift', hint: 'Time In: 11:00-16:00 (11AM-4PM), Time Out: 19:00-00:00 (7PM-12AM)' },
    // Night: starts 18:00-23:00, ends 04:00-10:00 (next day)
    night_shift: { timeInMin: 18, timeInMax: 23, timeOutMin: 4, timeOutMax: 10, label: 'Night Shift', hint: 'Time In: 18:00-23:00 (6PM-11PM), Time Out: 04:00-10:00 (4AM-10AM next day)' },
    // Graveyard: starts 22:00-02:00, ends 05:00-11:00
    graveyard_shift: { timeInMin: 22, timeInMax: 26, timeOutMin: 5, timeOutMax: 11, label: 'Graveyard Shift', hint: 'Time In: 22:00-02:00 (10PM-2AM), Time Out: 05:00-11:00 (5AM-11AM)' },
};

// Helper to get hour from time string
const getHour = (time: string): number => {
    const [h] = time.split(':');
    return parseInt(h);
};

// Check if hour is within range (handles overnight ranges)
const isHourInRange = (hour: number, min: number, max: number): boolean => {
    if (max > 24) {
        // Handle ranges that cross midnight (e.g., 22-26 means 22:00-02:00)
        return hour >= min || hour <= (max - 24);
    }
    return hour >= min && hour <= max;
};

// Get warning message if time is outside the expected range for shift type
const getShiftTimeWarning = (shiftType: string, timeIn: string, timeOut: string): string | null => {
    const range = SHIFT_TIME_RANGES[shiftType];
    if (!range) return null; // utility_24h has no range restriction

    const timeInHour = getHour(timeIn);
    const timeOutHour = getHour(timeOut);

    const timeInValid = isHourInRange(timeInHour, range.timeInMin, range.timeInMax);
    const timeOutValid = isHourInRange(timeOutHour, range.timeOutMin, range.timeOutMax);

    if (!timeInValid || !timeOutValid) {
        return `Time may not align with ${range.label}. Recommended: ${range.hint}`;
    }
    return null;
};

export default function EmployeeScheduleCreate() {
    const { users, campaigns, sites, auth, currentUser, isRestrictedRole, isFirstTimeSetup } = usePage<PageProps>().props;
    const timeFormat = (auth?.user?.time_format as '12' | '24') || '24';

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
        site_id: null as number | null,
        shift_type: "night_shift",
        scheduled_time_in: "22:00",
        scheduled_time_out: "07:00",
        work_days: ["monday", "tuesday", "wednesday", "thursday", "friday"],
        grace_period_minutes: 15,
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
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isRestrictedRole, currentUser]);

    // Auto-fill times when shift type changes
    const handleShiftTypeChange = (shiftType: string) => {
        setData("shift_type", shiftType);
        const defaults = SHIFT_DEFAULTS[shiftType];
        if (defaults) {
            setData(prev => ({
                ...prev,
                shift_type: shiftType,
                scheduled_time_in: defaults.timeIn,
                scheduled_time_out: defaults.timeOut,
            }));
        }
    };

    // Calculate shift time warning in real-time
    const shiftTimeWarning = getShiftTimeWarning(data.shift_type, data.scheduled_time_in, data.scheduled_time_out);

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
                    {/* Shift Time Warning Alert */}
                    {shiftTimeWarning && (
                        <Alert className="mb-6 border-amber-500 bg-amber-50 dark:bg-amber-950/20">
                            <AlertTriangle className="h-4 w-4 text-amber-600" />
                            <AlertTitle className="text-amber-800 dark:text-amber-200">Shift Time Notice</AlertTitle>
                            <AlertDescription className="text-amber-700 dark:text-amber-300">
                                {shiftTimeWarning}
                            </AlertDescription>
                        </Alert>
                    )}
                    <Card>
                        <CardHeader>
                            <div className="flex items-start justify-between">
                                <div>
                                    <CardTitle>Schedule Details</CardTitle>
                                    <CardDescription>
                                        {isFirstTimeSetup
                                            ? "Complete the form below with your work schedule information"
                                            : "Configure the employee's shift times, work days, and assignments"
                                        }
                                    </CardDescription>
                                </div>
                                <Dialog>
                                    <DialogTrigger asChild>
                                        <Button variant="ghost" size="icon" className="h-8 w-8">
                                            <HelpCircle className="h-5 w-5 text-muted-foreground" />
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent className="sm:max-w-md">
                                        <DialogHeader>
                                            <DialogTitle>Shift Time Guide</DialogTitle>
                                            <DialogDescription>
                                                Recommended time ranges for each shift type
                                            </DialogDescription>
                                        </DialogHeader>
                                        <div className="space-y-4 py-4">
                                            <div className="rounded-lg border p-3 space-y-1">
                                                <h4 className="font-medium text-sm">🌅 Morning Shift</h4>
                                                <p className="text-sm text-muted-foreground">Time In: 04:00 - 09:00 (4AM - 9AM)</p>
                                                <p className="text-sm text-muted-foreground">Time Out: 12:00 - 17:00 (12PM - 5PM)</p>
                                            </div>
                                            <div className="rounded-lg border p-3 space-y-1">
                                                <h4 className="font-medium text-sm">🌤️ Afternoon Shift</h4>
                                                <p className="text-sm text-muted-foreground">Time In: 11:00 - 16:00 (11AM - 4PM)</p>
                                                <p className="text-sm text-muted-foreground">Time Out: 19:00 - 00:00 (7PM - 12AM)</p>
                                            </div>
                                            <div className="rounded-lg border p-3 space-y-1">
                                                <h4 className="font-medium text-sm">🌙 Night Shift</h4>
                                                <p className="text-sm text-muted-foreground">Time In: 18:00 - 23:00 (6PM - 11PM)</p>
                                                <p className="text-sm text-muted-foreground">Time Out: 04:00 - 10:00 (4AM - 10AM next day)</p>
                                            </div>
                                            <div className="rounded-lg border p-3 space-y-1">
                                                <h4 className="font-medium text-sm">🌃 Graveyard Shift</h4>
                                                <p className="text-sm text-muted-foreground">Time In: 22:00 - 02:00 (10PM - 2AM)</p>
                                                <p className="text-sm text-muted-foreground">Time Out: 05:00 - 11:00 (5AM - 11AM)</p>
                                            </div>
                                            <div className="rounded-lg border p-3 space-y-1">
                                                <h4 className="font-medium text-sm">🔄 24H Utility</h4>
                                                <p className="text-sm text-muted-foreground">No time restrictions</p>
                                            </div>
                                        </div>
                                    </DialogContent>
                                </Dialog>
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
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
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

                                {/* Shift Type */}
                                <div className="space-y-2">
                                    <Label htmlFor="shift_type">
                                        Shift Type <span className="text-red-500">*</span>
                                    </Label>
                                    <Select
                                        value={data.shift_type}
                                        onValueChange={handleShiftTypeChange}
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="morning_shift">
                                                🌅 Morning Shift (04:00-17:00) 4:00 AM - 5:00 PM
                                            </SelectItem>
                                            <SelectItem value="afternoon_shift">
                                                🌤️ Afternoon Shift (11:00-00:00) 11:00 AM - 12:00 AM
                                            </SelectItem>
                                            <SelectItem value="night_shift">
                                                🌙 Night Shift (18:00-10:00) 6:00 PM - 10:00 AM
                                            </SelectItem>
                                            <SelectItem value="graveyard_shift">
                                                🌃 Graveyard Shift (22:00-11:00) 10:00 PM - 11:00 AM
                                            </SelectItem>
                                            <SelectItem value="utility_24h">🔄 24H Utility</SelectItem>
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
                                        {isRestrictedRole && (
                                            <p className="text-xs text-muted-foreground mb-1">
                                                If you're unsure about your shift time, please ask your admin or team lead.
                                            </p>
                                        )}
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
                                        {isRestrictedRole && (
                                            <p className="text-xs text-muted-foreground mb-1">
                                                If you're unsure about your shift time, please ask your admin or team lead.
                                            </p>
                                        )}
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
                                            Minutes late before considered tardy (typically 15 minutes)
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
                                        <Input
                                            type="date"
                                            value={data.effective_date}
                                            onChange={e => setData("effective_date", e.target.value)}
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
                                <div><strong>Shift:</strong> {data.shift_type.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())}</div>
                                <div><strong>Time:</strong> {formatTime(data.scheduled_time_in, timeFormat)} - {formatTime(data.scheduled_time_out, timeFormat)}</div>
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
