import React, { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { format, parseISO } from 'date-fns';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Textarea } from '@/components/ui/textarea';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { ArrowLeft, Check, X, Ban, Info } from 'lucide-react';
import { toast } from 'sonner';

interface User {
    id: number;
    name: string;
    email: string;
}

interface LeaveRequest {
    id: number;
    user: User;
    reviewer?: User;
    leave_type: string;
    start_date: string;
    end_date: string;
    days_requested: number;
    reason: string;
    team_lead_email: string;
    campaign_department: string;
    medical_cert_submitted: boolean;
    status: string;
    reviewed_at: string | null;
    review_notes: string | null;
    credits_deducted: number | null;
    attendance_points_at_request: number;
    created_at: string;
}

interface Props {
    leaveRequest: LeaveRequest;
    isAdmin: boolean;
    canCancel: boolean;
}

export default function Show({ leaveRequest, isAdmin, canCancel }: Props) {
    const [showApproveDialog, setShowApproveDialog] = useState(false);
    const [showDenyDialog, setShowDenyDialog] = useState(false);
    const [showCancelDialog, setShowCancelDialog] = useState(false);

    const approveForm = useForm({ review_notes: '' });
    const denyForm = useForm({ review_notes: '' });

    const handleApprove = () => {
        approveForm.post(`/leave-requests/${leaveRequest.id}/approve`, {
            onSuccess: () => {
                setShowApproveDialog(false);
                toast.success('Leave request approved successfully');
            },
        });
    };

    const handleDeny = () => {
        denyForm.post(`/leave-requests/${leaveRequest.id}/deny`, {
            onSuccess: () => {
                setShowDenyDialog(false);
                toast.success('Leave request denied');
            },
        });
    };

    const handleCancel = () => {
        router.post(
            `/leave-requests/${leaveRequest.id}/cancel`,
            {},
            {
                onSuccess: () => {
                    setShowCancelDialog(false);
                    toast.success('Leave request cancelled');
                },
            }
        );
    };

    const getStatusBadge = (status: string) => {
        const variants = {
            pending: 'bg-yellow-100 text-yellow-800 border-yellow-300',
            approved: 'bg-green-100 text-green-800 border-green-300',
            denied: 'bg-red-100 text-red-800 border-red-300',
            cancelled: 'bg-gray-100 text-gray-800 border-gray-300',
        };
        return (
            <Badge variant="outline" className={variants[status as keyof typeof variants]}>
                {status.toUpperCase()}
            </Badge>
        );
    };

    return (
        <AppLayout>
            <Head title={`Leave Request #${leaveRequest.id}`} />

            <div className="container mx-auto px-4 py-8 max-w-4xl">
                <div className="mb-6 flex justify-between items-center">
                    <Link href="/leave-requests">
                        <Button variant="ghost">
                            <ArrowLeft className="mr-2 h-4 w-4" />
                            Back to List
                        </Button>
                    </Link>

                    <div className="flex gap-2">
                        {isAdmin && leaveRequest.status === 'pending' && (
                            <>
                                <Button variant="default" onClick={() => setShowApproveDialog(true)}>
                                    <Check className="mr-2 h-4 w-4" />
                                    Approve
                                </Button>
                                <Button variant="destructive" onClick={() => setShowDenyDialog(true)}>
                                    <X className="mr-2 h-4 w-4" />
                                    Deny
                                </Button>
                            </>
                        )}
                        {canCancel && (
                            <Button variant="outline" onClick={() => setShowCancelDialog(true)}>
                                <Ban className="mr-2 h-4 w-4" />
                                Cancel Request
                            </Button>
                        )}
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <div className="flex justify-between items-start">
                            <div>
                                <CardTitle>Leave Request #{leaveRequest.id}</CardTitle>
                                <p className="text-sm text-muted-foreground mt-1">
                                    Submitted on {format(parseISO(leaveRequest.created_at), 'MMMM d, yyyy')}
                                </p>
                            </div>
                            {getStatusBadge(leaveRequest.status)}
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        {/* Employee Info */}
                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <p className="text-sm font-medium text-muted-foreground">Employee</p>
                                <p className="text-base">{leaveRequest.user.name}</p>
                            </div>
                            <div>
                                <p className="text-sm font-medium text-muted-foreground">Email</p>
                                <p className="text-base">{leaveRequest.user.email}</p>
                            </div>
                        </div>

                        {/* Leave Details */}
                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <p className="text-sm font-medium text-muted-foreground">Leave Type</p>
                                <Badge variant="secondary" className="mt-1">
                                    {leaveRequest.leave_type}
                                </Badge>
                            </div>
                            <div>
                                <p className="text-sm font-medium text-muted-foreground">Duration</p>
                                <p className="text-base">{leaveRequest.days_requested} days</p>
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <p className="text-sm font-medium text-muted-foreground">Start Date</p>
                                <p className="text-base">
                                    {format(parseISO(leaveRequest.start_date), 'MMMM d, yyyy')}
                                </p>
                            </div>
                            <div>
                                <p className="text-sm font-medium text-muted-foreground">End Date</p>
                                <p className="text-base">
                                    {format(parseISO(leaveRequest.end_date), 'MMMM d, yyyy')}
                                </p>
                            </div>
                        </div>

                        {/* Work Info */}
                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <p className="text-sm font-medium text-muted-foreground">Team Lead</p>
                                <p className="text-base">{leaveRequest.team_lead_email}</p>
                            </div>
                            <div>
                                <p className="text-sm font-medium text-muted-foreground">Campaign/Department</p>
                                <p className="text-base">{leaveRequest.campaign_department}</p>
                            </div>
                        </div>

                        {/* Medical Cert (if SL) */}
                        {leaveRequest.leave_type === 'SL' && (
                            <div>
                                <p className="text-sm font-medium text-muted-foreground">
                                    Medical Certificate
                                </p>
                                <p className="text-base">
                                    {leaveRequest.medical_cert_submitted ? 'Will be submitted' : 'Not required'}
                                </p>
                            </div>
                        )}

                        {/* Credits Info */}
                        {leaveRequest.credits_deducted && (
                            <div>
                                <p className="text-sm font-medium text-muted-foreground">Leave Credits Deducted</p>
                                <p className="text-base">{leaveRequest.credits_deducted} days</p>
                            </div>
                        )}

                        {/* Attendance Points at Request */}
                        <div>
                            <p className="text-sm font-medium text-muted-foreground">
                                Attendance Points at Request
                            </p>
                            <p className="text-base">{leaveRequest.attendance_points_at_request}</p>
                        </div>

                        {/* Reason */}
                        <div>
                            <p className="text-sm font-medium text-muted-foreground mb-2">Reason</p>
                            <Alert>
                                <Info className="h-4 w-4" />
                                <AlertDescription>{leaveRequest.reason}</AlertDescription>
                            </Alert>
                        </div>

                        {/* Review Info */}
                        {leaveRequest.reviewed_at && (
                            <div className="border-t pt-4">
                                <h3 className="font-semibold mb-4">Review Information</h3>
                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <p className="text-sm font-medium text-muted-foreground">Reviewed By</p>
                                        <p className="text-base">{leaveRequest.reviewer?.name || 'N/A'}</p>
                                    </div>
                                    <div>
                                        <p className="text-sm font-medium text-muted-foreground">Reviewed On</p>
                                        <p className="text-base">
                                            {format(parseISO(leaveRequest.reviewed_at), 'MMMM d, yyyy')}
                                        </p>
                                    </div>
                                </div>
                                {leaveRequest.review_notes && (
                                    <div className="mt-4">
                                        <p className="text-sm font-medium text-muted-foreground mb-2">
                                            Review Notes
                                        </p>
                                        <Alert>
                                            <AlertDescription>{leaveRequest.review_notes}</AlertDescription>
                                        </Alert>
                                    </div>
                                )}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            {/* Approve Dialog */}
            <Dialog open={showApproveDialog} onOpenChange={setShowApproveDialog}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Approve Leave Request</DialogTitle>
                        <DialogDescription>
                            Approve this leave request for {leaveRequest.user.name}?
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div>
                            <label className="text-sm font-medium">Review Notes (Optional)</label>
                            <Textarea
                                value={approveForm.data.review_notes}
                                onChange={(e) => approveForm.setData('review_notes', e.target.value)}
                                placeholder="Add any comments..."
                                rows={3}
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setShowApproveDialog(false)}>
                            Cancel
                        </Button>
                        <Button onClick={handleApprove} disabled={approveForm.processing}>
                            Approve
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Deny Dialog */}
            <Dialog open={showDenyDialog} onOpenChange={setShowDenyDialog}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Deny Leave Request</DialogTitle>
                        <DialogDescription>Provide a reason for denying this request.</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div>
                            <label className="text-sm font-medium">
                                Review Notes <span className="text-red-500">*</span>
                            </label>
                            <Textarea
                                value={denyForm.data.review_notes}
                                onChange={(e) => denyForm.setData('review_notes', e.target.value)}
                                placeholder="Reason for denial (required, minimum 10 characters)..."
                                rows={3}
                            />
                            {denyForm.errors.review_notes && (
                                <p className="text-sm text-red-500 mt-1">{denyForm.errors.review_notes}</p>
                            )}
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setShowDenyDialog(false)}>
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={handleDeny}
                            disabled={denyForm.processing}
                        >
                            Deny Request
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Cancel Dialog */}
            <Dialog open={showCancelDialog} onOpenChange={setShowCancelDialog}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Cancel Leave Request</DialogTitle>
                        <DialogDescription>
                            Are you sure you want to cancel this leave request? This action cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setShowCancelDialog(false)}>
                            No, Keep It
                        </Button>
                        <Button variant="destructive" onClick={handleCancel}>
                            Yes, Cancel Request
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
