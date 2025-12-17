import * as React from 'react';
import { cn } from '@/lib/utils';

interface TimeInputProps {
    value: string; // Always stored as 24-hour format (HH:mm)
    onChange: (value: string) => void;
    className?: string;
    id?: string;
    disabled?: boolean;
}

/**
 * Simple native time input component.
 */
export function TimeInput({
    value,
    onChange,
    className,
    id,
    disabled = false,
}: TimeInputProps) {
    return (
        <input
            id={id}
            type="time"
            value={value}
            onChange={(e) => onChange(e.target.value)}
            disabled={disabled}
            aria-label="Time input"
            className={cn("w-full h-10 px-3 rounded-md border border-input bg-background text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50", className)}
        />
    );
}
