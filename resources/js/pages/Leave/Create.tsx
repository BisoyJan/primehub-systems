import React, { useState, useEffect } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { format, differenceInDays, parseISO } from 'date-fns';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Checkbox } from '@/components/ui/checkbox';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { AlertCircle, Calendar, CreditCard } from 'lucide-react';

interface CreditsSummary {
    year: number;
    is_eligible: boolean;
    eligibility_date: string | null;
    monthly_rate: number;
    total_earned: number;
    total_used: number;
    balance: number;
}

interface Props {
    creditsSummary: CreditsSummary;
    attendancePoints: number;
    hasRecentAbsence: boolean;
    nextEligibleLeaveDate: string | null;
    teamLeadEmails: string[];
    campaigns: string[];
    twoWeeksFromNow: string;
}

export default function Create({
    creditsSummary,
    attendancePoints,
    hasRecentAbsence,
    nextEligibleLeaveDate,
    teamLeadEmails,
    campaigns,
    twoWeeksFromNow,
}: Props) {
    const { data, setData, post, processing, errors } = useForm({
        leave_type: '',
        start_date: '',
        end_date: '',
        reason: '',
        team_lead_email: '',
        campaign_department: '',
        medical_cert_submitted: false,
    });

    const [calculatedDays, setCalculatedDays] = useState<number>(0);
    const [validationWarnings, setValidationWarnings] = useState<string[]>([]);

    // Calculate days when dates change
    useEffect(() => {
        if (data.start_date && data.end_date) {
            try {
                const start = parseISO(data.start_date);
                const end = parseISO(data.end_date);
                const days = differenceInDays(end, start) + 1;
                setCalculatedDays(days > 0 ? days : 0);
            } catch {
                setCalculatedDays(0);
            }
        } else {
            setCalculatedDays(0);
        }
    }, [data.start_date, data.end_date]);

    // Real-time validation warnings
    useEffect(() => {
        const warnings: string[] = [];

        // Check eligibility
        if (!creditsSummary.is_eligible && ['VL', 'SL', 'BL'].includes(data.leave_type)) {
            warnings.push(
                `You are not eligible to use leave credits yet. Eligible on ${format(parseISO(creditsSummary.eligibility_date!), 'MMMM d, yyyy')}.`
            );
        }

        // Check 2-week notice (only for VL and BL, not SL as it's unpredictable)
        if (data.start_date && ['VL', 'BL'].includes(data.leave_type)) {
            const start = parseISO(data.start_date);
            const twoWeeks = parseISO(twoWeeksFromNow);
            if (start < twoWeeks) {
                warnings.push(
                    `Leave must be requested at least 2 weeks in advance. Earliest date: ${format(twoWeeks, 'MMMM d, yyyy')}`
                );
            }
        }

        // Check attendance points for VL/BL
        if (['VL', 'BL'].includes(data.leave_type) && attendancePoints > 6) {
            warnings.push(
                `You have ${attendancePoints} attendance points (must be ≤6 for Vacation Leave).`
            );
        }

        // Check recent absence for VL/BL
        if (['VL', 'BL'].includes(data.leave_type) && hasRecentAbsence) {
            warnings.push(
                `You had an absence in the last 30 days. Next eligible date: ${format(parseISO(nextEligibleLeaveDate!), 'MMMM d, yyyy')}`
            );
        }

        // Check leave credits balance
        if (['VL', 'SL', 'BL'].includes(data.leave_type) && calculatedDays > 0) {
            if (creditsSummary.balance < calculatedDays) {
                warnings.push(
                    `Insufficient leave credits. Available: ${creditsSummary.balance} days, Requested: ${calculatedDays} days`
                );
            }
        }

        setValidationWarnings(warnings);
    }, [
        data.leave_type,
        data.start_date,
        creditsSummary,
        attendancePoints,
        hasRecentAbsence,
        nextEligibleLeaveDate,
        calculatedDays,
        twoWeeksFromNow,
    ]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/leave-requests');
    };

    const requiresCredits = ['VL', 'SL', 'BL'].includes(data.leave_type);
    const remainingBalance = requiresCredits
        ? Math.max(0, creditsSummary.balance - calculatedDays)
        : creditsSummary.balance;

    return (
        <AppLayout>
            <Head title="Request Leave" />

            <div className="container mx-auto px-4 py-8 max-w-4xl">
                <div className="mb-6">
                    <h1 className="text-3xl font-bold">Request Leave</h1>
                    <p className="text-muted-foreground mt-2">
                        Submit a leave request for approval
                    </p>
                </div>

                {/* Leave Credits Summary */}
                {creditsSummary.is_eligible && (
                    <Card className="mb-6">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <CreditCard className="h-5 w-5" />
                                Leave Credits Balance
                            </CardTitle>
                            <CardDescription>
                                Year {creditsSummary.year} • Credits reset annually and do not carry over
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                                <div>
                                    <p className="text-sm text-muted-foreground">Available</p>
                                    <p className="text-2xl font-bold">{creditsSummary.balance}</p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">This Request</p>
                                    <p className="text-2xl font-bold text-orange-600">
                                        {requiresCredits && calculatedDays > 0 ? `-${calculatedDays}` : '0'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">Remaining</p>
                                    <p className="text-2xl font-bold text-green-600">
                                        {remainingBalance.toFixed(2)}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">Monthly Rate</p>
                                    <p className="text-2xl font-bold">{creditsSummary.monthly_rate}</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {!creditsSummary.is_eligible && creditsSummary.eligibility_date && (
                    <Alert className="mb-6">
                        <AlertCircle className="h-4 w-4" />
                        <AlertTitle>Not Eligible Yet</AlertTitle>
                        <AlertDescription>
                            You will be eligible to use leave credits on{' '}
                            <strong>
                                {format(parseISO(creditsSummary.eligibility_date), 'MMMM d, yyyy')}
                            </strong>
                            . You can still apply for non-credited leave types (SPL, LOA, LDV).
                        </AlertDescription>
                    </Alert>
                )}

                {/* Validation Warnings */}
                {validationWarnings.length > 0 && (
                    <Alert variant="destructive" className="mb-6">
                        <AlertCircle className="h-4 w-4" />
                        <AlertTitle>Validation Issues</AlertTitle>
                        <AlertDescription>
                            <ul className="list-disc list-inside space-y-1">
                                {validationWarnings.map((warning, idx) => (
                                    <li key={idx}>{warning}</li>
                                ))}
                            </ul>
                        </AlertDescription>
                    </Alert>
                )}

                {/* Leave Request Form */}
                <Card>
                    <CardHeader>
                        <CardTitle>Leave Details</CardTitle>
                        <CardDescription>Fill in the information below</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit} className="space-y-6">
                            {/* Leave Type */}
                            <div className="space-y-2">
                                <Label htmlFor="leave_type">
                                    Leave Type <span className="text-red-500">*</span>
                                </Label>
                                <Select
                                    value={data.leave_type}
                                    onValueChange={(value) => setData('leave_type', value)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select leave type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="VL">Vacation Leave (VL)</SelectItem>
                                        <SelectItem value="SL">Sick Leave (SL)</SelectItem>
                                        <SelectItem value="BL">Bereavement Leave (BL)</SelectItem>
                                        <SelectItem value="SPL">Solo Parent Leave (SPL)</SelectItem>
                                        <SelectItem value="LOA">Leave of Absence (LOA)</SelectItem>
                                        <SelectItem value="LDV">
                                            Leave Due to Domestic Violence (LDV)
                                        </SelectItem>
                                        <SelectItem value="UPTO">
                                            Unpaid Personal Time Off (UPTO)
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                {errors.leave_type && (
                                    <p className="text-sm text-red-500">{errors.leave_type}</p>
                                )}
                                {data.leave_type && (
                                    <p className="text-xs text-muted-foreground">
                                        {requiresCredits
                                            ? '✓ Deducts from leave credits'
                                            : '○ Does not deduct from leave credits'}
                                    </p>
                                )}
                            </div>

                            {/* Date Range */}
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <Label htmlFor="start_date">
                                        Start Date <span className="text-red-500">*</span>
                                    </Label>
                                    <Input
                                        id="start_date"
                                        type="date"
                                        value={data.start_date}
                                        onChange={(e) => setData('start_date', e.target.value)}
                                        min={new Date().toISOString().split('T')[0]}
                                    />
                                    {errors.start_date && (
                                        <p className="text-sm text-red-500">{errors.start_date}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="end_date">
                                        End Date <span className="text-red-500">*</span>
                                    </Label>
                                    <Input
                                        id="end_date"
                                        type="date"
                                        value={data.end_date}
                                        onChange={(e) => setData('end_date', e.target.value)}
                                        min={data.start_date || new Date().toISOString().split('T')[0]}
                                    />
                                    {errors.end_date && (
                                        <p className="text-sm text-red-500">{errors.end_date}</p>
                                    )}
                                </div>
                            </div>

                            {/* Calculated Days Display */}
                            {calculatedDays > 0 && (
                                <Alert>
                                    <Calendar className="h-4 w-4" />
                                    <AlertTitle>Duration</AlertTitle>
                                    <AlertDescription>
                                        <strong>{calculatedDays}</strong> day{calculatedDays !== 1 ? 's' : ''}{' '}
                                        requested
                                    </AlertDescription>
                                </Alert>
                            )}

                            {/* Team Lead Email */}
                            <div className="space-y-2">
                                <Label htmlFor="team_lead_email">
                                    Team Lead Email <span className="text-red-500">*</span>
                                </Label>
                                <Select
                                    value={data.team_lead_email}
                                    onValueChange={(value) => setData('team_lead_email', value)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select team lead" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {teamLeadEmails.map((email) => (
                                            <SelectItem key={email} value={email}>
                                                {email}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.team_lead_email && (
                                    <p className="text-sm text-red-500">{errors.team_lead_email}</p>
                                )}
                            </div>

                            {/* Campaign/Department */}
                            <div className="space-y-2">
                                <Label htmlFor="campaign_department">
                                    Campaign/Department <span className="text-red-500">*</span>
                                </Label>
                                <Select
                                    value={data.campaign_department}
                                    onValueChange={(value) => setData('campaign_department', value)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select campaign/department" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {campaigns.map((campaign) => (
                                            <SelectItem key={campaign} value={campaign}>
                                                {campaign}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.campaign_department && (
                                    <p className="text-sm text-red-500">{errors.campaign_department}</p>
                                )}
                            </div>

                            {/* Medical Certificate (for SL) */}
                            {data.leave_type === 'SL' && (
                                <div className="flex items-center space-x-2">
                                    <Checkbox
                                        id="medical_cert_submitted"
                                        checked={data.medical_cert_submitted}
                                        onCheckedChange={(checked) =>
                                            setData('medical_cert_submitted', checked as boolean)
                                        }
                                    />
                                    <Label htmlFor="medical_cert_submitted" className="font-normal">
                                        I will submit a medical certificate
                                    </Label>
                                </div>
                            )}

                            {/* Reason */}
                            <div className="space-y-2">
                                <Label htmlFor="reason">
                                    Reason <span className="text-red-500">*</span>
                                </Label>
                                <Textarea
                                    id="reason"
                                    value={data.reason}
                                    onChange={(e) => setData('reason', e.target.value)}
                                    placeholder="Please provide a detailed reason for your leave request..."
                                    rows={4}
                                    className="resize-none"
                                />
                                {errors.reason && <p className="text-sm text-red-500">{errors.reason}</p>}
                                <p className="text-xs text-muted-foreground">
                                    {data.reason.length}/1000 characters (minimum 10)
                                </p>
                            </div>

                            {/* Form Errors */}
                            {(errors as Record<string, string | string[]>).validation && (
                                <Alert variant="destructive">
                                    <AlertCircle className="h-4 w-4" />
                                    <AlertTitle>Cannot Submit Request</AlertTitle>
                                    <AlertDescription>
                                        <ul className="list-disc list-inside space-y-1">
                                            {Array.isArray((errors as Record<string, string | string[]>).validation) ? (
                                                ((errors as Record<string, string | string[]>).validation as string[]).map((error: string, idx: number) => (
                                                    <li key={idx}>{error}</li>
                                                ))
                                            ) : (
                                                <li>{(errors as Record<string, string | string[]>).validation as string}</li>
                                            )}
                                        </ul>
                                    </AlertDescription>
                                </Alert>
                            )}

                            {/* Submit Button */}
                            <div className="flex gap-4">
                                <Button type="submit" disabled={processing || validationWarnings.length > 0}>
                                    {processing ? 'Submitting...' : 'Submit Leave Request'}
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => router.visit('/leave-requests')}
                                >
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
