import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Users,
    CheckCircle,
    Clock,
    AlertTriangle,
    XCircle,
    Calendar as CalendarIcon,
    FileWarning
} from 'lucide-react';
import { motion } from 'framer-motion';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
    {
        title: 'Attendance',
        href: '/attendance',
    },
    {
        title: 'Statistics',
        href: '/attendance/dashboard',
    },
];

interface AttendanceStats {
    total: number;
    on_time: number;
    tardy: number;
    half_day: number;
    ncns: number;
    advised: number;
    needs_verification: number;
}

interface DashboardProps {
    statistics: AttendanceStats;
    startDate: string;
    endDate: string;
}

interface StatCardProps {
    title: string;
    value: number;
    percentage?: number;
    icon: React.ComponentType<{ className?: string }>;
    variant?: 'default' | 'success' | 'warning' | 'danger' | 'info';
    description?: string;
}

const StatCard: React.FC<StatCardProps> = ({
    title,
    value,
    percentage,
    icon: Icon,
    variant = 'default',
    description
}) => {
    const variantStyles = {
        default: 'border-border',
        success: 'border-green-500/30 bg-green-50/50 dark:bg-green-950/20',
        warning: 'border-yellow-500/30 bg-yellow-50/50 dark:bg-yellow-950/20',
        danger: 'border-red-500/30 bg-red-50/50 dark:bg-red-950/20',
        info: 'border-blue-500/30 bg-blue-50/50 dark:bg-blue-950/20',
    };

    const iconStyles = {
        default: 'text-muted-foreground',
        success: 'text-green-600 dark:text-green-400',
        warning: 'text-yellow-600 dark:text-yellow-400',
        danger: 'text-red-600 dark:text-red-400',
        info: 'text-blue-600 dark:text-blue-400',
    };

    return (
        <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.3 }}
        >
            <Card className={`${variantStyles[variant]} hover:shadow-md transition-all`}>
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle className="text-sm font-medium">{title}</CardTitle>
                    <Icon className={`h-4 w-4 ${iconStyles[variant]}`} />
                </CardHeader>
                <CardContent>
                    <div className="text-2xl font-bold">{value.toLocaleString()}</div>
                    {percentage !== undefined && (
                        <p className="text-xs text-muted-foreground mt-1">
                            {percentage.toFixed(1)}% of total
                        </p>
                    )}
                    {description && (
                        <p className="text-xs text-muted-foreground mt-1">{description}</p>
                    )}
                </CardContent>
            </Card>
        </motion.div>
    );
};

export default function AttendanceDashboard({
    statistics,
    startDate: initialStartDate,
    endDate: initialEndDate
}: DashboardProps) {
    const [startDate, setStartDate] = useState(initialStartDate);
    const [endDate, setEndDate] = useState(initialEndDate);
    const [isLoading, setIsLoading] = useState(false);

    const handleDateChange = () => {
        if (!startDate || !endDate) return;

        setIsLoading(true);
        router.get(
            '/attendance/dashboard',
            { start_date: startDate, end_date: endDate },
            {
                preserveState: true,
                preserveScroll: true,
                onFinish: () => setIsLoading(false),
            }
        );
    };

    const resetToCurrentMonth = () => {
        const now = new Date();
        const firstDay = new Date(now.getFullYear(), now.getMonth(), 1);
        const lastDay = new Date(now.getFullYear(), now.getMonth() + 1, 0);

        setStartDate(firstDay.toISOString().split('T')[0]);
        setEndDate(lastDay.toISOString().split('T')[0]);

        setIsLoading(true);
        router.get(
            '/attendance/dashboard',
            {
                start_date: firstDay.toISOString().split('T')[0],
                end_date: lastDay.toISOString().split('T')[0]
            },
            {
                preserveState: true,
                preserveScroll: true,
                onFinish: () => setIsLoading(false),
            }
        );
    };

    const calculatePercentage = (value: number): number => {
        return statistics.total > 0 ? (value / statistics.total) * 100 : 0;
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Attendance Dashboard" />

            <div className="p-4 md:p-6 space-y-6">
                {/* Header */}
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Attendance Dashboard</h1>
                        <p className="text-muted-foreground">
                            Overview of attendance statistics and trends
                        </p>
                    </div>
                </div>

                {/* Date Range Filter */}
                <Card>
                    <CardHeader>
                        <CardTitle>Date Range Filter</CardTitle>
                        <CardDescription>
                            Select a date range to view attendance statistics
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-col md:flex-row gap-4">
                            <div className="flex-1">
                                <Label htmlFor="start-date">Start Date</Label>
                                <Input
                                    id="start-date"
                                    type="date"
                                    value={startDate}
                                    onChange={(e) => setStartDate(e.target.value)}
                                    className="mt-1"
                                />
                            </div>
                            <div className="flex-1">
                                <Label htmlFor="end-date">End Date</Label>
                                <Input
                                    id="end-date"
                                    type="date"
                                    value={endDate}
                                    onChange={(e) => setEndDate(e.target.value)}
                                    className="mt-1"
                                />
                            </div>
                            <div className="flex items-end gap-2">
                                <Button
                                    onClick={handleDateChange}
                                    disabled={isLoading || !startDate || !endDate}
                                >
                                    {isLoading ? 'Loading...' : 'Apply Filter'}
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={resetToCurrentMonth}
                                    disabled={isLoading}
                                >
                                    Current Month
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Statistics Overview */}
                <div>
                    <h2 className="text-xl font-semibold mb-4">Statistics Overview</h2>
                    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                        <StatCard
                            title="Total Attendance"
                            value={statistics.total}
                            icon={Users}
                            variant="default"
                            description="Total records in date range"
                        />
                        <StatCard
                            title="On Time"
                            value={statistics.on_time}
                            percentage={calculatePercentage(statistics.on_time)}
                            icon={CheckCircle}
                            variant="success"
                        />
                        <StatCard
                            title="Tardy"
                            value={statistics.tardy}
                            percentage={calculatePercentage(statistics.tardy)}
                            icon={Clock}
                            variant="warning"
                        />
                        <StatCard
                            title="Half Day Absence"
                            value={statistics.half_day}
                            percentage={calculatePercentage(statistics.half_day)}
                            icon={AlertTriangle}
                            variant="warning"
                        />
                    </div>
                </div>

                {/* Additional Statistics */}
                <div>
                    <h2 className="text-xl font-semibold mb-4">Additional Metrics</h2>
                    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                        <StatCard
                            title="NCNS (No Call No Show)"
                            value={statistics.ncns}
                            percentage={calculatePercentage(statistics.ncns)}
                            icon={XCircle}
                            variant="danger"
                        />
                        <StatCard
                            title="Advised Absence"
                            value={statistics.advised}
                            percentage={calculatePercentage(statistics.advised)}
                            icon={CalendarIcon}
                            variant="info"
                        />
                        <StatCard
                            title="Needs Verification"
                            value={statistics.needs_verification}
                            percentage={calculatePercentage(statistics.needs_verification)}
                            icon={FileWarning}
                            variant="danger"
                            description="Requires admin review"
                        />
                    </div>
                </div>

                {/* Summary Card */}
                <Card>
                    <CardHeader>
                        <CardTitle>Summary</CardTitle>
                        <CardDescription>
                            Quick overview of attendance trends
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-3">
                            <div className="flex items-center justify-between">
                                <span className="text-sm font-medium">Attendance Rate</span>
                                <div className="flex items-center gap-2">
                                    <div className="w-48 bg-muted rounded-full h-2">
                                        <div
                                            className="bg-green-500 h-2 rounded-full transition-all"
                                            style={{
                                                width: `${calculatePercentage(statistics.on_time)}%`,
                                            }}
                                        />
                                    </div>
                                    <span className="text-sm font-semibold w-12 text-right">
                                        {calculatePercentage(statistics.on_time).toFixed(1)}%
                                    </span>
                                </div>
                            </div>

                            <div className="flex items-center justify-between">
                                <span className="text-sm font-medium">Issues Detected</span>
                                <div className="flex items-center gap-2">
                                    <Badge variant="destructive">
                                        {statistics.tardy + statistics.half_day + statistics.ncns}
                                    </Badge>
                                </div>
                            </div>

                            <div className="flex items-center justify-between">
                                <span className="text-sm font-medium">Pending Verification</span>
                                <div className="flex items-center gap-2">
                                    {statistics.needs_verification > 0 ? (
                                        <Badge variant="outline" className="border-red-500 text-red-500">
                                            {statistics.needs_verification} records
                                        </Badge>
                                    ) : (
                                        <Badge variant="outline" className="border-green-500 text-green-500">
                                            All verified
                                        </Badge>
                                    )}
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Quick Actions */}
                <Card>
                    <CardHeader>
                        <CardTitle>Quick Actions</CardTitle>
                        <CardDescription>
                            Navigate to related attendance features
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                onClick={() => router.visit('/attendance')}
                            >
                                View All Attendance
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() => router.visit('/attendance/review')}
                            >
                                Review Attendance
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() => router.visit('/attendance/import')}
                            >
                                Import Attendance
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() => router.visit('/attendance-points')}
                            >
                                Attendance Points
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
