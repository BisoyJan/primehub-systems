import React, { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { ArrowLeft, Calendar, TrendingUp, TrendingDown, CreditCard, FileText, Banknote, AlertCircle, CheckCircle, Pencil, Loader2, AlertTriangle, Info, History, Clock } from 'lucide-react';
import { useFlashMessage, usePageLoading, usePageMeta } from '@/hooks';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { index as creditsIndexRoute } from '@/routes/leave-requests/credits';
import { show as leaveShowRoute } from '@/routes/leave-requests';
import { creditsUpdateCarryover, creditsUpdateMonthly } from '@/actions/App/Http/Controllers/LeaveRequestController';
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
    id: number;
    credits: number;
    credits_used?: number;
    credits_balance?: number;
    from_year: number;
    is_first_regularization: boolean;
    cash_converted: boolean;
    cash_converted_at: string | null;
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
    canEdit: boolean;
    pendingLeaveInfo: {
        pending_count: number;
        pending_credits: number;
        future_accrual: number;
        pending_requests: Array<{
            id: number;
            leave_type: string;
            start_date: string;
            end_date: string;
            days_requested: number;
        }>;
    };
    creditEditHistory: Array<{
        id: number;
        event: string;
        description: string;
        reason: string | null;
        editor_name: string;
        old_value: number | null;
        new_value: number | null;
        month: number | null;
        unabsorbed: number;
        created_at: string;
    }>;
}

export default function Show({ user, year, summary, carryoverSummary, carryoverReceived, monthlyCredits, leaveRequests, availableYears, canViewAll, canEdit = false, pendingLeaveInfo = { pending_count: 0, pending_credits: 0, future_accrual: 0, pending_requests: [] }, creditEditHistory = [] }: Props) {
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

    // Edit carryover dialog state
    const [isEditCarryoverOpen, setIsEditCarryoverOpen] = useState(false);
    const [pendingAcknowledged, setPendingAcknowledged] = useState(false);
    const editCarryoverForm = useForm({
        carryover_credits: carryoverReceived?.credits ?? 0,
        year: year,
        reason: '',
        notes: '',
    });

    const openEditCarryover = () => {
        if (!carryoverReceived) return;
        setPendingAcknowledged(false);
        editCarryoverForm.setData({
            carryover_credits: carryoverReceived.credits,
            year: year,
            reason: '',
            notes: '',
        });
        setIsEditCarryoverOpen(true);
    };

    const submitEditCarryover = () => {
        editCarryoverForm.put(creditsUpdateCarryover({ user: user.id }).url, {
            preserveScroll: true,
            onSuccess: () => {
                setIsEditCarryoverOpen(false);
                editCarryoverForm.reset();
            },
        });
    };

    // Edit monthly credit dialog state
    const [isEditMonthlyOpen, setIsEditMonthlyOpen] = useState(false);
    const [editingCredit, setEditingCredit] = useState<MonthlyCredit | null>(null);
    const editMonthlyForm = useForm({
        credits_earned: 0,
        reason: '',
    });

    const openEditMonthly = (credit: MonthlyCredit) => {
        setEditingCredit(credit);
        setPendingAcknowledged(false);
        editMonthlyForm.setData({
            credits_earned: credit.credits_earned,
            reason: '',
        });
        setIsEditMonthlyOpen(true);
    };

    const submitEditMonthly = () => {
        if (!editingCredit) return;
        editMonthlyForm.put(creditsUpdateMonthly({ user: user.id, leaveCredit: editingCredit.id }).url, {
            preserveScroll: true,
            onSuccess: () => {
                setIsEditMonthlyOpen(false);
                setEditingCredit(null);
                editMonthlyForm.reset();
            },
        });
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
                    <Card className={`${carryoverSummary.cash_converted
                        ? 'border-green-200 bg-green-50/50 dark:border-green-800 dark:bg-green-950/20'
                        : carryoverSummary.is_processed
                            ? 'border-amber-200 bg-amber-50/50 dark:border-amber-800 dark:bg-amber-950/20'
                            : 'border-blue-200 bg-blue-50/50 dark:border-blue-800 dark:bg-blue-950/20'
                        }`}>
                        <CardHeader>
                            <CardTitle className={`flex items-center gap-2 ${carryoverSummary.cash_converted
                                ? 'text-green-700 dark:text-green-400'
                                : carryoverSummary.is_processed
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
                            <Alert className={`${carryoverSummary.cash_converted
                                ? 'border-green-300 bg-green-100/50 dark:border-green-700 dark:bg-green-900/30'
                                : carryoverSummary.is_expired
                                    ? 'border-red-300 bg-red-100/50 dark:border-red-700 dark:bg-red-900/30'
                                    : carryoverSummary.is_processed
                                        ? 'border-amber-300 bg-amber-100/50 dark:border-amber-700 dark:bg-amber-900/30'
                                        : 'border-blue-300 bg-blue-100/50 dark:border-blue-700 dark:bg-blue-900/30'
                                }`}>
                                <AlertCircle className={`h-4 w-4 ${carryoverSummary.cash_converted ? 'text-green-600' : carryoverSummary.is_expired ? 'text-red-600' : carryoverSummary.is_processed ? 'text-amber-600' : 'text-blue-600'}`} />
                                <AlertTitle className={carryoverSummary.cash_converted ? 'text-green-700 dark:text-green-400' : carryoverSummary.is_expired ? 'text-red-700 dark:text-red-400' : carryoverSummary.is_processed ? 'text-amber-700 dark:text-amber-400' : 'text-blue-700 dark:text-blue-400'}>
                                    {carryoverSummary.cash_converted ? 'Cash Converted' : carryoverSummary.is_expired ? 'Credits Expired' : carryoverSummary.is_processed ? 'Important Note' : 'Projected Carryover'}
                                </AlertTitle>
                                <AlertDescription className={carryoverSummary.cash_converted ? 'text-green-600 dark:text-green-300' : carryoverSummary.is_expired ? 'text-red-600 dark:text-red-300' : carryoverSummary.is_processed ? 'text-amber-600 dark:text-amber-300' : 'text-blue-600 dark:text-blue-300'}>
                                    {carryoverSummary.cash_converted
                                        ? <p> These carryover credits have been <strong>converted to cash</strong>{carryoverSummary.cash_converted_at ? ` on ${carryoverSummary.cash_converted_at}` : ''}. They are no longer available for leave requests. </p>
                                        : carryoverSummary.is_expired
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
                                                {canEdit && <TableHead className="text-center w-12"></TableHead>}
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {/* Show carryover received as first row if applicable */}
                                            {carryoverReceived && (
                                                <TableRow className={carryoverReceived.cash_converted
                                                    ? 'bg-gray-50 dark:bg-gray-900/20'
                                                    : 'bg-green-50 dark:bg-green-900/20'
                                                }>
                                                    <TableCell className={`font-medium ${carryoverReceived.cash_converted ? 'text-gray-500 dark:text-gray-400' : 'text-green-700 dark:text-green-400'}`}>
                                                        <div className="flex items-center gap-2 flex-wrap">
                                                            <span className={carryoverReceived.cash_converted ? 'line-through' : ''}>
                                                                Carryover from {carryoverReceived.from_year}
                                                            </span>
                                                            {carryoverReceived.is_first_regularization && (
                                                                <Badge variant="outline" className="text-xs border-purple-500 text-purple-600">
                                                                    First Reg
                                                                </Badge>
                                                            )}
                                                            {carryoverReceived.cash_converted && (
                                                                <Badge variant="outline" className="text-xs border-green-500 text-green-600 bg-green-50">
                                                                    <Banknote className="h-3 w-3 mr-1" />
                                                                    Converted{carryoverReceived.cash_converted_at ? ` ${carryoverReceived.cash_converted_at}` : ''}
                                                                </Badge>
                                                            )}
                                                        </div>
                                                    </TableCell>
                                                    <TableCell className={`text-right ${carryoverReceived.cash_converted ? 'text-gray-400 line-through' : 'text-green-600'}`}>
                                                        +{carryoverReceived.credits.toFixed(2)}
                                                    </TableCell>
                                                    <TableCell className={`text-right ${carryoverReceived.cash_converted ? 'text-gray-400' : 'text-red-600'}`}>
                                                        {carryoverReceived.credits_used && carryoverReceived.credits_used > 0
                                                            ? `-${carryoverReceived.credits_used.toFixed(2)}`
                                                            : '—'}
                                                    </TableCell>
                                                    <TableCell className={`text-right font-medium ${carryoverReceived.cash_converted ? 'text-gray-400' : 'text-green-600'}`}>
                                                        {(carryoverReceived.credits_balance ?? carryoverReceived.credits).toFixed(2)}
                                                    </TableCell>
                                                    {canEdit && (
                                                        <TableCell className="text-center">
                                                            <Button variant="ghost" size="icon" className="h-7 w-7" title="Edit Carryover" onClick={openEditCarryover}>
                                                                <Pencil className="h-3.5 w-3.5" />
                                                            </Button>
                                                        </TableCell>
                                                    )}
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
                                                    {canEdit && (
                                                        <TableCell className="text-center">
                                                            <Button variant="ghost" size="icon" className="h-7 w-7" title="Edit Monthly Credit" onClick={() => openEditMonthly(credit)}>
                                                                <Pencil className="h-3.5 w-3.5" />
                                                            </Button>
                                                        </TableCell>
                                                    )}
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

                {/* Credit Edit History */}
                {canViewAll && creditEditHistory.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <History className="h-5 w-5" />
                                Credit Edit History ({year})
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-3">
                                {creditEditHistory.map((entry) => (
                                    <div key={entry.id} className="flex items-start gap-3 p-3 rounded-lg border bg-muted/30">
                                        <div className={`mt-0.5 rounded-full p-1.5 shrink-0 ${entry.event === 'carryover_manually_adjusted'
                                                ? 'bg-green-100 text-green-600 dark:bg-green-900/40 dark:text-green-400'
                                                : 'bg-blue-100 text-blue-600 dark:bg-blue-900/40 dark:text-blue-400'
                                            }`}>
                                            <Pencil className="h-3.5 w-3.5" />
                                        </div>
                                        <div className="flex-1 min-w-0 space-y-1">
                                            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                <Badge variant="outline" className="text-xs">
                                                    {entry.event === 'carryover_manually_adjusted' ? 'Carryover' : `Month ${entry.month}`}
                                                </Badge>
                                                <span className="text-sm font-medium">
                                                    {entry.old_value !== null && entry.new_value !== null
                                                        ? <>{entry.old_value.toFixed(2)} → {entry.new_value.toFixed(2)}</>
                                                        : 'Adjusted'}
                                                </span>
                                                {entry.unabsorbed > 0 && (
                                                    <Badge variant="destructive" className="text-xs">
                                                        {entry.unabsorbed.toFixed(2)} unabsorbed
                                                    </Badge>
                                                )}
                                            </div>
                                            {entry.reason && (
                                                <p className="text-sm text-muted-foreground">
                                                    <span className="font-medium">Reason:</span> {entry.reason}
                                                </p>
                                            )}
                                            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                                                <span className="flex items-center gap-1">
                                                    <Pencil className="h-3 w-3" />
                                                    {entry.editor_name}
                                                </span>
                                                <span className="flex items-center gap-1">
                                                    <Clock className="h-3 w-3" />
                                                    {format(new Date(entry.created_at), 'MMM d, yyyy h:mm a')}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>

            {/* Edit Carryover Credits Dialog */}
            <Dialog open={isEditCarryoverOpen} onOpenChange={(open) => {
                if (!open) {
                    setIsEditCarryoverOpen(false);
                    editCarryoverForm.reset();
                }
            }}>
                <DialogContent className="max-w-[90vw] sm:max-w-md max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Edit Carryover Credits</DialogTitle>
                        <DialogDescription>
                            Adjust carryover credits for <strong>{user.name}</strong> ({year})
                        </DialogDescription>
                    </DialogHeader>
                    {carryoverReceived && (
                        <div className="space-y-4">
                            <div className="grid grid-cols-2 gap-4 p-3 rounded-lg bg-muted/50 border text-sm">
                                <div>
                                    <p className="text-muted-foreground">Current Credits</p>
                                    <p className="font-semibold text-lg">{carryoverReceived.credits.toFixed(2)}</p>
                                </div>
                                <div>
                                    <p className="text-muted-foreground">Consumed</p>
                                    <p className="font-semibold text-lg text-red-600">{(carryoverReceived.credits_used ?? 0).toFixed(2)}</p>
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="carryover_credits">New Carryover Credits</Label>
                                <Input
                                    id="carryover_credits"
                                    type="number"
                                    step="0.25"
                                    min="0"
                                    max="30"
                                    value={editCarryoverForm.data.carryover_credits}
                                    onChange={(e) => editCarryoverForm.setData('carryover_credits', parseFloat(e.target.value) || 0)}
                                />
                                {editCarryoverForm.errors.carryover_credits && (
                                    <p className="text-sm text-red-600">{editCarryoverForm.errors.carryover_credits}</p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="carryover_reason">Reason <span className="text-red-500">*</span></Label>
                                <Textarea
                                    id="carryover_reason"
                                    placeholder="Explain why the carryover credits are being adjusted..."
                                    value={editCarryoverForm.data.reason}
                                    onChange={(e) => editCarryoverForm.setData('reason', e.target.value)}
                                    rows={3}
                                />
                                {editCarryoverForm.errors.reason && (
                                    <p className="text-sm text-red-600">{editCarryoverForm.errors.reason}</p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="carryover_notes">Notes (optional)</Label>
                                <Textarea
                                    id="carryover_notes"
                                    placeholder="Any additional notes..."
                                    value={editCarryoverForm.data.notes}
                                    onChange={(e) => editCarryoverForm.setData('notes', e.target.value)}
                                    rows={2}
                                />
                            </div>

                            {editCarryoverForm.data.carryover_credits < carryoverReceived.credits && (
                                <div className="flex items-start gap-2 p-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-700 text-sm dark:bg-amber-950 dark:border-amber-800 dark:text-amber-200">
                                    <AlertTriangle className="h-4 w-4 mt-0.5 shrink-0" />
                                    <p>Reducing carryover credits may trigger cascading adjustments to monthly credit records if the consumed amount exceeds the new value.</p>
                                </div>
                            )}

                            {pendingLeaveInfo.pending_count > 0 && (
                                <div className="flex items-start gap-2 p-3 rounded-lg bg-blue-50 border border-blue-200 text-blue-700 text-sm dark:bg-blue-950 dark:border-blue-800 dark:text-blue-200">
                                    <Info className="h-4 w-4 mt-0.5 shrink-0" />
                                    <div className="space-y-2">
                                        <p className="font-medium">Pending Leave Requests</p>
                                        <p>
                                            This employee has <strong>{pendingLeaveInfo.pending_count}</strong> pending leave request(s) totaling{' '}
                                            <strong>{pendingLeaveInfo.pending_credits.toFixed(2)}</strong> credit(s) awaiting approval.
                                            {pendingLeaveInfo.future_accrual > 0 && (
                                                <> Projected future accrual of <strong>{pendingLeaveInfo.future_accrual.toFixed(2)}</strong> credit(s) before the latest leave date is factored in.</>
                                            )}
                                            {editCarryoverForm.data.carryover_credits < carryoverReceived.credits && (
                                                <> If approved, the effective balance would be further reduced.</>
                                            )}
                                        </p>
                                        <ul className="space-y-1 ml-1">
                                            {pendingLeaveInfo.pending_requests.map((req) => (
                                                <li key={req.id} className="flex items-center gap-1.5">
                                                    <span className="text-blue-400">•</span>
                                                    <Link
                                                        href={leaveShowRoute(req.id).url}
                                                        className="underline hover:text-blue-900 dark:hover:text-blue-100"
                                                        target="_blank"
                                                    >
                                                        {req.leave_type} — {format(new Date(req.start_date), 'MMM d')} to {format(new Date(req.end_date), 'MMM d, yyyy')} ({req.days_requested} day{req.days_requested !== 1 ? 's' : ''})
                                                    </Link>
                                                </li>
                                            ))}
                                        </ul>
                                        <p className="text-xs text-blue-600 dark:text-blue-300 italic">
                                            Tip: Consider denying, editing the requested dates, or asking the employee to cancel pending requests before reducing credits to avoid insufficient balance issues.
                                        </p>
                                    </div>
                                </div>
                            )}

                            {pendingLeaveInfo.pending_count > 0 && (summary.balance - (carryoverReceived.credits - editCarryoverForm.data.carryover_credits) + pendingLeaveInfo.future_accrual) < pendingLeaveInfo.pending_credits && (
                                <label className="flex items-start gap-2 p-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-700 text-sm cursor-pointer dark:bg-amber-950 dark:border-amber-800 dark:text-amber-200">
                                    <input
                                        type="checkbox"
                                        checked={pendingAcknowledged}
                                        onChange={(e) => setPendingAcknowledged(e.target.checked)}
                                        className="mt-0.5 rounded border-amber-300"
                                    />
                                    <span>I understand this may cause insufficient balance for pending leave requests (including projected future accruals).</span>
                                </label>
                            )}
                        </div>
                    )}
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setIsEditCarryoverOpen(false)} disabled={editCarryoverForm.processing}>
                            Cancel
                        </Button>
                        <Button onClick={submitEditCarryover} disabled={
                            editCarryoverForm.processing
                            || !editCarryoverForm.data.reason
                            || (carryoverReceived != null && pendingLeaveInfo.pending_count > 0 && (summary.balance - (carryoverReceived.credits - editCarryoverForm.data.carryover_credits) + pendingLeaveInfo.future_accrual) < pendingLeaveInfo.pending_credits && !pendingAcknowledged)
                        }>
                            {editCarryoverForm.processing ? (
                                <><Loader2 className="h-4 w-4 mr-2 animate-spin" /> Saving...</>
                            ) : (
                                'Save Changes'
                            )}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Edit Monthly Credit Dialog */}
            <Dialog open={isEditMonthlyOpen} onOpenChange={(open) => {
                if (!open) {
                    setIsEditMonthlyOpen(false);
                    setEditingCredit(null);
                    editMonthlyForm.reset();
                }
            }}>
                <DialogContent className="max-w-[90vw] sm:max-w-md max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Edit Monthly Credit</DialogTitle>
                        <DialogDescription>
                            {editingCredit && (
                                <>Adjust <strong>{editingCredit.month_name}</strong> credits for <strong>{user.name}</strong></>
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    {editingCredit && (
                        <div className="space-y-4">
                            <div className="grid grid-cols-3 gap-4 p-3 rounded-lg bg-muted/50 border text-sm">
                                <div>
                                    <p className="text-muted-foreground">Current Earned</p>
                                    <p className="font-semibold text-lg">{editingCredit.credits_earned.toFixed(2)}</p>
                                </div>
                                <div>
                                    <p className="text-muted-foreground">Used</p>
                                    <p className="font-semibold text-lg text-red-600">{editingCredit.credits_used.toFixed(2)}</p>
                                </div>
                                <div>
                                    <p className="text-muted-foreground">Balance</p>
                                    <p className="font-semibold text-lg">{editingCredit.credits_balance.toFixed(2)}</p>
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="credits_earned">New Credits Earned</Label>
                                <Input
                                    id="credits_earned"
                                    type="number"
                                    step="0.25"
                                    min="0"
                                    max="20"
                                    value={editMonthlyForm.data.credits_earned}
                                    onChange={(e) => editMonthlyForm.setData('credits_earned', parseFloat(e.target.value) || 0)}
                                />
                                {editMonthlyForm.errors.credits_earned && (
                                    <p className="text-sm text-red-600">{editMonthlyForm.errors.credits_earned}</p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="monthly_reason">Reason <span className="text-red-500">*</span></Label>
                                <Textarea
                                    id="monthly_reason"
                                    placeholder="Explain why the monthly credit is being adjusted..."
                                    value={editMonthlyForm.data.reason}
                                    onChange={(e) => editMonthlyForm.setData('reason', e.target.value)}
                                    rows={3}
                                />
                                {editMonthlyForm.errors.reason && (
                                    <p className="text-sm text-red-600">{editMonthlyForm.errors.reason}</p>
                                )}
                            </div>

                            {editMonthlyForm.data.credits_earned < editingCredit.credits_used && (
                                <div className="flex items-start gap-2 p-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-700 text-sm dark:bg-amber-950 dark:border-amber-800 dark:text-amber-200">
                                    <AlertTriangle className="h-4 w-4 mt-0.5 shrink-0" />
                                    <p>Setting earned credits below the used amount will result in a negative balance for this month. The excess consumption may cascade to other months.</p>
                                </div>
                            )}

                            {pendingLeaveInfo.pending_count > 0 && (
                                <div className="flex items-start gap-2 p-3 rounded-lg bg-blue-50 border border-blue-200 text-blue-700 text-sm dark:bg-blue-950 dark:border-blue-800 dark:text-blue-200">
                                    <Info className="h-4 w-4 mt-0.5 shrink-0" />
                                    <div className="space-y-2">
                                        <p className="font-medium">Pending Leave Requests</p>
                                        <p>
                                            This employee has <strong>{pendingLeaveInfo.pending_count}</strong> pending leave request(s) totaling{' '}
                                            <strong>{pendingLeaveInfo.pending_credits.toFixed(2)}</strong> credit(s) awaiting approval.
                                            {pendingLeaveInfo.future_accrual > 0 && (
                                                <> Projected future accrual of <strong>{pendingLeaveInfo.future_accrual.toFixed(2)}</strong> credit(s) before the latest leave date is factored in.</>
                                            )}
                                            {editMonthlyForm.data.credits_earned < editingCredit.credits_earned && (
                                                <> If approved, the effective balance would be further reduced.</>
                                            )}
                                        </p>
                                        <ul className="space-y-1 ml-1">
                                            {pendingLeaveInfo.pending_requests.map((req) => (
                                                <li key={req.id} className="flex items-center gap-1.5">
                                                    <span className="text-blue-400">•</span>
                                                    <Link
                                                        href={leaveShowRoute(req.id).url}
                                                        className="underline hover:text-blue-900 dark:hover:text-blue-100"
                                                        target="_blank"
                                                    >
                                                        {req.leave_type} — {format(new Date(req.start_date), 'MMM d')} to {format(new Date(req.end_date), 'MMM d, yyyy')} ({req.days_requested} day{req.days_requested !== 1 ? 's' : ''})
                                                    </Link>
                                                </li>
                                            ))}
                                        </ul>
                                        <p className="text-xs text-blue-600 dark:text-blue-300 italic">
                                            Tip: Consider denying, updating the requested dates, or asking the employee to cancel pending requests before reducing credits to avoid insufficient balance issues.
                                        </p>
                                    </div>
                                </div>
                            )}

                            {pendingLeaveInfo.pending_count > 0 && (summary.balance - (editingCredit.credits_earned - editMonthlyForm.data.credits_earned) + pendingLeaveInfo.future_accrual) < pendingLeaveInfo.pending_credits && (
                                <label className="flex items-start gap-2 p-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-700 text-sm cursor-pointer dark:bg-amber-950 dark:border-amber-800 dark:text-amber-200">
                                    <input
                                        type="checkbox"
                                        checked={pendingAcknowledged}
                                        onChange={(e) => setPendingAcknowledged(e.target.checked)}
                                        className="mt-0.5 rounded border-amber-300"
                                    />
                                    <span>I understand this may cause insufficient balance for pending leave requests (including projected future accruals).</span>
                                </label>
                            )}
                        </div>
                    )}
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setIsEditMonthlyOpen(false)} disabled={editMonthlyForm.processing}>
                            Cancel
                        </Button>
                        <Button onClick={submitEditMonthly} disabled={
                            editMonthlyForm.processing
                            || !editMonthlyForm.data.reason
                            || (editingCredit !== null && pendingLeaveInfo.pending_count > 0 && (summary.balance - (editingCredit.credits_earned - editMonthlyForm.data.credits_earned) + pendingLeaveInfo.future_accrual) < pendingLeaveInfo.pending_credits && !pendingAcknowledged)
                        }>
                            {editMonthlyForm.processing ? (
                                <><Loader2 className="h-4 w-4 mr-2 animate-spin" /> Saving...</>
                            ) : (
                                'Save Changes'
                            )}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
