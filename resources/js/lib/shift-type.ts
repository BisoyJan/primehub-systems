export type ShiftType =
    | 'morning_shift'
    | 'afternoon_shift'
    | 'night_shift'
    | 'utility_24h';

export interface ShiftMeta {
    label: string;
    icon: string;
    description: string;
}

export const SHIFT_META: Record<ShiftType, ShiftMeta> = {
    morning_shift: {
        label: 'Morning Shift',
        icon: '🌅',
        description: 'Starts between 5:00 AM and 11:59 AM',
    },
    afternoon_shift: {
        label: 'Afternoon Shift',
        icon: '🌤️',
        description: 'Starts between 12:00 PM and 5:59 PM',
    },
    night_shift: {
        label: 'Night Shift',
        icon: '🌙',
        description: 'Starts after 6:00 PM or before 5:00 AM',
    },
    utility_24h: {
        label: '24-Hour Utility',
        icon: '🔄',
        description: 'No fixed shift window — hours-based tracking',
    },
};

/**
 * Derive the canonical shift_type from a scheduled time-in.
 *
 * Mirrors `EmployeeScheduleController::deriveShiftType()` on the PHP side.
 * Keep the two in sync — the backend treats this same rule as the source of
 * truth and re-derives on every store/update.
 */
export function deriveShiftType(timeIn: string, isUtility: boolean): ShiftType {
    if (isUtility) {
        return 'utility_24h';
    }

    const hour = parseInt(timeIn.slice(0, 2), 10);

    if (Number.isNaN(hour)) {
        return 'night_shift';
    }
    if (hour >= 5 && hour < 12) {
        return 'morning_shift';
    }
    if (hour >= 12 && hour < 18) {
        return 'afternoon_shift';
    }
    return 'night_shift';
}
