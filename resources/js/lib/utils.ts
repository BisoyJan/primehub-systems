import { type ClassValue, clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

/**
 * Format a time string (HH:MM:SS or HH:MM) to display in browser's local format
 * Converts 24-hour time to browser locale format (e.g., 2:30 PM for Philippines)
 */
export function formatTime(timeString: string | null | undefined): string {
    if (!timeString) return '-';

    // Parse time string (HH:MM:SS or HH:MM format)
    const [hours, minutes] = timeString.split(':');
    if (!hours || !minutes) return timeString;

    // Create a date object with the time to use toLocaleTimeString
    const date = new Date();
    date.setHours(parseInt(hours, 10), parseInt(minutes, 10), 0, 0);

    return date.toLocaleTimeString(undefined, {
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * Format a datetime string to display in browser's local timezone
 * Returns date and time formatted according to browser locale
 */
export function formatDateTime(dateTimeString: string | null | undefined): string {
    if (!dateTimeString) return '-';

    const date = new Date(dateTimeString);
    if (Number.isNaN(date.getTime())) return dateTimeString;

    return date.toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * Format a datetime string to display only time in browser's local timezone
 */
export function formatDateTimeToTime(dateTimeString: string | null | undefined): string {
    if (!dateTimeString) return '-';

    const date = new Date(dateTimeString);
    if (Number.isNaN(date.getTime())) return dateTimeString;

    return date.toLocaleTimeString(undefined, {
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * Format a date string (YYYY-MM-DD) to display in browser's local format
 * Handles date-only strings without timezone conversion issues
 */
export function formatDate(dateString: string | null | undefined): string {
    if (!dateString) return '-';

    // For date-only strings (YYYY-MM-DD), split and create date in local timezone
    const dateParts = dateString.split('T')[0].split('-');
    if (dateParts.length === 3) {
        const year = parseInt(dateParts[0]);
        const month = parseInt(dateParts[1]) - 1; // Month is 0-indexed
        const day = parseInt(dateParts[2]);

        const date = new Date(year, month, day);
        if (Number.isNaN(date.getTime())) return dateString;

        return date.toLocaleDateString(undefined, {
            weekday: 'short',
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    }

    // Fallback for other date formats
    const date = new Date(dateString);
    if (Number.isNaN(date.getTime())) return dateString;

    return date.toLocaleDateString(undefined, {
        weekday: 'short',
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

/**
 * Format a date string to short format (Mon, Jan 1, 2025)
 */
export function formatDateShort(dateString: string | null | undefined): string {
    if (!dateString) return '-';

    const dateParts = dateString.split('T')[0].split('-');
    if (dateParts.length === 3) {
        const year = parseInt(dateParts[0]);
        const month = parseInt(dateParts[1]) - 1;
        const day = parseInt(dateParts[2]);

        const date = new Date(year, month, day);
        if (Number.isNaN(date.getTime())) return dateString;

        return date.toLocaleDateString(undefined, {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        });
    }

    const date = new Date(dateString);
    if (Number.isNaN(date.getTime())) return dateString;

    return date.toLocaleDateString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

/**
 * Format total minutes worked to display as "Xh Ym" format
 * @param minutes Total minutes worked (integer)
 * @returns Formatted string like "8h 30m" or "-" if null/0
 */
export function formatWorkDuration(minutes: number | null | undefined): string {
    if (minutes == null || minutes === 0) return '-';

    const hours = Math.floor(minutes / 60);
    const mins = minutes % 60;

    if (hours === 0) return `${mins}m`;
    if (mins === 0) return `${hours}h`;
    return `${hours}h ${mins}m`;
}

/**
 * Format time adjustment minutes (tardy, undertime, overtime) to display as "Xh Ym" format
 * @param minutes Time adjustment in minutes (integer)
 * @returns Formatted string like "1h 23m" or "45m"
 */
export function formatTimeAdjustment(minutes: number | null | undefined): string {
    if (minutes == null || minutes === 0) return '0m';

    const hours = Math.floor(minutes / 60);
    const mins = minutes % 60;

    if (hours === 0) return `${mins}m`;
    if (mins === 0) return `${hours}h`;
    return `${hours}h ${mins}m`;
}
