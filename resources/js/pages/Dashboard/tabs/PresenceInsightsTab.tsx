import React, { useState, useMemo } from 'react';
import { motion } from 'framer-motion';
import { router, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { DatePicker } from '@/components/ui/date-picker';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { ChartContainer, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, XAxis, YAxis } from 'recharts';
import { useInitials } from '@/hooks/use-initials';
import {
    AlertCircle,
    AlertTriangle,
    Calendar,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    ExternalLink,
    TrendingUp,
    UserCheck,
    UserMinus,
    UserX,
    Users,
    XCircle,
} from 'lucide-react';
import { show as attendancePointsShow } from '@/routes/attendance-points';
import { StatCard } from '../components/StatCard';
import { DetailDialog } from '../components/DetailDialog';
import type { DashboardProps } from '../types';

export interface PresenceInsightsTabProps {
    presenceInsights: DashboardProps['presenceInsights'];
    isRestrictedRole: boolean;
    leaveCalendarMonth?: string;
    campaignPresence?: DashboardProps['campaignPresence'];
    pointsByCampaign?: DashboardProps['pointsByCampaign'];
}

export const PresenceInsightsTab: React.FC<PresenceInsightsTabProps> = ({
    presenceInsights,
    isRestrictedRole,
    leaveCalendarMonth,
    campaignPresence,
    pointsByCampaign,
}) => {
    const [activeDialog, setActiveDialog] = useState<string | null>(null);
    const [selectedVacantSite, setSelectedVacantSite] = useState<string | null>(null);
    const [hoveredLeaveId, setHoveredLeaveId] = useState<number | null>(null);
    const [presenceDate, setPresenceDate] = useState(new Date().toISOString().split('T')[0]);
    const [calendarDate, setCalendarDate] = useState(() => {
        if (leaveCalendarMonth) {
            return new Date(leaveCalendarMonth);
        }
        return new Date();
    });
    const getInitials = useInitials();

    const closeDialog = () => {
        setActiveDialog(null);
        setSelectedVacantSite(null);
    };

    const handlePresenceDateChange = (newDate: string) => {
        setPresenceDate(newDate);
        router.reload({
            data: { presence_date: newDate },
            only: ["presenceInsights"],
        });
    };

    const handleCalendarMonthChange = (newDate: Date) => {
        setCalendarDate(newDate);
        const year = newDate.getFullYear();
        const month = String(newDate.getMonth() + 1).padStart(2, '0');
        const day = String(newDate.getDate()).padStart(2, '0');
        const dateStr = `${year}-${month}-${day}`;

        router.reload({
            data: { leave_calendar_month: dateStr },
            only: ["presenceInsights", "leaveCalendarMonth"],
        });
    };

    const handlePrevMonth = () => {
        const newDate = new Date(calendarDate.getFullYear(), calendarDate.getMonth() - 1, 1);
        handleCalendarMonthChange(newDate);
    };

    const handleNextMonth = () => {
        const newDate = new Date(calendarDate.getFullYear(), calendarDate.getMonth() + 1, 1);
        handleCalendarMonthChange(newDate);
    };

    const handleToday = () => {
        handleCalendarMonthChange(new Date());
    };

    const filteredLeaves = useMemo(() => {
        return presenceInsights?.leaveCalendar || [];
    }, [presenceInsights?.leaveCalendar]);

    return (
        <>
            <motion.div
                initial={{ opacity: 0, y: 20 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.3 }}
            >
                {/* Today's Presence Overview - Hidden for Agent/Utility */}
                {!isRestrictedRole && (
                    <Card className="mb-6">
                        <CardHeader>
                            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                <div>
                                    <CardTitle className="flex items-center gap-2">
                                        <UserCheck className="h-5 w-5" />
                                        Presence Overview
                                    </CardTitle>
                                    <CardDescription>Employee presence status for {new Date(presenceDate).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })}</CardDescription>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => handlePresenceDateChange(new Date().toISOString().split('T')[0])}
                                    >
                                        Today
                                    </Button>
                                    <DatePicker
                                        value={presenceDate}
                                        onChange={(value) => handlePresenceDateChange(value)}
                                        placeholder="Select date"
                                    />
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-5">
                                <StatCard
                                    title="Total Scheduled"
                                    value={presenceInsights?.todayPresence.total_scheduled || 0}
                                    icon={Users}
                                    description="Active employees"
                                    onClick={() => { }}
                                    delay={0}
                                />
                                <StatCard
                                    title="Present"
                                    value={presenceInsights?.todayPresence.present || 0}
                                    icon={UserCheck}
                                    description="Currently at work"
                                    onClick={() => setActiveDialog('presentEmployees')}
                                    variant="success"
                                    delay={0.05}
                                />
                                <StatCard
                                    title="Absent"
                                    value={presenceInsights?.todayPresence.absent || 0}
                                    icon={UserX}
                                    description="Not reported"
                                    onClick={() => setActiveDialog('absentEmployees')}
                                    variant="danger"
                                    delay={0.1}
                                />
                                <StatCard
                                    title="On Leave"
                                    value={presenceInsights?.todayPresence.on_leave || 0}
                                    icon={UserMinus}
                                    description="Approved leaves"
                                    onClick={() => { }}
                                    variant="warning"
                                    delay={0.15}
                                />
                                <StatCard
                                    title="Unaccounted"
                                    value={presenceInsights?.todayPresence.unaccounted || 0}
                                    icon={AlertCircle}
                                    description="No record yet"
                                    onClick={() => { }}
                                    variant={presenceInsights?.todayPresence.unaccounted ? "warning" : "default"}
                                    delay={0.2}
                                />
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Leave Calendar Section */}
                <Card className="mb-6">
                    <CardHeader>
                        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                            <div>
                                <CardTitle className="flex items-center gap-2">
                                    <Calendar className="h-5 w-5" />
                                    Leave Calendar
                                </CardTitle>
                                <CardDescription>Employees on approved leave</CardDescription>
                            </div>
                            <div className="flex items-center gap-2">
                                <Button variant="outline" size="sm" asChild>
                                    <Link href="/form-requests/leave-requests/calendar">
                                        <ExternalLink className="h-4 w-4 mr-1" />
                                        Full Calendar
                                    </Link>
                                </Button>
                                <Button variant="outline" size="sm" onClick={handleToday}>
                                    Today
                                </Button>
                                <Button variant="outline" size="icon" onClick={handlePrevMonth}>
                                    <ChevronLeft className="h-4 w-4" />
                                </Button>
                                <div className="text-sm font-semibold min-w-[140px] text-center">
                                    {calendarDate.toLocaleDateString('en-US', { month: 'long', year: 'numeric' })}
                                </div>
                                <Button variant="outline" size="icon" onClick={handleNextMonth}>
                                    <ChevronRight className="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {(!filteredLeaves || filteredLeaves.length === 0) ? (
                            <div className="text-center text-muted-foreground py-8">
                                No employees on leave this month
                            </div>
                        ) : (
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                {/* Calendar Grid - Left Side */}
                                <div className="border rounded-lg p-4">
                                    {(() => {
                                        const today = new Date();
                                        const year = calendarDate.getFullYear();
                                        const month = calendarDate.getMonth();
                                        const firstDay = new Date(year, month, 1);
                                        const lastDay = new Date(year, month + 1, 0);
                                        const daysInMonth = lastDay.getDate();
                                        const startingDayOfWeek = firstDay.getDay();

                                        const formatDateKey = (date: Date) => {
                                            const y = date.getFullYear();
                                            const m = String(date.getMonth() + 1).padStart(2, '0');
                                            const d = String(date.getDate()).padStart(2, '0');
                                            return `${y}-${m}-${d}`;
                                        };

                                        const leavesByDate: Record<string, typeof filteredLeaves> = {};
                                        filteredLeaves.forEach(leave => {
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
                                            <div className="space-y-3">
                                                {/* Day names */}
                                                <div className="grid grid-cols-7 gap-1">
                                                    {dayNames.map((day, idx) => (
                                                        <div key={idx} className="text-center text-xs font-medium text-muted-foreground p-1">
                                                            {day}
                                                        </div>
                                                    ))}
                                                </div>
                                                {/* Calendar days */}
                                                <div className="grid grid-cols-7 gap-1">
                                                    {Array.from({ length: startingDayOfWeek }).map((_, i) => (
                                                        <div key={`empty-${i}`} className="aspect-square" />
                                                    ))}
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
                                                                    aspect-square p-1 rounded flex items-center justify-center text-sm relative transition-all duration-150
                                                                    ${hasLeaves ? 'bg-amber-500 dark:bg-amber-600 font-semibold text-white' : 'text-muted-foreground'}
                                                                    ${isToday ? 'ring-2 ring-primary' : ''}
                                                                    ${isHoveredLeaveDay ? 'ring-2 ring-offset-1 ring-offset-background ring-primary bg-amber-400 dark:bg-amber-500 shadow-lg' : ''}
                                                                `}
                                                            >
                                                                {day}
                                                                {hasLeaves && leavesOnDay.length > 1 && (
                                                                    <div className="absolute -top-0.5 -right-0.5 w-3 h-3 bg-red-500 rounded-full text-[8px] text-white flex items-center justify-center">
                                                                        {leavesOnDay.length}
                                                                    </div>
                                                                )}
                                                            </div>
                                                        );
                                                    })}
                                                </div>
                                                {/* Legend */}
                                                <div className="flex flex-row gap-4 text-xs pt-2 border-t">
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
                                            </div>
                                        );
                                    })()}
                                </div>

                                {/* Employee List - Right Side */}
                                <div className="border rounded-lg p-4 flex flex-col">
                                    <h4 className="text-sm font-semibold mb-3">All Leaves ({filteredLeaves.length})</h4>
                                    <div className="space-y-2 flex-1 max-h-[280px] overflow-y-auto pr-2">
                                        {filteredLeaves.map((leave) => (
                                            <div
                                                key={leave.id}
                                                className="p-3 border rounded-lg hover:bg-accent/50 cursor-pointer transition-colors text-sm"
                                                onClick={() => {
                                                    setActiveDialog('leaveDetail');
                                                    setSelectedVacantSite(leave.id.toString());
                                                }}
                                                onMouseEnter={() => setHoveredLeaveId(leave.id)}
                                                onMouseLeave={() => setHoveredLeaveId(null)}
                                            >
                                                <div className="flex items-start gap-2.5 mb-1">
                                                    {leave.avatar_url ? (
                                                        <img
                                                            src={leave.avatar_url}
                                                            alt={leave.user_name}
                                                            className="h-8 w-8 rounded-full object-cover shrink-0 mt-0.5"
                                                        />
                                                    ) : (
                                                        <div className="h-8 w-8 rounded-full bg-muted flex items-center justify-center shrink-0 mt-0.5">
                                                            <span className="text-xs font-medium text-muted-foreground">
                                                                {leave.user_name.split(',')[0]?.[0] ?? ''}
                                                            </span>
                                                        </div>
                                                    )}
                                                    <div className="flex-1 min-w-0">
                                                        <div className="flex items-start justify-between gap-1">
                                                            <span className="font-medium truncate">{leave.user_name}</span>
                                                            <Badge variant="outline" className="text-xs shrink-0">{leave.leave_type}</Badge>
                                                        </div>
                                                        <div className="text-xs text-muted-foreground">{leave.campaign_name}</div>
                                                    </div>
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {Number(leave.days_requested) === 1
                                                        ? `${new Date(leave.start_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} (1 day)`
                                                        : `${new Date(leave.start_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} - ${new Date(leave.end_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} (${Math.round(Number(leave.days_requested))} days)`
                                                    }
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Attendance Points Section - Hidden for Agent/Utility */}
                {!isRestrictedRole && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <AlertTriangle className="h-5 w-5" />
                                Attendance Points Overview
                            </CardTitle>
                            <CardDescription>Active attendance violations and high-risk employees</CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            {/* Summary Cards */}
                            <div className="grid gap-4 md:grid-cols-4">
                                <StatCard
                                    title="Total Active Points"
                                    value={presenceInsights?.attendancePoints.total_active_points.toFixed(1) || '0.0'}
                                    icon={AlertTriangle}
                                    description="All active violations"
                                    onClick={() => setActiveDialog('pointsBreakdown')}
                                    variant="warning"
                                />
                                <StatCard
                                    title="Total Violations"
                                    value={presenceInsights?.attendancePoints.total_violations || 0}
                                    icon={XCircle}
                                    description="Count of infractions"
                                    onClick={() => setActiveDialog('pointsBreakdown')}
                                />
                                <StatCard
                                    title="High Risk Employees"
                                    value={presenceInsights?.attendancePoints.high_risk_count || 0}
                                    icon={AlertCircle}
                                    description="6+ points"
                                    onClick={() => setActiveDialog('highRiskEmployees')}
                                    variant={(presenceInsights?.attendancePoints.high_risk_count || 0) > 0 ? "danger" : "default"}
                                />
                                <StatCard
                                    title="Points Trend"
                                    value={
                                        <TrendingUp className="h-6 w-6 text-orange-500" />
                                    }
                                    icon={TrendingUp}
                                    description="Last 6 months"
                                    onClick={() => setActiveDialog('pointsTrend')}
                                />
                            </div>

                            {/* High Risk Employees Preview */}
                            {presenceInsights?.attendancePoints.high_risk_employees && presenceInsights.attendancePoints.high_risk_employees.length > 0 && (
                                <div className="space-y-3">
                                    <div className="flex items-center justify-between">
                                        <h3 className="text-lg font-semibold">High Risk Employees (6+ Points)</h3>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => setActiveDialog('highRiskEmployees')}
                                        >
                                            View All
                                        </Button>
                                    </div>
                                    <div className="grid gap-3 md:grid-cols-2">
                                        {presenceInsights.attendancePoints.high_risk_employees.slice(0, 4).map((emp) => (
                                            <Card key={emp.user_id} className="border-red-500/30">
                                                <CardHeader className="pb-3">
                                                    <div className="flex items-start justify-between">
                                                        <div>
                                                            <CardTitle className="text-base">{emp.user_name}</CardTitle>
                                                            <CardDescription>{emp.user_role}</CardDescription>
                                                        </div>
                                                        <Badge variant="destructive" className="text-lg font-bold">
                                                            {emp.total_points} pts
                                                        </Badge>
                                                    </div>
                                                </CardHeader>
                                                <CardContent>
                                                    <div className="text-sm text-muted-foreground">
                                                        {emp.violations_count} violations
                                                    </div>
                                                    <Button
                                                        variant="link"
                                                        size="sm"
                                                        className="px-0 h-auto"
                                                        onClick={() => {
                                                            setActiveDialog('highRiskDetail');
                                                            setSelectedVacantSite(emp.user_id.toString());
                                                        }}
                                                    >
                                                        View Details →
                                                    </Button>
                                                </CardContent>
                                            </Card>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}
            </motion.div>

            {/* ─── Dialogs ──────────────────────────────────────────────── */}

            <DetailDialog
                open={activeDialog === 'leaveDetail'}
                onClose={closeDialog}
                title="Leave Request Details"
            >
                {(() => {
                    const leave = filteredLeaves?.find(l => l.id.toString() === selectedVacantSite);
                    if (!leave) return <div className="text-center text-muted-foreground py-4">Leave not found</div>;
                    return (
                        <div className="space-y-4">
                            <div className="flex items-start gap-4">
                                <Avatar className="h-16 w-16 overflow-hidden rounded-full">
                                    <AvatarImage src={leave.avatar_url} alt={leave.user_name} />
                                    <AvatarFallback className="rounded-full bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white text-lg">
                                        {getInitials(leave.user_name)}
                                    </AvatarFallback>
                                </Avatar>
                                <div className="flex-1">
                                    <div className="text-sm font-medium text-muted-foreground">Employee</div>
                                    <div className="text-lg font-semibold">{leave.user_name}</div>
                                    <div className="text-sm text-muted-foreground">{leave.campaign_name}</div>
                                </div>
                            </div>
                            <Separator />
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <div className="text-sm font-medium text-muted-foreground">Leave Type</div>
                                    <Badge variant="outline" className="mt-1">{leave.leave_type}</Badge>
                                </div>
                                <div>
                                    <div className="text-sm font-medium text-muted-foreground">Duration</div>
                                    <div className="mt-1">{`${Math.round(Number(leave.days_requested))} day${leave.days_requested !== 1 ? 's' : ''}`}</div>
                                </div>
                            </div>
                            <div>
                                <div className="text-sm font-medium text-muted-foreground">Date Range</div>
                                <div className="flex items-center gap-2 mt-1">
                                    <Calendar className="h-4 w-4 text-muted-foreground" />
                                    <span>{new Date(leave.start_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</span>
                                    <span>→</span>
                                    <span>{new Date(leave.end_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</span>
                                </div>
                            </div>
                            {!isRestrictedRole && leave.reason && (
                                <div>
                                    <div className="text-sm font-medium text-muted-foreground">Reason</div>
                                    <div className="mt-1 p-3 bg-muted rounded-md text-sm">
                                        {leave.reason}
                                    </div>
                                </div>
                            )}
                        </div>
                    );
                })()}
            </DetailDialog>

            <DetailDialog
                open={activeDialog === 'highRiskEmployees'}
                onClose={closeDialog}
                title="High Risk Employees"
                description="Employees with 6 or more active attendance points"
            >
                <div className="space-y-3">
                    {(!presenceInsights?.attendancePoints.high_risk_employees || presenceInsights.attendancePoints.high_risk_employees.length === 0) ? (
                        <div className="text-center text-muted-foreground py-8">
                            <CheckCircle2 className="h-12 w-12 mx-auto mb-3 text-green-500" />
                            <p>No high-risk employees at this time</p>
                        </div>
                    ) : (
                        presenceInsights.attendancePoints.high_risk_employees.map((emp) => (
                            <Card key={emp.user_id} className="border-red-500/30 cursor-pointer hover:bg-accent/50 transition-colors" onClick={() => {
                                setActiveDialog('highRiskDetail');
                                setSelectedVacantSite(emp.user_id.toString());
                            }}>
                                <CardHeader className="pb-3">
                                    <div className="flex items-start justify-between">
                                        <div>
                                            <CardTitle className="text-base">{emp.user_name}</CardTitle>
                                            <CardDescription>{emp.user_role}</CardDescription>
                                        </div>
                                        <Badge variant="destructive" className="text-lg font-bold">
                                            {emp.total_points} pts
                                        </Badge>
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    <div className="text-sm text-muted-foreground">
                                        {emp.violations_count} active violations
                                    </div>
                                </CardContent>
                            </Card>
                        ))
                    )}
                </div>
            </DetailDialog>

            <DetailDialog
                open={activeDialog === 'highRiskDetail'}
                onClose={() => {
                    setActiveDialog('highRiskEmployees');
                    setSelectedVacantSite(null);
                }}
                title="Employee Violation Details"
            >
                {(() => {
                    const emp = presenceInsights?.attendancePoints.high_risk_employees.find(e => e.user_id.toString() === selectedVacantSite);
                    if (!emp) return <div>Employee not found</div>;
                    return (
                        <div className="space-y-4">
                            <div className="flex items-start justify-between">
                                <div>
                                    <div className="text-lg font-semibold">{emp.user_name}</div>
                                    <div className="text-sm text-muted-foreground">{emp.user_role}</div>
                                </div>
                                <Badge variant="destructive" className="text-xl font-bold px-4 py-2">
                                    {emp.total_points} points
                                </Badge>
                            </div>
                            <Separator />
                            <div>
                                <div className="text-sm font-medium mb-2">Total Violations: {emp.violations_count}</div>
                                <div className="text-sm text-muted-foreground mb-3">Recent violations (up to 5 shown):</div>
                                <div className="space-y-2">
                                    {emp.points.map((point) => (
                                        <div key={point.id} className="p-3 border rounded-lg space-y-1">
                                            <div className="flex items-start justify-between">
                                                <div className="font-medium text-sm">
                                                    {point.point_type.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}
                                                </div>
                                                <Badge variant="outline">{point.points} pts</Badge>
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {new Date(point.shift_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {point.violation_details}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                Expires: {new Date(point.expires_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                            <Button
                                variant="outline"
                                className="w-full"
                                onClick={() => router.get(attendancePointsShow({ user: emp.user_id }, { query: { show_all: 1 } }).url)}
                            >
                                View Full History
                            </Button>
                            <Button
                                variant="outline"
                                className="w-full"
                                onClick={() => {
                                    setActiveDialog('highRiskEmployees');
                                    setSelectedVacantSite(null);
                                }}
                            >
                                ← Back to List
                            </Button>
                        </div>
                    );
                })()}
            </DetailDialog>

            <DetailDialog
                open={activeDialog === 'pointsBreakdown'}
                onClose={closeDialog}
                title="Points Breakdown by Type"
                description="Distribution of attendance points across violation types"
            >
                <div className="space-y-3">
                    {presenceInsights?.attendancePoints.by_type && (
                        <>
                            <div className="grid gap-3">
                                {[
                                    { key: 'whole_day_absence', label: 'Whole Day Absence (NCNS/FTN)', color: 'bg-red-500' },
                                    { key: 'half_day_absence', label: 'Half Day Absence', color: 'bg-orange-500' },
                                    { key: 'tardy', label: 'Tardy', color: 'bg-yellow-500' },
                                    { key: 'undertime', label: 'Undertime (≤1 hour)', color: 'bg-blue-500' },
                                    { key: 'undertime_more_than_hour', label: 'Undertime (>1 hour)', color: 'bg-purple-500' },
                                ].map(({ key, label, color }) => {
                                    const value = presenceInsights.attendancePoints.by_type[key as keyof typeof presenceInsights.attendancePoints.by_type];
                                    const percentage = presenceInsights.attendancePoints.total_active_points > 0
                                        ? ((value / presenceInsights.attendancePoints.total_active_points) * 100).toFixed(1)
                                        : '0.0';
                                    return (
                                        <div key={key} className="flex items-center justify-between p-3 border rounded-lg">
                                            <div className="flex items-center gap-3">
                                                <div className={`h-3 w-3 rounded-full ${color}`} />
                                                <span className="text-sm font-medium">{label}</span>
                                            </div>
                                            <div className="text-right">
                                                <div className="font-bold">{value.toFixed(1)} pts</div>
                                                <div className="text-xs text-muted-foreground">{percentage}%</div>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                            <Separator />
                            <div className="flex items-center justify-between p-3 bg-muted rounded-lg font-semibold">
                                <span>Total Active Points</span>
                                <span className="text-lg">{presenceInsights.attendancePoints.total_active_points.toFixed(1)}</span>
                            </div>
                        </>
                    )}
                </div>
            </DetailDialog>

            <DetailDialog
                open={activeDialog === 'pointsTrend'}
                onClose={closeDialog}
                title="Attendance Points Trend"
                description="Monthly trend of attendance points over the last 6 months"
            >
                <div className="space-y-4 max-h-[calc(80vh-200px)] overflow-y-auto">
                    <ChartContainer
                        config={{
                            total_points: {
                                label: "Total Points",
                                color: "hsl(25, 95%, 53%)",
                            },
                            violations_count: {
                                label: "Violations",
                                color: "hsl(221, 83%, 53%)",
                            },
                        }}
                        className="h-[250px] w-full"
                    >
                        <ResponsiveContainer width="100%" height="100%">
                            <BarChart data={presenceInsights?.attendancePoints.trend || []} margin={{ left: 0, right: 0, top: 5, bottom: 5 }}>
                                <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                                <XAxis
                                    dataKey="label"
                                    tick={{ fontSize: 11 }}
                                    className="text-muted-foreground"
                                />
                                <YAxis tick={{ fontSize: 11 }} className="text-muted-foreground" width={40} />
                                <ChartTooltip content={<ChartTooltipContent />} />
                                <Bar dataKey="total_points" fill="hsl(25, 95%, 53%)" radius={[4, 4, 0, 0]} />
                            </BarChart>
                        </ResponsiveContainer>
                    </ChartContainer>
                    <Separator />
                    <div>
                        <h4 className="text-sm font-semibold mb-3">Monthly Breakdown</h4>
                        <div className="grid grid-cols-2 gap-3">
                            {presenceInsights?.attendancePoints.trend?.map((item) => (
                                <div key={item.month} className="p-3 border rounded-lg">
                                    <div className="text-sm font-medium">{item.label}</div>
                                    <div className="text-2xl font-bold text-orange-600">{item.total_points}</div>
                                    <div className="text-xs text-muted-foreground">{item.violations_count} violations</div>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </DetailDialog>

            {/* ─── Phase 4: Campaign-Level Analytics (Super Admin / Admin) ─────── */}

            {/* Campaign Presence Comparison */}
            {!isRestrictedRole && campaignPresence && campaignPresence.length > 0 && (
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Users className="h-5 w-5 text-indigo-500" />
                            Campaign Presence Comparison
                        </CardTitle>
                        <CardDescription>Today's presence rate by campaign</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ChartContainer
                            config={{
                                present: { label: 'Present', color: 'hsl(142, 71%, 45%)' },
                                absent: { label: 'Absent', color: 'hsl(0, 84%, 60%)' },
                                on_leave: { label: 'On Leave', color: 'hsl(221, 83%, 53%)' },
                            }}
                            className="h-[300px] w-full"
                        >
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={campaignPresence} layout="vertical" margin={{ left: 0, right: 10, top: 5, bottom: 5 }}>
                                    <CartesianGrid strokeDasharray="3 3" />
                                    <XAxis type="number" tickLine={false} axisLine={false} fontSize={12} />
                                    <YAxis type="category" dataKey="campaign_name" width={120} tickLine={false} axisLine={false} fontSize={11} />
                                    <ChartTooltip content={<ChartTooltipContent />} />
                                    <Bar dataKey="present" stackId="a" fill="hsl(142, 71%, 45%)" radius={[0, 0, 0, 0]} />
                                    <Bar dataKey="absent" stackId="a" fill="hsl(0, 84%, 60%)" radius={[0, 0, 0, 0]} />
                                    <Bar dataKey="on_leave" stackId="a" fill="hsl(221, 83%, 53%)" radius={[0, 4, 4, 0]} />
                                </BarChart>
                            </ResponsiveContainer>
                        </ChartContainer>
                        <div className="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                            {campaignPresence.map((cp) => (
                                <div key={cp.campaign_id} className="flex items-center justify-between rounded-lg border p-3">
                                    <div>
                                        <p className="text-sm font-medium">{cp.campaign_name}</p>
                                        <p className="text-xs text-muted-foreground">{cp.total_scheduled} scheduled</p>
                                    </div>
                                    <Badge variant={cp.presence_rate >= 80 ? 'default' : cp.presence_rate >= 60 ? 'secondary' : 'destructive'}>
                                        {cp.presence_rate}%
                                    </Badge>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            )}

            {/* Points Distribution by Campaign */}
            {!isRestrictedRole && pointsByCampaign && pointsByCampaign.length > 0 && (
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <AlertTriangle className="h-5 w-5 text-orange-500" />
                            Points by Campaign
                        </CardTitle>
                        <CardDescription>Active attendance points distribution across campaigns</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ChartContainer
                            config={{
                                total_points: { label: 'Total Points', color: 'hsl(25, 95%, 53%)' },
                                high_risk_count: { label: 'High Risk', color: 'hsl(0, 84%, 60%)' },
                            }}
                            className="h-[280px] w-full"
                        >
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={pointsByCampaign} margin={{ left: 0, right: 10, top: 5, bottom: 5 }}>
                                    <CartesianGrid strokeDasharray="3 3" />
                                    <XAxis dataKey="campaign_name" tickLine={false} axisLine={false} fontSize={11} />
                                    <YAxis width={35} tickLine={false} axisLine={false} fontSize={12} />
                                    <ChartTooltip content={<ChartTooltipContent />} />
                                    <Bar dataKey="total_points" fill="hsl(25, 95%, 53%)" radius={[4, 4, 0, 0]} barSize={30} name="Total Points" />
                                </BarChart>
                            </ResponsiveContainer>
                        </ChartContainer>
                        <div className="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                            {pointsByCampaign.map((pc) => (
                                <div key={pc.campaign_id} className="rounded-lg border p-3 space-y-1">
                                    <p className="text-sm font-medium">{pc.campaign_name}</p>
                                    <div className="flex items-center gap-4 text-sm text-muted-foreground">
                                        <span>{pc.total_points} pts</span>
                                        <span>{pc.violations_count} violations</span>
                                        <span>{pc.employees_with_points} employees</span>
                                    </div>
                                    {pc.high_risk_count > 0 && (
                                        <Badge variant="destructive" className="text-xs">{pc.high_risk_count} high risk</Badge>
                                    )}
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            )}
        </>
    );
};
