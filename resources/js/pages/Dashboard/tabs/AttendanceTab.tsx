import React, { useState } from 'react';
import { motion } from 'framer-motion';
import { router, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { DatePicker } from '@/components/ui/date-picker';
import { ChartContainer, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';
import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
    ComposedChart,
    Label,
    Line,
    LineChart,
    Pie,
    PieChart,
    PolarGrid,
    PolarRadiusAxis,
    RadialBar,
    RadialBarChart,
    ReferenceLine,
    ResponsiveContainer,
    XAxis,
    YAxis,
} from 'recharts';
import {
    AlertTriangle,
    Award,
    ChevronLeft,
    ChevronRight,
    ExternalLink,
    ShieldAlert,
    TrendingUp,
    TrendingDown,
} from 'lucide-react';
import type { DashboardProps } from '../types';

export interface AttendanceTabProps {
    attendanceStatistics: DashboardProps['attendanceStatistics'];
    monthlyAttendanceData: DashboardProps['monthlyAttendanceData'];
    dailyAttendanceData: DashboardProps['dailyAttendanceData'];
    campaigns: DashboardProps['campaigns'];
    startDate: string;
    endDate: string;
    campaignId?: string;
    verificationFilter: string;
    isRestrictedRole: boolean;
    leaveCredits: DashboardProps['leaveCredits'];
    leaveConflicts: DashboardProps['leaveConflicts'];
    pointsEscalation?: DashboardProps['pointsEscalation'];
    ncnsTrend?: DashboardProps['ncnsTrend'];
    leaveUtilization?: DashboardProps['leaveUtilization'];
}

const ATTENDANCE_TREND_SLIDES = [
    { key: 'all', label: 'All Status', description: 'Overview of all attendance statuses', color: 'hsl(220, 10%, 40%)' },
    { key: 'on_time', label: 'On Time', description: 'Employees arriving on schedule', color: 'hsl(142, 71%, 45%)' },
    { key: 'time_adjustment', label: 'Time Adjustment', description: 'Overtime and undertime adjustments', color: 'hsl(280, 65%, 60%)' },
    { key: 'tardy', label: 'Tardy', description: 'Late arrivals', color: 'hsl(45, 93%, 47%)' },
    { key: 'half_day', label: 'Half Day', description: 'Half day leaves', color: 'hsl(25, 95%, 53%)' },
    { key: 'ncns', label: 'NCNS', description: 'No Call No Show', color: 'hsl(0, 84%, 60%)' },
    { key: 'advised', label: 'Advised', description: 'Advised absences', color: 'hsl(221, 83%, 53%)' },
];

export const AttendanceTab: React.FC<AttendanceTabProps> = ({
    attendanceStatistics,
    monthlyAttendanceData,
    dailyAttendanceData,
    campaigns,
    startDate: initialStartDate,
    endDate: initialEndDate,
    campaignId: initialCampaignId,
    verificationFilter: initialVerificationFilter,
    isRestrictedRole,
    leaveCredits,
    leaveConflicts,
    pointsEscalation,
    ncnsTrend,
    leaveUtilization,
}) => {
    const [dateRange, setDateRange] = useState({
        start: initialStartDate,
        end: initialEndDate,
    });
    const [selectedCampaignId, setSelectedCampaignId] = useState<string>(initialCampaignId || "all");
    const [verificationFilter, setVerificationFilter] = useState<string>(initialVerificationFilter || "verified");
    const [radialChartIndex, setRadialChartIndex] = useState(0);
    const [selectedMonth, setSelectedMonth] = useState<string>("all");
    const [attendanceTrendSlideIndex, setAttendanceTrendSlideIndex] = useState(0);

    const activeAttendanceSlide = ATTENDANCE_TREND_SLIDES[attendanceTrendSlideIndex];
    const activeAttendanceGradientId = `attendance-trend-${activeAttendanceSlide.key}`;

    // Generate month options from actual data
    const monthOptions = (() => {
        const monthKeys = Object.keys(monthlyAttendanceData).sort();
        return monthKeys.map(key => {
            const [year, month] = key.split('-');
            const date = new Date(parseInt(year), parseInt(month) - 1, 1);
            return date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
        });
    })();

    // Calculate filtered statistics based on selected month
    const filteredStatistics = (() => {
        if (selectedMonth === "all") {
            return attendanceStatistics;
        }

        const [monthStr, yearStr] = selectedMonth.split(' ');
        const monthDate = new Date(`${monthStr} 1, ${yearStr}`);
        const selectedYear = monthDate.getFullYear();
        const selectedMonthNum = monthDate.getMonth() + 1;
        const monthKey = `${selectedYear}-${String(selectedMonthNum).padStart(2, '0')}`;

        const monthRecord = monthlyAttendanceData[monthKey];

        if (!monthRecord) {
            return {
                total: 0,
                on_time: 0,
                time_adjustment: 0,
                overtime: 0,
                undertime: 0,
                tardy: 0,
                half_day: 0,
                ncns: 0,
                advised: 0,
                needs_verification: 0,
            };
        }

        return {
            total: Number(monthRecord.total || 0),
            on_time: Number(monthRecord.on_time || 0),
            time_adjustment: Number(monthRecord.time_adjustment || 0),
            overtime: 0,
            undertime: 0,
            tardy: Number(monthRecord.tardy || 0),
            half_day: Number(monthRecord.half_day || 0),
            ncns: Number(monthRecord.ncns || 0),
            advised: Number(monthRecord.advised || 0),
            needs_verification: 0,
        };
    })();

    const radialChartData = [
        { name: "On-Time", label: "On-Time Rate", value: filteredStatistics.total > 0 ? ((filteredStatistics.on_time / filteredStatistics.total) * 100) : 0, fill: "hsl(142, 71%, 45%)", count: filteredStatistics.on_time },
        { name: "Time Adjustment", label: "Time Adjustment Rate", value: filteredStatistics.total > 0 ? ((filteredStatistics.time_adjustment / filteredStatistics.total) * 100) : 0, fill: "hsl(280, 65%, 60%)", count: filteredStatistics.time_adjustment },
        { name: "Tardy", label: "Tardy Rate", value: filteredStatistics.total > 0 ? ((filteredStatistics.tardy / filteredStatistics.total) * 100) : 0, fill: "hsl(45, 93%, 47%)", count: filteredStatistics.tardy },
        { name: "Half Day", label: "Half Day Rate", value: filteredStatistics.total > 0 ? ((filteredStatistics.half_day / filteredStatistics.total) * 100) : 0, fill: "hsl(25, 95%, 53%)", count: filteredStatistics.half_day },
        { name: "NCNS", label: "NCNS Rate", value: filteredStatistics.total > 0 ? ((filteredStatistics.ncns / filteredStatistics.total) * 100) : 0, fill: "hsl(0, 84%, 60%)", count: filteredStatistics.ncns },
        { name: "Advised", label: "Advised Rate", value: filteredStatistics.total > 0 ? ((filteredStatistics.advised / filteredStatistics.total) * 100) : 0, fill: "hsl(221, 83%, 53%)", count: filteredStatistics.advised },
    ];

    const currentRadialData = radialChartData[radialChartIndex];

    // Attendance trend data
    const attendanceTrendData = (() => {
        if (selectedMonth !== "all") {
            const [monthStr, yearStr] = selectedMonth.split(' ');
            const monthDate = new Date(`${monthStr} 1, ${yearStr}`);
            const selectedYear = monthDate.getFullYear();
            const selectedMonthNum = monthDate.getMonth() + 1;
            const monthKey = `${selectedYear}-${String(selectedMonthNum).padStart(2, '0')}`;

            const dailyRecords = dailyAttendanceData[monthKey] || [];
            const daysInMonth = new Date(selectedYear, selectedMonthNum, 0).getDate();

            const data = [];
            for (let day = 1; day <= daysInMonth; day++) {
                const dayRecord = dailyRecords.find(r => Number(r.day) === day);
                data.push({
                    month: `${day}`,
                    total: Number(dayRecord?.total || 0),
                    on_time: Number(dayRecord?.on_time || 0),
                    time_adjustment: Number(dayRecord?.time_adjustment || 0),
                    tardy: Number(dayRecord?.tardy || 0),
                    half_day: Number(dayRecord?.half_day || 0),
                    ncns: Number(dayRecord?.ncns || 0),
                    advised: Number(dayRecord?.advised || 0),
                });
            }

            return data;
        } else {
            const monthKeys = Object.keys(monthlyAttendanceData).sort();
            return monthKeys.map(monthKey => {
                const monthRecord = monthlyAttendanceData[monthKey];
                const [year, month] = monthKey.split('-');
                const date = new Date(parseInt(year), parseInt(month) - 1, 1);
                const monthName = date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });

                return {
                    month: monthName,
                    total: Number(monthRecord?.total || 0),
                    on_time: Number(monthRecord?.on_time || 0),
                    time_adjustment: Number(monthRecord?.time_adjustment || 0),
                    tardy: Number(monthRecord?.tardy || 0),
                    half_day: Number(monthRecord?.half_day || 0),
                    ncns: Number(monthRecord?.ncns || 0),
                    advised: Number(monthRecord?.advised || 0),
                };
            });
        }
    })();

    const latestAttendanceTrend = attendanceTrendData.length > 0 ? attendanceTrendData[attendanceTrendData.length - 1] : null;

    const handleDateRangeChange = () => {
        router.reload({
            data: {
                start_date: dateRange.start,
                end_date: dateRange.end,
                campaign_id: selectedCampaignId && selectedCampaignId !== "all" ? selectedCampaignId : undefined,
                verification_filter: verificationFilter,
            },
            only: ["attendanceStatistics", "monthlyAttendanceData", "dailyAttendanceData", "startDate", "endDate", "campaignId", "verificationFilter"],
        });
    };

    const handlePrevStatus = () => {
        setRadialChartIndex((prev) => (prev === 0 ? radialChartData.length - 1 : prev - 1));
    };

    const handleNextStatus = () => {
        setRadialChartIndex((prev) => (prev === radialChartData.length - 1 ? 0 : prev + 1));
    };

    const handleAttendanceTrendPrev = () => {
        setAttendanceTrendSlideIndex((prev) => (prev === 0 ? ATTENDANCE_TREND_SLIDES.length - 1 : prev - 1));
    };

    const handleAttendanceTrendNext = () => {
        setAttendanceTrendSlideIndex((prev) => (prev === ATTENDANCE_TREND_SLIDES.length - 1 ? 0 : prev + 1));
    };

    return (
        <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.3 }}
            className="space-y-6"
        >
            {/* Attendance Filters */}
            <div className="space-y-4">
                <div>
                    <h3 className="text-lg font-semibold">Attendance Overview</h3>
                    <p className="text-sm text-muted-foreground">
                        {isRestrictedRole
                            ? "Your personal attendance for the selected period"
                            : "Overview of attendance for the selected period"
                        }
                    </p>
                </div>
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:flex-wrap">
                    {!isRestrictedRole && campaigns && campaigns.length > 0 && (
                        <div className="flex-1 min-w-[160px] max-w-[200px]">
                            <Select value={selectedCampaignId || "all"} onValueChange={(value) => setSelectedCampaignId(value === "all" ? "" : value)}>
                                <SelectTrigger>
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
                        </div>
                    )}
                    <div className="flex-1 min-w-[150px] max-w-[180px]">
                        <Select value={verificationFilter} onValueChange={setVerificationFilter}>
                            <SelectTrigger>
                                <SelectValue placeholder="Verification" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Records</SelectItem>
                                <SelectItem value="verified">Verified Only</SelectItem>
                                <SelectItem value="non_verified">Non-Verified</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="flex items-center gap-2 flex-wrap sm:flex-nowrap">
                        <DatePicker
                            value={dateRange.start}
                            onChange={(value) => setDateRange({ ...dateRange, start: value })}
                            placeholder="Start date"
                        />
                        <span className="text-muted-foreground text-sm hidden sm:inline">to</span>
                        <DatePicker
                            value={dateRange.end}
                            onChange={(value) => setDateRange({ ...dateRange, end: value })}
                            placeholder="End date"
                        />
                    </div>
                    <Button onClick={handleDateRangeChange} size="sm" className="w-full sm:w-auto">
                        Apply
                    </Button>
                </div>
            </div>

            {/* Attendance Stats Cards */}
            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle className="text-sm font-medium">Total Records</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="text-2xl font-bold">{filteredStatistics.total}</div>
                        <p className="text-xs text-muted-foreground mt-1">
                            {selectedMonth !== "all" ? "Filtered" : "All"} attendance entries
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle className="text-sm font-medium">On Time</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="text-2xl font-bold text-green-600">{filteredStatistics.on_time}</div>
                        <p className="text-xs text-muted-foreground mt-1">
                            {filteredStatistics.total > 0
                                ? `${((filteredStatistics.on_time / filteredStatistics.total) * 100).toFixed(1)}%`
                                : '0%'} of total
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle className="text-sm font-medium">Time Adjustment</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="text-2xl font-bold text-purple-600">{filteredStatistics.time_adjustment}</div>
                        <p className="text-xs text-muted-foreground mt-1">
                            {filteredStatistics.total > 0
                                ? `${((filteredStatistics.time_adjustment / filteredStatistics.total) * 100).toFixed(1)}%`
                                : '0%'} (<span className="text-cyan-600 font-medium">{attendanceStatistics.overtime || 0}</span> OT / <span className="text-purple-500 font-medium">{attendanceStatistics.undertime || 0}</span> UT)
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle className="text-sm font-medium">Tardy</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="text-2xl font-bold text-yellow-600">{filteredStatistics.tardy}</div>
                        <p className="text-xs text-muted-foreground mt-1">
                            {filteredStatistics.total > 0
                                ? `${((filteredStatistics.tardy / filteredStatistics.total) * 100).toFixed(1)}%`
                                : '0%'} of total
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle className="text-sm font-medium">Half Day</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="text-2xl font-bold text-orange-600">{filteredStatistics.half_day}</div>
                        <p className="text-xs text-muted-foreground mt-1">
                            {filteredStatistics.total > 0
                                ? `${((filteredStatistics.half_day / filteredStatistics.total) * 100).toFixed(1)}%`
                                : '0%'} of total
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle className="text-sm font-medium">NCNS</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="text-2xl font-bold text-red-600">{filteredStatistics.ncns}</div>
                        <p className="text-xs text-muted-foreground mt-1">
                            {filteredStatistics.total > 0
                                ? `${((filteredStatistics.ncns / filteredStatistics.total) * 100).toFixed(1)}%`
                                : '0%'} of total
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle className="text-sm font-medium">Advised Absence</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="text-2xl font-bold text-blue-600">{filteredStatistics.advised}</div>
                        <p className="text-xs text-muted-foreground mt-1">
                            {filteredStatistics.total > 0
                                ? `${((filteredStatistics.advised / filteredStatistics.total) * 100).toFixed(1)}%`
                                : '0%'} of total
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle className="text-sm font-medium">Needs Verification</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="text-2xl font-bold text-purple-600">{filteredStatistics.needs_verification}</div>
                        <p className="text-xs text-muted-foreground mt-1">
                            Requires review
                        </p>
                    </CardContent>
                </Card>
            </div>

            {/* Leave Conflicts Widget */}
            {!isRestrictedRole && leaveConflicts && leaveConflicts.total > 0 && (
                <Card className="border-amber-500/50 bg-amber-50/50 dark:bg-amber-950/20">
                    <CardHeader className="pb-3">
                        <div className="flex items-center justify-between">
                            <div>
                                <CardTitle className="text-lg flex items-center gap-2 text-amber-700 dark:text-amber-400">
                                    <AlertTriangle className="h-5 w-5" />
                                    Leave Conflicts
                                    <Badge variant="destructive" className="ml-2">
                                        {leaveConflicts.total}
                                    </Badge>
                                </CardTitle>
                                <CardDescription>
                                    Employees with biometric activity during approved leave
                                </CardDescription>
                            </div>
                            <Link
                                href="/attendance/review?leave_conflict=yes&verified=pending"
                                className="text-sm text-primary hover:underline flex items-center gap-1"
                            >
                                Review All <ExternalLink className="h-3 w-3" />
                            </Link>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-3">
                            {leaveConflicts.records.slice(0, 5).map((record) => (
                                <div
                                    key={record.id}
                                    className="flex items-center justify-between p-3 bg-white dark:bg-gray-900 rounded-lg border border-amber-200 dark:border-amber-800"
                                >
                                    <div className="flex-1">
                                        <div className="flex items-center gap-2">
                                            <span className="font-medium text-sm">{record.user_name}</span>
                                            <Badge variant="outline" className="text-xs">
                                                {record.leave_type}
                                            </Badge>
                                        </div>
                                        <div className="text-xs text-muted-foreground mt-1">
                                            <span>Worked on {new Date(record.shift_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</span>
                                            <span className="mx-1">•</span>
                                            <span>Leave: {new Date(record.leave_start).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} - {new Date(record.leave_end).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}</span>
                                        </div>
                                    </div>
                                    <Link
                                        href={`/attendance/review?verify=${record.id}`}
                                        className="text-xs text-primary hover:underline"
                                    >
                                        Review
                                    </Link>
                                </div>
                            ))}
                            {leaveConflicts.total > 5 && (
                                <p className="text-xs text-muted-foreground text-center pt-2">
                                    +{leaveConflicts.total - 5} more conflicts pending review
                                </p>
                            )}
                        </div>
                    </CardContent>
                </Card>
            )}

            {/* Leave Credits Widget */}
            {leaveCredits && (
                <Card>
                    <CardHeader className="pb-3">
                        <div className="flex items-center justify-between">
                            <div>
                                <CardTitle className="text-lg flex items-center gap-2">
                                    <Award className="h-5 w-5" />
                                    My Leave Credits
                                </CardTitle>
                                <CardDescription>
                                    Year {leaveCredits.year} • Credits reset annually
                                </CardDescription>
                            </div>
                            <a href="/form-requests/leave-requests/create" className="text-sm text-primary hover:underline">
                                Request Leave →
                            </a>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {!leaveCredits.is_eligible ? (
                            <div className="text-center py-4">
                                <p className="text-muted-foreground">
                                    You will be eligible on <span className="font-medium">{leaveCredits.eligibility_date}</span>
                                </p>
                                <p className="text-xs text-muted-foreground mt-1">
                                    (6 months after hire date)
                                </p>
                            </div>
                        ) : (
                            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <div className="text-center">
                                    <p className="text-xs text-muted-foreground uppercase">Rate/Month</p>
                                    <p className="text-2xl font-bold">{leaveCredits.monthly_rate}</p>
                                </div>
                                <div className="text-center">
                                    <p className="text-xs text-muted-foreground uppercase">Earned</p>
                                    <p className="text-2xl font-bold text-green-600">+{leaveCredits.total_earned.toFixed(1)}</p>
                                </div>
                                <div className="text-center">
                                    <p className="text-xs text-muted-foreground uppercase">Used</p>
                                    <p className="text-2xl font-bold text-orange-600">-{leaveCredits.total_used.toFixed(1)}</p>
                                </div>
                                <div className="text-center">
                                    <p className="text-xs text-muted-foreground uppercase">Balance</p>
                                    <p className="text-2xl font-bold text-primary">{leaveCredits.balance.toFixed(2)}</p>
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            )}

            {/* Attendance Charts Section */}
            <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                {/* Donut Chart - Status Breakdown */}
                <Card className="flex flex-col">
                    <CardHeader className="pb-0">
                        <CardTitle className="text-base">Status Distribution</CardTitle>
                        <CardDescription className="text-xs">Breakdown by attendance status</CardDescription>
                    </CardHeader>
                    <CardContent className="flex-1 pb-0">
                        <ChartContainer
                            config={{
                                on_time: { label: "On Time", color: "hsl(142, 71%, 45%)" },
                                time_adjustment: { label: "Time Adjustment", color: "hsl(280, 65%, 60%)" },
                                tardy: { label: "Tardy", color: "hsl(45, 93%, 47%)" },
                                half_day: { label: "Half Day", color: "hsl(25, 95%, 53%)" },
                                ncns: { label: "NCNS", color: "hsl(0, 84%, 60%)" },
                                advised: { label: "Advised", color: "hsl(221, 83%, 53%)" },
                            }}
                            className="mx-auto aspect-square max-h-[250px]"
                        >
                            <PieChart>
                                <ChartTooltip
                                    cursor={false}
                                    content={<ChartTooltipContent hideLabel />}
                                />
                                <Pie
                                    data={[
                                        { name: "on_time", value: filteredStatistics.on_time, fill: "hsl(142, 71%, 45%)" },
                                        { name: "time_adjustment", value: filteredStatistics.time_adjustment, fill: "hsl(280, 65%, 60%)" },
                                        { name: "tardy", value: filteredStatistics.tardy, fill: "hsl(45, 93%, 47%)" },
                                        { name: "half_day", value: filteredStatistics.half_day, fill: "hsl(25, 95%, 53%)" },
                                        { name: "ncns", value: filteredStatistics.ncns, fill: "hsl(0, 84%, 60%)" },
                                        { name: "advised", value: filteredStatistics.advised, fill: "hsl(221, 83%, 53%)" },
                                    ]}
                                    dataKey="value"
                                    nameKey="name"
                                    innerRadius={70}
                                    outerRadius={100}
                                    strokeWidth={2}
                                >
                                    <Label
                                        content={({ viewBox }) => {
                                            if (viewBox && "cx" in viewBox && "cy" in viewBox) {
                                                return (
                                                    <text
                                                        x={viewBox.cx}
                                                        y={viewBox.cy}
                                                        textAnchor="middle"
                                                        dominantBaseline="middle"
                                                    >
                                                        <tspan
                                                            x={viewBox.cx}
                                                            y={viewBox.cy}
                                                            className="fill-foreground text-4xl font-bold"
                                                        >
                                                            {filteredStatistics.total}
                                                        </tspan>
                                                        <tspan
                                                            x={viewBox.cx}
                                                            y={(viewBox.cy || 0) + 28}
                                                            className="fill-muted-foreground text-sm"
                                                        >
                                                            Total
                                                        </tspan>
                                                    </text>
                                                )
                                            }
                                        }}
                                    />
                                </Pie>
                            </PieChart>
                        </ChartContainer>
                    </CardContent>
                </Card>

                {/* Bar Chart - Count by Status */}
                <Card className="flex flex-col">
                    <CardHeader className="pb-0">
                        <CardTitle className="text-base">Count by Status</CardTitle>
                        <CardDescription className="text-xs">Actual number of records per status</CardDescription>
                    </CardHeader>
                    <CardContent className="flex-1 pb-0">
                        <ChartContainer
                            config={{ count: { label: "Records" } }}
                            className="aspect-square max-h-[250px]"
                        >
                            <BarChart
                                data={[
                                    { status: "On Time", count: filteredStatistics.on_time, fill: "hsl(142, 71%, 45%)" },
                                    { status: "Time Adj.", count: filteredStatistics.time_adjustment, fill: "hsl(280, 65%, 60%)" },
                                    { status: "Tardy", count: filteredStatistics.tardy, fill: "hsl(45, 93%, 47%)" },
                                    { status: "Half Day", count: filteredStatistics.half_day, fill: "hsl(25, 95%, 53%)" },
                                    { status: "NCNS", count: filteredStatistics.ncns, fill: "hsl(0, 84%, 60%)" },
                                    { status: "Advised", count: filteredStatistics.advised, fill: "hsl(221, 83%, 53%)" },
                                ]}
                                layout="vertical"
                                margin={{ left: 10, right: 10 }}
                            >
                                <CartesianGrid strokeDasharray="3 3" horizontal={false} />
                                <YAxis
                                    dataKey="status"
                                    type="category"
                                    tickLine={false}
                                    tickMargin={10}
                                    axisLine={false}
                                    width={70}
                                    fontSize={12}
                                />
                                <XAxis type="number" hide />
                                <ChartTooltip
                                    cursor={false}
                                    content={<ChartTooltipContent indicator="line" />}
                                />
                                <Bar dataKey="count" radius={[0, 4, 4, 0]} />
                            </BarChart>
                        </ChartContainer>
                    </CardContent>
                </Card>

                {/* Radial Chart - All Status Rate */}
                <Card className="flex flex-col">
                    <CardHeader className="pb-0">
                        <div className="flex items-center justify-between">
                            <div>
                                <CardTitle className="text-base">{currentRadialData.label}</CardTitle>
                                <CardDescription className="text-xs">Percentage of {currentRadialData.name.toLowerCase()} attendance</CardDescription>
                            </div>
                            <div className="flex items-center gap-1">
                                <button
                                    onClick={handlePrevStatus}
                                    className="p-1 rounded hover:bg-muted transition-colors"
                                    aria-label="Previous status"
                                >
                                    <ChevronLeft className="h-4 w-4" />
                                </button>
                                <button
                                    onClick={handleNextStatus}
                                    className="p-1 rounded hover:bg-muted transition-colors"
                                    aria-label="Next status"
                                >
                                    <ChevronRight className="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="flex-1 pb-0">
                        <ChartContainer
                            config={{
                                rate: {
                                    label: currentRadialData.label,
                                    color: currentRadialData.fill,
                                },
                            }}
                            className="mx-auto aspect-square max-h-[250px]"
                        >
                            <RadialBarChart
                                data={[currentRadialData]}
                                startAngle={90}
                                endAngle={90 - (currentRadialData.value / 100) * 360}
                                innerRadius={80}
                                outerRadius={115}
                            >
                                <PolarGrid
                                    gridType="circle"
                                    radialLines={false}
                                    stroke="none"
                                    className="first:fill-muted last:fill-background"
                                    polarRadius={[86, 74]}
                                />
                                <RadialBar dataKey="value" background cornerRadius={10} />
                                <PolarRadiusAxis tick={false} tickLine={false} axisLine={false}>
                                    <Label
                                        content={({ viewBox }) => {
                                            if (viewBox && "cx" in viewBox && "cy" in viewBox) {
                                                return (
                                                    <text
                                                        x={viewBox.cx}
                                                        y={viewBox.cy}
                                                        textAnchor="middle"
                                                        dominantBaseline="middle"
                                                    >
                                                        <tspan
                                                            x={viewBox.cx}
                                                            y={viewBox.cy}
                                                            className="fill-foreground text-4xl font-bold"
                                                        >
                                                            {currentRadialData.value.toFixed(1)}%
                                                        </tspan>
                                                        <tspan
                                                            x={viewBox.cx}
                                                            y={(viewBox.cy || 0) + 24}
                                                            className="fill-muted-foreground text-xs"
                                                        >
                                                            {currentRadialData.name}
                                                        </tspan>
                                                        <tspan
                                                            x={viewBox.cx}
                                                            y={(viewBox.cy || 0) + 40}
                                                            className="fill-muted-foreground text-xs"
                                                        >
                                                            ({currentRadialData.count} of {filteredStatistics.total})
                                                        </tspan>
                                                    </text>
                                                );
                                            }
                                        }}
                                    />
                                </PolarRadiusAxis>
                            </RadialBarChart>
                        </ChartContainer>
                    </CardContent>
                </Card>
            </div>

            {/* Area Chart - Monthly Statistics */}
            <Card>
                <CardHeader className="pb-0">
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <CardTitle className="text-base">Monthly Attendance Trends</CardTitle>
                            <CardDescription className="text-xs">
                                {activeAttendanceSlide.description}
                            </CardDescription>
                        </div>
                        <div className="flex items-center gap-2">
                            <div className="text-sm font-medium">
                                {activeAttendanceSlide.label}
                            </div>
                            <div className="flex items-center gap-2">
                                <button
                                    type="button"
                                    onClick={handleAttendanceTrendPrev}
                                    className="rounded-full border px-2 py-1 text-xs hover:bg-muted"
                                    aria-label="Previous trend"
                                >
                                    <ChevronLeft className="h-4 w-4" />
                                </button>
                                <button
                                    type="button"
                                    onClick={handleAttendanceTrendNext}
                                    className="rounded-full border px-2 py-1 text-xs hover:bg-muted"
                                    aria-label="Next trend"
                                >
                                    <ChevronRight className="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2 mt-4 pt-4 border-t">
                        <div className="flex items-center gap-2">
                            <span className="text-xs font-medium">Filter Month:</span>
                            <Select value={selectedMonth} onValueChange={setSelectedMonth}>
                                <SelectTrigger className="h-8 w-[140px] text-xs">
                                    <SelectValue placeholder="All Months" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Months</SelectItem>
                                    {monthOptions.map((month) => (
                                        <SelectItem key={month} value={month}>{month}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                </CardHeader>
                <CardContent className="pt-4">
                    {attendanceTrendData.length === 0 ? (
                        <div className="py-10 text-center text-muted-foreground">
                            No attendance data available for the selected period.
                        </div>
                    ) : (
                        <>
                            <ChartContainer
                                config={
                                    activeAttendanceSlide.key === 'all'
                                        ? ATTENDANCE_TREND_SLIDES.slice(1).reduce((acc, slide) => ({ ...acc, [slide.key]: { label: slide.label, color: slide.color } }), {})
                                        : {
                                            [activeAttendanceSlide.key]: {
                                                label: activeAttendanceSlide.label,
                                                color: activeAttendanceSlide.color,
                                            }
                                        }
                                }
                                className="h-[320px] w-full"
                            >
                                <ResponsiveContainer width="100%" height="100%">
                                    <AreaChart data={attendanceTrendData} margin={{ left: 10, right: 10 }}>
                                        <defs>
                                            {activeAttendanceSlide.key === 'all' ? (
                                                ATTENDANCE_TREND_SLIDES.slice(1).map(slide => (
                                                    <linearGradient key={slide.key} id={`attendance-trend-${slide.key}`} x1="0" y1="0" x2="0" y2="1">
                                                        <stop offset="5%" stopColor={slide.color} stopOpacity={0.8} />
                                                        <stop offset="95%" stopColor={slide.color} stopOpacity={0.05} />
                                                    </linearGradient>
                                                ))
                                            ) : (
                                                <linearGradient id={activeAttendanceGradientId} x1="0" y1="0" x2="0" y2="1">
                                                    <stop offset="5%" stopColor={activeAttendanceSlide.color} stopOpacity={0.8} />
                                                    <stop offset="95%" stopColor={activeAttendanceSlide.color} stopOpacity={0.05} />
                                                </linearGradient>
                                            )}
                                        </defs>
                                        <CartesianGrid strokeDasharray="3 3" />
                                        <XAxis dataKey="month" tickLine={false} axisLine={false} fontSize={12} interval={selectedMonth !== "all" ? "preserveStartEnd" : 0} />
                                        <YAxis allowDecimals={false} width={40} tickLine={false} axisLine={false} fontSize={12} />
                                        <ChartTooltip
                                            cursor={false}
                                            content={<ChartTooltipContent />}
                                        />
                                        {activeAttendanceSlide.key === 'all' ? (
                                            ATTENDANCE_TREND_SLIDES.slice(1).map(slide => (
                                                <Area
                                                    key={slide.key}
                                                    type="monotone"
                                                    dataKey={slide.key}
                                                    stroke={slide.color}
                                                    fill={`url(#attendance-trend-${slide.key})`}
                                                    strokeWidth={2}
                                                    activeDot={{ r: 5 }}
                                                    stackId="1"
                                                />
                                            ))
                                        ) : (
                                            <Area
                                                type="monotone"
                                                dataKey={activeAttendanceSlide.key}
                                                stroke={activeAttendanceSlide.color}
                                                fill={`url(#${activeAttendanceGradientId})`}
                                                strokeWidth={2}
                                                activeDot={{ r: 5 }}
                                            />
                                        )}
                                    </AreaChart>
                                </ResponsiveContainer>
                            </ChartContainer>
                            <div className="mt-4 flex flex-wrap items-center justify-between gap-4 text-sm">
                                <div>
                                    <p className="text-muted-foreground text-xs uppercase">Latest Period</p>
                                    <p className="font-semibold">{latestAttendanceTrend?.month ?? 'N/A'}</p>
                                </div>
                                <div className="flex flex-wrap gap-4">
                                    <div>
                                        <p className="text-muted-foreground text-xs uppercase">Total</p>
                                        <p className="font-semibold">{latestAttendanceTrend?.total ?? 0}</p>
                                    </div>
                                    <div>
                                        <p className="text-muted-foreground text-xs uppercase">On Time</p>
                                        <p className="font-semibold">{latestAttendanceTrend?.on_time ?? 0}</p>
                                    </div>
                                    <div>
                                        <p className="text-muted-foreground text-xs uppercase">Tardy</p>
                                        <p className="font-semibold">{latestAttendanceTrend?.tardy ?? 0}</p>
                                    </div>
                                    <div>
                                        <p className="text-muted-foreground text-xs uppercase">Absences</p>
                                        <p className="font-semibold">{(latestAttendanceTrend?.ncns ?? 0) + (latestAttendanceTrend?.advised ?? 0)}</p>
                                    </div>
                                </div>
                            </div>
                        </>
                    )}
                </CardContent>
            </Card>

            {/* ─── Phase 4: Enhanced Analytics (Super Admin / Admin / HR) ─────── */}

            {/* Attendance Compliance Rate + Leave Utilization (side by side) */}
            {!isRestrictedRole && (attendanceStatistics.total > 0 || (leaveUtilization && leaveUtilization.months.length > 0)) && (
                <div className="grid gap-4 md:grid-cols-2">
                    {/* Attendance Compliance Rate KPI - Compact */}
                    {attendanceStatistics.total > 0 && (
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-sm">
                                    <Award className="h-4 w-4 text-green-500" />
                                    Attendance Compliance Rate
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {(() => {
                                    const complianceRate = Math.round((attendanceStatistics.on_time / attendanceStatistics.total) * 100);
                                    return (
                                        <div className="space-y-3">
                                            {/* Rate + Progress Bar */}
                                            <div className="flex items-baseline gap-2">
                                                <span className={`text-3xl font-bold ${complianceRate >= 80 ? 'text-green-500' : complianceRate >= 60 ? 'text-yellow-500' : 'text-red-500'}`}>
                                                    {complianceRate}%
                                                </span>
                                                <span className="text-xs text-muted-foreground">on-time rate</span>
                                            </div>
                                            <div className="h-2 w-full rounded-full bg-muted overflow-hidden">
                                                <div
                                                    className={`h-full rounded-full transition-all ${complianceRate >= 80 ? 'bg-green-500' : complianceRate >= 60 ? 'bg-yellow-500' : 'bg-red-500'}`}
                                                    style={{ width: `${complianceRate}%` }}
                                                />
                                            </div>

                                            {/* Breakdown */}
                                            <div className="grid grid-cols-2 gap-2 pt-1">
                                                <div className="flex items-center gap-2 text-xs">
                                                    <div className="h-2.5 w-2.5 rounded-full bg-green-500" />
                                                    <span className="text-muted-foreground">On Time</span>
                                                    <span className="ml-auto font-medium">{attendanceStatistics.on_time}</span>
                                                </div>
                                                <div className="flex items-center gap-2 text-xs">
                                                    <div className="h-2.5 w-2.5 rounded-full bg-yellow-500" />
                                                    <span className="text-muted-foreground">Tardy</span>
                                                    <span className="ml-auto font-medium">{attendanceStatistics.tardy}</span>
                                                </div>
                                                <div className="flex items-center gap-2 text-xs">
                                                    <div className="h-2.5 w-2.5 rounded-full bg-red-500" />
                                                    <span className="text-muted-foreground">NCNS</span>
                                                    <span className="ml-auto font-medium">{attendanceStatistics.ncns}</span>
                                                </div>
                                                <div className="flex items-center gap-2 text-xs">
                                                    <div className="h-2.5 w-2.5 rounded-full bg-blue-500" />
                                                    <span className="text-muted-foreground">Advised</span>
                                                    <span className="ml-auto font-medium">{attendanceStatistics.advised}</span>
                                                </div>
                                            </div>
                                        </div>
                                    );
                                })()}
                            </CardContent>
                        </Card>
                    )}

                    {/* Leave Utilization Chart */}
                    {leaveUtilization && leaveUtilization.months.length > 0 && (
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-sm">
                                    <Award className="h-4 w-4 text-indigo-500" />
                                    Leave Utilization
                                    <Badge variant="secondary" className="ml-auto text-[10px]">{leaveUtilization.totals.utilization_rate}% used</Badge>
                                </CardTitle>
                                <CardDescription className="text-xs">
                                    {leaveUtilization.totals.total_earned} earned, {leaveUtilization.totals.total_used} used
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <ChartContainer config={{ earned: { label: 'Earned', color: 'hsl(142, 71%, 45%)' }, used: { label: 'Used', color: 'hsl(221, 83%, 53%)' }, utilization_rate: { label: 'Utilization %', color: 'hsl(280, 65%, 60%)' } }} className="h-[200px] w-full">
                                    <ResponsiveContainer width="100%" height="100%">
                                        <ComposedChart data={leaveUtilization.months} margin={{ top: 5, right: 10, left: 0, bottom: 0 }}>
                                            <CartesianGrid strokeDasharray="3 3" />
                                            <XAxis dataKey="label" tickLine={false} axisLine={false} fontSize={11} />
                                            <YAxis yAxisId="left" allowDecimals={false} width={30} tickLine={false} axisLine={false} fontSize={11} />
                                            <YAxis yAxisId="right" orientation="right" domain={[0, 100]} width={30} tickLine={false} axisLine={false} fontSize={11} tickFormatter={(v) => `${v}%`} />
                                            <ChartTooltip content={<ChartTooltipContent />} />
                                            <Bar yAxisId="left" dataKey="earned" fill="hsl(142, 71%, 45%)" radius={[4, 4, 0, 0]} barSize={16} name="Earned" />
                                            <Bar yAxisId="left" dataKey="used" fill="hsl(221, 83%, 53%)" radius={[4, 4, 0, 0]} barSize={16} name="Used" />
                                            <Line yAxisId="right" type="monotone" dataKey="utilization_rate" stroke="hsl(280, 65%, 60%)" strokeWidth={2} dot={{ r: 3 }} name="Utilization %" />
                                        </ComposedChart>
                                    </ResponsiveContainer>
                                </ChartContainer>
                            </CardContent>
                        </Card>
                    )}
                </div>
            )}

            {/* Points Escalation Alert */}
            {!isRestrictedRole && pointsEscalation && pointsEscalation.count > 0 && (
                <Card className="border-amber-200 dark:border-amber-800">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <ShieldAlert className="h-5 w-5 text-amber-500" />
                            Points Escalation Alert
                            <Badge variant="outline" className="ml-auto border-amber-500 text-amber-600">{pointsEscalation.count} employees</Badge>
                        </CardTitle>
                        <CardDescription>Employees with 4.00–5.99 active points — nearing the 6-point threshold</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-3">
                            {pointsEscalation.employees.slice(0, 8).map((emp) => (
                                <div key={emp.user_id} className="flex items-center justify-between rounded-lg border p-3">
                                    <div>
                                        <p className="font-medium text-sm">{emp.user_name}</p>
                                        <p className="text-xs text-muted-foreground">{emp.user_role} &middot; {emp.violations_count} violations</p>
                                    </div>
                                    <div className="text-right">
                                        <p className="font-bold text-amber-600">{emp.total_points} pts</p>
                                        <p className="text-xs text-muted-foreground">{emp.remaining_before_threshold} pts to threshold</p>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            )}

            {/* NCNS Trend Chart */}
            {!isRestrictedRole && ncnsTrend && ncnsTrend.length > 0 && (
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <AlertTriangle className="h-5 w-5 text-red-500" />
                            NCNS Trend
                            {ncnsTrend.length >= 2 && (() => {
                                const lastChange = ncnsTrend[ncnsTrend.length - 1].change;
                                if (lastChange === 'increasing') {
                                    return <Badge variant="destructive" className="ml-2 flex items-center gap-1"><TrendingUp className="h-3 w-3" /> Increasing</Badge>;
                                }
                                if (lastChange === 'decreasing') {
                                    return <Badge className="ml-2 flex items-center gap-1 bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300"><TrendingDown className="h-3 w-3" /> Decreasing</Badge>;
                                }
                                return <Badge variant="secondary" className="ml-2">Stable</Badge>;
                            })()}
                        </CardTitle>
                        <CardDescription>No Call No Show incidents over the last 6 months</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ChartContainer config={{ ncns_count: { label: 'NCNS Count', color: 'hsl(0, 84%, 60%)' } }} className="h-[250px] w-full">
                            <ResponsiveContainer width="100%" height="100%">
                                <LineChart data={ncnsTrend} margin={{ top: 5, right: 10, left: 0, bottom: 0 }}>
                                    <CartesianGrid strokeDasharray="3 3" />
                                    <XAxis dataKey="label" tickLine={false} axisLine={false} fontSize={12} />
                                    <YAxis allowDecimals={false} width={35} tickLine={false} axisLine={false} fontSize={12} />
                                    <ChartTooltip content={<ChartTooltipContent />} />
                                    <ReferenceLine y={0} stroke="hsl(var(--muted-foreground))" strokeDasharray="3 3" />
                                    <Line type="monotone" dataKey="ncns_count" stroke="hsl(0, 84%, 60%)" strokeWidth={2} dot={{ r: 4, fill: 'hsl(0, 84%, 60%)' }} activeDot={{ r: 6 }} />
                                </LineChart>
                            </ResponsiveContainer>
                        </ChartContainer>
                    </CardContent>
                </Card>
            )}

        </motion.div>
    );
};
