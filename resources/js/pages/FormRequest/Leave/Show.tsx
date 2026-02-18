import React, { useState, useMemo } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { format, parseISO, eachDayOfInterval, isWeekend } from 'date-fns';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Can } from '@/components/authorization';
import { Checkbox } from '@/components/ui/checkbox';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { ArrowLeft, Check, X, Ban, Info, Trash2, CheckCircle, Clock, UserCheck, XCircle, Shield, Edit, AlertTriangle, Calendar, FileImage, ExternalLink, ZoomIn, ZoomOut, RotateCcw } from 'lucide-react';
import { toast } from 'sonner';
import { usePermission } from '@/hooks/use-permission';
import { index as leaveIndexRoute, approve as leaveApproveRoute, deny as leaveDenyRoute, partialDeny as leavePartialDenyRoute, cancel as leaveCancelRoute, destroy as leaveDestroyRoute, edit as leaveEditRoute, medicalCert as leaveMedicalCertRoute, show as leaveShowRoute } from '@/routes/leave-requests';

interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    avatar_url?: string;
}

interface DeniedDate {
    id: number;
    denied_date: string;
    denial_reason: string | null;
    denied_by: number;
    denier?: User;
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
    campaign_id?: number;
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
    original_start_date?: string;
    original_end_date?: string;
    date_modified_by?: number;
    date_modification_reason?: string;
    date_modifier?: User;
    cancelled_by?: number;
    cancellation_reason?: string;
    canceller?: User;
    auto_cancelled?: boolean;
    auto_cancelled_reason?: string;
    short_notice_override?: boolean;
    short_notice_override_by?: number;
    short_notice_overrider?: User;
    medical_cert_path?: string;
    // Partial denial fields
    has_partial_denial?: boolean;
    approved_days?: number;
    sl_credits_applied?: boolean;
    sl_no_credit_reason?: string;
    vl_credits_applied?: boolean;
    vl_no_credit_reason?: string;
    linked_request_id?: number;
    denied_dates?: DeniedDate[];
}

interface AbsenceWindowInfo {
    within_window: boolean;
    last_absence_date: string | null;
    window_end_date: string | null;
}

interface ActiveAttendancePoint {
    id: number;
    shift_date: string;
    point_type: string;
    points: number;
    violation_details: string | null;
    expires_at: string | null;
    gbro_expires_at: string | null;
    eligible_for_gbro: boolean;
    current_status: 'active' | 'excused' | 'expired';
    excused_at: string | null;
    expired_at: string | null;
}

interface CreditPreview {
    should_deduct: boolean;
    reason: string | null;
    convert_to_upto: boolean;
    partial_credit: boolean;
    credits_to_deduct?: number;
    upto_days?: number;
}

interface LinkedRequestSummary {
    id: number;
    leave_type: string;
    start_date: string;
    end_date: string;
    days_requested: number;
    status: string;
}

interface Props {
    leaveRequest: LeaveRequest;
    isAdmin: boolean;
    isTeamLead?: boolean;
    isSuperAdmin?: boolean;
    canCancel: boolean;
    hasUserApproved: boolean;
    canTlApprove?: boolean;
    userRole: string;
    canAdminCancel?: boolean;
    canEditApproved?: boolean;
    canViewMedicalCert?: boolean;
    earlierConflicts?: EarlierConflict[];
    absenceWindowInfo?: AbsenceWindowInfo | null;
    activeAttendancePoints?: ActiveAttendancePoint[];
    creditPreview?: CreditPreview | null;
    linkedRequest?: LinkedRequestSummary | null;
    companionRequests?: LinkedRequestSummary[];
}

interface EarlierConflict {
    id: number;
    user_name: string;
    leave_type: string;
    start_date: string;
    end_date: string;
    status: string;
    created_at: string;
    overlapping_dates: string[];
}

export default function Show({
    leaveRequest,
    isAdmin,
    canCancel,
    hasUserApproved,
    canTlApprove = false,
    isSuperAdmin = false,
    canAdminCancel = false,
    canEditApproved = false,
    canViewMedicalCert = false,
    earlierConflicts = [],
    absenceWindowInfo = null,
    activeAttendancePoints = [],
    creditPreview = null,
    linkedRequest = null,
    companionRequests = [],
}: Props) {
    const [showApproveDialog, setShowApproveDialog] = useState(false);
    const [showDenyDialog, setShowDenyDialog] = useState(false);
    const [showPartialDenyDialog, setShowPartialDenyDialog] = useState(false);
    const [showMedicalCertDialog, setShowMedicalCertDialog] = useState(false);
    const [medicalCertZoom, setMedicalCertZoom] = useState(100);

    // Zoom controls
    const handleZoomIn = () => setMedicalCertZoom(prev => Math.min(prev + 25, 300));
    const handleZoomOut = () => setMedicalCertZoom(prev => Math.max(prev - 25, 50));
    const handleZoomReset = () => setMedicalCertZoom(100);

    // Reset zoom when dialog opens
    const handleOpenMedicalCert = () => {
        setMedicalCertZoom(100);
        setShowMedicalCertDialog(true);
    };
    const [showCancelDialog, setShowCancelDialog] = useState(false);
    const [showDeleteDialog, setShowDeleteDialog] = useState(false);
    const [showTLApproveDialog, setShowTLApproveDialog] = useState(false);
    const [showForceApproveDialog, setShowForceApproveDialog] = useState(false);
    const [showTLDenyDialog, setShowTLDenyDialog] = useState(false);
    const [showAdminCancelDialog, setShowAdminCancelDialog] = useState(false);
    const [showAdjustForWorkDialog, setShowAdjustForWorkDialog] = useState(false);
    const [selectedApprovedDates, setSelectedApprovedDates] = useState<string[]>([]);
    const [showAttendancePointsDialog, setShowAttendancePointsDialog] = useState(false);
    const { can } = usePermission();
    const getInitials = useInitials();

    const approveForm = useForm({ review_notes: '' });
    const denyForm = useForm({ review_notes: '' });
    const partialDenyForm = useForm({ denied_dates: [] as string[], denial_reason: '', review_notes: '' });
    const tlApproveForm = useForm({ review_notes: '' });
    const tlDenyForm = useForm({ review_notes: '' });
    const forceApproveForm = useForm({
        review_notes: '',
        denied_dates: [] as string[],
        denial_reason: ''
    });
    const [forceApprovePartialMode, setForceApprovePartialMode] = useState(false);
    const [forceApproveSelectedDates, setForceApproveSelectedDates] = useState<string[]>([]);
    const cancelForm = useForm({ cancellation_reason: '' });
    const adminCancelForm = useForm({ cancellation_reason: '' });
    const adjustForWorkForm = useForm({
        work_date: '',
        adjustment_type: 'end_early' as 'end_early' | 'start_late' | 'remove_day',
        notes: ''
    });

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

    // Handle force approve date selection toggle
    const toggleForceApproveDate = (dateStr: string) => {
        setForceApproveSelectedDates(prev => {
            if (prev.includes(dateStr)) {
                return prev.filter(d => d !== dateStr);
            } else {
                return [...prev, dateStr];
            }
        });
    };

    const handleForceApprove = () => {
        // Build the data to submit
        const submitData: {
            review_notes: string;
            denied_dates: string[];
            denial_reason: string;
        } = {
            review_notes: forceApproveForm.data.review_notes,
            denied_dates: [],
            denial_reason: forceApproveForm.data.denial_reason
        };

        // If partial mode is enabled and dates are selected, calculate denied dates
        if (forceApprovePartialMode && forceApproveSelectedDates.length > 0 && forceApproveSelectedDates.length < workDays.length) {
            submitData.denied_dates = workDays
                .map(date => format(date, 'yyyy-MM-dd'))
                .filter(dateStr => !forceApproveSelectedDates.includes(dateStr));
        }

        router.post(`/form-requests/leave-requests/${leaveRequest.id}/force-approve`, submitData, {
            onSuccess: () => {
                setShowForceApproveDialog(false);
                setForceApprovePartialMode(false);
                setForceApproveSelectedDates([]);
                forceApproveForm.reset();
                toast.success(forceApprovePartialMode && forceApproveSelectedDates.length > 0
                    ? 'Leave request force approved with partial denial'
                    : 'Leave request force approved by Super Admin');
            },
            onError: (errors) => {
                toast.error(errors.error || 'Failed to force approve');
            },
        });
    };

    const handleCancel = () => {
        cancelForm.post(leaveCancelRoute(leaveRequest.id).url, {
            onSuccess: () => {
                setShowCancelDialog(false);
                cancelForm.reset();
                toast.success('Leave request cancelled');
            },
        });
    };

    const handleAdminCancel = () => {
        adminCancelForm.post(leaveCancelRoute(leaveRequest.id).url, {
            onSuccess: () => {
                setShowAdminCancelDialog(false);
                toast.success('Approved leave request cancelled. Credits have been restored.');
            },
            onError: () => {
                toast.error('Failed to cancel leave request');
            },
        });
    };

    const handleAdjustForWork = () => {
        adjustForWorkForm.post(`/form-requests/leave-requests/${leaveRequest.id}/adjust-for-work`, {
            onSuccess: () => {
                setShowAdjustForWorkDialog(false);
                adjustForWorkForm.reset();
                toast.success('Leave adjusted successfully. Credits have been restored for the work day.');
            },
            onError: (errors) => {
                toast.error(errors.error || 'Failed to adjust leave');
            },
        });
    };

    // Helper function to format attendance point type
    const formatPointType = (type: string) => {
        const typeMap: Record<string, string> = {
            'whole_day_absence': 'Whole Day Absence',
            'half_day_absence': 'Half Day Absence',
            'undertime': 'Undertime',
            'undertime_more_than_hour': 'Undertime (>1 Hour)',
            'tardy': 'Tardy',
        };
        return typeMap[type] || type;
    };

    // Get list of working days (Mon-Fri) in leave period
    const workDays = useMemo(() => {
        try {
            const start = parseISO(leaveRequest.start_date);
            const end = parseISO(leaveRequest.end_date);
            return eachDayOfInterval({ start, end }).filter(date => !isWeekend(date));
        } catch {
            return [];
        }
    }, [leaveRequest.start_date, leaveRequest.end_date]);

    // Get list of dates in leave period for the adjust dialog
    const getLeaveDates = () => {
        try {
            const start = parseISO(leaveRequest.start_date);
            const end = parseISO(leaveRequest.end_date);
            return eachDayOfInterval({ start, end }).filter(date => !isWeekend(date));
        } catch {
            return [];
        }
    };

    // Handle partial approval - toggle date selection
    const toggleApprovedDate = (dateStr: string) => {
        setSelectedApprovedDates(prev => {
            if (prev.includes(dateStr)) {
                return prev.filter(d => d !== dateStr);
            } else {
                return [...prev, dateStr];
            }
        });
    };

    // Handle partial approval submission
    const handlePartialDeny = () => {
        if (selectedApprovedDates.length === 0) {
            toast.error('Please select at least one date to approve');
            return;
        }

        if (selectedApprovedDates.length === workDays.length) {
            toast.error('You cannot approve all dates. Use full approve instead.');
            return;
        }

        // Calculate denied dates (dates NOT selected for approval)
        const deniedDates = workDays
            .map(date => format(date, 'yyyy-MM-dd'))
            .filter(dateStr => !selectedApprovedDates.includes(dateStr));

        partialDenyForm.setData('denied_dates', deniedDates);
        partialDenyForm.post(leavePartialDenyRoute(leaveRequest.id).url, {
            onSuccess: () => {
                setShowPartialDenyDialog(false);
                setSelectedApprovedDates([]);
                partialDenyForm.reset();
                toast.success('Leave request partially approved');
            },
            onError: (errors) => {
                toast.error(errors.error || 'Failed to process partial approval');
            },
        });
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
                                {/* Partial Deny for Team Lead - Only show for multi-day requests */}
                                {workDays.length > 1 && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="border-green-300 text-green-700 hover:bg-green-50"
                                        onClick={() => setShowPartialDenyDialog(true)}
                                    >
                                        <Calendar className="mr-1 h-4 w-4" />
                                        <span className="hidden sm:inline">Partial Approved (TL)</span>
                                    </Button>
                                )}
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
                                {/* Partial Deny - Only show for multi-day requests */}
                                {workDays.length > 1 && (
                                    <Can permission="leave.approve">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="border-green-300 text-green-700 hover:bg-green-50"
                                            onClick={() => setShowPartialDenyDialog(true)}
                                        >
                                            <Calendar className="mr-1 h-4 w-4" />
                                            <span className="hidden sm:inline">Partial Approved</span>
                                        </Button>
                                    </Can>
                                )}
                            </>
                        )}
                        {/* Super Admin Force Approve Button - Shows when pending and not fully approved */}
                        {isSuperAdmin && leaveRequest.status === 'pending' && !isFullyApproved && (
                            <Button
                                variant="default"
                                size="sm"
                                className="bg-purple-600 hover:bg-purple-700"
                                onClick={() => setShowForceApproveDialog(true)}
                            >
                                <Shield className="mr-1 h-4 w-4" />
                                <span className="hidden sm:inline">Force Approve</span>
                            </Button>
                        )}
                        {hasUserApproved && leaveRequest.status === 'pending' && (
                            <Badge variant="outline" className="bg-green-50 text-green-700 border-green-300">
                                <CheckCircle className="mr-1 h-3 w-3" />
                                You've Approved
                            </Badge>
                        )}
                        {/* Admin Actions for Approved Leaves */}
                        {canEditApproved && leaveRequest.status === 'approved' && (
                            <>
                                <Link href={leaveEditRoute({ leaveRequest: leaveRequest.id }).url}>
                                    <Button variant="outline" size="sm">
                                        <Edit className="mr-1 h-4 w-4" />
                                        <span className="hidden sm:inline">Edit Dates</span>
                                    </Button>
                                </Link>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="border-blue-300 text-blue-700 hover:bg-blue-50"
                                    onClick={() => setShowAdjustForWorkDialog(true)}
                                >
                                    <Calendar className="mr-1 h-4 w-4" />
                                    <span className="hidden sm:inline">Adjust for Work</span>
                                </Button>
                            </>
                        )}
                        {canAdminCancel && (
                            <Button
                                variant="outline"
                                size="sm"
                                className="border-red-300 text-red-700 hover:bg-red-50"
                                onClick={() => setShowAdminCancelDialog(true)}
                            >
                                <Ban className="mr-1 h-4 w-4" />
                                <span className="hidden sm:inline">{leaveRequest.status === 'approved' ? 'Cancel Approved' : 'Cancel'}</span>
                            </Button>
                        )}
                        {canCancel && !canAdminCancel && (leaveRequest.status === 'pending' || (leaveRequest.status === 'approved' && leaveRequest.has_partial_denial)) && (
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
                        <div className="flex items-start gap-4 pb-4 border-b">
                            <Avatar className="h-16 w-16 overflow-hidden rounded-full">
                                <AvatarImage src={leaveRequest.user.avatar_url} alt={leaveRequest.user.name} />
                                <AvatarFallback className="rounded-full bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white text-lg">
                                    {getInitials(leaveRequest.user.name)}
                                </AvatarFallback>
                            </Avatar>
                            <div className="flex-1 grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <p className="text-sm font-medium text-muted-foreground">Employee</p>
                                    <p className="text-base font-semibold">{leaveRequest.user.name}</p>
                                </div>
                                <div>
                                    <p className="text-sm font-medium text-muted-foreground">Email</p>
                                    <p className="text-base break-all">{leaveRequest.user.email}</p>
                                </div>
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
                                <p className="text-base">
                                    {leaveRequest.has_partial_denial ? (
                                        <>
                                            <span className="text-green-600 font-medium">{leaveRequest.approved_days} days approved</span>
                                            <span className="text-muted-foreground"> / {leaveRequest.days_requested} days requested</span>
                                        </>
                                    ) : (
                                        <>{leaveRequest.days_requested} days</>
                                    )}
                                </p>
                            </div>
                        </div>

                        {/* Partial Denial Info */}
                        {Boolean(leaveRequest.has_partial_denial && leaveRequest.denied_dates && leaveRequest.denied_dates.length > 0) && (
                            <Alert className="border-orange-200 bg-orange-50 dark:border-orange-800 dark:bg-orange-950">
                                <Calendar className="h-4 w-4 text-orange-600" />
                                <AlertDescription className="text-orange-800 dark:text-orange-200">
                                    <p className="font-medium mb-2">Partial Approval - Some dates were denied:</p>
                                    <div className="space-y-1">
                                        {leaveRequest.denied_dates?.map((dd) => (
                                            <div key={dd.id} className="flex items-center gap-2 text-sm">
                                                <Badge variant="destructive" className="text-xs">Denied</Badge>
                                                <span>{format(parseISO(dd.denied_date), 'EEEE, MMM d, yyyy')}</span>
                                                {dd.denial_reason && <span className="text-muted-foreground">- {dd.denial_reason}</span>}
                                            </div>
                                        ))}
                                    </div>
                                    {leaveRequest.denied_dates?.[0]?.denier && (
                                        <p className="mt-2 text-xs text-muted-foreground">
                                            Denied by: {leaveRequest.denied_dates[0].denier.name}
                                        </p>
                                    )}
                                </AlertDescription>
                            </Alert>
                        )}

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

                        {/* Earlier Conflicts Warning (First-Come-First-Serve for VL/UPTO) - Hidden for cancelled leaves */}
                        {Boolean(earlierConflicts && earlierConflicts.length > 0 && ['VL', 'UPTO'].includes(leaveRequest.leave_type) && leaveRequest.status !== 'cancelled') && (
                            <Alert className="border-orange-200 bg-orange-50 dark:border-orange-800 dark:bg-orange-950">
                                <AlertTriangle className="h-4 w-4 text-orange-600" />
                                <AlertDescription className="text-orange-800 dark:text-orange-200">
                                    <div className="space-y-2">
                                        <div className="flex items-center justify-between gap-2">
                                            <p className="font-semibold">
                                                Date Conflict Detected (First-Come-First-Serve Policy)
                                            </p>
                                            <a
                                                href={`/form-requests/leave-requests/calendar?month=${leaveRequest.start_date.substring(0, 7)}${leaveRequest.campaign_id ? `&campaign_id=${leaveRequest.campaign_id}` : ''}&leave_type=${leaveRequest.leave_type}`}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                <Button variant="outline" size="sm" className="h-7 text-xs bg-white dark:bg-gray-800">
                                                    <Calendar className="mr-1 h-3 w-3" />
                                                    View Calendar
                                                    <ExternalLink className="ml-1 h-3 w-3" />
                                                </Button>
                                            </a>
                                        </div>
                                        <p className="text-sm">
                                            The following leave requests from the same campaign were submitted earlier and have overlapping dates:
                                        </p>
                                        <div className="mt-2 max-h-60 overflow-y-auto space-y-2 pr-1 custom-scrollbar">
                                            {earlierConflicts.map((conflict) => (
                                                <div
                                                    key={conflict.id}
                                                    className="bg-white/50 dark:bg-black/20 rounded-md p-2 border border-orange-200 dark:border-orange-700 text-sm"
                                                >
                                                    <div className="flex flex-wrap items-center justify-between gap-1">
                                                        <div className="flex items-center gap-2">
                                                            <span className="font-medium">{conflict.user_name}</span>
                                                            <div className="flex gap-1">
                                                                <Badge variant="outline" className="h-5 px-1.5 text-[10px]">
                                                                    {conflict.leave_type}
                                                                </Badge>
                                                                <Badge
                                                                    variant={conflict.status === 'approved' ? 'default' : 'secondary'}
                                                                    className="h-5 px-1.5 text-[10px]"
                                                                >
                                                                    {conflict.status}
                                                                </Badge>
                                                            </div>
                                                        </div>
                                                        <span className="text-xs text-muted-foreground whitespace-nowrap">
                                                            {format(parseISO(conflict.start_date), 'MMM d')} - {format(parseISO(conflict.end_date), 'MMM d')}
                                                        </span>
                                                    </div>

                                                    <div className="flex flex-wrap justify-between items-end gap-2 mt-1">
                                                        <span className="text-xs text-muted-foreground">
                                                            Submitted: {format(parseISO(conflict.created_at), 'MM/dd h:mm a')}
                                                        </span>

                                                        {Boolean(conflict.overlapping_dates && conflict.overlapping_dates.length > 0) && (
                                                            <span className="text-xs font-medium text-orange-600 dark:text-orange-400">
                                                                Overlaps: {conflict.overlapping_dates.map(d => format(parseISO(d), 'MMM d')).join(', ')}
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                        <p className="text-xs italic mt-1 opacity-80">
                                            Note: Earlier submitted requests may take priority.
                                        </p>
                                    </div>
                                </AlertDescription>
                            </Alert>
                        )}

                        {/* Medical Cert (if SL) */}
                        {leaveRequest.leave_type === 'SL' && (
                            <div className="space-y-2">
                                <p className="text-sm font-medium text-muted-foreground">
                                    Medical Certificate
                                </p>
                                {leaveRequest.medical_cert_path && canViewMedicalCert ? (
                                    <div className="flex items-center gap-2">
                                        <Badge variant="outline" className="bg-green-50 text-green-700 border-green-300">
                                            <FileImage className="h-3 w-3 mr-1" />
                                            Uploaded
                                        </Badge>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={handleOpenMedicalCert}
                                        >
                                            <FileImage className="h-4 w-4 mr-1" />
                                            View Certificate
                                        </Button>
                                        <a
                                            href={leaveMedicalCertRoute(leaveRequest.id).url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            title="Open medical certificate in new tab"
                                        >
                                            <Button variant="ghost" size="sm">
                                                <ExternalLink className="h-4 w-4" />
                                            </Button>
                                        </a>
                                    </div>
                                ) : leaveRequest.medical_cert_submitted ? (
                                    <Badge variant="outline" className="bg-yellow-50 text-yellow-700 border-yellow-300">
                                        Pending submission
                                    </Badge>
                                ) : (
                                    <Badge variant="outline" className="bg-gray-50 text-gray-600 border-gray-300">
                                        Not provided
                                    </Badge>
                                )}
                            </div>
                        )}

                        {/* Credits Info */}
                        {Boolean(leaveRequest.credits_deducted !== null && leaveRequest.credits_deducted !== undefined && Number(leaveRequest.credits_deducted) > 0) && (
                            <div>
                                <p className="text-sm font-medium text-muted-foreground">Leave Credits Deducted</p>
                                <p className="text-base">{leaveRequest.credits_deducted} days</p>
                            </div>
                        )}

                        {/* SL No Credit Reason - Show when SL has no credits applied */}
                        {leaveRequest.leave_type === 'SL' && leaveRequest.sl_credits_applied === false && leaveRequest.sl_no_credit_reason && (
                            <Alert className="border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-950">
                                <Info className="h-4 w-4 text-blue-600" />
                                <AlertDescription className="text-blue-800 dark:text-blue-200">
                                    <strong>SL Credits Not Deducted:</strong> {leaveRequest.sl_no_credit_reason}
                                </AlertDescription>
                            </Alert>
                        )}

                        {/* SL Converted to UPTO - Show when UPTO was converted from SL due to insufficient credits */}
                        {leaveRequest.leave_type === 'UPTO' && leaveRequest.sl_no_credit_reason && leaveRequest.sl_no_credit_reason.includes('Converted to UPTO') && (
                            <Alert className="border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950">
                                <AlertTriangle className="h-4 w-4 text-amber-600" />
                                <AlertDescription className="text-amber-800 dark:text-amber-200">
                                    <strong>Originally filed as Sick Leave (SL):</strong> {leaveRequest.sl_no_credit_reason}
                                </AlertDescription>
                            </Alert>
                        )}

                        {/* VL No Credit Reason - Show when VL has partial or no credits applied */}
                        {leaveRequest.leave_type === 'VL' && leaveRequest.vl_credits_applied === false && leaveRequest.vl_no_credit_reason && (
                            <Alert className="border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-950">
                                <Info className="h-4 w-4 text-blue-600" />
                                <AlertDescription className="text-blue-800 dark:text-blue-200">
                                    <strong>VL Credits Not Deducted:</strong> {leaveRequest.vl_no_credit_reason}
                                </AlertDescription>
                            </Alert>
                        )}

                        {/* VL with partial credits - reason displayed */}
                        {leaveRequest.leave_type === 'VL' && leaveRequest.vl_credits_applied === true && leaveRequest.vl_no_credit_reason && (
                            <Alert className="border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950">
                                <AlertTriangle className="h-4 w-4 text-amber-600" />
                                <AlertDescription className="text-amber-800 dark:text-amber-200">
                                    <strong>Partial VL Credits:</strong> {leaveRequest.vl_no_credit_reason}
                                </AlertDescription>
                            </Alert>
                        )}

                        {/* VL Converted to UPTO - Show when UPTO was converted from VL due to insufficient credits */}
                        {leaveRequest.leave_type === 'UPTO' && leaveRequest.vl_no_credit_reason && leaveRequest.vl_no_credit_reason.includes('Converted to UPTO') && (
                            <Alert className="border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950">
                                <AlertTriangle className="h-4 w-4 text-amber-600" />
                                <AlertDescription className="text-amber-800 dark:text-amber-200">
                                    <strong>Originally filed as Vacation Leave (VL):</strong> {leaveRequest.vl_no_credit_reason}
                                </AlertDescription>
                            </Alert>
                        )}

                        {/* Linked Parent Request - Show on UPTO companion requests */}
                        {linkedRequest && (
                            <Alert className="border-purple-200 bg-purple-50 dark:border-purple-800 dark:bg-purple-950">
                                <Info className="h-4 w-4 text-purple-600" />
                                <AlertDescription className="text-purple-800 dark:text-purple-200">
                                    <strong>Linked Request:</strong> This UPTO was auto-created from{' '}
                                    <Link
                                        href={leaveShowRoute(linkedRequest.id).url}
                                        className="underline font-semibold hover:text-purple-600"
                                    >
                                        {linkedRequest.leave_type} Request #{linkedRequest.id}
                                    </Link>
                                    {' '}({linkedRequest.days_requested} day(s), {format(parseISO(linkedRequest.start_date), 'MMM d, yyyy')} – {format(parseISO(linkedRequest.end_date), 'MMM d, yyyy')})
                                </AlertDescription>
                            </Alert>
                        )}

                        {/* Companion UPTO Requests - Show on parent VL/SL requests */}
                        {companionRequests && companionRequests.length > 0 && (
                            <Alert className="border-purple-200 bg-purple-50 dark:border-purple-800 dark:bg-purple-950">
                                <Info className="h-4 w-4 text-purple-600" />
                                <AlertDescription className="text-purple-800 dark:text-purple-200">
                                    <strong>Linked UPTO Request{companionRequests.length > 1 ? 's' : ''}:</strong>{' '}
                                    {companionRequests.map((companion, idx) => (
                                        <span key={companion.id}>
                                            {idx > 0 && ', '}
                                            <Link
                                                href={leaveShowRoute(companion.id).url}
                                                className="underline font-semibold hover:text-purple-600"
                                            >
                                                UPTO #{companion.id}
                                            </Link>
                                            {' '}({companion.days_requested} day(s))
                                        </span>
                                    ))}
                                    {' '}— auto-created for excess days with insufficient credits.
                                </AlertDescription>
                            </Alert>
                        )}



                        {/* Attendance Points at Request - Highlighted if >= 6, hidden for ML */}
                        {leaveRequest.leave_type !== 'ML' && (
                            <div>
                                <p className="text-sm font-medium text-muted-foreground">
                                    Attendance Points at Request
                                </p>
                                <div className="flex items-center gap-2 mt-1">
                                    {Number(leaveRequest.attendance_points_at_request || 0) >= 6 ? (
                                        <Badge variant="destructive" className="text-sm font-semibold px-3 py-1">
                                            <AlertTriangle className="h-3.5 w-3.5 mr-1.5" />
                                            {Number(leaveRequest.attendance_points_at_request || 0).toFixed(1)} Points (High)
                                        </Badge>
                                    ) : (
                                        <p className="text-base">
                                            {Number(leaveRequest.attendance_points_at_request || 0).toFixed(1)}
                                        </p>
                                    )}
                                    {activeAttendancePoints && activeAttendancePoints.length > 0 && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => setShowAttendancePointsDialog(true)}
                                            className="h-8"
                                        >
                                            <Info className="h-4 w-4 mr-1" />
                                            View Details
                                        </Button>
                                    )}
                                </div>
                            </div>
                        )}

                        {/* High Attendance Points Alert - Hidden for cancelled leaves and ML */}
                        {Number(leaveRequest.attendance_points_at_request || 0) >= 6 && leaveRequest.status !== 'cancelled' && leaveRequest.leave_type !== 'ML' && (
                            <Alert className="border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-950">
                                <AlertDescription className="text-red-800 dark:text-red-200">
                                    <strong>⚠️ High Attendance Points:</strong>  <p>This employee has{' '}
                                        <span className="font-bold">{Number(leaveRequest.attendance_points_at_request || 0).toFixed(1)} attendance points</span>{' '}
                                        at the time of this request. Please review attendance history before approving.</p>
                                </AlertDescription>
                            </Alert>
                        )}

                        {/* 30-Day Absence Window Warning for VL - Hidden for cancelled leaves */}
                        {Boolean(absenceWindowInfo?.within_window && leaveRequest.leave_type === 'VL' && leaveRequest.status !== 'cancelled') && (
                            <Alert className="border-amber-300 bg-amber-50 dark:border-amber-800 dark:bg-amber-950">
                                <AlertTriangle className="h-4 w-4 text-amber-600" />
                                <AlertDescription className="text-amber-800 dark:text-amber-200">
                                    <div className="space-y-1">
                                        <p className="font-semibold">⚠️ 30-Day Absence Window Violation</p>
                                        <p className="text-sm">
                                            This VL application falls within 30 days of the employee's last recorded absence.
                                        </p>
                                        <div className="text-sm mt-2">
                                            <p><strong>Last Absence Date:</strong> {absenceWindowInfo?.last_absence_date ? format(parseISO(absenceWindowInfo.last_absence_date), 'MMMM d, yyyy') : 'N/A'}</p>
                                            <p><strong>Window Ends:</strong> {absenceWindowInfo?.window_end_date ? format(parseISO(absenceWindowInfo.window_end_date), 'MMMM d, yyyy') : 'N/A'}</p>
                                        </div>
                                        <p className="text-xs italic mt-1 opacity-80">
                                            Note: VL applications within 30 days of last absence may require additional review.
                                        </p>
                                    </div>
                                </AlertDescription>
                            </Alert>
                        )}

                        {/* Reason */}
                        <div>
                            <p className="text-sm font-medium text-muted-foreground mb-2">Reason</p>
                            <Alert className="overflow-hidden">
                                <Info className="h-4 w-4 flex-shrink-0" />
                                <AlertDescription className="break-words whitespace-pre-wrap">{leaveRequest.reason}</AlertDescription>
                            </Alert>
                        </div>

                        {/* Short Notice Override Info */}
                        {leaveRequest.short_notice_override && (
                            <Alert className="border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-950">
                                <Shield className="h-4 w-4 text-blue-600" />
                                <AlertDescription className="text-blue-800 dark:text-blue-200">
                                    <strong>Short Notice Override:</strong> The 2-week advance notice requirement was overridden
                                    {leaveRequest.short_notice_overrider && (
                                        <span> by {leaveRequest.short_notice_overrider.name}</span>
                                    )}.
                                </AlertDescription>
                            </Alert>
                        )}

                        {/* Date Modification History */}
                        {(leaveRequest.original_start_date && leaveRequest.original_end_date) || leaveRequest.date_modification_reason ? (
                            <Alert className="border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950">
                                <AlertTriangle className="h-4 w-4 text-amber-600" />
                                <AlertDescription className="text-amber-800 dark:text-amber-200">
                                    {leaveRequest.original_start_date && leaveRequest.original_end_date && (
                                        <div className="mb-2">
                                            <strong>Dates Modified:</strong> Original dates were{' '}
                                            {format(parseISO(leaveRequest.original_start_date), 'MMM d, yyyy')} to{' '}
                                            {format(parseISO(leaveRequest.original_end_date), 'MMM d, yyyy')}
                                            {leaveRequest.date_modifier && (
                                                <span className="font-semibold">. Modified by {leaveRequest.date_modifier.name}</span>
                                            )}
                                        </div>
                                    )}
                                    {leaveRequest.date_modification_reason && (
                                        <div className={leaveRequest.original_start_date ? "mt-3 pt-3 border-t border-amber-300 dark:border-amber-700" : ""}>
                                            {!leaveRequest.original_start_date && <strong className="block mb-2">Adjustment Details:</strong>}
                                            <div className="space-y-1 text-sm whitespace-pre-line">
                                                {leaveRequest.date_modification_reason}
                                            </div>
                                        </div>
                                    )}
                                </AlertDescription>
                            </Alert>
                        ) : null}

                        {/* Cancellation Info */}
                        {leaveRequest.status === 'cancelled' && (
                            <Alert className="border-gray-300 bg-gray-50 dark:border-gray-700 dark:bg-gray-900">
                                <Ban className="h-4 w-4 text-gray-600" />
                                <AlertDescription className="text-gray-800 dark:text-gray-200">
                                    {leaveRequest.auto_cancelled ? (
                                        <>
                                            <strong>Auto-Cancelled:</strong> {leaveRequest.auto_cancelled_reason}
                                        </>
                                    ) : leaveRequest.canceller ? (
                                        <>
                                            <strong>Cancelled by {leaveRequest.canceller.name}:</strong>{' '}
                                            {leaveRequest.cancellation_reason || 'No reason provided'}
                                        </>
                                    ) : (
                                        <strong>Cancelled by user</strong>
                                    )}
                                </AlertDescription>
                            </Alert>
                        )}

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
                                            <p className="text-sm mt-2 italic text-muted-foreground">
                                                {leaveRequest.tl_review_notes ? `"${leaveRequest.tl_review_notes}"` : 'No notes provided'}
                                            </p>
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
                                                <p className="text-xs mt-1 italic text-muted-foreground">
                                                    {leaveRequest.tl_review_notes ? `"${leaveRequest.tl_review_notes}"` : 'No notes provided'}
                                                </p>
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
                                            <p className="text-xs mt-1 italic text-muted-foreground">
                                                {leaveRequest.tl_review_notes ? `"${leaveRequest.tl_review_notes}"` : 'No notes provided'}
                                            </p>
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
                        {/* Credit Split Preview for VL/SL */}
                        {creditPreview && (leaveRequest.leave_type === 'VL' || leaveRequest.leave_type === 'SL') && (
                            <>
                                {creditPreview.convert_to_upto && (
                                    <Alert className="border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-950">
                                        <AlertTriangle className="h-4 w-4 text-red-600" />
                                        <AlertDescription className="text-red-800 dark:text-red-200">
                                            <strong>No {leaveRequest.leave_type} credits available.</strong> This request will be converted to UPTO (Unpaid Time Off) upon approval.
                                            {creditPreview.reason && <span className="block mt-1 text-sm">{creditPreview.reason}</span>}
                                        </AlertDescription>
                                    </Alert>
                                )}
                                {creditPreview.partial_credit && (
                                    <Alert className="border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950">
                                        <AlertTriangle className="h-4 w-4 text-amber-600" />
                                        <AlertDescription className="text-amber-800 dark:text-amber-200">
                                            <strong>Partial {leaveRequest.leave_type} credits available.</strong>{' '}
                                            {creditPreview.credits_to_deduct} day(s) will use {leaveRequest.leave_type} credits, and{' '}
                                            {creditPreview.upto_days} day(s) will be filed as a linked UPTO request.
                                        </AlertDescription>
                                    </Alert>
                                )}
                                {!creditPreview.convert_to_upto && !creditPreview.partial_credit && creditPreview.should_deduct && (
                                    <Alert className="border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-950">
                                        <CheckCircle className="h-4 w-4 text-green-600" />
                                        <AlertDescription className="text-green-800 dark:text-green-200">
                                            Full {leaveRequest.leave_type} credits available. {creditPreview.credits_to_deduct} day(s) will be deducted.
                                        </AlertDescription>
                                    </Alert>
                                )}
                            </>
                        )}
                        <div>
                            <label className="text-sm font-medium">
                                Review Notes <span className="text-red-500">*</span>
                            </label>
                            <Textarea
                                value={approveForm.data.review_notes}
                                onChange={(e) => approveForm.setData('review_notes', e.target.value)}
                                placeholder="Add comments (required, minimum 10 characters)..."
                                rows={3}
                            />
                            {approveForm.errors.review_notes && (
                                <p className="text-sm text-red-500 mt-1">{approveForm.errors.review_notes}</p>
                            )}
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

            {/* Partial Deny Dialog */}
            <Dialog open={showPartialDenyDialog} onOpenChange={(open) => {
                setShowPartialDenyDialog(open);
                if (!open) {
                    setSelectedApprovedDates([]);
                    partialDenyForm.reset();
                }
            }}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Partial Approval - Select Dates to Approve</DialogTitle>
                        <DialogDescription>
                            Select specific dates to approve from this leave request. The remaining dates will be denied.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div>
                            <Label className="text-sm font-medium mb-2 block">
                                Leave Period: {format(parseISO(leaveRequest.start_date), 'MMM d, yyyy')} - {format(parseISO(leaveRequest.end_date), 'MMM d, yyyy')}
                            </Label>
                            <p className="text-sm text-muted-foreground mb-3">
                                Total working days: {workDays.length} | Selected to approve: {selectedApprovedDates.length}
                            </p>
                            <div className="border rounded-md p-3 max-h-64 overflow-y-auto space-y-2">
                                {workDays.map((date) => {
                                    const dateStr = format(date, 'yyyy-MM-dd');
                                    const isSelected = selectedApprovedDates.includes(dateStr);
                                    return (
                                        <div
                                            key={dateStr}
                                            className={`flex items-center space-x-3 p-2 rounded cursor-pointer transition-colors ${isSelected ? 'bg-green-50 border border-green-200 text-green-900' : 'hover:bg-muted'
                                                }`}
                                            onClick={() => toggleApprovedDate(dateStr)}
                                        >
                                            <Checkbox
                                                checked={isSelected}
                                                onCheckedChange={() => toggleApprovedDate(dateStr)}
                                            />
                                            <div className="flex-1">
                                                <span className="font-medium">{format(date, 'EEEE, MMM d, yyyy')}</span>
                                            </div>
                                            {isSelected && (
                                                <Badge className="text-xs bg-green-600 text-white">Will be approved</Badge>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                        <div>
                            <Label className="text-sm font-medium">
                                Reason for Partial Approval <span className="text-red-500">*</span>
                            </Label>
                            <Textarea
                                value={partialDenyForm.data.denial_reason}
                                onChange={(e) => partialDenyForm.setData('denial_reason', e.target.value)}
                                placeholder="Why are some dates being denied? (required, minimum 10 characters)..."
                                rows={2}
                            />
                            {partialDenyForm.errors.denial_reason && (
                                <p className="text-sm text-red-500 mt-1">{partialDenyForm.errors.denial_reason}</p>
                            )}
                        </div>
                        <div>
                            <Label className="text-sm font-medium">Additional Notes (Optional)</Label>
                            <Textarea
                                value={partialDenyForm.data.review_notes}
                                onChange={(e) => partialDenyForm.setData('review_notes', e.target.value)}
                                placeholder="Any additional notes..."
                                rows={2}
                            />
                        </div>
                        {selectedApprovedDates.length > 0 && selectedApprovedDates.length < workDays.length && (
                            <Alert className="bg-green-50 border-green-200">
                                <Check className="h-4 w-4 text-green-600" />
                                <AlertDescription className="text-green-800">
                                    <p>{selectedApprovedDates.length} day(s) will be <strong>approved</strong>, {workDays.length - selectedApprovedDates.length} day(s) will be <strong>denied</strong>.</p>
                                </AlertDescription>
                            </Alert>
                        )}
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => {
                            setShowPartialDenyDialog(false);
                            setSelectedApprovedDates([]);
                            partialDenyForm.reset();
                        }}>
                            Cancel
                        </Button>
                        <Button
                            variant="default"
                            className="bg-orange-600 hover:bg-orange-700"
                            onClick={handlePartialDeny}
                            disabled={partialDenyForm.processing || selectedApprovedDates.length === 0 || selectedApprovedDates.length === workDays.length}
                        >
                            Partial Approve ({selectedApprovedDates.length} days)
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
                            <label className="text-sm font-medium">
                                Review Notes <span className="text-red-500">*</span>
                            </label>
                            <Textarea
                                value={tlApproveForm.data.review_notes}
                                onChange={(e) => tlApproveForm.setData('review_notes', e.target.value)}
                                placeholder="Add comments (required, minimum 10 characters)..."
                                rows={3}
                            />
                            {tlApproveForm.errors.review_notes && (
                                <p className="text-sm text-red-500 mt-1">{tlApproveForm.errors.review_notes}</p>
                            )}
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
                                value={tlDenyForm.data.review_notes}
                                onChange={(e) => tlDenyForm.setData('review_notes', e.target.value)}
                                placeholder="Reason for rejection (required, minimum 10 characters)..."
                                rows={3}
                            />
                            {tlDenyForm.errors.review_notes && (
                                <p className="text-sm text-red-500 mt-1">{tlDenyForm.errors.review_notes}</p>
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
            <Dialog open={showCancelDialog} onOpenChange={(open) => { setShowCancelDialog(open); if (!open) cancelForm.reset(); }}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Cancel Leave Request</DialogTitle>
                        <DialogDescription>
                            {leaveRequest.status === 'approved' && leaveRequest.has_partial_denial
                                ? 'This leave request was partially approved. Cancelling will restore any deducted credits and remove associated attendance records. This action cannot be undone.'
                                : 'Are you sure you want to cancel this leave request? This action cannot be undone.'}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-2">
                        <Label htmlFor="cancel-reason">Reason for cancellation <span className="text-red-500">*</span></Label>
                        <Textarea
                            id="cancel-reason"
                            placeholder="Please provide a reason for cancelling this leave request..."
                            value={cancelForm.data.cancellation_reason}
                            onChange={(e) => cancelForm.setData('cancellation_reason', e.target.value)}
                            rows={3}
                        />
                        {cancelForm.errors.cancellation_reason && (
                            <p className="text-sm text-red-500">{cancelForm.errors.cancellation_reason}</p>
                        )}
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setShowCancelDialog(false)}>
                            No, Keep It
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={handleCancel}
                            disabled={cancelForm.processing || !cancelForm.data.cancellation_reason.trim()}
                        >
                            {cancelForm.processing ? 'Cancelling...' : 'Yes, Cancel Request'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Admin Cancel Leave Dialog */}
            <Dialog open={showAdminCancelDialog} onOpenChange={setShowAdminCancelDialog}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <AlertTriangle className="h-5 w-5 text-red-600" />
                            {leaveRequest.status === 'approved' ? 'Cancel Approved Leave Request' : 'Cancel Leave Request'}
                        </DialogTitle>
                        <DialogDescription>
                            {leaveRequest.status === 'approved'
                                ? 'You are about to cancel an approved leave request. This will restore the employee\'s leave credits and delete any associated attendance records.'
                                : 'You are about to cancel this pending leave request. This action cannot be undone.'}
                        </DialogDescription>
                    </DialogHeader>
                    {leaveRequest.status === 'approved' && (
                        <Alert className="border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950">
                            <AlertTriangle className="h-4 w-4 text-amber-600" />
                            <AlertDescription className="text-amber-800 dark:text-amber-200">
                                <ul className="list-disc list-inside text-sm space-y-1">
                                    <li>Leave credits ({leaveRequest.days_requested} days) will be restored</li>
                                    <li>Attendance records for leave dates will be deleted</li>
                                    <li>The employee will be notified of this cancellation</li>
                                </ul>
                            </AlertDescription>
                        </Alert>
                    )}
                    <div className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="cancellation_reason">Reason for Cancellation <span className="text-red-500">*</span></Label>
                            <Textarea
                                id="cancellation_reason"
                                value={adminCancelForm.data.cancellation_reason}
                                onChange={(e) => adminCancelForm.setData('cancellation_reason', e.target.value)}
                                placeholder={leaveRequest.status === 'approved'
                                    ? 'Please provide a reason for cancelling this approved leave...'
                                    : 'Please provide a reason for cancelling this leave request...'}
                                rows={3}
                            />
                            <p className="text-xs text-muted-foreground">
                                This reason will be recorded in the audit log and sent to the employee.
                            </p>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setShowAdminCancelDialog(false)}>
                            {leaveRequest.status === 'approved' ? 'Keep Approved' : 'Keep Request'}
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={handleAdminCancel}
                            disabled={adminCancelForm.processing || !adminCancelForm.data.cancellation_reason.trim()}
                        >
                            {leaveRequest.status === 'approved' ? 'Cancel Approved Leave' : 'Cancel Leave Request'}
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

            {/* Force Approve Dialog (Super Admin Only) */}
            <Dialog open={showForceApproveDialog} onOpenChange={(open) => {
                setShowForceApproveDialog(open);
                if (!open) {
                    setForceApprovePartialMode(false);
                    setForceApproveSelectedDates([]);
                    forceApproveForm.reset();
                }
            }}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Shield className="h-5 w-5 text-purple-600" />
                            Force Approve Leave Request
                        </DialogTitle>
                        <DialogDescription>
                            As Super Admin, you can force approve this leave request, bypassing the requirement for HR approval. This action will immediately approve the request.
                        </DialogDescription>
                    </DialogHeader>
                    <Alert className="border-purple-200 bg-purple-50 dark:border-purple-800 dark:bg-purple-950">
                        <Shield className="h-4 w-4 text-purple-600" />
                        <AlertDescription className="text-purple-800 dark:text-purple-200">
                            This will override any pending approvals from Team Lead, Admin, or HR.
                        </AlertDescription>
                    </Alert>
                    {/* Credit Split Preview for VL/SL in Force Approve */}
                    {creditPreview && (leaveRequest.leave_type === 'VL' || leaveRequest.leave_type === 'SL') && (
                        <>
                            {creditPreview.convert_to_upto && (
                                <Alert className="border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-950">
                                    <AlertTriangle className="h-4 w-4 text-red-600" />
                                    <AlertDescription className="text-red-800 dark:text-red-200">
                                        <strong>No {leaveRequest.leave_type} credits available.</strong> Will be converted to UPTO upon approval.
                                        {creditPreview.reason && <span className="block mt-1 text-sm">{creditPreview.reason}</span>}
                                    </AlertDescription>
                                </Alert>
                            )}
                            {creditPreview.partial_credit && (
                                <Alert className="border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950">
                                    <AlertTriangle className="h-4 w-4 text-amber-600" />
                                    <AlertDescription className="text-amber-800 dark:text-amber-200">
                                        <strong>Partial credits:</strong> {creditPreview.credits_to_deduct} day(s) as {leaveRequest.leave_type}, {creditPreview.upto_days} day(s) as linked UPTO.
                                    </AlertDescription>
                                </Alert>
                            )}
                            {!creditPreview.convert_to_upto && !creditPreview.partial_credit && creditPreview.should_deduct && (
                                <Alert className="border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-950">
                                    <CheckCircle className="h-4 w-4 text-green-600" />
                                    <AlertDescription className="text-green-800 dark:text-green-200">
                                        Full {leaveRequest.leave_type} credits available ({creditPreview.credits_to_deduct} day(s)).
                                    </AlertDescription>
                                </Alert>
                            )}
                        </>
                    )}
                    <div className="space-y-4">
                        {/* Partial Approval Toggle */}
                        {workDays.length > 1 && (
                            <div className="flex items-center space-x-2">
                                <Checkbox
                                    id="forceApprovePartialMode"
                                    checked={forceApprovePartialMode}
                                    onCheckedChange={(checked) => {
                                        setForceApprovePartialMode(checked === true);
                                        if (!checked) {
                                            setForceApproveSelectedDates([]);
                                            forceApproveForm.setData('denied_dates', []);
                                            forceApproveForm.setData('denial_reason', '');
                                        }
                                    }}
                                />
                                <label htmlFor="forceApprovePartialMode" className="text-sm font-medium cursor-pointer">
                                    Partial Approval (deny some dates)
                                </label>
                            </div>
                        )}

                        {/* Date Selection for Partial Approval */}
                        {forceApprovePartialMode && workDays.length > 1 && (
                            <div>
                                <Label className="text-sm font-medium mb-2 block">
                                    Select dates to APPROVE ({forceApproveSelectedDates.length} of {workDays.length} selected)
                                </Label>
                                <div className="border rounded-md p-3 max-h-48 overflow-y-auto space-y-2">
                                    {workDays.map((date) => {
                                        const dateStr = format(date, 'yyyy-MM-dd');
                                        const isSelected = forceApproveSelectedDates.includes(dateStr);
                                        return (
                                            <div
                                                key={dateStr}
                                                className={`flex items-center space-x-3 p-2 rounded cursor-pointer transition-colors ${isSelected ? 'bg-green-50 border border-green-200 dark:bg-green-950 dark:border-green-800' : 'hover:bg-muted'}`}
                                                onClick={() => toggleForceApproveDate(dateStr)}
                                            >
                                                <Checkbox
                                                    checked={isSelected}
                                                    onCheckedChange={() => toggleForceApproveDate(dateStr)}
                                                />
                                                <span className="font-medium text-sm">{format(date, 'EEE, MMM d, yyyy')}</span>
                                                {isSelected && (
                                                    <Badge className="text-xs bg-green-600 text-white ml-auto">Approve</Badge>
                                                )}
                                                {!isSelected && (
                                                    <Badge variant="destructive" className="text-xs ml-auto">Deny</Badge>
                                                )}
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        )}

                        {/* Denial Reason for Partial Approval */}
                        {forceApprovePartialMode && forceApproveSelectedDates.length > 0 && forceApproveSelectedDates.length < workDays.length && (
                            <div>
                                <label className="text-sm font-medium">
                                    Reason for Denying {workDays.length - forceApproveSelectedDates.length} Date(s) <span className="text-red-500">*</span>
                                </label>
                                <Textarea
                                    value={forceApproveForm.data.denial_reason}
                                    onChange={(e) => forceApproveForm.setData('denial_reason', e.target.value)}
                                    placeholder="Why are some dates being denied? (required, minimum 10 characters)..."
                                    rows={2}
                                />
                                {forceApproveForm.errors.denial_reason && (
                                    <p className="text-sm text-red-500 mt-1">{forceApproveForm.errors.denial_reason}</p>
                                )}
                            </div>
                        )}

                        <div>
                            <label className="text-sm font-medium">
                                Review Notes <span className="text-red-500">*</span>
                            </label>
                            <Textarea
                                value={forceApproveForm.data.review_notes}
                                onChange={(e) => forceApproveForm.setData('review_notes', e.target.value)}
                                placeholder="Add comments for the force approval (required, minimum 10 characters)..."
                                rows={3}
                            />
                            {forceApproveForm.errors.review_notes && (
                                <p className="text-sm text-red-500 mt-1">{forceApproveForm.errors.review_notes}</p>
                            )}
                        </div>

                        {/* Summary for Partial Approval */}
                        {forceApprovePartialMode && forceApproveSelectedDates.length > 0 && forceApproveSelectedDates.length < workDays.length && (
                            <Alert className="bg-orange-50 border-orange-200 dark:bg-orange-950 dark:border-orange-800">
                                <AlertTriangle className="h-4 w-4 text-orange-600" />
                                <AlertDescription className="text-orange-800 dark:text-orange-200">
                                    <p> {forceApproveSelectedDates.length} day(s) will be <strong>approved</strong>, {workDays.length - forceApproveSelectedDates.length} day(s) will be <strong>denied</strong>. </p>
                                </AlertDescription>
                            </Alert>
                        )}
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => {
                            setShowForceApproveDialog(false);
                            setForceApprovePartialMode(false);
                            setForceApproveSelectedDates([]);
                            forceApproveForm.reset();
                        }}>
                            Cancel
                        </Button>
                        <Button
                            className="bg-purple-600 hover:bg-purple-700"
                            onClick={handleForceApprove}
                            disabled={
                                forceApproveForm.processing ||
                                (forceApprovePartialMode && forceApproveSelectedDates.length === 0) ||
                                (forceApprovePartialMode && forceApproveSelectedDates.length === workDays.length)
                            }
                        >
                            <Shield className="mr-1 h-4 w-4" />
                            {forceApprovePartialMode && forceApproveSelectedDates.length > 0 && forceApproveSelectedDates.length < workDays.length
                                ? `Force Approve (${forceApproveSelectedDates.length} days)`
                                : 'Force Approve All'
                            }
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Adjust for Work Day Dialog */}
            <Dialog open={showAdjustForWorkDialog} onOpenChange={setShowAdjustForWorkDialog}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Calendar className="h-5 w-5 text-blue-600" />
                            Adjust Leave for Work Day
                        </DialogTitle>
                        <DialogDescription>
                            Adjust this leave when the employee reported to work on one of the leave days. Credits will be restored for the adjusted days.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4 py-2">
                        <Alert className="border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-950">
                            <Info className="h-4 w-4 text-blue-600" />
                            <AlertDescription className="text-blue-800 dark:text-blue-200">
                                Current leave: {format(parseISO(leaveRequest.start_date), 'MMM d')} - {format(parseISO(leaveRequest.end_date), 'MMM d, yyyy')} ({leaveRequest.days_requested} days)
                            </AlertDescription>
                        </Alert>

                        <div className="space-y-2">
                            <Label htmlFor="work_date">Date Employee Reported to Work</Label>
                            <Select
                                value={adjustForWorkForm.data.work_date}
                                onValueChange={(value) => adjustForWorkForm.setData('work_date', value)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select the work date" />
                                </SelectTrigger>
                                <SelectContent>
                                    {getLeaveDates().map((date) => (
                                        <SelectItem key={date.toISOString()} value={format(date, 'yyyy-MM-dd')}>
                                            {format(date, 'EEEE, MMM d, yyyy')}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="adjustment_type">How to Adjust Leave</Label>
                            <Select
                                value={adjustForWorkForm.data.adjustment_type}
                                onValueChange={(value: 'end_early' | 'start_late' | 'remove_day') =>
                                    adjustForWorkForm.setData('adjustment_type', value)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select adjustment type" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="end_early">
                                        End leave early (reported on work date, worked remaining days)
                                    </SelectItem>
                                    <SelectItem value="start_late">
                                        Start leave later (reported on work date, on leave after)
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p className="text-xs text-muted-foreground">
                                {adjustForWorkForm.data.adjustment_type === 'end_early'
                                    ? 'Leave will end the day before the work date. Days after will be restored.'
                                    : 'Leave will start the day after the work date. Days before will be restored.'
                                }
                            </p>
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="notes">Notes (Optional)</Label>
                            <Textarea
                                id="notes"
                                value={adjustForWorkForm.data.notes}
                                onChange={(e) => adjustForWorkForm.setData('notes', e.target.value)}
                                placeholder="Add any notes about this adjustment..."
                                rows={2}
                            />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => {
                            setShowAdjustForWorkDialog(false);
                            adjustForWorkForm.reset();
                        }}>
                            Cancel
                        </Button>
                        <Button
                            onClick={handleAdjustForWork}
                            disabled={adjustForWorkForm.processing || !adjustForWorkForm.data.work_date}
                            className="bg-blue-600 hover:bg-blue-700"
                        >
                            <Calendar className="mr-1 h-4 w-4" />
                            Adjust Leave
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Medical Certificate Dialog */}
            <Dialog open={showMedicalCertDialog} onOpenChange={setShowMedicalCertDialog}>
                <DialogContent className="max-w-3xl max-h-[90vh]">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <FileImage className="h-5 w-5" />
                            {leaveRequest.leave_type === 'SL' ? 'Medical Certificate' : 'Supporting Document'}
                        </DialogTitle>
                        <DialogDescription>
                            Leave Request #{leaveRequest.id} - {leaveRequest.user.name}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="flex flex-col gap-4">
                        {/* Zoom Controls */}
                        <div className="flex items-center justify-center gap-2 pb-2 border-b">
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={handleZoomOut}
                                disabled={medicalCertZoom <= 50}
                            >
                                <ZoomOut className="h-4 w-4" />
                            </Button>
                            <span className="text-sm font-medium min-w-[60px] text-center">{medicalCertZoom}%</span>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={handleZoomIn}
                                disabled={medicalCertZoom >= 300}
                            >
                                <ZoomIn className="h-4 w-4" />
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={handleZoomReset}
                            >
                                <RotateCcw className="h-4 w-4" />
                            </Button>
                        </div>
                        {/* Image Container */}
                        <div className="flex justify-center items-start p-4 overflow-auto max-h-[60vh]">
                            {leaveRequest.medical_cert_path && (
                                <img
                                    src={leaveMedicalCertRoute(leaveRequest.id).url}
                                    alt={leaveRequest.leave_type === 'SL' ? 'Medical Certificate' : 'Supporting Document'}
                                    className="max-w-full object-contain rounded-lg border transition-transform duration-200"
                                    style={{ width: `${medicalCertZoom}%`, height: 'auto' }}
                                />
                            )}
                        </div>
                    </div>

                    <DialogFooter>
                        <a
                            href={leaveMedicalCertRoute(leaveRequest.id).url}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <Button variant="outline">
                                <ExternalLink className="h-4 w-4 mr-2" />
                                Open in New Tab
                            </Button>
                        </a>
                        <Button onClick={() => setShowMedicalCertDialog(false)}>
                            Close
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Attendance Points Details Dialog */}
            <Dialog open={showAttendancePointsDialog} onOpenChange={setShowAttendancePointsDialog}>
                <DialogContent className="max-w-3xl max-h-[80vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Attendance Points at Time of Request</DialogTitle>
                        <DialogDescription>
                            Points that were active when {leaveRequest.user.name} submitted this leave request
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4">
                        {activeAttendancePoints && activeAttendancePoints.length > 0 ? (
                            <>
                                <div className="bg-muted/50 p-3 rounded-lg">
                                    <div className="flex items-center justify-between">
                                        <span className="text-sm font-medium">Total Points at Request:</span>
                                        <span className="text-2xl font-bold text-destructive">
                                            {Number(leaveRequest.attendance_points_at_request || 0).toFixed(1)}
                                        </span>
                                    </div>
                                </div>

                                <div className="space-y-3">
                                    {activeAttendancePoints.map((point, index) => (
                                        <div key={point.id} className={`border rounded-lg p-4 ${point.current_status !== 'active' ? 'opacity-60 bg-muted/30' : ''}`}>
                                            <div className="flex items-start justify-between gap-4">
                                                <div className="space-y-3 flex-1">
                                                    <div className="space-y-1">
                                                        <div className="flex items-center gap-2">
                                                            <span className="text-muted-foreground text-sm font-medium">#{index + 1}</span>
                                                            <div className="flex items-center gap-2 text-sm font-medium">
                                                                <Calendar className="h-4 w-4 text-muted-foreground" />
                                                                <span>
                                                                    {format(parseISO(point.shift_date), 'EEEE, MMMM d, yyyy')}
                                                                </span>
                                                            </div>
                                                        </div>

                                                        <div className="flex items-start gap-2 text-sm ml-6">
                                                            <AlertTriangle className="h-4 w-4 text-muted-foreground mt-0.5 shrink-0" />
                                                            <div>
                                                                <p className="font-medium text-muted-foreground">
                                                                    {formatPointType(point.point_type)}
                                                                </p>
                                                                {point.violation_details && (
                                                                    <p className="text-xs text-muted-foreground mt-1">
                                                                        {point.violation_details}
                                                                    </p>
                                                                )}
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div className="ml-6 space-y-1">
                                                        {point.current_status === 'active' && (point.expires_at || point.gbro_expires_at) && (
                                                            <>
                                                                {point.expires_at && (
                                                                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                                                        <Clock className="h-3.5 w-3.5" />
                                                                        <span>
                                                                            SRO Expires: {format(parseISO(point.expires_at), 'MMM d, yyyy')}
                                                                        </span>
                                                                    </div>
                                                                )}
                                                                {point.eligible_for_gbro && point.gbro_expires_at && (
                                                                    <div className="flex items-center gap-2 text-xs text-green-600 dark:text-green-400">
                                                                        <CheckCircle className="h-3.5 w-3.5" />
                                                                        <span>
                                                                            GBRO Expires: {format(parseISO(point.gbro_expires_at), 'MMM d, yyyy')}
                                                                        </span>
                                                                    </div>
                                                                )}
                                                                {!point.eligible_for_gbro && (
                                                                    <div className="flex items-center gap-2 text-xs text-orange-600 dark:text-orange-400">
                                                                        <AlertTriangle className="h-3.5 w-3.5" />
                                                                        <span>Not eligible for GBRO</span>
                                                                    </div>
                                                                )}
                                                            </>
                                                        )}
                                                        {point.current_status === 'excused' && point.excused_at && (
                                                            <div className="flex items-center gap-2 text-xs text-green-600">
                                                                <CheckCircle className="h-3.5 w-3.5" />
                                                                <span>
                                                                    Excused on: {format(parseISO(point.excused_at), 'MMM d, yyyy')}
                                                                </span>
                                                            </div>
                                                        )}
                                                        {point.current_status === 'expired' && point.expired_at && (
                                                            <div className="flex items-center gap-2 text-xs text-gray-500">
                                                                <Clock className="h-3.5 w-3.5" />
                                                                <span>
                                                                    Expired on: {format(parseISO(point.expired_at), 'MMM d, yyyy')}
                                                                </span>
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>

                                                <div className="flex flex-col items-end gap-2 shrink-0">
                                                    <Badge variant="destructive" className="text-sm px-3 py-1">
                                                        {point.points} {point.points === 1 ? 'Point' : 'Points'}
                                                    </Badge>
                                                    {point.current_status === 'excused' && (
                                                        <Badge variant="outline" className="text-xs bg-green-100 text-green-700 border-green-300">
                                                            <CheckCircle className="h-3 w-3 mr-1" />
                                                            Now Excused
                                                        </Badge>
                                                    )}
                                                    {point.current_status === 'expired' && (
                                                        <Badge variant="outline" className="text-xs bg-gray-100 text-gray-600 border-gray-300">
                                                            <Clock className="h-3 w-3 mr-1" />
                                                            Now Expired
                                                        </Badge>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>

                                {/* Show note if any points have changed status */}
                                {activeAttendancePoints.some(p => p.current_status !== 'active') && (
                                    <Alert className="border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-950">
                                        <Info className="h-4 w-4 text-blue-600" />
                                        <AlertDescription className="text-blue-800 dark:text-blue-200 text-sm">
                                            Some points shown above have been excused or expired since this request was submitted.
                                            The grayed-out entries were active at the time of submission but are no longer counted.
                                        </AlertDescription>
                                    </Alert>
                                )}
                            </>
                        ) : (
                            <div className="text-center py-8 text-muted-foreground">
                                No attendance points were recorded at the time of this request
                            </div>
                        )}
                    </div>
                    <DialogFooter>
                        <Button onClick={() => setShowAttendancePointsDialog(false)}>
                            Close
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
