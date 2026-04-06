import React from 'react';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';

interface TableSkeletonProps {
    columns?: number;
    rows?: number;
    className?: string;
    showHeader?: boolean;
}

/**
 * Skeleton loading placeholder for table data
 * Provides consistent table loading state across list/index pages
 *
 * @example
 * ```tsx
 * {isLoading ? (
 *     <TableSkeleton columns={5} rows={8} />
 * ) : (
 *     <Table>...</Table>
 * )}
 * ```
 */
export function TableSkeleton({
    columns = 4,
    rows = 5,
    className,
    showHeader = true,
}: TableSkeletonProps) {
    return (
        <div className={cn('rounded-md border overflow-hidden', className)}>
            <Table>
                {showHeader && (
                    <TableHeader>
                        <TableRow className="bg-muted/50">
                            {Array.from({ length: columns }).map((_, i) => (
                                <TableHead key={i}>
                                    <Skeleton className="h-4 w-20" />
                                </TableHead>
                            ))}
                        </TableRow>
                    </TableHeader>
                )}
                <TableBody>
                    {Array.from({ length: rows }).map((_, rowIdx) => (
                        <TableRow key={rowIdx}>
                            {Array.from({ length: columns }).map((_, colIdx) => (
                                <TableCell key={colIdx}>
                                    <Skeleton
                                        className={cn(
                                            'h-4',
                                            colIdx === 0 ? 'w-12' : colIdx === columns - 1 ? 'w-16' : 'w-24',
                                        )}
                                    />
                                </TableCell>
                            ))}
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
