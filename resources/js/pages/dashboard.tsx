import React, { useState, useEffect, useMemo } from 'react';
import { motion } from 'framer-motion';
import { Head, router, usePage, Link } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { DatePicker } from '@/components/ui/date-picker';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import CalendarWithHolidays from '@/components/CalendarWithHolidays';
import { ChartContainer, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';
import { Bar, BarChart, CartesianGrid, XAxis, YAxis, Pie, PieChart, Label, RadialBar, RadialBarChart, PolarGrid, Area, AreaChart, ResponsiveContainer, PolarRadiusAxis } from 'recharts';

import { Monitor, AlertCircle, HardDrive, Wrench, MapPin, Server, XCircle, Calendar, ChevronLeft, ChevronRight, Clock, Loader2, CheckCircle2, ClipboardList, Building2, Users, UserCheck, UserX, UserMinus, AlertTriangle, TrendingUp, Award, ExternalLink } from 'lucide-react';
import type { SharedData, UserRole } from '@/types';

//
import { show as attendancePointsShow } from '@/routes/attendance-points';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

interface DashboardProps {
    totalStations?: {
        total: number;
        bysite: Array<{ site: string; count: number }>;
    };
    noPcs?: {
        total: number;
        stations: Array<{ station: string; site: string; campaign: string }>;
    };
    vacantStations?: {
        total: number;
        bysite: Array<{ site: string; count: number }>;
        stations: Array<{ site: string; station_number: string }>;
    };
    ssdPcs?: {
        total: number;
        details: Array<{ site: string; count: number }>;
    };
    hddPcs?: {
        total: number;
        details: Array<{ site: string; count: number }>;
    };
    dualMonitor?: {
        total: number;
        bysite: Array<{ site: string; count: number }>;
    };
    maintenanceDue?: {
        total: number;
        stations: Array<{ station: string; site: string; dueDate: string; daysOverdue: number }>;
    };
    unassignedPcSpecs?: Array<{
        id: number;
        pc_number: string;
        model: string;
        ram: string;
        ram_gb: number;
        ram_count: number;
        disk: string;
        disk_tb: number;
        disk_count: number;
        processor: string;
        cpu_count: number;
        issue: string | null;
    }>;
    itConcernStats?: {
        pending: number;
        in_progress: number;
        resolved: number;
        bySite?: Array<{
            site: string;
            pending: number;
            in_progress: number;
            resolved: number;
            total: number;
        }>;
    };
    itConcernTrends?: Array<{
        month: string;
        label: string;
        total: number;
        pending: number;
        in_progress: number;
        resolved: number;
    }>;
    attendanceStatistics: {
        total: number;
        on_time: number;
        time_adjustment: number;
        overtime: number;
        undertime: number;
        tardy: number;
        half_day: number;
        ncns: number;
        advised: number;
        needs_verification: number;
    };
    monthlyAttendanceData: Record<string, {
        month: string;
        total: number;
        on_time: number;
        time_adjustment: number;
        tardy: number;
        half_day: number;
        ncns: number;
        advised: number;
    }>;
    dailyAttendanceData: Record<string, Array<{
        month: string;
        day: number;
        total: number;
        on_time: number;
        time_adjustment: number;
        tardy: number;
        half_day: number;
        ncns: number;
        advised: number;
    }>>;
    campaigns?: Array<{
        id: number;
        name: string;
    }>;
    startDate: string;
    endDate: string;
    campaignId?: string;
    verificationFilter: string;
    isRestrictedRole: boolean;
    presenceInsights?: {
        todayPresence: {
            total_scheduled: number;
            present: number;
            absent: number;
            on_leave: number;
            unaccounted: number;
        };
        leaveCalendar: Array<{
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
        }>;
        attendancePoints: {
            total_active_points: number;
            total_violations: number;
            high_risk_count: number;
            high_risk_employees: Array<{
                user_id: number;
                user_name: string;
                user_role: string;
                total_points: number;
                violations_count: number;
                points: Array<{
                    id: number;
                    shift_date: string;
                    point_type: string;
                    points: number;
                    violation_details: string;
                    expires_at: string;
                }>;
            }>;
            by_type: {
                whole_day_absence: number;
                half_day_absence: number;
                tardy: number;
                undertime: number;
                undertime_more_than_hour: number;
            };
            trend: Array<{
                month: string;
                label: string;
                total_points: number;
                violations_count: number;
            }>;
        };
    };
    leaveCredits?: {
        year: number;
        is_eligible: boolean;
        eligibility_date: string | null;
        monthly_rate: number;
        total_earned: number;
        total_used: number;
        balance: number;
    };
    leaveCalendarMonth?: string;
}

interface StatCardProps {
    title: string;
    value: React.ReactNode;
    icon: React.ComponentType<{ className?: string }>;
    description?: string;
    onClick: () => void;
    variant?: 'default' | 'warning' | 'danger' | 'success';
    delay?: number;
}

const StatCard: React.FC<StatCardProps> = ({ title, value, icon: Icon, description, onClick, variant = 'default', delay = 0 }) => {
    const variantStyles = {
        default: 'hover:border-primary/50',
        warning: 'border-orange-500/30 hover:border-orange-500/50',
        danger: 'border-red-500/30 hover:border-red-500/50',
        success: 'border-green-500/30 hover:border-green-500/50'
    };

    return (
        <motion.div
            layout
            initial={{ opacity: 0, y: 20, scale: 0.95 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            whileHover={{ scale: 1.04, y: -4 }}
            transition={{ duration: 0.3, type: 'spring', stiffness: 200, delay }}
        >
            <Card
                className={`cursor-pointer transition-all hover:shadow-lg ${variantStyles[variant]}`}
                onClick={onClick}
            >
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle className="text-sm font-medium">{title}</CardTitle>
                    <Icon className="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                    <div className="text-2xl font-bold">{value}</div>
                    {description && (
                        <p className="text-xs text-muted-foreground mt-1">{description}</p>
                    )}
                </CardContent>
            </Card>
        </motion.div>
    );
};

interface DetailDialogProps {
    open: boolean;
    onClose: () => void;
    title: string;
    description?: string;
    children: React.ReactNode;
}

const DetailDialog: React.FC<DetailDialogProps> = ({ open, onClose, title, description, children }) => {
    return (
        <Dialog open={open} onOpenChange={onClose}>
            <DialogContent className="max-w-2xl max-h-[80vh] overflow-y-auto">
                <motion.div
                    initial={{ opacity: 0, y: 20 }}
                    animate={{ opacity: 1, y: 0 }}
                    exit={{ opacity: 0, y: 20 }}
                    transition={{ duration: 0.3, type: 'spring', stiffness: 200 }}
                >
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        {description && <DialogDescription>{description}</DialogDescription>}
                    </DialogHeader>
                    <div className="mt-4">{children}</div>
                </motion.div>
            </DialogContent>
        </Dialog>
    );
};

// Extend the Window interface to include vacantStationsData
declare global {
    interface Window {
        vacantStationsData?: Array<{ site: string; station_number: string }>;
    }
}

// Define available tabs for each role
type TabType = 'infrastructure' | 'attendance' | 'it-concerns' | 'presence-insights';

const ROLE_TABS: Record<UserRole, TabType[]> = {
    'Super Admin': ['infrastructure', 'attendance', 'presence-insights', 'it-concerns'],
    'Admin': ['attendance', 'presence-insights', 'infrastructure'],
    'IT': ['infrastructure', 'it-concerns', 'attendance'],
    'Team Lead': ['attendance', 'presence-insights'],
    'Agent': ['attendance', 'presence-insights'],
    'HR': ['attendance', 'presence-insights'],
    'Utility': ['attendance'],
};

const TAB_CONFIG: Record<TabType, { label: string; icon: React.ComponentType<{ className?: string }> }> = {
    'infrastructure': { label: 'Infrastructure', icon: Building2 },
    'attendance': { label: 'Attendance', icon: Users },
    'it-concerns': { label: 'IT Concerns', icon: ClipboardList },
    'presence-insights': { label: 'Presence Insights', icon: UserCheck },
};

export default function Dashboard({
    totalStations,
    noPcs,
    vacantStations,
    ssdPcs,
    hddPcs,
    dualMonitor,
    maintenanceDue,
    unassignedPcSpecs,
    itConcernStats,
    itConcernTrends,
    attendanceStatistics,
    monthlyAttendanceData,
    dailyAttendanceData,
    startDate: initialStartDate,
    endDate: initialEndDate,
    campaignId: initialCampaignId,
    verificationFilter: initialVerificationFilter,
    campaigns,
    isRestrictedRole,
    presenceInsights,
    leaveCredits,
    leaveCalendarMonth,
}: DashboardProps) {
    // Get user role from shared data
    const { auth } = usePage<SharedData>().props;
    const userRole: UserRole = auth?.user?.role || 'Agent';

    // Get available tabs based on user role
    const availableTabs = useMemo(() => ROLE_TABS[userRole] || ['attendance'], [userRole]);
    const defaultTab = availableTabs[0];

    const [activeTab, setActiveTab] = useState<TabType>(defaultTab);
    const [activeDialog, setActiveDialog] = useState<string | null>(null);
    const [dateRange, setDateRange] = useState({
        start: initialStartDate,
        end: initialEndDate,
    });
    const [selectedCampaignId, setSelectedCampaignId] = useState<string>(initialCampaignId || "all");
    const [verificationFilter, setVerificationFilter] = useState<string>(initialVerificationFilter || "verified");
    const [radialChartIndex, setRadialChartIndex] = useState(0);
    const [selectedMonth, setSelectedMonth] = useState<string>("all");
    const concernStatusConfig = [
        { key: 'pending', label: 'Pending' },
        { key: 'in_progress', label: 'In Progress' },
        { key: 'resolved', label: 'Resolved' },
    ];
    const itTrendSlides = [
        { key: 'total', label: 'All Concerns Trend', description: 'Overview of all IT concerns over time', color: 'hsl(280, 65%, 60%)' },
        { key: 'pending', label: 'Pending Trend', description: 'Monitors new issues awaiting action', color: 'hsl(45, 93%, 47%)' },
        { key: 'in_progress', label: 'In-Progress Trend', description: 'Tracks workload currently being handled', color: 'hsl(221, 83%, 53%)' },
        { key: 'resolved', label: 'Resolved Trend', description: 'Measures closure rate per month', color: 'hsl(142, 71%, 45%)' },
    ];

    const [attendanceTrendSlideIndex, setAttendanceTrendSlideIndex] = useState(0);
    const attendanceTrendSlides = [
        { key: 'all', label: 'All Status', description: 'Overview of all attendance statuses', color: 'hsl(220, 10%, 40%)' },
        { key: 'on_time', label: 'On Time', description: 'Employees arriving on schedule', color: 'hsl(142, 71%, 45%)' },
        { key: 'time_adjustment', label: 'Time Adjustment', description: 'Overtime and undertime adjustments', color: 'hsl(280, 65%, 60%)' },
        { key: 'tardy', label: 'Tardy', description: 'Late arrivals', color: 'hsl(45, 93%, 47%)' },
        { key: 'half_day', label: 'Half Day', description: 'Half day leaves', color: 'hsl(25, 95%, 53%)' },
        { key: 'ncns', label: 'NCNS', description: 'No Call No Show', color: 'hsl(0, 84%, 60%)' },
        { key: 'advised', label: 'Advised', description: 'Advised absences', color: 'hsl(221, 83%, 53%)' },
    ];
    const activeAttendanceSlide = attendanceTrendSlides[attendanceTrendSlideIndex];
    const activeAttendanceGradientId = `attendance-trend-${activeAttendanceSlide.key}`;

    const handleAttendanceTrendPrev = () => {
        setAttendanceTrendSlideIndex((prev) => (prev === 0 ? attendanceTrendSlides.length - 1 : prev - 1));
    };

    const handleAttendanceTrendNext = () => {
        setAttendanceTrendSlideIndex((prev) => (prev === attendanceTrendSlides.length - 1 ? 0 : prev + 1));
    };

    // Generate month options from the actual data returned by backend (monthlyAttendanceData keys)
    // This ensures we only show months that have data in the selected date range
    const monthOptions = (() => {
        const monthKeys = Object.keys(monthlyAttendanceData).sort();
        return monthKeys.map(key => {
            // Convert YYYY-MM to "Mon YYYY" format (e.g., "2025-11" -> "Nov 2025")
            const [year, month] = key.split('-');
            const date = new Date(parseInt(year), parseInt(month) - 1, 1);
            return date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
        });
    })();

    // Calculate filtered statistics based on selected month
    const filteredStatistics = (() => {
        // If no month filter applied, return original statistics
        if (selectedMonth === "all") {
            return attendanceStatistics;
        }

        // Parse the selected month to get the month key (e.g., "Nov 2025" -> "2025-11")
        const [monthStr, yearStr] = selectedMonth.split(' ');
        const monthDate = new Date(`${monthStr} 1, ${yearStr}`);
        const selectedYear = monthDate.getFullYear();
        const selectedMonthNum = monthDate.getMonth() + 1;
        const monthKey = `${selectedYear}-${String(selectedMonthNum).padStart(2, '0')}`;

        // Get the actual monthly data from backend
        const monthRecord = monthlyAttendanceData[monthKey];

        if (!monthRecord) {
            // No data for selected month
            return {
                total: 0,
                on_time: 0,
                time_adjustment: 0,
                tardy: 0,
                half_day: 0,
                ncns: 0,
                advised: 0,
                needs_verification: 0,
            };
        }

        // Return actual data for the selected month
        return {
            total: Number(monthRecord.total || 0),
            on_time: Number(monthRecord.on_time || 0),
            time_adjustment: Number(monthRecord.time_adjustment || 0),
            tardy: Number(monthRecord.tardy || 0),
            half_day: Number(monthRecord.half_day || 0),
            ncns: Number(monthRecord.ncns || 0),
            advised: Number(monthRecord.advised || 0),
            needs_verification: 0, // This field is only available in the overall statistics
        };
    })();

    const radialChartData = [
        {
            name: "On-Time",
            label: "On-Time Rate",
            value: filteredStatistics.total > 0
                ? ((filteredStatistics.on_time / filteredStatistics.total) * 100)
                : 0,
            fill: "hsl(142, 71%, 45%)",
            count: filteredStatistics.on_time,
        },
        {
            name: "Time Adjustment",
            label: "Time Adjustment Rate",
            value: filteredStatistics.total > 0
                ? ((filteredStatistics.time_adjustment / filteredStatistics.total) * 100)
                : 0,
            fill: "hsl(280, 65%, 60%)",
            count: filteredStatistics.time_adjustment,
        },
        {
            name: "Tardy",
            label: "Tardy Rate",
            value: filteredStatistics.total > 0
                ? ((filteredStatistics.tardy / filteredStatistics.total) * 100)
                : 0,
            fill: "hsl(45, 93%, 47%)",
            count: filteredStatistics.tardy,
        },
        {
            name: "Half Day",
            label: "Half Day Rate",
            value: filteredStatistics.total > 0
                ? ((filteredStatistics.half_day / filteredStatistics.total) * 100)
                : 0,
            fill: "hsl(25, 95%, 53%)",
            count: filteredStatistics.half_day,
        },
        {
            name: "NCNS",
            label: "NCNS Rate",
            value: filteredStatistics.total > 0
                ? ((filteredStatistics.ncns / filteredStatistics.total) * 100)
                : 0,
            fill: "hsl(0, 84%, 60%)",
            count: filteredStatistics.ncns,
        },
        {
            name: "Advised",
            label: "Advised Rate",
            value: filteredStatistics.total > 0
                ? ((filteredStatistics.advised / filteredStatistics.total) * 100)
                : 0,
            fill: "hsl(221, 83%, 53%)",
            count: filteredStatistics.advised,
        },
    ];

    const currentRadialData = radialChartData[radialChartIndex];

    const handlePrevStatus = () => {
        setRadialChartIndex((prev) => (prev === 0 ? radialChartData.length - 1 : prev - 1));
    };

    const handleNextStatus = () => {
        setRadialChartIndex((prev) => (prev === radialChartData.length - 1 ? 0 : prev + 1));
    };

    const goToItConcerns = (status?: 'pending' | 'in_progress' | 'resolved') => {
        const params = status ? { status } : {};
        router.get('/form-requests/it-concerns', params);
    };

    const concernStats = itConcernStats ?? { pending: 0, in_progress: 0, resolved: 0 };
    const concernBreakdown = itConcernStats?.bySite ?? [];
    const totalConcerns = concernStats.pending + concernStats.in_progress + concernStats.resolved;
    const itTrends = itConcernTrends ?? [];
    const [itTrendSlideIndex, setItTrendSlideIndex] = useState(0);
    const activeItTrendSlide = itTrendSlides[itTrendSlideIndex];
    const latestItTrend = itTrends.length > 0 ? itTrends[itTrends.length - 1] : null;

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
    //
    const [selectedVacantSite, setSelectedVacantSite] = useState<string | null>(null);
    const [selectedNoPcSite, setSelectedNoPcSite] = useState<string | null>(null);
    const [hoveredLeaveId, setHoveredLeaveId] = useState<number | null>(null);
    const [calendarDate, setCalendarDate] = useState(() => {
        // Initialize from server prop if available, otherwise use current date
        if (leaveCalendarMonth) {
            return new Date(leaveCalendarMonth);
        }
        return new Date();
    });
    const [presenceDate, setPresenceDate] = useState(new Date().toISOString().split('T')[0]);

    const closeDialog = () => {
        setActiveDialog(null);
        setSelectedVacantSite(null);
        setSelectedNoPcSite(null);
    };

    const handleTrendPrev = () => {
        setItTrendSlideIndex((prev) => (prev === 0 ? itTrendSlides.length - 1 : prev - 1));
    };

    const handleTrendNext = () => {
        setItTrendSlideIndex((prev) => (prev === itTrendSlides.length - 1 ? 0 : prev + 1));
    };

    const handleCalendarMonthChange = (newDate: Date) => {
        setCalendarDate(newDate);
        // Format date as YYYY-MM-DD in local time to avoid timezone issues
        const year = newDate.getFullYear();
        const month = String(newDate.getMonth() + 1).padStart(2, '0');
        const day = String(newDate.getDate()).padStart(2, '0');
        const dateStr = `${year}-${month}-${day}`;

        router.reload({
            data: {
                leave_calendar_month: dateStr,
            },
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

    const handlePresenceDateChange = (newDate: string) => {
        setPresenceDate(newDate);
        router.reload({
            data: {
                presence_date: newDate,
            },
            only: ["presenceInsights"],
        });
    };

    // Get leaves from backend (already filtered by selected month)
    const filteredLeaves = useMemo(() => {
        return presenceInsights?.leaveCalendar || [];
    }, [presenceInsights?.leaveCalendar]);

    // Calculate attendance trend data
    const attendanceTrendData = (() => {
        // If a specific month is selected, show daily data for that month
        if (selectedMonth !== "all") {
            // Parse the selected month (e.g., "Nov 2025")
            const [monthStr, yearStr] = selectedMonth.split(' ');

            const monthDate = new Date(`${monthStr} 1, ${yearStr}`);
            // Format as YYYY-MM using local date components
            const selectedYear = monthDate.getFullYear();
            const selectedMonthNum = monthDate.getMonth() + 1;
            const monthKey = `${selectedYear}-${String(selectedMonthNum).padStart(2, '0')}`;

            // Get daily data for this month from backend
            const dailyRecords = dailyAttendanceData[monthKey] || [];

            // Get the number of days in the selected month
            const daysInMonth = new Date(selectedYear, selectedMonthNum, 0).getDate();

            // Create array with all days, filling in zeros for days without data
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
            // Show monthly data when "All Months" is selected
            // Use the actual data from backend (monthlyAttendanceData) instead of generating from date range
            const monthKeys = Object.keys(monthlyAttendanceData).sort();

            return monthKeys.map(monthKey => {
                const monthRecord = monthlyAttendanceData[monthKey];
                // Convert YYYY-MM to "Mon YYYY" format for display
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

    // calendar and holidays handled inside CalendarWithHolidays component

    const [currentDateTime, setCurrentDateTime] = useState<{ date: string; time: string }>({ date: '', time: '' });

    useEffect(() => {
        const updateDateTime = () => {
            const now = new Date();
            const date = now.toLocaleDateString("en-US", {
                weekday: "long",
                year: "numeric",
                month: "long",
                day: "numeric",
            });
            const time = now.toLocaleTimeString("en-US", {
                hour: "2-digit",
                minute: "2-digit",
                hour12: true,
            });
            setCurrentDateTime({ date, time });
        };
        updateDateTime();
        const interval = setInterval(updateDateTime, 10000); // update every 10s
        return () => clearInterval(interval);
    }, []);

    const VacantStationNumbers: React.FC<{ site: string; onBack: () => void }> = ({ site, onBack }) => {
        const stationNumbers = vacantStations?.stations
            ? vacantStations.stations
                .filter((s) => s.site === site)
                .map((s) => s.station_number)
            : [];

        return (
            <div>
                <button className="mb-4 text-sm text-primary underline" onClick={onBack}>&larr; Back to sites</button>
                {stationNumbers.length === 0 ? (
                    <div className="text-center text-muted-foreground py-8">No vacant stations found for {site}</div>
                ) : (
                    <div>
                        <div className="mb-2 font-semibold">Vacant Station Numbers:</div>
                        <div className="flex flex-wrap gap-2">
                            {stationNumbers.map((num, idx) => (
                                <Badge key={idx} variant="outline">{num}</Badge>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        );
    };

    const NoPcStationNumbers: React.FC<{ site: string; onBack: () => void }> = ({ site, onBack }) => {
        const stationNumbers = noPcs?.stations
            ? noPcs.stations
                .filter((s) => s.site === site)
                .map((s) => s.station)
            : [];

        return (
            <div>
                <button className="mb-4 text-sm text-primary underline" onClick={onBack}>&larr; Back to sites</button>
                {stationNumbers.length === 0 ? (
                    <div className="text-center text-muted-foreground py-8">No stations without PCs found for {site}</div>
                ) : (
                    <div>
                        <div className="mb-2 font-semibold">Stations Without PCs:</div>
                        <div className="flex flex-wrap gap-2">
                            {stationNumbers.map((num, idx) => (
                                <Badge key={idx} variant="outline">{num}</Badge>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <motion.div
                className="p-4 md:p-6 space-y-6"
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                transition={{ duration: 0.5 }}
            >
                {/* New Header with Date/Time */}
                <motion.div
                    className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
                    initial={{ opacity: 0, y: -20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.5, delay: 0.1 }}
                >
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Dashboard</h1>
                        <p className="text-muted-foreground">
                            {isRestrictedRole
                                ? "Your personal overview"
                                : "System overview and analytics"
                            }
                        </p>
                    </div>
                    <button
                        onClick={() => setActiveDialog('dateTime')}
                        className="group flex items-center gap-3 px-4 py-3 rounded-lg border bg-card hover:bg-accent hover:border-primary/50 transition-all cursor-pointer"
                    >
                        <Calendar className="h-5 w-5 text-muted-foreground group-hover:text-primary transition-colors" />
                        <div className="text-left">
                            <div className="text-sm font-medium">{currentDateTime.date}</div>
                            <div className="text-lg font-bold text-primary">{currentDateTime.time}</div>
                        </div>
                    </button>
                </motion.div>

                {/* Tabbed Navigation */}
                <Tabs value={activeTab} onValueChange={(value) => setActiveTab(value as TabType)} className="space-y-6">
                    <motion.div
                        initial={{ opacity: 0, y: 10 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.3, delay: 0.2 }}
                    >
                        <TabsList className="grid w-full max-w-3xl" style={{ gridTemplateColumns: `repeat(${availableTabs.length}, 1fr)` }}>
                            {availableTabs.map((tab) => {
                                const config = TAB_CONFIG[tab];
                                const Icon = config.icon;
                                return (
                                    <TabsTrigger key={tab} value={tab} className="flex items-center gap-2">
                                        <Icon className="h-4 w-4" />
                                        <span className="hidden sm:inline">{config.label}</span>
                                    </TabsTrigger>
                                );
                            })}
                        </TabsList>
                    </motion.div>

                    {/* Infrastructure Tab */}
                    {availableTabs.includes('infrastructure') && (
                        <TabsContent value="infrastructure" className="space-y-6">
                            <motion.div
                                initial={{ opacity: 0, y: 20 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{ duration: 0.3 }}
                            >
                                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                                    {/* Total Stations */}
                                    <StatCard
                                        title="Total Stations"
                                        value={totalStations?.total || 0}
                                        icon={Server}
                                        description="Click for breakdown by site"
                                        onClick={() => setActiveDialog('stations')}
                                    />

                                    {/* Available PC Specs */}
                                    <StatCard
                                        title="Available PCs"
                                        value={unassignedPcSpecs?.length || 0}
                                        icon={Server}
                                        description="PC specs not assigned to any station"
                                        onClick={() => setActiveDialog('availablePcs')}
                                        variant={(unassignedPcSpecs?.length || 0) > 0 ? "success" : "default"}
                                    />

                                    {/* No PCs */}
                                    <StatCard
                                        title="Stations Without PCs"
                                        value={noPcs?.total || 0}
                                        icon={AlertCircle}
                                        description="Requires PC assignment"
                                        onClick={() => setActiveDialog('noPcs')}
                                        variant="warning"
                                    />

                                    {/* Vacant Stations */}
                                    <StatCard
                                        title="Vacant Stations"
                                        value={vacantStations?.total || 0}
                                        icon={XCircle}
                                        description="Available for deployment"
                                        onClick={() => setActiveDialog('vacantStations')}
                                    />

                                    {/* PCs with SSD & HDD Combined */}
                                    <StatCard
                                        title="PCs with SSD & HDD"
                                        value={
                                            <div className="flex flex-row gap-2">
                                                <span>
                                                    <span className="font-semibold">{hddPcs?.total || 0}</span>
                                                    <span className="text-xs text-muted-foreground ml-1">HDD</span>
                                                </span>
                                                <span>
                                                    <span className="font-semibold text-green-600 dark:text-green-400">{ssdPcs?.total || 0}</span>
                                                    <span className="text-xs text-muted-foreground ml-1">SSD</span>
                                                </span>
                                            </div>
                                        }
                                        icon={HardDrive}
                                        description="Solid State & Hard Disk Drives"
                                        onClick={() => setActiveDialog('diskPcs')}
                                        variant="success"
                                    />

                                    {/* Dual Monitor */}
                                    <StatCard
                                        title="Dual Monitor Setups"
                                        value={dualMonitor?.total || 0}
                                        icon={Monitor}
                                        description="Stations with 2 monitors"
                                        onClick={() => setActiveDialog('dualMonitor')}
                                    />

                                    {/* Maintenance Due */}
                                    <StatCard
                                        title="Maintenance Due"
                                        value={maintenanceDue?.total || 0}
                                        icon={Wrench}
                                        description="Requires attention"
                                        onClick={() => setActiveDialog('maintenanceDue')}
                                        variant={(maintenanceDue?.total || 0) > 0 ? "danger" : "default"}
                                    />
                                </div>
                            </motion.div>
                        </TabsContent>
                    )}

                    {/* IT Concerns Tab */}
                    {availableTabs.includes('it-concerns') && (
                        <TabsContent value="it-concerns" className="space-y-6">
                            <motion.div
                                initial={{ opacity: 0, y: 20 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{ duration: 0.3 }}
                            >
                                {/* IT Concerns Summary Cards */}
                                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4 mb-6">
                                    <StatCard
                                        title="Total Concerns"
                                        value={totalConcerns}
                                        icon={ClipboardList}
                                        description="Click for per-site breakdown"
                                        onClick={() => setActiveDialog('itConcernsBySite')}
                                        variant={totalConcerns > 0 ? 'success' : 'default'}
                                    />
                                    <StatCard
                                        title="Pending"
                                        value={concernStats.pending}
                                        icon={Clock}
                                        description="Awaiting acknowledgement"
                                        onClick={() => goToItConcerns('pending')}
                                        variant={concernStats.pending > 0 ? 'warning' : 'default'}
                                    />
                                    <StatCard
                                        title="In Progress"
                                        value={concernStats.in_progress}
                                        icon={Loader2}
                                        description="Currently being handled"
                                        onClick={() => goToItConcerns('in_progress')}
                                    />
                                    <StatCard
                                        title="Resolved"
                                        value={concernStats.resolved}
                                        icon={CheckCircle2}
                                        description="Closed this period"
                                        onClick={() => goToItConcerns('resolved')}
                                        variant="success"
                                    />
                                </div>

                                {/* IT Concerns Trend Chart */}
                                <Card>
                                    <CardHeader className="pb-0">
                                        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                                            <div>
                                                <CardTitle className="text-base">IT Concern Trends</CardTitle>
                                                <CardDescription className="text-xs">
                                                    {activeItTrendSlide.description}
                                                </CardDescription>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <div className="text-sm font-medium">
                                                    {activeItTrendSlide.label}
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <button
                                                        type="button"
                                                        onClick={handleTrendPrev}
                                                        className="rounded-full border px-2 py-1 text-xs hover:bg-muted"
                                                        aria-label="Previous trend"
                                                    >
                                                        <ChevronLeft className="h-4 w-4" />
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={handleTrendNext}
                                                        className="rounded-full border px-2 py-1 text-xs hover:bg-muted"
                                                        aria-label="Next trend"
                                                    >
                                                        <ChevronRight className="h-4 w-4" />
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </CardHeader>
                                    <CardContent className="pt-4">
                                        {itTrends.length === 0 ? (
                                            <div className="py-10 text-center text-muted-foreground">
                                                No IT concern activity recorded for the selected window.
                                            </div>
                                        ) : (
                                            <>
                                                <ChartContainer
                                                    config={{
                                                        [activeItTrendSlide.key]: {
                                                            label: activeItTrendSlide.label,
                                                            color: activeItTrendSlide.color,
                                                        }
                                                    }}
                                                    className="h-[320px] w-full"
                                                >
                                                    <ResponsiveContainer width="100%" height="100%">
                                                        <AreaChart data={itTrends} margin={{ left: 10, right: 10 }}>
                                                            <defs>
                                                                <linearGradient id={`it-trend-dedicated-${activeItTrendSlide.key}`} x1="0" y1="0" x2="0" y2="1">
                                                                    <stop offset="5%" stopColor={activeItTrendSlide.color} stopOpacity={0.8} />
                                                                    <stop offset="95%" stopColor={activeItTrendSlide.color} stopOpacity={0.05} />
                                                                </linearGradient>
                                                            </defs>
                                                            <CartesianGrid strokeDasharray="3 3" />
                                                            <XAxis dataKey="label" tickLine={false} axisLine={false} fontSize={12} interval="preserveStartEnd" />
                                                            <YAxis allowDecimals={false} width={40} tickLine={false} axisLine={false} fontSize={12} />
                                                            <ChartTooltip
                                                                cursor={false}
                                                                content={<ChartTooltipContent />}
                                                            />
                                                            <Area
                                                                type="monotone"
                                                                dataKey={activeItTrendSlide.key as 'total' | 'pending' | 'in_progress' | 'resolved'}
                                                                stroke={activeItTrendSlide.color}
                                                                fill={`url(#it-trend-dedicated-${activeItTrendSlide.key})`}
                                                                strokeWidth={2}
                                                                activeDot={{ r: 5 }}
                                                            />
                                                        </AreaChart>
                                                    </ResponsiveContainer>
                                                </ChartContainer>
                                                <div className="mt-4 flex flex-wrap items-center justify-between gap-4 text-sm">
                                                    <div>
                                                        <p className="text-muted-foreground text-xs uppercase">Latest Month</p>
                                                        <p className="font-semibold">{latestItTrend?.label ?? 'N/A'}</p>
                                                    </div>
                                                    <div className="flex flex-wrap gap-4">
                                                        <div>
                                                            <p className="text-muted-foreground text-xs uppercase">Total</p>
                                                            <p className="font-semibold">{latestItTrend?.total ?? 0}</p>
                                                        </div>
                                                        <div>
                                                            <p className="text-muted-foreground text-xs uppercase">Pending</p>
                                                            <p className="font-semibold">{latestItTrend?.pending ?? 0}</p>
                                                        </div>
                                                        <div>
                                                            <p className="text-muted-foreground text-xs uppercase">In Progress</p>
                                                            <p className="font-semibold">{latestItTrend?.in_progress ?? 0}</p>
                                                        </div>
                                                        <div>
                                                            <p className="text-muted-foreground text-xs uppercase">Resolved</p>
                                                            <p className="font-semibold">{latestItTrend?.resolved ?? 0}</p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </>
                                        )}
                                    </CardContent>
                                </Card>
                            </motion.div>
                        </TabsContent>
                    )}

                    {/* Presence Insights Tab */}
                    {availableTabs.includes('presence-insights') && (
                        <TabsContent value="presence-insights" className="space-y-6">
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

                                                        // Helper to format date as YYYY-MM-DD in local time
                                                        const formatDateKey = (date: Date) => {
                                                            const y = date.getFullYear();
                                                            const m = String(date.getMonth() + 1).padStart(2, '0');
                                                            const d = String(date.getDate()).padStart(2, '0');
                                                            return `${y}-${m}-${d}`;
                                                        };

                                                        // Get leaves by date (only for days within this month)
                                                        const leavesByDate: Record<string, typeof filteredLeaves> = {};
                                                        filteredLeaves.forEach(leave => {
                                                            // Parse dates - handle both YYYY-MM-DD and ISO formats
                                                            const startStr = leave.start_date.split('T')[0];
                                                            const endStr = leave.end_date.split('T')[0];
                                                            const [sy, sm, sd] = startStr.split('-').map(Number);
                                                            const [ey, em, ed] = endStr.split('-').map(Number);
                                                            const start = new Date(sy, sm - 1, sd);
                                                            const end = new Date(ey, em - 1, ed);

                                                            // Clamp to the displayed month
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
                                                                    {/* Empty cells for days before month starts */}
                                                                    {Array.from({ length: startingDayOfWeek }).map((_, i) => (
                                                                        <div key={`empty-${i}`} className="aspect-square" />
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
                                                    <div className="space-y-2 flex-1 overflow-y-auto pr-2">
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
                                                                <div className="flex items-start justify-between gap-2 mb-1">
                                                                    <div>
                                                                        <span className="font-medium">{leave.user_name}</span>
                                                                        <div className="text-xs text-muted-foreground">{leave.campaign_name}</div>
                                                                    </div>
                                                                    <Badge variant="outline" className="text-xs shrink-0">{leave.leave_type}</Badge>
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
                        </TabsContent>
                    )}

                    {/* Attendance Tab */}
                    {availableTabs.includes('attendance') && (
                        <TabsContent value="attendance" className="space-y-6">
                            <motion.div
                                initial={{ opacity: 0, y: 20 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{ duration: 0.3 }}
                                className="space-y-6"
                            >
                                {/* Attendance Filters */}
                                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <h3 className="text-lg font-semibold">Attendance Overview</h3>
                                        <p className="text-sm text-muted-foreground">
                                            {isRestrictedRole
                                                ? "Your personal attendance for the selected period"
                                                : "Overview of attendance for the selected period"
                                            }
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        {!isRestrictedRole && campaigns && campaigns.length > 0 && (
                                            <Select value={selectedCampaignId || "all"} onValueChange={(value) => setSelectedCampaignId(value === "all" ? "" : value)}>
                                                <SelectTrigger className="w-[160px]">
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
                                        <Select value={verificationFilter} onValueChange={setVerificationFilter}>
                                            <SelectTrigger className="w-[150px]">
                                                <SelectValue placeholder="Verification" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="all">All Records</SelectItem>
                                                <SelectItem value="verified">Verified Only</SelectItem>
                                                <SelectItem value="non_verified">Non-Verified</SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <DatePicker
                                            value={dateRange.start}
                                            onChange={(value) => setDateRange({ ...dateRange, start: value })}
                                            placeholder="Start date"
                                        />
                                        <span className="text-muted-foreground text-sm">to</span>
                                        <DatePicker
                                            value={dateRange.end}
                                            onChange={(value) => setDateRange({ ...dateRange, end: value })}
                                            placeholder="End date"
                                        />
                                        <Button onClick={handleDateRangeChange} size="sm">
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
                                                    on_time: {
                                                        label: "On Time",
                                                        color: "hsl(142, 71%, 45%)",
                                                    },
                                                    time_adjustment: {
                                                        label: "Time Adjustment",
                                                        color: "hsl(280, 65%, 60%)",
                                                    },
                                                    tardy: {
                                                        label: "Tardy",
                                                        color: "hsl(45, 93%, 47%)",
                                                    },
                                                    half_day: {
                                                        label: "Half Day",
                                                        color: "hsl(25, 95%, 53%)",
                                                    },
                                                    ncns: {
                                                        label: "NCNS",
                                                        color: "hsl(0, 84%, 60%)",
                                                    },
                                                    advised: {
                                                        label: "Advised",
                                                        color: "hsl(221, 83%, 53%)",
                                                    },
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
                                                config={{
                                                    count: {
                                                        label: "Records",
                                                    },
                                                }}
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
                                                            ? attendanceTrendSlides.slice(1).reduce((acc, slide) => ({ ...acc, [slide.key]: { label: slide.label, color: slide.color } }), {})
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
                                                                    attendanceTrendSlides.slice(1).map(slide => (
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
                                                                attendanceTrendSlides.slice(1).map(slide => (
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
                            </motion.div>
                        </TabsContent>
                    )}
                </Tabs>

                <DetailDialog
                    open={activeDialog === 'itConcernsBySite'}
                    onClose={closeDialog}
                    title="IT Concerns by Site"
                    description="Pending, in-progress, and resolved concerns grouped per site"
                >
                    {concernBreakdown.length === 0 ? (
                        <div className="py-6 text-center text-muted-foreground">
                            No IT concerns recorded yet.
                        </div>
                    ) : (
                        <div className="space-y-4">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <p className="text-sm text-muted-foreground">
                                    Totals reflect all concern statuses with site-specific counts.
                                </p>
                                <button
                                    className="text-sm font-medium text-primary underline"
                                    onClick={() => goToItConcerns()}
                                >
                                    View IT Concerns List
                                </button>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="min-w-full border text-sm">
                                    <thead>
                                        <tr className="bg-muted">
                                            <th className="px-3 py-2 text-left font-semibold">IT Concerns</th>
                                            {concernBreakdown.map((site) => (
                                                <th key={site.site} className="px-3 py-2 text-left font-semibold whitespace-nowrap">
                                                    {site.site}
                                                </th>
                                            ))}
                                            <th className="px-3 py-2 text-left font-semibold">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {concernStatusConfig.map((status) => (
                                            <tr key={status.key} className="border-t">
                                                <td className="px-3 py-2 font-medium">{status.label}</td>
                                                {concernBreakdown.map((site) => (
                                                    <td
                                                        key={`${site.site}-${status.key}`}
                                                        className="px-3 py-2"
                                                    >
                                                        {site[status.key as 'pending' | 'in_progress' | 'resolved']}
                                                    </td>
                                                ))}
                                                <td className="px-3 py-2 font-semibold">
                                                    {status.key === 'pending'
                                                        ? concernStats.pending
                                                        : status.key === 'in_progress'
                                                            ? concernStats.in_progress
                                                            : concernStats.resolved}
                                                </td>
                                            </tr>
                                        ))}
                                        <tr className="border-t bg-muted/50">
                                            <td className="px-3 py-2 font-semibold">Total</td>
                                            {concernBreakdown.map((site) => (
                                                <td key={`${site.site}-total`} className="px-3 py-2 font-semibold">
                                                    {site.total}
                                                </td>
                                            ))}
                                            <td className="px-3 py-2 font-bold">{totalConcerns}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}
                </DetailDialog>

                <DetailDialog
                    open={activeDialog === 'dateTime'}
                    onClose={closeDialog}
                    title="Calendar"
                    description="View the current month and date. Holidays are highlighted."
                >
                    <div className="flex flex-col items-center py-4 w-full">
                        <CalendarWithHolidays countryCode={['PH', 'US']} width={420} />
                    </div>
                </DetailDialog>

                <DetailDialog
                    open={activeDialog === 'stations'}
                    onClose={closeDialog}
                    title="Stations Breakdown by Site"
                    description="Breakdown of all stations by site"
                >
                    <div className="space-y-3">
                        {(totalStations?.bysite || []).map((site, idx) => (
                            <div key={idx} className="flex items-center justify-between p-3 rounded-lg border">
                                <div className="flex items-center gap-2">
                                    <MapPin className="h-4 w-4 text-muted-foreground" />
                                    <span className="font-medium">{site.site}</span>
                                </div>
                                <Badge variant="secondary">{site.count} stations</Badge>
                            </div>
                        ))}
                        <Separator />
                        <div className="flex items-center justify-between p-3 bg-muted rounded-lg">
                            <span className="font-semibold">Total</span>
                            <Badge>{totalStations?.total || 0} stations</Badge>
                        </div>
                    </div>
                </DetailDialog>

                <DetailDialog
                    open={activeDialog === 'availablePcs'}
                    onClose={closeDialog}
                    title="Available PC Specs"
                    description="PC specs not assigned to any station"
                >
                    <div className="space-y-2">
                        {(!unassignedPcSpecs || unassignedPcSpecs.length === 0) ? (
                            <div className="text-center text-muted-foreground py-8">
                                All PC specs are assigned to stations
                            </div>
                        ) : (
                            unassignedPcSpecs.map((pc) => (
                                <div key={pc.id} className="flex flex-col md:flex-row md:items-center justify-between p-3 rounded-lg border">
                                    <div>
                                        <div className="font-medium">{pc.pc_number} - {pc.model}</div>
                                        <div className="text-xs text-muted-foreground">
                                            RAM: {pc.ram_gb} GB ({pc.ram_count} module{pc.ram_count !== 1 ? 's' : ''})
                                            | Disk: {pc.disk_tb} TB ({pc.disk_count} drive{pc.disk_count !== 1 ? 's' : ''})
                                            | CPU: {pc.processor} ({pc.cpu_count} processor{pc.cpu_count !== 1 ? 's' : ''})
                                        </div>
                                        {pc.issue && <div className="text-xs text-red-500">Issue: {pc.issue}</div>}
                                    </div>
                                </div>
                            ))
                        )}
                    </div>
                </DetailDialog>

                <DetailDialog
                    open={activeDialog === 'noPcs'}
                    onClose={closeDialog}
                    title={selectedNoPcSite ? `Stations Without PCs in ${selectedNoPcSite}` : "Stations Without PCs"}
                    description={selectedNoPcSite ? "Station numbers needing PC assignment" : "Stations that need PC assignment"}
                >
                    {!selectedNoPcSite ? (
                        <div className="space-y-2">
                            {(!noPcs || noPcs.total === 0) ? (
                                <div className="text-center text-muted-foreground py-8">
                                    All stations have PCs assigned
                                </div>
                            ) : (
                                Array.from(new Set(noPcs.stations.map(s => s.site))).map((site, idx) => (
                                    <div
                                        key={idx}
                                        className="flex items-center justify-between p-3 rounded-lg border cursor-pointer hover:bg-muted"
                                        onClick={() => setSelectedNoPcSite(site)}
                                    >
                                        <div className="flex items-center gap-2">
                                            <MapPin className="h-4 w-4 text-muted-foreground" />
                                            <span className="font-medium">{site}</span>
                                        </div>
                                        <Badge variant="secondary">{
                                            noPcs.stations.filter(s => s.site === site).length
                                        } without PC</Badge>
                                    </div>
                                ))
                            )}
                        </div>
                    ) : (
                        <NoPcStationNumbers site={selectedNoPcSite} onBack={() => setSelectedNoPcSite(null)} />
                    )}
                </DetailDialog>

                <DetailDialog
                    open={activeDialog === 'vacantStations'}
                    onClose={closeDialog}
                    title={selectedVacantSite ? `Vacant Stations in ${selectedVacantSite}` : "Vacant Stations by Site"}
                    description={selectedVacantSite ? "Station numbers available for deployment" : "Available stations ready for deployment"}
                >
                    {!selectedVacantSite ? (
                        <div className="space-y-3">
                            {(!vacantStations?.bysite || vacantStations.bysite.length === 0) ? (
                                <div className="text-center text-muted-foreground py-8">
                                    No vacant stations
                                </div>
                            ) : (
                                vacantStations.bysite.map((site, idx) => (
                                    <div
                                        key={idx}
                                        className="flex items-center justify-between p-3 rounded-lg border cursor-pointer hover:bg-muted"
                                        onClick={() => setSelectedVacantSite(site.site)}
                                    >
                                        <div className="flex items-center gap-2">
                                            <MapPin className="h-4 w-4 text-muted-foreground" />
                                            <span className="font-medium">{site.site}</span>
                                        </div>
                                        <Badge variant="secondary">{site.count} vacant</Badge>
                                    </div>
                                ))
                            )}
                        </div>
                    ) : (
                        <VacantStationNumbers site={selectedVacantSite} onBack={() => setSelectedVacantSite(null)} />
                    )}
                </DetailDialog>

                <DetailDialog
                    open={activeDialog === 'diskPcs'}
                    onClose={closeDialog}
                    title="PCs with SSD & HDD by Site"
                    description="Stations equipped with Solid State Drives and Hard Disk Drives"
                >
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <div className="text-lg font-semibold mb-2 flex items-center gap-2">
                                <HardDrive className="h-5 w-5 text-green-600 dark:text-green-400" />
                                SSD Breakdown
                            </div>
                            <div className="space-y-3">
                                {(!ssdPcs?.details || ssdPcs.details.length === 0) ? (
                                    <div className="text-center text-muted-foreground py-8">
                                        No PCs with SSD found
                                    </div>
                                ) : (
                                    ssdPcs.details.map((site, idx) => (
                                        <div key={idx} className="flex items-center justify-between p-3 rounded-lg border">
                                            <div className="flex items-center gap-2">
                                                <span className="font-medium">{site.site}</span>
                                            </div>
                                            <Badge variant="secondary">{site.count} PCs</Badge>
                                        </div>
                                    ))
                                )}
                            </div>
                        </div>
                        <div>
                            <div className="text-lg font-semibold mb-2 flex items-center gap-2">
                                <HardDrive className="h-5 w-5 text-muted-foreground" />
                                HDD Breakdown
                            </div>
                            <div className="space-y-3">
                                {(!hddPcs?.details || hddPcs.details.length === 0) ? (
                                    <div className="text-center text-muted-foreground py-8">
                                        No PCs with HDD found
                                    </div>
                                ) : (
                                    hddPcs.details.map((site, idx) => (
                                        <div key={idx} className="flex items-center justify-between p-3 rounded-lg border">
                                            <div className="flex items-center gap-2">
                                                <span className="font-medium">{site.site}</span>
                                            </div>
                                            <Badge variant="secondary">{site.count} PCs</Badge>
                                        </div>
                                    ))
                                )}
                            </div>
                        </div>
                    </div>
                </DetailDialog>

                <DetailDialog
                    open={activeDialog === 'dualMonitor'}
                    onClose={closeDialog}
                    title="Dual Monitor Setups by Site"
                    description="Stations with dual monitor configuration"
                >
                    <div className="space-y-3">
                        {(!dualMonitor?.bysite || dualMonitor.bysite.length === 0) ? (
                            <div className="text-center text-muted-foreground py-8">
                                No dual monitor setups found
                            </div>
                        ) : (
                            dualMonitor.bysite.map((site, idx) => (
                                <div key={idx} className="flex items-center justify-between p-3 rounded-lg border">
                                    <div className="flex items-center gap-2">
                                        <Monitor className="h-4 w-4 text-muted-foreground" />
                                        <span className="font-medium">{site.site}</span>
                                    </div>
                                    <Badge variant="secondary">{site.count} setups</Badge>
                                </div>
                            ))
                        )}
                    </div>
                </DetailDialog>

                <DetailDialog
                    open={activeDialog === 'maintenanceDue'}
                    onClose={closeDialog}
                    title="Maintenance Due"
                    description="Stations requiring maintenance attention"
                >
                    <div className="space-y-2">
                        {(!maintenanceDue || maintenanceDue.total === 0) ? (
                            <div className="text-center text-muted-foreground py-8">
                                No overdue maintenance
                            </div>
                        ) : (
                            <>
                                {maintenanceDue.stations.slice(0, 10).map((station, idx) => (
                                    <div key={idx} className="flex items-center justify-between p-3 rounded-lg border border-red-500/30">
                                        <div>
                                            <div className="font-medium">{station.station}</div>
                                            <div className="text-sm text-muted-foreground">
                                                {station.site} • Due: {station.dueDate}
                                            </div>
                                        </div>
                                        <Badge variant="destructive">{station.daysOverdue}</Badge>
                                    </div>
                                ))}
                                {maintenanceDue.stations.length > 10 && (
                                    <div className="text-center text-sm text-muted-foreground pt-2">
                                        ... and {maintenanceDue.total - 10} more
                                    </div>
                                )}
                            </>
                        )}
                    </div>
                </DetailDialog>

                {/* Presence Insights Dialogs */}
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
                                <div>
                                    <div className="text-sm font-medium text-muted-foreground">Employee</div>
                                    <div className="text-lg font-semibold">{leave.user_name}</div>
                                    <div className="text-sm text-muted-foreground">{leave.campaign_name}</div>
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
                                {leave.reason && (
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
            </motion.div>
        </AppLayout>
    );
}
