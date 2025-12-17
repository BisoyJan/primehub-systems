import * as React from 'react';
import { cn } from '@/lib/utils';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

interface TimeInput12HProps {
    value: string; // Always stored as 24-hour format (HH:mm)
    onChange: (value: string) => void;
    className?: string;
    id?: string;
    disabled?: boolean;
}

/**
 * Convert 24-hour time to 12-hour format parts
 */
function to12Hour(time24: string): { hour: string; minute: string; period: 'AM' | 'PM' } {
    if (!time24) return { hour: '', minute: '', period: 'AM' };

    const [hourStr, minuteStr] = time24.split(':');
    let hour = parseInt(hourStr, 10);
    const minute = minuteStr || '00';

    const period: 'AM' | 'PM' = hour >= 12 ? 'PM' : 'AM';

    if (hour === 0) {
        hour = 12;
    } else if (hour > 12) {
        hour = hour - 12;
    }

    return { hour: hour.toString(), minute, period };
}

/**
 * Convert 12-hour format parts to 24-hour time string
 */
function to24Hour(hour: string, minute: string, period: 'AM' | 'PM'): string {
    if (!hour || !minute) return '';

    let hour24 = parseInt(hour, 10);

    if (period === 'AM') {
        if (hour24 === 12) hour24 = 0;
    } else {
        if (hour24 !== 12) hour24 += 12;
    }

    return `${hour24.toString().padStart(2, '0')}:${minute}`;
}

/**
 * 12-hour time input with hour, minute, and AM/PM dropdowns.
 * Stores value internally as 24-hour format for consistency.
 */
export function TimeInput12H({
    value,
    onChange,
    className,
    id,
    disabled = false,
}: TimeInput12HProps) {
    const parsed = to12Hour(value);
    const [hour, setHour] = React.useState(parsed.hour);
    const [minute, setMinute] = React.useState(parsed.minute);
    const [period, setPeriod] = React.useState<'AM' | 'PM'>(parsed.period);

    // Sync with external value changes
    React.useEffect(() => {
        const parsed = to12Hour(value);
        setHour(parsed.hour);
        setMinute(parsed.minute);
        setPeriod(parsed.period);
    }, [value]);

    const handleChange = (newHour: string, newMinute: string, newPeriod: 'AM' | 'PM') => {
        if (newHour && newMinute) {
            const time24 = to24Hour(newHour, newMinute, newPeriod);
            onChange(time24);
        }
    };

    const hours = ['12', '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11'];
    const minutes = Array.from({ length: 60 }, (_, i) => i.toString().padStart(2, '0'));

    return (
        <div className={cn("flex items-center gap-1", className)} id={id}>
            {/* Hour */}
            <Select
                value={hour}
                onValueChange={(val) => {
                    setHour(val);
                    handleChange(val, minute, period);
                }}
                disabled={disabled}
            >
                <SelectTrigger className="w-[70px]">
                    <SelectValue placeholder="Hr" />
                </SelectTrigger>
                <SelectContent>
                    {hours.map((h) => (
                        <SelectItem key={h} value={h}>
                            {h}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            <span className="text-muted-foreground">:</span>

            {/* Minute */}
            <Select
                value={minute}
                onValueChange={(val) => {
                    setMinute(val);
                    handleChange(hour, val, period);
                }}
                disabled={disabled}
            >
                <SelectTrigger className="w-[70px]">
                    <SelectValue placeholder="Min" />
                </SelectTrigger>
                <SelectContent>
                    {minutes.map((m) => (
                        <SelectItem key={m} value={m}>
                            {m}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            {/* AM/PM */}
            <Select
                value={period}
                onValueChange={(val: 'AM' | 'PM') => {
                    setPeriod(val);
                    handleChange(hour, minute, val);
                }}
                disabled={disabled}
            >
                <SelectTrigger className="w-[70px]">
                    <SelectValue placeholder="AM" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="AM">AM</SelectItem>
                    <SelectItem value="PM">PM</SelectItem>
                </SelectContent>
            </Select>
        </div>
    );
}
