import * as React from 'react';
import { cn } from '@/lib/utils';

interface TimeInputProps {
    value: string; // Always stored as 24-hour format (HH:mm)
    onChange: (value: string) => void;
    is24HourFormat: boolean;
    className?: string;
    id?: string;
    disabled?: boolean;
}

/**
 * Native time input with optional 24-hour format helper text.
 */
export function TimeInput({
    value,
    onChange,
    is24HourFormat,
    className,
    id,
    disabled = false,
}: TimeInputProps) {
    const [isFocused, setIsFocused] = React.useState(false);
    const [displayValue, setDisplayValue] = React.useState(value);

    // Sync with external value changes
    React.useEffect(() => {
        setDisplayValue(value);
    }, [value]);

    const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const newValue = e.target.value;
        setDisplayValue(newValue);
        onChange(newValue);
    };

    // Show helper when focused (while typing) or when there's a value
    const showHelper = is24HourFormat && (isFocused || displayValue);

    return (
        <>
            <input
                id={id}
                type="time"
                value={displayValue}
                onChange={handleChange}
                onFocus={() => setIsFocused(true)}
                onBlur={() => setIsFocused(false)}
                disabled={disabled}
                aria-label="Time input"
                className={cn("w-full h-10 px-3 rounded-md border border-input bg-background text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50", className)}
            />
            {showHelper && (
                <p className="text-xs text-muted-foreground mt-1">
                    {displayValue ? `24h format: ${displayValue}` : '24-hour format (HH:MM)'}
                </p>
            )}
        </>
    );
}
