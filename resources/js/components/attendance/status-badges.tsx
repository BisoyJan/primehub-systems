import React from 'react';
import { Badge } from '@/components/ui/badge';
import { CheckCircle, AlertCircle } from 'lucide-react';

/**
 * Attendance status configuration - Single source of truth for all status badges
 */
export const STATUS_CONFIG: Record<string, { label: string; className: string }> = {
    on_time: { label: 'On Time', className: 'bg-green-500' },
    tardy: { label: 'Tardy', className: 'bg-yellow-500' },
    half_day_absence: { label: 'Half Day', className: 'bg-orange-500' },
    advised_absence: { label: 'Advised Absence', className: 'bg-blue-500' },
    on_leave: { label: 'On Leave', className: 'bg-blue-600' },
    ncns: { label: 'NCNS', className: 'bg-red-500' },
    undertime: { label: 'Undertime', className: 'bg-orange-400' },
    undertime_more_than_hour: { label: 'UT >1hr', className: 'bg-orange-600' },
    failed_bio_in: { label: 'Failed Bio In', className: 'bg-purple-500' },
    failed_bio_out: { label: 'Failed Bio Out', className: 'bg-purple-500' },
    needs_manual_review: { label: 'Needs Review', className: 'bg-amber-500' },
    present_no_bio: { label: 'Present (No Bio)', className: 'bg-gray-500' },
    non_work_day: { label: 'Non-Work Day', className: 'bg-slate-500' },
};

/**
 * Get a single status badge
 */
export const getStatusBadge = (status: string) => {
    const config = STATUS_CONFIG[status] || { label: status, className: 'bg-gray-500' };
    return <Badge className={config.className}>{config.label}</Badge>;
};

/**
 * Props for attendance record status badges
 */
interface AttendanceStatusProps {
    status: string;
    secondaryStatus?: string | null;
    adminVerified?: boolean;
    overtimeMinutes?: number;
    overtimeApproved?: boolean;
    warnings?: string[];
    /** Show "On Leave (Manual)" label when status is on_leave */
    showManualLeaveLabel?: boolean;
}

/**
 * Get all status badges for an attendance record
 * Displays primary status on top, secondary status below
 * Includes overtime, verified icon, and warning icon
 */
export const AttendanceStatusBadges = ({
    status,
    secondaryStatus,
    adminVerified,
    overtimeMinutes,
    overtimeApproved,
    warnings,
    showManualLeaveLabel = false,
}: AttendanceStatusProps) => {
    return (
        <div className="flex flex-col gap-1 items-start">
            {/* Primary Status Row */}
            <div className="flex items-center gap-1">
                {showManualLeaveLabel && status === 'on_leave' ? (
                    <Badge className="bg-blue-500">On Leave (Manual)</Badge>
                ) : (
                    getStatusBadge(status)
                )}
                {adminVerified && (
                    <span title="Verified">
                        <CheckCircle className="h-4 w-4 text-green-500" />
                    </span>
                )}
                {warnings && warnings.length > 0 && (
                    <span title="Has warnings - needs review">
                        <AlertCircle className="h-4 w-4 text-amber-500" />
                    </span>
                )}
            </div>
            {/* Secondary Status Row */}
            {secondaryStatus && (
                <div className="flex items-center gap-1">
                    {getStatusBadge(secondaryStatus)}
                </div>
            )}
            {/* Overtime Row - only show if overtime is more than 30 minutes (threshold) */}
            {overtimeMinutes && overtimeMinutes > 30 && (
                <div className="flex items-center gap-1">
                    <Badge className={overtimeApproved ? 'bg-green-500' : 'bg-blue-500'}>
                        Overtime{overtimeApproved && ' ✓'}
                    </Badge>
                </div>
            )}
        </div>
    );
};

/**
 * Legacy function wrapper for getStatusBadges - for backward compatibility
 * Use AttendanceStatusBadges component for new code
 */
export const getStatusBadges = (record: {
    status: string;
    secondary_status?: string | null;
    admin_verified?: boolean;
    overtime_minutes?: number;
    overtime_approved?: boolean;
    warnings?: string[];
}) => {
    return (
        <AttendanceStatusBadges
            status={record.status}
            secondaryStatus={record.secondary_status}
            adminVerified={record.admin_verified}
            overtimeMinutes={record.overtime_minutes}
            overtimeApproved={record.overtime_approved}
            warnings={record.warnings}
        />
    );
};
