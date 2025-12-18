import React, { useState, useMemo } from "react";
import { Head, router, usePage } from "@inertiajs/react";
import AppLayout from "@/layouts/app-layout";
import { useFlashMessage, usePageLoading, usePageMeta } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { LoadingOverlay } from "@/components/LoadingOverlay";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import { Calendar, ChevronLeft, ChevronRight, LayoutGrid, Columns, ArrowLeft } from "lucide-react";
import { Link } from "@inertiajs/react";

interface Leave {
    id: number;
    user_id: number;
    user_name: string;
    user_role: string;
    campaign_name: string;
    leave_type: string;
    start_date: string;
    end_date: string;
    days_requested: number;
    reason: string;
    status: string;
}

interface Campaign {
    id: number;
    name: string;
}

interface PageProps {
    leaves: Leave[];
    campaigns: Campaign[] | null;
    filters: {
        month: string;
        campaign_id: string | null;
        leave_type: string | null;
        view_mode: 'single' | 'multi';
    };
    isRestrictedRole: boolean;
    [key: string]: unknown;
}

const leaveTypes = [
    { value: 'VL', label: 'Vacation Leave' },
    { value: 'SL', label: 'Sick Leave' },
    { value: 'EL', label: 'Emergency Leave' },
    { value: 'ML', label: 'Maternity Leave' },
    { value: 'PL', label: 'Paternity Leave' },
    { value: 'BL', label: 'Bereavement Leave' },
];

const leaveTypeColors: Record<string, string> = {
    'VL': 'bg-blue-500',
    'SL': 'bg-red-500',
    'EL': 'bg-orange-500',
    'ML': 'bg-pink-500',
    'PL': 'bg-purple-500',
    'BL': 'bg-gray-500',
};

export default function LeaveCalendar() {
    const { leaves, campaigns, filters, isRestrictedRole } = usePage<PageProps>().props;
    const [hoveredLeaveId, setHoveredLeaveId] = useState<number | null>(null);

    const { title, breadcrumbs } = usePageMeta({
        title: "Leave Calendar",
        breadcrumbs: [
            { title: "Leave Requests", href: "/form-requests/leave-requests" },
            { title: "Calendar", href: "" },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    // Parse current month
    const currentMonth = useMemo(() => {
        const [year, month] = filters.month.split('-').map(Number);
        return new Date(year, month - 1, 1);
    }, [filters.month]);

    const handleFilterChange = (key: string, value: string | null) => {
        router.get('/form-requests/leave-requests/calendar', {
            ...filters,
            [key]: value === 'all' ? null : value,
        }, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handleMonthChange = (direction: 'prev' | 'next') => {
        const newDate = new Date(currentMonth);
        if (direction === 'prev') {
            newDate.setMonth(newDate.getMonth() - 1);
        } else {
            newDate.setMonth(newDate.getMonth() + 1);
        }
        const newMonth = `${newDate.getFullYear()}-${String(newDate.getMonth() + 1).padStart(2, '0')}`;
        handleFilterChange('month', newMonth);
    };

    const handleToday = () => {
        const today = new Date();
        const newMonth = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`;
        handleFilterChange('month', newMonth);
    };

    // Get months to display based on view mode
    const monthsToDisplay = useMemo(() => {
        if (filters.view_mode === 'multi') {
            const prevMonth = new Date(currentMonth);
            prevMonth.setMonth(prevMonth.getMonth() - 1);
            const nextMonth = new Date(currentMonth);
            nextMonth.setMonth(nextMonth.getMonth() + 1);
            return [prevMonth, currentMonth, nextMonth];
        }
        return [currentMonth];
    }, [currentMonth, filters.view_mode]);

    // Helper to format date as YYYY-MM-DD
    const formatDateKey = (date: Date) => {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    };

    // Render a single month calendar
    const renderMonth = (monthDate: Date, isCompact: boolean = false) => {
        const today = new Date();
        const year = monthDate.getFullYear();
        const month = monthDate.getMonth();
        const firstDay = new Date(year, month, 1);
        const lastDay = new Date(year, month + 1, 0);
        const daysInMonth = lastDay.getDate();
        const startingDayOfWeek = firstDay.getDay();

        // Get leaves by date for this month
        const leavesByDate: Record<string, Leave[]> = {};
        leaves.forEach(leave => {
            const startStr = leave.start_date.split('T')[0];
            const endStr = leave.end_date.split('T')[0];
            const [sy, sm, sd] = startStr.split('-').map(Number);
            const [ey, em, ed] = endStr.split('-').map(Number);
            const start = new Date(sy, sm - 1, sd);
            const end = new Date(ey, em - 1, ed);

            const effectiveStart = start < firstDay ? firstDay : start;
            const effectiveEnd = end > lastDay ? lastDay : end;

            const currentDate = new Date(effectiveStart);
            while (currentDate <= effectiveEnd) {
                const dateKey = formatDateKey(currentDate);
                if (!leavesByDate[dateKey]) {
                    leavesByDate[dateKey] = [];
                }
                leavesByDate[dateKey].push(leave);
                currentDate.setDate(currentDate.getDate() + 1);
            }
        });

        const dayNames = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];

        return (
            <div className={`space-y-2 ${isCompact ? '' : 'p-4'}`}>
                {/* Month header */}
                <div className="text-center font-semibold text-sm mb-2">
                    {monthDate.toLocaleDateString('en-US', { month: 'long', year: 'numeric' })}
                </div>
                {/* Day names */}
                <div className="grid grid-cols-7 gap-1">
                    {dayNames.map((day, idx) => (
                        <div key={idx} className="text-center text-xs font-medium text-muted-foreground p-0.5">
                            {day}
                        </div>
                    ))}
                </div>
                {/* Calendar days */}
                <div className="grid grid-cols-7 gap-1">
                    {/* Empty cells */}
                    {Array.from({ length: startingDayOfWeek }).map((_, i) => (
                        <div key={`empty-${i}`} className={isCompact ? "aspect-square" : "aspect-square"} />
                    ))}
                    {/* Actual days */}
                    {Array.from({ length: daysInMonth }).map((_, i) => {
                        const day = i + 1;
                        const dateKey = formatDateKey(new Date(year, month, day));
                        const leavesOnDay = leavesByDate[dateKey] || [];
                        const isToday = day === today.getDate() && month === today.getMonth() && year === today.getFullYear();
                        const hasLeaves = leavesOnDay.length > 0;
                        const isHoveredLeaveDay = hoveredLeaveId !== null && leavesOnDay.some(l => l.id === hoveredLeaveId);

                        return (
                            <div
                                key={day}
                                className={`
                                    ${isCompact ? 'aspect-square p-0.5' : 'aspect-square p-1'}
                                    rounded flex items-center justify-center text-xs relative transition-all duration-150
                                    ${hasLeaves ? 'bg-amber-500 dark:bg-amber-600 font-semibold text-white' : 'text-muted-foreground'}
                                    ${isToday ? 'ring-2 ring-primary' : ''}
                                    ${isHoveredLeaveDay ? 'ring-2 ring-offset-1 ring-offset-background ring-primary bg-amber-400 dark:bg-amber-500 shadow-lg' : ''}
                                `}
                                title={hasLeaves ? `${leavesOnDay.length} employee(s) on leave` : undefined}
                            >
                                {day}
                                {hasLeaves && leavesOnDay.length > 1 && (
                                    <div className="absolute -top-0.5 -right-0.5 w-3 h-3 bg-red-500 rounded-full text-[7px] text-white flex items-center justify-center">
                                        {leavesOnDay.length}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
            </div>
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                <LoadingOverlay isLoading={isPageLoading} />

                <PageHeader
                    title="Leave Calendar"
                    description="View approved employee leaves"
                />

                {/* Filters */}
                <Card>
                    <CardContent className="pt-2">
                        <div className="flex flex-col sm:flex-row gap-4 items-start sm:items-center justify-between">
                            <div className="flex flex-wrap gap-3 items-center">
                                <Button variant="outline" size="sm" asChild>
                                    <Link href="/form-requests/leave-requests">
                                        <ArrowLeft className="h-4 w-4 mr-2" />
                                        Back to Leave Requests
                                    </Link>
                                </Button>

                                {!isRestrictedRole && campaigns && (
                                    <Select
                                        value={filters.campaign_id || 'all'}
                                        onValueChange={(value) => handleFilterChange('campaign_id', value)}
                                    >
                                        <SelectTrigger className="w-[180px]">
                                            <SelectValue placeholder="All Campaigns" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">All Campaigns</SelectItem>
                                            {campaigns.map((campaign) => (
                                                <SelectItem key={campaign.id} value={String(campaign.id)}>
                                                    {campaign.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                )}

                                <Select
                                    value={filters.leave_type || 'all'}
                                    onValueChange={(value) => handleFilterChange('leave_type', value)}
                                >
                                    <SelectTrigger className="w-[160px]">
                                        <SelectValue placeholder="All Types" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Leave Types</SelectItem>
                                        {leaveTypes.map((type) => (
                                            <SelectItem key={type.value} value={type.value}>
                                                {type.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>

                                <div className="flex items-center border rounded-md">
                                    <Button
                                        variant={filters.view_mode === 'single' ? 'secondary' : 'ghost'}
                                        size="sm"
                                        className="rounded-r-none"
                                        onClick={() => handleFilterChange('view_mode', 'single')}
                                    >
                                        <LayoutGrid className="h-4 w-4 mr-1" />
                                        Single
                                    </Button>
                                    <Button
                                        variant={filters.view_mode === 'multi' ? 'secondary' : 'ghost'}
                                        size="sm"
                                        className="rounded-l-none"
                                        onClick={() => handleFilterChange('view_mode', 'multi')}
                                    >
                                        <Columns className="h-4 w-4 mr-1" />
                                        3 Months
                                    </Button>
                                </div>
                            </div>

                            <div className="flex items-center gap-2">
                                <Button variant="outline" size="sm" onClick={handleToday}>
                                    Today
                                </Button>
                                <Button variant="outline" size="icon" onClick={() => handleMonthChange('prev')}>
                                    <ChevronLeft className="h-4 w-4" />
                                </Button>
                                <div className="text-sm font-semibold min-w-[140px] text-center">
                                    {currentMonth.toLocaleDateString('en-US', { month: 'long', year: 'numeric' })}
                                </div>
                                <Button variant="outline" size="icon" onClick={() => handleMonthChange('next')}>
                                    <ChevronRight className="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Calendar and Details */}
                <div className="grid grid-cols-1 lg:grid-cols-5 gap-4">
                    {/* Calendar(s) */}
                    <Card className="lg:col-span-3">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Calendar className="h-5 w-5" />
                                {filters.view_mode === 'multi' ? '3-Month View' : 'Monthly View'}
                            </CardTitle>
                            <CardDescription>
                                {leaves.length} approved leave{leaves.length !== 1 ? 's' : ''} in view
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {filters.view_mode === 'multi' ? (
                                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    {monthsToDisplay.map((monthDate, idx) => (
                                        <div key={idx} className="border rounded-lg p-2">
                                            {renderMonth(monthDate, true)}
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="border rounded-lg max-w-lg mx-auto p-4">
                                    {renderMonth(currentMonth, false)}
                                </div>
                            )}

                            {/* Legend */}
                            <div className="flex flex-wrap gap-4 text-xs pt-4 mt-4 border-t">
                                <div className="flex items-center gap-2">
                                    <div className="w-4 h-4 rounded bg-amber-500 dark:bg-amber-600" />
                                    <span>On leave</span>
                                </div>
                                <div className="flex items-center gap-2">
                                    <div className="w-4 h-4 rounded ring-2 ring-primary" />
                                    <span>Today</span>
                                </div>
                                <div className="flex items-center gap-2">
                                    <div className="w-4 h-4 rounded bg-amber-500 relative">
                                        <div className="absolute -top-0.5 -right-0.5 w-2 h-2 bg-red-500 rounded-full" />
                                    </div>
                                    <span>Multiple employees</span>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Leave List */}
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>All Leaves ({leaves.length})</CardTitle>
                            <CardDescription>Hover to highlight dates</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-2 max-h-[600px] overflow-y-auto pr-2">
                                {leaves.length === 0 ? (
                                    <div className="text-center text-muted-foreground py-8">
                                        No approved leaves in this period
                                    </div>
                                ) : (
                                    leaves.map((leave) => (
                                        <div
                                            key={leave.id}
                                            className={`p-3 border rounded-lg cursor-pointer transition-colors text-sm ${hoveredLeaveId === leave.id ? 'bg-accent border-primary' : 'hover:bg-accent/50'
                                                }`}
                                            onMouseEnter={() => setHoveredLeaveId(leave.id)}
                                            onMouseLeave={() => setHoveredLeaveId(null)}
                                        >
                                            <div className="flex items-start justify-between gap-2 mb-1">
                                                <div>
                                                    <span className="font-medium">{leave.user_name}</span>
                                                    <div className="text-xs text-muted-foreground">{leave.campaign_name}</div>
                                                </div>
                                                <Badge
                                                    variant="outline"
                                                    className={`text-xs shrink-0 text-white border-0 ${leaveTypeColors[leave.leave_type] || 'bg-gray-500'}`}
                                                >
                                                    {leave.leave_type}
                                                </Badge>
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {Number(leave.days_requested) === 1
                                                    ? `${new Date(leave.start_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} (1 day)`
                                                    : `${new Date(leave.start_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} - ${new Date(leave.end_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} (${Math.round(Number(leave.days_requested))} days)`
                                                }
                                            </div>
                                            {leave.reason && (
                                                <div className="text-xs text-muted-foreground mt-1 line-clamp-2">
                                                    {leave.reason}
                                                </div>
                                            )}
                                        </div>
                                    ))
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
