import { cn } from '@/lib/utils';
import type { CoachingStatusLabel } from '@/types';

interface CoachingStatusBadgeProps {
    status: CoachingStatusLabel | string;
    className?: string;
    size?: 'sm' | 'md';
}

const statusStyles: Record<string, string> = {
    'Coaching Done': 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
    'Needs Coaching': 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
    'Badly Needs Coaching': 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400',
    'Please Coach ASAP': 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
    'No Record': 'bg-gray-100 text-gray-600 dark:bg-gray-800/30 dark:text-gray-400',
    'Draft': 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
};

export function CoachingStatusBadge({ status, className, size = 'sm' }: CoachingStatusBadgeProps) {
    const style = statusStyles[status] ?? statusStyles['No Record'];

    return (
        <span
            className={cn(
                'inline-flex items-center font-medium rounded-full whitespace-nowrap',
                size === 'sm' ? 'px-2 py-0.5 text-xs' : 'px-3 py-1 text-sm',
                style,
                className,
            )}
        >
            {status}
        </span>
    );
}

// Additional badges for ack/compliance statuses
const ackStyles: Record<string, string> = {
    Pending: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400',
    Acknowledged: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
    Disputed: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
};

export function AckStatusBadge({ status, className }: { status: string; className?: string }) {
    return (
        <span
            className={cn(
                'inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full whitespace-nowrap',
                ackStyles[status] ?? 'bg-gray-100 text-gray-600',
                className,
            )}
        >
            {status}
        </span>
    );
}

const complianceStyles: Record<string, string> = {
    Awaiting_Agent_Ack: 'bg-slate-100 text-slate-700 dark:bg-slate-800/30 dark:text-slate-400',
    For_Review: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
    Verified: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
    Rejected: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
};

const complianceLabels: Record<string, string> = {
    Awaiting_Agent_Ack: 'Awaiting Ack',
    For_Review: 'For Review',
    Verified: 'Verified',
    Rejected: 'Rejected',
};

export function ComplianceStatusBadge({ status, className }: { status: string; className?: string }) {
    return (
        <span
            className={cn(
                'inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full whitespace-nowrap',
                complianceStyles[status] ?? 'bg-gray-100 text-gray-600',
                className,
            )}
        >
            {complianceLabels[status] ?? status}
        </span>
    );
}

export function SeverityBadge({ flag, className }: { flag: string; className?: string }) {
    const styles: Record<string, string> = {
        Critical: 'bg-red-600 text-white',
        Normal: 'bg-muted text-muted-foreground',
    };

    return (
        <span
            className={cn(
                'inline-flex items-center px-2 py-0.5 text-xs font-semibold rounded-full',
                styles[flag] ?? 'bg-muted text-muted-foreground',
                className,
            )}
        >
            {flag}
        </span>
    );
}
