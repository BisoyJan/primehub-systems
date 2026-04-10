import { cn } from '@/lib/utils';
import type { CoachingStatusLabel } from '@/types';

interface CoachingSummaryCardsProps {
    totalAgents: number;
    statusCounts: Record<string, number>;
    totalLabel?: string;
    className?: string;
}

const statusConfig: { label: CoachingStatusLabel; key: string; bg: string; text: string; border: string }[] = [
    { label: 'Coaching Done', key: 'Coaching Done', bg: 'bg-green-50 dark:bg-green-950/20', text: 'text-green-700 dark:text-green-400', border: 'border-green-200 dark:border-green-800' },
    { label: 'Needs Coaching', key: 'Needs Coaching', bg: 'bg-yellow-50 dark:bg-yellow-950/20', text: 'text-yellow-700 dark:text-yellow-400', border: 'border-yellow-200 dark:border-yellow-800' },
    { label: 'Badly Needs Coaching', key: 'Badly Needs Coaching', bg: 'bg-orange-50 dark:bg-orange-950/20', text: 'text-orange-700 dark:text-orange-400', border: 'border-orange-200 dark:border-orange-800' },
    { label: 'Please Coach ASAP', key: 'Please Coach ASAP', bg: 'bg-red-50 dark:bg-red-950/20', text: 'text-red-700 dark:text-red-400', border: 'border-red-200 dark:border-red-800' },
    { label: 'No Record', key: 'No Record', bg: 'bg-gray-50 dark:bg-gray-950/20', text: 'text-gray-600 dark:text-gray-400', border: 'border-gray-200 dark:border-gray-700' },
    { label: 'Draft', key: 'Draft', bg: 'bg-blue-50 dark:bg-blue-950/20', text: 'text-blue-700 dark:text-blue-400', border: 'border-blue-200 dark:border-blue-800' },
];

export function CoachingSummaryCards({ totalAgents, statusCounts, totalLabel, className }: CoachingSummaryCardsProps) {
    return (
        <div className={cn('grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-7', className)}>
            {/* Total Agents card */}
            <div className="rounded-lg border bg-card p-3 shadow-sm">
                <p className="text-xs text-muted-foreground font-medium">{totalLabel ?? 'Total Agents'}</p>
                <p className="text-2xl font-bold mt-1">{totalAgents}</p>
            </div>

            {/* Status cards */}
            {statusConfig.map(({ label, key, bg, text, border }) => (
                <div key={key} className={cn('rounded-lg border p-3 shadow-sm', bg, border)}>
                    <p className={cn('text-xs font-medium', text)}>{label}</p>
                    <p className={cn('text-2xl font-bold mt-1', text)}>
                        {statusCounts[key] ?? 0}
                    </p>
                </div>
            ))}
        </div>
    );
}
