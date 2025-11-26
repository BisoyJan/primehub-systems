import React, { useState, useEffect } from 'react';
import { motion } from 'framer-motion';
import { Head, router } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import CalendarWithHolidays from '@/components/CalendarWithHolidays';
import { ChartContainer, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';
import { Bar, BarChart, CartesianGrid, XAxis, YAxis, Pie, PieChart, Label, RadialBar, RadialBarChart, PolarGrid, Area, AreaChart, ResponsiveContainer, PolarRadiusAxis } from 'recharts';

import { Monitor, AlertCircle, HardDrive, Wrench, MapPin, Server, XCircle, Calendar, ChevronLeft, ChevronRight, Clock, Loader2, CheckCircle2, ClipboardList } from 'lucide-react';

//
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
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
}: DashboardProps) {
    const [activeDialog, setActiveDialog] = useState<string | null>(null);
    const [dateRange, setDateRange] = useState({
        start: initialStartDate,
        end: initialEndDate,
    });
    const [selectedCampaignId, setSelectedCampaignId] = useState<string>(initialCampaignId || "all");
    const [verificationFilter, setVerificationFilter] = useState<string>(initialVerificationFilter || "verified");
    const [radialChartIndex, setRadialChartIndex] = useState(0);
    const [selectedMonth, setSelectedMonth] = useState<string>("all");
    const [itConcernViewMode, setItConcernViewMode] = useState<'cards' | 'chart'>('cards');
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

    // Generate month options from date range
    const monthOptions = (() => {
        const months = [];
        const start = new Date(dateRange.start);
        const end = new Date(dateRange.end);
        const current = new Date(start.getFullYear(), start.getMonth(), 1);

        while (current <= end) {
            const monthKey = current.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
            months.push(monthKey);
            current.setMonth(current.getMonth() + 1);
        }

        return months;
    })();

    // Calculate filtered statistics based on selected month and status
    const filteredStatistics = (() => {
        // If no filters applied, return original statistics
        if (selectedMonth === "all") {
            return attendanceStatistics;
        }

        // Calculate what fraction of data to show based on month filter
        const totalMonths = monthOptions.length || 1;
        const monthFraction = selectedMonth === "all" ? 1 : (1 / totalMonths);

        // Apply month filter
        const stats = {
            total: selectedMonth === "all" ? attendanceStatistics.total : Math.floor(attendanceStatistics.total * monthFraction),
            on_time: selectedMonth === "all" ? attendanceStatistics.on_time : Math.floor(attendanceStatistics.on_time * monthFraction),
            time_adjustment: selectedMonth === "all" ? attendanceStatistics.time_adjustment : Math.floor(attendanceStatistics.time_adjustment * monthFraction),
            tardy: selectedMonth === "all" ? attendanceStatistics.tardy : Math.floor(attendanceStatistics.tardy * monthFraction),
            half_day: selectedMonth === "all" ? attendanceStatistics.half_day : Math.floor(attendanceStatistics.half_day * monthFraction),
            ncns: selectedMonth === "all" ? attendanceStatistics.ncns : Math.floor(attendanceStatistics.ncns * monthFraction),
            advised: selectedMonth === "all" ? attendanceStatistics.advised : Math.floor(attendanceStatistics.advised * monthFraction),
            needs_verification: selectedMonth === "all" ? attendanceStatistics.needs_verification : Math.floor(attendanceStatistics.needs_verification * monthFraction),
        };

        return stats;
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
    const activeTrendGradientId = `it-trend-${activeItTrendSlide.key}`;

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

    // Calculate attendance trend data
    const attendanceTrendData = (() => {
        const start = new Date(dateRange.start);
        const end = new Date(dateRange.end);

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
            const current = new Date(start);
            current.setDate(1); // Set to first day of month
            const data = [];

            while (current <= end) {
                // Format as YYYY-MM using local date components, not UTC
                const year = current.getFullYear();
                const month = String(current.getMonth() + 1).padStart(2, '0');
                const monthKey = `${year}-${month}`;
                const monthName = current.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });

                // Get monthly data from backend
                const monthRecord = monthlyAttendanceData[monthKey];

                data.push({
                    month: monthName,
                    total: Number(monthRecord?.total || 0),
                    on_time: Number(monthRecord?.on_time || 0),
                    time_adjustment: Number(monthRecord?.time_adjustment || 0),
                    tardy: Number(monthRecord?.tardy || 0),
                    half_day: Number(monthRecord?.half_day || 0),
                    ncns: Number(monthRecord?.ncns || 0),
                    advised: Number(monthRecord?.advised || 0),
                });

                current.setMonth(current.getMonth() + 1);
            }

            return data;
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
                {/* Header - Only show for non-restricted roles */}
                {!isRestrictedRole && (
                    <motion.div
                        className="flex items-center justify-between"
                        initial={{ opacity: 0, y: -20 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.5, delay: 0.1 }}
                    >
                        <div>
                            <h1 className="text-3xl font-bold tracking-tight">Dashboard</h1>
                            <p className="text-muted-foreground">
                                Overview of all stations and PC specifications
                            </p>
                        </div>
                    </motion.div>
                )}

                {/* Stats Grid */}
                <motion.div
                    className="grid gap-4 md:grid-cols-2 lg:grid-cols-4"
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    transition={{ duration: 0.5, delay: 0.2 }}
                >

                    {/* Current Date & Time */}
                    <StatCard
                        title="Current Date & Time"
                        value={
                            <div className="flex flex-col">
                                <span className="font-semibold text-base">{currentDateTime.date}</span>
                                <span className="text-lg text-muted-foreground">{currentDateTime.time}</span>
                            </div>
                        }
                        icon={Calendar}
                        onClick={() => setActiveDialog('dateTime')}
                        delay={0.25}
                    />

                    {!isRestrictedRole && (
                        <>
                            {/* Total Stations */}
                            <StatCard
                                title="Total Stations"
                                value={totalStations?.total || 0}
                                icon={Server}
                                description="Click for breakdown by site"
                                onClick={() => setActiveDialog('stations')}
                                delay={0.3}
                            />

                            {/* Available PC Specs */}
                            <StatCard
                                title="Available PCs"
                                value={unassignedPcSpecs?.length || 0}
                                icon={Server}
                                description="PC specs not assigned to any station"
                                onClick={() => setActiveDialog('availablePcs')}
                                variant={(unassignedPcSpecs?.length || 0) > 0 ? "success" : "default"}
                                delay={0.35}
                            />
                            {/* No PCs */}
                            <StatCard
                                title="Stations Without PCs"
                                value={noPcs?.total || 0}
                                icon={AlertCircle}
                                description="Requires PC assignment"
                                onClick={() => setActiveDialog('noPcs')}
                                variant="warning"
                                delay={0.4}
                            />

                            {/* Vacant Stations */}
                            <StatCard
                                title="Vacant Stations"
                                value={vacantStations?.total || 0}
                                icon={XCircle}
                                description="Available for deployment"
                                onClick={() => setActiveDialog('vacantStations')}
                                delay={0.45}
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
                                delay={0.5}
                            />

                            {/* Dual Monitor */}
                            <StatCard
                                title="Dual Monitor Setups"
                                value={dualMonitor?.total || 0}
                                icon={Monitor}
                                description="Stations with 2 monitors"
                                onClick={() => setActiveDialog('dualMonitor')}
                                delay={0.55}
                            />

                            {/* Maintenance Due */}
                            <StatCard
                                title="Maintenance Due"
                                value={maintenanceDue?.total || 0}
                                icon={Wrench}
                                description="Requires attention"
                                onClick={() => setActiveDialog('maintenanceDue')}
                                variant={(maintenanceDue?.total || 0) > 0 ? "danger" : "default"}
                                delay={0.6}
                            />

                            {/* IT Concerns Widget */}
                            <div className="col-span-1 md:col-span-2 lg:col-span-4">
                                <div className="flex items-center justify-between mb-4">
                                    <div className="flex items-center gap-2">
                                        <h3 className="font-semibold text-lg">
                                            {itConcernViewMode === 'cards' ? 'IT Concerns Overview' : 'IT Concern Trends'}
                                        </h3>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <span className="text-xs text-muted-foreground mr-2">
                                            Switch View
                                        </span>
                                        <Button
                                            variant="outline"
                                            size="icon"
                                            className="h-8 w-8 rounded-full"
                                            onClick={() => setItConcernViewMode(prev => prev === 'cards' ? 'chart' : 'cards')}
                                        >
                                            {itConcernViewMode === 'cards' ? <ChevronRight className="h-4 w-4" /> : <ChevronLeft className="h-4 w-4" />}
                                        </Button>
                                    </div>
                                </div>

                                {itConcernViewMode === 'cards' ? (
                                    <motion.div
                                        initial={{ opacity: 0, x: -20 }}
                                        animate={{ opacity: 1, x: 0 }}
                                        exit={{ opacity: 0, x: 20 }}
                                        transition={{ duration: 0.3 }}
                                        className="grid gap-4 md:grid-cols-2 lg:grid-cols-4"
                                    >
                                        <StatCard
                                            title="IT Concerns (All)"
                                            value={totalConcerns}
                                            icon={ClipboardList}
                                            description="Click for per-site breakdown"
                                            onClick={() => setActiveDialog('itConcernsBySite')}
                                            variant={totalConcerns > 0 ? 'success' : 'default'}
                                            delay={0.63}
                                        />
                                        <StatCard
                                            title="IT Concerns (Pending)"
                                            value={concernStats.pending}
                                            icon={Clock}
                                            description="Awaiting acknowledgement"
                                            onClick={() => goToItConcerns('pending')}
                                            variant={concernStats.pending > 0 ? 'warning' : 'default'}
                                            delay={0.68}
                                        />
                                        <StatCard
                                            title="IT Concerns (In Progress)"
                                            value={concernStats.in_progress}
                                            icon={Loader2}
                                            description="Currently being handled"
                                            onClick={() => goToItConcerns('in_progress')}
                                            delay={0.73}
                                        />
                                        <StatCard
                                            title="IT Concerns (Resolved)"
                                            value={concernStats.resolved}
                                            icon={CheckCircle2}
                                            description="Closed this period"
                                            onClick={() => goToItConcerns('resolved')}
                                            variant="success"
                                            delay={0.78}
                                        />
                                    </motion.div>
                                ) : (
                                    <motion.div
                                        initial={{ opacity: 0, x: 20 }}
                                        animate={{ opacity: 1, x: 0 }}
                                        exit={{ opacity: 0, x: -20 }}
                                        transition={{ duration: 0.3 }}
                                    >
                                        <Card>
                                            <CardHeader className="pb-0">
                                                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                                                    <div>
                                                        <CardTitle className="text-base">Trend Analysis</CardTitle>
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
                                                <div className="flex flex-wrap items-center gap-2 mt-4 pt-4 border-t">
                                                    <div className="flex items-center gap-2">
                                                        <span className="text-xs font-medium">Filter Date:</span>
                                                        <input
                                                            type="date"
                                                            value={dateRange.start}
                                                            onChange={(e) => setDateRange({ ...dateRange, start: e.target.value })}
                                                            className="h-8 px-2 border rounded-md text-xs"
                                                            aria-label="Start date"
                                                        />
                                                        <span className="text-muted-foreground text-xs">to</span>
                                                        <input
                                                            type="date"
                                                            value={dateRange.end}
                                                            onChange={(e) => setDateRange({ ...dateRange, end: e.target.value })}
                                                            className="h-8 px-2 border rounded-md text-xs"
                                                            aria-label="End date"
                                                        />
                                                        <Button
                                                            size="sm"
                                                            onClick={handleDateRangeChange}
                                                            className="h-8 text-xs"
                                                        >
                                                            Apply
                                                        </Button>
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
                                                                        <linearGradient id={activeTrendGradientId} x1="0" y1="0" x2="0" y2="1">
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
                                                                        fill={`url(#${activeTrendGradientId})`}
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
                                )}
                            </div>

                            {/* Cards removed as requested */}
                        </>
                    )}
                </motion.div>

                {/* Attendance Statistics Section */}
                <motion.div
                    className="space-y-4"
                    initial={{ opacity: 0, y: 20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.5, delay: 0.9 }}
                >
                    <div className="flex items-center justify-between">
                        <div>
                            <h2 className="text-2xl font-bold tracking-tight">Attendance Statistics</h2>
                            <p className="text-muted-foreground">
                                {isRestrictedRole
                                    ? "Your personal attendance for the selected period"
                                    : "Overview of attendance for the selected period"
                                }
                            </p>
                        </div>
                        <motion.div
                            className="flex items-center gap-2"
                            initial={{ opacity: 0, x: 20 }}
                            animate={{ opacity: 1, x: 0 }}
                            transition={{ duration: 0.5, delay: 1.0 }}
                        >
                            {!isRestrictedRole && campaigns && campaigns.length > 0 && (
                                <Select value={selectedCampaignId || "all"} onValueChange={(value) => setSelectedCampaignId(value === "all" ? "" : value)}>
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
                            <Select value={verificationFilter} onValueChange={setVerificationFilter}>
                                <SelectTrigger className="w-[180px]">
                                    <SelectValue placeholder="Verification Status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Records</SelectItem>
                                    <SelectItem value="verified">Verified Only</SelectItem>
                                    <SelectItem value="non_verified">Non-Verified Only</SelectItem>
                                </SelectContent>
                            </Select>
                            <input
                                type="date"
                                value={dateRange.start}
                                onChange={(e) => setDateRange({ ...dateRange, start: e.target.value })}
                                className="px-3 py-2 border rounded-md text-sm"
                                placeholder="Start date"
                                title="Start date"
                            />
                            <span className="text-muted-foreground">to</span>
                            <input
                                type="date"
                                value={dateRange.end}
                                onChange={(e) => setDateRange({ ...dateRange, end: e.target.value })}
                                className="px-3 py-2 border rounded-md text-sm"
                                placeholder="End date"
                                title="End date"
                            />
                            <button
                                onClick={handleDateRangeChange}
                                className="px-4 py-2 bg-primary text-primary-foreground rounded-md text-sm hover:bg-primary/90"
                            >
                                Apply
                            </button>
                        </motion.div>
                    </div>

                    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                        <motion.div
                            layout
                            initial={{ opacity: 0, y: 20 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ duration: 0.3, delay: 1.1 }}
                        >
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
                        </motion.div>

                        <motion.div
                            layout
                            initial={{ opacity: 0, y: 20 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ duration: 0.3, delay: 1.2 }}
                        >
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
                        </motion.div>

                        <motion.div
                            layout
                            initial={{ opacity: 0, y: 20 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ duration: 0.3, delay: 1.25 }}
                        >
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
                        </motion.div>

                        <motion.div
                            layout
                            initial={{ opacity: 0, y: 20 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ duration: 0.3, delay: 1.3 }}
                        >
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
                        </motion.div>

                        <motion.div
                            layout
                            initial={{ opacity: 0, y: 20 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ duration: 0.3, delay: 1.4 }}
                        >
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
                        </motion.div>

                        <motion.div
                            layout
                            initial={{ opacity: 0, y: 20 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ duration: 0.3, delay: 1.45 }}
                        >
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
                        </motion.div>

                        <motion.div
                            layout
                            initial={{ opacity: 0, y: 20 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ duration: 0.3, delay: 1.5 }}
                        >
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
                        </motion.div>

                        <motion.div
                            layout
                            initial={{ opacity: 0, y: 20 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ duration: 0.3, delay: 1.6 }}
                        >
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
                        </motion.div>
                    </div>

                    {/* Attendance Charts Section */}
                    <motion.div
                        className="grid gap-6 md:grid-cols-2 lg:grid-cols-3 mt-8"
                        initial={{ opacity: 0, y: 20 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.5, delay: 1.9 }}
                    >
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

                    </motion.div>

                    {/* Area Chart - Monthly Statistics */}
                    <motion.div
                        className="mt-6"
                        initial={{ opacity: 0, y: 20 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.5, delay: 2.0 }}
                    >
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
                </motion.div>

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

                {/* DetailDialogs removed as requested */}
            </motion.div>
        </AppLayout>
    );
}
