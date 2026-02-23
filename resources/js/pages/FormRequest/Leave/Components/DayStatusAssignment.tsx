import React, { useMemo, useState as useLocalState } from 'react';
import { format, parseISO } from 'date-fns';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { CheckCircle, XCircle, AlertTriangle, Info, MessageSquarePlus, ChevronDown, ChevronUp } from 'lucide-react';
import { cn } from '@/lib/utils';

export interface DayStatus {
    date: string;
    status: 'pending' | 'sl_credited' | 'ncns' | 'advised_absence';
    notes?: string;
}

export interface ExistingDayRecord {
    id: number;
    date: string;
    day_status: string;
    notes: string | null;
    status_label: string;
    is_paid: boolean;
    assigned_by: string | null;
    assigned_at: string | null;
}

interface Props {
    dayStatuses: DayStatus[];
    onChange: (dayStatuses: DayStatus[]) => void;
    readOnly?: boolean;
    existingRecords?: ExistingDayRecord[] | null;
    creditPreviewInfo?: {
        availableCredits: number;
    } | null;
}

const STATUS_OPTIONS = [
    {
        value: 'sl_credited',
        label: 'SL Credited',
        shortLabel: 'SL Credited',
        description: 'Paid — uses SL credit',
        color: 'bg-green-100 text-green-800 border-green-200 dark:bg-green-900/30 dark:text-green-300 dark:border-green-800',
        dotColor: 'bg-green-500',
        tag: 'Paid',
        tagColor: 'text-green-600 dark:text-green-400',
    },
    {
        value: 'ncns',
        label: 'NCNS',
        shortLabel: 'NCNS',
        description: 'No Call, No Show — unpaid + attendance point',
        color: 'bg-red-100 text-red-800 border-red-200 dark:bg-red-900/30 dark:text-red-300 dark:border-red-800',
        dotColor: 'bg-red-500',
        tag: 'Unpaid',
        tagColor: 'text-red-600 dark:text-red-400',
    },
    {
        value: 'advised_absence',
        label: 'Advised Absence (UPTO)',
        shortLabel: 'Advised Absence',
        description: 'Unpaid Time Off — informed but no credits',
        color: 'bg-amber-100 text-amber-800 border-amber-200 dark:bg-amber-900/30 dark:text-amber-300 dark:border-amber-800',
        dotColor: 'bg-amber-500',
        tag: 'Unpaid',
        tagColor: 'text-amber-600 dark:text-amber-400',
    },
] as const;

function getStatusOption(value: string) {
    return STATUS_OPTIONS.find((opt) => opt.value === value);
}

function StatusBadge({ status }: { status: string }) {
    const opt = getStatusOption(status);
    if (!opt) return <Badge variant="outline">Pending</Badge>;

    return (
        <span className={cn('inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium', opt.color)}>
            <span className={cn('h-1.5 w-1.5 rounded-full', opt.dotColor)} />
            {opt.label}
        </span>
    );
}

export default function DayStatusAssignment({ dayStatuses, onChange, readOnly = false, existingRecords = null, creditPreviewInfo = null }: Props) {
    const [expandedNotes, setExpandedNotes] = useLocalState<Set<number>>(() => {
        // Auto-expand rows that already have notes
        const initial = new Set<number>();
        dayStatuses.forEach((d, i) => {
            if (d.notes && d.notes.trim().length > 0) initial.add(i);
        });
        return initial;
    });

    const toggleNotes = (index: number) => {
        setExpandedNotes((prev) => {
            const next = new Set(prev);
            if (next.has(index)) {
                next.delete(index);
            } else {
                next.add(index);
            }
            return next;
        });
    };

    const summary = useMemo(() => {
        const credited = dayStatuses.filter((d) => d.status === 'sl_credited').length;
        const ncns = dayStatuses.filter((d) => d.status === 'ncns').length;
        const advised = dayStatuses.filter((d) => d.status === 'advised_absence').length;
        const pending = dayStatuses.filter((d) => d.status === 'pending').length;
        return { credited, ncns, advised, pending, total: dayStatuses.length };
    }, [dayStatuses]);

    const creditExceeded = creditPreviewInfo && summary.credited > creditPreviewInfo.availableCredits;

    const handleStatusChange = (index: number, newStatus: DayStatus['status']) => {
        const updated = [...dayStatuses];
        updated[index] = { ...updated[index], status: newStatus };
        onChange(updated);
    };

    const handleNotesChange = (index: number, notes: string) => {
        const updated = [...dayStatuses];
        updated[index] = { ...updated[index], notes };
        onChange(updated);
    };

    // Quick assign all to a specific status
    const handleBulkAssign = (status: DayStatus['status']) => {
        onChange(dayStatuses.map((d) => ({ ...d, status })));
    };

    if (readOnly && existingRecords && existingRecords.length > 0) {
        return <ReadOnlyView records={existingRecords} />;
    }

    return (
        <div className="space-y-3">
            {/* Summary Bar */}
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border bg-muted/50 px-3 py-2">
                <span className="text-xs font-medium text-muted-foreground">Summary:</span>
                {summary.credited > 0 && (
                    <span className="inline-flex items-center gap-1 text-xs font-medium text-green-700 dark:text-green-400">
                        <CheckCircle className="h-3 w-3" /> {summary.credited} SL Credited
                    </span>
                )}
                {summary.ncns > 0 && (
                    <span className="inline-flex items-center gap-1 text-xs font-medium text-red-700 dark:text-red-400">
                        <XCircle className="h-3 w-3" /> {summary.ncns} NCNS
                    </span>
                )}
                {summary.advised > 0 && (
                    <span className="inline-flex items-center gap-1 text-xs font-medium text-amber-700 dark:text-amber-400">
                        <AlertTriangle className="h-3 w-3" /> {summary.advised} Advised Absence
                    </span>
                )}
                {summary.pending > 0 && (
                    <span className="inline-flex items-center gap-1 text-xs font-medium text-muted-foreground">
                        <Info className="h-3 w-3" /> {summary.pending} Pending
                    </span>
                )}
            </div>

            {/* Credit Warning */}
            {creditExceeded && creditPreviewInfo && (
                <Alert className="border-red-200 bg-red-50 py-2 dark:border-red-800 dark:bg-red-950">
                    <AlertTriangle className="h-4 w-4 text-red-600" />
                    <AlertDescription className="text-red-800 dark:text-red-200 text-xs">
                        <strong>Credit limit exceeded.</strong> {summary.credited} SL Credited assigned, but only{' '}
                        {creditPreviewInfo.availableCredits} credit(s) available.
                    </AlertDescription>
                </Alert>
            )}

            {/* Quick Assign Buttons */}
            {!readOnly && dayStatuses.length > 1 && (
                <div className="flex flex-wrap items-center gap-1.5">
                    <span className="text-[11px] text-muted-foreground">Quick assign all:</span>
                    {STATUS_OPTIONS.map((opt) => (
                        <button
                            key={opt.value}
                            type="button"
                            onClick={() => handleBulkAssign(opt.value as DayStatus['status'])}
                            className={cn(
                                'inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-[11px] font-medium transition-colors hover:opacity-80',
                                opt.color,
                            )}
                        >
                            {opt.shortLabel}
                        </button>
                    ))}
                </div>
            )}

            {/* Day-by-Day Assignment — Compact Table-like Layout */}
            <div className="rounded-lg border divide-y overflow-hidden">
                {dayStatuses.map((day, index) => {
                    const statusOpt = getStatusOption(day.status);
                    const hasNotes = day.notes && day.notes.trim().length > 0;
                    const isNoteExpanded = expandedNotes.has(index);

                    return (
                        <div
                            key={day.date}
                            className={cn(
                                'transition-colors',
                                day.status === 'sl_credited' && 'bg-green-50/40 dark:bg-green-950/10',
                                day.status === 'ncns' && 'bg-red-50/40 dark:bg-red-950/10',
                                day.status === 'advised_absence' && 'bg-amber-50/40 dark:bg-amber-950/10',
                                day.status === 'pending' && 'bg-background',
                            )}
                        >
                            {/* Main row */}
                            <div className="flex items-center gap-2 px-3 py-2">
                                {/* Color indicator */}
                                <div
                                    className={cn(
                                        'w-1 self-stretch rounded-full shrink-0',
                                        statusOpt?.dotColor ?? 'bg-muted-foreground/30',
                                    )}
                                />

                                {/* Date */}
                                <div className="min-w-0 shrink-0">
                                    <span className="text-sm font-medium leading-tight">
                                        {format(parseISO(day.date), 'EEE, MMM d')}
                                    </span>
                                </div>

                                {/* Status Select — pushed to the right */}
                                <div className="ml-auto flex items-center gap-2">
                                    <Select
                                        value={day.status}
                                        onValueChange={(value) => handleStatusChange(index, value as DayStatus['status'])}
                                        disabled={readOnly}
                                    >
                                        <SelectTrigger className="h-7 w-[170px] text-xs border-0 bg-white/70 dark:bg-white/10 shadow-sm">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {STATUS_OPTIONS.map((opt) => (
                                                <SelectItem key={opt.value} value={opt.value}>
                                                    <div className="flex items-center gap-2">
                                                        <span className={cn('h-2 w-2 rounded-full shrink-0', opt.dotColor)} />
                                                        <span>{opt.shortLabel}</span>
                                                        <span className="text-muted-foreground text-[10px]">
                                                            ({opt.tag})
                                                        </span>
                                                    </div>
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>

                                    {/* Add/toggle note button */}
                                    {!readOnly && (
                                        <button
                                            type="button"
                                            onClick={() => toggleNotes(index)}
                                            className={cn(
                                                'inline-flex items-center justify-center h-7 w-7 rounded-md text-muted-foreground hover:text-foreground hover:bg-muted/80 transition-colors shrink-0',
                                                isNoteExpanded && 'bg-muted text-foreground',
                                            )}
                                            title={isNoteExpanded ? 'Hide notes' : 'Add notes'}
                                        >
                                            {isNoteExpanded ? <ChevronUp className="h-3.5 w-3.5" /> : <MessageSquarePlus className="h-3.5 w-3.5" />}
                                        </button>
                                    )}
                                </div>
                            </div>

                            {/* Auto-assigned note preview (when not expanded) */}
                            {!isNoteExpanded && hasNotes && (
                                <div className="px-3 pb-2 pl-7">
                                    <p className="text-[11px] text-muted-foreground italic truncate">{day.notes}</p>
                                </div>
                            )}

                            {/* Expandable notes area */}
                            {isNoteExpanded && !readOnly && (
                                <div className="px-3 pb-2 pl-7">
                                    <Textarea
                                        value={day.notes || ''}
                                        onChange={(e) => handleNotesChange(index, e.target.value)}
                                        placeholder="Add a note for this day..."
                                        rows={1}
                                        className="text-xs resize-none min-h-[28px] bg-white/70 dark:bg-white/10"
                                        disabled={readOnly}
                                        autoFocus
                                    />
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>

            {/* Legend */}
            <div className="flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-muted-foreground px-1">
                <span className="inline-flex items-center gap-1">
                    <span className="h-1.5 w-1.5 rounded-full bg-green-500" /> SL Credited = Paid (uses credit)
                </span>
                <span className="inline-flex items-center gap-1">
                    <span className="h-1.5 w-1.5 rounded-full bg-red-500" /> NCNS = Unpaid + attendance point
                </span>
                <span className="inline-flex items-center gap-1">
                    <span className="h-1.5 w-1.5 rounded-full bg-amber-500" /> Advised Absence = UPTO (Unpaid)
                </span>
            </div>
        </div>
    );
}

function ReadOnlyView({ records }: { records: ExistingDayRecord[] }) {
    const summary = useMemo(() => {
        const credited = records.filter((r) => r.day_status === 'sl_credited').length;
        const ncns = records.filter((r) => r.day_status === 'ncns').length;
        const advised = records.filter((r) => r.day_status === 'advised_absence').length;
        return { credited, ncns, advised, total: records.length };
    }, [records]);

    return (
        <div className="space-y-3">
            {/* Summary */}
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border bg-muted/50 px-3 py-2">
                <span className="text-xs font-medium text-muted-foreground">Day Statuses:</span>
                {summary.credited > 0 && (
                    <span className="inline-flex items-center gap-1 text-xs font-medium text-green-700 dark:text-green-400">
                        <CheckCircle className="h-3 w-3" /> {summary.credited} SL Credited
                    </span>
                )}
                {summary.ncns > 0 && (
                    <span className="inline-flex items-center gap-1 text-xs font-medium text-red-700 dark:text-red-400">
                        <XCircle className="h-3 w-3" /> {summary.ncns} NCNS
                    </span>
                )}
                {summary.advised > 0 && (
                    <span className="inline-flex items-center gap-1 text-xs font-medium text-amber-700 dark:text-amber-400">
                        <AlertTriangle className="h-3 w-3" /> {summary.advised} Advised Absence
                    </span>
                )}
            </div>

            {/* Per-Day Records — Compact */}
            <div className="rounded-lg border divide-y overflow-hidden">
                {records.map((record) => {
                    const statusOpt = getStatusOption(record.day_status);

                    return (
                        <div
                            key={record.id}
                            className={cn(
                                'transition-colors',
                                record.day_status === 'sl_credited' && 'bg-green-50/40 dark:bg-green-950/10',
                                record.day_status === 'ncns' && 'bg-red-50/40 dark:bg-red-950/10',
                                record.day_status === 'advised_absence' && 'bg-amber-50/40 dark:bg-amber-950/10',
                            )}
                        >
                            <div className="flex items-center gap-2 px-3 py-2">
                                {/* Color indicator */}
                                <div
                                    className={cn(
                                        'w-1 self-stretch rounded-full shrink-0',
                                        statusOpt?.dotColor ?? 'bg-muted-foreground/30',
                                    )}
                                />

                                {/* Date */}
                                <span className="text-sm font-medium min-w-0 shrink-0">
                                    {format(parseISO(record.date), 'EEE, MMM d')}
                                </span>

                                {/* Status + Paid/Unpaid */}
                                <div className="ml-auto flex items-center gap-2">
                                    <StatusBadge status={record.day_status} />
                                    {record.is_paid ? (
                                        <Badge variant="default" className="text-[10px] h-5 bg-green-600">Paid</Badge>
                                    ) : (
                                        <Badge variant="outline" className="text-[10px] h-5 text-muted-foreground">Unpaid</Badge>
                                    )}
                                </div>
                            </div>

                            {/* Notes + Assigned by — compact row */}
                            {(record.notes || record.assigned_by) && (
                                <div className="flex items-center gap-3 px-3 pb-1.5 pl-7 text-[11px] text-muted-foreground">
                                    {record.notes && (
                                        <span className="italic truncate max-w-[300px]" title={record.notes}>
                                            {record.notes}
                                        </span>
                                    )}
                                    {record.assigned_by && (
                                        <span className="ml-auto shrink-0">
                                            by {record.assigned_by}
                                        </span>
                                    )}
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

export { StatusBadge, STATUS_OPTIONS };
