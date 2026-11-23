import { Button } from '@/components/ui/button';
import { useState } from 'react';

export interface ItConcernEditChange {
    field: string;
    old: string | number | null;
    new: string | number | null;
}

export interface ItConcernEditEntry {
    id: number;
    name: string;
    at: string;
    changes: ItConcernEditChange[];
}

const FIELD_LABELS: Record<string, string> = {
    status: 'Status',
    priority: 'Priority',
    category: 'Category',
    description: 'Description',
    station_number: 'Station',
    site_id: 'Site',
    user_id: 'Submitted By',
    resolution_notes: 'Resolution Notes',
    resolved_by: 'Resolved By',
    resolved_at: 'Resolved At',
};

const STATUS_LABELS: Record<string, string> = {
    pending: 'Pending',
    in_progress: 'In Progress',
    resolved: 'Resolved',
    cancelled: 'Cancelled',
};

const LONG_TEXT_FIELDS = new Set(['description', 'resolution_notes']);

const formatDateTime = (dateString: string) =>
    new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });

const formatValue = (
    field: string,
    value: ItConcernEditChange['old'],
): string => {
    if (value === null || value === '') {
        return '—';
    }

    const text = String(value);

    switch (field) {
        case 'resolved_at':
            return formatDateTime(text);
        case 'status':
            return STATUS_LABELS[text] ?? text;
        case 'priority':
            return text.charAt(0).toUpperCase() + text.slice(1);
        default:
            return text;
    }
};

const formatFieldLabel = (field: string) =>
    FIELD_LABELS[field] ??
    field.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

interface ItConcernEditHistoryProps {
    entries: ItConcernEditEntry[];
    initialVisible?: number;
}

export function ItConcernEditHistory({
    entries,
    initialVisible = 3,
}: ItConcernEditHistoryProps) {
    const [showAll, setShowAll] = useState(false);

    if (entries.length === 0) {
        return <p className="text-sm text-muted-foreground">No edits yet.</p>;
    }

    const editorCount = new Set(entries.map((entry) => entry.name)).size;
    const visibleEntries = showAll ? entries : entries.slice(0, initialVisible);

    return (
        <div className="space-y-3">
            <p className="text-xs text-muted-foreground">
                Edited {entries.length}{' '}
                {entries.length === 1 ? 'time' : 'times'} by {editorCount}{' '}
                {editorCount === 1 ? 'user' : 'users'}
            </p>

            <ol className="space-y-3">
                {visibleEntries.map((entry) => (
                    <li key={entry.id} className="border-l-2 border-muted pl-3">
                        <div className="flex flex-wrap items-baseline gap-x-2 text-sm">
                            <span className="font-medium">{entry.name}</span>
                            <span className="text-muted-foreground">
                                {formatDateTime(entry.at)}
                            </span>
                        </div>
                        <ul className="mt-1 space-y-1 text-xs text-muted-foreground">
                            {entry.changes.map((change) => (
                                <li key={change.field} className="break-words">
                                    <span className="font-medium text-foreground">
                                        {formatFieldLabel(change.field)}:
                                    </span>{' '}
                                    {LONG_TEXT_FIELDS.has(change.field) ? (
                                        <span className="mt-0.5 block space-y-0.5">
                                            <span
                                                className="line-clamp-3 block whitespace-pre-wrap line-through opacity-70"
                                                title={formatValue(
                                                    change.field,
                                                    change.old,
                                                )}
                                            >
                                                {formatValue(
                                                    change.field,
                                                    change.old,
                                                )}
                                            </span>
                                            <span
                                                className="line-clamp-3 block whitespace-pre-wrap"
                                                title={formatValue(
                                                    change.field,
                                                    change.new,
                                                )}
                                            >
                                                {formatValue(
                                                    change.field,
                                                    change.new,
                                                )}
                                            </span>
                                        </span>
                                    ) : (
                                        <>
                                            {formatValue(
                                                change.field,
                                                change.old,
                                            )}{' '}
                                            →{' '}
                                            {formatValue(
                                                change.field,
                                                change.new,
                                            )}
                                        </>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </li>
                ))}
            </ol>

            {entries.length > initialVisible && (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="h-auto px-0 text-xs hover:bg-transparent hover:underline"
                    onClick={() => setShowAll((value) => !value)}
                >
                    {showAll ? 'Show less' : `Show all ${entries.length} edits`}
                </Button>
            )}
        </div>
    );
}
