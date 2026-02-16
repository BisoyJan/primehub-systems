import React, { useState, useMemo } from 'react';
import { motion } from 'framer-motion';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Separator } from '@/components/ui/separator';
import { ChartContainer, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';
import {
    Bar,
    BarChart,
    Cell,
    Label,
    Pie,
    PieChart,
    PolarRadiusAxis,
    RadialBar,
    RadialBarChart,
    ResponsiveContainer,
    XAxis,
    YAxis,
} from 'recharts';
import {
    Calendar,
    Clock,
    CheckCircle2,
    AlertTriangle,
    XCircle,
    UserCheck,
    UserX,
    ClipboardList,
    TrendingUp,
    Award,
} from 'lucide-react';
import type { PersonalSchedule, PersonalRequests, PersonalAttendanceSummary, LeaveCredits } from '../types';

export interface PersonalDashboardTabProps {
    personalSchedule?: PersonalSchedule | null;
    personalRequests?: PersonalRequests;
    personalAttendanceSummary?: PersonalAttendanceSummary;
    leaveCredits?: LeaveCredits;
}

const STATUS_COLORS: Record<string, string> = {
    approved: 'bg-green-500/10 text-green-700 border-green-500/30',
    pending: 'bg-yellow-500/10 text-yellow-700 border-yellow-500/30',
    rejected: 'bg-red-500/10 text-red-700 border-red-500/30',
    resolved: 'bg-green-500/10 text-green-700 border-green-500/30',
    in_progress: 'bg-blue-500/10 text-blue-700 border-blue-500/30',
    open: 'bg-yellow-500/10 text-yellow-700 border-yellow-500/30',
    closed: 'bg-gray-500/10 text-gray-700 border-gray-500/30',
    completed: 'bg-green-500/10 text-green-700 border-green-500/30',
};

const PRIORITY_COLORS: Record<string, string> = {
    low: 'bg-gray-500/10 text-gray-700 border-gray-500/30',
    medium: 'bg-yellow-500/10 text-yellow-700 border-yellow-500/30',
    high: 'bg-orange-500/10 text-orange-700 border-orange-500/30',
    critical: 'bg-red-500/10 text-red-700 border-red-500/30',
};

const DAY_ABBREVIATIONS: Record<string, string> = {
    Monday: 'Mon',
    Tuesday: 'Tue',
    Wednesday: 'Wed',
    Thursday: 'Thu',
    Friday: 'Fri',
    Saturday: 'Sat',
    Sunday: 'Sun',
};

function formatDate(dateString: string): string {
    return new Date(dateString).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

function formatTime(timeString: string): string {
    const [hours, minutes] = timeString.split(':');
    const date = new Date();
    date.setHours(Number(hours));
    date.setMinutes(Number(minutes));
    return date.toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: 'numeric',
        hour12: true,
    });
}

export const PersonalDashboardTab: React.FC<PersonalDashboardTabProps> = ({
    personalSchedule,
    personalRequests,
    personalAttendanceSummary,
    leaveCredits,
}) => {
    const [requestTab, setRequestTab] = useState<string>('leaves');

    const pointsThreshold = personalAttendanceSummary?.points_threshold ?? 6;
    const totalPoints = personalAttendanceSummary?.total_points ?? 0;
    const pointsPercentage = Math.min((totalPoints / pointsThreshold) * 100, 100);

    // Donut chart data for attendance distribution
    const attendanceDonutData = useMemo(() => {
        if (!personalAttendanceSummary) return [];
        return [
            { name: 'On Time', value: personalAttendanceSummary.on_time, fill: 'hsl(142, 71%, 45%)' },
            { name: 'Tardy', value: personalAttendanceSummary.tardy, fill: 'hsl(45, 93%, 47%)' },
            { name: 'Half Day', value: personalAttendanceSummary.half_day, fill: 'hsl(25, 95%, 53%)' },
            { name: 'NCNS', value: personalAttendanceSummary.ncns, fill: 'hsl(0, 84%, 60%)' },
            { name: 'On Leave', value: personalAttendanceSummary.on_leave, fill: 'hsl(221, 83%, 53%)' },
        ].filter(d => d.value > 0);
    }, [personalAttendanceSummary]);

    // Horizontal bar data for points by type
    const pointsByTypeBarData = useMemo(() => {
        if (!personalAttendanceSummary?.points_by_type) return [];
        const labels: Record<string, string> = {
            whole_day_absence: 'Full Day Absence',
            half_day_absence: 'Half Day Absence',
            tardy: 'Tardy',
            undertime: 'Undertime',
            undertime_more_than_hour: 'Undertime (1hr+)',
        };
        return Object.entries(personalAttendanceSummary.points_by_type)
            .filter(([, pts]) => pts > 0)
            .map(([type, pts]) => ({
                name: labels[type] ?? type.replace(/_/g, ' '),
                points: pts,
            }))
            .sort((a, b) => b.points - a.points);
    }, [personalAttendanceSummary]);

    // Radial gauge data for points threshold
    const pointsGaugeData = useMemo(() => [{
        name: 'points',
        value: Math.min(totalPoints, pointsThreshold),
        fill: pointsPercentage >= 80 ? 'hsl(0, 84%, 60%)' : pointsPercentage >= 50 ? 'hsl(45, 93%, 47%)' : 'hsl(142, 71%, 45%)',
    }], [totalPoints, pointsThreshold, pointsPercentage]);

    return (
        <div className="space-y-6">
            {/* Row 1: Schedule + Attendance Summary */}
            <div className="grid gap-6 grid-cols-1 lg:grid-cols-2">
                {/* Schedule Card */}
                <motion.div
                    initial={{ opacity: 0, y: 20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.3, delay: 0 }}
                >
                    <Card className="h-full">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Clock className="h-5 w-5" />
                                My Schedule
                            </CardTitle>
                            {personalSchedule && (
                                <CardDescription>
                                    {personalSchedule.campaign} · {personalSchedule.site}
                                </CardDescription>
                            )}
                        </CardHeader>
                        <CardContent>
                            {personalSchedule ? (
                                <div className="space-y-4">
                                    <div className="grid grid-cols-2 gap-3">
                                        <div className="rounded-lg border p-3">
                                            <p className="text-xs text-muted-foreground">Shift Type</p>
                                            <p className="text-sm font-semibold capitalize">{personalSchedule.shift_type.replace(/_/g, ' ')}</p>
                                        </div>
                                        <div className="rounded-lg border p-3">
                                            <p className="text-xs text-muted-foreground">Grace Period</p>
                                            <p className="text-sm font-semibold">{personalSchedule.grace_period_minutes} min</p>
                                        </div>
                                        <div className="rounded-lg border p-3">
                                            <p className="text-xs text-muted-foreground">Time In</p>
                                            <p className="text-sm font-semibold">{formatTime(personalSchedule.time_in)}</p>
                                        </div>
                                        <div className="rounded-lg border p-3">
                                            <p className="text-xs text-muted-foreground">Time Out</p>
                                            <p className="text-sm font-semibold">{formatTime(personalSchedule.time_out)}</p>
                                        </div>
                                    </div>

                                    <div>
                                        <p className="text-xs text-muted-foreground mb-2">Work Days</p>
                                        <div className="flex flex-wrap gap-1.5">
                                            {personalSchedule.work_days.map((day) => (
                                                <Badge key={day} variant="secondary" className="text-xs">
                                                    {DAY_ABBREVIATIONS[day] ?? day}
                                                </Badge>
                                            ))}
                                        </div>
                                    </div>

                                    <Separator />

                                    <div>
                                        <p className="text-xs text-muted-foreground mb-2">Next 7 Work Days</p>
                                        <div className="space-y-1">
                                            {personalSchedule.next_shifts.slice(0, 7).map((shift, i) => (
                                                <div
                                                    key={i}
                                                    className="flex items-center gap-2 text-sm rounded px-2 py-1 hover:bg-muted/50"
                                                >
                                                    <Calendar className="h-3.5 w-3.5 text-muted-foreground" />
                                                    <span>{formatDate(shift)}</span>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">No active schedule found.</p>
                            )}
                        </CardContent>
                    </Card>
                </motion.div>

                {/* Attendance Summary Card */}
                <motion.div
                    initial={{ opacity: 0, y: 20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.3, delay: 0.1 }}
                >
                    <Card className="h-full">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <TrendingUp className="h-5 w-5" />
                                Attendance Summary
                            </CardTitle>
                            {personalAttendanceSummary && (
                                <CardDescription>{personalAttendanceSummary.month}</CardDescription>
                            )}
                        </CardHeader>
                        <CardContent>
                            {personalAttendanceSummary ? (
                                <div className="space-y-4">
                                    <div className="grid grid-cols-3 sm:grid-cols-5 gap-2">
                                        {[
                                            { label: 'Present', value: personalAttendanceSummary.present, icon: CheckCircle2, color: 'text-green-600' },
                                            { label: 'On Time', value: personalAttendanceSummary.on_time, icon: UserCheck, color: 'text-green-500' },
                                            { label: 'Tardy', value: personalAttendanceSummary.tardy, icon: Clock, color: 'text-yellow-600' },
                                            { label: 'Absent', value: personalAttendanceSummary.absent, icon: UserX, color: 'text-red-500' },
                                            { label: 'NCNS', value: personalAttendanceSummary.ncns, icon: XCircle, color: 'text-red-600' },
                                        ].map(({ label, value, icon: Icon, color }) => (
                                            <div key={label} className="rounded-lg border p-2 text-center">
                                                <Icon className={`h-4 w-4 mx-auto mb-1 ${color}`} />
                                                <p className="text-lg font-bold">{value}</p>
                                                <p className="text-[10px] text-muted-foreground">{label}</p>
                                            </div>
                                        ))}
                                    </div>

                                    <div className="grid grid-cols-2 gap-2">
                                        <div className="rounded-lg border p-2 text-center">
                                            <p className="text-sm text-muted-foreground">Half Day</p>
                                            <p className="text-lg font-bold">{personalAttendanceSummary.half_day}</p>
                                        </div>
                                        <div className="rounded-lg border p-2 text-center">
                                            <p className="text-sm text-muted-foreground">On Leave</p>
                                            <p className="text-lg font-bold">{personalAttendanceSummary.on_leave}</p>
                                        </div>
                                    </div>

                                    {/* Attendance Donut Chart */}
                                    {attendanceDonutData.length > 0 && (
                                        <>
                                            <Separator />
                                            <div>
                                                <p className="text-xs text-muted-foreground mb-2">Status Distribution</p>
                                                <ChartContainer config={{
                                                    on_time: { label: 'On Time', color: 'hsl(142, 71%, 45%)' },
                                                    tardy: { label: 'Tardy', color: 'hsl(45, 93%, 47%)' },
                                                    half_day: { label: 'Half Day', color: 'hsl(25, 95%, 53%)' },
                                                    ncns: { label: 'NCNS', color: 'hsl(0, 84%, 60%)' },
                                                    on_leave: { label: 'On Leave', color: 'hsl(221, 83%, 53%)' },
                                                }} className="h-[160px] w-full">
                                                    <ResponsiveContainer width="100%" height="100%">
                                                        <PieChart>
                                                            <Pie
                                                                data={attendanceDonutData}
                                                                dataKey="value"
                                                                nameKey="name"
                                                                innerRadius={40}
                                                                outerRadius={65}
                                                                strokeWidth={2}
                                                                paddingAngle={2}
                                                            >
                                                                {attendanceDonutData.map((entry, i) => (
                                                                    <Cell key={i} fill={entry.fill} />
                                                                ))}
                                                                <Label
                                                                    content={({ viewBox }) => {
                                                                        if (viewBox && 'cx' in viewBox && 'cy' in viewBox) {
                                                                            return (
                                                                                <text x={viewBox.cx} y={viewBox.cy} textAnchor="middle" dominantBaseline="middle">
                                                                                    <tspan x={viewBox.cx} y={viewBox.cy} className="fill-foreground text-xl font-bold">{personalAttendanceSummary.total}</tspan>
                                                                                    <tspan x={viewBox.cx} y={(viewBox.cy ?? 0) + 16} className="fill-muted-foreground text-[10px]">Total</tspan>
                                                                                </text>
                                                                            );
                                                                        }
                                                                    }}
                                                                />
                                                            </Pie>
                                                            <ChartTooltip content={<ChartTooltipContent />} />
                                                        </PieChart>
                                                    </ResponsiveContainer>
                                                </ChartContainer>
                                            </div>
                                        </>
                                    )}

                                    <Separator />

                                    {/* Points Gauge (RadialBarChart) */}
                                    <div>
                                        <p className="text-xs text-muted-foreground mb-1">Attendance Points</p>
                                        <div className="flex items-center gap-4">
                                            <ChartContainer config={{ points: { label: 'Points' } }} className="h-[120px] w-[120px] shrink-0">
                                                <RadialBarChart
                                                    innerRadius={35}
                                                    outerRadius={55}
                                                    data={pointsGaugeData}
                                                    startAngle={90}
                                                    endAngle={90 + (Math.min(totalPoints, pointsThreshold) / pointsThreshold) * 360}
                                                >
                                                    <PolarRadiusAxis tick={false} tickLine={false} axisLine={false}>
                                                        <Label
                                                            content={({ viewBox }) => {
                                                                if (viewBox && 'cx' in viewBox && 'cy' in viewBox) {
                                                                    return (
                                                                        <text x={viewBox.cx} y={viewBox.cy} textAnchor="middle" dominantBaseline="middle">
                                                                            <tspan x={viewBox.cx} y={viewBox.cy} className="fill-foreground text-lg font-bold">{totalPoints}</tspan>
                                                                            <tspan x={viewBox.cx} y={(viewBox.cy ?? 0) + 14} className="fill-muted-foreground text-[9px]">of {pointsThreshold}</tspan>
                                                                        </text>
                                                                    );
                                                                }
                                                            }}
                                                        />
                                                    </PolarRadiusAxis>
                                                    <RadialBar dataKey="value" background cornerRadius={8} />
                                                </RadialBarChart>
                                            </ChartContainer>
                                            <div className="space-y-1 flex-1">
                                                {pointsPercentage >= 80 && (
                                                    <p className="text-xs text-red-500 flex items-center gap-1">
                                                        <AlertTriangle className="h-3 w-3" />
                                                        Approaching threshold
                                                    </p>
                                                )}
                                                {pointsPercentage >= 50 && pointsPercentage < 80 && (
                                                    <p className="text-xs text-yellow-600 flex items-center gap-1">
                                                        <AlertTriangle className="h-3 w-3" />
                                                        Moderate risk
                                                    </p>
                                                )}
                                                {pointsPercentage < 50 && (
                                                    <p className="text-xs text-green-600 flex items-center gap-1">
                                                        <CheckCircle2 className="h-3 w-3" />
                                                        Good standing
                                                    </p>
                                                )}
                                                <p className="text-[10px] text-muted-foreground">
                                                    {(pointsThreshold - totalPoints).toFixed(2)} pts remaining
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Points by Type — Horizontal BarChart */}
                                    {pointsByTypeBarData.length > 0 && (
                                        <div>
                                            <p className="text-xs text-muted-foreground mb-2">Points by Type</p>
                                            <ChartContainer config={{ points: { label: 'Points', color: 'hsl(25, 95%, 53%)' } }} className="h-[120px] w-full">
                                                <ResponsiveContainer width="100%" height="100%">
                                                    <BarChart data={pointsByTypeBarData} layout="vertical" margin={{ left: 0, right: 5, top: 0, bottom: 0 }}>
                                                        <XAxis type="number" hide />
                                                        <YAxis type="category" dataKey="name" width={100} tickLine={false} axisLine={false} fontSize={10} />
                                                        <ChartTooltip content={<ChartTooltipContent />} />
                                                        <Bar dataKey="points" fill="hsl(25, 95%, 53%)" radius={[0, 4, 4, 0]} barSize={14} />
                                                    </BarChart>
                                                </ResponsiveContainer>
                                            </ChartContainer>
                                        </div>
                                    )}

                                    {/* Upcoming Expirations */}
                                    {personalAttendanceSummary.upcoming_expirations.length > 0 && (
                                        <div className="space-y-1">
                                            <p className="text-xs text-muted-foreground">Upcoming Expirations</p>
                                            {personalAttendanceSummary.upcoming_expirations.map((exp, i) => (
                                                <div
                                                    key={i}
                                                    className="flex items-center justify-between text-xs rounded px-2 py-1 bg-muted/50"
                                                >
                                                    <span>{exp.point_type.replace(/_/g, ' ')}</span>
                                                    <span className="text-muted-foreground">
                                                        {exp.points}pt · expires {formatDate(exp.expires_at)}
                                                    </span>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">No attendance data available.</p>
                            )}
                        </CardContent>
                    </Card>
                </motion.div>
            </div>

            {/* Row 2: Recent Requests + Leave Credits */}
            <div className="grid gap-6 grid-cols-1 lg:grid-cols-3">
                {/* Recent Requests */}
                <motion.div
                    initial={{ opacity: 0, y: 20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.3, delay: 0.2 }}
                    className="lg:col-span-2"
                >
                    <Card className="h-full">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <ClipboardList className="h-5 w-5" />
                                Recent Requests
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Tabs value={requestTab} onValueChange={setRequestTab}>
                                <TabsList className="grid w-full grid-cols-3">
                                    <TabsTrigger value="leaves" className="text-xs sm:text-sm">
                                        Leaves
                                        {personalRequests?.leaves?.length ? (
                                            <Badge variant="secondary" className="ml-1.5 text-[10px] px-1.5">
                                                {personalRequests.leaves.length}
                                            </Badge>
                                        ) : null}
                                    </TabsTrigger>
                                    <TabsTrigger value="it_concerns" className="text-xs sm:text-sm">
                                        IT Concerns
                                        {personalRequests?.it_concerns?.length ? (
                                            <Badge variant="secondary" className="ml-1.5 text-[10px] px-1.5">
                                                {personalRequests.it_concerns.length}
                                            </Badge>
                                        ) : null}
                                    </TabsTrigger>
                                    <TabsTrigger value="medication" className="text-xs sm:text-sm">
                                        Medication
                                        {personalRequests?.medication_requests?.length ? (
                                            <Badge variant="secondary" className="ml-1.5 text-[10px] px-1.5">
                                                {personalRequests.medication_requests.length}
                                            </Badge>
                                        ) : null}
                                    </TabsTrigger>
                                </TabsList>

                                {/* Leaves Tab */}
                                <TabsContent value="leaves" className="mt-3">
                                    {personalRequests?.leaves?.length ? (
                                        <div className="space-y-2">
                                            {personalRequests.leaves.slice(0, 5).map((leave) => (
                                                <div
                                                    key={leave.id}
                                                    className="flex items-center justify-between rounded-lg border p-3"
                                                >
                                                    <div className="space-y-0.5">
                                                        <p className="text-sm font-medium">{leave.leave_type}</p>
                                                        <p className="text-xs text-muted-foreground">
                                                            {formatDate(leave.start_date)} — {formatDate(leave.end_date)} ·{' '}
                                                            {leave.days_requested}d
                                                        </p>
                                                    </div>
                                                    <Badge variant="outline" className={STATUS_COLORS[leave.status.toLowerCase()] ?? ''}>
                                                        {leave.status}
                                                    </Badge>
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="text-sm text-muted-foreground py-4 text-center">No leave requests.</p>
                                    )}
                                </TabsContent>

                                {/* IT Concerns Tab */}
                                <TabsContent value="it_concerns" className="mt-3">
                                    {personalRequests?.it_concerns?.length ? (
                                        <div className="space-y-2">
                                            {personalRequests.it_concerns.slice(0, 5).map((concern) => (
                                                <div
                                                    key={concern.id}
                                                    className="flex items-center justify-between rounded-lg border p-3"
                                                >
                                                    <div className="space-y-0.5 flex-1 min-w-0 mr-3">
                                                        <p className="text-sm font-medium">{concern.category}</p>
                                                        <p className="text-xs text-muted-foreground truncate">
                                                            {concern.description}
                                                        </p>
                                                    </div>
                                                    <div className="flex items-center gap-1.5 shrink-0">
                                                        <Badge variant="outline" className={PRIORITY_COLORS[concern.priority.toLowerCase()] ?? ''}>
                                                            {concern.priority}
                                                        </Badge>
                                                        <Badge variant="outline" className={STATUS_COLORS[concern.status.toLowerCase()] ?? ''}>
                                                            {concern.status}
                                                        </Badge>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="text-sm text-muted-foreground py-4 text-center">No IT concerns.</p>
                                    )}
                                </TabsContent>

                                {/* Medication Tab */}
                                <TabsContent value="medication" className="mt-3">
                                    {personalRequests?.medication_requests?.length ? (
                                        <div className="space-y-2">
                                            {personalRequests.medication_requests.slice(0, 5).map((med) => (
                                                <div
                                                    key={med.id}
                                                    className="flex items-center justify-between rounded-lg border p-3"
                                                >
                                                    <div className="space-y-0.5">
                                                        <p className="text-sm font-medium">{med.name}</p>
                                                        <p className="text-xs text-muted-foreground">{med.medication_type}</p>
                                                    </div>
                                                    <Badge variant="outline" className={STATUS_COLORS[med.status.toLowerCase()] ?? ''}>
                                                        {med.status}
                                                    </Badge>
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="text-sm text-muted-foreground py-4 text-center">No medication requests.</p>
                                    )}
                                </TabsContent>
                            </Tabs>
                        </CardContent>
                    </Card>
                </motion.div>

                {/* Leave Credits Card */}
                <motion.div
                    initial={{ opacity: 0, y: 20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.3, delay: 0.3 }}
                >
                    <Card className="h-full">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Award className="h-5 w-5" />
                                Leave Credits
                            </CardTitle>
                            {leaveCredits && (
                                <CardDescription>FY {leaveCredits.year}</CardDescription>
                            )}
                        </CardHeader>
                        <CardContent>
                            {leaveCredits ? (
                                <div className="space-y-4">
                                    {!leaveCredits.is_eligible ? (
                                        <div className="rounded-lg border border-yellow-500/30 bg-yellow-500/5 p-3">
                                            <p className="text-xs text-yellow-700 flex items-center gap-1.5">
                                                <AlertTriangle className="h-3.5 w-3.5" />
                                                Not yet eligible for leave credits
                                            </p>
                                            {leaveCredits.eligibility_date && (
                                                <p className="text-xs text-muted-foreground mt-1">
                                                    Eligible from {formatDate(leaveCredits.eligibility_date)}
                                                </p>
                                            )}
                                        </div>
                                    ) : (
                                        <>
                                            <div className="text-center">
                                                <p className="text-3xl font-bold">{leaveCredits.balance.toFixed(1)}</p>
                                                <p className="text-xs text-muted-foreground">Days Remaining</p>
                                            </div>

                                            <Separator />

                                            <div className="space-y-2">
                                                <div className="flex items-center justify-between text-sm">
                                                    <span className="text-muted-foreground">Monthly Rate</span>
                                                    <span className="font-medium">{leaveCredits.monthly_rate.toFixed(2)}</span>
                                                </div>
                                                <div className="flex items-center justify-between text-sm">
                                                    <span className="text-muted-foreground">Total Earned</span>
                                                    <span className="font-medium text-green-600">
                                                        {leaveCredits.total_earned.toFixed(1)}
                                                    </span>
                                                </div>
                                                <div className="flex items-center justify-between text-sm">
                                                    <span className="text-muted-foreground">Total Used</span>
                                                    <span className="font-medium text-red-600">
                                                        {leaveCredits.total_used.toFixed(1)}
                                                    </span>
                                                </div>
                                            </div>

                                            {/* Usage bar */}
                                            <div className="space-y-1">
                                                <div className="h-2 w-full rounded-full bg-muted overflow-hidden">
                                                    <div
                                                        className="h-full rounded-full bg-primary transition-all"
                                                        style={{
                                                            width: `${leaveCredits.total_earned > 0 ? ((leaveCredits.total_used / leaveCredits.total_earned) * 100).toFixed(1) : 0}%`,
                                                        }}
                                                    />
                                                </div>
                                                <p className="text-[10px] text-muted-foreground text-center">
                                                    {leaveCredits.total_earned > 0
                                                        ? ((leaveCredits.total_used / leaveCredits.total_earned) * 100).toFixed(0)
                                                        : 0}
                                                    % used
                                                </p>
                                            </div>
                                        </>
                                    )}
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">No leave credit data available.</p>
                            )}
                        </CardContent>
                    </Card>
                </motion.div>
            </div>
        </div>
    );
};
