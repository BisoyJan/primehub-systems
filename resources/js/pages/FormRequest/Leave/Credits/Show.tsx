import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
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
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { ArrowLeft, Calendar, TrendingUp, TrendingDown, CreditCard, FileText, Banknote, AlertCircle, CheckCircle } from 'lucide-react';
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
    has_partial_denial: boolean;
    approved_days: number | null;
}

interface CarryoverSummary {
    has_carryover: boolean;
    is_processed: boolean;
    is_expired: boolean;
    carryover_credits: number;
    credits_from_year?: number;
    forfeited_credits: number;
    cash_converted: boolean;
    cash_converted_at: string | null;
    from_year: number;
    to_year: number;
}

interface CarryoverReceived {
    credits: number;
    credits_used?: number;
    credits_balance?: number;
    from_year: number;
    is_first_regularization: boolean;
}

interface RegularizationInfo {
    is_regularized: boolean;
    regularization_date: string | null;
    hire_year: number;
}

interface Props {
    user: {
        id: number;
        name: string;
        email: string;
        role: string;
        hired_date: string;
        avatar?: string;
        avatar_url?: string;
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
    carryoverSummary?: CarryoverSummary | null;
    carryoverReceived?: CarryoverReceived | null;
    regularization?: RegularizationInfo;
    monthlyCredits: MonthlyCredit[];
    leaveRequests: LeaveRequestHistory[];
    availableYears: number[];
    canViewAll: boolean;
}

export default function Show({ user, year, summary, carryoverSummary, carryoverReceived, monthlyCredits, leaveRequests, availableYears, canViewAll }: Props) {
    const getInitials = useInitials();

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

                {/* Employee Info with Avatar */}
                <div className="flex items-start gap-4 pb-4 border-b">
                    <Avatar className="h-16 w-16 overflow-hidden rounded-full">
                        <AvatarImage src={user.avatar_url} alt={user.name} />
                        <AvatarFallback className="rounded-full bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white text-lg">
                            {getInitials(user.name)}
                        </AvatarFallback>
                    </Avatar>
                    <div className="flex-1">
                        <h1 className="text-2xl font-bold">{canViewAll ? user.name : 'My Leave Credits'}</h1>
                        <p className="text-sm text-muted-foreground mt-1">
                            {user.email} • {user.role} • Hired: {format(new Date(user.hired_date), 'MMM d, yyyy')}
                        </p>
                    </div>
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
                </div>

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

                {/* Carryover Credits Section - For Conversion/Leave */}
                {carryoverSummary && carryoverSummary.carryover_credits > 0 && (
                    <Card className={`${carryoverSummary.is_processed
                        ? 'border-amber-200 bg-amber-50/50 dark:border-amber-800 dark:bg-amber-950/20'
                        : 'border-blue-200 bg-blue-50/50 dark:border-blue-800 dark:bg-blue-950/20'
                        }`}>
                        <CardHeader>
                            <CardTitle className={`flex items-center gap-2 ${carryoverSummary.is_processed
                                ? 'text-amber-700 dark:text-amber-400'
                                : 'text-blue-700 dark:text-blue-400'
                                }`}>
                                <Banknote className="h-5 w-5" />
                                Carryover Credits to {carryoverSummary.to_year} (For Conversion/Leave)
                                {!carryoverSummary.is_processed && (
                                    <Badge variant="outline" className="ml-2 border-blue-500 text-blue-600">Projected</Badge>
                                )}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                                <div className="p-3 rounded-lg bg-background border">
                                    <p className="text-sm text-muted-foreground">Balance from {year}</p>
                                    <p className="text-xl font-bold">{carryoverSummary.credits_from_year?.toFixed(2) || summary.balance.toFixed(2)}</p>
                                </div>
                                <div className="p-3 rounded-lg bg-background border">
                                    <p className="text-sm text-muted-foreground">Carryover for Conversion (Max 4)</p>
                                    <p className={`text-xl font-bold ${carryoverSummary.is_processed ? 'text-amber-600' : 'text-blue-600'}`}>
                                        {carryoverSummary.carryover_credits.toFixed(2)}
                                    </p>
                                </div>
                                <div className="p-3 rounded-lg bg-background border">
                                    <p className="text-sm text-muted-foreground">Forfeited</p>
                                    <p className="text-xl font-bold text-red-600">{carryoverSummary.forfeited_credits.toFixed(2)}</p>
                                </div>
                                <div className="p-3 rounded-lg bg-background border">
                                    <p className="text-sm text-muted-foreground">Status</p>
                                    {!carryoverSummary.is_processed ? (
                                        <div className="flex items-center gap-1">
                                            <AlertCircle className="h-4 w-4 text-blue-500" />
                                            <span className="text-blue-600 font-medium">Not Processed</span>
                                        </div>
                                    ) : carryoverSummary.cash_converted ? (
                                        <div className="flex items-center gap-1">
                                            <CheckCircle className="h-4 w-4 text-green-500" />
                                            <span className="text-green-600 font-medium">Converted</span>
                                        </div>
                                    ) : carryoverSummary.is_expired ? (
                                        <div className="flex items-center gap-1">
                                            <AlertCircle className="h-4 w-4 text-red-500" />
                                            <span className="text-red-600 font-medium">Expired</span>
                                        </div>
                                    ) : (
                                        <div className="flex items-center gap-1">
                                            <AlertCircle className="h-4 w-4 text-amber-500" />
                                            <span className="text-amber-600 font-medium">Available until March</span>
                                        </div>
                                    )}
                                </div>
                            </div>
                            <Alert className={`${carryoverSummary.is_expired
                                ? 'border-red-300 bg-red-100/50 dark:border-red-700 dark:bg-red-900/30'
                                : carryoverSummary.is_processed
                                    ? 'border-amber-300 bg-amber-100/50 dark:border-amber-700 dark:bg-amber-900/30'
                                    : 'border-blue-300 bg-blue-100/50 dark:border-blue-700 dark:bg-blue-900/30'
                                }`}>
                                <AlertCircle className={`h-4 w-4 ${carryoverSummary.is_expired ? 'text-red-600' : carryoverSummary.is_processed ? 'text-amber-600' : 'text-blue-600'}`} />
                                <AlertTitle className={carryoverSummary.is_expired ? 'text-red-700 dark:text-red-400' : carryoverSummary.is_processed ? 'text-amber-700 dark:text-amber-400' : 'text-blue-700 dark:text-blue-400'}>
                                    {carryoverSummary.is_expired ? 'Credits Expired' : carryoverSummary.is_processed ? 'Important Note' : 'Projected Carryover'}
                                </AlertTitle>
                                <AlertDescription className={carryoverSummary.is_expired ? 'text-red-600 dark:text-red-300' : carryoverSummary.is_processed ? 'text-amber-600 dark:text-amber-300' : 'text-blue-600 dark:text-blue-300'}>
                                    {carryoverSummary.is_expired
                                        ? <p> These carryover credits have <strong>expired</strong> as March has passed. They can no longer be used for leave requests or conversion. </p>
                                        : carryoverSummary.is_processed
                                            ? <p> Carryover credits can be used for <strong>leave requests or conversion</strong> until end of March. After March, unused carryover credits will expire. Maximum of 4 credits can be carried over per year. </p>
                                            : <p> This is a <strong>projected carryover</strong> based on the current balance. The actual carryover will be processed at year end (January 1st, {carryoverSummary.to_year}). </p>
                                    }
                                </AlertDescription>
                            </Alert>
                        </CardContent>
                    </Card>
                )}

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
                            {monthlyCredits.length === 0 && !carryoverReceived ? (
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
                                            {/* Show carryover received as first row if applicable */}
                                            {carryoverReceived && (
                                                <TableRow className="bg-green-50 dark:bg-green-900/20">
                                                    <TableCell className="font-medium text-green-700 dark:text-green-400">
                                                        Carryover from {carryoverReceived.from_year}
                                                        {carryoverReceived.is_first_regularization && (
                                                            <Badge variant="outline" className="ml-2 text-xs border-purple-500 text-purple-600">
                                                                First Reg
                                                            </Badge>
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-right text-green-600">
                                                        +{carryoverReceived.credits.toFixed(2)}
                                                    </TableCell>
                                                    <TableCell className="text-right text-red-600">
                                                        {carryoverReceived.credits_used && carryoverReceived.credits_used > 0
                                                            ? `-${carryoverReceived.credits_used.toFixed(2)}`
                                                            : '—'}
                                                    </TableCell>
                                                    <TableCell className="text-right font-medium text-green-600">
                                                        {(carryoverReceived.credits_balance ?? carryoverReceived.credits).toFixed(2)}
                                                    </TableCell>
                                                </TableRow>
                                            )}
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
                                                        {leave.has_partial_denial && leave.approved_days !== null
                                                            ? leave.approved_days
                                                            : leave.days_requested}
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
                                                    {leaveRequests.reduce((sum, l) => sum + (l.has_partial_denial && l.approved_days !== null ? l.approved_days : l.days_requested), 0)}
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
