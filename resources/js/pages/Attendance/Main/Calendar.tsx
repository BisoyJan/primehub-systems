import React, { useState, useMemo } from "react";
import { Head, router, usePage } from "@inertiajs/react";
import AppLayout from "@/layouts/app-layout";
import { type SharedData } from "@/types";
import { useFlashMessage, usePageMeta, usePermission } from "@/hooks";
import { formatTime, formatWorkDuration } from "@/lib/utils";
import { PageHeader } from "@/components/PageHeader";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from "@/components/ui/command";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { ChevronLeft, ChevronRight, Check, ChevronsUpDown, Calendar as CalendarIcon, ShieldCheck, X, CheckCircle } from "lucide-react";
import {
    hub as attendanceHub,
    calendar as attendanceCalendar,
    review as attendanceReview,
    quickApprove as attendanceQuickApprove,
} from "@/routes/attendance";

interface User {
    id: number;
    name: string;
}

interface Site {
    id: number;
    name: string;
}

interface EmployeeSchedule {
    id: number;
    shift_type: string;
    scheduled_time_in: string;
    scheduled_time_out: string;
    site?: Site;
}

interface AttendanceRecord {
    id: number;
    user: User;
    employee_schedule?: EmployeeSchedule;
    shift_date: string;
    scheduled_time_in?: string;
    scheduled_time_out?: string;
    actual_time_in?: string;
    actual_time_out?: string;
    total_minutes_worked?: number;
    status: string;
    secondary_status?: string;
    tardy_minutes?: number;
    undertime_minutes?: number;
    overtime_minutes?: number;
    overtime_approved?: boolean;
    is_advised: boolean;
    is_cross_site_bio?: boolean;
    bio_in_site?: Site;
    bio_out_site?: Site;
    admin_verified: boolean;
    verification_notes?: string;
    notes?: string;
    warnings?: string[];
}

interface PageProps extends SharedData {
    attendances: Record<string, AttendanceRecord>;
    users: User[];
    selectedUser?: User | null;
    campaigns?: Array<{ id: number; name: string }>;
    teamLeadCampaignId?: number;
    month: number;
    year: number;
    verificationFilter: string;
    campaignFilter?: string;
}

const statusColors: Record<string, string> = {
    on_time: 'bg-green-500 text-white hover:bg-green-600',
    tardy: 'bg-yellow-500 text-white hover:bg-yellow-600',
    half_day_absence: 'bg-orange-500 text-white hover:bg-orange-600',
    advised_absence: 'bg-blue-500 text-white hover:bg-blue-600',
    ncns: 'bg-red-500 text-white hover:bg-red-600',
    undertime: 'bg-orange-400 text-white hover:bg-orange-500',
    failed_bio_in: 'bg-purple-500 text-white hover:bg-purple-600',
    failed_bio_out: 'bg-purple-400 text-white hover:bg-purple-500',
    present_no_bio: 'bg-gray-500 text-white hover:bg-gray-600',
    non_work_day: 'bg-slate-500 text-white hover:bg-slate-600',
    on_leave: 'bg-blue-600 text-white hover:bg-blue-700',
    needs_manual_review: 'bg-amber-500 text-white hover:bg-amber-600',
};

const statusLabels: Record<string, string> = {
    on_time: 'On Time',
    tardy: 'Tardy',
    half_day_absence: 'Half Day',
    advised_absence: 'Advised Absence',
    ncns: 'NCNS',
    undertime: 'Undertime',
    failed_bio_in: 'No Bio In',
    failed_bio_out: 'No Bio Out',
    present_no_bio: 'Present (No Bio)',
    non_work_day: 'Non-Work Day',
    on_leave: 'On Leave',
    needs_manual_review: 'Needs Review',
};

// formatTime is now imported from @/lib/utils

export default function AttendanceCalendar() {
    const { attendances, users, selectedUser, campaigns = [], teamLeadCampaignId, month, year, verificationFilter: initialVerificationFilter, campaignFilter: initialCampaignFilter } = usePage<PageProps>().props;

    useFlashMessage();
    const { can } = usePermission();

    const { title, breadcrumbs } = usePageMeta({
        title: 'Attendance Calendar',
        breadcrumbs: [
            { title: 'Attendance', href: attendanceHub().url },
            { title: 'Calendar' },
        ],
    });

    const [isUserPopoverOpen, setIsUserPopoverOpen] = useState(false);
    const [userSearchQuery, setUserSearchQuery] = useState("");
    const [selectedDate, setSelectedDate] = useState<string | null>(null);
    const [isDetailDialogOpen, setIsDetailDialogOpen] = useState(false);
    const [verificationFilter, setVerificationFilter] = useState(initialVerificationFilter || 'all');
    const [campaignFilter, setCampaignFilter] = useState(() => {
        if (initialCampaignFilter) return initialCampaignFilter;
        if (teamLeadCampaignId) return teamLeadCampaignId.toString();
        return 'all';
    });

    // Filter users based on search query
    const filteredUsers = useMemo(() => {
        if (!userSearchQuery) return users;
        return users.filter(user =>
            user.name.toLowerCase().includes(userSearchQuery.toLowerCase())
        );
    }, [users, userSearchQuery]);

    // Get days in current month
    const daysInMonth = new Date(year, month, 0).getDate();
    const firstDayOfMonth = new Date(year, month - 1, 1).getDay(); // 0 = Sunday

    // Generate calendar days
    const calendarDays: (number | null)[] = [];

    // Add empty cells for days before the first day of the month
    for (let i = 0; i < firstDayOfMonth; i++) {
        calendarDays.push(null);
    }

    // Add the days of the month
    for (let day = 1; day <= daysInMonth; day++) {
        calendarDays.push(day);
    }

    const monthNames = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'
    ];

    const handlePreviousMonth = () => {
        const newMonth = month === 1 ? 12 : month - 1;
        const newYear = month === 1 ? year - 1 : year;
        navigateToMonth(newMonth, newYear);
    };

    const handleNextMonth = () => {
        const newMonth = month === 12 ? 1 : month + 1;
        const newYear = month === 12 ? year + 1 : year;
        navigateToMonth(newMonth, newYear);
    };

    const navigateToMonth = (newMonth: number, newYear: number) => {
        const params: Record<string, string | number> = { month: newMonth, year: newYear };
        if (selectedUser) {
            params.user_id = selectedUser.id;
        }
        if (verificationFilter !== 'all') {
            params.verification_filter = verificationFilter;
        }
        if (campaignFilter !== 'all') {
            params.campaign_id = campaignFilter;
        }
        router.get(attendanceCalendar().url, params, { preserveState: true });
    };

    const handleUserSelect = (userId: number | null) => {
        const params: Record<string, string | number> = { month, year };
        if (userId) {
            params.user_id = userId;
        }
        if (verificationFilter !== 'all') {
            params.verification_filter = verificationFilter;
        }
        if (campaignFilter !== 'all') {
            params.campaign_id = campaignFilter;
        }
        router.get(attendanceCalendar().url, params);
        setIsUserPopoverOpen(false);
        setUserSearchQuery("");
    };

    const handleVerificationFilterChange = (value: string) => {
        setVerificationFilter(value);
        const params: Record<string, string | number> = { month, year };
        if (selectedUser) {
            params.user_id = selectedUser.id;
        }
        if (value !== 'all') {
            params.verification_filter = value;
        }
        if (campaignFilter !== 'all') {
            params.campaign_id = campaignFilter;
        }
        router.get(attendanceCalendar().url, params, { preserveState: true });
    };

    const handleCampaignFilterChange = (value: string) => {
        setCampaignFilter(value);
        const params: Record<string, string | number> = { month, year };
        // Clear selected user when campaign changes since user list will be filtered
        if (verificationFilter !== 'all') {
            params.verification_filter = verificationFilter;
        }
        if (value !== 'all') {
            params.campaign_id = value;
        }
        router.get(attendanceCalendar().url, params, { preserveState: true });
    };

    const handleDayClick = (day: number) => {
        const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        if (attendances[dateStr]) {
            setSelectedDate(dateStr);
            setIsDetailDialogOpen(true);
        }
    };

    const getAttendanceForDay = (day: number): AttendanceRecord | undefined => {
        const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        return attendances[dateStr];
    };

    const selectedAttendance = selectedDate ? attendances[selectedDate] : null;

    const handleQuickApprove = () => {
        if (!selectedAttendance) return;

        router.post(attendanceQuickApprove({ attendance: selectedAttendance.id }).url, {}, {
            preserveScroll: true,
            onSuccess: () => {
                setIsDetailDialogOpen(false);
                setSelectedDate(null);
            },
        });
    };

    const handleVerify = () => {
        if (!selectedAttendance) return;

        // Open review page with verify parameter in new tab
        window.open(attendanceReview({ query: { verify: selectedAttendance.id } }).url, '_blank');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3">
                <PageHeader
                    title={title}
                    description="View employee attendance in calendar format"
                />

                {/* User Selection and Month Navigation */}
                <div className="flex flex-col sm:flex-row gap-3 items-start sm:items-center justify-between">
                    <div className="flex flex-col sm:flex-row gap-3 w-full sm:w-auto">
                        {campaigns.length > 0 && (
                            <div className="w-full sm:w-60">
                                <Select value={campaignFilter} onValueChange={handleCampaignFilterChange}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="All Campaigns" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Campaigns</SelectItem>
                                        {campaigns.map(campaign => (
                                            <SelectItem key={campaign.id} value={String(campaign.id)}>
                                                {campaign.name}{teamLeadCampaignId === campaign.id ? " (Your Campaign)" : ""}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}
                        {users.length > 0 && (
                            <div className="w-full sm:w-80">
                                <Popover open={isUserPopoverOpen} onOpenChange={setIsUserPopoverOpen}>
                                    <PopoverTrigger asChild>
                                        <Button
                                            variant="outline"
                                            role="combobox"
                                            aria-expanded={isUserPopoverOpen}
                                            className="w-full justify-between font-normal"
                                        >
                                            <span className="truncate">
                                                {selectedUser ? selectedUser.name : "Select employee..."}
                                            </span>
                                            <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                        </Button>
                                    </PopoverTrigger>
                                    <PopoverContent className="w-full p-0" align="start">
                                        <Command shouldFilter={false}>
                                            <CommandInput
                                                placeholder="Search employee..."
                                                value={userSearchQuery}
                                                onValueChange={setUserSearchQuery}
                                            />
                                            <CommandList>
                                                <CommandEmpty>No employee found.</CommandEmpty>
                                                <CommandGroup>
                                                    {filteredUsers.map((user) => (
                                                        <CommandItem
                                                            key={user.id}
                                                            value={user.name}
                                                            onSelect={() => handleUserSelect(user.id)}
                                                            className="cursor-pointer"
                                                        >
                                                            <Check
                                                                className={`mr-2 h-4 w-4 ${selectedUser?.id === user.id
                                                                    ? "opacity-100"
                                                                    : "opacity-0"
                                                                    }`}
                                                            />
                                                            {user.name}
                                                        </CommandItem>
                                                    ))}
                                                </CommandGroup>
                                            </CommandList>
                                        </Command>
                                    </PopoverContent>
                                </Popover>
                            </div>
                        )}
                        {selectedUser && (
                            <div className="w-full sm:w-60">
                                <Select value={verificationFilter} onValueChange={handleVerificationFilterChange}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="Filter by status" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Records</SelectItem>
                                        <SelectItem value="verified">Verified Only</SelectItem>
                                        <SelectItem value="non_verified">Non-Verified Only</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        )}
                    </div>

                    <div className="flex items-center gap-3">
                        <Button
                            variant="outline"
                            size="icon"
                            onClick={handlePreviousMonth}
                        >
                            <ChevronLeft className="h-4 w-4" />
                        </Button>
                        <h2 className="text-lg font-semibold min-w-[180px] text-center">
                            {monthNames[month - 1]} {year}
                        </h2>
                        <Button
                            variant="outline"
                            size="icon"
                            onClick={handleNextMonth}
                        >
                            <ChevronRight className="h-4 w-4" />
                        </Button>
                    </div>
                </div>

                {!selectedUser && users.length > 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-12">
                            <CalendarIcon className="h-12 w-12 text-muted-foreground mb-4" />
                            <p className="text-muted-foreground text-center">
                                Please select an employee to view their attendance calendar
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <CardContent className="p-0">
                            {/* Calendar Grid */}
                            <div className="grid grid-cols-7 gap-0">
                                {/* Day headers */}
                                {['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map((day) => (
                                    <div
                                        key={day}
                                        className="border-b border-r last:border-r-0 p-2 text-center text-sm font-semibold bg-muted"
                                    >
                                        {day}
                                    </div>
                                ))}

                                {/* Calendar days */}
                                {calendarDays.map((day, index) => {
                                    const attendance = day ? getAttendanceForDay(day) : undefined;
                                    const isToday = day && new Date().getDate() === day &&
                                        new Date().getMonth() === month - 1 &&
                                        new Date().getFullYear() === year;

                                    return (
                                        <div
                                            key={index}
                                            className={`min-h-[100px] border-b border-r last:border-r-0 p-2 ${day ? 'cursor-pointer hover:bg-muted/50' : 'bg-muted/20'
                                                } ${isToday ? 'bg-blue-50' : ''}`}
                                            onClick={() => day && attendance && handleDayClick(day)}
                                        >
                                            {day && (
                                                <>
                                                    <div className={`text-sm font-medium mb-2 ${isToday ? 'text-blue-600 font-bold' : ''
                                                        }`}>
                                                        {day}
                                                    </div>
                                                    {attendance && (
                                                        <div className="space-y-1.5">
                                                            {/* Status indicators as small squares like legend */}
                                                            <div className="flex items-center justify-center gap-1 flex-wrap">
                                                                {/* Primary Status */}
                                                                <div
                                                                    className={`w-4 h-4 rounded ${statusColors[attendance.status] || 'bg-gray-500'
                                                                        }`}
                                                                    title={statusLabels[attendance.status] || attendance.status}
                                                                />

                                                                {/* Secondary Status */}
                                                                {attendance.secondary_status && (
                                                                    <div
                                                                        className={`w-4 h-4 rounded ${statusColors[attendance.secondary_status] || 'bg-gray-500'
                                                                            }`}
                                                                        title={statusLabels[attendance.secondary_status]}
                                                                    />
                                                                )}

                                                                {/* Overtime */}
                                                                {attendance.overtime_minutes && attendance.overtime_minutes > 30 && (
                                                                    <div
                                                                        className={`w-4 h-4 rounded flex items-center justify-center ${attendance.overtime_approved
                                                                            ? 'bg-green-600'
                                                                            : 'bg-red-500'
                                                                            }`}
                                                                        title={`Overtime: ${attendance.overtime_minutes >= 60
                                                                            ? `${Math.floor(attendance.overtime_minutes / 60)}h ${attendance.overtime_minutes % 60 > 0 ? `${attendance.overtime_minutes % 60}m` : ''}`.trim()
                                                                            : `${attendance.overtime_minutes}m`
                                                                            }${attendance.overtime_approved ? ' (Approved)' : ' (Not Approved)'}`}
                                                                    >
                                                                        {!attendance.overtime_approved && <X className="h-3 w-3 text-white" />}
                                                                    </div>
                                                                )}

                                                                {/* Verified Icon */}
                                                                {attendance.admin_verified && (
                                                                    <div title="Verified">
                                                                        <ShieldCheck className="h-4 w-4 text-green-600" />
                                                                    </div>
                                                                )}
                                                            </div>

                                                            {/* Time Display - In and Out on same row */}
                                                            {(attendance.actual_time_in || attendance.actual_time_out) && (
                                                                <div className="text-[13px] text-muted-foreground text-center leading-tight flex items-center justify-center gap-2">
                                                                    {attendance.actual_time_in && (
                                                                        <span>In: {formatTime(attendance.actual_time_in)}</span>
                                                                    )}
                                                                    {attendance.actual_time_out && (
                                                                        <span>Out: {formatTime(attendance.actual_time_out)}</span>
                                                                    )}
                                                                </div>
                                                            )}
                                                        </div>
                                                    )}
                                                </>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Legend */}
                {selectedUser && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm">Status Legend</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-2">
                                {Object.entries(statusLabels).map(([key, label]) => (
                                    <div key={key} className="flex items-center gap-2">
                                        <div className={`w-4 h-4 rounded ${statusColors[key] || 'bg-gray-500'}`}></div>
                                        <span className="text-xs">{label}</span>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Detail Dialog */}
                <Dialog open={isDetailDialogOpen} onOpenChange={setIsDetailDialogOpen}>
                    <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
                        <DialogHeader>
                            <DialogTitle>Attendance Details</DialogTitle>
                            <DialogDescription>
                                {selectedAttendance && (
                                    <>
                                        {selectedAttendance.user.name} - {selectedAttendance.shift_date}
                                    </>
                                )}
                            </DialogDescription>
                        </DialogHeader>
                        {selectedAttendance && (
                            <div className="space-y-4">
                                {/* Status */}
                                <div>
                                    <label className="text-sm font-semibold">Status</label>
                                    <div className="flex gap-2 mt-1">
                                        <Badge className={`border-0 ${statusColors[selectedAttendance.status] || 'bg-gray-500 text-white'}`}>
                                            {statusLabels[selectedAttendance.status] || selectedAttendance.status}
                                        </Badge>
                                        {selectedAttendance.secondary_status && (
                                            <Badge className={`border-0 ${statusColors[selectedAttendance.secondary_status] || 'bg-gray-500 text-white'}`}>
                                                {statusLabels[selectedAttendance.secondary_status]}
                                            </Badge>
                                        )}
                                        {selectedAttendance.admin_verified && (
                                            <Badge variant="outline" className="bg-green-600 text-white border-0">
                                                ✓ Verified
                                            </Badge>
                                        )}
                                    </div>
                                </div>

                                {/* Schedule */}
                                {selectedAttendance.employee_schedule && (
                                    <div>
                                        <label className="text-sm font-semibold">Schedule</label>
                                        <div className="text-sm mt-1">
                                            <div>
                                                Shift: {selectedAttendance.employee_schedule.shift_type.replace('_', ' ').toUpperCase()}
                                            </div>
                                            <div>
                                                Time: {formatTime(selectedAttendance.scheduled_time_in)} - {formatTime(selectedAttendance.scheduled_time_out)}
                                            </div>
                                            {selectedAttendance.employee_schedule.site && (
                                                <div>Site: {selectedAttendance.employee_schedule.site.name}</div>
                                            )}
                                        </div>
                                    </div>
                                )}

                                {/* Actual Times */}
                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <label className="text-sm font-semibold">Time In</label>
                                        <div className="text-sm mt-1">
                                            {selectedAttendance.actual_time_in
                                                ? formatTime(selectedAttendance.actual_time_in)
                                                : 'No record'}
                                        </div>
                                        {selectedAttendance.bio_in_site && (
                                            <div className="text-xs text-muted-foreground">
                                                Site: {selectedAttendance.bio_in_site.name}
                                            </div>
                                        )}
                                    </div>
                                    <div>
                                        <label className="text-sm font-semibold">Time Out</label>
                                        <div className="text-sm mt-1">
                                            {selectedAttendance.actual_time_out
                                                ? formatTime(selectedAttendance.actual_time_out)
                                                : 'No record'}
                                        </div>
                                        {selectedAttendance.bio_out_site && (
                                            <div className="text-xs text-muted-foreground">
                                                Site: {selectedAttendance.bio_out_site.name}
                                            </div>
                                        )}
                                    </div>
                                </div>

                                {/* Total Hours Worked */}
                                <div>
                                    <label className="text-sm font-semibold">Total Hours Worked</label>
                                    <div className="text-sm mt-1">
                                        {formatWorkDuration(selectedAttendance.total_minutes_worked)}
                                    </div>
                                </div>

                                {/* Violations */}
                                {(selectedAttendance.tardy_minutes || selectedAttendance.undertime_minutes || selectedAttendance.overtime_minutes) && (
                                    <div>
                                        <label className="text-sm font-semibold">Time Adjustments</label>
                                        <div className="space-y-1 mt-1 text-sm">
                                            {selectedAttendance.tardy_minutes && (
                                                <div className="text-yellow-700">
                                                    Tardy: {selectedAttendance.tardy_minutes >= 60
                                                        ? `${Math.floor(selectedAttendance.tardy_minutes / 60)} hour${Math.floor(selectedAttendance.tardy_minutes / 60) > 1 ? 's' : ''}${selectedAttendance.tardy_minutes % 60 > 0 ? ` ${selectedAttendance.tardy_minutes % 60} minutes` : ''}`
                                                        : `${selectedAttendance.tardy_minutes} minutes`
                                                    }
                                                </div>
                                            )}
                                            {selectedAttendance.undertime_minutes && (
                                                <div className="text-orange-700">
                                                    Undertime: {selectedAttendance.undertime_minutes >= 60
                                                        ? `${Math.floor(selectedAttendance.undertime_minutes / 60)} hour${Math.floor(selectedAttendance.undertime_minutes / 60) > 1 ? 's' : ''}${selectedAttendance.undertime_minutes % 60 > 0 ? ` ${selectedAttendance.undertime_minutes % 60} minutes` : ''}`
                                                        : `${selectedAttendance.undertime_minutes} minutes`
                                                    }
                                                </div>
                                            )}
                                            {selectedAttendance.overtime_minutes && selectedAttendance.overtime_minutes > 30 && (
                                                <div className="flex items-center gap-1">
                                                    <span className={selectedAttendance.overtime_approved ? "text-green-700" : "text-red-700"}>
                                                        Overtime: {selectedAttendance.overtime_minutes >= 60
                                                            ? `${Math.floor(selectedAttendance.overtime_minutes / 60)} hour${Math.floor(selectedAttendance.overtime_minutes / 60) > 1 ? 's' : ''}${selectedAttendance.overtime_minutes % 60 > 0 ? ` ${selectedAttendance.overtime_minutes % 60} minutes` : ''}`
                                                            : `${selectedAttendance.overtime_minutes} minutes`
                                                        }
                                                    </span>
                                                    {selectedAttendance.overtime_approved ? (
                                                        <CheckCircle className="h-3 w-3 text-green-700" />
                                                    ) : selectedAttendance.admin_verified ? (
                                                        <X className="h-3 w-3 text-red-700" />
                                                    ) : null}
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                )}

                                {/* Notes */}
                                {(selectedAttendance.notes || selectedAttendance.verification_notes) && (
                                    <div>
                                        <label className="text-sm font-semibold">Notes</label>
                                        <div className="text-sm mt-1 space-y-1">
                                            {selectedAttendance.notes && (
                                                <div className="bg-muted p-2 rounded">{selectedAttendance.notes}</div>
                                            )}
                                            {selectedAttendance.verification_notes && (
                                                <div className="bg-blue-50 p-2 rounded text-blue-900">
                                                    <span className="font-semibold">Verification:</span> {selectedAttendance.verification_notes}
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                )}

                                {/* Warnings */}
                                {selectedAttendance.warnings && selectedAttendance.warnings.length > 0 && (
                                    <div>
                                        <label className="text-sm font-semibold text-amber-700">Warnings</label>
                                        <div className="text-sm mt-1 space-y-1">
                                            {selectedAttendance.warnings.map((warning, idx) => (
                                                <div key={idx} className="bg-amber-50 p-2 rounded text-amber-900">
                                                    {warning}
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </div>
                        )}
                        <DialogFooter className="flex gap-2">
                            {selectedAttendance &&
                                can('attendance.approve') &&
                                selectedAttendance.status === 'on_time' &&
                                !selectedAttendance.secondary_status &&
                                !selectedAttendance.overtime_minutes &&
                                !selectedAttendance.admin_verified && (
                                    <Button
                                        onClick={handleQuickApprove}
                                        className="bg-green-600 hover:bg-green-700"
                                    >
                                        <Check className="h-4 w-4 mr-2" />
                                        Approve
                                    </Button>
                                )}
                            {selectedAttendance &&
                                can('attendance.verify') &&
                                (selectedAttendance.status !== 'on_time' ||
                                    selectedAttendance.secondary_status ||
                                    selectedAttendance.overtime_minutes) &&
                                !selectedAttendance.admin_verified && (
                                    <Button
                                        onClick={handleVerify}
                                        variant="outline"
                                        className="border-blue-600 text-blue-600 hover:bg-blue-50"
                                    >
                                        <ShieldCheck className="h-4 w-4 mr-2" />
                                        Verify
                                    </Button>
                                )}
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
