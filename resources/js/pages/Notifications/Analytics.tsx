import { Head } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { PageHeader } from '@/components/PageHeader';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ChartContainer, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';
import { Area, AreaChart, Bar, BarChart, CartesianGrid, ResponsiveContainer, XAxis, YAxis } from 'recharts';
import { useFlashMessage, usePageLoading, usePageMeta } from '@/hooks';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { Bell, BookOpen, Clock, Mail, TrendingUp } from 'lucide-react';
import type { BreadcrumbItem } from '@/types';
import { index as notificationsIndexRoute } from '@/routes/notifications';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Notifications', href: notificationsIndexRoute().url },
    { title: 'Analytics', href: '#' },
];

interface Summary {
    total: number;
    read: number;
    unread: number;
    read_rate: number;
    scheduled_pending: number;
    last_30_days: number;
}

interface TypeDistribution {
    type: string;
    count: number;
}

interface MonthlyTrend {
    month: string;
    label: string;
    total: number;
    read: number;
    unread: number;
}

interface ReadRateByType {
    type: string;
    total: number;
    read: number;
    read_rate: number;
}

interface Props {
    summary: Summary;
    typeDistribution: TypeDistribution[];
    monthlyTrends: MonthlyTrend[];
    readRateByType: ReadRateByType[];
}

const typeLabels: Record<string, string> = {
    leave_request: 'Leave Request',
    it_concern: 'IT Concern',
    medication_request: 'Medication',
    maintenance_due: 'Maintenance',
    pc_assignment: 'PC Assignment',
    system: 'System',
    attendance_status: 'Attendance',
    coaching_session: 'Coaching',
    coaching_acknowledged: 'Coaching Ack',
    coaching_reviewed: 'Coaching Review',
    coaching_ready_for_review: 'Ready Review',
    coaching_pending_reminder: 'Coaching Reminder',
    coaching_unacknowledged_alert: 'Coaching Alert',
    break_overage: 'Break Overage',
    undertime_approval: 'Undertime',
    account_deletion: 'Account Delete',
    account_reactivation: 'Account Reactivate',
    account_restored: 'Account Restore',
    announcement: 'Announcement',
    reminder: 'Reminder',
    alert: 'Alert',
    custom: 'Custom',
};

const trendChartConfig = {
    total: { label: 'Total', color: 'hsl(221, 83%, 53%)' },
    read: { label: 'Read', color: 'hsl(142, 71%, 45%)' },
    unread: { label: 'Unread', color: 'hsl(0, 84%, 60%)' },
};

const distributionChartConfig = {
    count: { label: 'Count', color: 'hsl(262, 83%, 58%)' },
};

const readRateChartConfig = {
    read_rate: { label: 'Read Rate %', color: 'hsl(142, 71%, 45%)' },
};

export default function Analytics({ summary, typeDistribution, monthlyTrends, readRateByType }: Props) {
    const { title } = usePageMeta({ title: 'Notification Analytics', breadcrumbs });
    useFlashMessage();
    const isLoading = usePageLoading();

    const distributionData = typeDistribution.map((item) => ({
        ...item,
        name: typeLabels[item.type] || item.type,
    }));

    const readRateData = readRateByType.map((item) => ({
        ...item,
        name: typeLabels[item.type] || item.type,
    }));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <LoadingOverlay isLoading={isLoading} />

            <div className="container mx-auto py-6 max-w-7xl">
                <PageHeader
                    title="Notification Analytics"
                    description="Overview of notification delivery and engagement metrics"
                />

                {/* Summary Stats */}
                <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mt-6">
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-2">
                                <Mail className="h-4 w-4 text-muted-foreground" />
                                <p className="text-sm text-muted-foreground">Total</p>
                            </div>
                            <p className="text-2xl font-bold mt-1">{summary.total.toLocaleString()}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-2">
                                <BookOpen className="h-4 w-4 text-green-500" />
                                <p className="text-sm text-muted-foreground">Read</p>
                            </div>
                            <p className="text-2xl font-bold mt-1">{summary.read.toLocaleString()}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-2">
                                <Bell className="h-4 w-4 text-red-500" />
                                <p className="text-sm text-muted-foreground">Unread</p>
                            </div>
                            <p className="text-2xl font-bold mt-1">{summary.unread.toLocaleString()}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-2">
                                <TrendingUp className="h-4 w-4 text-blue-500" />
                                <p className="text-sm text-muted-foreground">Read Rate</p>
                            </div>
                            <p className="text-2xl font-bold mt-1">{summary.read_rate}%</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-2">
                                <Clock className="h-4 w-4 text-amber-500" />
                                <p className="text-sm text-muted-foreground">Scheduled</p>
                            </div>
                            <p className="text-2xl font-bold mt-1">{summary.scheduled_pending}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-2">
                                <Mail className="h-4 w-4 text-purple-500" />
                                <p className="text-sm text-muted-foreground">Last 30d</p>
                            </div>
                            <p className="text-2xl font-bold mt-1">{summary.last_30_days.toLocaleString()}</p>
                        </CardContent>
                    </Card>
                </div>

                {/* Charts */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
                    {/* Monthly Trends */}
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>Monthly Notification Trends</CardTitle>
                            <CardDescription>Total, read, and unread notifications over the last 6 months</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <ChartContainer config={trendChartConfig} className="h-80 w-full">
                                <ResponsiveContainer width="100%" height="100%">
                                    <AreaChart data={monthlyTrends} margin={{ left: 10, right: 10 }}>
                                        <defs>
                                            <linearGradient id="gradTotal" x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="5%" stopColor="hsl(221, 83%, 53%)" stopOpacity={0.3} />
                                                <stop offset="95%" stopColor="hsl(221, 83%, 53%)" stopOpacity={0} />
                                            </linearGradient>
                                            <linearGradient id="gradRead" x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="5%" stopColor="hsl(142, 71%, 45%)" stopOpacity={0.3} />
                                                <stop offset="95%" stopColor="hsl(142, 71%, 45%)" stopOpacity={0} />
                                            </linearGradient>
                                        </defs>
                                        <CartesianGrid strokeDasharray="3 3" />
                                        <XAxis dataKey="label" />
                                        <YAxis />
                                        <ChartTooltip content={<ChartTooltipContent />} />
                                        <Area
                                            type="monotone"
                                            dataKey="total"
                                            stroke="hsl(221, 83%, 53%)"
                                            fill="url(#gradTotal)"
                                            name="Total"
                                        />
                                        <Area
                                            type="monotone"
                                            dataKey="read"
                                            stroke="hsl(142, 71%, 45%)"
                                            fill="url(#gradRead)"
                                            name="Read"
                                        />
                                    </AreaChart>
                                </ResponsiveContainer>
                            </ChartContainer>
                        </CardContent>
                    </Card>

                    {/* Type Distribution */}
                    <Card>
                        <CardHeader>
                            <CardTitle>By Type</CardTitle>
                            <CardDescription>Notification volume by category</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <ChartContainer config={distributionChartConfig} className="h-80 w-full">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={distributionData} layout="vertical" margin={{ left: 80, right: 10 }}>
                                        <CartesianGrid strokeDasharray="3 3" />
                                        <XAxis type="number" />
                                        <YAxis type="category" dataKey="name" width={70} tick={{ fontSize: 12 }} />
                                        <ChartTooltip content={<ChartTooltipContent />} />
                                        <Bar dataKey="count" fill="hsl(262, 83%, 58%)" radius={[0, 4, 4, 0]} name="Count" />
                                    </BarChart>
                                </ResponsiveContainer>
                            </ChartContainer>
                        </CardContent>
                    </Card>

                    {/* Read Rate by Type */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Read Rate by Type</CardTitle>
                            <CardDescription>Percentage of notifications read per type</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <ChartContainer config={readRateChartConfig} className="h-80 w-full">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={readRateData} layout="vertical" margin={{ left: 80, right: 10 }}>
                                        <CartesianGrid strokeDasharray="3 3" />
                                        <XAxis type="number" domain={[0, 100]} />
                                        <YAxis type="category" dataKey="name" width={70} tick={{ fontSize: 12 }} />
                                        <ChartTooltip content={<ChartTooltipContent />} />
                                        <Bar dataKey="read_rate" fill="hsl(142, 71%, 45%)" radius={[0, 4, 4, 0]} name="Read Rate %" />
                                    </BarChart>
                                </ResponsiveContainer>
                            </ChartContainer>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
