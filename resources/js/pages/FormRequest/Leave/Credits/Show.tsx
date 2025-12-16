import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ArrowLeft, Calendar, TrendingUp, TrendingDown, CreditCard, FileText } from 'lucide-react';
import { useFlashMessage, usePageLoading, usePageMeta } from '@/hooks';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { index as creditsIndexRoute } from '@/routes/leave-requests/credits';
import { format } from 'date-fns';

interface MonthlyCredit {
    id: number;
    month: number;
    month_name: string;
    credits_earned: number;
    credits_used: number;
    credits_balance: number;
    accrued_at: string;
}

interface LeaveRequestHistory {
    id: number;
    leave_type: string;
    start_date: string;
    end_date: string;
    days_requested: number;
    credits_deducted: number;
    approved_at: string | null;
}

interface Props {
    user: {
        id: number;
        name: string;
        email: string;
        role: string;
        hired_date: string;
    };
    year: number;
    summary: {
        is_eligible: boolean;
        eligibility_date: string | null;
        monthly_rate: number;
        total_earned: number;
        total_used: number;
        balance: number;
    };
    monthlyCredits: MonthlyCredit[];
    leaveRequests: LeaveRequestHistory[];
    availableYears: number[];
    canViewAll: boolean;
}

export default function Show({ user, year, summary, monthlyCredits, leaveRequests, availableYears, canViewAll }: Props) {
    const { title, breadcrumbs } = usePageMeta({
        title: canViewAll ? `Leave Credits - ${user.name}` : 'My Leave Credits',
        breadcrumbs: canViewAll
            ? [
                { title: 'Form Requests', href: '/form-requests' },
                { title: 'Leave Credits', href: creditsIndexRoute().url },
                { title: user.name, href: `/form-requests/leave-requests/credits/${user.id}` },
            ]
            : [
                { title: 'Form Requests', href: '/form-requests' },
                { title: 'My Leave Credits', href: `/form-requests/leave-requests/credits/${user.id}` },
            ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    const handleYearChange = (newYear: string) => {
        router.get(`/form-requests/leave-requests/credits/${user.id}`, { year: newYear }, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const formatLeaveType = (type: string) => {
        return type.split('_').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <LoadingOverlay isLoading={isPageLoading} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                {/* Header with Back Button - only show if user can view all credits */}
                {canViewAll && (
                    <div className="flex items-center gap-4">
                        <Link href={creditsIndexRoute().url}>
                            <Button variant="outline" size="icon">
                                <ArrowLeft className="h-4 w-4" />
                            </Button>
                        </Link>
                    </div>
                )}

                <PageHeader
                    title={canViewAll ? user.name : 'My Leave Credits'}
                    description={`${user.email} • ${user.role} • Hired: ${format(new Date(user.hired_date), 'MMM d, yyyy')}`}
                    actions={
                        <div className="flex items-center gap-2">
                            <span className="text-sm text-muted-foreground">Year:</span>
                            <Select value={year.toString()} onValueChange={handleYearChange}>
                                <SelectTrigger className="w-28">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {availableYears.map((y) => (
                                        <SelectItem key={y} value={y.toString()}>
                                            {y}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    }
                />

                {/* Summary Cards */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">Monthly Rate</CardTitle>
                            <Calendar className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{summary.monthly_rate.toFixed(2)}</div>
                            <p className="text-xs text-muted-foreground">
                                Credits earned per month
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">Total Earned</CardTitle>
                            <TrendingUp className="h-4 w-4 text-green-500" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-green-600">{summary.total_earned.toFixed(2)}</div>
                            <p className="text-xs text-muted-foreground">
                                Credits accrued in {year}
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">Total Used</CardTitle>
                            <TrendingDown className="h-4 w-4 text-red-500" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-red-600">{summary.total_used.toFixed(2)}</div>
                            <p className="text-xs text-muted-foreground">
                                Credits used in {year}
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">Current Balance</CardTitle>
                            <CreditCard className="h-4 w-4 text-blue-500" />
                        </CardHeader>
                        <CardContent>
                            <div className={`text-2xl font-bold ${summary.balance > 0 ? 'text-blue-600' : 'text-gray-600'}`}>
                                {summary.balance.toFixed(2)}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Available credits
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {/* Two Tables Side by Side */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {/* Monthly Accruals Table */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Calendar className="h-5 w-5" />
                                Monthly Accruals ({year})
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {monthlyCredits.length === 0 ? (
                                <div className="text-center py-8 text-muted-foreground">
                                    <Calendar className="h-12 w-12 mx-auto mb-4 opacity-50" />
                                    <p>No credits accrued for {year}</p>
                                    {!summary.is_eligible && summary.eligibility_date && (
                                        <p className="text-sm mt-2">
                                            Eligible from: {format(new Date(summary.eligibility_date), 'MMM d, yyyy')}
                                        </p>
                                    )}
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Month</TableHead>
                                                <TableHead className="text-right">Earned</TableHead>
                                                <TableHead className="text-right">Used</TableHead>
                                                <TableHead className="text-right">Balance</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {monthlyCredits.map((credit) => (
                                                <TableRow key={credit.id}>
                                                    <TableCell className="font-medium">
                                                        {credit.month_name}
                                                    </TableCell>
                                                    <TableCell className="text-right text-green-600">
                                                        +{credit.credits_earned.toFixed(2)}
                                                    </TableCell>
                                                    <TableCell className="text-right text-red-600">
                                                        {credit.credits_used > 0 ? `-${credit.credits_used.toFixed(2)}` : '0.00'}
                                                    </TableCell>
                                                    <TableCell className="text-right font-medium">
                                                        {credit.credits_balance.toFixed(2)}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Leave Usage History Table */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <FileText className="h-5 w-5" />
                                Leave Usage History ({year})
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {leaveRequests.length === 0 ? (
                                <div className="text-center py-8 text-muted-foreground">
                                    <FileText className="h-12 w-12 mx-auto mb-4 opacity-50" />
                                    <p>No leave credits used in {year}</p>
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Leave Type</TableHead>
                                                <TableHead>Date</TableHead>
                                                <TableHead className="text-right">Days</TableHead>
                                                <TableHead className="text-right">Credits</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {leaveRequests.map((leave) => (
                                                <TableRow key={leave.id}>
                                                    <TableCell>
                                                        <Badge variant="outline">
                                                            {formatLeaveType(leave.leave_type)}
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell className="text-sm">
                                                        {format(new Date(leave.start_date), 'MMM d')}
                                                        {leave.start_date !== leave.end_date && (
                                                            <> - {format(new Date(leave.end_date), 'MMM d')}</>
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        {leave.days_requested}
                                                    </TableCell>
                                                    <TableCell className="text-right text-red-600 font-medium">
                                                        -{leave.credits_deducted.toFixed(2)}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                            {/* Total row */}
                                            <TableRow className="bg-muted/50 font-medium">
                                                <TableCell colSpan={2}>Total</TableCell>
                                                <TableCell className="text-right">
                                                    {leaveRequests.reduce((sum, l) => sum + l.days_requested, 0)}
                                                </TableCell>
                                                <TableCell className="text-right text-red-600">
                                                    -{leaveRequests.reduce((sum, l) => sum + l.credits_deducted, 0).toFixed(2)}
                                                </TableCell>
                                            </TableRow>
                                        </TableBody>
                                    </Table>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
