import React from 'react';
import { Badge } from '@/components/ui/badge';

/**
 * Shift type configuration - Single source of truth for all shift type badges
 */
export const SHIFT_TYPE_CONFIG: Record<string, { label: string; className: string }> = {
    day_shift: { label: 'Day Shift', className: 'bg-sky-500' },
    night_shift: { label: 'Night Shift', className: 'bg-indigo-500' },
    mid_shift: { label: 'Mid Shift', className: 'bg-violet-500' },
    afternoon_shift: { label: 'Afternoon Shift', className: 'bg-amber-500' },
    graveyard_shift: { label: 'Graveyard Shift', className: 'bg-slate-600' },
    morning_shift: { label: 'Morning Shift', className: 'bg-yellow-500' },
    utility_24h: { label: '24H Utility', className: 'bg-purple-500' },
};

/**
 * Get a shift type badge
 */
export const getShiftTypeBadge = (shiftType: string) => {
    const config = SHIFT_TYPE_CONFIG[shiftType];
    
    if (config) {
        return <Badge className={config.className}>{config.label}</Badge>;
    }
    
    // Fallback: format the shift type nicely
    const formattedLabel = shiftType
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (l) => l.toUpperCase());
    
    return <Badge className="bg-gray-500">{formattedLabel}</Badge>;
};

/**
 * Shift Type Badge Component
 */
interface ShiftTypeBadgeProps {
    shiftType: string;
}

export const ShiftTypeBadge = ({ shiftType }: ShiftTypeBadgeProps) => {
    return getShiftTypeBadge(shiftType);
};
