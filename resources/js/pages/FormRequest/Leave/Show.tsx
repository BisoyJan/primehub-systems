import React, { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { format, parseISO } from 'date-fns';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Textarea } from '@/components/ui/textarea';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Can } from '@/components/authorization';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { ArrowLeft, Check, X, Ban, Info, Trash2, CheckCircle, Clock, UserCheck, XCircle } from 'lucide-react';
import { toast } from 'sonner';
import { usePermission } from '@/hooks/use-permission';
import { index as leaveIndexRoute, approve as leaveApproveRoute, deny as leaveDenyRoute, cancel as leaveCancelRoute, destroy as leaveDestroyRoute } from '@/routes/leave-requests';

interface User {
    id: number;
    name: string;
    email: string;
}

interface LeaveRequest {
    id: number;
    user: User;
    reviewer?: User;
    admin_approver?: User;
    hr_approver?: User;
    tl_approver?: User;
    leave_type: string;
    start_date: string;
    end_date: string;
    days_requested: number;
    reason: string;
    campaign_department: string;
    medical_cert_submitted: boolean;
    status: string;
    reviewed_at: string | null;
    review_notes: string | null;
    admin_approved_at: string | null;
    admin_review_notes: string | null;
    hr_approved_at: string | null;
    hr_review_notes: string | null;
    requires_tl_approval: boolean;
    tl_approved_at: string | null;
    tl_review_notes: string | null;
    tl_rejected: boolean;
    credits_deducted: number | null;
    attendance_points_at_request: number;
    created_at: string;
}

interface Props {
    leaveRequest: LeaveRequest;
    isAdmin: boolean;
    isTeamLead?: boolean;
    canCancel: boolean;
    hasUserApproved: boolean;
    canTlApprove?: boolean;
    userRole: string;
}

export default function Show({ leaveRequest, isAdmin, canCancel, hasUserApproved, canTlApprove = false }: Props) {
    const [showApproveDialog, setShowApproveDialog] = useState(false);
    const [showDenyDialog, setShowDenyDialog] = useState(false);
    const [showCancelDialog, setShowCancelDialog] = useState(false);
    const [showDeleteDialog, setShowDeleteDialog] = useState(false);
    const [showTLApproveDialog, setShowTLApproveDialog] = useState(false);
    const [showTLDenyDialog, setShowTLDenyDialog] = useState(false);
    const { can } = usePermission();

    const approveForm = useForm({ review_notes: '' });
    const denyForm = useForm({ review_notes: '' });
    const tlApproveForm = useForm({ tl_review_notes: '' });
    const tlDenyForm = useForm({ tl_review_notes: '' });

    // Check if Admin/HR has approved
    const isAdminApproved = !!leaveRequest.admin_approved_at;
    const isHrApproved = !!leaveRequest.hr_approved_at;
    const isTlApproved = !!leaveRequest.tl_approved_at;
    const isFullyApproved = isAdminApproved && isHrApproved;

    // Determine if user can approve (not already approved by their role)
    const canUserApprove = isAdmin && leaveRequest.status === 'pending' && !hasUserApproved &&
        (!leaveRequest.requires_tl_approval || isTlApproved);

    const handleApprove = () => {
        approveForm.post(leaveApproveRoute(leaveRequest.id).url, {
            onSuccess: () => {
                setShowApproveDialog(false);
                toast.success('Leave request approved successfully');
            },
        });
    };

    const handleDeny = () => {
        denyForm.post(leaveDenyRoute(leaveRequest.id).url, {
            onSuccess: () => {
                setShowDenyDialog(false);
                toast.success('Leave request denied');
            },
        });
    };

    const handleTLApprove = () => {
        tlApproveForm.post(`/form-requests/leave-requests/${leaveRequest.id}/approve-tl`, {
            onSuccess: () => {
                setShowTLApproveDialog(false);
                toast.success('Leave request approved. Now pending Admin/HR approval.');
            },
        });
    };

    const handleTLDeny = () => {
        tlDenyForm.post(`/form-requests/leave-requests/${leaveRequest.id}/deny-tl`, {
            onSuccess: () => {
                setShowTLDenyDialog(false);
                toast.success('Leave request has been rejected.');
            },
        });
    };

    const handleCancel = () => {
        router.post(
            leaveCancelRoute(leaveRequest.id).url,
            {},
            {
                onSuccess: () => {
                    setShowCancelDialog(false);
                    toast.success('Leave request cancelled');
                },
            }
        );
    };

    const handleDelete = () => {
        router.delete(
            leaveDestroyRoute(leaveRequest.id).url,
            {
                onSuccess: () => {
                    setShowDeleteDialog(false);
                    toast.success('Leave request deleted');
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
                <div className="mb-6 flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">
                    <Link href={leaveIndexRoute().url}>
                        <Button variant="ghost" size="sm">
                            <ArrowLeft className="mr-2 h-4 w-4" />
                            Back to List
                        </Button>
                    </Link>

                    <div className="flex flex-wrap gap-2">
                        {/* Team Lead Approval Buttons */}
                        {canTlApprove && leaveRequest.status === 'pending' && (
                            <>
                                <Button variant="default" size="sm" onClick={() => setShowTLApproveDialog(true)}>
                                    <UserCheck className="mr-1 h-4 w-4" />
                                    <span className="hidden sm:inline">Approve (TL)</span>
                                </Button>
                                <Button variant="destructive" size="sm" onClick={() => setShowTLDenyDialog(true)}>
                                    <X className="mr-1 h-4 w-4" />
                                    <span className="hidden sm:inline">Reject (TL)</span>
                                </Button>
                            </>
                        )}
                        {/* Admin/HR Approval Buttons */}
                        {canUserApprove && (
                            <>
                                <Can permission="leave.approve">
                                    <Button variant="default" size="sm" onClick={() => setShowApproveDialog(true)}>
                                        <Check className="mr-1 h-4 w-4" />
                                        <span className="hidden sm:inline">Approve</span>
                                    </Button>
                                </Can>
                                <Can permission="leave.approve">
                                    <Button variant="destructive" size="sm" onClick={() => setShowDenyDialog(true)}>
                                        <X className="mr-1 h-4 w-4" />
                                        <span className="hidden sm:inline">Deny</span>
                                    </Button>
                                </Can>
                            </>
                        )}
                        {hasUserApproved && leaveRequest.status === 'pending' && (
                            <Badge variant="outline" className="bg-green-50 text-green-700 border-green-300">
                                <CheckCircle className="mr-1 h-3 w-3" />
                                You've Approved
                            </Badge>
                        )}
                        {canCancel && leaveRequest.status === 'pending' && (
                            <Can permission="leave.cancel">
                                <Button variant="outline" size="sm" onClick={() => setShowCancelDialog(true)}>
                                    <Ban className="mr-1 h-4 w-4" />
                                    <span className="hidden sm:inline">Cancel</span>
                                </Button>
                            </Can>
                        )}
                        {can('leave.delete') && (
                            <Button variant="destructive" size="sm" onClick={() => setShowDeleteDialog(true)}>
                                <Trash2 className="mr-1 h-4 w-4" />
                                <span className="hidden sm:inline">Delete</span>
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
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <p className="text-sm font-medium text-muted-foreground">Employee</p>
                                <p className="text-base">{leaveRequest.user.name}</p>
                            </div>
                            <div>
                                <p className="text-sm font-medium text-muted-foreground">Email</p>
                                <p className="text-base break-all">{leaveRequest.user.email}</p>
                            </div>
                        </div>

                        {/* Leave Details */}
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
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

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
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
                        <div>
                            <p className="text-sm font-medium text-muted-foreground">Campaign/Department</p>
                            <p className="text-base">{leaveRequest.campaign_department}</p>
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
                            <p className="text-base">
                                {leaveRequest.attendance_points_at_request
                                    ? Number(leaveRequest.attendance_points_at_request).toFixed(1)
                                    : '0'}
                            </p>
                        </div>

                        {/* Reason */}
                        <div>
                            <p className="text-sm font-medium text-muted-foreground mb-2">Reason</p>
                            <Alert className="overflow-hidden">
                                <Info className="h-4 w-4 flex-shrink-0" />
                                <AlertDescription className="break-words whitespace-pre-wrap">{leaveRequest.reason}</AlertDescription>
                            </Alert>
                        </div>

                        {/* TL Approval Status (for requests requiring TL approval) */}
                        {leaveRequest.requires_tl_approval && leaveRequest.status === 'pending' && !leaveRequest.tl_rejected && (
                            <div className="border-t pt-4">
                                <h3 className="font-semibold mb-4">Team Lead Approval</h3>
                                <div className={`p-4 rounded-lg border ${isTlApproved ? 'bg-green-500/10 border-green-500/30 dark:bg-green-500/20 dark:border-green-500/40' : 'bg-yellow-500/10 border-yellow-500/30 dark:bg-yellow-500/20 dark:border-yellow-500/40'}`}>
                                    <div className="flex items-center gap-2 mb-2">
                                        {isTlApproved ? (
                                            <CheckCircle className="h-5 w-5 text-green-600 dark:text-green-400" />
                                        ) : (
                                            <Clock className="h-5 w-5 text-yellow-600 dark:text-yellow-400" />
                                        )}
                                        <span className="font-medium">Team Lead Approval</span>
                                    </div>
                                    {isTlApproved ? (
                                        <>
                                            <p className="text-sm text-muted-foreground">
                                                Approved by {leaveRequest.tl_approver?.name || 'Team Lead'}
                                            </p>
                                            <p className="text-xs text-muted-foreground mt-1">
                                                {leaveRequest.tl_approved_at && format(parseISO(leaveRequest.tl_approved_at), 'MMM d, yyyy h:mm a')}
                                            </p>
                                            {leaveRequest.tl_review_notes && (
                                                <p className="text-sm mt-2 italic text-muted-foreground">"{leaveRequest.tl_review_notes}"</p>
                                            )}
                                        </>
                                    ) : (
                                        <p className="text-sm text-yellow-700 dark:text-yellow-400">Awaiting Team Lead approval</p>
                                    )}
                                </div>
                            </div>
                        )}

                        {/* Dual Approval Status */}
                        {leaveRequest.status === 'pending' && (isAdminApproved || isHrApproved || (leaveRequest.requires_tl_approval && isTlApproved)) && (
                            <div className="border-t pt-4">
                                <h3 className="font-semibold mb-4">Approval Progress</h3>
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    {/* Admin Approval Status */}
                                    <div className={`p-4 rounded-lg border ${isAdminApproved ? 'bg-green-500/10 border-green-500/30 dark:bg-green-500/20 dark:border-green-500/40' : 'bg-yellow-500/10 border-yellow-500/30 dark:bg-yellow-500/20 dark:border-yellow-500/40'}`}>
                                        <div className="flex items-center gap-2 mb-2">
                                            {isAdminApproved ? (
                                                <CheckCircle className="h-5 w-5 text-green-600 dark:text-green-400" />
                                            ) : (
                                                <Clock className="h-5 w-5 text-yellow-600 dark:text-yellow-400" />
                                            )}
                                            <span className="font-medium">Admin Approval</span>
                                        </div>
                                        {isAdminApproved ? (
                                            <>
                                                <p className="text-sm text-muted-foreground">
                                                    Approved by {leaveRequest.admin_approver?.name || 'Admin'}
                                                </p>
                                                <p className="text-xs text-muted-foreground mt-1">
                                                    {leaveRequest.admin_approved_at && format(parseISO(leaveRequest.admin_approved_at), 'MMM d, yyyy h:mm a')}
                                                </p>
                                                {leaveRequest.admin_review_notes && (
                                                    <p className="text-sm mt-2 italic text-muted-foreground">"{leaveRequest.admin_review_notes}"</p>
                                                )}
                                            </>
                                        ) : (
                                            <p className="text-sm text-yellow-700 dark:text-yellow-400">Awaiting Admin approval</p>
                                        )}
                                    </div>

                                    {/* HR Approval Status */}
                                    <div className={`p-4 rounded-lg border ${isHrApproved ? 'bg-green-500/10 border-green-500/30 dark:bg-green-500/20 dark:border-green-500/40' : 'bg-yellow-500/10 border-yellow-500/30 dark:bg-yellow-500/20 dark:border-yellow-500/40'}`}>
                                        <div className="flex items-center gap-2 mb-2">
                                            {isHrApproved ? (
                                                <CheckCircle className="h-5 w-5 text-green-600 dark:text-green-400" />
                                            ) : (
                                                <Clock className="h-5 w-5 text-yellow-600 dark:text-yellow-400" />
                                            )}
                                            <span className="font-medium">HR Approval</span>
                                        </div>
                                        {isHrApproved ? (
                                            <>
                                                <p className="text-sm text-muted-foreground">
                                                    Approved by {leaveRequest.hr_approver?.name || 'HR'}
                                                </p>
                                                <p className="text-xs text-muted-foreground mt-1">
                                                    {leaveRequest.hr_approved_at && format(parseISO(leaveRequest.hr_approved_at), 'MMM d, yyyy h:mm a')}
                                                </p>
                                                {leaveRequest.hr_review_notes && (
                                                    <p className="text-sm mt-2 italic text-muted-foreground">"{leaveRequest.hr_review_notes}"</p>
                                                )}
                                            </>
                                        ) : (
                                            <p className="text-sm text-yellow-700 dark:text-yellow-400">Awaiting HR approval</p>
                                        )}
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* Review Info (for final approved/denied status) */}
                        {leaveRequest.reviewed_at && (
                            <div className="border-t pt-4">
                                <h3 className="font-semibold mb-4">Final Review Information</h3>

                                {/* Show all approval info when fully approved */}
                                {leaveRequest.status === 'approved' && (
                                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-4">
                                        {/* TL Approval (if required) */}
                                        {leaveRequest.requires_tl_approval && isTlApproved && (
                                            <div className="p-3 rounded-lg bg-green-500/10 border border-green-500/30 dark:bg-green-500/20 dark:border-green-500/40">
                                                <div className="flex items-center gap-2 mb-1">
                                                    <CheckCircle className="h-4 w-4 text-green-600 dark:text-green-400" />
                                                    <span className="font-medium text-sm">Team Lead Approved</span>
                                                </div>
                                                <p className="text-sm text-muted-foreground">
                                                    {leaveRequest.tl_approver?.name || 'Team Lead'}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {leaveRequest.tl_approved_at && format(parseISO(leaveRequest.tl_approved_at), 'MMM d, yyyy h:mm a')}
                                                </p>
                                                {leaveRequest.tl_review_notes && (
                                                    <p className="text-xs mt-1 italic text-muted-foreground">"{leaveRequest.tl_review_notes}"</p>
                                                )}
                                            </div>
                                        )}

                                        {/* Admin Approval */}
                                        {isAdminApproved && (
                                            <div className="p-3 rounded-lg bg-green-500/10 border border-green-500/30 dark:bg-green-500/20 dark:border-green-500/40">
                                                <div className="flex items-center gap-2 mb-1">
                                                    <CheckCircle className="h-4 w-4 text-green-600 dark:text-green-400" />
                                                    <span className="font-medium text-sm">Admin Approved</span>
                                                </div>
                                                <p className="text-sm text-muted-foreground">
                                                    {leaveRequest.admin_approver?.name || 'Admin'}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {leaveRequest.admin_approved_at && format(parseISO(leaveRequest.admin_approved_at), 'MMM d, yyyy h:mm a')}
                                                </p>
                                                {leaveRequest.admin_review_notes && (
                                                    <p className="text-xs mt-1 italic text-muted-foreground">"{leaveRequest.admin_review_notes}"</p>
                                                )}
                                            </div>
                                        )}

                                        {/* HR Approval */}
                                        {isHrApproved && (
                                            <div className="p-3 rounded-lg bg-green-500/10 border border-green-500/30 dark:bg-green-500/20 dark:border-green-500/40">
                                                <div className="flex items-center gap-2 mb-1">
                                                    <CheckCircle className="h-4 w-4 text-green-600 dark:text-green-400" />
                                                    <span className="font-medium text-sm">HR Approved</span>
                                                </div>
                                                <p className="text-sm text-muted-foreground">
                                                    {leaveRequest.hr_approver?.name || 'HR'}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {leaveRequest.hr_approved_at && format(parseISO(leaveRequest.hr_approved_at), 'MMM d, yyyy h:mm a')}
                                                </p>
                                                {leaveRequest.hr_review_notes && (
                                                    <p className="text-xs mt-1 italic text-muted-foreground">"{leaveRequest.hr_review_notes}"</p>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}

                                {/* TL Rejection Info */}
                                {leaveRequest.status === 'denied' && leaveRequest.tl_rejected && (
                                    <div className="mb-4">
                                        <div className="p-3 rounded-lg bg-red-500/10 border border-red-500/30 dark:bg-red-500/20 dark:border-red-500/40">
                                            <div className="flex items-center gap-2 mb-1">
                                                <XCircle className="h-4 w-4 text-red-600 dark:text-red-400" />
                                                <span className="font-medium text-sm">Rejected by Team Lead</span>
                                            </div>
                                            <p className="text-sm text-muted-foreground">
                                                {leaveRequest.tl_approver?.name || 'Team Lead'}
                                            </p>
                                            {leaveRequest.tl_review_notes && (
                                                <p className="text-xs mt-1 italic text-muted-foreground">"{leaveRequest.tl_review_notes}"</p>
                                            )}
                                        </div>
                                    </div>
                                )}

                                {/* Denied or old review info */}
                                {(leaveRequest.status === 'denied' || (!isAdminApproved && !isHrApproved)) && (
                                    <>
                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
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
                                                <Alert className="overflow-hidden">
                                                    <AlertDescription className="break-words whitespace-pre-wrap">{leaveRequest.review_notes}</AlertDescription>
                                                </Alert>
                                            </div>
                                        )}
                                    </>
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
                            {!isFullyApproved && (
                                <span className="block mt-2 text-yellow-600">
                                    Note: Leave requests require approval from both Admin and HR.
                                    {isAdminApproved && ' Admin has already approved. Your HR approval will finalize this request.'}
                                    {isHrApproved && ' HR has already approved. Your Admin approval will finalize this request.'}
                                    {!isAdminApproved && !isHrApproved && ' The other role will be notified to review.'}
                                </span>
                            )}
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

            {/* TL Approve Dialog */}
            <Dialog open={showTLApproveDialog} onOpenChange={setShowTLApproveDialog}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Team Lead Approval</DialogTitle>
                        <DialogDescription>
                            Approve this leave request as Team Lead. It will then proceed to Admin and HR for final approval.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div>
                            <label className="text-sm font-medium">Review Notes (Optional)</label>
                            <Textarea
                                value={tlApproveForm.data.tl_review_notes}
                                onChange={(e) => tlApproveForm.setData('tl_review_notes', e.target.value)}
                                placeholder="Add any comments..."
                                rows={3}
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setShowTLApproveDialog(false)}>
                            Cancel
                        </Button>
                        <Button onClick={handleTLApprove} disabled={tlApproveForm.processing}>
                            Approve as Team Lead
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* TL Deny Dialog */}
            <Dialog open={showTLDenyDialog} onOpenChange={setShowTLDenyDialog}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Reject Leave Request</DialogTitle>
                        <DialogDescription>
                            Rejecting this request as Team Lead will deny the leave request. Provide a reason for the rejection.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div>
                            <label className="text-sm font-medium">
                                Review Notes <span className="text-red-500">*</span>
                            </label>
                            <Textarea
                                value={tlDenyForm.data.tl_review_notes}
                                onChange={(e) => tlDenyForm.setData('tl_review_notes', e.target.value)}
                                placeholder="Reason for rejection (required, minimum 10 characters)..."
                                rows={3}
                            />
                            {tlDenyForm.errors.tl_review_notes && (
                                <p className="text-sm text-red-500 mt-1">{tlDenyForm.errors.tl_review_notes}</p>
                            )}
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setShowTLDenyDialog(false)}>
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={handleTLDeny}
                            disabled={tlDenyForm.processing}
                        >
                            Reject Request
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

            {/* Delete Dialog */}
            <Dialog open={showDeleteDialog} onOpenChange={setShowDeleteDialog}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete Leave Request</DialogTitle>
                        <DialogDescription>
                            Are you sure you want to permanently delete this leave request? This action cannot be undone and will remove all associated data.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setShowDeleteDialog(false)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={handleDelete}>
                            Delete
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
